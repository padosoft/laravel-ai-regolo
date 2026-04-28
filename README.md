# laravel-ai-regolo

**Laravel AI SDK provider extension** that adds [Seeweb Regolo](https://regolo.ai) — the Italian sovereign cloud — as a first-class provider for the official [`laravel/ai`](https://github.com/laravel/ai) SDK.

> 🇮🇹 Adds a **Regolo open-model catalog** (30+ models hosted in Italy) plus chat, embeddings, and reranking under the same unified `Ai::driver()` API the rest of `laravel/ai` exposes.

## Why this package

`laravel/ai` is the official Laravel AI SDK and ships 14+ providers out of the box (OpenAI, Anthropic, Gemini, Mistral, Groq, Cohere, DeepSeek, Bedrock, Azure OpenAI, OpenRouter, Ollama, Jina, VoyageAi, Xai, ElevenLabs). **It does not cover Regolo** — Seeweb's Italian sovereign cloud — even though Regolo gives you:

- A growing catalog of **open models** hosted in Italy (LLaMA-3, Qwen, Mistral, Gemma, Phi, DeepSeek, ...)
- **Chat + embeddings + reranking** in a single API
- GDPR + AI Act-friendly hosting (data never leaves the EU)
- Pay-as-you-go pricing competitive with US-hosted providers

This package fills that gap. Drop it in alongside `laravel/ai` and Regolo becomes available through the same unified `Ai::driver()` / `Embeddings::for()` / `Reranking::of()` APIs the SDK already exposes.

## Features

- ✅ **Chat completion + streaming** via the SDK's `TextProvider` contract
- ✅ **Embeddings** via `EmbeddingProvider`
- ✅ **Reranking** via `RerankingProvider`
- ✅ **Regolo open-model catalog**: 30+ models, auto-discovered via the `/v1/models` endpoint
- ✅ **ReAct-style tool calls** for open models without native function calling
- ✅ **OpenAI-compatible** under the hood (chat + embeddings) for predictable behaviour
- ✅ **Type-safe**: PHP 8.3+, readonly DTOs, Pint-formatted, PHPStan level 8
- ✅ **CI matrix**: PHP 8.3 + 8.4 + 8.5

## Installation

```bash
composer require laravel/ai
composer require padosoft/laravel-ai-regolo
```

The package auto-registers via Laravel's package discovery. Add a `regolo` entry to your `config/ai.php`:

```php
return [
    'providers' => [
        // Built-in providers from laravel/ai (OpenAI / Anthropic / Gemini /
        // Ollama / Mistral / Groq / Cohere / DeepSeek / Bedrock / ...)
        'openai' => ['driver' => 'openai', 'key' => env('OPENAI_API_KEY')],
        'ollama' => ['driver' => 'ollama'],

        // Added by this package
        'regolo' => [
            'driver' => 'regolo',
            'key'    => env('REGOLO_API_KEY'),
            'url'    => env('REGOLO_BASE_URL', 'https://api.regolo.ai/v1'),
            'timeout' => 60,
        ],
    ],
    'defaults' => [
        'text'        => env('AI_DEFAULT_TEXT', 'regolo'),
        'embeddings'  => env('AI_DEFAULT_EMBEDDINGS', 'regolo'),
        'reranking'   => env('AI_DEFAULT_RERANKING', 'regolo'),
    ],
];
```

## Quick start

```php
use Laravel\Ai\Embeddings;
use Laravel\Ai\Reranking;
use Laravel\Ai\Agent;

// Chat with a Regolo open model
$response = Agent::for('Tell me three things about Rome.')
    ->using('regolo', 'Llama-3.3-70B-Instruct')
    ->prompt();

echo $response->text;

// Embeddings
$vectors = Embeddings::for(['testo italiano', 'english text'])
    ->generate('regolo', 'Qwen3-Embedding-8B');

// Reranking
$ranked = Reranking::of([
    'Rome is the capital of Italy.',
    'Paris is the capital of France.',
])
    ->limit(2)
    ->rerank('What is the capital of Italy?', 'regolo', 'jina-reranker-v2');
```

## Comparison vs `laravel/ai` standalone

| Capability                       | `laravel/ai` only | `laravel/ai` + this package |
|----------------------------------|:-----------------:|:----------------------------:|
| OpenAI / Anthropic / Gemini      |        ✅         |              ✅              |
| Mistral / Groq / Cohere          |        ✅         |              ✅              |
| DeepSeek / Bedrock / Azure OpenAI|        ✅         |              ✅              |
| OpenRouter / Xai / Jina / Voyage |        ✅         |              ✅              |
| Ollama (local + Cloud)           |        ✅         |              ✅              |
| **Regolo chat + streaming**      |        ❌         |              ✅              |
| **Regolo embeddings**            |        ❌         |              ✅              |
| **Regolo reranking**             |        ❌         |              ✅              |
| **Regolo open-model catalog**    |        ❌         |              ✅              |

## Documentation

See [docs/](./docs/) for full API reference (coming with v0.1.0).

## License

Apache-2.0 — see [LICENSE](./LICENSE).

## Status

🚧 Pre-release. v0.1.0 expected May 2026.

## About

Built by [Padosoft](https://padosoft.com) for [AskMyDocs](https://github.com/lopadova/AskMyDocs) and the wider Laravel AI ecosystem. Standalone agnostic — does not depend on AskMyDocs.
