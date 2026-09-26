<?php

declare(strict_types=1);

namespace VoicemailAi\Mail;

/**
 * Selects the mail profile of a voicemail box, falling back to the global one.
 */
final readonly class MailProfiles
{
    /**
     * @param array<string, MailProfile> $mailboxes Profiles keyed by voicemail box number
     */
    public function __construct(
        public MailProfile $default,
        public array $mailboxes = [],
    ) {}

    public function for(?string $mailbox): MailProfile
    {
        return $mailbox !== null ? $this->mailboxes[$mailbox] ?? $this->default : $this->default;
    }
}
