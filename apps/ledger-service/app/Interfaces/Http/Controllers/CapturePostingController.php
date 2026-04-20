<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers;

use App\Application\PostCaptureEntries\PostCaptureEntriesCommand;
use App\Application\PostCaptureEntries\PostCaptureEntriesHandler;
use App\Domain\Ledger\LedgerTransaction;
use App\Interfaces\Http\Requests\PostCaptureEntriesRequest;
use Illuminate\Http\JsonResponse;

final class CapturePostingController
{
    public function __construct(
        private readonly PostCaptureEntriesHandler $handler,
    ) {}

    public function store(PostCaptureEntriesRequest $request): JsonResponse
    {
        $command = new PostCaptureEntriesCommand(
            paymentId: $request->validated('payment_id'),
            merchantId: $request->validated('merchant_id'),
            amount: (int) $request->validated('amount'),
            currency: strtoupper((string) $request->validated('currency')),
            correlationId: $request->validated('correlation_id'),
            causationId: $request->validated('causation_id'),
            feeAmount: (int) $request->validated('fee_amount', 0),
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
