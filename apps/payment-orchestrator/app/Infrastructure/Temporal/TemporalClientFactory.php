<?php

namespace App\Infrastructure\Temporal;

use Temporal\Client\ClientOptions;
use Temporal\Client\GRPC\ServiceClient;
use Temporal\Client\WorkflowClient;
use Temporal\Client\WorkflowClientInterface;

class TemporalClientFactory
{
    public static function create(string $address, string $namespace): WorkflowClientInterface
    {
        return WorkflowClient::create(
            serviceClient: ServiceClient::createInsecure($address),
            options: (new ClientOptions)->withNamespace($namespace),
        );
    }
}
