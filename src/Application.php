<?php

declare(strict_types=1);

namespace VoicemailAi;

use PHPMailer\PHPMailer\PHPMailer;
use Psr\Log\LoggerInterface;
use Throwable;
use VoicemailAi\Audio\AudioConverter;
use VoicemailAi\Audio\AudioFile;
use VoicemailAi\Mail\RawMailForwarder;
use VoicemailAi\Mail\TemplateRenderer;
use VoicemailAi\Mail\VoicemailMailer;
use VoicemailAi\Mail\VoicemailParser;
use VoicemailAi\Process\ProcessRunner;
use VoicemailAi\Transcription\OvhTranscriber;
use VoicemailAi\Transcription\TranscriberInterface;
use VoicemailAi\Transcription\Transcript;

/**
 * Voicemail pipeline: parse -> transcribe -> convert attachment to MP3 -> send.
 *
 * A voicemail must never be lost: whenever the enriched email cannot be built
 * or sent, the original Asterisk email is delivered unchanged.
 */
final readonly class Application
{
    public const EXIT_SUCCESS = 0;

    public const EXIT_FAILURE = 1;

    /**
     * @param resource|null $output Dry-run output; when set, nothing is sent
     */
    public function __construct(
        private VoicemailParser $parser,
        private AudioConverter $converter,
        private TranscriberInterface $transcriber,
        private VoicemailMailer $mailer,
        private RawMailForwarder $forwarder,
        private LoggerInterface $logger,
        private bool $attachAudio = true,
        private mixed $output = null,
    ) {}

    /**
     * @param resource|null $output
     */
    public static function create(Config $config, LoggerInterface $logger, mixed $output = null): self
    {
        $processRunner = new ProcessRunner();

        return new self(
            parser: new VoicemailParser(),
            converter: new AudioConverter($processRunner, $config->ffmpegPath, $config->mp3Bitrate, $logger),
            transcriber: new OvhTranscriber(
                endpointUrl: $config->transcriptionUrl,
                apiToken: $config->apiToken,
                model: $config->model,
                language: $config->language,
                prompt: $config->prompt,
                temperature: $config->temperature,
                timeout: $config->timeout,
                maxRetries: $config->maxRetries,
                logger: $logger,
            ),
            mailer: new VoicemailMailer(
                renderer: new TemplateRenderer(),
                sendmailPath: $config->sendmailPath,
                htmlTemplate: $config->htmlTemplate,
                textTemplate: $config->textTemplate,
                fromAddress: $config->fromAddress,
                fromName: $config->fromName,
                envelopeSender: $config->envelopeSender,
                subjectPrefix: $config->subjectPrefix,
            ),
            forwarder: new RawMailForwarder($processRunner, $config->sendmailPath),
            logger: $logger,
            attachAudio: $config->attachAudio,
            output: $output,
        );
    }

    public function run(string $raw): int
    {
        if (trim($raw) === '') {
            $this->logger->error('Empty input, nothing to send.');

            return self::EXIT_FAILURE;
        }

        try {
            $voicemail = $this->parser->parse($raw);
        } catch (Throwable $exception) {
            $this->logger->error('Parsing failed, forwarding original email: {exception}', [
                'exception' => $exception,
            ]);

            return $this->forwardRaw($raw);
        }

        if ($voicemail->audio === null) {
            // Pager notification or mailbox configured without attachment.
            $this->logger->info('No audio attachment, forwarding original email.');

            return $this->forwardRaw($raw);
        }

        $transcript = $this->transcribe($voicemail->audio);
        // Without transcription the recording is the only content left: attach it regardless of the setting.
        $attachment = $this->attachAudio || $transcript === null ? $this->converter->toMp3($voicemail->audio) : null;

        try {
            $this->send($this->mailer->compose($voicemail, $transcript, $attachment));
        } catch (Throwable $exception) {
            $this->logger->error('Sending enriched email failed, forwarding original email: {exception}', [
                'exception' => $exception,
            ]);

            return $this->forwardRaw($raw);
        }

        $this->logger->info('Voicemail from {caller} sent to {recipients} (transcription: {status}).', [
            'caller' => $voicemail->caller() ?? 'unknown',
            'recipients' => implode(', ', array_map(static fn($address) => $address->email, $voicemail->to)),
            'status' => $transcript !== null ? 'ok' : 'failed',
        ]);

        return self::EXIT_SUCCESS;
    }

    private function transcribe(AudioFile $audio): ?Transcript
    {
        $start = hrtime(true);

        try {
            $transcript = $this->transcriber->transcribe($audio);
        } catch (Throwable $exception) {
            $this->logger->error('Transcription failed: {exception}', ['exception' => $exception]);

            return null;
        }

        $this->logger->info('Transcription done in {elapsed}s ({chars} characters, language {language}).', [
            'elapsed' => round((hrtime(true) - $start) / 1e9, 1),
            'chars' => mb_strlen($transcript->text),
            'language' => $transcript->language ?? 'n/a',
        ]);

        return $transcript;
    }

    private function send(PHPMailer $mail): void
    {
        if (is_resource($this->output)) {
            $mail->preSend();
            fwrite($this->output, $mail->getSentMIMEMessage());

            return;
        }

        $mail->send();
    }

    private function forwardRaw(string $raw): int
    {
        if (is_resource($this->output)) {
            fwrite($this->output, $raw);

            return self::EXIT_SUCCESS;
        }

        try {
            $this->forwarder->forward($raw);
        } catch (Throwable $exception) {
            $this->logger->critical('Unable to forward original email, voicemail notification lost: {exception}', [
                'exception' => $exception,
            ]);

            return self::EXIT_FAILURE;
        }

        return self::EXIT_SUCCESS;
    }
}
