<?php

declare(strict_types=1);

namespace DeApi\AiProvider\Tests;

use DeApi\AiProvider\Metadata\DeApiModelMetadataDirectory;
use DeApi\AiProvider\Models\DeApiEmbeddingGenerationModel;
use DeApi\AiProvider\Models\DeApiImageGenerationModel;
use DeApi\AiProvider\Models\DeApiTextToSpeechConversionModel;
use DeApi\AiProvider\Models\DeApiVideoGenerationModel;
use DeApi\AiProvider\Provider\DeApiProvider;
use PHPUnit\Framework\TestCase;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\EmbeddingGeneration\Contracts\EmbeddingGenerationModelInterface;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;

/**
 * @covers \DeApi\AiProvider\Provider\DeApiProvider
 * @covers \DeApi\AiProvider\Metadata\DeApiModelMetadataDirectory
 * @covers \DeApi\AiProvider\Models\DeApiImageGenerationModel
 * @covers \DeApi\AiProvider\Models\DeApiTextToSpeechConversionModel
 * @covers \DeApi\AiProvider\Models\DeApiEmbeddingGenerationModel
 * @covers \DeApi\AiProvider\Models\DeApiVideoGenerationModel
 */
class DeApiProviderTest extends TestCase
{
    /** A 1x1 transparent PNG. */
    private const PNG_BASE64 =
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private const MODEL_IDS = [
        'Flux_2_Klein_4B_BF16',
        'WhisperLargeV3',
        'Kokoro',
        'Bge_M3_FP16',
        'Ltx2_5_22B_Dist_INT8',
        'Flux1schnell',
        'QwenImageEdit_Plus_NF4',
        'MiniMaxH3_33B_Turbo_INT8',
    ];

    public function testProviderMetadata(): void
    {
        $metadata = DeApiProvider::metadata();

        $this->assertSame('deapi', $metadata->getId());
        $this->assertSame('deAPI', $metadata->getName());
        $this->assertSame('https://app.deapi.ai/register', $metadata->getCredentialsUrl());
        $this->assertSame('https://oai.deapi.ai/v1/models', DeApiProvider::url('models'));
    }

    public function testRequestOptionsKeepCallerTimeout(): void
    {
        $this->assertSame(300.0, DeApiProvider::requestOptions(null)->getTimeout());

        $callerOptions = new RequestOptions();
        $callerOptions->setTimeout(12.0);
        $this->assertSame(12.0, DeApiProvider::requestOptions($callerOptions)->getTimeout());

        // The caller's object is not modified.
        $emptyOptions = new RequestOptions();
        DeApiProvider::requestOptions($emptyOptions);
        $this->assertNull($emptyOptions->getTimeout());
    }

    public function testAvailabilityAsksTheApiEveryTime(): void
    {
        $availability = new \DeApi\AiProvider\Provider\DeApiProviderAvailability();
        $transporter = new FakeTransporter(
            $this->modelsResponse(),
            FakeTransporter::json(['error' => ['code' => 'invalid_api_key']], 401)
        );
        $availability->setHttpTransporter($transporter);
        $availability->setRequestAuthentication(new ApiKeyRequestAuthentication('test-key'));

        $this->assertTrue($availability->isConfigured());
        $this->assertFalse($availability->isConfigured());
        $this->assertCount(2, $transporter->requests);
        $this->assertSame('https://oai.deapi.ai/v1/models', $transporter->requests[1]->getUri());
    }

    public function testDirectoryListsOnlySupportedModelsInRankOrder(): void
    {
        $transporter = new FakeTransporter($this->modelsResponse());
        $directory = $this->directory($transporter);

        $ids = array_map(
            static function (ModelMetadata $m): string {
                return $m->getId();
            },
            $directory->listModelMetadata()
        );

        $expected = ['Flux1schnell', 'Flux_2_Klein_4B_BF16', 'Ltx2_5_22B_Dist_INT8', 'MiniMaxH3_33B_Turbo_INT8', 'Kokoro'];
        if (interface_exists(EmbeddingGenerationModelInterface::class)) {
            $expected[] = 'Bge_M3_FP16';
        }
        $this->assertSame($expected, $ids);

        $request = $transporter->requests[0];
        $this->assertSame('https://oai.deapi.ai/v1/models', $request->getUri());
        $this->assertSame('Bearer test-key', $request->getHeaderAsString('Authorization'));
        $this->assertSame(30.0, $request->getOptions()->getTimeout());
    }

