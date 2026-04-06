<?php

namespace App\Interfaces\Http\Controllers;

use App\Application\Merchant\RotateApiKey;
use App\Interfaces\Http\Requests\RotateApiKeyRequest;
use Illuminate\Http\JsonResponse;

final class RotateApiKeyController
{
    public function __construct(private readonly RotateApiKey $rotateApiKey) {}

    public function __invoke(RotateApiKeyRequest $request): JsonResponse
    {
        $apiKey = $request->attributes->get('api_key');
        $grace  = $request->has('grace_minutes') ? $request->integer('grace_minutes') : null;

        $result = $this->rotateApiKey->execute($apiKey, $grace);

        return response()->json(['api_key' => $result], 201);
    }
}