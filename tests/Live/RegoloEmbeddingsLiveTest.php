<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiRegolo\Tests\Live;

use Laravel\Ai\Responses\EmbeddingsResponse;

/**
 * Live embeddings test against `POST /v1/embeddings` on
 * `api.regolo.ai`.
 *
 * Verifies the wire contract: the configured embedding model returns
 * a non-empty float vector, the dimension matches the model's
 * declared dimension, and `tokens` is non-zero.
 */
final class RegoloEmbeddingsLiveTest extends LiveTestCase
{
    public function test_live_embeddings_return_a_vector_of_expected_dimension(): void
    {
        $response = $this->liveGateway()->generateEmbeddings(
            $this->liveProvider(),
            $this->embeddingsModel(),
            ['Roma è la capitale d\'Italia.'],
            $this->embeddingsDimensions(),
        );

        $this->assertInstanceOf(EmbeddingsResponse::class, $response);
        $this->assertCount(1, $response->embeddings, 'One input should produce one vector.');
        $this->assertNotEmpty($response->embeddings[0], 'Vector should not be empty.');
        $this->assertGreaterThan(0, $response->tokens, 'Live response should report a non-zero token count.');
        $this->assertSame('regolo', $response->meta->provider);
        $this->assertSame($this->embeddingsModel(), $response->meta->model);

        // The vector must contain real (non-zero) floats — a degenerate
        // all-zero vector would still pass the `notEmpty` check above.
        $sumOfAbsoluteValues = array_sum(array_map('abs', $response->embeddings[0]));
        $this->assertGreaterThan(0.0, $sumOfAbsoluteValues, 'Vector must carry signal (sum of |x_i| > 0).');
    }

    public function test_live_embeddings_batch_returns_one_vector_per_input(): void
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
            $this->embeddingsDimensions(),
        );

        $this->assertCount(count($inputs), $response->embeddings, 'Batch size should be preserved.');

        $firstVectorDimension = count($response->embeddings[0]);
        foreach ($response->embeddings as $vector) {
            $this->assertCount($firstVectorDimension, $vector, 'All vectors in the batch must share the same dimension.');
        }
    }
}
