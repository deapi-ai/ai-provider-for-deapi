# AI Provider for deAPI

[deAPI](https://deapi.ai) provider for the [WordPress AI Client](https://make.wordpress.org/core/2026/03/24/introducing-the-ai-client-in-wordpress-7-0/) (WordPress 7.0+) and the [PHP AI Client SDK](https://github.com/WordPress/php-ai-client).

Once the plugin is active and an API key is saved under **Settings > Connectors**, any plugin built on the AI Client can use deAPI's open models:

| Capability | Models | Notes |
|---|---|---|
| Image generation | `Flux1schnell`, `ZImageTurbo_INT8`, `Flux_2_Klein_4B_BF16`, `ZAnimeDistill_8Step_INT8` | PNG; FLUX.1 also landscape and portrait |
| Image editing | `Flux_2_Klein_4B_BF16` | Up to 3 input images, inline or public URL |
| Video generation | `Ltx2_5_22B_Dist_INT8`, `MiniMaxH3_33B_Turbo_INT8` | MP4, text-to-video or first-frame image; blocks until the video is ready |
| Text-to-speech | `Kokoro`, `Chatterbox`, `Qwen3_TTS_12Hz_1_7B_CustomVoice` | MP3; OpenAI voice names work as aliases |
| Embeddings | `Bge_M3_FP16` | 1024 dimensions; needs AI Client 1.4+ |

deAPI has no chat models, so text features keep using your other providers. Requests go to deAPI's OpenAI-compatible gateway at `https://oai.deapi.ai/v1` ([docs](https://docs.deapi.ai/openai-compatibility)).

## Usage

```php
use WordPress\AiClient\Files\Enums\MediaOrientationEnum;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;

// Image
$image = wp_ai_client_prompt( 'A red fox in a snowy forest at golden hour' )
	->using_model_preference( array( 'deapi', 'Flux1schnell' ) )
	->as_output_media_orientation( MediaOrientationEnum::landscape() )
	->generate_image();

// Edit: add the source image to the prompt
$edited = wp_ai_client_prompt( 'Make it night with a full moon' )
	->with_file( $image )
	->using_model_preference( array( 'deapi', 'Flux_2_Klein_4B_BF16' ) )
	->generate_image();

// Video (run it from WP-CLI, cron or a background job: it takes 30 s to 2 min)
$video = wp_ai_client_prompt( 'A paper boat drifting down a rain-soaked street' )
	->using_model_preference( array( 'deapi', 'Ltx2_5_22B_Dist_INT8' ) )
	->using_model_config( ModelConfig::fromArray( array( 'customOptions' => array( 'seconds' => 5 ) ) ) )
	->as_output_media_aspect_ratio( '16:9' )
	->generate_video();

// Speech
$mp3 = wp_ai_client_prompt( 'Hello from deAPI.' )
	->using_model_preference( array( 'deapi', 'Kokoro' ) )
	->as_output_speech_voice( 'af_nova' )
	->convert_text_to_speech();
```

The API key can also come from the `DEAPI_API_KEY` environment variable or constant. Get a key at [app.deapi.ai](https://app.deapi.ai/register).

## Development

```bash
composer install
vendor/bin/phpunit
```

The tests mock the HTTP layer and run on PHP 7.4+ against php-ai-client 1.3.1 (bundled with WordPress 7.0 and 7.1) and 1.5.

Releases: bump `Version` in `ai-provider-for-deapi.php` and `Stable tag` in `readme.txt`, then publish a GitHub release. The deploy workflow pushes it to wordpress.org (it needs the `SVN_USERNAME` and `SVN_PASSWORD` secrets).

## License

GPL-2.0-or-later. The model classes follow the structure of the [AI Provider for OpenAI](https://github.com/WordPress/ai-provider-for-openai) plugin by the WordPress AI Team (GPL-2.0-or-later).
