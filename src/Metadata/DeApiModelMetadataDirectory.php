<?php

declare(strict_types=1);

namespace DeApi\AiProvider\Metadata;

use DeApi\AiProvider\Provider\DeApiProvider;
use WordPress\AiClient\Files\Enums\FileTypeEnum;
use WordPress\AiClient\Files\Enums\MediaOrientationEnum;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\EmbeddingGeneration\Contracts\EmbeddingGenerationModelInterface;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleModelMetadataDirectory;

/**
 * Model metadata directory for deAPI.
 *
 * `GET /v1/models` lists the models available to the account but carries no capabilities, so the capabilities
 * of the supported models are declared here. Models the AI Client cannot use (for example transcription, video,
 * or new models this version does not know yet) are left out.
 *
 * @since 0.1.0
 */
class DeApiModelMetadataDirectory extends AbstractOpenAiCompatibleModelMetadataDirectory
{
    /**
     * Image generation models and their properties.
     *
     * - `landscape`: whether the model accepts portrait and landscape sizes besides square ones.
     * - `edit`: whether the model also edits images (text + image input, routed to `/images/edits`).
     *
     * @since 0.1.0
     *
     * @var array<string, array{name: string, landscape: bool, edit: bool}>
     */
    public const IMAGE_MODELS = [
        'Flux1schnell'              => ['name' => 'FLUX.1 [schnell]', 'landscape' => true, 'edit' => false],
        'ZImageTurbo_INT8'          => ['name' => 'Z-Image Turbo', 'landscape' => false, 'edit' => false],
        'Flux_2_Klein_4B_BF16'      => ['name' => 'FLUX.2 [klein] 4B', 'landscape' => false, 'edit' => true],
        'ZAnimeDistill_8Step_INT8'  => ['name' => 'Z-Anime Distill', 'landscape' => false, 'edit' => false],
    ];

    /**
     * Text-to-speech models. Each accepts the OpenAI voice names (alloy, nova, ...) as aliases.
     *
     * @since 0.1.0
     *
     * @var array<string, string>
     */
    public const TTS_MODELS = [
        'Kokoro'                          => 'Kokoro',
        'Chatterbox'                      => 'Chatterbox',
        'Qwen3_TTS_12Hz_1_7B_CustomVoice' => 'Qwen3 TTS CustomVoice',
    ];

