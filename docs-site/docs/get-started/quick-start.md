---
title: Quick Start
description: Make the first chat, embedding, and rerank calls through Regolo.
---

# Quick Start

After installation, verify the provider through the same Laravel AI APIs you would use with OpenAI, Anthropic, Gemini, or Ollama.

## Chat

```php
use Laravel\Ai\Agent;

$response = Agent::for('Riassumi il GDPR per un prodotto SaaS italiano.')
    ->using('regolo', 'Llama-3.3-70B-Instruct')
    ->prompt();

$response->text;
$response->usage->promptTokens;
$response->meta->provider;
```

## Streaming

```php
use Laravel\Ai\Agent;
use Laravel\Ai\Streaming\Events\TextDelta;

foreach (Agent::for('Spiega il reranking in cinque punti.')->using('regolo')->stream() as $event) {
    if ($event instanceof TextDelta) {
        echo $event->text;
    }
}
```

## Embeddings

```php
use Laravel\Ai\Embeddings;

$batch = Embeddings::for([
    'Regolo ospita modelli open-weight in Italia.',
    'Laravel AI espone provider intercambiabili.',
])->generate('regolo', 'Qwen3-Embedding-8B');

count($batch->embeddings);
```

## Reranking

```php
use Laravel\Ai\Reranking;

$ranked = Reranking::of([
    'Roma e la capitale d Italia.',
    'Parigi e la capitale della Francia.',
    'La pasta al pomodoro e un piatto italiano.',
])->limit(2)->rerank('Quale documento parla della capitale italiana?', 'regolo');

$ranked->results[0]->document;
```

:::tip
Start with explicit model ids in early integration tests. Move to configured defaults once the application has stable routing rules.
:::
