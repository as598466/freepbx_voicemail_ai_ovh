<?php

declare(strict_types=1);

namespace VoicemailAi\Tests\Transcription;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use VoicemailAi\Audio\AudioFile;
use VoicemailAi\Transcription\OvhTranscriber;
use VoicemailAi\Transcription\Transcript;
use VoicemailAi\Transcription\TranscriptionException;

/**
 * Runs OvhTranscriber against a fake AI Endpoints server (PHP built-in web server).
 */
final class OvhTranscriberTest extends TestCase
{
    /** @var resource|null */
    private static $server;

    private static string $baseUrl;

    private static string $stateDir;

    private string $runId;

    public static function setUpBeforeClass(): void
    {
        self::$stateDir = sys_get_temp_dir() . '/vmai-test-' . bin2hex(random_bytes(6));
        mkdir(self::$stateDir);

        // Reserve a free port.
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        $server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, dirname(__DIR__) . '/fixtures/transcription-server.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['VMAI_TEST_STATE_DIR' => self::$stateDir],
        );
        self::assertIsResource($server);
        self::$server = $server;
        self::$baseUrl = 'http://127.0.0.1:' . $port;

        for ($try = 0; $try < 100; $try++) {
            $connection = @fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 0.1);

            if (is_resource($connection)) {
                fclose($connection);

                return;
            }

            usleep(50_000);
        }

        throw new RuntimeException('The fake transcription server did not start.');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }

        array_map('unlink', glob(self::$stateDir . '/*') ?: []);
        rmdir(self::$stateDir);
    }

    protected function setUp(): void
    {
        $this->runId = bin2hex(random_bytes(6));
    }

    public function testSendsAudioAndParsesResponse(): void
    {
        $transcript = $this->transcriber('ok', token: 'secret', language: 'fr', prompt: 'Devis')->transcribe($this->audio());

        self::assertEquals(new Transcript('Bonjour, rappelez-moi.', 'french', 3.5), $transcript);

        $requests = $this->requests('ok');
        self::assertCount(1, $requests);
        self::assertSame('POST', $requests[0]['method']);
        self::assertSame('Bearer secret', $requests[0]['authorization']);
        self::assertSame([
            'model' => 'whisper-large-v3',
            'response_format' => 'verbose_json',
            'temperature' => '0',
            'language' => 'fr',
            'prompt' => 'Devis',
        ], $requests[0]['fields']);
        self::assertSame(['name' => 'msg0000.wav', 'type' => 'audio/x-wav', 'size' => 44], $requests[0]['file']);
    }

    public function testOmitsTokenAndOptionalFields(): void
    {
        $this->transcriber('ok')->transcribe($this->audio());

        $request = $this->requests('ok')[0];
        self::assertNull($request['authorization']);
        self::assertArrayNotHasKey('language', $request['fields']);
        self::assertArrayNotHasKey('prompt', $request['fields']);
    }

    public function testRetriesTemporaryErrors(): void
    {
        $transcript = $this->transcriber('flaky', maxRetries: 2)->transcribe($this->audio());

        self::assertSame('Bonjour', $transcript->text);
        self::assertCount(2, $this->requests('flaky'));
    }

    public function testGivesUpAfterMaxRetries(): void
    {
        $exception = $this->transcriptionFailure($this->transcriber('down', maxRetries: 2));

        self::assertTrue($exception->retryable);
        self::assertStringContainsString('HTTP 503', $exception->getMessage());
        self::assertCount(3, $this->requests('down'));
    }

    public function testDoesNotRetryClientErrors(): void
    {
        $exception = $this->transcriptionFailure($this->transcriber('bad-request', maxRetries: 2));

        self::assertFalse($exception->retryable);
        self::assertStringContainsString('Invalid model', $exception->getMessage());
        self::assertCount(1, $this->requests('bad-request'));
    }

    public function testRejectsInvalidJson(): void
    {
        $exception = $this->transcriptionFailure($this->transcriber('invalid-json', maxRetries: 2));

        self::assertStringContainsString('Invalid JSON response', $exception->getMessage());
        self::assertCount(1, $this->requests('invalid-json'));
    }

    public function testRejectsResponseWithoutText(): void
    {
        $exception = $this->transcriptionFailure($this->transcriber('no-text'));

        self::assertStringContainsString('Unexpected response', $exception->getMessage());
    }

    public function testNetworkErrorsAreRetryable(): void
    {
        // Nothing listens on port 1: the connection is refused.
        $transcriber = new OvhTranscriber('http://127.0.0.1:1/audio/transcriptions', null, 'whisper-large-v3', maxRetries: 0);

        $exception = $this->transcriptionFailure($transcriber);

        self::assertTrue($exception->retryable);
        self::assertStringContainsString('HTTP request failed', $exception->getMessage());
    }

    private function transcriber(
        string $scenario,
        ?string $token = null,
        ?string $language = null,
        ?string $prompt = null,
        int $maxRetries = 0,
    ): OvhTranscriber {
        return new OvhTranscriber(
            endpointUrl: sprintf('%s/%s/%s/audio/transcriptions', self::$baseUrl, $scenario, $this->runId),
            apiToken: $token,
            model: 'whisper-large-v3',
            language: $language,
            prompt: $prompt,
            timeout: 5,
            maxRetries: $maxRetries,
            retryDelay: 0,
        );
    }

    private function transcriptionFailure(OvhTranscriber $transcriber): TranscriptionException
    {
        try {
            $transcriber->transcribe($this->audio());
        } catch (TranscriptionException $exception) {
            return $exception;
        }

        self::fail('TranscriptionException expected.');
    }

    /**
     * @return list<array{method: string, authorization: string|null, fields: array<string, string>, file: array<string, mixed>|null}>
     */
    private function requests(string $scenario): array
    {
        $files = glob(sprintf('%s/%s-%s-*.json', self::$stateDir, $scenario, $this->runId)) ?: [];
        sort($files, SORT_NATURAL);

        return array_map(
            static fn(string $file): array => json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR),
            $files,
        );
    }

    private function audio(): AudioFile
    {
        // Minimal WAV header: the fake server does not decode the audio.
        return new AudioFile('msg0000.wav', 'audio/x-wav', str_pad('RIFF', 44, "\0"));
    }
}
