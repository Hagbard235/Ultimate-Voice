<?php

/**
 * Ultimate Voice POC — IP-Symcon Device Module
 *
 * Flow:
 *   UVD_Speak($id, "doorbell")
 *     → POST {ServerURL}/v1/poc/generate  { character_id, event_type }
 *     ← { audio_url, text, from_cache, filename }
 *     → Download MP3 from audio_url → local cache
 *     → FTP upload → public URL
 *     → EchoRemote announce
 *
 * The module never touches OpenAI or ElevenLabs directly.
 * All AI/voice generation happens on the Ultimate Voice server.
 */
class UltimateVoiceDevice extends IPSModule
{
    public function Create(): void
    {
        parent::Create();

        // Server connection
        $this->RegisterPropertyString('ServerURL', 'http://localhost:8000');
        $this->RegisterPropertyString('APIKey', '');
        $this->RegisterPropertyString('CharacterID', 'butler_de');

        // Audio output
        $this->RegisterPropertyInteger('EchoRemoteID', 0);
        $this->RegisterPropertyString('FTPHost', '');
        $this->RegisterPropertyString('FTPUser', '');
        $this->RegisterPropertyString('FTPPassword', '');
        $this->RegisterPropertyString('FTPRemotePath', '/voice/');
        $this->RegisterPropertyString('WebserverBaseURL', '');

        // Test button helper
        $this->RegisterPropertyString('TestEventType', 'doorbell');

        // State variables
        $this->RegisterVariableString('LastSpokenText', 'Letzter Text', '', 0);
        $this->RegisterVariableString('LastAudioURL', 'Letzte Audio-URL', '', 0);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $serverURL = $this->ReadPropertyString('ServerURL');
        if (empty($serverURL)) {
            $this->SetStatus(104); // error: server URL missing
        } else {
            $this->SetStatus(102); // active
        }
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Main method: request audio from server, cache locally, play on Echo.
     *
     * @param string $EventType  One of: doorbell, battery_low, washer_done, etc.
     * @return int  IPS Media ID of the audio file, or 0 on error.
     */
    public function Speak(string $EventType): int
    {
        $serverURL   = rtrim($this->ReadPropertyString('ServerURL'), '/');
        $apiKey      = $this->ReadPropertyString('APIKey');
        $characterId = $this->ReadPropertyString('CharacterID');

        if (empty($serverURL)) {
            $this->LogMessage('UV: Server URL ist nicht konfiguriert.', KL_ERROR);
            return 0;
        }

        // --- Step 1: Check local cache ---
        $cacheDir  = $this->GetCacheDir();
        $localFile = $cacheDir . DIRECTORY_SEPARATOR . $EventType . '_variant_1.mp3';

        if (file_exists($localFile)) {
            $this->LogMessage("UV: Cache-Hit für '$EventType', kein Server-Aufruf nötig.", KL_MESSAGE);
            return $this->AnnounceLocalFile($localFile, $EventType);
        }

        // --- Step 2: Request from server ---
        $response = $this->RequestGenerate($serverURL, $apiKey, $characterId, $EventType);
        if ($response === false) {
            $this->LogMessage("UV: Server-Aufruf fehlgeschlagen für '$EventType'.", KL_ERROR);
            return 0;
        }

        $this->LogMessage("UV: Text vom Server: " . $response['text'], KL_MESSAGE);
        $this->SetValue('LastSpokenText', $response['text']);
        $this->SetValue('LastAudioURL', $response['audio_url']);

        // --- Step 3: Download audio file ---
        $audioData = $this->DownloadAudio($response['audio_url'], $apiKey);
        if ($audioData === false) {
            $this->LogMessage('UV: Download der Audio-Datei fehlgeschlagen.', KL_ERROR);
            return 0;
        }

        // --- Step 4: Save to local cache ---
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        file_put_contents($localFile, $audioData);
        $this->LogMessage("UV: Audio gespeichert: $localFile", KL_MESSAGE);

        // --- Step 5: Announce ---
        return $this->AnnounceLocalFile($localFile, $EventType);
    }

    /**
     * Test button handler.
     */
    public function TestSpeak(): string
    {
        $eventType = $this->ReadPropertyString('TestEventType');
        $mediaId   = $this->Speak($eventType);
        if ($mediaId > 0) {
            return "✅ Erfolg! Media-ID: $mediaId — Check your Echo device.";
        }
        return '❌ Fehler — Bitte Logs prüfen (IPS Log-Ansicht).';
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * POST /v1/poc/generate and return decoded JSON response, or false on error.
     */
    private function RequestGenerate(
        string $serverURL,
        string $apiKey,
        string $characterId,
        string $eventType
    ): array|false {
        $url     = "$serverURL/v1/poc/generate";
        $payload = json_encode([
            'character_id' => $characterId,
            'event_type'   => $eventType,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
        ]);

        $body  = curl_exec($ch);
        $error = curl_error($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error) {
            $this->LogMessage("UV: cURL-Fehler: $error", KL_ERROR);
            return false;
        }
        if ($code !== 200) {
            $this->LogMessage("UV: Server antwortete mit HTTP $code: " . substr($body, 0, 200), KL_ERROR);
            return false;
        }

        $data = json_decode($body, true);
        if (!isset($data['audio_url'])) {
            $this->LogMessage('UV: Unerwartetes Server-Antwort-Format.', KL_ERROR);
            return false;
        }

        return $data;
    }

    /**
     * Download audio file from the given URL. Returns binary content or false.
     */
    private function DownloadAudio(string $audioURL, string $apiKey): string|false
    {
        $ch = curl_init($audioURL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
            ],
        ]);

        $data  = curl_exec($ch);
        $error = curl_error($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error || $code !== 200) {
            $this->LogMessage("UV: Download fehlgeschlagen (HTTP $code): $error", KL_ERROR);
            return false;
        }

        return $data;
    }

