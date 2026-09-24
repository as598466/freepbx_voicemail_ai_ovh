<?php

declare(strict_types=1);

namespace VoicemailAi\Log;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;
use Throwable;

/**
 * Minimal PSR-3 logger writing to syslog (journald on Debian 12), and optionally to stderr.
 */
final class SyslogLogger extends AbstractLogger
{
    private const PRIORITIES = [
        LogLevel::EMERGENCY => LOG_EMERG,
        LogLevel::ALERT => LOG_ALERT,
        LogLevel::CRITICAL => LOG_CRIT,
        LogLevel::ERROR => LOG_ERR,
        LogLevel::WARNING => LOG_WARNING,
        LogLevel::NOTICE => LOG_NOTICE,
        LogLevel::INFO => LOG_INFO,
        LogLevel::DEBUG => LOG_DEBUG,
    ];

    /**
     * @param resource|null $stderr
     */
    public function __construct(
        string $ident,
        private readonly bool $debug = false,
        private readonly mixed $stderr = null,
    ) {
        openlog($ident, LOG_PID | LOG_ODELAY, LOG_MAIL);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if ($level === LogLevel::DEBUG && !$this->debug) {
            return;
        }

        $message = $this->interpolate((string) $message, $context);

        syslog(self::PRIORITIES[$level] ?? LOG_INFO, $message);

        if (is_resource($this->stderr)) {
            fwrite($this->stderr, sprintf('[%s] %s%s', $level, $message, PHP_EOL));
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function interpolate(string $message, array $context): string
    {
        $replacements = [];

        foreach ($context as $key => $value) {
            if ($value instanceof Throwable) {
                $value = sprintf('%s: %s', $value::class, $value->getMessage());
            } elseif (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif ($value === null) {
                $value = 'null';
            } elseif (!is_scalar($value) && !$value instanceof Stringable) {
                $value = get_debug_type($value);
            }

            $replacements['{' . $key . '}'] = (string) $value;
        }

        return strtr($message, $replacements);
    }
}
