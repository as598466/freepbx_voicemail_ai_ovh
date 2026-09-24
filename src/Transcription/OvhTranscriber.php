<?php

declare(strict_types=1);

namespace VoicemailAi\Transcription;

use CURLStringFile;
use JsonException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use VoicemailAi\Audio\AudioFile;

/**
 * Speech-to-text with OVHcloud AI Endpoints (OpenAI compatible /audio/transcriptions API).
 *
 * @see https://docs.ovhcloud.com/en/guides/public-cloud/ai-machine-learning/ai-endpoints-audio-models
 */
final readonly class OvhTranscriber implements TranscriberInterface
{
    private const RETRYABLE_STATUS_CODES = [408, 425, 429, 500, 502, 503, 504];

    private const USER_AGENT = 'freepbx-voicemail-ai/1.0';

    public function __construct(
        private string $endpointUrl,
        private ?string $apiToken,
        private string $model,
        private ?string $language = null,
        private ?string $prompt = null,
        private float $temperature = 0.0,
        private int $timeout = 120,
        private int $maxRetries = 2,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public function transcribe(AudioFile $audio): Transcript
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                return $this->request($audio);
            } catch (TranscriptionException $exception) {
                if (!$exception->retryable || $attempt >= $this->maxRetries) {
                    throw $exception;
                }

                $delay = 2 ** $attempt;

                $this->logger->warning('Transcription attempt {attempt} failed, retrying in {delay}s: {exception}', [
                    'attempt' => $attempt + 1,
                    'delay' => $delay,
                    'exception' => $exception,
                ]);

                sleep($delay);
            }
        }
    }

    /**
     * @throws TranscriptionException
     */
    private function request(AudioFile $audio): Transcript
    {
        $fields = [
            'file' => new CURLStringFile($audio->content, $audio->filename, $audio->mimeType),
            'model' => $this->model,
            'response_format' => 'verbose_json',
            'temperature' => (string) $this->temperature,
        ];

        if ($this->language !== null) {
            $fields['language'] = $this->language;
        }

        if ($this->prompt !== null) {
            $fields['prompt'] = $this->prompt;
        }

        $headers = ['Accept: application/json'];

        if ($this->apiToken !== null) {
            $headers[] = 'Authorization: Bearer ' . $this->apiToken;
        }

        $handle = curl_init($this->endpointUrl);

        if ($handle === false) {
            throw new TranscriptionException('Unable to initialize cURL.');
        }

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $fields,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_USERAGENT => self::USER_AGENT,
        ]);

        $this->logger->debug('POST {url} ({size} bytes, model {model}).', [
            'url' => $this->endpointUrl,
            'size' => $audio->size(),
            'model' => $this->model,
        ]);

        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        if (!is_string($response)) {
            throw new TranscriptionException(
                sprintf('HTTP request failed: %s', curl_error($handle)),
                retryable: true,
            );
        }

        if ($status < 200 || $status >= 300) {
            throw new TranscriptionException(
                sprintf('OVHcloud AI Endpoints returned HTTP %d: %s', $status, $this->excerpt($response)),
                retryable: in_array($status, self::RETRYABLE_STATUS_CODES, true),
            );
        }

        return $this->parseResponse($response);
    }

    /**
     * @throws TranscriptionException
     */
    private function parseResponse(string $response): Transcript
    {
        try {
            $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new TranscriptionException(
                sprintf('Invalid JSON response: %s', $this->excerpt($response)),
                previous: $exception,
            );
        }

        if (!is_array($data) || !isset($data['text']) || !is_string($data['text'])) {
            throw new TranscriptionException(
                sprintf('Unexpected response: %s', $this->excerpt($response)),
            );
        }

        $language = $data['language'] ?? null;
        $duration = $data['duration'] ?? null;

        return new Transcript(
            text: trim($data['text']),
            language: is_string($language) ? $language : null,
            duration: is_numeric($duration) ? (float) $duration : null,
        );
    }

    private function excerpt(string $response): string
    {
        $response = trim(preg_replace('/\s+/', ' ', $response) ?? $response);

        return mb_strimwidth($response, 0, 300, '...');
    }
}