    public function testOnlyKleinAcceptsImageInput(): void
    {
        $directory = $this->directory(new FakeTransporter($this->modelsResponse()));

        $this->assertTrue($this->acceptsImageInput($directory->getModelMetadata('Flux_2_Klein_4B_BF16')));
        $this->assertFalse($this->acceptsImageInput($directory->getModelMetadata('Flux1schnell')));
    }

    public function testImageGenerationRequest(): void
    {
        $transporter = new FakeTransporter(
            FakeTransporter::json(['created' => 1755691200, 'data' => [['b64_json' => self::PNG_BASE64]]])
        );
        $model = $this->model('Flux1schnell', $transporter);
        $model->setConfig(ModelConfig::fromArray([
            'candidateCount' => 2,
            'outputMediaOrientation' => 'landscape',
        ]));

        $result = $model->generateImageResult([$this->userMessage(new MessagePart('a red fox'))]);

        $request = $transporter->requests[0];
        $this->assertSame('https://oai.deapi.ai/v1/images/generations', $request->getUri());
        $this->assertSame(
            [
                'model' => 'Flux1schnell',
                'prompt' => 'a red fox',
                'n' => 2,
                'response_format' => 'b64_json',
                'size' => '1536x1024',
            ],
            $request->getData()
        );
        $this->assertSame(300.0, $request->getOptions()->getTimeout());

        $this->assertSame('img-1755691200', $result->getId());
        $file = $result->getCandidates()[0]->getMessage()->getParts()[0]->getFile();
        $this->assertSame('image/png', $file->getMimeType());
        $this->assertSame(self::PNG_BASE64, $file->getBase64Data());
    }

    public function testImageEditSendsJsonWithAllImages(): void
    {
        $transporter = new FakeTransporter(
            FakeTransporter::json([
                'created' => 1755691200,
                'data' => [['url' => 'https://results.deapi.ai/1/abc.png?X-Amz-Expires=18000']],
            ])
        );
        $model = $this->model('Flux_2_Klein_4B_BF16', $transporter);
        $model->setConfig(ModelConfig::fromArray(['outputFileType' => 'remote']));

        $result = $model->generateImageResult([
            $this->userMessage(
                new MessagePart('put the cat on the sofa'),
                new MessagePart(new File('data:image/png;base64,' . self::PNG_BASE64)),
                new MessagePart(new File('https://example.com/sofa.png', 'image/png'))
            ),
        ]);

        $request = $transporter->requests[0];
        $this->assertSame('https://oai.deapi.ai/v1/images/edits', $request->getUri());
        $this->assertSame('application/json', $request->getHeaderAsString('Content-Type'));
        $this->assertSame(
            [
                'model' => 'Flux_2_Klein_4B_BF16',
                'prompt' => 'put the cat on the sofa',
                'images' => [
                    ['image_url' => 'data:image/png;base64,' . self::PNG_BASE64],
                    ['image_url' => 'https://example.com/sofa.png'],
                ],
                'response_format' => 'url',
            ],
            $request->getData()
        );

        $file = $result->getCandidates()[0]->getMessage()->getParts()[0]->getFile();
        $this->assertTrue($file->isRemote());
        $this->assertStringStartsWith('https://results.deapi.ai/', (string) $file->getUrl());
    }

    public function testImageEditRejectsMoreThanThreeImages(): void
    {
        $model = $this->model('Flux_2_Klein_4B_BF16', new FakeTransporter());
        $image = new MessagePart(new File('data:image/png;base64,' . self::PNG_BASE64));

        $this->expectException(InvalidArgumentException::class);
        $model->generateImageResult([
            $this->userMessage(new MessagePart('merge'), $image, $image, $image, $image),
        ]);
    }

