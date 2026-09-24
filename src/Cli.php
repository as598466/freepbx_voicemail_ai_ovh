<?php

declare(strict_types=1);

namespace VoicemailAi;

use ErrorException;
use Throwable;
use VoicemailAi\Log\SyslogLogger;
use VoicemailAi\Mail\RawMailForwarder;
use VoicemailAi\Process\ProcessRunner;

/**
 * Command line entry point used as Asterisk voicemail "mailcmd".
 */
final class Cli
{
    private const USAGE = <<<'TXT'
        Usage: voicemail-ai [--config=FILE] [--dry-run] [--verbose] < message.eml

          --config=FILE  Configuration file (default: config/config.php or $VOICEMAIL_AI_CONFIG)
          --dry-run      Transcribe but print the resulting email on stdout instead of sending it
          --verbose      Also write logs to stderr, including debug messages

        TXT;

    /**
     * @param list<string> $argv
     * @param string $raw Email piped by Asterisk
     * @param resource $stdout
     */
    public function main(array $argv, string $raw, $stdout): int
    {
        [$options, $unknown] = $this->parseOptions($argv);

        if ($unknown !== []) {
            // A typo in the FreePBX "Mail Command" must not lose voicemails: warn and go on.
            fwrite(STDERR, sprintf('voicemail-ai: ignoring unknown option(s): %s%s', implode(' ', $unknown), PHP_EOL));
            fwrite(STDERR, self::USAGE);
        }

        // Turn warnings into exceptions so that every failure reaches the fallback path.
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0 || ($severity & (E_DEPRECATED | E_USER_DEPRECATED)) !== 0) {
                return false;
            }

            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            return $this->process($raw, $options, $unknown, $options['dryRun'] ? $stdout : null);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @param array{config: string, dryRun: bool, verbose: bool} $options
     * @param list<string> $unknown
     * @param resource|null $output
     */
    private function process(string $raw, array $options, array $unknown, mixed $output): int
    {
        try {
            $config = Config::fromFile($options['config']);
        } catch (Throwable $exception) {
            return $this->rescue($raw, $exception, Config::DEFAULT_SENDMAIL, $output);
        }

        try {
            $logger = new SyslogLogger(
                $config->logIdent,
                $config->debug || $options['verbose'],
                $options['verbose'] ? STDERR : null,
            );

            if ($unknown !== []) {
                $logger->warning('Ignoring unknown option(s): {options}', ['options' => implode(' ', $unknown)]);
            }

            return Application::create($config, $logger, $output)->run($raw);
        } catch (Throwable $exception) {
            // Application has its own fallbacks: this only catches unexpected failures.
            return $this->rescue($raw, $exception, $config->sendmailPath, $output);
        }
    }

    /**
     * @param list<string> $argv
     *
     * @return array{array{config: string, dryRun: bool, verbose: bool}, list<string>} Options and unknown arguments
     */
    private function parseOptions(array $argv): array
    {
        $options = [
            'config' => getenv('VOICEMAIL_AI_CONFIG') ?: dirname(__DIR__) . '/config/config.php',
            'dryRun' => false,
            'verbose' => false,
        ];
        $unknown = [];

        foreach (array_slice($argv, 1) as $argument) {
            if ($argument === '--dry-run') {
                $options['dryRun'] = true;
            } elseif ($argument === '--verbose' || $argument === '-v') {
                $options['verbose'] = true;
            } elseif (str_starts_with($argument, '--config=') && strlen($argument) > 9) {
                $options['config'] = substr($argument, 9);
            } else {
                $unknown[] = $argument;
            }
        }

        return [$options, $unknown];
    }

    /**
     * Something unexpected failed: still deliver the voicemail as Asterisk would have done.
     *
     * @param resource|null $output
     */
    private function rescue(string $raw, Throwable $exception, string $sendmailPath, mixed $output): int
    {
        $message = sprintf(
            'voicemail-ai: %s: %s. Forwarding original email.',
            $exception::class,
            rtrim($exception->getMessage(), '.'),
        );

        openlog('voicemail-ai', LOG_PID, LOG_MAIL);
        syslog(LOG_ERR, $message);
        fwrite(STDERR, $message . PHP_EOL);

        if (trim($raw) === '') {
            return Application::EXIT_FAILURE;
        }

        try {
            (new RawMailForwarder(new ProcessRunner(), $sendmailPath, $output))->forward($raw);
        } catch (Throwable $forwardException) {
            syslog(LOG_CRIT, sprintf('voicemail-ai: %s', $forwardException->getMessage()));
        }

        return Application::EXIT_FAILURE;
    }
}
