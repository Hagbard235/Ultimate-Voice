<?php

/**
 * Ultimate Voice — IP-Symcon Device Module
 *
 * Delivery tiers:
 *
 *   FREE  (delivery_mode = 'webhook'):
 *     1. POST {ServerURL}/v1/poc/generate  → { audio_url, file_id, … }
 *     2. Download MP3 → local IPS media cache
 *     3. $this->RegisterHook() registriert den Hook → ProcessHookData() liefert MP3
 *        Alexa URL: https://{hash}.ipmagic.de/hook/uv_{instanceId}?id={uuid}&char={charId}
 *     4. EchoRemote SSML <audio src="…webhook-url…"/>
 *
 *   PREMIUM  (delivery_mode = 'direct'):
 *     1. POST {ServerURL}/v1/poc/generate  → { audio_url, … }
 *     2. EchoRemote SSML mit audio_url direkt (Alexa → unser Server)
 *
 * Debug: Rechtsklick auf Instanz → Debug
 */
class UltimateVoiceDevice extends IPSModule
{
    private const INDEX_FILENAME = 'uv_index.json';

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('ServerURL',     'http://localhost:8000');
        $this->RegisterPropertyString('APIKey',        '');
        $this->RegisterPropertyString('CharacterID',   'butler_de');
        $this->RegisterPropertyString('DeliveryMode',  'webhook');
        $this->RegisterPropertyInteger('EchoRemoteID', 0);
        $this->RegisterPropertyString('TestEventType', 'doorbell');

        $this->RegisterVariableString('LastSpokenText', 'Letzter Text', '', 0);
        $this->RegisterVariableString('LastAudioURL',   'Letzte Audio-URL', '', 0);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $serverURL   = $this->ReadPropertyString('ServerURL');
        $characterId = $this->ReadPropertyString('CharacterID');
        $mode        = $this->ReadPropertyString('DeliveryMode');
        $echoId      = $this->ReadPropertyInteger('EchoRemoteID');

        $this->SendDebug('ApplyChanges', "ServerURL=$serverURL | CharacterID=$characterId | DeliveryMode=$mode | EchoRemoteID=$echoId", 0);

        if (empty($serverURL)) {
            $this->LogMessage('UV: Server URL ist nicht konfiguriert.', KL_ERROR);
            $this->SetStatus(104);
            return;
        }

        // Webhook registrieren — ProcessHookData() liefert die MP3 an Alexa aus
        $hookPath = '/hook/uv_' . $this->InstanceID;
        try {
            $this->RegisterHook($hookPath);
            $this->SendDebug('Webhook', "Hook registriert: $hookPath", 0);
            $this->LogMessage("UV: Hook registriert: $hookPath", KL_MESSAGE);

            if (function_exists('IPS_GetConnectUrl')) {
                $connectURL = rtrim(IPS_GetConnectUrl(), '/');
                if (!empty($connectURL)) {
                    $exampleURL = "$connectURL$hookPath?id={uuid}&char=$characterId";
                    $this->SendDebug('Webhook', "Öffentliche URL: $exampleURL", 0);
                    $this->LogMessage("UV: Webhook-URL: $exampleURL", KL_MESSAGE);
                } else {
                    $this->SendDebug('Webhook', 'Connect URL leer — Connect aktiv?', 0);
                }
            }
        } catch (\Throwable $e) {
            $this->SendDebug('Webhook', 'RegisterHook fehlgeschlagen: ' . $e->getMessage(), 0);
            $this->LogMessage('UV: RegisterHook fehlgeschlagen — WebHook Control in IPS aktiv? ' . $e->getMessage(), KL_WARNING);
        }

