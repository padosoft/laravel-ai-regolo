<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiRegolo\Tests\Unit\Gateway\Regolo;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\AiServiceProvider;
use Laravel\Ai\Providers\Provider;
use Orchestra\Testbench\TestCase;
use Padosoft\LaravelAiRegolo\Gateway\Regolo\RegoloGateway;
use Padosoft\LaravelAiRegolo\LaravelAiRegoloServiceProvider;
use Padosoft\LaravelAiRegolo\Providers\RegoloProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * Image-generation coverage for the Regolo provider.
 *
 * Regolo exposes an OpenAI-compatible `POST /v1/images/generations`
 * endpoint with the public model `Qwen-Image` as the catalogue
 * default. The wire contract is `{ data: [{ b64_json: '...' }, ...] }`,
 * which the gateway maps to `Laravel\Ai\Responses\Data\GeneratedImage`.
 *
 * Image-edit (the `images/edits` cousin endpoint) is not part of the
 * documented Regolo API surface — passing `$attachments` therefore
 * raises a `RuntimeException` rather than silently dropping them.
 */
final class RegoloGatewayImageTest extends TestCase
{
    public function test_generate_image_returns_b64_encoded_png(): void
    {
        Http::fake([
            'api.regolo.test/v1/images/generations' => Http::response($this->imageFixture(['fake-png-1', 'fake-png-2'])),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        $response = $gateway->generateImage(
            $this->makeProvider(),
            'Qwen-Image',
            'A renaissance fresco of an Italian server farm.',
        );

        $this->assertCount(2, $response->images);
        $this->assertSame('fake-png-1', $response->images[0]->image);
        $this->assertSame('image/png', $response->images[0]->mime);
        $this->assertSame('regolo', $response->meta->provider);
        $this->assertSame('Qwen-Image', $response->meta->model);

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return str_ends_with($request->url(), '/images/generations')
                && $body['model'] === 'Qwen-Image'
                && $body['prompt'] === 'A renaissance fresco of an Italian server farm.';
        });
    }

    public function test_generate_image_forwards_size_and_quality_when_provided(): void
    {
        Http::fake([
            'api.regolo.test/v1/images/generations' => Http::response($this->imageFixture(['x'])),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        $gateway->generateImage(
            $this->makeProvider(),
            'Qwen-Image',
            'A photo of Roman ruins at sunset.',
            attachments: [],
            size: '1:1',
            quality: 'high',
        );

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $body['size'] === '1:1'
                && $body['quality'] === 'high';
        });
    }

    public function test_generate_image_omits_size_and_quality_when_null(): void
    {
        Http::fake([
            'api.regolo.test/v1/images/generations' => Http::response($this->imageFixture(['x'])),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        $gateway->generateImage(
            $this->makeProvider(),
            'Qwen-Image',
            'A botanical illustration.',
        );

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            // size + quality must NOT be present in the body so the
            // upstream model can apply its own defaults.
            return ! array_key_exists('size', $body)
                && ! array_key_exists('quality', $body);
        });
    }

