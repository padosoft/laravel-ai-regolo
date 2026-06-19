---
title: Configuration
description: Configure provider credentials, defaults, model aliases, and timeouts.
---

# Configuration

The provider stores credentials and model defaults in `config/ai.php`. The gateway reads those values from the provider on every call, so key rotation and endpoint changes are configuration operations.

## Provider keys

| Key | Type | Default | Notes |
| --- | --- | --- | --- |
| `driver` | string | `regolo` | Resolves `ai.provider.regolo`. |
| `name` | string | `regolo` | Returned in response metadata. |
| `key` | string | `env('REGOLO_API_KEY')` | Bearer token for Regolo. |
| `url` | string | `https://api.regolo.ai/v1` | Base URL without endpoint suffix. |
| `timeout` | integer | `60` | Per-request fallback timeout. |

## Defaults

```php
'defaults' => [
    'text' => env('AI_DEFAULT_TEXT', 'regolo'),
    'embeddings' => env('AI_DEFAULT_EMBEDDINGS', 'regolo'),
    'reranking' => env('AI_DEFAULT_RERANKING', 'regolo'),
],
```

## Model aliases

Use `default`, `cheapest`, and `smartest` for text routing when product code should not hard-code a model id:

```php
'models' => [
    'text' => [
        'default' => 'Llama-3.1-8B-Instruct',
        'cheapest' => 'Llama-3.1-8B-Instruct',
        'smartest' => 'Llama-3.3-70B-Instruct',
    ],
],
```

:::warning
Embedding dimensions are part of your persistence contract. If you change `models.embeddings.dimensions`, rebuild downstream vector indexes that stored the previous vector length.
:::

## Configuration formula

For a retrieval pipeline with `n` candidate documents and top `k` final chunks, the reranking work can be thought of as:

$$
cost \propto n \times tokens(query + document)
$$

Keep `n` bounded before reranking. A common pattern is vector search for 50 candidates, rerank to 5, then send the 5 chunks to chat.
