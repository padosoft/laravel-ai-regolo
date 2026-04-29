<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiRegolo\Tests\Unit\Gateway\Regolo;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\AiServiceProvider;
use Laravel\Ai\Files\Base64Audio;
use Orchestra\Testbench\TestCase;
use Padosoft\LaravelAiRegolo\Gateway\Regolo\RegoloGateway;
use Padosoft\LaravelAiRegolo\LaravelAiRegoloServiceProvider;
use Padosoft\LaravelAiRegolo\Providers\RegoloProvider;

/**
 * Audio transcription (speech-to-text) coverage for the Regolo
 * provider.
 *
 * Regolo exposes `POST /v1/audio/transcriptions` mirroring OpenAI
 * Whisper's wire shape. The default catalogue model is
 * `faster-whisper-large-v3`. The endpoint accepts multipart audio
 * uploads and returns either `{ text }` (plain) or
 * `{ text, segments: [...] }` (when `response_format=diarized_json`
 * is supplied for a diarization-capable model).
 */
final class RegoloGatewayTranscriptionTest extends TestCase
{
    public function test_generate_transcription_returns_plain_text_for_default_response_format(): void
    {
        Http::fake([
            'api.regolo.test/v1/audio/transcriptions' => Http::response([
                'text' => 'Buongiorno, sono Marco e oggi parliamo di Regolo.',
            ]),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        $audio = new Base64Audio(base64_encode("\x00fake-audio-bytes"), 'audio/mpeg');

        $response = $gateway->generateTranscription(
            $this->makeProvider(),
            'faster-whisper-large-v3',
            $audio,
        );

        $this->assertSame('Buongiorno, sono Marco e oggi parliamo di Regolo.', $response->text);
        $this->assertCount(0, $response->segments);
        $this->assertSame('regolo', $response->meta->provider);
        $this->assertSame('faster-whisper-large-v3', $response->meta->model);
    }

    public function test_generate_transcription_forwards_language_hint(): void
    {
        Http::fake([
            'api.regolo.test/v1/audio/transcriptions' => Http::response(['text' => 'ok']),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        $audio = new Base64Audio(base64_encode('audio'), 'audio/wav');

        $gateway->generateTranscription(
            $this->makeProvider(),
            'faster-whisper-large-v3',
            $audio,
            language: 'it',
        );

        Http::assertSent(function (Request $request) {
            return str_ends_with($request->url(), '/audio/transcriptions')
                && $request->method() === 'POST'
                && $this->multipartContains($request, 'name="model"', 'faster-whisper-large-v3')
                && $this->multipartContains($request, 'name="language"', 'it')
                && $this->multipartContains($request, 'name="response_format"', 'json');
        });
    }

    public function test_generate_transcription_diarize_flag_switches_response_format(): void
    {
        Http::fake([
            'api.regolo.test/v1/audio/transcriptions' => Http::response([
                'text' => 'Speaker 1: Ciao. Speaker 2: Salve.',
                'segments' => [
                    [
                        'text' => 'Ciao.',
                        'speaker' => 'speaker-1',
                        'start' => 0.0,
                        'end' => 0.7,
                    ],
                    [
                        'text' => 'Salve.',
                        'speaker' => 'speaker-2',
                        'start' => 1.2,
                        'end' => 1.9,
                    ],
                ],
                'usage' => ['input_tokens' => 5, 'total_tokens' => 12],
            ]),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        $audio = new Base64Audio(base64_encode('audio'), 'audio/ogg');

        $response = $gateway->generateTranscription(
            $this->makeProvider(),
            'faster-whisper-large-v3-diarize',
            $audio,
            diarize: true,
        );

        $this->assertCount(2, $response->segments);
        $this->assertSame('Ciao.', $response->segments[0]->text);
        $this->assertSame('speaker-1', $response->segments[0]->speaker);
        $this->assertEqualsWithDelta(0.0, $response->segments[0]->startSeconds, 0.001);
        $this->assertEqualsWithDelta(0.7, $response->segments[0]->endSeconds, 0.001);
        // Whisper usage maps `input_tokens` → `promptTokens` and the
        // billed delta (`total_tokens - input_tokens`, floored at 0)
        // → `completionTokens`. Fixture is { input: 5, total: 12 } so
        // the SDK consumer reading `promptTokens + completionTokens`
        // gets the original `total_tokens` (12) without double-counting.
        $this->assertSame(5, $response->usage->promptTokens);
        $this->assertSame(7, $response->usage->completionTokens);
        $this->assertSame(
            12,
            $response->usage->promptTokens + $response->usage->completionTokens,
            'Sum of prompt + completion must equal Whisper total_tokens (no double counting).',
        );

        Http::assertSent(function (Request $request) {
            return $this->multipartContains($request, 'name="response_format"', 'diarized_json');
        });
    }

    public function test_generate_transcription_omits_language_when_null(): void
    {
        Http::fake([
            'api.regolo.test/v1/audio/transcriptions' => Http::response(['text' => 'auto']),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        $audio = new Base64Audio(base64_encode('audio'), 'audio/mpeg');

        $gateway->generateTranscription(
            $this->makeProvider(),
            'faster-whisper-large-v3',
            $audio,
        );

        Http::assertSent(function (Request $request) {
            // Whisper auto-detects when `language` is omitted; sending
            // an explicit `null` would surface as a 422. The multipart
            // body therefore must NOT contain a `language` part.
            return ! str_contains((string) $request->body(), 'name="language"');
        });
    }

    public function test_default_transcription_model_falls_back_to_faster_whisper(): void
    {
        $provider = $this->makeProvider([
            'models' => [
                'text' => ['default' => 'Llama-3.1-8B-Instruct'],
                'embeddings' => ['default' => 'Qwen3-Embedding-8B', 'dimensions' => 4096],
                'reranking' => ['default' => 'jina-reranker-v2'],
                // transcription entry intentionally absent
            ],
        ]);

        $this->assertSame('faster-whisper-large-v3', $provider->defaultTranscriptionModel());
    }

    public function test_default_transcription_model_uses_config_when_provided(): void
    {
        $provider = $this->makeProvider([
            'models' => [
                'text' => ['default' => 'Llama-3.1-8B-Instruct'],
                'embeddings' => ['default' => 'Qwen3-Embedding-8B', 'dimensions' => 4096],
                'reranking' => ['default' => 'jina-reranker-v2'],
                'transcription' => ['default' => 'whisper-large-v3-italian'],
            ],
        ]);

        $this->assertSame('whisper-large-v3-italian', $provider->defaultTranscriptionModel());
    }

    /**
     * Pin the MIME → filename extension mapping in `audioFilename()`.
     *
     * The mapping has to keep `audio/m4a` and `audio/mp4` as DISTINCT
     * extensions because Whisper-style endpoints treat `.m4a` and
     * `.mp4` as different file types — labelling an `.m4a` payload
     * `audio.mp4` (or vice versa) trips a strict upstream dispatcher.
     * Container types (`video/mp4`, `video/webm`) reach this mapping
     * via `finfo_file()` for audio-only containers and must resolve to
     * the underlying audio extension.
     */
    public function test_multipart_filename_mime_extension_mapping(): void
    {
        $reflection = new \ReflectionMethod(RegoloGateway::class, 'audioFilename');
        $reflection->setAccessible(true);
        $gateway = new RegoloGateway($this->app->make('events'));

        $cases = [
            // [mime, expected filename]
            ['audio/mpeg',                 'audio.mp3'],
            ['audio/mp3',                  'audio.mp3'],
            ['audio/wav',                  'audio.wav'],
            ['audio/x-wav',                'audio.wav'],
            ['audio/ogg',                  'audio.ogg'],
            ['audio/ogg; codecs=opus',     'audio.ogg'],
            ['audio/flac',                 'audio.flac'],
            ['audio/x-flac',               'audio.flac'],
            ['audio/m4a',                  'audio.m4a'],
            ['audio/x-m4a',                'audio.m4a'],
            ['audio/mp4',                  'audio.mp4'],
            ['audio/webm',                 'audio.webm'],
            ['audio/mpga',                 'audio.mpga'],
            // Containers reported by finfo_file() for audio-only payloads:
            ['video/mp4',                  'audio.mp4'],
            ['video/webm',                 'audio.webm'],
            // Fallback for unknown MIME stays at the safe default:
            ['application/octet-stream',   'audio.mp3'],
        ];

        foreach ($cases as [$mime, $expected]) {
            $audio = new Base64Audio(base64_encode('x'), $mime);
            $this->assertSame(
                $expected,
                $reflection->invoke($gateway, $audio),
                sprintf('audioFilename(%s) should produce %s', $mime, $expected),
            );
        }
    }

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            AiServiceProvider::class,
            LaravelAiRegoloServiceProvider::class,
        ];
    }

    /**
     * Assert that the multipart request body contains a part with the
     * given disposition fragment AND the expected value. Multipart
     * bodies are not parseable via `Request::data()` the way URL-
     * encoded / JSON bodies are, so we use substring containment as
     * the cheapest reliable check: the multipart envelope renders
     * each part as
     *
     *     --<boundary>
     *     Content-Disposition: form-data; name="<name>"
     *
     *     <value>
     *
     * so the disposition fragment + the value both appearing in the
     * raw body proves the field reached the wire with that contents.
     */
    private function multipartContains(Request $request, string $dispositionFragment, string $expectedValue): bool
    {
        $body = (string) $request->body();

        return str_contains($body, $dispositionFragment)
            && str_contains($body, $expectedValue);
    }

    /**
     * @param  array<string, mixed>  $configOverride
     */
    private function makeProvider(array $configOverride = []): RegoloProvider
    {
        $config = array_merge([
            'driver' => 'regolo',
            'name' => 'regolo',
            'key' => 'test-api-key',
            'url' => 'https://api.regolo.test/v1',
            'models' => [
                'text' => ['default' => 'Llama-3.1-8B-Instruct'],
                'embeddings' => ['default' => 'Qwen3-Embedding-8B', 'dimensions' => 4096],
                'reranking' => ['default' => 'jina-reranker-v2'],
                'transcription' => ['default' => 'faster-whisper-large-v3'],
            ],
        ], $configOverride);

        return new RegoloProvider($config, $this->app->make('events'));
    }
}
