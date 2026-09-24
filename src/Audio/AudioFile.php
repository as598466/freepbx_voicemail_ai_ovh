<?php

declare(strict_types=1);

namespace VoicemailAi\Audio;

final readonly class AudioFile
{
    public function __construct(
        public string $filename,
        public string $mimeType,
        public string $content,
    ) {}

    public function size(): int
    {
        return strlen($this->content);
    }
}
