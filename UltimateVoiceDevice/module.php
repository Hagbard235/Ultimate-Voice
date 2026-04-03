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

        $this->RegisterPropertyString('ServerURL',     'https://voice.smarthome-services.xyz');
        $this->RegisterPropertyString('APIKey',        '');
        $this->RegisterPropertyString('CharacterID',   'butler_de');
        $this->RegisterPropertyString('DeliveryMode',  'userdir');
        $this->RegisterPropertyInteger('EchoRemoteID', 0);

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

        // userdir-Modus: Connect URL ausgeben als Info
        if ($mode === 'userdir') {
            $connectURL = $this->GetConnectURL();
            $userDir    = IPS_GetKernelDir() . 'user' . DIRECTORY_SEPARATOR;
            $this->SendDebug('UserDir', "Ablage-Pfad: $userDir", 0);
            if (!empty($connectURL)) {
                $this->SendDebug('UserDir', "Alexa-URL-Muster: {$connectURL}/user/uv_{uuid}.mp3", 0);
                $this->LogMessage("UV: UserDir-Modus aktiv. Alexa-URL: {$connectURL}/user/uv_{uuid}.mp3", KL_MESSAGE);
            }
        }

        // Webhook-Modus: RegisterHook versuchen (erfordert WebHook Control in IPS)
        if ($mode === 'webhook') {
            $hookPath = '/hook/uv_' . $this->InstanceID;
            try {
                $this->RegisterHook($hookPath);
                $this->SendDebug('Webhook', "Hook registriert: $hookPath", 0);
                $this->LogMessage("UV: Hook registriert: $hookPath", KL_MESSAGE);
                $connectURL = $this->GetConnectURL();
                if (!empty($connectURL)) {
                    $this->SendDebug('Webhook', "URL: $connectURL$hookPath?id={uuid}&char=$characterId", 0);
                }
            } catch (\Throwable $e) {
                $this->SendDebug('Webhook', 'RegisterHook fehlgeschlagen: ' . $e->getMessage(), 0);
                $this->LogMessage('UV: RegisterHook fehlgeschlagen: ' . $e->getMessage(), KL_WARNING);
            }
        }

        $this->SetStatus(102);
        $this->SendDebug('ApplyChanges', 'Status: aktiv (102)', 0);
    }

    // =========================================================================
    // Dynamisches Konfigurations-Formular
    // Lädt Charaktere und Event-Typen live vom Server
    // =========================================================================

    public function GetConfigurationForm(): string
    {
        $serverURL = rtrim($this->ReadPropertyString('ServerURL'), '/');
        $apiKey    = $this->ReadPropertyString('APIKey');

        // Fallback-Optionen falls Server nicht erreichbar
        $charOptions = [
            ['caption' => 'butler_de (Fallback — Server nicht erreichbar)', 'value' => 'butler_de'],
        ];
        $eventOptions = [
            ['caption' => 'Türklingel (doorbell)', 'value' => 'doorbell'],
            ['caption' => 'Batterie leer (battery_low)', 'value' => 'battery_low'],
            ['caption' => 'Bewegung (motion_detected)', 'value' => 'motion_detected'],
            ['caption' => 'Willkommen (welcome)', 'value' => 'welcome'],
        ];

        $catalogLoaded = false;
        $catalogError  = '';

        if (!empty($serverURL) && !empty($apiKey)) {
            $catalog = $this->FetchCatalog($serverURL, $apiKey);
            if ($catalog !== false) {
                // Charaktere
                if (!empty($catalog['characters'])) {
                    $charOptions = array_map(function ($c) {
                        $tier  = strtoupper($c['tier']);
                        $lang  = strtoupper($c['language']);
                        return ['caption' => "{$c['name']} ({$lang} · {$tier})", 'value' => $c['id']];
                    }, $catalog['characters']);
                }
                // Event-Typen
                if (!empty($catalog['event_types'])) {
                    $eventOptions = array_map(function ($e) {
                        return ['caption' => "{$e['label']} ({$e['id']})", 'value' => $e['id']];
                    }, $catalog['event_types']);
                }
                $catalogLoaded = true;
            } else {
                $catalogError = 'Server nicht erreichbar oder API Key ungültig — Fallback-Werte werden angezeigt.';
            }
        } else {
            $catalogError = 'Bitte Server URL und API Key eingeben, dann "Konfiguration neu laden" klicken.';
        }

        $statusLabel = $catalogLoaded
            ? '✅ Charaktere und Event-Typen erfolgreich vom Server geladen.'
            : '⚠️ ' . $catalogError;

        $form = [
            'elements' => [
                [
                    'type'     => 'ExpansionPanel',
                    'caption'  => 'Server-Verbindung',
                    'expanded' => true,
                    'items'    => [
                        [
                            'name'    => 'ServerURL',
                            'type'    => 'ValidationTextBox',
                            'caption' => 'Server URL',
                            'width'   => '400px',
                            'value'   => 'https://voice.smarthome-services.xyz',
                        ],
                        [
                            'name'    => 'APIKey',
                            'type'    => 'PasswordTextBox',
                            'caption' => 'API Key',
                            'width'   => '400px',
                        ],
                        [
                            'type'    => 'Label',
                            'caption' => $statusLabel,
                            'italic'  => true,
                        ],
                    ],
                ],
                [
                    'type'     => 'ExpansionPanel',
                    'caption'  => 'Charakter',
                    'expanded' => true,
                    'items'    => [
                        [
                            'name'    => 'CharacterID',
                            'type'    => 'Select',
                            'caption' => 'Charakter',
                            'width'   => '400px',
                            'options' => $charOptions,
                        ],
                    ],
                ],
                [
                    'type'     => 'ExpansionPanel',
                    'caption'  => 'Ausgabe (Echo / Alexa)',
                    'expanded' => true,
                    'items'    => [
                        [
                            'name'     => 'EchoRemoteID',
                            'type'     => 'SelectInstance',
                            'caption'  => 'EchoRemote Instanz',
                            'width'    => '400px',
                            'moduleID' => '{496AB8B5-396A-40E4-AF41-32F4AA6F5404}',
                        ],
                        [
                            'name'    => 'DeliveryMode',
                            'type'    => 'Select',
                            'caption' => 'Auslieferungs-Modus',
                            'width'   => '350px',
                            'value'   => 'userdir',
                            'options' => [
                                ['caption' => 'IPS /user/-Verzeichnis via Connect (Free)', 'value' => 'userdir'],
                                ['caption' => 'Webhook via IPS Connect (experimentell)',   'value' => 'webhook'],
                                ['caption' => 'Direkt vom Server (Premium)',               'value' => 'direct'],
                            ],
                        ],
                        [
                            'type'    => 'Label',
                            'caption' => 'UserDir: MP3 im /user/-Verzeichnis, Alexa holt via IPMagic-URL.',
                            'italic'  => true,
                        ],
                        [
                            'type'    => 'Label',
                            'caption' => 'Direkt: Alexa holt Audio direkt vom Ultimate Voice Server. Erfordert Premium-Abo.',
                            'italic'  => true,
                        ],
                    ],
                ],
            ],
            'actions' => [
                [
                    'type'     => 'ExpansionPanel',
                    'caption'  => 'Test',
                    'expanded' => true,
                    'items'    => [
                        [
                            'type'    => 'ValidationTextBox',
                            'name'    => 'TestRoom',
                            'caption' => 'Raum / Kontext (optional)',
                            'width'   => '250px',
                            'value'   => '',
                        ],
                        [
                            'type'    => 'Select',
                            'name'    => 'TestEventType',
                            'caption' => 'Test-Event',
                            'width'   => '350px',
                            'options' => $eventOptions,
                        ],
                        [
                            'type'    => 'Button',
                            'caption' => 'Sprechen (Cache)',
                            'onClick' => 'echo UVD_Speak($id, $TestEventType, $TestRoom);',
                        ],
                        [
                            'type'    => 'Button',
                            'caption' => 'Neu generieren (LLM-Test)',
                            'onClick' => 'echo UVD_ForceSpeak($id, $TestEventType, $TestRoom);',
                        ],
                        [
                            'type'    => 'Button',
                            'caption' => 'Webhook Status prüfen',
                            'onClick' => 'echo UVD_GetWebhookStatus($id);',
                        ],
                        [
                            'type'    => 'Button',
                            'caption' => 'Lokalen Cache leeren',
                            'onClick' => 'echo UVD_ClearCache($id);',
                        ],
                    ],
                ],
                [
                    'type'    => 'Button',
                    'caption' => '🔄 Konfiguration neu laden (Charaktere & Events vom Server)',
                    'onClick' => 'IPS_RequestAction($id, "ReloadForm", "");',
                ],
                [
                    'type'    => 'Label',
                    'caption' => 'Direkte Nutzung in IPS-Skripten: UVD_Speak($id, \'doorbell\');  oder mit Raum: UVD_Speak($id, \'motion_detected\', \'Garage\');',
                    'italic'  => true,
                ],
            ],
            'status' => [],
        ];

        return json_encode($form);
    }

    /**
     * Wird aufgerufen wenn der "Neu laden"-Button gedrückt wird.
     */
    public function RequestAction(string $ident, mixed $value): void
    {
        if ($ident === 'ReloadForm') {
            $this->ReloadForm();
        }
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

    /**
     * Spricht eine Phrase für das angegebene Event aus.
     * @param string $EventType  Event-ID aus dem Portal (z.B. doorbell, motion_detected, …)
     * @param string $Room       Optionaler Raum-/Kontext-String (z.B. "Wohnzimmer").
     *                           Wenn angegeben, werden raum-spezifische Phrasen bevorzugt.
     * Aufruf: UVD_Speak($id, 'doorbell');
     *         UVD_Speak($id, 'motion_detected', 'Garage');
     */
    public function Speak(string $EventType, string $Room = ''): bool
    {
        $serverURL   = rtrim($this->ReadPropertyString('ServerURL'), '/');
        $apiKey      = $this->ReadPropertyString('APIKey');
        $characterId = $this->ReadPropertyString('CharacterID');
        $mode        = $this->ReadPropertyString('DeliveryMode');

        $this->SendDebug('Speak', "EventType=$EventType | Room=$Room | CharacterID=$characterId | DeliveryMode=$mode", 0);
        $this->LogMessage("UV: Speak('$EventType'" . ($Room ? ", Raum='$Room'" : '') . ") — Modus=$mode, Charakter=$characterId", KL_MESSAGE);

        if (empty($serverURL)) {
            $this->SendDebug('Speak', 'FEHLER: Server URL nicht konfiguriert', 0);
            $this->LogMessage('UV: Server URL nicht konfiguriert.', KL_ERROR);
            return false;
        }

        // --- Step 1: Lokaler Cache-Check (webhook + userdir) ---
        $cacheKey = $Room ? "{$EventType}::{$Room}" : $EventType;
        if ($mode === 'webhook' || $mode === 'userdir') {
            $index  = $this->LoadLocalIndex();
            $cached = $index[$cacheKey] ?? $index[$EventType] ?? null;
            $this->SendDebug('LocalCache', "cacheKey=$cacheKey | entry=" . ($cached ? json_encode($cached) : 'nicht vorhanden'), 0);

            if ($cached) {
                $fileId      = $cached['file_id'];
                $correctPath = $this->GetLocalFilePath($mode, $fileId);

                if (file_exists($correctPath)) {
                    $this->SendDebug('LocalCache', "Hit! $correctPath", 0);
                    $this->LogMessage("UV: Lokaler Cache-Hit für '$EventType'", KL_MESSAGE);
                    $this->SetValue('LastSpokenText', $cached['text'] ?? '(gecacht)');
                    $this->SetValue('LastAudioURL',   $correctPath);
                    if ($mode === 'userdir') return $this->AnnounceViaUserDir($fileId);
                    return $this->AnnounceViaWebhook($fileId);
                }

                if (!empty($cached['path']) && file_exists($cached['path'])) {
                    $destDir = dirname($correctPath);
                    if (!is_dir($destDir)) mkdir($destDir, 0755, true);
                    copy($cached['path'], $correctPath);
                    $this->SendDebug('LocalCache', "Kopiert: {$cached['path']} → $correctPath", 0);
                    $this->LogMessage("UV: Cache-Datei in neuen Modus-Pfad kopiert", KL_MESSAGE);
                    $this->SetValue('LastSpokenText', $cached['text'] ?? '(gecacht)');
                    $this->SetValue('LastAudioURL',   $correctPath);
                    if ($mode === 'userdir') return $this->AnnounceViaUserDir($fileId);
                    return $this->AnnounceViaWebhook($fileId);
                }

                $this->SendDebug('LocalCache', "Miss — Datei nicht gefunden (file_id=$fileId)", 0);
            }
            $this->SendDebug('LocalCache', 'Miss — Server wird angefragt', 0);
        }

        // --- Step 2: Server anfragen ---
        $this->SendDebug('ServerRequest', "GET $serverURL/v1/characters/$characterId/play/$EventType room=$Room", 0);
        $response = $this->RequestGenerate($serverURL, $apiKey, $characterId, $EventType, false, $Room);

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

        $fileId    = $response['file_id'];
        $localFile = $this->GetLocalFilePath($mode, $fileId);
        $localDir  = dirname($localFile);

        $this->SendDebug('Deliver', "mode=$mode | file_id=$fileId | localFile=$localFile", 0);

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
            $this->SendDebug('Download', "Gespeichert: $localFile (" . strlen($audioData) . ' Bytes)', 0);
            $this->LogMessage("UV: Audio gespeichert — " . strlen($audioData) . " Bytes", KL_MESSAGE);
        } else {
            $this->SendDebug('Download', 'Übersprungen — Datei vorhanden', 0);
        }

        $index = $this->LoadLocalIndex();
        $index[$cacheKey] = [
            'file_id' => $fileId,
            'path'    => $localFile,
            'text'    => $response['text'] ?? '',
            'room'    => $Room ?: null,
        ];
        $this->SaveLocalIndex($index);
        $this->SendDebug('LocalCache', "Index aktualisiert: $cacheKey → $fileId", 0);

        if ($mode === 'userdir') {
            return $this->AnnounceViaUserDir($fileId);
        }
        return $this->AnnounceViaWebhook($fileId);
    }

    /**
     * Wie Speak(), erzwingt aber neue LLM-Generierung.
     * Aufruf: UVD_ForceSpeak($instanzId, 'doorbell');
     *         UVD_ForceSpeak($instanzId, 'motion_detected', 'Garten');
     */
    public function ForceSpeak(string $EventType, string $Room = ''): bool
    {
        $this->SendDebug('ForceSpeak', "Erzwinge Neu-Generierung für: $EventType | Room=$Room", 0);

        $cacheKey = $Room ? "{$EventType}::{$Room}" : $EventType;

        $index = $this->LoadLocalIndex();
        foreach ([$cacheKey, $EventType] as $key) {
            if (isset($index[$key])) {
                $fileId = $index[$key]['file_id'] ?? '';
                if ($fileId) {
                    $this->DeleteUserDirFile($fileId);
                    $cachePath = $this->GetCacheDir() . DIRECTORY_SEPARATOR . $fileId . '.mp3';
                    if (file_exists($cachePath)) unlink($cachePath);
                    $this->SendDebug('ForceSpeak', "Lokale Datei gelöscht: $fileId", 0);
                }
                unset($index[$key]);
            }
        }
        $this->SaveLocalIndex($index);

        $serverURL   = rtrim($this->ReadPropertyString('ServerURL'), '/');
        $apiKey      = $this->ReadPropertyString('APIKey');
        $characterId = $this->ReadPropertyString('CharacterID');
        $mode        = $this->ReadPropertyString('DeliveryMode');

        $response = $this->RequestGenerate($serverURL, $apiKey, $characterId, $EventType, true, $Room);

        if ($response === false) {
            $this->LogMessage("UV: ForceSpeak Server-Fehler für '$EventType'.", KL_ERROR);
            return false;
        }

        $this->LogMessage("UV: ForceSpeak OK — \"{$response['text']}\"", KL_MESSAGE);
        $this->SetValue('LastSpokenText', $response['text']);
        $this->SetValue('LastAudioURL',   $response['audio_url']);

        if ($mode === 'direct') {
            return $this->AnnounceViaDirect($response['audio_url']);
        }

        $fileId    = $response['file_id'];
        $localFile = $this->GetLocalFilePath($mode, $fileId);
        if (!is_dir(dirname($localFile))) mkdir(dirname($localFile), 0755, true);
        $audioData = $this->DownloadAudio($response['audio_url'], $apiKey);
        if ($audioData === false) return false;
        file_put_contents($localFile, $audioData);
        $this->SendDebug('ForceSpeak', "Gespeichert: $localFile (" . strlen($audioData) . ' Bytes)', 0);

        $index = $this->LoadLocalIndex();
        $index[$cacheKey] = ['file_id' => $fileId, 'path' => $localFile, 'text' => $response['text'], 'room' => $Room ?: null];
        $this->SaveLocalIndex($index);

        if ($mode === 'userdir') return $this->AnnounceViaUserDir($fileId);
        return $this->AnnounceViaWebhook($fileId);
    }

    public function GetWebhookStatus(): string
    {
        $hookPath = '/hook/uv_' . $this->InstanceID;
        $lines    = ["=== Webhook-Diagnose (Instanz #{$this->InstanceID}) ===", ''];

        if (function_exists('IPS_GetHookList')) {
            $hooks = IPS_GetHookList();
            $this->SendDebug('HookList', json_encode($hooks), 0);
            $found = false;
            foreach ($hooks as $hook) {
                if (($hook['Hook'] ?? '') === $hookPath) {
                    $found = true;
                    $lines[] = "✅ Hook registriert: $hookPath → Instanz #{$hook['TargetID']}";
                    break;
                }
            }
            if (!$found) {
                $lines[] = "❌ Hook NICHT in IPS_GetHookList(): $hookPath";
                $lines[] = '   Alle Hooks: ' . implode(', ', array_column($hooks, 'Hook'));
            }
        } else {
            $lines[] = '⚠️ IPS_GetHookList() nicht verfügbar';
        }

        $lines[] = '';
        $connectURL = $this->GetConnectURL();
        if (!empty($connectURL)) {
            $lines[] = "✅ Connect URL: $connectURL";
            $lines[] = "🔗 Vollständige Webhook-URL:";
            $lines[] = "   $connectURL$hookPath?id={uuid}&char={charId}";
        } else {
            $lines[] = '❌ Connect URL nicht verfügbar';
        }

        $lines[] = '';
        try {
            $this->RegisterHook($hookPath);
            $lines[] = '✅ RegisterHook() aufgerufen ohne Exception';
        } catch (\Throwable $e) {
            $lines[] = '❌ RegisterHook() Exception: ' . $e->getMessage();
        }

        $result = implode("\n", $lines);
        $this->SendDebug('WebhookStatus', $result, 0);
        return $result;
    }

    public function TestSpeak(string $EventType = ''): string
    {
        $eventType = $EventType ?: $this->ReadPropertyString('TestEventType');
        $mode      = $this->ReadPropertyString('DeliveryMode');
        $serverURL = $this->ReadPropertyString('ServerURL');
        $echoId    = $this->ReadPropertyInteger('EchoRemoteID');

        $this->SendDebug('TestSpeak', "event=$eventType | mode=$mode | server=$serverURL | echo=$echoId", 0);

        $warnings = [];
        if (empty($eventType))  $warnings[] = 'Kein Event-Typ ausgewählt';
        if (empty($serverURL))  $warnings[] = 'Server URL fehlt';
        if ($echoId <= 0)       $warnings[] = 'EchoRemote Instanz nicht gesetzt';
        if (($mode === 'webhook' || $mode === 'userdir') && empty($this->GetConnectURL())) {
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

    /**
     * Holt Charaktere und Event-Typen vom Server via API Key.
     * Gibt assoziatives Array oder false bei Fehler zurück.
     */
    private function FetchCatalog(string $serverURL, string $apiKey): array|false
    {
        $url = "$serverURL/v1/catalog";
        $this->SendDebug('Catalog', "GET $url", 0);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey],
        ]);

        $body  = curl_exec($ch);
        $error = curl_error($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error || $code !== 200) {
            $this->SendDebug('Catalog', "Fehler: HTTP $code | $error", 0);
            return false;
        }

        $data = json_decode($body, true);
        if (!isset($data['characters'], $data['event_types'])) {
            $this->SendDebug('Catalog', 'Unerwartetes Format: ' . substr($body, 0, 200), 0);
            return false;
        }

        $this->SendDebug('Catalog', sprintf(
            'Geladen: %d Charaktere, %d Event-Typen',
            count($data['characters']),
            count($data['event_types'])
        ), 0);

        return $data;
    }

    private function AnnounceViaUserDir(string $fileId): bool
    {
        $connectURL = $this->GetConnectURL();
        if (empty($connectURL)) {
            $this->SendDebug('Announce', 'FEHLER: Connect URL leer', 0);
            $this->LogMessage('UV: Connect URL nicht verfügbar — userdir-Modus benötigt IPS Connect.', KL_ERROR);
            return false;
        }

        $audioURL = $connectURL . '/user/uv_' . $fileId . '.mp3';
        $this->SendDebug('Announce', "UserDir-URL für Alexa: $audioURL", 0);
        return $this->SendSSMLToEcho($audioURL);
    }

    private function GetLocalFilePath(string $mode, string $fileId): string
    {
        if ($mode === 'userdir') {
            return IPS_GetKernelDir() . 'user' . DIRECTORY_SEPARATOR . 'uv_' . $fileId . '.mp3';
        }
        return $this->GetCacheDir() . DIRECTORY_SEPARATOR . $fileId . '.mp3';
    }

    private function AnnounceViaWebhook(string $fileId): bool
    {
        if (!IPS_ModuleExists('{9486D575-EE8C-40D7-9051-7E30E223C581}')) {
            $this->SendDebug('Announce', 'FEHLER: Connect Control Modul nicht gefunden', 0);
            $this->LogMessage('UV: Connect Control nicht verfügbar.', KL_ERROR);
            return false;
        }

        $connectBase = $this->GetConnectURL();
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
        string $characterId, string $eventType,
        bool $force = false,
        string $room = ''
    ): array|false {
        $params = [];
        if ($force) $params[] = 'force=true';
        if ($room !== '') $params[] = 'room=' . urlencode($room);
        $url = "$serverURL/v1/characters/$characterId/play/$eventType"
             . ($params ? '?' . implode('&', $params) : '');

        $this->SendDebug('HTTP', "GET $url", 0);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => [
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

    public function ClearCache(): string
    {
        $index   = $this->LoadLocalIndex();
        $deleted = 0;

        foreach ($index as $eventType => $entry) {
            $this->DeleteUserDirFile($entry['file_id']);
            $cachePath = $this->GetCacheDir() . DIRECTORY_SEPARATOR . $entry['file_id'] . '.mp3';
            if (file_exists($cachePath)) {
                unlink($cachePath);
            }
            $deleted++;
        }

        $this->SaveLocalIndex([]);
        $this->SendDebug('ClearCache', "Geleert — $deleted Einträge entfernt", 0);
        $this->LogMessage("UV: Lokaler Cache geleert ($deleted Dateien)", KL_MESSAGE);
        return "✅ Cache geleert — $deleted Dateien entfernt.";
    }

    private function DeleteUserDirFile(string $fileId): void
    {
        $path = IPS_GetKernelDir() . 'user' . DIRECTORY_SEPARATOR . 'uv_' . $fileId . '.mp3';
        if (file_exists($path)) {
            unlink($path);
            $this->SendDebug('DeleteFile', "Gelöscht: $path", 0);
        }
    }

    private function GetConnectURL(): string
    {
        $ids = IPS_GetInstanceListByModuleID('{9486D575-BE8C-4ED8-B5B5-20930E26DE6F}');
        if (count($ids) > 0) {
            $url = CC_GetURL($ids[0]);
            $this->SendDebug('Connect', "URL: $url (Instanz #{$ids[0]})", 0);
            return rtrim($url, '/');
        }
        $this->SendDebug('Connect', 'Keine Connect Control Instanz gefunden', 0);
        return '';
    }

    private function GetCacheDir(): string
    {
        return IPS_GetKernelDir() . 'media' . DIRECTORY_SEPARATOR
             . 'ultimate_voice'   . DIRECTORY_SEPARATOR
             . $this->ReadPropertyString('CharacterID');
    }
}
