---
title: Motivazione
description: Why laravel-ai-regolo exists and when to choose Regolo.
---

# Motivazione

`laravel/ai` gives Laravel applications one API surface for multiple AI providers. Regolo gives Italian teams a sovereign AI endpoint operated by Seeweb. `laravel-ai-regolo` connects those two facts without requiring application code to learn a custom HTTP client.

## The gap

The official SDK ships many providers, but not Regolo. Without this package, a Laravel team has three weaker options:

- Build direct `Http::` calls and duplicate streaming, tool, error, and response mapping.
- Reuse an OpenAI client and leak Regolo-specific assumptions across the app.
- Delay sovereign AI adoption until the provider exists upstream.

## The goal

The package makes Regolo feel like any other Laravel AI provider:

```php
Agent::for($prompt)->using('regolo')->prompt();
Embeddings::for($texts)->generate('regolo');
Reranking::of($docs)->rerank($query, 'regolo');
```

## When it matters

Use Regolo when prompts or source documents include regulated data, Italian-language content, EU procurement constraints, or a future need to move between open-weight models.

:::note
This package is intentionally standalone. It has no dependency on AskMyDocs, Padosoft private code, or a specific product architecture.
:::
