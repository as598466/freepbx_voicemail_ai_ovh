<?php

declare(strict_types=1);

namespace VoicemailAi\Audio;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use VoicemailAi\Process\ProcessRunner;

/**
 * Converts the voicemail recording (wav, wav49/GSM...) to a mono MP3 attachment with ffmpeg.
 *
 * Conversion is best effort: on any failure the original file is returned.
 */
final readonly class AudioConverter
{
    public function __construct(
        private ProcessRunner $processRunner,
        private ?string $ffmpegPath,
        private string $bitrate = '32k',
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public function toMp3(AudioFile $audio): AudioFile
    {
        if ($this->ffmpegPath === null) {
            return $audio;
        }

        try {
            return $this->convert($audio, $this->ffmpegPath);
        } catch (Throwable $exception) {
            $this->logger->warning('Audio conversion error, attaching original audio: {exception}', [
                'exception' => $exception,
            ]);

            return $audio;
        }
    }

    private function convert(AudioFile $audio, string $ffmpegPath): AudioFile
    {
        if (!is_executable($ffmpegPath)) {
            $this->logger->warning('ffmpeg not found at {path}, attaching original audio.', [
                'path' => $ffmpegPath,
            ]);

            return $audio;
        }

        $input = tempnam(sys_get_temp_dir(), 'vmai-');

        if ($input === false) {
            $this->logger->warning('Unable to create a temporary file, attaching original audio.');

            return $audio;
        }

        $output = $input . '.mp3';

        try {
            file_put_contents($input, $audio->content);

            // 16 kHz (MPEG-2) is played everywhere, unlike 8 kHz (MPEG-2.5).
            $result = $this->processRunner->run([
                $ffmpegPath,
                '-hide_banner',
                '-nostdin',
                '-loglevel', 'error',
                '-y',
                '-i', $input,
                '-ac', '1',
                '-ar', '16000',
                '-c:a', 'libmp3lame',
                '-b:a', $this->bitrate,
                $output,
            ]);

            if (!$result->isSuccessful() || !is_file($output) || filesize($output) === 0) {
                $this->logger->warning('ffmpeg conversion failed (exit {code}), attaching original audio: {error}', [
                    'code' => $result->exitCode,
                    'error' => $result->stderr,
                ]);

                return $audio;
            }

            $converted = new AudioFile(
                pathinfo($audio->filename, PATHINFO_FILENAME) . '.mp3',
                'audio/mpeg',
                (string) file_get_contents($output),
            );

            $this->logger->debug('Audio converted: {from} ({fromSize} bytes) -> {to} ({toSize} bytes).', [
                'from' => $audio->filename,
                'fromSize' => $audio->size(),
                'to' => $converted->filename,
                'toSize' => $converted->size(),
            ]);

            return $converted;
        } finally {
            foreach ([$input, $output] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
}
