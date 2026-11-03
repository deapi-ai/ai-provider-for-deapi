<?php

declare(strict_types=1);

namespace DeApi\AiProvider\Provider;

use Exception;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\Http\Contracts\WithHttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\WithRequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Traits\WithHttpTransporterTrait;
use WordPress\AiClient\Providers\Http\Traits\WithRequestAuthenticationTrait;

/**
 * Checks that the configured deAPI key is accepted.
 *
 * Unlike the generic list-models availability, this always asks the API instead of reading the cached model
 * list. The cache key does not depend on the API key, so on a site with a persistent object cache a cached list
 * would let a wrong key pass the check on Settings > Connectors.
 *
 * @since 0.1.0
 */
class DeApiProviderAvailability implements
    ProviderAvailabilityInterface,
    WithHttpTransporterInterface,
    WithRequestAuthenticationInterface
{
    use WithHttpTransporterTrait;
    use WithRequestAuthenticationTrait;

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    public function isConfigured(): bool
    {
        try {
            $request = new Request(
                HttpMethodEnum::GET(),
                DeApiProvider::url('models'),
                [],
                null,
                DeApiProvider::requestOptions(null, 30.0)
            );
            $request = $this->getRequestAuthentication()->authenticateRequest($request);
            $response = $this->getHttpTransporter()->send($request);
        } catch (Exception $e) {
            return false;
        }

        $data = $response->getData();
        return $response->getStatusCode() === 200 && isset($data['data']) && is_array($data['data']);
    }
}
