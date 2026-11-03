<?php

declare(strict_types=1);

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain text for logs and API errors, not HTML; callers escape them on output.

namespace DeApi\AiProvider\Provider;

use DeApi\AiProvider\Metadata\DeApiModelMetadataDirectory;
use DeApi\AiProvider\Models\DeApiEmbeddingGenerationModel;
use DeApi\AiProvider\Models\DeApiImageGenerationModel;
use DeApi\AiProvider\Models\DeApiTextToSpeechConversionModel;
use DeApi\AiProvider\Models\DeApiVideoGenerationModel;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\EmbeddingGeneration\Contracts\EmbeddingGenerationModelInterface;

/**
 * The deAPI provider.
 *
 * deAPI serves open models through an OpenAI-compatible gateway. The gateway has no chat endpoint, so this
 * provider offers image generation and editing, video generation, text-to-speech and embeddings only.
 *
 * @since 0.1.0
 */
class DeApiProvider extends AbstractApiProvider
{
    /**
     * Default request timeout in seconds for generation requests.
     *
     * The gateway holds the connection open until the GPU job finishes. Image jobs usually take 5-60 seconds,
     * edits and long speech can take several minutes, while the WordPress HTTP API defaults to 5 seconds.
     *
     * @since 0.1.0
     */
    public const DEFAULT_TIMEOUT = 300.0;

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    protected static function baseUrl(): string
    {
        return 'https://oai.deapi.ai/v1';
    }

    /**
     * Returns request options with a timeout suitable for deAPI generation jobs.
     *
     * Options passed by the caller are kept. Only a missing timeout is filled in.
     *
     * @since 0.1.0
     *
     * @param RequestOptions|null $options The caller's request options, if any.
     * @param float $timeout The timeout to use when the caller did not set one.
     * @return RequestOptions The request options to send.
     */
    public static function requestOptions(?RequestOptions $options, float $timeout = self::DEFAULT_TIMEOUT): RequestOptions
    {
        $options = $options !== null ? clone $options : new RequestOptions();
        if ($options->getTimeout() === null) {
            $options->setTimeout($timeout);
        }
        return $options;
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    protected static function createModel(
        ModelMetadata $modelMetadata,
        ProviderMetadata $providerMetadata
    ): ModelInterface {
        $capabilities = $modelMetadata->getSupportedCapabilities();
        foreach ($capabilities as $capability) {
            if ($capability->isImageGeneration()) {
                return new DeApiImageGenerationModel($modelMetadata, $providerMetadata);
            }
            if ($capability->isTextToSpeechConversion()) {
                return new DeApiTextToSpeechConversionModel($modelMetadata, $providerMetadata);
            }
            if ($capability->isVideoGeneration()) {
                return new DeApiVideoGenerationModel($modelMetadata, $providerMetadata);
            }
            // Embedding generation support was added in AI Client 1.4.0.
            if (
                $capability->isEmbeddingGeneration() &&
                interface_exists(EmbeddingGenerationModelInterface::class)
            ) {
                return new DeApiEmbeddingGenerationModel($modelMetadata, $providerMetadata);
            }
        }

        throw new RuntimeException(
            'Unsupported model capabilities: ' . implode(', ', $capabilities)
        );
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    protected static function createProviderMetadata(): ProviderMetadata
    {
        $providerMetadataArgs = [
            'deapi',
            'deAPI',
            ProviderTypeEnum::cloud(),
            'https://app.deapi.ai/register',
            RequestAuthenticationMethod::apiKey(),
        ];
        // Provider description support was added in AI Client 1.2.0.
        if (version_compare(AiClient::VERSION, '1.2.0', '>=')) {
            $description = 'Image and video generation, image editing, text-to-speech and embeddings with open models such as FLUX, LTX and MiniMax.';
            if (function_exists('__')) {
                // phpcs:ignore Generic.Files.LineLength.TooLong
                $description = __('Image and video generation, image editing, text-to-speech and embeddings with open models such as FLUX, LTX and MiniMax.', 'ai-provider-for-deapi');
            }
            $providerMetadataArgs[] = $description;
        }
        // Provider logoPath support was added in AI Client 1.3.0.
        if (version_compare(AiClient::VERSION, '1.3.0', '>=')) {
            $pluginDir = dirname(__DIR__, 2);
            if (defined('AI_PROVIDER_FOR_DEAPI_PLUGIN_DIR')) {
                $definedPluginDir = constant('AI_PROVIDER_FOR_DEAPI_PLUGIN_DIR');
                if (is_string($definedPluginDir)) {
                    $pluginDir = $definedPluginDir;
                }
            }
            $providerMetadataArgs[] = $pluginDir . '/assets/images/deapi.svg';
        }
        return new ProviderMetadata(...$providerMetadataArgs);
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    protected static function createProviderAvailability(): ProviderAvailabilityInterface
    {
        // A valid key can list models. The gateway answers 401 for a missing or rejected key.
        return new DeApiProviderAvailability();
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface
    {
        return new DeApiModelMetadataDirectory();
    }
}
