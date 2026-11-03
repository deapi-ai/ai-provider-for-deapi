<?php

declare(strict_types=1);

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for logs and API errors, not HTML; callers escape them on output.

namespace DeApi\AiProvider\Models;

use DeApi\AiProvider\Provider\DeApiProvider;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Files\Enums\MediaOrientationEnum;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\VideoGeneration\Contracts\VideoGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

/**
 * A deAPI video model, using the OpenAI-compatible (Sora-shaped) Videos API.
 *
 * The API is asynchronous: `POST /videos` creates a job, `GET /videos/{id}` reports its status and
 * `GET /videos/{id}/content` returns the MP4 once it is completed. This model waits for the job, so a call
 * blocks until the video is ready (typically 30-120 seconds) or {@see self::MAX_WAIT_SECONDS} pass.
 *
 * Text-to-video uses the text of the prompt. When the prompt also contains an image, it is sent as the first
 * frame (`input_reference`). The clip length is set with the `seconds` custom option.
 *
 * @since 0.1.0
 */
class DeApiVideoGenerationModel extends AbstractApiBasedModel implements VideoGenerationModelInterface
{
    /**
     * How long to wait for a job to finish, in seconds.
     *
     * @since 0.1.0
     */
    public const MAX_WAIT_SECONDS = 600;

    /**
     * Delay between status checks, in seconds.
     *
     * @since 0.1.0
     */
    public const POLL_INTERVAL_SECONDS = 5;

    /**
     * Output sizes by aspect ratio. Every supported video model accepts these five sizes.
     *
     * @since 0.1.0
     *
     * @var array<string, string>
     */
    public const SIZES = [
        '1:1'  => '768x768',
        '16:9' => '1344x768',
        '9:16' => '768x1344',
        '4:3'  => '1024x768',
        '3:4'  => '768x1024',
    ];

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    public function generateVideoResult(array $prompt): GenerativeAiResult
    {
        $job = $this->sendJsonRequest(HttpMethodEnum::POST(), 'videos', $this->prepareGenerateVideoParams($prompt));
        $videoId = isset($job['id']) && is_string($job['id']) ? $job['id'] : '';
        if ($videoId === '') {
            throw ResponseException::fromMissingData($this->providerMetadata()->getName(), 'id');
        }

        $job = $this->waitForCompletion($videoId, $job);

        $content = $this->sendRequest(
            $this->createRequest(HttpMethodEnum::GET(), 'videos/' . rawurlencode($videoId) . '/content', [], null, 300.0)
        );
        $body = $content->getBody();
        if ($body === null || $body === '') {
            throw ResponseException::fromMissingData($this->providerMetadata()->getName(), 'content');
        }

        $videoFile = new File(base64_encode($body), 'video/mp4');
        $message   = new Message(MessageRoleEnum::model(), [new MessagePart($videoFile)]);

        $additionalData = $job;
        unset($additionalData['id']);

        return new GenerativeAiResult(
            $videoId,
            [new Candidate($message, FinishReasonEnum::stop())],
            new TokenUsage(0, 0, 0),
            $this->providerMetadata(),
            $this->metadata(),
            $additionalData
        );
    }

    /**
     * Prepares the parameters for `POST /videos`.
     *
     * @since 0.1.0
     *
     * @param list<Message> $prompt The prompt: text, and optionally one image used as the first frame.
     * @return array<string, mixed> The request parameters.
     * @throws InvalidArgumentException If the prompt is invalid or a custom option conflicts.
     */
    protected function prepareGenerateVideoParams(array $prompt): array
    {
        $config = $this->getConfig();

        $candidateCount = $config->getCandidateCount();
        if ($candidateCount !== null && $candidateCount > 1) {
            throw new InvalidArgumentException('deAPI generates one video per request.');
        }

        $texts  = [];
        $images = [];
        foreach ($prompt as $message) {
            foreach ($message->getParts() as $part) {
                $file = $part->getFile();
                if ($file !== null && $file->isImage()) {
                    $images[] = $file;
                    continue;
                }
                $text = $part->getText();
                if ($text !== null && trim($text) !== '' && $message->getRole()->isUser()) {
                    $texts[] = $text;
                }
            }
        }

        if ($texts === []) {
            throw new InvalidArgumentException('The prompt must contain text describing the video.');
        }
        if (count($images) > 1) {
            throw new InvalidArgumentException('deAPI video generation accepts at most one image (the first frame).');
        }

        $params = [
            'model'  => $this->metadata()->getId(),
            'prompt' => implode("\n", $texts),
        ];

        if ($images !== []) {
            $image = $images[0];
            $url = $image->isRemote() ? $image->getUrl() : $image->getDataUri();
            if ($url === null || $url === '') {
                throw new InvalidArgumentException('The first-frame image has no URL or inline data.');
            }
            $params['input_reference'] = ['image_url' => $url];
        }

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
            // The API expects `seconds` as a string, as in the OpenAI spec.
            $params[$key] = $key === 'seconds' && is_int($value) ? (string) $value : $value;
        }

