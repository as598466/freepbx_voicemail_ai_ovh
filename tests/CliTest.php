<?php

declare(strict_types=1);

namespace VoicemailAi\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Runs bin/voicemail-ai in a subprocess, always with --dry-run so that nothing is sent.
 */
final class CliTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        array_map('unlink', array_filter($this->temporaryFiles, 'is_file'));
    }

    public function testIgnoresUnknownOptions(): void
    {
        $result = $this->runCli($this->fixture(), ['--config=' . $this->config(), '--dryrun']);

        self::assertSame(0, $result['exitCode']);
        self::assertStringContainsString('X-Voicemail-Transcription: failed', $result['stdout']);
        self::assertStringContainsString('ignoring unknown option(s): --dryrun', $result['stderr']);
    }

    public function testForwardsOriginalEmailWhenConfigurationIsMissing(): void
    {
        $raw = $this->fixture();

        $result = $this->runCli($raw, ['--config=/nonexistent/config.php']);

        self::assertSame(1, $result['exitCode']);
        self::assertSame($raw, $result['stdout']);
    }

    public function testAttachesOriginalAudioWhenTemporaryDirectoryIsUnusable(): void
    {
        // tempnam() then falls back to the system directory with a notice, turned into an exception.
        $result = $this->runCli(
            $this->fixture(),
            ['--config=' . $this->config(['audio' => ['ffmpeg' => '/bin/true']])],
            ['TMPDIR' => '/nonexistent'],
        );

        self::assertSame(0, $result['exitCode']);
        self::assertStringContainsString('X-Voicemail-Transcription: failed', $result['stdout']);
        self::assertStringContainsString('filename=msg0000.wav', $result['stdout']);
    }

    public function testForwardsOriginalEmailOnFatalError(): void
    {
        $raw = $this->fixture();

        // Redeclaring a function is a compile error that no try/catch can intercept.
        $template = $this->temporaryFile('<?php function vmai_fatal() {} function vmai_fatal() {}');

        $result = $this->runCli($raw, ['--config=' . $this->config(['mail' => ['html_template' => $template]])]);

        self::assertNotSame(0, $result['exitCode']);
        self::assertSame($raw, $result['stdout']);
    }

    public function testFailsOnEmptyInput(): void
    {
        $result = $this->runCli('', ['--config=' . $this->config()]);

        self::assertSame(1, $result['exitCode']);
        self::assertSame('', $result['stdout']);
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $environment
     *
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    private function runCli(string $stdin, array $arguments, array $environment = []): array
    {
        $stdout = $this->temporaryFile('');
        $stderr = $this->temporaryFile('');

        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', dirname(__DIR__) . '/bin/voicemail-ai', '--dry-run', ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['file', $stdout, 'w'], 2 => ['file', $stderr, 'w']],
            $pipes,
            null,
            $environment + getenv(),
        );
        self::assertIsResource($process);

        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);

        return [
            'exitCode' => proc_close($process),
            'stdout' => (string) file_get_contents($stdout),
            'stderr' => (string) file_get_contents($stderr),
        ];
    }

    /**
     * Configuration whose transcription fails immediately (nothing listens on port 1).
     *
     * @param array<string, array<string, mixed>> $overrides
     */
    private function config(array $overrides = []): string
    {
        $config = array_replace_recursive([
            'ovh' => ['base_url' => 'http://127.0.0.1:1/v1', 'max_retries' => 0, 'timeout' => 5],
            'audio' => ['ffmpeg' => null],
            'log' => ['ident' => 'voicemail-ai-test'],
        ], $overrides);

        return $this->temporaryFile('<?php return ' . var_export($config, true) . ';');
    }

    private function temporaryFile(string $content): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'vmai-test-');
        file_put_contents($file, $content);

        return $this->temporaryFiles[] = $file;
    }

    private function fixture(): string
    {
        return (string) file_get_contents(__DIR__ . '/fixtures/voicemail.eml');
    }
}
