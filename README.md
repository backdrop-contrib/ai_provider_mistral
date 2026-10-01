# AI Provider Mistral

Mistral AI provider for the Backdrop CMS AI module.

Adds Mistral (https://mistral.ai/) to the providers the `ai` module can route
to, using the REST API at `https://api.mistral.ai/v1`.

## Supported operations

| Operation | Supported | Notes |
|---|---|---|
| Chat | Yes | Streaming supported. |
| Completions | Yes | Sent as a single chat message; Mistral has no text-completions endpoint. |
| Tool calling | Yes | Standard `tools` / `tool_choice` payload. |
| Vision | Yes | Models that report the `vision` capability. |
| Embeddings | Yes | `mistral-embed`, `codestral-embed`. |
| Moderation | Yes | `mistral-moderation-*`, `shieldstral-*`. |
| Speech-to-text | Yes | Voxtral transcription models. Translation is not offered. |
| Text-to-speech | Yes | Voxtral TTS; the voice value is sent as `voice_id`. |
| Image generation | No | Mistral has no image generation endpoint. |
| Document OCR | Yes | Dedicated `/v1/ocr` endpoint, for PDFs — see below. |

Batch, agents, conversations and libraries are Mistral-specific endpoints
outside the shared adapter contract and are not exposed.

## Document OCR

Vision models read text out of images, and `ai_tools`' `ai_image_to_text` already
covers that for any provider. What Mistral adds is `/v1/ocr`, which reads
multi-page PDFs and returns markdown with tables — something a vision chat turn
cannot do.

It is a provider extra, not part of the shared adapter contract:

```php
$adapter = ai_provider_mistral_get_adapter();
$result = $adapter->ocr('https://example.com/report.pdf', '', ['pages' => '0-4']);
print $result['text'];
```

`ocr()` returns `text` (all page markdown joined), `pages` (raw page objects with
tables and image metadata), `model` and `pages_processed`. Pass
`['is_image' => TRUE]` for an image. Models come from `getOcrModels()` and
default to `mistral-ocr-latest`. They are kept out of the chat and vision model
lists: the API rejects them for chat outright.

When the `ai_tools` module is enabled, the same endpoint is available to agents
and the chatbot as the `ai_mistral_ocr` tool. It takes a managed `file_fid` or a
public `url` (PDF or image), an optional zero-based `pages` selection such as
`"0-4"` or `"0,2,5"`, and an optional `model`. Managed files are read through
their stream wrapper, so remote schemes such as `s3://` work. The tool only
appears when a Mistral API key is configured.

## Installation

- Install this module using the official [Backdrop CMS instructions](https://backdropcms.org/user-guide/modules).
- Create an authentication key with the Key module holding your Mistral API key
  (https://console.mistral.ai/api-keys).
- Enable and configure the provider at `admin/config/ai/settings`.

## Issues

Bugs and feature requests should be reported in the [Issue Queue](https://github.com/backdrop-contrib/ai_provider_mistral/issues).

## Current Maintainer

[Justin Keiser](https://github.com/keiserjb)

## Credits

- Created for Backdrop CMS by [Justin Keiser](https://github.com/keiserjb).

- Developed with AI assistance.

## License

This project is GPL v2 software. See the LICENSE.txt file in this directory for complete text.
