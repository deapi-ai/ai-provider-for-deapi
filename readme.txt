=== AI Provider for deAPI ===
Contributors: deapi
Tags: ai, image generation, video generation, text to speech, flux
Requires at least: 6.9
Tested up to: 7.1
Stable tag: 0.1.0
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://spdx.org/licenses/GPL-2.0-or-later.html

deAPI image and video generation, image editing, text-to-speech and embeddings with open models for the WordPress AI Client.

== Description ==

This plugin adds [deAPI](https://deapi.ai) as a provider for the WordPress AI Client, which is part of WordPress 7.0. Once it is active and an API key is saved under Settings > Connectors, any plugin built on the AI Client can generate and edit images, generate videos, create speech and compute embeddings with deAPI.

deAPI runs open-source models on a distributed GPU network and charges per request, with no subscription.

**Supported models**

* Image generation: FLUX.1 [schnell], Z-Image Turbo, Z-Anime Distill and FLUX.2 [klein] 4B.
* Image editing: FLUX.2 [klein] 4B, with up to three input images.
* Video generation: LTX-2.5 22B (3-10 seconds) and MiniMax H3 33B Turbo (8 seconds), from text or from a first-frame image. Sizes: 768x768, 1344x768, 768x1344, 1024x768 and 768x1024. Output is MP4.
* Text-to-speech: Kokoro (41 voices in 7 languages), Chatterbox and Qwen3 TTS CustomVoice. Output is MP3.
* Embeddings: BGE-M3 (1024 dimensions). Requires WordPress 7.2 or later.

The plugin lists only the models available to your deAPI account. deAPI has no chat models, so text features such as title or excerpt generation keep using your other AI providers.

**Requirements**

* WordPress 7.0 or later (the plugin loads on 6.9 but does nothing without the AI Client).
* A deAPI account and API key. You can [create an account](https://app.deapi.ai/register).

== Installation ==

1. Install and activate the plugin.
2. Create an API key in the [deAPI dashboard](https://app.deapi.ai/settings/api-keys).
3. Go to Settings > Connectors, find deAPI and paste the key. WordPress checks the key with deAPI when you save it.

You can also set the key with the `DEAPI_API_KEY` environment variable or a `DEAPI_API_KEY` constant in `wp-config.php`.

To try it, install the [AI Experiments](https://wordpress.org/plugins/ai/) plugin and use its image generation feature in the post editor.

== Frequently Asked Questions ==

= Which deAPI features are not available? =

Transcription, music generation and the other deAPI endpoints are not part of this plugin, because the WordPress AI Client does not support them yet or deAPI does not offer them through its OpenAI-compatible gateway.

= Why does image generation take a few seconds? =

deAPI waits for the GPU job to finish before it answers. Most images take 5 to 30 seconds, edits can take longer. The plugin allows up to 5 minutes per request.

= How long does video generation take? =

Usually 30 seconds to 2 minutes. The AI Client waits for the finished MP4, so call it from WP-CLI, a cron event or a background job rather than a normal page request, where the PHP time limit (often 30 seconds) can stop it first. Set the length with the `seconds` custom option, for example `['customOptions' => ['seconds' => 5]]`.

= How do I choose a voice? =

Pass a voice ID through the AI Client, for example `af_nova` or `bm_george` for Kokoro. The OpenAI voice names (`alloy`, `echo`, `fable`, `onyx`, `nova`, `shimmer`) also work. The default voice is `alloy`.

== External services ==

This plugin connects to the deAPI API at `https://oai.deapi.ai/v1` to list the models available to your account and to run the generation requests that plugins send through the WordPress AI Client. Nothing is sent until an API key is configured.

* When you save an API key under Settings > Connectors, and when the AI Client needs the model list, the plugin sends your API key to `https://oai.deapi.ai/v1/models`.
* When a plugin asks the AI Client for an image, an image edit, a video, speech or embeddings and deAPI is selected, the plugin sends your API key, the prompt or input text, any source images, and the requested options (model, size, number of images, video length, voice) to the matching deAPI endpoint. For videos it then checks the job status every 5 seconds and downloads the finished file from the same API.
* Generated images may be returned as links to `https://results.deapi.ai`, which the AI Client may download.

The plugin sends no other data and makes no requests in the background.

deAPI [Terms of Service](https://deapi.ai/terms-of-service) and [Privacy Policy](https://deapi.ai/privacy-policy).

== Changelog ==

= 0.1.0 =

* Initial release: image generation and editing, video generation, text-to-speech and embeddings.
