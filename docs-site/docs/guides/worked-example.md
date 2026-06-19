---
title: Worked Example
description: Build a small sovereign RAG answer flow with embeddings, reranking, and chat.
---

# Worked Example

This example builds a compact RAG flow for Italian policy documents.

## Step 1: index documents

```php
use Laravel\Ai\Embeddings;

$documents = PolicyDocument::query()->whereNull('embedded_at')->limit(100)->get();

foreach ($documents->chunk(25) as $chunk) {
    $batch = Embeddings::for($chunk->pluck('body')->all())->generate('regolo');

    foreach ($batch->embeddings as $offset => $embedding) {
        $chunk[$offset]->update([
            'embedding' => $embedding->vector,
            'embedded_at' => now(),
        ]);
    }
}
```

## Step 2: retrieve and rerank

```php
use Laravel\Ai\Reranking;

$candidates = PolicyDocument::nearestTo($queryEmbedding)->limit(50)->get();

$ranked = Reranking::of($candidates->pluck('body')->all())
    ->limit(5)
    ->rerank($question, 'regolo');
```

## Step 3: answer with citations

```php
use Laravel\Ai\Agent;

$context = collect($ranked->results)
    ->map(fn ($result) => $result->document)
    ->implode("\n\n---\n\n");

$answer = Agent::for("Rispondi usando solo questo contesto:\n\n{$context}\n\nDomanda: {$question}")
    ->using('regolo')
    ->prompt();
```

:::warning
Never pass documents into the final prompt only because vector search returned them. Rerank, filter by permissions, and cap the final context size.
:::