        return $params;
    }

    /**
     * Maps an orientation or aspect ratio to a size.
     *
     * @since 0.1.0
     *
     * @param MediaOrientationEnum|null $orientation The requested orientation.
     * @param string|null $aspectRatio The requested aspect ratio; takes precedence.
     * @return string The size, for example `1344x768`.
     * @throws InvalidArgumentException If the aspect ratio is not supported.
     */
    protected function prepareSizeParam(?MediaOrientationEnum $orientation, ?string $aspectRatio): string
    {
        if ($aspectRatio !== null) {
            if (!isset(self::SIZES[$aspectRatio])) {
                throw new InvalidArgumentException(
                    sprintf('The aspect ratio "%s" is not supported for deAPI video.', $aspectRatio)
                );
            }
            return self::SIZES[$aspectRatio];
        }
        if ($orientation !== null && $orientation->isLandscape()) {
            return self::SIZES['16:9'];
        }
        if ($orientation !== null && $orientation->isPortrait()) {
            return self::SIZES['9:16'];
        }
        return self::SIZES['1:1'];
    }

    /**
     * Polls the job until it is completed.
     *
     * @since 0.1.0
     *
     * @param string $videoId The video job ID.
     * @param array<string, mixed> $job The job object returned on creation.
     * @return array<string, mixed> The completed job object.
     * @throws RuntimeException If the job fails or does not finish in time.
     */
    protected function waitForCompletion(string $videoId, array $job): array
    {
        $waited = 0;
        while (true) {
            $status = isset($job['status']) && is_string($job['status']) ? $job['status'] : '';
            if ($status === 'completed') {
                return $job;
            }
            if ($status === 'failed') {
                $error = isset($job['error']['message']) && is_string($job['error']['message'])
                    ? $job['error']['message']
                    : 'unknown error';
                throw new RuntimeException(sprintf('deAPI video job %s failed: %s', $videoId, $error));
            }
            if ($waited >= self::MAX_WAIT_SECONDS) {
                throw new RuntimeException(
                    sprintf('deAPI video job %s did not finish within %d seconds.', $videoId, self::MAX_WAIT_SECONDS)
                );
            }

            $this->pause(self::POLL_INTERVAL_SECONDS);
            $waited += self::POLL_INTERVAL_SECONDS;
            $job = $this->sendJsonRequest(HttpMethodEnum::GET(), 'videos/' . rawurlencode($videoId));
        }
    }

    /**
     * Waits between status checks. Separate so tests can skip the delay.
     *
     * @since 0.1.0
     *
     * @param int $seconds Seconds to wait.
     * @return void
     */
    protected function pause(int $seconds): void
    {
        sleep($seconds);
    }

    /**
     * Sends a request with an optional JSON body and returns the decoded JSON response.
     *
     * @since 0.1.0
     *
     * @param HttpMethodEnum $method The HTTP method.
     * @param string $path The API path.
     * @param array<string, mixed>|null $data The JSON body, if any.
     * @return array<string, mixed> The decoded response.
     */
    protected function sendJsonRequest(HttpMethodEnum $method, string $path, ?array $data = null): array
    {
        $headers = $data !== null ? ['Content-Type' => 'application/json'] : [];
        $response = $this->sendRequest($this->createRequest($method, $path, $headers, $data, 60.0));
        $responseData = $response->getData();
        if ($responseData === null) {
            throw ResponseException::fromMissingData($this->providerMetadata()->getName(), 'body');
        }
        return $responseData;
    }

    /**
     * Authenticates and sends a request, throwing on an error status.
     *
     * @since 0.1.0
     *
     * @param Request $request The request.
     * @return Response The successful response.
     */
    protected function sendRequest(Request $request): Response
    {
        $request = $this->getRequestAuthentication()->authenticateRequest($request);
        $response = $this->getHttpTransporter()->send($request);
        ResponseUtil::throwIfNotSuccessful($response);
        return $response;
    }

    /**
     * Creates a request to the deAPI gateway.
     *
     * @since 0.1.0
     *
     * @param HttpMethodEnum $method The HTTP method.
     * @param string $path The API path.
     * @param array<string, string> $headers The headers.
     * @param array<string, mixed>|null $data The JSON body, if any.
     * @param float $timeout The timeout to use when the caller set none.
     * @return Request The request.
     */
    protected function createRequest(
        HttpMethodEnum $method,
        string $path,
        array $headers,
        ?array $data,
        float $timeout
    ): Request {
        return new Request(
            $method,
            DeApiProvider::url($path),
            $headers,
            $data,
            DeApiProvider::requestOptions($this->getRequestOptions(), $timeout)
        );
    }
}
