<?php

declare(strict_types=1);

namespace App\Application\DispatchCallback;

use App\Domain\Callback\CallbackDelivery;
use App\Domain\Callback\CallbackSubscription;
use App\Domain\Callback\DeliveryStatus;
use App\Infrastructure\RabbitMq\CallbackQueuePublisherInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DispatchCallbackHandler
{
    public function __construct(
        private readonly CallbackQueuePublisherInterface $publisher,
    ) {}

    /**
     * Finds active subscriptions for the merchant + event type, creates a
     * CallbackDelivery record for each, signs the payload, and enqueues to
     * the RabbitMQ dispatch queue.
     *
     * @return list<DispatchedDelivery>
     */
    public function handle(DispatchCallbackCommand $command): array
    {
        $subscriptions = CallbackSubscription::query()
            ->where('merchant_id', $command->merchantId)
            ->where('is_active', true)
            ->whereJsonContains('event_types', $command->eventType)
            ->get();

        if ($subscriptions->isEmpty()) {
            return [];
        }

        $queue = (string) config('rabbitmq.queues.dispatch', 'merchant.callback.dispatch');
        $maxAttempts = (int) config('rabbitmq.max_attempts', 5);

        $callbackPayload = $this->buildCallbackPayload($command);
        $now = now()->toIso8601ZuluString();

        $dispatched = [];

        foreach ($subscriptions as $subscription) {
            $delivery = DB::transaction(function () use ($subscription, $command, $callbackPayload): CallbackDelivery {
                return CallbackDelivery::create([
                    'subscription_id' => $subscription->id,
                    'payment_id' => $command->paymentId,
                    'merchant_id' => $command->merchantId,
                    'event_type' => $command->eventType,
                    'payload' => $callbackPayload,
                    'endpoint_url' => $subscription->callback_url,
                    'status' => DeliveryStatus::Pending,
                    'correlation_id' => $command->correlationId,
                ]);
            });

            $messageId = Str::uuid()->toString();
            $signature = $this->sign($callbackPayload, $subscription->signing_secret ?? '', $subscription->signing_algorithm ?? 'hmac-sha256');

            $message = [
                'message_id' => $messageId,
                'correlation_id' => $command->correlationId,
                'source_service' => 'merchant-callback-delivery',
                'enqueued_at' => $now,
                'message_type' => 'merchant_callback_dispatch',
                'callback_id' => $delivery->id,
                'merchant_id' => $command->merchantId,
                'payment_id' => $command->paymentId,
                'endpoint_url' => $subscription->callback_url,
                'callback_payload' => $callbackPayload,
                'signature' => $signature,
                'attempt_number' => 1,
                'max_attempts' => $maxAttempts,
                'scheduled_at' => $now,
            ];

            $this->publisher->publish($queue, $messageId, json_encode($message, JSON_THROW_ON_ERROR));

            $dispatched[] = new DispatchedDelivery($delivery->id, $subscription->callback_url);
        }

        return $dispatched;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCallbackPayload(DispatchCallbackCommand $command): array
    {
        $payload = [
            'event_type' => $command->eventType,
            'payment_id' => $command->paymentId,
            'amount' => [
                'value' => $command->amountValue,
                'currency' => $command->amountCurrency,
            ],
            'status' => $command->status,
            'occurred_at' => $command->occurredAt,
        ];

        if ($command->refundId !== null) {
            $payload['refund_id'] = $command->refundId;
        }

        if ($command->idempotencyKey !== null) {
            $payload['idempotency_key'] = $command->idempotencyKey;
        }

        return $payload;
    }

    private function sign(mixed $payload, string $secret, string $algorithm): string
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        if ($algorithm !== 'hmac-sha256') {
            throw new \InvalidArgumentException("Unsupported signing algorithm: {$algorithm}");
        }

        return hash_hmac('sha256', $body, $secret);
    }
}
