---
title: Chat And Streaming
description: Use Regolo for normal and streaming chat through Laravel AI.
---

# Chat And Streaming

The text gateway maps Laravel AI messages, tools, and attachments to Regolo's OpenAI-classic chat completions surface.

## Standard completion

```php
use Laravel\Ai\Agent;

$answer = Agent::for('Scrivi un oggetto email per onboarding B2B.')
    ->using('regolo', 'Llama-3.3-70B-Instruct')
    ->prompt();

return $answer->text;
```

## Streaming completion

Use streaming when the UI should show token deltas as they arrive:

```php
use Laravel\Ai\Agent;
use Laravel\Ai\Streaming\Events\TextDelta;

$stream = Agent::for('Genera una checklist GDPR per un chatbot.')
    ->using('regolo')
    ->stream();

foreach ($stream as $event) {
    if ($event instanceof TextDelta) {
        echo $event->text;
    }
}
```

## Tool calling

Use Laravel AI's tool APIs as the application contract. Regolo models that support native function calling receive tool schemas directly; other models can still be routed through prompt-level ReAct patterns at the application layer.

:::note
Tool execution belongs to your Laravel application. The provider serializes tool definitions and parses tool calls; it does not run business logic.
:::

## Gotcha warning

:::warning
Do not couple domain code to Regolo response JSON. Read `text`, `usage`, `meta`, and tool calls from Laravel AI response objects so provider routing stays interchangeable.
:::
