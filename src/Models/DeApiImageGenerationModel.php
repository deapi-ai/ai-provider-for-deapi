<?php

declare(strict_types=1);

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for logs and API errors, not HTML; callers escape them on output.

namespace DeApi\AiProvider\Models;

use DeApi\AiProvider\Provider\DeApiProvider;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleImageGenerationModel;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;

/**
 * A deAPI image model, using the OpenAI-compatible Images API.
 *
 * Text-only prompts go to `/images/generations`. When the prompt also contains one or more images, the request
 * goes to `/images/edits` (only models that declare text + image input, such as FLUX.2 [klein], support this).
 *
 * @since 0.1.0
 */
class DeApiImageGenerationModel extends AbstractOpenAiCompatibleImageGenerationModel
{
    /**
     * The maximum number of input images an edit request accepts.
     *
     * @since 0.1.0
     */
    public const MAX_EDIT_IMAGES = 3;

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     *
     * @param list<Message> $prompt The prompt to generate or edit an image for.
     * @return GenerativeAiResult The generative AI result.
     */
    public function generateImageResult(array $prompt): GenerativeAiResult
    {
        if ($this->extractImages($prompt) !== []) {
            return $this->generateImageEditResult($prompt);
        }

        return parent::generateImageResult($prompt);
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    protected function createRequest(
        HttpMethodEnum $method,
        string $path,
        array $headers = [],
        $data = null
    ): Request {
        return new Request(
            $method,
            DeApiProvider::url($path),
            $headers,
            $data,
            DeApiProvider::requestOptions($this->getRequestOptions())
        );
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    protected function getResultId(array $responseData): string
    {
        if (isset($responseData['id']) && is_string($responseData['id'])) {
            return $responseData['id'];
        }

        // The Images API returns a `created` timestamp instead of an `id`.
        return isset($responseData['created']) && is_int($responseData['created'])
            ? 'img-' . $responseData['created']
            : '';
    }

    /**
     * Generates an edit result using the `/images/edits` endpoint.
     *
     * The request uses the JSON body variant, so both inline (base64) and public remote images are supported.
     *
     * @since 0.1.0
     *
     * @param list<Message> $prompt The prompt with the edit instruction and the source image(s).
     * @return GenerativeAiResult The generative AI result.
     */
    protected function generateImageEditResult(array $prompt): GenerativeAiResult
    {
        $params = $this->prepareEditParams($prompt);

        $request = $this->createRequest(
            HttpMethodEnum::POST(),
            'images/edits',
            ['Content-Type' => 'application/json'],
            $params
        );
        $request = $this->getRequestAuthentication()->authenticateRequest($request);

        $response = $this->getHttpTransporter()->send($request);
        $this->throwIfNotSuccessful($response);

        // deAPI always returns PNG.
        return $this->parseResponseToGenerativeAiResult($response, 'image/png');
    }

    /**
     * Prepares the JSON parameters for an edit request.
     *
     * @since 0.1.0
     *
     * @param list<Message> $prompt The prompt with the edit instruction and the source image(s).
     * @return array<string, mixed> The request parameters.
     * @throws InvalidArgumentException If the prompt has no text, too many images, or a custom option conflicts.
     */
    protected function prepareEditParams(array $prompt): array
    {
        $images = $this->extractImages($prompt);
        if (count($images) > self::MAX_EDIT_IMAGES) {
            throw new InvalidArgumentException(
                sprintf('deAPI image edits accept at most %d input images.', self::MAX_EDIT_IMAGES)
            );
        }

        $config = $this->getConfig();
        $params = [
            'model'  => $this->metadata()->getId(),
            'prompt' => $this->extractEditText($prompt),
            'images' => array_map(
                static function (File $image): array {
                    return ['image_url' => self::imageUrl($image)];
                },
                $images
            ),
        ];

        $candidateCount = $config->getCandidateCount();
        if ($candidateCount !== null) {
            $params['n'] = $candidateCount;
        }

        $outputFileType = $config->getOutputFileType();
        $params['response_format'] = $outputFileType !== null && $outputFileType->isRemote() ? 'url' : 'b64_json';

        $orientation = $config->getOutputMediaOrientation();
        $aspectRatio = $config->getOutputMediaAspectRatio();
        if ($orientation !== null || $aspectRatio !== null) {
            $params['size'] = $this->prepareSizeParam($orientation, $aspectRatio);
        }

        foreach ($config->getCustomOptions() as $key => $value) {
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
     * Returns all image files in the prompt, in order.
     *
     * @since 0.1.0
     *
     * @param list<Message> $prompt The prompt messages.
     * @return list<File> The image files.
     */
    protected function extractImages(array $prompt): array
    {
        $images = [];
        foreach ($prompt as $message) {
            foreach ($message->getParts() as $part) {
                $file = $part->getFile();
                if ($file !== null && $file->isImage()) {
                    $images[] = $file;
                }
            }
        }
        return $images;
    }

    /**
     * Returns the edit instruction: the text of the user message(s) in the prompt.
     *
     * @since 0.1.0
     *
     * @param list<Message> $prompt The prompt messages.
     * @return string The edit instruction.
     * @throws InvalidArgumentException If the prompt has no user text.
     */
    protected function extractEditText(array $prompt): string
    {
        $texts = [];
        foreach ($prompt as $message) {
            if (!$message->getRole()->isUser()) {
                continue;
            }
            foreach ($message->getParts() as $part) {
                $text = $part->getText();
                if ($text !== null && trim($text) !== '') {
                    $texts[] = $text;
                }
            }
        }

        if ($texts === []) {
            throw new InvalidArgumentException(
                'The prompt must contain a user message with text instructions for the edit.'
            );
        }

        return implode("\n", $texts);
    }

    /**
     * Returns the `image_url` value for an image: its public URL, or a `data:` URI for inline images.
     *
     * @since 0.1.0
     *
     * @param File $image The image file.
     * @return string The URL or data URI.
     * @throws InvalidArgumentException If the image has neither.
     */
    private static function imageUrl(File $image): string
    {
        $url = $image->isRemote() ? $image->getUrl() : $image->getDataUri();
        if ($url === null || $url === '') {
            throw new InvalidArgumentException('The image to edit has no URL or inline data.');
        }
        return $url;
    }
}
