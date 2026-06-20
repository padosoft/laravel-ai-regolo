---
title: Provider Selection
description: Choose when to route Laravel AI workloads to Regolo.
---

# Provider Selection

Regolo is a strong default for Italian or EU-sensitive workloads. Provider routing can still vary per feature.

| Workload | Recommended provider posture |
| --- | --- |
| Customer support with PII | Prefer Regolo. |
| Italian-language knowledge base | Prefer Regolo. |
| Synthetic benchmark with no PII | Any configured provider. |
| Frontier reasoning requirement | Compare Regolo with top closed models. |
| Vector search for regulated documents | Prefer Regolo embeddings. |

## Route by risk

```php
$provider = $containsPersonalData ? 'regolo' : config('ai.defaults.text');
```

:::tip
Make provider choice visible in logs and traces. It helps legal, support, and finance teams answer where each workload ran.
:::
