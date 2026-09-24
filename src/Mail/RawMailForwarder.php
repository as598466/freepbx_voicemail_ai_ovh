<?php

declare(strict_types=1);

namespace VoicemailAi\Mail;

use VoicemailAi\Exception\ProcessException;
use VoicemailAi\Process\ProcessRunner;

/**
 * Delivers the original Asterisk email unchanged, exactly like the default "sendmail -t" mailcmd.
 */
final readonly class RawMailForwarder
{
    public function __construct(
        private ProcessRunner $processRunner,
        private string $sendmailPath,
    ) {}

    /**
     * @throws ProcessException
     */
    public function forward(string $raw): void
    {
        $result = $this->processRunner->run([$this->sendmailPath, '-t', '-oi'], $raw);

        if (!$result->isSuccessful()) {
            throw new ProcessException(sprintf(
                'sendmail exited with code %d: %s',
                $result->exitCode,
                $result->stderr,
            ));
        }
    }
}
