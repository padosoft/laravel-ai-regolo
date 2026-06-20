---
title: Embeddings
description: Generate Regolo embeddings and keep vector dimensions consistent.
---

# Embeddings

Embeddings convert text into numeric vectors. In this package the default Regolo embedding model is `Qwen3-Embedding-8B` with a configured dimension of `4096`.

```php
use Laravel\Ai\Embeddings;

$vectors = Embeddings::for([
    'Fattura elettronica per cliente italiano.',
    'Contratto quadro con clausole GDPR.',
])->generate('regolo');
```

## Storage contract

Your database or vector store must match the configured dimension:

| Store | What to verify |
| --- | --- |
| PostgreSQL with pgvector | `vector(4096)` column length |
| External vector DB | Collection dimension |
| File cache | Serializer preserves float precision |

## Batch behavior

Use small batches for user-facing writes and larger batches for backfills. Keep retry logic outside the provider so the application can decide whether a partial indexing job should resume or fail.

```php
foreach (array_chunk($documents, 32) as $chunk) {
    Embeddings::for($chunk)->generate('regolo', 'Qwen3-Embedding-8B');
}
```

:::warning
Changing embedding models without rebuilding the index creates silent relevance failures. Treat model id plus dimension as an index version.
:::
