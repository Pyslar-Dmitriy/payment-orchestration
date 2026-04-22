<?php

declare(strict_types=1);

namespace App\Application\DeliverCallback;

use App\Domain\Callback\CallbackAttempt;
use App\Domain\Callback\CallbackDelivery;
use App\Domain\Callback\DeliveryStatus;
use App\Infrastructure\Http\HttpCallbackSenderInterface;
use App\Infrastructure\RabbitMq\CallbackRetryRouterInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DeliverCallbackHandler
{
    public function __construct(
        private readonly HttpCallbackSenderInterface $sender,
        private readonly CallbackRetryRouterInterface $retryRouter,
    ) {}

    public function handle(DeliverCallbackCommand $command): void
    {
        // Idempotency: skip messages already processed by this worker
        $alreadyProcessed = DB::table('inbox_messages')
            ->where('message_id', $command->messageId)
            ->exists();

        if ($alreadyProcessed) {
            return;
        }

        $result = $this->sender->send(
            endpointUrl: $command->endpointUrl,
            payload: $command->callbackPayload,
            signature: $command->signature,
            callbackId: $command->callbackId,
            correlationId: $command->correlationId,
        );

        $now = now();
        $isLastAttempt = $command->attemptNumber >= $command->maxAttempts;
        $isPermanent = $result->isPermanentFailure || ($result->success === false && $isLastAttempt);

        DB::transaction(function () use ($command, $result, $now, $isPermanent): void {
            CallbackAttempt::create([
                'delivery_id' => $command->callbackId,
                'attempt_number' => $command->attemptNumber,
                'attempted_at' => $now,
                'http_status_code' => $result->statusCode,
                'response_body' => $result->responseBody,
                'response_headers' => $result->responseHeaders,
                'failure_reason' => $result->failureReason,
                'duration_ms' => $result->durationMs,
            ]);

            if ($result->success) {
                CallbackDelivery::where('id', $command->callbackId)->update([
                    'status' => DeliveryStatus::Delivered,
                    'attempt_count' => $command->attemptNumber,
                    'last_attempted_at' => $now,
                    'delivered_at' => $now,
                ]);

                // Inbox written inside the transaction on success so delivery status
                // and dedup marker are committed atomically — prevents double delivery
                // if the worker crashes between commit and ACK.
                DB::table('inbox_messages')->insert([
                    'message_id' => $command->messageId,
                    'processed_at' => $now,
                    'created_at' => $now,
                ]);
            } elseif ($isPermanent) {
                CallbackDelivery::where('id', $command->callbackId)->update([
                    'status' => DeliveryStatus::Dlq,
                    'attempt_count' => $command->attemptNumber,
                    'last_attempted_at' => $now,
                ]);
            } else {
                CallbackDelivery::where('id', $command->callbackId)->update([
                    'attempt_count' => $command->attemptNumber,
                    'last_attempted_at' => $now,
                ]);
            }
        });

        if (! $result->success) {
            $retryMessageId = Str::uuid()->toString();
            $retryBody = $this->buildRetryBody($command, $retryMessageId);

            if ($isPermanent) {
                $this->retryRouter->routeToDlq($retryMessageId, $retryBody);
            } else {
                $this->retryRouter->routeToRetry($retryMessageId, $retryBody, $command->attemptNumber + 1);
            }

            // Inbox written only after routing succeeds. If routing throws, the
            // exception propagates and the consumer NACKs with requeue — the message
            // stays processable. Writing here (not in the transaction) means a crash
            // after routing but before this insert causes the routing to happen twice,
            // which is far less harmful than silently losing the retry.
            DB::table('inbox_messages')->insert([
                'message_id' => $command->messageId,
                'processed_at' => $now,
                'created_at' => $now,
            ]);
        }
    }

    private function buildRetryBody(DeliverCallbackCommand $command, string $newMessageId): string
    {
        $nextAttempt = $command->attemptNumber + 1;

        $message = [
            'message_id' => $newMessageId,
            'correlation_id' => $command->correlationId,
            'source_service' => 'merchant-callback-delivery',
            'enqueued_at' => now()->toIso8601ZuluString(),
            'message_type' => 'merchant_callback_dispatch',
            'callback_id' => $command->callbackId,
            'merchant_id' => $command->merchantId,
            'payment_id' => $command->paymentId,
            'endpoint_url' => $command->endpointUrl,
            'callback_payload' => $command->callbackPayload,
            'signature' => $command->signature,
            'attempt_number' => $nextAttempt,
            'max_attempts' => $command->maxAttempts,
            'scheduled_at' => now()->toIso8601ZuluString(),
        ];

        return json_encode($message, JSON_THROW_ON_ERROR);
    }
}
