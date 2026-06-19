---
title: Multimodal
description: Use Regolo image generation, transcription, and text-to-speech.
---

# Multimodal

The package includes Regolo gateways for image generation, audio transcription, and text-to-speech when the configured Regolo model catalog supports the selected model ids.

## Image generation

```php
use Laravel\Ai\Image;

$image = Image::of('Un diagramma isometrico di un data center italiano')
    ->generate('regolo', 'Qwen-Image');
```

## Audio transcription

```php
use Laravel\Ai\Transcription;

$transcript = Transcription::of(storage_path('calls/demo.mp3'))
    ->using('regolo', 'faster-whisper-large-v3')
    ->generate();
```

## Text-to-speech

```php
use Laravel\Ai\Audio;

$speech = Audio::for('Benvenuto nella console Regolo.')
    ->generate('regolo', config('ai.providers.regolo.models.audio.default'));
```

:::warning
Regolo's text-to-speech catalog may require an explicit model id from Seeweb. Leave `REGOLO_AUDIO_MODEL` empty until you have a valid value, and skip live TTS verification in that state.
:::
