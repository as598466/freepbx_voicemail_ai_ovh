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
     * @param resource $stdin
     * @param resource $stdout
     */
    public function main(array $argv, $stdin, $stdout): int
    {
        $options = $this->parseOptions($argv);

        if ($options === null) {
            fwrite(STDERR, self::USAGE);

            return Application::EXIT_FAILURE;
        }

        // Turn warnings into exceptions so that every failure reaches the fallback path.
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0 || ($severity & (E_DEPRECATED | E_USER_DEPRECATED)) !== 0) {
                return false;
            }

            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        $raw = (string) stream_get_contents($stdin);

        try {
            $config = Config::fromFile($options['config']);
        } catch (Throwable $exception) {
            return $this->rescue($raw, $exception, $options['dryRun'] ? $stdout : null);
        }

        $logger = new SyslogLogger(
            $config->logIdent,
            $config->debug || $options['verbose'],
            $options['verbose'] ? STDERR : null,
        );

        return Application::create($config, $logger, $options['dryRun'] ? $stdout : null)->run($raw);
    }

    /**
     * @param list<string> $argv
     *
     * @return array{config: string, dryRun: bool, verbose: bool}|null
     */
    private function parseOptions(array $argv): ?array
    {
        $options = [
            'config' => getenv('VOICEMAIL_AI_CONFIG') ?: dirname(__DIR__) . '/config/config.php',
            'dryRun' => false,
            'verbose' => false,
        ];

        foreach (array_slice($argv, 1) as $argument) {
            if ($argument === '--dry-run') {
                $options['dryRun'] = true;
            } elseif ($argument === '--verbose' || $argument === '-v') {
                $options['verbose'] = true;
            } elseif (str_starts_with($argument, '--config=') && strlen($argument) > 9) {
                $options['config'] = substr($argument, 9);
            } else {
                return null;
            }
        }

        return $options;
    }

    /**
     * Configuration is broken: still deliver the voicemail as Asterisk would have done.
     *
     * @param resource|null $output
     */
    private function rescue(string $raw, Throwable $exception, mixed $output): int
    {
        $message = sprintf('voicemail-ai: %s Forwarding original email.', $exception->getMessage());

        openlog('voicemail-ai', LOG_PID, LOG_MAIL);
        syslog(LOG_ERR, $message);
        fwrite(STDERR, $message . PHP_EOL);

        if (trim($raw) === '') {
            return Application::EXIT_FAILURE;
        }

        if (is_resource($output)) {
            fwrite($output, $raw);

            return Application::EXIT_FAILURE;
        }

        try {
            (new RawMailForwarder(new ProcessRunner(), Config::DEFAULT_SENDMAIL))->forward($raw);
        } catch (Throwable $forwardException) {
            syslog(LOG_CRIT, sprintf('voicemail-ai: %s', $forwardException->getMessage()));
        }

        return Application::EXIT_FAILURE;
    }
}
