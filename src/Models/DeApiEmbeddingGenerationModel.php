<?php

declare(strict_types=1);

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for logs and API errors, not HTML; callers escape them on output.

namespace DeApi\AiProvider\Models;

use DeApi\AiProvider\Provider\DeApiProvider;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\EmbeddingGeneration\Contracts\EmbeddingGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Embedding;
use WordPress\AiClient\Results\DTO\EmbeddingResult;
use WordPress\AiClient\Results\DTO\TokenUsage;

/**
 * A deAPI embedding model, using the OpenAI-compatible `/embeddings` endpoint.
 *
 * Requires AI Client 1.4.0 or later. The provider only creates this model when the interface exists.
 *
 * @since 0.1.0
 */
class DeApiEmbeddingGenerationModel extends AbstractApiBasedModel implements EmbeddingGenerationModelInterface
{
    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    public function generateEmbeddingResult(array $input): EmbeddingResult
    {
        $request = new Request(
            HttpMethodEnum::POST(),
            DeApiProvider::url('embeddings'),
            ['Content-Type' => 'application/json'],
            $this->prepareGenerateEmbeddingsParams($input),
            // The backend embeds one string per job, so large batches take a while.
            DeApiProvider::requestOptions($this->getRequestOptions(), 120.0)
        );
        $request = $this->getRequestAuthentication()->authenticateRequest($request);

        $response = $this->getHttpTransporter()->send($request);
        ResponseUtil::throwIfNotSuccessful($response);

        return $this->parseResponseToEmbeddingResult($response);
    }

    /**
     * Prepares the request parameters.
     *
     * @since 0.1.0
     *
     * @param list<MessagePart> $input The text parts to embed.
     * @return array<string, mixed> The request parameters.
     * @throws InvalidArgumentException If the input is empty or not text.
     */
    protected function prepareGenerateEmbeddingsParams(array $input): array
    {
        if ($input === [] || !array_is_list($input)) {
            throw new InvalidArgumentException('Embedding input must be a non-empty list of message parts.');
        }

        $texts = [];
        foreach ($input as $index => $part) {
            if (!$part instanceof MessagePart || !$part->getType()->isText()) {
                throw new InvalidArgumentException(
                    sprintf('deAPI embedding input at index %d must be a text part.', $index)
                );
            }
            $text = $part->getText();
            if ($text === null || trim($text) === '') {
                throw new InvalidArgumentException(
                    sprintf('deAPI embedding input at index %d must contain non-empty text.', $index)
                );
            }
            $texts[] = $text;
        }

        // `dimensions` is not sent: deAPI always returns the model's native width (1024 for BGE-M3).
        $params = [
            'model' => $this->metadata()->getId(),
            'input' => $texts,
        ];

        foreach ($this->getConfig()->getCustomOptions() as $key => $value) {
            if (isset($params[$key])) {
                throw new InvalidArgumentException(
                    sprintf('The custom option "%s" conflicts with an existing parameter.', $key)
                );
            }
            $params[$key] = $value;
        }

        return $params;
    }

    /**
     * Parses the embeddings response.
     *
     * @since 0.1.0
     *
     * @param Response $response The response.
     * @return EmbeddingResult The embedding result.
     */
    protected function parseResponseToEmbeddingResult(Response $response): EmbeddingResult
    {
        $responseData = $response->getData();
        if (!isset($responseData['data']) || !$responseData['data']) {
            throw ResponseException::fromMissingData($this->providerMetadata()->getName(), 'data');
        }
        if (!is_array($responseData['data']) || !array_is_list($responseData['data'])) {
            throw ResponseException::fromInvalidData(
                $this->providerMetadata()->getName(),
                'data',
                'The value must be an indexed array.'
            );
        }

        $embeddings = [];
        foreach ($responseData['data'] as $index => $embeddingData) {
            if (
                !is_array($embeddingData) ||
                !isset($embeddingData['embedding']) ||
                !is_array($embeddingData['embedding'])
            ) {
                throw ResponseException::fromInvalidData(
                    $this->providerMetadata()->getName(),
                    "data[{$index}].embedding",
                    'The value must be an embedding vector.'
                );
            }
            $embeddings[] = new Embedding($embeddingData['embedding'], count($embeddingData['embedding']));
        }

        $usage = isset($responseData['usage']) && is_array($responseData['usage']) ? $responseData['usage'] : [];
        $tokenUsage = new TokenUsage(
            $usage['prompt_tokens'] ?? 0,
            0,
            $usage['total_tokens'] ?? ($usage['prompt_tokens'] ?? 0)
        );

        $additionalData = $responseData;
        unset($additionalData['id'], $additionalData['data'], $additionalData['usage']);

        return new EmbeddingResult(
            isset($responseData['id']) && is_string($responseData['id']) ? $responseData['id'] : '',
            $embeddings,
            count($embeddings[0]->getValues()),
            $tokenUsage,
            $this->providerMetadata(),
            $this->metadata(),
            $additionalData
        );
    }
}
