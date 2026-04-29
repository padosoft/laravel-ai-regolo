<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiRegolo\Tests\Live;

use Laravel\Ai\Responses\ImageResponse;

/**
 * Live image-generation test against `POST /v1/images/generations`
 * on `api.regolo.ai`.
 *
 * Verifies the wire contract: the configured image model returns a
 * non-empty base64 PNG payload, the response shape is the
 * OpenAI-compatible `{ data: [{ b64_json: '...' }] }` envelope, and
 * the gateway maps the result onto `Laravel\Ai\Responses\Data\GeneratedImage`
 * with a non-empty `image` field and `image/png` MIME.
 *
 * Cost note: a single Qwen-Image generation against the default
 * Regolo catalogue runs in roughly ~3-8s and costs in the order of
 * €0.001 — well within the "well under €0.01 per full live run"
 * budget.
 */
final class RegoloImageLiveTest extends LiveTestCase
{
    public function test_live_image_generation_returns_b64_png(): void
    {
        $response = $this->liveGateway()->generateImage(
            $this->liveProvider(),
            $this->imageModel(),
            'A renaissance fresco of an Italian server farm, soft '.
            'natural light, photorealistic, ornate gold frame.',
            attachments: [],
            size: null,
            quality: null,
            timeout: $this->liveTimeout(),
        );

        $this->assertInstanceOf(ImageResponse::class, $response);
        $this->assertGreaterThanOrEqual(
            1,
            count($response->images),
            'Live image generation should return at least one image.',
        );

        $first = $response->images[0];
        $this->assertNotEmpty(
            $first->image,
            'Generated image must carry a non-empty base64 payload.',
        );
        $this->assertSame(
            'image/png',
            $first->mime,
            'Gateway must label the generated image as PNG; '.
            'the OpenAI-compatible /v1/images/generations endpoint '.
            'returns base64 PNG by default.',
        );

        // Round-trip the base64 payload to confirm it actually decodes
        // to bytes — the wire contract is brittle against base64
        // encoders that emit URL-safe variants without padding.
        $decoded = base64_decode($first->image, strict: true);
        $this->assertNotFalse(
            $decoded,
            'Base64 payload must round-trip cleanly via base64_decode().',
        );
        $this->assertGreaterThan(
            0,
            strlen($decoded),
            'Decoded PNG bytes must be non-empty.',
        );

        // Sanity check on the PNG file signature so a future
        // upstream regression that switches to JPEG or returns
        // empty bytes fails this test loudly rather than masquerading
        // as success.
        $this->assertSame(
            "\x89PNG\r\n\x1a\n",
            substr($decoded, 0, 8),
            'Decoded bytes must start with the canonical PNG '.
            'file signature (`\x89PNG\\r\\n\\x1a\\n`).',
        );

        $this->assertSame('regolo', $response->meta->provider);
        $this->assertSame($this->imageModel(), $response->meta->model);
    }
}
