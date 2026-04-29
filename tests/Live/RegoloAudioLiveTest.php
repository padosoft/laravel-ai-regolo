<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiRegolo\Tests\Live;

use Laravel\Ai\Responses\AudioResponse;

/**
 * Live text-to-speech test against `POST /v1/audio/speech` on
 * `api.regolo.ai`.
 *
 * Self-skips when `REGOLO_LIVE_AUDIO_MODEL` is unset because Regolo's
 * TTS catalogue is not on the public `GET /v1/models` listing yet —
 * the model id has to come from Seeweb directly through the
 * commercial / early-access channel. Once the catalogue is published
 * the env var becomes optional and a sensible default kicks in
 * (see `audioModelOrSkip()` in {@see LiveTestCase}).
 *
 * Verifies the wire contract: the configured TTS model returns a
 * non-empty audio payload that decodes to MP3 bytes and the response
 * carries the canonical `audio/mpeg` MIME type.
 */
final class RegoloAudioLiveTest extends LiveTestCase
{
    public function test_live_audio_returns_base64_encoded_mp3(): void
    {
        $model = $this->audioModelOrSkip();

        $response = $this->liveGateway()->generateAudio(
            $this->liveProvider(),
            $model,
            'Buongiorno e benvenuto su Regolo: la nuvola sovrana italiana per l\'intelligenza artificiale.',
            $this->audioVoice(),
            instructions: null,
            timeout: $this->liveTimeout(),
        );

        $this->assertInstanceOf(AudioResponse::class, $response);
        $this->assertNotEmpty(
            $response->audio,
            'Live TTS response must carry a non-empty base64 audio payload.',
        );
        $this->assertSame(
            'audio/mpeg',
            $response->mime,
            'Gateway must label the generated audio as MP3 (the '.
            'OpenAI-compatible /v1/audio/speech endpoint returns '.
            'audio/mpeg by default).',
        );

        // Round-trip the base64 payload to confirm it actually decodes
        // to bytes. A subset of TTS providers occasionally emit base64
        // with stripped padding when the input is short — strict
        // decode catches that immediately.
        $decoded = base64_decode($response->audio, strict: true);
        $this->assertNotFalse(
            $decoded,
            'Base64 audio payload must round-trip cleanly via base64_decode().',
        );
        $this->assertGreaterThan(
            0,
            strlen($decoded),
            'Decoded MP3 bytes must be non-empty.',
        );

        // Sanity check on the MP3 file signature: most encoders emit
        // an `ID3` tag prefix or the canonical MPEG sync word
        // (`\xFF\xFB`/`\xFF\xF3`/`\xFF\xF2`). Accept either so a
        // codec switch on Regolo's side does not flake the test.
        $head = substr($decoded, 0, 3);
        $first = ord($decoded[0] ?? "\x00");
        $second = ord($decoded[1] ?? "\x00");
        $this->assertTrue(
            $head === 'ID3' || ($first === 0xFF && ($second & 0xE0) === 0xE0),
            sprintf(
                'Decoded bytes must start with "ID3" tag or a 0xFF/0xExx MPEG sync word; got: %s',
                bin2hex(substr($decoded, 0, 4)),
            ),
        );

        $this->assertSame('regolo', $response->meta->provider);
        $this->assertSame($model, $response->meta->model);
    }
}
