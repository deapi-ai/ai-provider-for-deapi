<?php

declare(strict_types=1);

namespace DeApi\AiProvider\Tests;

use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;

/**
 * Records requests and answers them with queued responses.
 */
class FakeTransporter implements HttpTransporterInterface
{
    /** @var list<Request> */
    public array $requests = [];

    /** @var list<Response> */
    private array $responses;

    /**
     * @param Response ...$responses Responses returned in order.
     */
    public function __construct(Response ...$responses)
    {
        $this->responses = $responses;
    }

    public function send(Request $request, ?RequestOptions $options = null): Response
    {
        $this->requests[] = $request;
        $response = array_shift($this->responses);
        if ($response === null) {
            throw new \RuntimeException('No queued response for ' . $request->getUri());
        }
        return $response;
    }

    public static function json(array $data, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => ['application/json']], (string) json_encode($data));
    }
}
