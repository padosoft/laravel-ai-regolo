<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiRegolo\Gateway\Regolo;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Gateway\EmbeddingGateway;
use Laravel\Ai\Contracts\Gateway\RerankingGateway;
use Laravel\Ai\Contracts\Gateway\TextGateway;

/**
 * HTTP gateway for Regolo's REST API.
 *
 * Regolo is OpenAI-compatible for chat completions and embeddings
 * (`/v1/chat/completions`, `/v1/embeddings`) and Cohere-style for
 * reranking (`/v1/rerank`). One gateway class implements all three
 * SDK gateway interfaces so the provider can wire it into every
 * capability concern with a single constructor call.
 *
 * Implementation lands in W2.A.2 (chat + streaming), W2.A.3
 * (embeddings + reranking). This class exists today as a typed
 * scaffold so RegoloProvider can wire it in.
 */
final class RegoloGateway implements EmbeddingGateway, RerankingGateway, TextGateway
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly int $timeout,
        private readonly Dispatcher $events,
    ) {
    }

    // Method bodies for prompt / stream / embeddings / rerank land in
    // W2.A.2 + W2.A.3. Each method will be added in lock-step with
    // the SDK gateway interface signatures (TextGateway, EmbeddingGateway,
    // RerankingGateway) — those are read at implementation time so
    // the method declarations match exactly.
}
