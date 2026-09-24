<?php

declare(strict_types=1);

namespace VoicemailAi;

use VoicemailAi\Exception\ConfigurationException;

final readonly class Config
{
    public const DEFAULT_SENDMAIL = '/usr/sbin/sendmail';

    private const DEFAULT_BASE_URL = 'https://oai.endpoints.kepler.ai.cloud.ovh.net/v1';

    private const DEFAULT_MODEL = 'whisper-large-v3';

    public function __construct(
        public string $transcriptionUrl,
        public ?string $apiToken,
        public string $model,
        public ?string $language,
        public ?string $prompt,
        public float $temperature,
        public int $timeout,
        public int $maxRetries,
        public ?string $ffmpegPath,
        public string $mp3Bitrate,
        public string $sendmailPath,
        public ?string $fromAddress,
        public ?string $fromName,
        public ?string $envelopeSender,
        public string $subjectPrefix,
        public bool $attachAudio,
        public string $htmlTemplate,
        public string $textTemplate,
        public string $logIdent,
        public bool $debug,
    ) {}

    /**
     * @throws ConfigurationException
     */
    public static function fromFile(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new ConfigurationException(sprintf('Configuration file "%s" is not readable.', $path));
        }

        $config = require $path;

        if (!is_array($config)) {
            throw new ConfigurationException(sprintf('Configuration file "%s" must return an array.', $path));
        }

        return self::fromArray($config);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @throws ConfigurationException
     */
    public static function fromArray(array $config): self
    {
        $ovh = self::section($config, 'ovh');
        $audio = self::section($config, 'audio');
        $mail = self::section($config, 'mail');
        $log = self::section($config, 'log');
        $templates = dirname(__DIR__) . '/templates';

        $baseUrl = self::string($ovh['base_url'] ?? null) ?? self::DEFAULT_BASE_URL;

        return new self(
            transcriptionUrl: rtrim($baseUrl, '/') . '/audio/transcriptions',
            apiToken: self::string($ovh['token'] ?? null),
            model: self::string($ovh['model'] ?? null) ?? self::DEFAULT_MODEL,
            language: self::string($ovh['language'] ?? null),
            prompt: self::string($ovh['prompt'] ?? null),
            temperature: (float) ($ovh['temperature'] ?? 0.0),
            timeout: max(1, (int) ($ovh['timeout'] ?? 120)),
            maxRetries: max(0, (int) ($ovh['max_retries'] ?? 2)),
            ffmpegPath: self::string($audio['ffmpeg'] ?? null),
            mp3Bitrate: self::string($audio['mp3_bitrate'] ?? null) ?? '32k',
            sendmailPath: self::string($mail['sendmail'] ?? null) ?? self::DEFAULT_SENDMAIL,
            fromAddress: self::string($mail['from_address'] ?? null),
            fromName: self::string($mail['from_name'] ?? null),
            envelopeSender: self::string($mail['envelope_sender'] ?? null),
            subjectPrefix: (string) ($mail['subject_prefix'] ?? ''),
            attachAudio: (bool) ($mail['attach_audio'] ?? true),
            htmlTemplate: self::string($mail['html_template'] ?? null) ?? $templates . '/email.html.php',
            textTemplate: self::string($mail['text_template'] ?? null) ?? $templates . '/email.txt.php',
            logIdent: self::string($log['ident'] ?? null) ?? 'voicemail-ai',
            debug: (bool) ($log['debug'] ?? false),
        );
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     *
     * @throws ConfigurationException
     */
    private static function section(array $config, string $name): array
    {
        $section = $config[$name] ?? [];

        if (!is_array($section)) {
            throw new ConfigurationException(sprintf('Configuration section "%s" must be an array.', $name));
        }

        return $section;
    }

    private static function string(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