    public function test_generate_image_rejects_attachments_with_runtime_exception(): void
    {
        $gateway = new RegoloGateway($this->app->make('events'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Regolo does not currently expose an image-edit endpoint');

        $gateway->generateImage(
            $this->makeProvider(),
            'Qwen-Image',
            'Edit this image.',
            attachments: ['fake-attachment'],
        );
    }

    public function test_image_timeout_precedence_provider_config_then_constant(): void
    {
        // Per-call timeout omitted + provider config['timeout'] = 240
        // → 240 wins (the consuming Laravel app explicitly chose a
        // longer ceiling and we honour it).
        $providerWithTimeout = $this->makeProvider(['timeout' => 240]);
        $this->assertSame(240, $this->resolveImageTimeout(null, $providerWithTimeout));

        // Per-call timeout omitted + provider config has NO timeout
        // entry → fallback to IMAGE_DEFAULT_TIMEOUT_SECONDS (120).
        $providerWithoutTimeout = $this->makeProvider(); // default fixture has no 'timeout' key
        $this->assertSame(120, $this->resolveImageTimeout(null, $providerWithoutTimeout));

        // Per-call timeout 30 + provider config['timeout'] = 240
        // → 30 wins (caller's explicit override always trumps both).
        $this->assertSame(30, $this->resolveImageTimeout(30, $providerWithTimeout));

        // Provider config['timeout'] = 0 (invalid) → falls through to
        // the 120s package floor rather than `Http::timeout(0)` (which
        // means "no timeout at all" on the underlying Guzzle client).
        $providerWithZero = $this->makeProvider(['timeout' => 0]);
        $this->assertSame(120, $this->resolveImageTimeout(null, $providerWithZero));

        // Provider config['timeout'] = "abc" (non-numeric) → same
        // safe-default fallback as the zero / negative cases.
        $providerWithGarbage = $this->makeProvider(['timeout' => 'abc']);
        $this->assertSame(120, $this->resolveImageTimeout(null, $providerWithGarbage));
    }

    public function test_image_default_timeout_constant_is_120_seconds(): void
    {
        // `generateImage` applies `self::IMAGE_DEFAULT_TIMEOUT_SECONDS`
        // when the caller passes `null` for `$timeout`. The constant
        // is the single point of truth for the image-endpoint
        // timeout — pinning it here means a future "let me tune this
        // down" change has to consciously update both the constant
        // and this assertion. Image rendering on Qwen-Image takes
        // 8–25s for a typical prompt; the 60s text-default is too
        // tight, hence the dedicated longer ceiling.
        $this->assertSame(120, RegoloGateway::IMAGE_DEFAULT_TIMEOUT_SECONDS);
    }

    public function test_generate_image_default_model_falls_through_provider_config(): void
    {
        Http::fake([
            'api.regolo.test/v1/images/generations' => Http::response($this->imageFixture(['x'])),
        ]);

        $provider = $this->makeProvider([
            'models' => [
                'text' => ['default' => 'Llama-3.1-8B-Instruct'],
                'embeddings' => ['default' => 'Qwen3-Embedding-8B', 'dimensions' => 4096],
                'reranking' => ['default' => 'Qwen3-Reranker-4B'],
                'image' => ['default' => 'Qwen-Image-Heavy'],
            ],
        ]);

        $this->assertSame('Qwen-Image-Heavy', $provider->defaultImageModel());
    }

    /**
     * Exercise each branch of the gateway's MIME byte-signature
     * sniffer end-to-end through `generateImage()`. The Live suite
     * already covers the JPEG branch (Qwen-Image returns JPEG
     * today), but a regression in the PNG / WebP / GIF87a / GIF89a
     * branches would have shipped silently — none of the existing
     * unit fixtures pass valid base64, so they all fall back to
     * `image/png` regardless of which branch fires. Copilot caught
     * this gap on the v0.2.2 review (PR #11 round-5).
     *
     * For each fixture the magic-byte prefix is the canonical file
     * signature for that format, padded out with arbitrary trailing
     * bytes so the decoded payload exceeds the longest signature
     * length the sniffer inspects (12 bytes for WebP). The padding
     * bytes are deliberately not a second magic prefix so a
     * sniffer regression that, say, classifies WebP-after-PNG as
     * WebP fails this test.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function imageSignatureFixtures(): iterable
    {
        // Each fixture is `[expectedMime, rawBytes]`. The test
        // base64-encodes `rawBytes` to feed the gateway via
        // Http::fake; the gateway then decodes only the first ~12
        // raw bytes via its 24-char prefix-decode optimisation.
        yield 'png' => ['image/png', "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR"];
        yield 'jpeg-jfif' => ['image/jpeg', "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01"];
        yield 'jpeg-exif' => ['image/jpeg', "\xFF\xD8\xFF\xE1\x00\x10Exif\x00\x00"];
        yield 'webp' => ['image/webp', 'RIFF'."\x24\x00\x00\x00".'WEBPVP8 '];
        yield 'gif87a' => ['image/gif', 'GIF87a'."\x10\x00\x10\x00\x00\x00"];
        yield 'gif89a' => ['image/gif', 'GIF89a'."\x10\x00\x10\x00\x00\x00"];
    }

    #[DataProvider('imageSignatureFixtures')]
    public function test_generate_image_classifies_each_recognised_signature(string $expectedMime, string $rawBytes): void
    {
        $b64 = base64_encode($rawBytes);

        Http::fake([
            'api.regolo.test/v1/images/generations' => Http::response($this->imageFixture([$b64])),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        $response = $gateway->generateImage(
            $this->makeProvider(),
            'Qwen-Image',
            'A photorealistic aurora over Alpine peaks.',
        );

        $this->assertSame(
            $expectedMime,
            $response->images[0]->mime,
            sprintf(
                'Gateway must label the response with `%s` when the b64 '.
                'payload decodes to bytes starting with the canonical '.
                'magic prefix for that format. Got `%s`. Hex of decoded '.
                'prefix: `%s`.',
                $expectedMime,
                $response->images[0]->mime,
                bin2hex(substr($rawBytes, 0, 12)),
            ),
        );
    }

    public function test_generate_image_classifies_unrecognised_signature_as_png_fallback(): void
    {
        // A valid base64 payload whose decoded bytes match NO known
        // image magic prefix must fall back to `image/png` — the
        // long-standing OpenAI default and the contract every
        // existing consumer of the gateway relies on. Use 24 bytes
        // of `\x00` so the prefix-decoder (24-char window) always
        // produces 18 raw bytes the sniffer can inspect.
        $b64 = base64_encode(str_repeat("\x00", 24));

        Http::fake([
            'api.regolo.test/v1/images/generations' => Http::response($this->imageFixture([$b64])),
        ]);

        $gateway = new RegoloGateway($this->app->make('events'));

        $response = $gateway->generateImage(
            $this->makeProvider(),
            'Qwen-Image',
            'An abstract pattern of midnight stars.',
        );

        $this->assertSame('image/png', $response->images[0]->mime);
    }

    public function test_generate_image_default_model_when_config_missing(): void
    {
        $provider = $this->makeProvider([
            'models' => [
                'text' => ['default' => 'Llama-3.1-8B-Instruct'],
                'embeddings' => ['default' => 'Qwen3-Embedding-8B', 'dimensions' => 4096],
                'reranking' => ['default' => 'Qwen3-Reranker-4B'],
                // image entry intentionally absent
            ],
        ]);

        // Hard default matches the publicly-documented Qwen-Image entry
        // in `GET https://api.regolo.ai/v1/models`.
        $this->assertSame('Qwen-Image', $provider->defaultImageModel());
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
     * Reflective shim around the gateway's private
     * `imageEffectiveTimeout()` helper. The method is private because
     * the precedence chain it encodes is an implementation detail of
     * `generateImage` — but it's exactly the boundary between
     * caller / provider-config / package-default that callers are
     * most likely to misconfigure, so the unit suite needs to lock it
     * down. `setAccessible(true)` is required because the gateway
     * class is `final` (no subclass spy is possible).
     */
    private function resolveImageTimeout(?int $timeout, RegoloProvider $provider): int
    {
        $gateway = new RegoloGateway($this->app->make('events'));
        $method = new \ReflectionMethod($gateway, 'imageEffectiveTimeout');
        $method->setAccessible(true);

        return $method->invoke($gateway, $timeout, $provider);
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
            ],
        ], $configOverride);

        return new RegoloProvider($config, $this->app->make('events'));
    }

    /**
     * @param  array<int, string>  $b64Payloads
     * @return array<string, mixed>
     */
    private function imageFixture(array $b64Payloads): array
    {
        return [
            'created' => 1_710_000_000,
            'data' => array_map(fn (string $b64) => ['b64_json' => $b64], $b64Payloads),
        ];
    }
}
