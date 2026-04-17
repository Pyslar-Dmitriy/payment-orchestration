<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers;

use App\Domain\Provider\ProviderRoutingConfigRepositoryInterface;
use App\Interfaces\Http\Requests\ProviderAvailabilityRequest;
use Illuminate\Http\JsonResponse;

/**
 * Internal admin endpoint: update a provider's availability at runtime.
 *
 * Accessible only from the internal Docker network (enforced by
 * InternalNetworkMiddleware). The change takes effect immediately for all
 * subsequent routing decisions without a service restart.
 *
 * PATCH /internal/providers/{key}/availability
 */
final class ProviderAvailabilityController
{
    public function __construct(
        private readonly ProviderRoutingConfigRepositoryInterface $configs,
    ) {}

    public function __invoke(ProviderAvailabilityRequest $request, string $key): JsonResponse
    {
        if ($this->configs->find($key) === null) {
            return response()->json(['message' => "Provider '{$key}' is not configured."], 404);
        }

        $available = (bool) $request->input('available');
        $this->configs->setAvailability($key, $available);

        return response()->json([
            'provider_key' => $key,
            'available' => $available,
        ], 200);
    }
}