    public function testTextToSpeechRequest(): void
    {
        $transporter = new FakeTransporter(new Response(200, ['Content-Type' => ['audio/mpeg']], 'ID3-mp3-bytes'));
        $model = $this->model('Kokoro', $transporter);

        $result = $model->convertTextToSpeechResult([$this->userMessage(new MessagePart('Hello'))]);

        $request = $transporter->requests[0];
        $this->assertSame('https://oai.deapi.ai/v1/audio/speech', $request->getUri());
        $this->assertSame(
            ['model' => 'Kokoro', 'input' => 'Hello', 'voice' => 'alloy', 'response_format' => 'mp3'],
            $request->getData()
        );
        $file = $result->getCandidates()[0]->getMessage()->getParts()[0]->getFile();
        $this->assertSame('audio/mpeg', $file->getMimeType());
        $this->assertSame(base64_encode('ID3-mp3-bytes'), $file->getBase64Data());
    }

    public function testTextToSpeechUsesConfiguredVoiceAndRejectsOtherFormats(): void
    {
        $transporter = new FakeTransporter(new Response(200, [], 'mp3'));
        $model = $this->model('Kokoro', $transporter);
        $model->setConfig(ModelConfig::fromArray(['outputSpeechVoice' => 'bm_george']));
        $model->convertTextToSpeechResult([$this->userMessage(new MessagePart('Hi'))]);
        $this->assertSame('bm_george', $transporter->requests[0]->getData()['voice']);

        $model->setConfig(ModelConfig::fromArray(['outputMimeType' => 'audio/wav']));
        $this->expectException(InvalidArgumentException::class);
        $model->convertTextToSpeechResult([$this->userMessage(new MessagePart('Hi'))]);
    }

    public function testEmbeddingRequest(): void
    {
        if (!interface_exists(EmbeddingGenerationModelInterface::class)) {
            $this->markTestSkipped('Embedding generation requires PHP AI Client 1.4.0 or later.');
        }

        $transporter = new FakeTransporter(FakeTransporter::json([
            'object' => 'list',
            'data' => [
                ['object' => 'embedding', 'index' => 0, 'embedding' => [0.1, 0.2, 0.3]],
                ['object' => 'embedding', 'index' => 1, 'embedding' => [0.4, 0.5, 0.6]],
            ],
            'model' => 'Bge_M3_FP16',
            'usage' => ['prompt_tokens' => 0, 'total_tokens' => 0],
        ]));
        $model = $this->model('Bge_M3_FP16', $transporter);

        $result = $model->generateEmbeddingResult([new MessagePart('first'), new MessagePart('second')]);

        $request = $transporter->requests[0];
        $this->assertSame('https://oai.deapi.ai/v1/embeddings', $request->getUri());
        $this->assertSame(['model' => 'Bge_M3_FP16', 'input' => ['first', 'second']], $request->getData());
        $this->assertSame(120.0, $request->getOptions()->getTimeout());
        $this->assertCount(2, $result->getEmbeddings());
        $this->assertSame([0.4, 0.5, 0.6], $result->getEmbeddings()[1]->getValues());
    }

    public function testVideoGenerationPollsAndDownloadsContent(): void
    {
        $job = ['id' => 'vid-1', 'object' => 'video', 'model' => 'Ltx2_5_22B_Dist_INT8', 'seconds' => '5'];
        $transporter = new FakeTransporter(
            FakeTransporter::json($job + ['status' => 'queued', 'progress' => 0]),
            FakeTransporter::json($job + ['status' => 'in_progress', 'progress' => 50]),
            FakeTransporter::json($job + ['status' => 'completed', 'progress' => 100]),
            new Response(200, ['Content-Type' => ['video/mp4']], 'mp4-bytes')
        );
        $model = $this->model('Ltx2_5_22B_Dist_INT8', $transporter);
        $model->setConfig(ModelConfig::fromArray([
            'outputMediaAspectRatio' => '16:9',
            'customOptions' => ['seconds' => 5],
        ]));

        $result = $model->generateVideoResult([
            $this->userMessage(
                new MessagePart('the camera pushes in slowly'),
                new MessagePart(new File('https://example.com/frame.png', 'image/png'))
            ),
        ]);

        $requests = $transporter->requests;
        $this->assertCount(4, $requests);
        $this->assertSame('https://oai.deapi.ai/v1/videos', $requests[0]->getUri());
        $this->assertSame(
            [
                'model' => 'Ltx2_5_22B_Dist_INT8',
                'prompt' => 'the camera pushes in slowly',
                'input_reference' => ['image_url' => 'https://example.com/frame.png'],
                'size' => '1344x768',
                'seconds' => '5',
            ],
            $requests[0]->getData()
        );
        $this->assertSame('https://oai.deapi.ai/v1/videos/vid-1', $requests[1]->getUri());
        $this->assertSame('https://oai.deapi.ai/v1/videos/vid-1/content', $requests[3]->getUri());
        $this->assertSame('Bearer test-key', $requests[3]->getHeaderAsString('Authorization'));
        $this->assertSame([5, 5], $model->pauses);

        $this->assertSame('vid-1', $result->getId());
        $file = $result->getCandidates()[0]->getMessage()->getParts()[0]->getFile();
        $this->assertSame('video/mp4', $file->getMimeType());
        $this->assertSame(base64_encode('mp4-bytes'), $file->getBase64Data());
    }

