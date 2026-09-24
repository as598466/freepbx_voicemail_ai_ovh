<?php

declare(strict_types=1);

namespace VoicemailAi\Transcription;

use VoicemailAi\Audio\AudioFile;

interface TranscriberInterface
{
    /**
     * @throws TranscriptionException
     */
    public function transcribe(AudioFile $audio): Transcript;
}
