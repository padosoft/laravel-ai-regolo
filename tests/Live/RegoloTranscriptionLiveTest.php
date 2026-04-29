<?php

declare(strict_types=1);

namespace Padosoft\LaravelAiRegolo\Tests\Live;

use Laravel\Ai\Files\Base64Audio;
use Laravel\Ai\Responses\TranscriptionResponse;

/**
 * Live audio-transcription test against `POST /v1/audio/transcriptions`
 * on `api.regolo.ai`.
 *
 * Self-skips when `REGOLO_LIVE_TRANSCRIPTION_AUDIO_PATH` is unset
 * because the test needs a real speech recording to assert anything
 * meaningful — Whisper-style models will produce empty / nonsense
 * output on synthetic silence or sine waves, and shipping a
 * pre-recorded fixture would bloat the package distribution. Point
 * the env var at any short MP3 / WAV / OGG / FLAC / M4A clip the
 * operator has on disk — even a single phrase recorded on a phone
 * works. Generate one quickly with:
 *
 *     ffmpeg -f lavfi -t 5 -i "sine=frequency=440" /tmp/silence.mp3   # WON'T transcribe
 *     # or, on macOS:
 *     say -o /tmp/sample.aiff "this is a regolo live test" && \
 *         ffmpeg -i /tmp/sample.aiff /tmp/sample.mp3
 *     # or, on Linux with espeak-ng:
 *     espeak-ng -w /tmp/sample.wav "this is a regolo live test"
 *
 * Synthetic silence / sine waves transcribe to empty `text` so use
 * a real speech sample (the recipes above produce a usable one).
 *
 * Recommended fixture: 5-15 seconds of clearly enunciated Italian or
 * English speech, ≤ 1 MB. Whisper handles longer clips but the test
 * runs in a constrained CI-style 60s default timeout.
 *
 * Verifies the wire contract: the configured transcription model
 * returns a non-empty `text` payload, the response is mapped onto
 * `Laravel\Ai\Responses\TranscriptionResponse`, and the `meta` block
 * carries the canonical provider / model values.
 */
final class RegoloTranscriptionLiveTest extends LiveTestCase
{
    public function test_live_transcription_returns_non_empty_text(): void
    {
        $audioPath = $this->envValue('REGOLO_LIVE_TRANSCRIPTION_AUDIO_PATH');

        if ($audioPath === null || $audioPath === '') {
            $this->markTestSkipped(
                'Live transcription test requires REGOLO_LIVE_TRANSCRIPTION_AUDIO_PATH '.
                'pointing at a real speech audio file (mp3 / wav / ogg / flac / m4a). '.
                'Whisper-style models produce empty / nonsense output on synthetic '.
                'audio, so a real recording is the only way to validate the wire '.
                'contract end-to-end.'
            );
        }

        if (! is_file($audioPath) || ! is_readable($audioPath)) {
            $this->markTestSkipped(sprintf(
                'REGOLO_LIVE_TRANSCRIPTION_AUDIO_PATH is set to "%s" but the file '.
                'does not exist or is not readable. Adjust the env var to a real '.
                'audio file path.',
                $audioPath,
            ));
        }

        $bytes = file_get_contents($audioPath);
        $this->assertNotFalse($bytes, 'Failed to read the audio fixture at '.$audioPath);

        $audio = new Base64Audio(
            base64_encode($bytes),
            $this->detectAudioMimeType($audioPath),
        );

        $response = $this->liveGateway()->generateTranscription(
            $this->liveProvider(),
            $this->transcriptionModel(),
            $audio,
            language: $this->envValue('REGOLO_LIVE_TRANSCRIPTION_LANGUAGE'),
            diarize: false,
            timeout: $this->liveTimeout(),
        );

        $this->assertInstanceOf(TranscriptionResponse::class, $response);
        $this->assertNotEmpty(
            trim($response->text),
            'Live transcription response must carry non-empty `text`. '.
            'If the test fails here on a known-good audio file, verify '.
            'the model can decode the file format (Whisper supports '.
            'mp3 / wav / ogg / flac / m4a / webm).',
        );

        $this->assertSame('regolo', $response->meta->provider);
        $this->assertSame($this->transcriptionModel(), $response->meta->model);
    }

    /**
     * Best-effort MIME detection from filename extension. The gateway's
     * multipart filename heuristic uses the same mapping in reverse,
     * so we keep them in sync.
     */
    private function detectAudioMimeType(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            'flac' => 'audio/flac',
            'm4a' => 'audio/m4a',
            'webm' => 'audio/webm',
            'mp4' => 'audio/mp4',
            default => 'audio/mpeg',
        };
    }
}
