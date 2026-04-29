<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiRegolo\Tests\Live;

use Laravel\Ai\Responses\Data\RankedDocument;
use Laravel\Ai\Responses\RerankingResponse;

/**
 * Live reranking test against `POST /v1/rerank` on `api.regolo.ai`.
 *
 * Verifies that the Cohere/Jina-shaped wire contract holds: every
 * input document is scored, scores arrive in descending order, and
 * the most relevant document for the query lands at the top.
 */
final class RegoloRerankLiveTest extends LiveTestCase
{
    public function test_live_reranking_orders_documents_by_relevance(): void
    {
        $documents = [
            'Roma è la capitale d\'Italia e si trova nel Lazio.',
            'Pasta al pomodoro è un piatto classico.',
            'Il calcio è uno sport popolare in Italia.',
            'Milano è il principale centro finanziario italiano.',
        ];

        $response = $this->liveGateway()->rerank(
            $this->liveProvider(),
            $this->rerankingModel(),
            $documents,
            'Quale città è la capitale italiana?',
            limit: 4,
        );

        $this->assertInstanceOf(RerankingResponse::class, $response);
        $this->assertCount(count($documents), $response->results, 'All input docs should be scored.');
        $this->assertSame('regolo', $response->meta->provider);
        $this->assertSame($this->rerankingModel(), $response->meta->model);

        // Every result must reference an original document by index.
        foreach ($response->results as $result) {
            $this->assertInstanceOf(RankedDocument::class, $result);
            $this->assertSame($documents[$result->index], $result->document);
        }

        // Scores must be in descending order — that is the API contract,
        // not a happy coincidence. A flaky model here would surface as
        // an off-by-one bug somewhere in the stack.
        $scores = array_map(fn (RankedDocument $r) => $r->score, $response->results);
        for ($i = 0; $i < count($scores) - 1; $i++) {
            $this->assertGreaterThanOrEqual($scores[$i + 1], $scores[$i], 'Rerank scores must arrive in descending order.');
        }

        // Smoke-level relevance: the Roma doc should outrank "calcio".
        $topDocument = $response->results[0]->document;
        $this->assertStringContainsString('Roma', $topDocument, 'Top-1 result for "capitale italiana" should mention Roma.');
    }
}
