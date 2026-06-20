---
title: Provider Contracts
description: The Laravel AI contracts implemented by the Regolo provider.
---

# Provider Contracts

The package implements public Laravel AI provider and gateway contracts. That keeps the boundary small and makes upstream SDK changes easier to audit.

| Contract family | Package class | Responsibility |
| --- | --- | --- |
| Provider | `RegoloProvider` | Expose text, embedding, and reranking capabilities. |
| Service provider | `LaravelAiRegoloServiceProvider` | Bind `ai.provider.regolo`. |
| Gateway | `RegoloGateway` | Perform HTTP calls and map responses. |
| Concerns | `Gateway/Regolo/Concerns/*` | Split request building, parsing, streaming, tools, and clients. |

:::tip
When adding a new capability, keep Laravel AI DTOs as the boundary. Do not return raw Regolo arrays from public package methods.
:::
