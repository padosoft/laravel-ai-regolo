---
title: ADR
description: Architecture decision records for the Regolo provider.
---

# ADR

These decisions explain the package shape. Keep new decisions short, dated, and linked to tests when possible.

:::details ADR 001: Extend Laravel AI Instead Of Forking It
Status: accepted.

Context: Regolo is not bundled in the upstream provider list.

Decision: Implement Laravel AI public contracts and register `ai.provider.regolo` instead of forking the SDK.

Consequences: Application code stays on the official SDK. Upstream contract changes require audits, but normal feature improvements flow from Laravel AI.
:::

:::details ADR 002: Use OpenAI Classic Chat Completions
Status: accepted.

Context: Regolo exposes an OpenAI-compatible classic chat surface rather than OpenAI's newer Responses API.

Decision: Model the text gateway after classic chat-completion providers and map Laravel AI messages into `POST /v1/chat/completions`.

Consequences: The package can support chat and streaming cleanly today. Responses-only features remain out of scope until Regolo exposes equivalents.
:::

:::details ADR 003: Keep Gateway Stateless
Status: accepted.

Context: Laravel containers may share gateway instances.

Decision: Read credentials, base URL, model aliases, and timeout from the provider argument on each call.

Consequences: Runtime key rotation and staging endpoint changes do not require rebuilding the service provider.
:::

:::details ADR 004: Treat Embedding Dimension As Data Contract
Status: accepted.

Context: Vector stores require fixed-length vectors.

Decision: Document and configure the embedding dimension explicitly.

Consequences: Changing embedding model or dimension requires an index rebuild.
:::
