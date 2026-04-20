<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers;

use App\Application\PostRefundEntries\PostRefundEntriesCommand;
use App\Application\PostRefundEntries\PostRefundEntriesHandler;
use App\Domain\Ledger\LedgerTransaction;
use App\Interfaces\Http\Requests\PostRefundEntriesRequest;
use Illuminate\Http\JsonResponse;

final class RefundPostingController
{
    public function __construct(
        private readonly PostRefundEntriesHandler $handler,
    ) {}

    public function store(PostRefundEntriesRequest $request): JsonResponse
    {
        $command = new PostRefundEntriesCommand(
            refundId: $request->validated('refund_id'),
            paymentId: $request->validated('payment_id'),
            merchantId: $request->validated('merchant_id'),
            amount: (int) $request->validated('amount'),
            currency: strtoupper((string) $request->validated('currency')),
            correlationId: $request->validated('correlation_id'),
            causationId: $request->validated('causation_id'),
            feeRefundAmount: (int) $request->validated('fee_refund_amount', 0),
        );

        $transaction = $this->handler->handle($command);
        $transaction->load('entries');

        return response()->json(
            $this->formatResponse($transaction),
            $transaction->wasRecentlyCreated ? 201 : 200,
        );
    }

    /** @return array<string, mixed> */
    private function formatResponse(LedgerTransaction $transaction): array
    {
        return [
            'transaction_id' => $transaction->id,
            'idempotency_key' => $transaction->idempotency_key,
            'entry_type' => $transaction->entry_type->value,
            'payment_id' => $transaction->payment_id,
            'refund_id' => $transaction->refund_id,
            'created_at' => $transaction->created_at->toIso8601String(),
            'entries' => $transaction->entries->map(fn ($e) => [
                'id' => $e->id,
                'account_id' => $e->account_id,
                'direction' => $e->direction->value,
                'amount' => $e->amount,
                'currency' => $e->currency,
            ])->values()->all(),
        ];
    }
}
