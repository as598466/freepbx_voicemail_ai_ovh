<?php

declare(strict_types=1);

namespace VoicemailAi\Tests;

use PHPUnit\Framework\TestCase;
use VoicemailAi\Config;
use VoicemailAi\Exception\ConfigurationException;

final class ConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = Config::fromArray([]);

        self::assertSame('https://oai.endpoints.kepler.ai.cloud.ovh.net/v1/audio/transcriptions', $config->transcriptionUrl);
        self::assertNull($config->apiToken);
        self::assertSame('whisper-large-v3', $config->model);
        self::assertNull($config->language);
        self::assertNull($config->prompt);
        self::assertSame(0.0, $config->temperature);
        self::assertSame(120, $config->timeout);
        self::assertSame(2, $config->maxRetries);
        self::assertNull($config->ffmpegPath);
        self::assertSame('32k', $config->mp3Bitrate);
        self::assertSame(Config::DEFAULT_SENDMAIL, $config->sendmailPath);
        self::assertNull($config->fromAddress);
        self::assertSame('', $config->subjectPrefix);
        self::assertTrue($config->attachAudio);
        self::assertFileExists($config->htmlTemplate);
        self::assertFileExists($config->textTemplate);
        self::assertSame('voicemail-ai', $config->logIdent);
        self::assertFalse($config->debug);
    }

    public function testNormalizesValues(): void
    {
        $config = Config::fromArray([
            'ovh' => [
                'base_url' => 'https://example.com/v1/',
                'token' => '  secret  ',
                'language' => '',
                'prompt' => ['not', 'a', 'string'],
                'timeout' => 0,
                'max_retries' => -3,
            ],
            'mail' => [
                'from_address' => 'vm@example.com',
                'subject_prefix' => '[Répondeur] ',
                'attach_audio' => false,
            ],
        ]);

        self::assertSame('https://example.com/v1/audio/transcriptions', $config->transcriptionUrl);
        self::assertSame('secret', $config->apiToken);
        self::assertNull($config->language);
        self::assertNull($config->prompt);
        self::assertSame(1, $config->timeout);
        self::assertSame(0, $config->maxRetries);
        self::assertSame('vm@example.com', $config->fromAddress);
        self::assertSame('[Répondeur] ', $config->subjectPrefix);
        self::assertFalse($config->attachAudio);
    }

    public function testDistributedFileIsValid(): void
    {
        $config = Config::fromFile(dirname(__DIR__) . '/config/config.dist.php');

        self::assertNull($config->apiToken);
        self::assertSame('fr', $config->language);
        self::assertSame('/usr/bin/ffmpeg', $config->ffmpegPath);
    }

    public function testRejectsSectionThatIsNotAnArray(): void
    {
        $this->expectException(ConfigurationException::class);

        Config::fromArray(['ovh' => 'token']);
    }

    public function testRejectsMissingFile(): void
    {
        $this->expectException(ConfigurationException::class);

        Config::fromFile('/nonexistent/config.php');
    }

    public function testRejectsFileNotReturningAnArray(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'vmai-test-');
        file_put_contents($file, '<?php return 42;');

        try {
            $this->expectException(ConfigurationException::class);

            Config::fromFile($file);
        } finally {
            unlink($file);
        }
    }
}
