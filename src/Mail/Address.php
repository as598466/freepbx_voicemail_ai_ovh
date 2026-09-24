<?php

declare(strict_types=1);

namespace VoicemailAi\Mail;

final readonly class Address
{
    public function __construct(
        public string $email,
        public string $name = '',
    ) {}
}
