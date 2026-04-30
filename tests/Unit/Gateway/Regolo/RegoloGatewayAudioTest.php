<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiRegolo\Tests\Unit\Gateway\Regolo;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase;
use Padosoft\LaravelAiRegolo\Gateway\Regolo\RegoloGateway;
use Padosoft\LaravelAiRegolo\LaravelAiRegoloServiceProvider;
use Padosoft\LaravelAiRegolo\Providers\RegoloProvider;

/**
 * Audio (text-to-speech) coverage for the Regolo provider.
 *
 * Regolo exposes `POST /v1/audio/speech` mirroring OpenAI's TTS shape
 * — the gateway sends `{ model, input, voice, response_format,
 * speed, instructions? }` and treats the raw HTTP body as the encoded
 * MP3 payload, base64-encoded into `AudioResponse::$audio`.
 *
 * Regolo does not yet publish a TTS model catalogue in
 * `GET /v1/models`; the package therefore does NOT pin a default
 * `defaultAudioModel()` and the caller must pass the model name
 * explicitly. The tests below use placeholder model ids that mirror
 * what Seeweb exposed in early-access experiments.
 */
final class RegoloGatewayAudioTest extends TestCase
{
    public function test_generate_audio_returns_base64_encoded_mp3(): void
    {
        $rawMp3Bytes = "\xFF\xFB\x90\x00FAKE-MP3-PAYLOAD";

        Http::fake([
            'api.regolo.test/v1/audio/speech' => Http::response($rawMp3Bytes, 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        $response = $gateway->generateAudio(
            $this->makeProvider(),
            'tts-italian-1',
            'Buongiorno e benvenuto su Regolo.',
            'alloy',
        );

        $this->assertSame(base64_encode($rawMp3Bytes), $response->audio);
        $this->assertSame('audio/mpeg', $response->mime);
        $this->assertSame('regolo', $response->meta->provider);
        $this->assertSame('tts-italian-1', $response->meta->model);
    }

    public function test_generate_audio_forwards_voice_and_instructions_verbatim(): void
    {
        Http::fake([
            'api.regolo.test/v1/audio/speech' => Http::response('mp3-bytes', 200),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        $gateway->generateAudio(
            $this->makeProvider(),
            'tts-multi',
            'Read this slowly.',
            'custom-voice-id',
            instructions: 'Speak in a calm narrative tone, 0.9× speed.',
        );

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return str_ends_with($request->url(), '/audio/speech')
                && $body['model'] === 'tts-multi'
                && $body['input'] === 'Read this slowly.'
                && $body['voice'] === 'custom-voice-id'
                && $body['instructions'] === 'Speak in a calm narrative tone, 0.9× speed.'
                && $body['response_format'] === 'mp3'
                && $body['speed'] === 1.0;
        });
    }

    public function test_generate_audio_omits_instructions_when_null(): void
    {
        Http::fake([
            'api.regolo.test/v1/audio/speech' => Http::response('mp3-bytes', 200),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        $gateway->generateAudio(
            $this->makeProvider(),
            'tts-default',
            'Hello world.',
            'default-female',
        );

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            // The `instructions` key must NOT be present when null —
            // otherwise the upstream would receive a `null` literal it
            // may reject as a 422 schema violation.
            return ! array_key_exists('instructions', $body);
        });
    }

    public function test_generate_audio_with_empty_response_body(): void
    {
        // Defensive scenario: if the upstream returns 200 with an empty
        // body (e.g. content filter rejection that surfaces as 200 +
        // empty), the gateway must still produce a valid AudioResponse
        // with an empty payload rather than crashing.
        Http::fake([
            'api.regolo.test/v1/audio/speech' => Http::response('', 200),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        $response = $gateway->generateAudio(
            $this->makeProvider(),
            'tts-edge',
            'Speak this.',
            'alloy',
        );

        $this->assertSame('', $response->audio);
        $this->assertSame('audio/mpeg', $response->mime);
    }

    public function test_default_audio_model_is_unset_when_config_absent(): void
    {
        // Regolo's TTS catalogue is not public yet — the package
        // intentionally leaves `defaultAudioModel()` empty so callers
        // who skip the model argument get a clear upstream 4xx pointing
        // at "model required" instead of a hard-coded guess that may
        // not exist in production.
        $provider = $this->makeProvider([
            'models' => [
                'text' => ['default' => 'Llama-3.1-8B-Instruct'],
                'embeddings' => ['default' => 'Qwen3-Embedding-8B', 'dimensions' => 4096],
                'reranking' => ['default' => 'Qwen3-Reranker-4B'],
                'image' => ['default' => 'Qwen-Image'],
                'transcription' => ['default' => 'faster-whisper-large-v3'],
                // audio key intentionally omitted
            ],
        ]);

        $this->assertSame('', $provider->defaultAudioModel());
    }

    public function test_default_audio_model_uses_config_when_provided(): void
    {
        $provider = $this->makeProvider([
            'models' => [
                'text' => ['default' => 'Llama-3.1-8B-Instruct'],
                'embeddings' => ['default' => 'Qwen3-Embedding-8B', 'dimensions' => 4096],
                'reranking' => ['default' => 'Qwen3-Reranker-4B'],
                'image' => ['default' => 'Qwen-Image'],
                'transcription' => ['default' => 'faster-whisper-large-v3'],
                'audio' => ['default' => 'tts-italian-2'],
            ],
        ]);

        $this->assertSame('tts-italian-2', $provider->defaultAudioModel());
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
                'reranking' => ['default' => 'Qwen3-Reranker-4B'],
                'image' => ['default' => 'Qwen-Image'],
                'transcription' => ['default' => 'faster-whisper-large-v3'],
            ],
        ], $configOverride);

        return new RegoloProvider($config, $this->app->make('events'));
    }
}
