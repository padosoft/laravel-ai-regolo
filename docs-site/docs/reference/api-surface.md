---
title: API Surface
description: Public APIs and classes users interact with.
---

# API Surface

Application developers mostly interact with Laravel AI. Package internals are relevant when contributing to the provider.

## User-facing APIs

```php
Agent::for($prompt)->using('regolo', $model)->prompt();
Agent::for($prompt)->using('regolo', $model)->stream();
Embeddings::for($texts)->generate('regolo', $model);
Reranking::of($documents)->rerank($query, 'regolo', $model);
Image::of($prompt)->generate('regolo', $model);
Transcription::of($path)->using('regolo', $model)->generate();
Audio::for($text)->generate('regolo', $model);
```

## Package classes

| Class | Purpose |
| --- | --- |
| `Padosoft\LaravelAiRegolo\LaravelAiRegoloServiceProvider` | Registers the provider binding. |
| `Padosoft\LaravelAiRegolo\Providers\RegoloProvider` | Exposes capabilities to Laravel AI. |
| `Padosoft\LaravelAiRegolo\Gateway\Regolo\RegoloGateway` | Sends requests and parses responses. |
| `Padosoft\LaravelAiRegolo\Gateway\Regolo\Concerns\CreatesRegoloClient` | Creates configured Laravel HTTP clients. |
