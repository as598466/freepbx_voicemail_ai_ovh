<?php

declare(strict_types=1);

namespace VoicemailAi\Transcription;

use RuntimeException;
use Throwable;

final class TranscriptionException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
