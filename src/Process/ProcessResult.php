<?php

declare(strict_types=1);

namespace VoicemailAi\Process;

final readonly class ProcessResult
{
    public function __construct(
        public int $exitCode,
        public string $stderr,
    ) {}

    public function isSuccessful(): bool
    {
        return $this->exitCode === 0;
    }
}
