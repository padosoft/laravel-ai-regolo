<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiRegolo\Tests\Live;

use Laravel\Ai\Responses\EmbeddingsResponse;

/**
 * Live embeddings test against `POST /v1/embeddings` on
 * `api.regolo.ai`.
 *
 * Verifies the wire contract: the configured embedding model returns
 * a non-empty float vector with non-zero signal, `tokens` is non-zero,
 * and a batch preserves both input cardinality and a uniform vector
 * length across every entry.
 *
 * Note: vector dimension is **model-defined**. The current
 * `RegoloGateway::generateEmbeddings()` posts `{ model, input }` to
 * `/v1/embeddings` and ignores the SDK's `int $dimensions` argument,
 * so these tests deliberately do NOT assert against an env-driven
 * expected dimension — only that every vector inside a single
 * response shares the same (non-zero) length, which is the invariant
 * any healthy embeddings API must satisfy.
 */
final class RegoloEmbeddingsLiveTest extends LiveTestCase
{
    public function test_live_embeddings_return_a_vector_with_non_zero_signal(): void
    {
        $response = $this->liveGateway()->generateEmbeddings(
            $this->liveProvider(),
            $this->embeddingsModel(),
            ['Roma è la capitale d\'Italia.'],
            $this->embeddingsDimensionsPlaceholder(),
            $this->liveTimeout(),
        );

        $this->assertInstanceOf(EmbeddingsResponse::class, $response);
        $this->assertCount(1, $response->embeddings, 'One input should produce one vector.');
        $this->assertNotEmpty($response->embeddings[0], 'Vector should not be empty.');
        $this->assertGreaterThan(
            0,
            count($response->embeddings[0]),
            'Vector dimension reported by the API must be > 0.',
        );
        $this->assertGreaterThan(0, $response->tokens, 'Live response should report a non-zero token count.');
        $this->assertSame('regolo', $response->meta->provider);
        $this->assertSame($this->embeddingsModel(), $response->meta->model);

        // The vector must contain real (non-zero) floats — a degenerate
        // all-zero vector would still pass the `notEmpty` check above.
        $sumOfAbsoluteValues = array_sum(array_map('abs', $response->embeddings[0]));
        $this->assertGreaterThan(0.0, $sumOfAbsoluteValues, 'Vector must carry signal (sum of |x_i| > 0).');
    }

    public function test_live_embeddings_batch_returns_one_vector_per_input_with_uniform_dimension(): void
    {
        $inputs = [
            'Italian sovereign cloud.',
            'OpenAI-compatible chat completions.',
            'Vector reranking against jina-reranker-v2.',
        ];

        $response = $this->liveGateway()->generateEmbeddings(
            $this->liveProvider(),
            $this->embeddingsModel(),
            $inputs,
            $this->embeddingsDimensionsPlaceholder(),
            $this->liveTimeout(),
        );

        $this->assertInstanceOf(EmbeddingsResponse::class, $response);
        $this->assertCount(count($inputs), $response->embeddings, 'Batch size should be preserved.');

        // Every vector must share the same dimension AND the dimension
        // must be > 0. Using the first vector's length as the baseline
        // catches the real failure modes (mixed-dimension batch, empty
        // vector smuggled in among populated ones) without coupling
        // the assertion to a hard-coded dimension that the gateway
        // does not currently steer.
        $firstVectorLength = count($response->embeddings[0]);
        $this->assertGreaterThan(0, $firstVectorLength, 'First vector dimension must be > 0.');

        foreach ($response->embeddings as $i => $vector) {
            $this->assertCount(
                $firstVectorLength,
                $vector,
                sprintf(
                    'Every vector in the batch must share the same dimension; vector[%d] length differs from vector[0] length (%d).',
                    $i,
                    $firstVectorLength,
                ),
            );
        }
    }
}
