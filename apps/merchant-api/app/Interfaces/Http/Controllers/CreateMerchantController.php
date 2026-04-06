<?php

namespace App\Interfaces\Http\Controllers;

use App\Application\Merchant\IssueApiKey;
use App\Domain\Merchant\Merchant;
use App\Interfaces\Http\Requests\CreateMerchantRequest;
use Illuminate\Http\JsonResponse;

final class CreateMerchantController
{
    public function __construct(private readonly IssueApiKey $issueApiKey) {}

    public function __invoke(CreateMerchantRequest $request): JsonResponse
    {
        $merchant = Merchant::create([
            'name'         => $request->validated('name'),
            'email'        => $request->validated('email'),
            'status'       => 'active',
            'callback_url' => $request->validated('callback_url'),
        ]);

        $keyResult = $this->issueApiKey->execute($merchant);

        return response()->json([
            'merchant_id' => $merchant->id,
            'name'        => $merchant->name,
            'email'       => $merchant->email,
            'api_key'     => $keyResult,
        ], 201);
    }
}