    /**
     * Upload local file to FTP and announce on Echo via EchoRemote.
     * Returns IPS Media ID or 0 on error.
     */
    private function AnnounceLocalFile(string $localFile, string $eventType): int
    {
        $mediaId = $this->GetOrCreateMediaObject($localFile, $eventType);

        // FTP upload → public URL for Alexa
        $publicURL = $this->UploadToFTP($localFile, basename($localFile));
        if ($publicURL === false) {
            $this->LogMessage('UV: FTP-Upload fehlgeschlagen — kein Echo-Announce möglich.', KL_WARNING);
            return $mediaId;
        }

        $echoId = $this->ReadPropertyInteger('EchoRemoteID');
        if ($echoId > 0 && IPS_InstanceExists($echoId)) {
            $ssml = '<speak><audio src="' . $publicURL . '"/></speak>';
            EchoRemote_TextToSpeech($echoId, $ssml);
            $this->LogMessage("UV: Echo-Announce gesendet: $publicURL", KL_MESSAGE);
        } else {
            $this->LogMessage('UV: EchoRemote nicht konfiguriert — nur IPS Media-Objekt erstellt.', KL_WARNING);
        }

        return $mediaId;
    }

    /**
     * Find existing IPS Media object for this event type or create a new one.
     */
    private function GetOrCreateMediaObject(string $filePath, string $eventType): int
    {
        $name     = 'UV_' . $this->InstanceID . '_' . $eventType;
        $children = IPS_GetChildrenIDs($this->InstanceID);

        foreach ($children as $childId) {
            if (IPS_MediaExists($childId) && IPS_GetObject($childId)['ObjectName'] === $name) {
                IPS_SetMediaFile($childId, $filePath, false);
                return $childId;
            }
        }

        // Create new media object
        $mediaId = IPS_CreateMedia(1); // type 1 = audio
        IPS_SetParent($mediaId, $this->InstanceID);
        IPS_SetName($mediaId, $name);
        IPS_SetMediaFile($mediaId, $filePath, false);
        return $mediaId;
    }

    /**
     * Upload file to configured FTP server and return public URL, or false.
     */
    private function UploadToFTP(string $localFile, string $remoteFileName): string|false
    {
        $host       = $this->ReadPropertyString('FTPHost');
        $user       = $this->ReadPropertyString('FTPUser');
        $password   = $this->ReadPropertyString('FTPPassword');
        $remotePath = $this->ReadPropertyString('FTPRemotePath');
        $baseURL    = rtrim($this->ReadPropertyString('WebserverBaseURL'), '/');

        if (empty($host) || empty($user) || empty($baseURL)) {
            $this->LogMessage('UV: FTP nicht konfiguriert — überspringe Upload.', KL_WARNING);
            return false;
        }

        $conn = @ftp_connect($host);
        if (!$conn) {
            $this->LogMessage("UV: FTP-Verbindung zu $host fehlgeschlagen.", KL_ERROR);
            return false;
        }

        if (!@ftp_login($conn, $user, $password)) {
            $this->LogMessage('UV: FTP-Login fehlgeschlagen.', KL_ERROR);
            ftp_close($conn);
            return false;
        }

        ftp_pasv($conn, true);

        if (!empty($remotePath)) {
            @ftp_chdir($conn, $remotePath);
        }

        $result = ftp_put($conn, $remoteFileName, $localFile, FTP_BINARY);
        ftp_close($conn);

        if (!$result) {
            $this->LogMessage("UV: FTP-Upload von $remoteFileName fehlgeschlagen.", KL_ERROR);
            return false;
        }

        return $baseURL . '/' . $remoteFileName;
    }

    /**
     * Returns (and creates) the local cache directory for this instance.
     */
    private function GetCacheDir(): string
    {
        $dir = IPS_GetKernelDir() . 'media' . DIRECTORY_SEPARATOR
             . 'ultimate_voice_poc' . DIRECTORY_SEPARATOR
             . $this->ReadPropertyString('CharacterID');
        return $dir;
    }
}
