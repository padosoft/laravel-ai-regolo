---
title: Installation
description: Install padosoft/laravel-ai-regolo and prepare a Laravel application for Regolo.
---

# Installation

Install the official Laravel AI SDK and this provider package:

```bash
composer require laravel/ai
composer require padosoft/laravel-ai-regolo
```

The service provider is auto-discovered through Composer:

```json
{
  "extra": {
    "laravel": {
      "providers": [
        "Padosoft\\LaravelAiRegolo\\LaravelAiRegoloServiceProvider"
      ]
    }
  }
}
```

## Requirements

| Dependency | Supported range |
| --- | --- |
| PHP | `^8.3` |
| Laravel components | `^12.0` or `^13.0` |
| Laravel AI SDK | `^0.6\|^0.7\|^0.8` |

:::warning
Laravel 11 is not supported because the upstream `laravel/ai` SDK requires Laravel 12 or newer components.
:::

## Publish or edit AI configuration

Publish `config/ai.php` from `laravel/ai` if your application does not already have it, then add a `regolo` provider entry. The package registers the `regolo` driver name.

```php
'regolo' => [
    'driver' => 'regolo',
    'name' => 'regolo',
    'key' => env('REGOLO_API_KEY'),
    'url' => env('REGOLO_BASE_URL', 'https://api.regolo.ai/v1'),
    'timeout' => 60,
    'models' => [
        'text' => [
            'default' => 'Llama-3.1-8B-Instruct',
            'cheapest' => 'Llama-3.1-8B-Instruct',
            'smartest' => 'Llama-3.3-70B-Instruct',
        ],
        'embeddings' => [
            'default' => 'Qwen3-Embedding-8B',
            'dimensions' => 4096,
        ],
        'reranking' => [
            'default' => 'Qwen3-Reranker-4B',
        ],
        'image' => [
            'default' => env('REGOLO_IMAGE_MODEL', 'Qwen-Image'),
        ],
        'transcription' => [
            'default' => env('REGOLO_TRANSCRIPTION_MODEL', 'faster-whisper-large-v3'),
        ],
        'audio' => [
            'default' => env('REGOLO_AUDIO_MODEL'),
        ],
    ],
],
```

## Environment

```dotenv
REGOLO_API_KEY=rg_live_...
REGOLO_BASE_URL=https://api.regolo.ai/v1
AI_DEFAULT_TEXT=regolo
AI_DEFAULT_EMBEDDINGS=regolo
AI_DEFAULT_RERANKING=regolo
```

Keep `REGOLO_BASE_URL` optional in production unless Seeweb gives you a staging or dedicated endpoint.
