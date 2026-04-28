# laravel-ai-regolo

**Laravel AI SDK provider extension** that adds [Seeweb Regolo](https://regolo.ai) (Italian sovereign cloud) and [Ollama](https://ollama.com) (local + Cloud) on top of the official [`laravel/ai`](https://github.com/laravel/ai) SDK.

> 🇮🇹 First-class support for the **Regolo open-model catalog** — 30+ models that aren't reachable through the standard `laravel/ai` providers (OpenAI / Anthropic / Gemini / Mistral / Groq / Cohere / Perplexity / Workers AI / OpenRouter).

## Why this package

`laravel/ai` is the official Laravel AI SDK and covers 9 providers out of the box. **It does NOT cover Regolo** — Seeweb's Italian sovereign cloud — even though Regolo gives you access to:

- A growing catalog of **open models** hosted in Italy (LLaMA-3, Qwen, Mistral, Gemma, Phi, DeepSeek, ...)
- **Embeddings + reranking** in a single API
- GDPR + AI Act-friendly hosting (data never leaves the EU)
- Pay-as-you-go pricing competitive with US providers

This package fills the gap. You add Regolo (and Ollama) to the same unified `laravel/ai` API and your app code doesn't change when you swap models.

## Features

- ✅ **Regolo provider**: chat completion, streaming, embeddings, reranking
- ✅ **Regolo open-model catalog**: 30+ models, auto-detected via `models` endpoint
- ✅ **Ollama provider**: local instance + Ollama Cloud (€19/mo subscription)
- ✅ **ReAct-style tool calls** for models without native function calling
- ✅ **OpenAI-compatible** under the hood (Regolo + Ollama both expose OpenAI-shape REST)
- ✅ **Type-safe**: PHP 8.3+, readonly DTOs, Pint-formatted, PHPStan level 8
- ✅ **CI matrix**: PHP 8.3 + 8.4 + 8.5 × Laravel 11/12/13

## Installation

```bash
composer require laravel/ai
composer require padosoft/laravel-ai-regolo
```

The package auto-registers via Laravel's package discovery. Configure your providers in `config/ai.php`:

```php
return [
    'providers' => [
        // Standard providers from laravel/ai
        'openai'    => ['driver' => 'openai',    'api_key' => env('OPENAI_API_KEY')],
        'anthropic' => ['driver' => 'anthropic', 'api_key' => env('ANTHROPIC_API_KEY')],

        // Added by this package
        'regolo' => [
            'driver'  => 'regolo',
            'api_key' => env('REGOLO_API_KEY'),
            'base'    => env('REGOLO_BASE', 'https://api.regolo.ai/v1'),
        ],
        'ollama' => [
            'driver' => 'ollama',
            'base'   => env('OLLAMA_BASE', 'http://localhost:11434'),
        ],
    ],
];
```

## Quick start

```php
use Laravel\Ai\Facades\Ai;

// Chat with a Regolo open model
$response = Ai::driver('regolo')
    ->chat([
        ['role' => 'user', 'content' => 'Spiegami la teoria della relatività in 3 frasi'],
    ])
    ->withModel('Llama-3.3-70B-Instruct')
    ->execute();

echo $response->content;

// Embeddings
$vec = Ai::driver('regolo')
    ->embed('test text')
    ->withModel('gte-Qwen2-1.5B-instruct')
    ->execute();

// Local Ollama
$response = Ai::driver('ollama')
    ->chat([
        ['role' => 'user', 'content' => 'Hello!'],
    ])
    ->withModel('qwen2.5:7b-instruct')
    ->execute();
```

## Comparison vs `laravel/ai` standalone

| Feature                          | `laravel/ai` only | `laravel/ai` + this package |
|----------------------------------|:-----------------:|:----------------------------:|
| OpenAI / Anthropic / Gemini      |        ✅         |              ✅              |
| Mistral / Groq / Cohere          |        ✅         |              ✅              |
| Perplexity / Workers AI          |        ✅         |              ✅              |
| OpenRouter                       |        ✅         |              ✅              |
| **Regolo (Seeweb sovereign IT)** |        ❌         |              ✅              |
| **Regolo open-model catalog**    |        ❌         |              ✅              |
| **Embeddings via Regolo**        |        ❌         |              ✅              |
| **Reranking via Regolo**         |        ❌         |              ✅              |
| **Ollama local**                 |        ❌         |              ✅              |
| **Ollama Cloud**                 |        ❌         |              ✅              |

## Documentation

See [docs/](./docs/) for full API reference (coming with v0.1.0).

## License

Apache-2.0 — see [LICENSE](./LICENSE).

## Status

🚧 Pre-release. v0.1.0 expected May 2026.

## About

Built by [Padosoft](https://padosoft.com) for [AskMyDocs](https://github.com/lopadova/AskMyDocs) and the wider Laravel AI ecosystem. Standalone agnostic — does not depend on AskMyDocs.
