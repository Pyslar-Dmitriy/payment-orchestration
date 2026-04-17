<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers;

use App\Domain\Provider\DTO\RoutingRequest;
use App\Domain\Provider\Exception\NoProviderAvailableException;
use App\Domain\Provider\ProviderRouter;
use App\Domain\Provider\ProviderRoutingConfigRepositoryInterface;
use App\Interfaces\Http\Requests\ProviderRouteRequest;
use Illuminate\Http\JsonResponse;

final class ProviderRouteController
{
    public function __construct(
        private readonly ProviderRouter $router,
        private readonly ProviderRoutingConfigRepositoryInterface $configs,
    ) {}

    public function __invoke(ProviderRouteRequest $request): JsonResponse
    {
        $routingRequest = new RoutingRequest(
            currency: strtoupper((string) $request->input('currency')),
            country: strtoupper((string) $request->input('country')),
            merchantType: $request->input('merchant_type'),
            excludedProviderKeys: (array) $request->input('excluded_provider_keys', []),
        );

        try {
            $providerKey = $this->router->route($this->configs->all(), $routingRequest);
        } catch (NoProviderAvailableException $e) {
            return response()->json([
                'message' => 'No provider available for the requested routing parameters.',
                'error' => 'no_provider_available',
            ], 422);
        }

        return response()->json(['provider_key' => $providerKey], 200);
    }
}
