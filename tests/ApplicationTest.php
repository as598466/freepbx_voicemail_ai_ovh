<?php

declare(strict_types=1);

namespace VoicemailAi\Tests;

use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use VoicemailAi\Application;
use VoicemailAi\Audio\AudioConverter;
use VoicemailAi\Audio\AudioFile;
use VoicemailAi\Mail\RawMailForwarder;
use VoicemailAi\Mail\TemplateRenderer;
use VoicemailAi\Mail\VoicemailMailer;
use VoicemailAi\Mail\VoicemailParser;
use VoicemailAi\Process\ProcessRunner;
use VoicemailAi\Transcription\TranscriberInterface;
use VoicemailAi\Transcription\Transcript;
use VoicemailAi\Transcription\TranscriptionException;

final class ApplicationTest extends TestCase
{
    public function testSendsEnrichedEmailWithTranscription(): void
    {
        $transcriber = new class implements TranscriberInterface {
            public function transcribe(AudioFile $audio): Transcript
            {
                return new Transcript('Bonjour, rappelez-moi au sujet du devis.', 'fr', 3.2);
            }
        };

        $output = $this->runApplication($transcriber, $this->fixture());

        self::assertStringContainsString('To: Jean Dupont <jean.dupont@example.com>', $output);
        self::assertStringContainsString('X-Voicemail-Transcription: ok', $output);
        self::assertStringContainsString('rappelez-moi', quoted_printable_decode($output));
        self::assertStringContainsString('filename=msg0000.wav', $output);
    }

    public function testSendsEmailEvenWhenTranscriptionFails(): void
    {
        $transcriber = new class implements TranscriberInterface {
            public function transcribe(AudioFile $audio): Transcript
            {
                throw new TranscriptionException('HTTP 503');
            }
        };

        $output = $this->runApplication($transcriber, $this->fixture());

        self::assertStringContainsString('X-Voicemail-Transcription: failed', $output);
        self::assertStringContainsString("n'a pas pu être réalisée", quoted_printable_decode($output));
    }

    public function testForwardsEmailWithoutAudioUnchanged(): void
    {
        $raw = "From: vm@pbx.example.com\nTo: pager@example.com\nSubject: Nouveau message\n\nMessage de 0612345678\n";

        $transcriber = new class implements TranscriberInterface {
            public function transcribe(AudioFile $audio): Transcript
            {
                throw new LogicException('Must not be called.');
            }
        };

        self::assertSame($raw, $this->runApplication($transcriber, $raw));
    }

    private function runApplication(TranscriberInterface $transcriber, string $raw): string
    {
        $output = fopen('php://memory', 'w+');
        self::assertIsResource($output);

        $processRunner = new ProcessRunner();
        $templates = dirname(__DIR__) . '/templates';

        $application = new Application(
            parser: new VoicemailParser(),
            converter: new AudioConverter($processRunner, null),
            transcriber: $transcriber,
            mailer: new VoicemailMailer(
                renderer: new TemplateRenderer(),
                sendmailPath: '/usr/sbin/sendmail',
                htmlTemplate: $templates . '/email.html.php',
                textTemplate: $templates . '/email.txt.php',
            ),
            forwarder: new RawMailForwarder($processRunner, '/usr/sbin/sendmail'),
            logger: new NullLogger(),
            output: $output,
        );

        self::assertSame(Application::EXIT_SUCCESS, $application->run($raw));

        rewind($output);

        return (string) stream_get_contents($output);
    }

    private function fixture(): string
    {
        return (string) file_get_contents(__DIR__ . '/fixtures/voicemail.eml');
    }
}
