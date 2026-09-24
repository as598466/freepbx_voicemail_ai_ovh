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
    /**
     * @param resource|null $output Dry-run output; when set, the email is written there instead of sent
     */
    public function __construct(
        private ProcessRunner $processRunner,
        private string $sendmailPath,
        private mixed $output = null,
    ) {}

    /**
     * @throws ProcessException
     */
    public function forward(string $raw): void
    {
        if (is_resource($this->output)) {
            fwrite($this->output, $raw);

            return;
        }

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
