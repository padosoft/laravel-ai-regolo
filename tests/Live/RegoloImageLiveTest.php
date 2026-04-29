<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiRegolo\Tests\Live;

use Laravel\Ai\Responses\ImageResponse;

/**
 * Live image-generation test against `POST /v1/images/generations`
 * on `api.regolo.ai`.
 *
 * Verifies the wire contract: the configured image model returns a
 * non-empty base64 image payload, the response shape is the
 * OpenAI-compatible `{ data: [{ b64_json: '...' }] }` envelope, and
 * the gateway maps the result onto `Laravel\Ai\Responses\Data\GeneratedImage`
 * with a non-empty `image` field and a recognised image MIME (PNG /
 * JPEG / WebP / GIF). The OpenAI-compatible envelope has no `mime`
 * field, so the gateway sniffs the decoded bytes' magic prefix —
 * Regolo's `Qwen-Image` empirically returns JPEG today; a future
 * Regolo release that swaps in PNG/WebP/GIF must keep passing this
 * test without code changes here.
 *
 * Cost note: a single Qwen-Image generation against the default
 * Regolo catalogue runs in roughly ~3-8s and costs in the order of
 * €0.001 — well within the "well under €0.01 per full live run"
 * budget.
 */
final class RegoloImageLiveTest extends LiveTestCase
{
    public function test_live_image_generation_returns_b64_image(): void
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
        $this->assertContains(
            $first->mime,
            ['image/png', 'image/jpeg', 'image/webp', 'image/gif'],
            'Gateway must label the generated image with a recognised '.
            'image MIME type — sniffed from the base64 payload because '.
            "the OpenAI-compatible /v1/images/generations envelope has\n".
            'no `mime` field. Regolo\'s `Qwen-Image` empirically '.
            "returns JPEG today; the gateway's signature-sniffer\n".
            'handles JPEG / PNG / WebP / GIF.',
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
            'Decoded image bytes must be non-empty.',
        );

        // Sanity-check every magic-byte segment the gateway's MIME
        // claim implies. A mismatch (claim PNG but bytes are JPEG, or
        // claim WebP but the `WEBP` marker at offset 8 is missing —
        // a `RIFF` container can hold WAV/AVI/etc., so the WebP
        // detector requires both prefixes) would point at a
        // regression in the signature sniffer itself, not at Regolo,
        // and is the failure we want to catch loudly here.
        foreach ($this->expectedMagicSegmentsForMime($first->mime) as [$offset, $expected]) {
            $this->assertSame(
                $expected,
                substr($decoded, $offset, strlen($expected)),
                sprintf(
                    'Decoded bytes at offset %d must equal the canonical magic '.
                    'segment `%s` for the gateway-claimed MIME `%s`.',
                    $offset,
                    bin2hex($expected),
                    $first->mime,
                ),
            );
        }

        $this->assertSame('regolo', $response->meta->provider);
        $this->assertSame($this->imageModel(), $response->meta->model);
    }

    /**
     * Map a recognised image MIME to the list of `[offset, bytes]`
     * segments its file-signature must satisfy. The gateway's
     * `detectImageMime()` is the source of truth for what each MIME
     * means; this helper inverts that mapping for the live-test
     * assertion. Keep the two in sync — if a new MIME ever gets added
     * on the gateway side, add the matching segments here and the
     * assertion stays meaningful.
     *
     * Most formats are identified by a single prefix at offset 0, but
     * **WebP** is a `RIFF` container with the `WEBP` discriminator at
     * offset 8 — checking `RIFF` alone would also accept WAV/AVI and
     * silently mask a sniffer regression that mis-labels them as
     * `image/webp` (Copilot review on PR #11). Returning a list of
     * segments lets the assertion check both anchors without
     * special-casing in the test body.
     *
     * @return list<array{0: int, 1: string}>
     */
    private function expectedMagicSegmentsForMime(string $mime): array
    {
        return match ($mime) {
            'image/png' => [[0, "\x89PNG\r\n\x1a\n"]],
            'image/jpeg' => [[0, "\xFF\xD8\xFF"]],
            'image/webp' => [[0, 'RIFF'], [8, 'WEBP']],
            'image/gif' => [[0, 'GIF8']],
            default => [[0, "\x00"]],
        };
    }
}