        $this->SetStatus(102);
        $this->SendDebug('ApplyChanges', 'Status: aktiv (102)', 0);
    }

    // =========================================================================
    // Webhook — IPS ruft ProcessHookData() auf wenn die URL aufgerufen wird
    // =========================================================================

    protected function ProcessHookData(): void
    {
        $this->SendDebug('ProcessHookData', 'Eingehende Anfrage: ' . json_encode($_GET), 0);

        $fileId = isset($_GET['id'])   ? preg_replace('/[^a-f0-9\-]/', '', $_GET['id'])  : '';
        $charId = isset($_GET['char']) ? preg_replace('/[^a-z0-9_]/',  '', $_GET['char']) : $this->ReadPropertyString('CharacterID');

        if (empty($fileId)) {
            $this->SendDebug('ProcessHookData', 'FEHLER: id-Parameter fehlt', 0);
            http_response_code(400);
            echo 'Missing id parameter';
            return;
        }

        $localFile = IPS_GetKernelDir() . 'media' . DIRECTORY_SEPARATOR
                   . 'ultimate_voice'   . DIRECTORY_SEPARATOR
                   . $charId            . DIRECTORY_SEPARATOR
                   . $fileId . '.mp3';

        $this->SendDebug('ProcessHookData', "Suche: $localFile", 0);

        if (!file_exists($localFile)) {
            $this->SendDebug('ProcessHookData', 'FEHLER: Datei nicht gefunden', 0);
            $this->LogMessage("UV: Webhook: Datei nicht gefunden: $localFile", KL_WARNING);
            http_response_code(404);
            echo 'Audio file not found';
            return;
        }

        $size = filesize($localFile);
        $this->SendDebug('ProcessHookData', "Liefere $size Bytes als audio/mpeg", 0);
        $this->LogMessage("UV: Webhook liefert {$fileId}.mp3 ($size Bytes)", KL_MESSAGE);

        header('Content-Type: audio/mpeg');
        header('Content-Length: ' . $size);
        header('Cache-Control: public, max-age=86400');
        readfile($localFile);
    }

    // =========================================================================
    // Public API
    // =========================================================================

    public function Speak(string $EventType): bool
    {
        $serverURL   = rtrim($this->ReadPropertyString('ServerURL'), '/');
        $apiKey      = $this->ReadPropertyString('APIKey');
        $characterId = $this->ReadPropertyString('CharacterID');
        $mode        = $this->ReadPropertyString('DeliveryMode');

        $this->SendDebug('Speak', "EventType=$EventType | CharacterID=$characterId | DeliveryMode=$mode", 0);
        $this->LogMessage("UV: Speak('$EventType') — Modus=$mode, Charakter=$characterId", KL_MESSAGE);

        if (empty($serverURL)) {
            $this->SendDebug('Speak', 'FEHLER: Server URL nicht konfiguriert', 0);
            $this->LogMessage('UV: Server URL nicht konfiguriert.', KL_ERROR);
            return false;
        }

        // --- Step 1: Lokaler Cache-Check (nur webhook-Modus) ---
        if ($mode === 'webhook') {
            $index  = $this->LoadLocalIndex();
            $cached = $index[$EventType] ?? null;
            $this->SendDebug('LocalCache', "event=$EventType | entry=" . ($cached ? json_encode($cached) : 'nicht vorhanden'), 0);

            if ($cached && file_exists($cached['path'])) {
                $this->SendDebug('LocalCache', 'Hit! ' . $cached['path'], 0);
                $this->LogMessage("UV: Lokaler Cache-Hit für '$EventType'", KL_MESSAGE);
                return $this->AnnounceViaWebhook($cached['file_id']);
            }
            $this->SendDebug('LocalCache', 'Miss — Server wird angefragt', 0);
        }

        // --- Step 2: Server anfragen ---
        $this->SendDebug('ServerRequest', "POST $serverURL/v1/poc/generate | character=$characterId | event=$EventType", 0);
        $response = $this->RequestGenerate($serverURL, $apiKey, $characterId, $EventType);

        if ($response === false) {
            $this->SendDebug('ServerRequest', 'FEHLER: Kein Ergebnis', 0);
            $this->LogMessage("UV: Server-Aufruf fehlgeschlagen für '$EventType'.", KL_ERROR);
            return false;
        }

        $this->SendDebug('ServerRequest', 'Antwort: ' . json_encode($response), 0);
        $this->LogMessage("UV: Server OK — \"{$response['text']}\" | from_cache=" . ($response['from_cache'] ? 'ja' : 'nein'), KL_MESSAGE);

        $this->SetValue('LastSpokenText', $response['text']);
        $this->SetValue('LastAudioURL',   $response['audio_url']);

        // --- Step 3: Ausliefern ---
        if ($mode === 'direct') {
            $this->SendDebug('Deliver', 'direct → ' . $response['audio_url'], 0);
            return $this->AnnounceViaDirect($response['audio_url']);
        }

        // Webhook: herunterladen → lokal speichern → via Webhook an Alexa
        $fileId    = $response['file_id'];
        $localDir  = $this->GetCacheDir();
        $localFile = $localDir . DIRECTORY_SEPARATOR . $fileId . '.mp3';

        $this->SendDebug('Deliver', "webhook | file_id=$fileId | localFile=$localFile", 0);

        if (!file_exists($localFile) || !$response['from_cache']) {
            $this->SendDebug('Download', 'Starte Download: ' . $response['audio_url'], 0);
            $audioData = $this->DownloadAudio($response['audio_url'], $apiKey);

            if ($audioData === false) {
                $this->SendDebug('Download', 'FEHLER: Download fehlgeschlagen', 0);
                $this->LogMessage('UV: Download der Audio-Datei fehlgeschlagen.', KL_ERROR);
                return false;
            }

            if (!is_dir($localDir)) {
                mkdir($localDir, 0755, true);
            }
            file_put_contents($localFile, $audioData);
            $this->SendDebug('Download', 'Gespeichert: ' . $localFile . ' (' . strlen($audioData) . ' Bytes)', 0);
            $this->LogMessage("UV: Audio gespeichert — " . strlen($audioData) . " Bytes", KL_MESSAGE);
        } else {
            $this->SendDebug('Download', 'Übersprungen — Datei vorhanden', 0);
        }

        $index = $this->LoadLocalIndex();
        $index[$EventType] = ['file_id' => $fileId, 'path' => $localFile];
        $this->SaveLocalIndex($index);
        $this->SendDebug('LocalCache', "Index aktualisiert: $EventType → $fileId", 0);

        return $this->AnnounceViaWebhook($fileId);
    }

    public function TestSpeak(): string
    {
        $eventType = $this->ReadPropertyString('TestEventType');
        $mode      = $this->ReadPropertyString('DeliveryMode');
        $serverURL = $this->ReadPropertyString('ServerURL');
        $echoId    = $this->ReadPropertyInteger('EchoRemoteID');

        $this->SendDebug('TestSpeak', "event=$eventType | mode=$mode | server=$serverURL | echo=$echoId", 0);

        $warnings = [];
        if (empty($serverURL))  $warnings[] = 'Server URL fehlt';
        if ($echoId <= 0)       $warnings[] = 'EchoRemote Instanz nicht gesetzt';
        if ($mode === 'webhook' && function_exists('IPS_GetConnectUrl') && empty(IPS_GetConnectUrl())) {
            $warnings[] = 'IPS Connect URL ist leer';
        }

        if (!empty($warnings)) {
            $msg = '⚠️ Konfigurationsprobleme:' . "\n" . implode("\n", array_map(fn($w) => "  • $w", $warnings));
            $this->SendDebug('TestSpeak', $msg, 0);
            return $msg;
        }

        $ok = $this->Speak($eventType);

        if ($ok) {
            return "✅ Erfolg!\n\nText: " . $this->GetValue('LastSpokenText')
                 . "\nURL: " . $this->GetValue('LastAudioURL')
                 . "\n\nBitte Echo-Gerät beobachten.";
        }
        return "❌ Fehlgeschlagen\n→ IPS Log-Ansicht\n→ Rechtsklick Instanz → Debug";
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function AnnounceViaWebhook(string $fileId): bool
    {
        if (!function_exists('IPS_GetConnectUrl')) {
            $this->SendDebug('Announce', 'FEHLER: IPS_GetConnectUrl() nicht vorhanden', 0);
            $this->LogMessage('UV: IPS_GetConnectUrl() nicht verfügbar.', KL_ERROR);
            return false;
        }

        $connectBase = rtrim(IPS_GetConnectUrl(), '/');
        if (empty($connectBase)) {
            $this->SendDebug('Announce', 'FEHLER: Connect URL leer', 0);
            $this->LogMessage('UV: IPS Connect URL leer — Connect aktiv?', KL_ERROR);
            return false;
        }

        $characterId = $this->ReadPropertyString('CharacterID');
        $hookName    = 'uv_' . $this->InstanceID;
        $webhookURL  = "$connectBase/hook/$hookName?id=$fileId&char=$characterId";

        $this->SendDebug('Announce', "Webhook-URL für Alexa: $webhookURL", 0);
        return $this->SendSSMLToEcho($webhookURL);
    }

    private function AnnounceViaDirect(string $audioURL): bool
    {
        $this->SendDebug('Announce', "Direkt-URL für Alexa: $audioURL", 0);
        return $this->SendSSMLToEcho($audioURL);
    }

    private function SendSSMLToEcho(string $audioURL): bool
    {
        $echoId = $this->ReadPropertyInteger('EchoRemoteID');
        $this->SendDebug('EchoRemote', "EchoRemoteID=$echoId", 0);

        if ($echoId <= 0) {
            $this->SendDebug('EchoRemote', 'FEHLER: ID nicht gesetzt', 0);
            $this->LogMessage('UV: EchoRemote nicht konfiguriert.', KL_WARNING);
            return false;
        }
        if (!IPS_InstanceExists($echoId)) {
            $this->SendDebug('EchoRemote', "FEHLER: Instanz $echoId existiert nicht", 0);
            $this->LogMessage("UV: EchoRemote Instanz $echoId existiert nicht.", KL_ERROR);
            return false;
        }

        $ssml = '<speak><audio src="' . htmlspecialchars($audioURL, ENT_XML1) . '"/></speak>';
        $this->SendDebug('EchoRemote', "Sende SSML: $ssml", 0);

        EchoRemote_TextToSpeech($echoId, $ssml);
        $this->LogMessage("UV: Echo-Announce: $audioURL", KL_MESSAGE);
        return true;
    }

    private function RequestGenerate(
        string $serverURL, string $apiKey,
        string $characterId, string $eventType
    ): array|false {
        $url     = "$serverURL/v1/poc/generate";
        $payload = json_encode(['character_id' => $characterId, 'event_type' => $eventType]);

        $this->SendDebug('HTTP', "POST $url", 0);

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

        $this->SendDebug('HTTP', "HTTP $code | " . substr($body ?: '', 0, 300), 0);

        if ($error) {
            $this->LogMessage("UV: cURL-Fehler: $error", KL_ERROR);
            return false;
        }
        if ($code !== 200) {
            $this->LogMessage("UV: Server HTTP $code: " . substr($body, 0, 200), KL_ERROR);
            return false;
        }

        $data = json_decode($body, true);
        if (!isset($data['audio_url'], $data['file_id'])) {
            $this->LogMessage('UV: Unerwartetes Server-Antwort-Format.', KL_ERROR);
            return false;
        }

        return $data;
    }

    private function DownloadAudio(string $audioURL, string $apiKey): string|false
    {
        $this->SendDebug('HTTP', "GET $audioURL", 0);

        $ch = curl_init($audioURL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey],
        ]);

        $data  = curl_exec($ch);
        $error = curl_error($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->SendDebug('HTTP', "HTTP $code | " . ($error ?: strlen($data) . ' Bytes'), 0);

        if ($error || $code !== 200) {
            $this->LogMessage("UV: Download fehlgeschlagen (HTTP $code): $error", KL_ERROR);
            return false;
        }

        return $data;
    }

    private function LoadLocalIndex(): array
    {
        $path = $this->GetCacheDir() . DIRECTORY_SEPARATOR . self::INDEX_FILENAME;
        $this->SendDebug('Index', "Lade: $path | exists=" . (file_exists($path) ? 'ja' : 'nein'), 0);
        if (file_exists($path)) {
            $data = json_decode(file_get_contents($path), true);
            return is_array($data) ? $data : [];
        }
        return [];
    }

    private function SaveLocalIndex(array $index): void
    {
        $dir = $this->GetCacheDir();
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents(
            $dir . DIRECTORY_SEPARATOR . self::INDEX_FILENAME,
            json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    private function GetCacheDir(): string
    {
        return IPS_GetKernelDir() . 'media' . DIRECTORY_SEPARATOR
             . 'ultimate_voice'   . DIRECTORY_SEPARATOR
             . $this->ReadPropertyString('CharacterID');
    }
}
