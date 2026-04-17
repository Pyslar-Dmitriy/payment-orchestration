<?php

declare(strict_types=1);

namespace App\Domain\Provider;

use App\Domain\Provider\DTO\RoutingRequest;
use App\Domain\Provider\Exception\NoProviderAvailableException;

/**
 * Pure routing algorithm — no I/O, no framework dependencies.
 *
 * Given a list of ProviderRoutingConfig objects and a RoutingRequest, selects
 * the best provider key according to the rule-based priority model:
 *
 *   1. Filter by currencies, countries, merchant_types.
 *   2. Remove unavailable providers (available = false).
 *   3. Remove any explicitly excluded provider keys (fallback path).
 *   4. Sort remaining candidates by priority ascending.
 *   5. Return the first candidate's providerKey, or throw NoProviderAvailableException.
 *
 * Currency and country comparisons are case-insensitive.
 */
final class ProviderRouter
{
    /**
     * @param  ProviderRoutingConfig[]  $configs
     *
     * @throws NoProviderAvailableException
     */
    public function route(array $configs, RoutingRequest $request): string
    {
        $currency = strtoupper($request->currency);
        $country = strtoupper($request->country);

        $candidates = array_values(array_filter(
            $configs,
            function (ProviderRoutingConfig $config) use ($currency, $country, $request): bool {
                if (! $config->available) {
                    return false;
                }

                if (in_array($config->providerKey, $request->excludedProviderKeys, true)) {
                    return false;
                }

                if (! in_array($currency, array_map('strtoupper', $config->currencies), true)) {
                    return false;
                }

                if (! in_array($country, array_map('strtoupper', $config->countries), true)) {
                    return false;
                }

                if (! empty($config->merchantTypes)) {
                    if ($request->merchantType === null) {
                        return false;
                    }
                    if (! in_array($request->merchantType, $config->merchantTypes, true)) {
                        return false;
                    }
                }

                return true;
            }
        ));

        if (empty($candidates)) {
            throw new NoProviderAvailableException(
                $request->currency,
                $request->country,
                $request->merchantType,
            );
        }

        usort($candidates, static fn (ProviderRoutingConfig $a, ProviderRoutingConfig $b): int => $a->priority <=> $b->priority);

        return $candidates[0]->providerKey;
    }
}
