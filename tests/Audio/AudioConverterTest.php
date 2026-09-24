<?php

declare(strict_types=1);

namespace VoicemailAi\Tests\Audio;

use PHPUnit\Framework\TestCase;
use VoicemailAi\Audio\AudioConverter;
use VoicemailAi\Audio\AudioFile;
use VoicemailAi\Mail\VoicemailParser;
use VoicemailAi\Process\ProcessRunner;

final class AudioConverterTest extends TestCase
{
    private const FFMPEG = '/usr/bin/ffmpeg';

    public function testReturnsOriginalAudioWithoutFfmpeg(): void
    {
        $audio = $this->audio();

        self::assertSame($audio, (new AudioConverter(new ProcessRunner(), null))->toMp3($audio));
    }

    public function testReturnsOriginalAudioWhenFfmpegIsMissing(): void
    {
        $audio = $this->audio();

        self::assertSame($audio, (new AudioConverter(new ProcessRunner(), '/nonexistent/ffmpeg'))->toMp3($audio));
    }

    public function testReturnsOriginalAudioWhenFfmpegFails(): void
    {
        $audio = $this->audio();

        self::assertSame($audio, (new AudioConverter(new ProcessRunner(), '/bin/false'))->toMp3($audio));
    }

    public function testConvertsToMp3(): void
    {
        if (!is_executable(self::FFMPEG)) {
            self::markTestSkipped('ffmpeg is not installed.');
        }

        $temporaryFiles = glob(sys_get_temp_dir() . '/vmai-*') ?: [];

        $mp3 = (new AudioConverter(new ProcessRunner(), self::FFMPEG))->toMp3($this->audio());

        self::assertSame('msg0000.mp3', $mp3->filename);
        self::assertSame('audio/mpeg', $mp3->mimeType);
        self::assertStringStartsWith('ID3', $mp3->content);
        self::assertSame($temporaryFiles, glob(sys_get_temp_dir() . '/vmai-*') ?: [], 'Temporary files left behind.');
    }

    private function audio(): AudioFile
    {
        $voicemail = (new VoicemailParser())->parse((string) file_get_contents(__DIR__ . '/../fixtures/voicemail.eml'));
        self::assertNotNull($voicemail->audio);

        return $voicemail->audio;
    }
}
