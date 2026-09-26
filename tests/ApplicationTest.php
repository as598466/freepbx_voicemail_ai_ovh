<?php

declare(strict_types=1);

namespace VoicemailAi\Tests;

use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use VoicemailAi\Application;
use VoicemailAi\Audio\AudioConverter;
use VoicemailAi\Audio\AudioFile;
use VoicemailAi\Mail\MailProfile;
use VoicemailAi\Mail\MailProfiles;
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
        $output = $this->runApplication($this->succeedingTranscriber(), $this->fixture());

        self::assertStringContainsString('To: Jean Dupont <jean.dupont@example.com>', $output);
        self::assertStringContainsString('X-Voicemail-Transcription: ok', $output);
        self::assertStringContainsString('rappelez-moi', quoted_printable_decode($output));
        self::assertStringContainsString('filename=msg0000.wav', $output);
    }

    public function testSendsEmailEvenWhenTranscriptionFails(): void
    {
        $output = $this->runApplication($this->failingTranscriber(), $this->fixture());

        self::assertStringContainsString('X-Voicemail-Transcription: failed', $output);
        self::assertStringContainsString("n'a pas pu être réalisée", quoted_printable_decode($output));
    }

    public function testOmitsAudioWhenAttachmentIsDisabled(): void
    {
        $output = $this->runApplication($this->succeedingTranscriber(), $this->fixture(), attachAudio: false);

        self::assertStringContainsString('X-Voicemail-Transcription: ok', $output);
        self::assertStringNotContainsString('filename=msg0000.wav', $output);
    }

    public function testAttachesAudioWhenTranscriptionFailsEvenIfAttachmentIsDisabled(): void
    {
        $output = $this->runApplication($this->failingTranscriber(), $this->fixture(), attachAudio: false);

        self::assertStringContainsString('X-Voicemail-Transcription: failed', $output);
        self::assertStringContainsString('filename=msg0000.wav', $output);
    }

    public function testUsesMailSettingsOfTheMailbox(): void
    {
        $templates = dirname(__DIR__) . '/templates';
        $profiles = new MailProfiles(
            new MailProfile($templates . '/email.html.php', $templates . '/email.txt.php', subjectPrefix: '[Global] '),
            [
                '1001' => new MailProfile(
                    $templates . '/email.html.php',
                    $templates . '/email.txt.php',
                    fromAddress: 'sav@example.com',
                    fromName: 'Service client',
                    subjectPrefix: '[SAV] ',
                    attachAudio: false,
                ),
            ],
        );

        $output = $this->runApplication($this->succeedingTranscriber(), $this->fixture(), mailProfiles: $profiles);

        self::assertStringContainsString('From: Service client <sav@example.com>', $output);
        self::assertStringContainsString('[SAV] ', iconv_mime_decode_headers($output, 0, 'UTF-8')['Subject'] ?? '');
        self::assertStringNotContainsString('filename=msg0000.wav', $output);
    }

    public function testUsesGlobalMailSettingsForOtherMailboxes(): void
    {
        $templates = dirname(__DIR__) . '/templates';
        $profiles = new MailProfiles(
            new MailProfile($templates . '/email.html.php', $templates . '/email.txt.php', subjectPrefix: '[Global] '),
            ['2000' => new MailProfile('/nonexistent/email.html.php', $templates . '/email.txt.php')],
        );

        $output = $this->runApplication($this->succeedingTranscriber(), $this->fixture(), mailProfiles: $profiles);

        self::assertStringContainsString('[Global] ', iconv_mime_decode_headers($output, 0, 'UTF-8')['Subject'] ?? '');
        self::assertStringContainsString('filename=msg0000.wav', $output);
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

    public function testForwardsOriginalEmailWhenComposingFails(): void
    {
        $raw = $this->fixture();

        $output = $this->runApplication($this->succeedingTranscriber(), $raw, htmlTemplate: '/nonexistent/email.html.php');

        self::assertSame($raw, $output);
    }

    public function testForwardsOriginalEmailWithoutRecipient(): void
    {
        $raw = (string) preg_replace('/^To: .*\n/m', '', $this->fixture());

        self::assertSame($raw, $this->runApplication($this->succeedingTranscriber(), $raw));
    }

    public function testFailsOnEmptyInput(): void
    {
        $output = $this->runApplication(
            $this->succeedingTranscriber(),
            " \n",
            expectedExitCode: Application::EXIT_FAILURE,
        );

        self::assertSame('', $output);
    }

    private function runApplication(
        TranscriberInterface $transcriber,
        string $raw,
        bool $attachAudio = true,
        ?string $htmlTemplate = null,
        ?MailProfiles $mailProfiles = null,
        int $expectedExitCode = Application::EXIT_SUCCESS,
    ): string {
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
            ),
            forwarder: new RawMailForwarder($processRunner, '/usr/sbin/sendmail', $output),
            logger: new NullLogger(),
            mailProfiles: $mailProfiles ?? new MailProfiles(new MailProfile(
                htmlTemplate: $htmlTemplate ?? $templates . '/email.html.php',
                textTemplate: $templates . '/email.txt.php',
                attachAudio: $attachAudio,
            )),
            output: $output,
        );

        self::assertSame($expectedExitCode, $application->run($raw));

        rewind($output);

        return (string) stream_get_contents($output);
    }

    private function succeedingTranscriber(): TranscriberInterface
    {
        return new class implements TranscriberInterface {
            public function transcribe(AudioFile $audio): Transcript
            {
                return new Transcript('Bonjour, rappelez-moi au sujet du devis.', 'fr', 3.2);
            }
        };
    }

    private function failingTranscriber(): TranscriberInterface
    {
        return new class implements TranscriberInterface {
            public function transcribe(AudioFile $audio): Transcript
            {
                throw new TranscriptionException('HTTP 503');
            }
        };
    }

    private function fixture(): string
    {
        return (string) file_get_contents(__DIR__ . '/fixtures/voicemail.eml');
    }
}
