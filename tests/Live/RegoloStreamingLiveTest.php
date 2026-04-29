<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiRegolo\Tests\Live;

use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;

/**
 * Live streaming test against `POST /v1/chat/completions` with
 * `stream: true` on `api.regolo.ai`.
 *
 * Verifies that SSE chunks arrive, the SDK's stream-event sequence
 * is well-formed, and the gateway emits at least one TextDelta.
 */
final class RegoloStreamingLiveTest extends LiveTestCase
{
    public function test_live_streaming_emits_text_deltas_and_terminates(): void
    {
        $events = iterator_to_array($this->liveGateway()->streamText(
            'live-stream-test',
            $this->liveProvider(),
            $this->textModel(),
            'Reply in two short sentences.',
            [new UserMessage('Tell me one fact about Florence.')],
            timeout: $this->liveTimeout(),
        ));

        $start = array_filter($events, fn ($e) => $e instanceof StreamStart);
        $deltas = array_filter($events, fn ($e) => $e instanceof TextDelta);
        $end = array_filter($events, fn ($e) => $e instanceof StreamEnd);

        $this->assertCount(1, $start, 'Live stream should emit exactly one StreamStart.');
        $this->assertGreaterThanOrEqual(1, count($deltas), 'Live stream should deliver at least one TextDelta.');
        $this->assertCount(1, $end, 'Live stream should terminate with exactly one StreamEnd.');

        $combined = TextDelta::combine(collect($events));
        $this->assertNotEmpty($combined, 'Concatenated deltas should produce a non-empty answer.');
    }
}