    public function testVideoGenerationReportsFailedJob(): void
    {
        $transporter = new FakeTransporter(
            FakeTransporter::json(['id' => 'vid-2', 'status' => 'queued']),
            FakeTransporter::json([
                'id' => 'vid-2',
                'status' => 'failed',
                'error' => ['code' => 'job_failed', 'message' => 'Job failed during rendering.'],
            ])
        );
        $model = $this->model('MiniMaxH3_33B_Turbo_INT8', $transporter);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Job failed during rendering.');
        $model->generateVideoResult([$this->userMessage(new MessagePart('a sunrise'))]);
    }

    public function testVideoGenerationRejectsSeveralImages(): void
    {
        $model = $this->model('MiniMaxH3_33B_Turbo_INT8', new FakeTransporter());
        $image = new MessagePart(new File('https://example.com/frame.png', 'image/png'));

        $this->expectException(InvalidArgumentException::class);
        $model->generateVideoResult([$this->userMessage(new MessagePart('a sunrise'), $image, $image)]);
    }

    private function modelsResponse(): Response
    {
        return FakeTransporter::json([
            'object' => 'list',
            'data' => array_map(
                static function (string $id): array {
                    return ['id' => $id, 'object' => 'model', 'created' => 1776902400, 'owned_by' => 'depin'];
                },
                self::MODEL_IDS
            ),
        ]);
    }

    private function directory(FakeTransporter $transporter): DeApiModelMetadataDirectory
    {
        $directory = new DeApiModelMetadataDirectory();
        $directory->setHttpTransporter($transporter);
        $directory->setRequestAuthentication(new ApiKeyRequestAuthentication('test-key'));
        return $directory;
    }

    /**
     * @return DeApiImageGenerationModel|DeApiTextToSpeechConversionModel|DeApiEmbeddingGenerationModel|DeApiVideoGenerationModel
     */
    private function model(string $modelId, FakeTransporter $transporter)
    {
        $metadata = $this->directory(new FakeTransporter($this->modelsResponse()))->getModelMetadata($modelId);
        $providerMetadata = DeApiProvider::metadata();

        if ($modelId === 'Ltx2_5_22B_Dist_INT8' || $modelId === 'MiniMaxH3_33B_Turbo_INT8') {
            $model = new class ($metadata, $providerMetadata) extends DeApiVideoGenerationModel {
                /** @var list<int> */
                public array $pauses = [];

                protected function pause(int $seconds): void
                {
                    $this->pauses[] = $seconds;
                }
            };
        } elseif ($modelId === 'Kokoro') {
            $model = new DeApiTextToSpeechConversionModel($metadata, $providerMetadata);
        } elseif ($modelId === 'Bge_M3_FP16') {
            $model = new DeApiEmbeddingGenerationModel($metadata, $providerMetadata);
        } else {
            $model = new DeApiImageGenerationModel($metadata, $providerMetadata);
        }
        $model->setHttpTransporter($transporter);
        $model->setRequestAuthentication(new ApiKeyRequestAuthentication('test-key'));
        return $model;
    }

    private function userMessage(MessagePart ...$parts): Message
    {
        return new Message(MessageRoleEnum::user(), $parts);
    }

    private function acceptsImageInput(ModelMetadata $metadata): bool
    {
        foreach ($metadata->getSupportedOptions() as $option) {
            if ($option->getName()->equals(OptionEnum::inputModalities())) {
                foreach ((array) $option->getSupportedValues() as $combination) {
                    if (in_array(ModalityEnum::image(), $combination, false)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }
}
