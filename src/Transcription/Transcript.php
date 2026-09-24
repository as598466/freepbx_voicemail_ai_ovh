<?php

declare(strict_types=1);

namespace VoicemailAi\Transcription;

final readonly class Transcript
{
    public function __construct(
        public string $text,
        public ?string $language = null,
        public ?float $duration = null,
    ) {}

    public function isEmpty(): bool
    {
        return trim($this->text) === '';
    }
}
