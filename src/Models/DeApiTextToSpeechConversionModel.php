<?php

declare(strict_types=1);

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for logs and API errors, not HTML; callers escape them on output.

namespace DeApi\AiProvider\Models;

use DeApi\AiProvider\Provider\DeApiProvider;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\TextToSpeechConversion\Contracts\TextToSpeechConversionModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

/**
 * A deAPI text-to-speech model, using the OpenAI-compatible `/audio/speech` endpoint.
 *
 * deAPI returns MP3 only. Voices are model-specific (for example `af_nova` or `bm_george` on Kokoro); the OpenAI
 * voice names (`alloy`, `nova`, ...) work as aliases on every model.
 *
 * @since 0.1.0
 */
class DeApiTextToSpeechConversionModel extends AbstractApiBasedModel implements
    TextToSpeechConversionModelInterface
{
    /**
     * The voice used when none is configured.
     *
     * @since 0.1.0
     */
    private const DEFAULT_VOICE = 'alloy';

    /**
     * The only output MIME type deAPI produces.
     *
     * @since 0.1.0
     */
    private const MIME_TYPE = 'audio/mpeg';

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    public function convertTextToSpeechResult(array $prompt): GenerativeAiResult
    {
        $mimeType = $this->getConfig()->getOutputMimeType();
        if ($mimeType !== null && $mimeType !== '' && $mimeType !== self::MIME_TYPE) {
            throw new InvalidArgumentException(
                sprintf('deAPI text to speech only produces audio/mpeg, not "%s".', $mimeType)
            );
        }

        $request = new Request(
            HttpMethodEnum::POST(),
            DeApiProvider::url('audio/speech'),
            ['Content-Type' => 'application/json'],
            $this->prepareConvertParams($prompt),
            DeApiProvider::requestOptions($this->getRequestOptions())
        );
        $request = $this->getRequestAuthentication()->authenticateRequest($request);

        $response = $this->getHttpTransporter()->send($request);
        ResponseUtil::throwIfNotSuccessful($response);

        return $this->parseResponseToGenerativeAiResult($response);
    }

    /**
     * Prepares the request parameters.
     *
     * @since 0.1.0
     *
     * @param list<Message> $prompt The prompt with the text to speak.
     * @return array<string, mixed> The request parameters.
     * @throws InvalidArgumentException If a custom option conflicts with an existing parameter.
     */
    protected function prepareConvertParams(array $prompt): array
    {
        $voice = $this->getConfig()->getOutputSpeechVoice();

        $params = [
            'model'           => $this->metadata()->getId(),
            'input'           => $this->preparePromptText($prompt),
            'voice'           => ($voice === null || $voice === '') ? self::DEFAULT_VOICE : $voice,
            'response_format' => 'mp3',
        ];

        // Custom options, for example `speed` (Kokoro: 0.5-2.0) or `language`.
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
     * Joins all text parts of the prompt.
     *
     * @since 0.1.0
     *
     * @param list<Message> $prompt The prompt messages.
     * @return string The text to convert.
     * @throws InvalidArgumentException If the prompt has no text.
     */
    protected function preparePromptText(array $prompt): string
    {
        $text = '';
        foreach ($prompt as $message) {
            foreach ($message->getParts() as $part) {
                $partText = $part->getText();
                if ($partText !== null) {
                    $text .= ($text === '' ? '' : "\n") . $partText;
                }
            }
        }

        if ($text === '') {
            throw new InvalidArgumentException('The prompt must contain text to convert to speech.');
        }

        return $text;
    }

    /**
     * Wraps the audio bytes of the response in a result.
     *
     * @since 0.1.0
     *
     * @param Response $response The response with the MP3 body.
     * @return GenerativeAiResult The result.
     */
    protected function parseResponseToGenerativeAiResult(Response $response): GenerativeAiResult
    {
        $body = $response->getBody();
        if ($body === null || $body === '') {
            throw ResponseException::fromMissingData($this->providerMetadata()->getName(), 'body');
        }

        $audioFile = new File(base64_encode($body), self::MIME_TYPE);
        $message   = new Message(MessageRoleEnum::model(), [new MessagePart($audioFile)]);
        $candidate = new Candidate($message, FinishReasonEnum::stop());

        return new GenerativeAiResult(
            uniqid('deapi-tts-', true),
            [$candidate],
            new TokenUsage(0, 0, 0),
            $this->providerMetadata(),
            $this->metadata()
        );
    }
}
