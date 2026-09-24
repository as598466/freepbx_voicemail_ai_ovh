<?php

declare(strict_types=1);

namespace VoicemailAi\Process;

use VoicemailAi\Exception\ProcessException;

/**
 * Runs an external command without going through a shell.
 */
final readonly class ProcessRunner
{
    /**
     * @param non-empty-list<string> $command
     *
     * @throws ProcessException
     */
    public function run(array $command, ?string $stdin = null): ProcessResult
    {
        $descriptors = [
            0 => $stdin === null ? ['file', '/dev/null', 'r'] : ['pipe', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new ProcessException(sprintf('Unable to start "%s".', $command[0]));
        }

        if ($stdin !== null) {
            $this->write($pipes[0], $stdin);
            fclose($pipes[0]);
        }

        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        return new ProcessResult(proc_close($process), trim($stderr));
    }

    /**
     * @param resource $pipe
     */
    private function write($pipe, string $data): void
    {
        $length = strlen($data);
        $offset = 0;

        while ($offset < $length) {
            $written = fwrite($pipe, substr($data, $offset, 65536));

            if ($written === false || $written === 0) {
                throw new ProcessException('Unable to write to process stdin.');
            }

            $offset += $written;
        }
    }
}