    /**
     * Embedding models.
     *
     * @since 0.1.0
     *
     * @var array<string, string>
     */
    public const EMBEDDING_MODELS = [
        'Bge_M3_FP16' => 'BGE-M3',
    ];

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    protected function createRequest(HttpMethodEnum $method, string $path, array $headers = [], $data = null): Request
    {
        return new Request(
            $method,
            DeApiProvider::url($path),
            $headers,
            $data,
            DeApiProvider::requestOptions(null, 30.0)
        );
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    protected function parseResponseToModelMetadataList(Response $response): array
    {
        $responseData = $response->getData();
        if (!isset($responseData['data']) || !is_array($responseData['data'])) {
            throw ResponseException::fromMissingData('deAPI', 'data');
        }

        $models = [];
        foreach ($responseData['data'] as $modelData) {
            if (!is_array($modelData) || !isset($modelData['id']) || !is_string($modelData['id'])) {
                continue;
            }
            $metadata = $this->createModelMetadata($modelData['id']);
            if ($metadata !== null) {
                $models[] = $metadata;
            }
        }

        usort(
            $models,
            static function (ModelMetadata $a, ModelMetadata $b): int {
                return self::modelRank($a->getId()) <=> self::modelRank($b->getId());
            }
        );

        return $models;
    }

    /**
     * Creates the metadata for a model ID, or returns null when the AI Client cannot use the model.
     *
     * @since 0.1.0
     *
     * @param string $modelId The model ID from `GET /v1/models`.
     * @return ModelMetadata|null The model metadata, or null if the model is not supported.
     */
    protected function createModelMetadata(string $modelId): ?ModelMetadata
    {
        if (isset(self::IMAGE_MODELS[$modelId])) {
            $model = self::IMAGE_MODELS[$modelId];
            return new ModelMetadata(
                $modelId,
                $model['name'],
                [CapabilityEnum::imageGeneration()],
                self::imageOptions($model['landscape'], $model['edit'])
            );
        }

        if (isset(self::TTS_MODELS[$modelId])) {
            return new ModelMetadata(
                $modelId,
                self::TTS_MODELS[$modelId],
                [CapabilityEnum::textToSpeechConversion()],
                [
                    new SupportedOption(OptionEnum::inputModalities(), [[ModalityEnum::text()]]),
                    new SupportedOption(OptionEnum::outputModalities(), [[ModalityEnum::audio()]]),
                    new SupportedOption(OptionEnum::outputMimeType(), ['audio/mpeg']),
                    new SupportedOption(OptionEnum::outputFileType(), [FileTypeEnum::inline()]),
                    new SupportedOption(OptionEnum::outputSpeechVoice()),
                    new SupportedOption(OptionEnum::customOptions()),
                ]
            );
        }

        // Embedding generation support was added in AI Client 1.4.0.
        if (isset(self::EMBEDDING_MODELS[$modelId]) && interface_exists(EmbeddingGenerationModelInterface::class)) {
            return new ModelMetadata(
                $modelId,
                self::EMBEDDING_MODELS[$modelId],
                [CapabilityEnum::embeddingGeneration()],
                [
                    new SupportedOption(OptionEnum::inputModalities(), [[ModalityEnum::text()]]),
                    new SupportedOption(OptionEnum::customOptions()),
                ]
            );
        }

        return null;
    }

    /**
     * Returns the supported options for an image model.
     *
     * Output is always PNG. Square sizes are 512, 768 and 1024 pixels; models with `landscape` also accept
     * 1536x1024 and 1024x1536.
     *
     * @since 0.1.0
     *
     * @param bool $landscape Whether the model supports portrait and landscape output.
     * @param bool $edit Whether the model can edit an input image.
     * @return list<SupportedOption> The supported options.
     */
    private static function imageOptions(bool $landscape, bool $edit): array
    {
        $inputModalities = [[ModalityEnum::text()]];
        if ($edit) {
            $inputModalities[] = [ModalityEnum::text(), ModalityEnum::image()];
        }

        $orientations = [MediaOrientationEnum::square()];
        $aspectRatios = ['1:1'];
        if ($landscape) {
            $orientations[] = MediaOrientationEnum::landscape();
            $orientations[] = MediaOrientationEnum::portrait();
            $aspectRatios[] = '3:2';
            $aspectRatios[] = '2:3';
        }

        return [
            new SupportedOption(OptionEnum::inputModalities(), $inputModalities),
            new SupportedOption(OptionEnum::outputModalities(), [[ModalityEnum::image()]]),
            new SupportedOption(OptionEnum::candidateCount()),
            new SupportedOption(OptionEnum::outputMimeType(), ['image/png']),
            new SupportedOption(OptionEnum::outputFileType(), [FileTypeEnum::inline(), FileTypeEnum::remote()]),
            new SupportedOption(OptionEnum::outputMediaOrientation(), $orientations),
            new SupportedOption(OptionEnum::outputMediaAspectRatio(), $aspectRatios),
            new SupportedOption(OptionEnum::customOptions()),
        ];
    }

    /**
     * Returns the sort rank of a model, so that the recommended model of each kind comes first.
     *
     * @since 0.1.0
     *
     * @param string $modelId The model ID.
     * @return int The rank; lower comes first.
     */
    private static function modelRank(string $modelId): int
    {
        $order = array_merge(
            array_keys(self::IMAGE_MODELS),
            array_keys(self::TTS_MODELS),
            array_keys(self::EMBEDDING_MODELS)
        );
        $rank = array_search($modelId, $order, true);
        return $rank === false ? PHP_INT_MAX : (int) $rank;
    }
}
