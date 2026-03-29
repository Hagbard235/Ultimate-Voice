<?php

/**
 * Ultimate Voice — IP-Symcon Device Module
 *
 * Delivery tiers:
 *
 *   FREE  (delivery_mode = 'webhook'):
 *     1. POST {ServerURL}/v1/poc/generate  → { audio_url, file_id, … }
 *     2. Download MP3 from audio_url → local IPS media cache
 *     3. Register IPS webhook (IPMagic URL) → Alexa fetches from there
 *        Webhook URL: https://{hash}.ipmagic.de/hook/uv_{InstanceID}?id={uuid}
 *     4. EchoRemote SSML <audio src="…"/>  with webhook URL
 *
 *   PREMIUM  (delivery_mode = 'direct'):
 *     1. POST {ServerURL}/v1/poc/generate  → { audio_url, … }
 *     2. EchoRemote SSML <audio src="…"/>  with audio_url directly
 *        (Alexa fetches from voice.smarthome-services.xyz)
 *        No local download, no FTP, no webhook needed.
 *
 * The module never touches OpenAI or ElevenLabs directly.
 * All AI/voice generation happens on the Ultimate Voice server.
 */
class UltimateVoiceDevice extends IPSModule
{
    // Lookup: local UUID → audio path
    private const INDEX_FILENAME = 'uv_index.json';

    public function Create(): void
    {
        parent::Create();

        // --- Server connection ---
        $this->RegisterPropertyString('ServerURL', 'http://localhost:8000');
        $this->RegisterPropertyString('APIKey', '');
        $this->RegisterPropertyString('CharacterID', 'butler_de');

        // --- Delivery mode ---
        // 'webhook'  → FREE:    download locally, serve via IPMagic webhook to Alexa
        // 'direct'   → PREMIUM: Alexa fetches directly from the Ultimate Voice server
        $this->RegisterPropertyString('DeliveryMode', 'webhook');

        // --- Audio output ---
        $this->RegisterPropertyInteger('EchoRemoteID', 0);

        // --- Test button helper ---
        $this->RegisterPropertyString('TestEventType', 'doorbell');

        // --- State variables ---
        $this->RegisterVariableString('LastSpokenText', 'Letzter Text', '', 0);
        $this->RegisterVariableString('LastAudioURL', 'Letzte Audio-URL', '', 0);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $serverURL = $this->ReadPropertyString('ServerURL');
        $mode      = $this->ReadPropertyString('DeliveryMode');

        if (empty($serverURL)) {
            $this->SetStatus(104); // error: server URL missing
            return;
        }

        // Register webhook for free-tier delivery
        if ($mode === 'webhook') {
            $this->RegisterHook('/hook/uv_' . $this->InstanceID);
        }

        $this->SetStatus(102); // active
    }

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Main method: request audio from server, cache locally (free) or play
     * directly (premium).
     *
     * Usage in IPS scripts:
     *   UVD_Speak($instanceId, 'doorbell');
     *   UVD_Speak($instanceId, 'battery_low');
     *
     * @param string $EventType  One of: doorbell, battery_low, washer_done,
     *                           window_open, motion_detected, welcome, goodbye,
     *                           temperature_alert, rain_alert, timer_done
     * @return bool  true on success
     */
    public function Speak(string $EventType): bool
    {
        $serverURL   = rtrim($this->ReadPropertyString('ServerURL'), '/');
        $apiKey      = $this->ReadPropertyString('APIKey');
        $characterId = $this->ReadPropertyString('CharacterID');
        $mode        = $this->ReadPropertyString('DeliveryMode');

        if (empty($serverURL)) {
            $this->LogMessage('UV: Server URL ist nicht konfiguriert.', KL_ERROR);
            return false;
        }

        // --- Step 1: Check local cache (webhook mode only) ---
        if ($mode === 'webhook') {
            $index  = $this->LoadLocalIndex();
            $cached = $index[$EventType] ?? null;
            if ($cached && file_exists($cached['path'])) {
                $this->LogMessage("UV: Lokaler Cache-Hit für '$EventType'.", KL_MESSAGE);
                return $this->AnnounceViaWebhook($cached['file_id']);
            }
        }

        // --- Step 2: Request from server ---
        $response = $this->RequestGenerate($serverURL, $apiKey, $characterId, $EventType);
        if ($response === false) {
            $this->LogMessage("UV: Server-Aufruf fehlgeschlagen für '$EventType'.", KL_ERROR);
            return false;
        }

        $this->LogMessage("UV: Text: " . $response['text'] . " | cached=" . ($response['from_cache'] ? 'ja' : 'nein'), KL_MESSAGE);
        $this->SetValue('LastSpokenText', $response['text']);
        $this->SetValue('LastAudioURL',   $response['audio_url']);

        // --- Step 3: Deliver ---
        if ($mode === 'direct') {
            // Premium: pass server URL directly to Alexa — no local download
            return $this->AnnounceViaDirect($response['audio_url']);
        }

        // Free tier: download → local cache → webhook
        $fileId   = $response['file_id'];
        $localDir = $this->GetCacheDir();
        $localFile = $localDir . DIRECTORY_SEPARATOR . $fileId . '.mp3';

        if (!file_exists($localFile) || !$response['from_cache']) {
            // Download (or re-download if server regenerated)
            $audioData = $this->DownloadAudio($response['audio_url'], $apiKey);
            if ($audioData === false) {
                $this->LogMessage('UV: Download der Audio-Datei fehlgeschlagen.', KL_ERROR);
                return false;
            }
            if (!is_dir($localDir)) {
                mkdir($localDir, 0755, true);
            }
            file_put_contents($localFile, $audioData);
            $this->LogMessage("UV: Audio gespeichert: $localFile", KL_MESSAGE);
        }

        // Update local index
        $index = $this->LoadLocalIndex();
        $index[$EventType] = ['file_id' => $fileId, 'path' => $localFile];
        $this->SaveLocalIndex($index);

        return $this->AnnounceViaWebhook($fileId);
    }

