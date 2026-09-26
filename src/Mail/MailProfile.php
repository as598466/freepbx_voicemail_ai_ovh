<?php

declare(strict_types=1);

namespace VoicemailAi\Mail;

/**
 * Presentation settings of the enriched email, global or specific to a voicemail box.
 */
final readonly class MailProfile
{
    public function __construct(
        public ?string $fromAddress = null,
        public ?string $fromName = null,
        public string $subjectPrefix = '',
        public bool $attachAudio = true,
    ) {}
}