    /**
     * Test button handler — callable from IPS configuration UI.
     */
    public function TestSpeak(): string
    {
        $eventType = $this->ReadPropertyString('TestEventType');
        $ok        = $this->Speak($eventType);
        return $ok
            ? "✅ Erfolg! Echo-Announce gesendet."
            : '❌ Fehler — Bitte Logs prüfen (IPS Log-Ansicht).';
    }

    // =========================================================================
    // Webhook handler — called by IPS when Alexa GETs the webhook URL
    // =========================================================================

    /**
     * IPMagic webhook: Alexa calls
     *   https://{hash}.ipmagic.de/hook/uv_{InstanceID}?id={uuid}
     * and receives the local MP3 as audio/mpeg.
     */
    public function ProcessHookData(): void
    {
        $fileId = isset($_GET['id']) ? preg_replace('/[^a-f0-9\-]/', '', $_GET['id']) : '';
        if (empty($fileId)) {
            http_response_code(400);
            echo 'Missing id parameter';
            return;
        }

        $localFile = $this->GetCacheDir() . DIRECTORY_SEPARATOR . $fileId . '.mp3';
        if (!file_exists($localFile)) {
            http_response_code(404);
            echo 'Audio file not found';
            return;
        }

        header('Content-Type: audio/mpeg');
        header('Content-Length: ' . filesize($localFile));
        header('Cache-Control: public, max-age=86400');
        readfile($localFile);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

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
        if (!isset($data['audio_url'], $data['file_id'])) {
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
     * FREE TIER: Build webhook URL with UUID and send SSML to Echo.
     * Alexa will call the IPMagic webhook URL, which triggers ProcessHookData().
     */
    private function AnnounceViaWebhook(string $fileId): bool
    {
        // IPS_GetConnectUrl() returns the IPMagic base URL, e.g.:
        //   https://abcdef1234567890abcdef1234567890.ipmagic.de
        if (!function_exists('IPS_GetConnectUrl')) {
            $this->LogMessage('UV: IPS Connect nicht verfügbar — kein IPMagic-URL.', KL_ERROR);
            return false;
        }

        $connectBase = rtrim(IPS_GetConnectUrl(), '/');
        $hookName    = 'uv_' . $this->InstanceID;
        $webhookURL  = "$connectBase/hook/$hookName?id=$fileId";

        return $this->SendSSMLToEcho($webhookURL);
    }

    /**
     * PREMIUM TIER: Pass the server URL directly to Alexa.
     * No local download, no webhook — Alexa fetches from voice.smarthome-services.xyz.
     */
    private function AnnounceViaDirect(string $audioURL): bool
    {
        return $this->SendSSMLToEcho($audioURL);
    }

    /**
     * Send SSML <audio> tag to the configured EchoRemote instance.
     */
    private function SendSSMLToEcho(string $audioURL): bool
    {
        $echoId = $this->ReadPropertyInteger('EchoRemoteID');
        if ($echoId <= 0 || !IPS_InstanceExists($echoId)) {
            $this->LogMessage('UV: EchoRemote nicht konfiguriert — kein Announce möglich.', KL_WARNING);
            return false;
        }

        $ssml = '<speak><audio src="' . htmlspecialchars($audioURL, ENT_XML1) . '"/></speak>';
        EchoRemote_TextToSpeech($echoId, $ssml);
        $this->LogMessage("UV: Echo-Announce: $audioURL", KL_MESSAGE);
        return true;
    }

    /**
     * Load the local event_type → {file_id, path} index from disk.
     */
    private function LoadLocalIndex(): array
    {
        $path = $this->GetCacheDir() . DIRECTORY_SEPARATOR . self::INDEX_FILENAME;
        if (file_exists($path)) {
            $data = json_decode(file_get_contents($path), true);
            return is_array($data) ? $data : [];
        }
        return [];
    }

    /**
     * Persist the local index to disk.
     */
    private function SaveLocalIndex(array $index): void
    {
        $dir  = $this->GetCacheDir();
        $path = $dir . DIRECTORY_SEPARATOR . self::INDEX_FILENAME;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Returns the local cache directory for this instance's character.
     * e.g. /var/lib/symcon/media/ultimate_voice/butler_de/
     */
    private function GetCacheDir(): string
    {
        return IPS_GetKernelDir() . 'media' . DIRECTORY_SEPARATOR
             . 'ultimate_voice'   . DIRECTORY_SEPARATOR
             . $this->ReadPropertyString('CharacterID');
    }
}
