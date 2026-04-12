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
                            'onClick' => 'UVD_Speak($id, (string)($TestEventType ?? \'\'), (string)($TestRoom ?? \'\'));',
                        ],
                        [
                            'type'    => 'Button',
                            'caption' => 'Neu generieren (LLM-Test)',
                            'onClick' => 'UVD_ForceSpeak($id, (string)($TestEventType ?? \'\'), (string)($TestRoom ?? \'\'));',
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
                    'type'    => 'Label',
                    'caption' => 'Direkte Nutzung in IPS-Skripten: UVD_Speak($id, \'doorbell\');  oder mit Raum: UVD_Speak($id, \'motion_detected\', \'Garage\');',
                    'italic'  => true,
                ],
            ],
            'status' => [],
        ];

        return json_encode($form);
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
     * Spricht eine Phrase für das angegebene Event aus (konfiguriertes Echo-Gerät).
     *
     * @param string $EventType  Event-ID aus dem Portal (z.B. doorbell, motion_detected, …)
     * @param string $Room       Optionaler Raum-/Kontext-String (z.B. "Wohnzimmer").
     *
     * Beispiele:
     *   UVD_Speak($id, 'doorbell');
     *   UVD_Speak($id, 'doorbell', 'Garage');
     *
     * Für eigene Echo-Auswahl → UVD_SpeakMulti($id, 'doorbell', '', [12345]);
     */
    public function Speak(string $EventType = '', string $Room = ''): bool
    {
        return $this->SpeakInternal($EventType, $Room, false, null);
    }

    /**
     * Wie Speak(), erzwingt aber neue LLM-Generierung (konfiguriertes Echo-Gerät).
     * Aufruf: UVD_ForceSpeak($instanzId, 'doorbell');
     *         UVD_ForceSpeak($instanzId, 'motion_detected', 'Garten');
     *
     * Für eigene Echo-Auswahl → UVD_ForceSpeakMulti($id, 'doorbell', '', [12345]);
     */
    public function ForceSpeak(string $EventType = '', string $Room = ''): bool
    {
        return $this->SpeakInternal($EventType, $Room, true, null);
    }

    /**
     * Spricht eine Phrase gleichzeitig auf mehreren Echo-Geräten.
     * Alle Geräte im Array spielen simultan — kein Delay zwischen den Dots.
     *
     * @param string     $EventType  Event-ID (z.B. 'doorbell', 'motion_detected')
     * @param string     $Room       Optionaler Raum-Kontext
     * @param int|array  $EchoIDs    Array mit EchoRemote-Instanz-IDs, z.B. [34274, 45302, 59264]
     *
     * Aufruf: UVD_SpeakMulti($id, 'doorbell', '', [34274, 45302, 59264, 57384]);
     *         UVD_SpeakMulti($id, 'motion_detected', 'EG', [34274, 45302]);
     */
    public function SpeakMulti(string $EventType, string $Room, $EchoIDs): bool
    {
        if (!is_array($EchoIDs)) {
            $EchoIDs = $EchoIDs ? [(int)$EchoIDs] : [];
        }
        return $this->SpeakInternal($EventType, $Room, false, $EchoIDs);
    }

    /**
     * Wie SpeakMulti(), erzwingt aber neue LLM-Generierung.
     * Aufruf: UVD_ForceSpeakMulti($id, 'doorbell', '', [34274, 45302]);
     */
    public function ForceSpeakMulti(string $EventType, string $Room, $EchoIDs): bool
    {
        if (!is_array($EchoIDs)) {
            $EchoIDs = $EchoIDs ? [(int)$EchoIDs] : [];
        }
        return $this->SpeakInternal($EventType, $Room, true, $EchoIDs);
    }

    /**
     * Zentrale Implementierung für alle Speak-Varianten.
     *
     * Der Server wird bei JEDEM Aufruf kontaktiert — er wählt zufällig eine der
     * vorhandenen Phrasen aus, sodass die Rotation funktioniert.
     * Die MP3-Dateien werden lokal gecacht (nach file_id). Wenn der Server eine
     * file_id zurückgibt, die lokal bereits vorhanden ist, wird KEIN Download
     * ausgelöst — die lokale Datei wird direkt gespielt.
     *
     * Warum kein lokaler "Event → file_id"-Index mehr?
     * Weil ein solcher Index immer dieselbe Datei liefern würde und die
     * server-seitige Zufallsauswahl unterläuft.
     */
    private function SpeakInternal(string $EventType, string $Room, bool $force, $EchoIDs): bool
    {
        $serverURL   = rtrim($this->ReadPropertyString('ServerURL'), '/');
        $apiKey      = $this->ReadPropertyString('APIKey');
        $characterId = $this->ReadPropertyString('CharacterID');
        $mode        = $this->ReadPropertyString('DeliveryMode');

        $echoLabel = is_array($EchoIDs) ? count($EchoIDs) . ' Geräte' : 'konfiguriert';
        $tag = $force ? 'ForceSpeak' : 'Speak';
        $this->SendDebug($tag, "EventType=$EventType | Room=$Room | CharacterID=$characterId | DeliveryMode=$mode | Echo=$echoLabel | force=" . ($force ? 'ja' : 'nein'), 0);
        $this->LogMessage("UV: {$tag}('$EventType'" . ($Room ? ", Raum='$Room'" : '') . ") — Modus=$mode, Charakter=$characterId", KL_MESSAGE);

        if (empty($serverURL)) {
            $this->SendDebug($tag, 'FEHLER: Server URL nicht konfiguriert', 0);
            $this->LogMessage('UV: Server URL nicht konfiguriert.', KL_ERROR);
            return false;
        }

        // --- Server anfragen (immer — er wählt zufällig aus den vorhandenen Phrasen) ---
        $this->SendDebug($tag, "GET $serverURL/v1/characters/$characterId/play/$EventType room=$Room force=$force", 0);
        $response = $this->RequestGenerate($serverURL, $apiKey, $characterId, $EventType, $force, $Room);

        if ($response === false) {
            $this->SendDebug($tag, 'FEHLER: Kein Ergebnis vom Server', 0);
            $this->LogMessage("UV: Server-Aufruf fehlgeschlagen für '$EventType'.", KL_ERROR);
            return false;
        }

        $this->SendDebug($tag, 'Antwort: ' . json_encode($response), 0);
        $this->LogMessage("UV: Server OK — \"{$response['text']}\" | from_cache=" . ($response['from_cache'] ? 'ja' : 'nein'), KL_MESSAGE);

        $this->SetValue('LastSpokenText', $response['text']);
        $this->SetValue('LastAudioURL',   $response['audio_url']);

        // --- direct-Modus: Audio-URL direkt an Alexa ---
        if ($mode === 'direct') {
            $this->SendDebug($tag, 'direct → ' . $response['audio_url'], 0);
            return $this->AnnounceViaDirect($response['audio_url'], $EchoIDs);
        }

        // --- webhook / userdir: MP3 lokal cachen (nach file_id), dann abspielen ---
        $fileId    = $response['file_id'];
        $localFile = $this->GetLocalFilePath($mode, $fileId);

        if (!file_exists($localFile)) {
            // Datei noch nicht lokal vorhanden → herunterladen
            $this->SendDebug($tag, "Download (neu): $fileId", 0);
            $audioData = $this->DownloadAudio($response['audio_url'], $apiKey);
            if ($audioData === false) {
                $this->SendDebug($tag, 'FEHLER: Download fehlgeschlagen', 0);
                $this->LogMessage('UV: Download der Audio-Datei fehlgeschlagen.', KL_ERROR);
                return false;
            }
            $localDir = dirname($localFile);
            if (!is_dir($localDir)) {
                mkdir($localDir, 0755, true);
            }
            file_put_contents($localFile, $audioData);
            $this->SendDebug($tag, "Gespeichert: $localFile (" . strlen($audioData) . ' Bytes)', 0);
            $this->LogMessage("UV: Audio gespeichert — " . strlen($audioData) . " Bytes ($fileId)", KL_MESSAGE);
        } else {
            // Datei bereits vorhanden → kein Download nötig
            $this->SendDebug($tag, "Lokaler Hit: $localFile (kein Download)", 0);
        }

        if ($mode === 'userdir') {
            return $this->AnnounceViaUserDir($fileId, $EchoIDs);
        }
        return $this->AnnounceViaWebhook($fileId, $EchoIDs);
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
        $eventType = $EventType;
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

    private function AnnounceViaUserDir(string $fileId, $echoIDs = null): bool
    {
        $connectURL = $this->GetConnectURL();
        if (empty($connectURL)) {
            $this->SendDebug('Announce', 'FEHLER: Connect URL leer', 0);
            $this->LogMessage('UV: Connect URL nicht verfügbar — userdir-Modus benötigt IPS Connect.', KL_ERROR);
            return false;
        }

        $audioURL = $connectURL . '/user/uv_' . $fileId . '.mp3';
        $this->SendDebug('Announce', "UserDir-URL für Alexa: $audioURL", 0);
        return $this->SendSSMLToEcho($audioURL, $echoIDs);
    }

    private function GetLocalFilePath(string $mode, string $fileId): string
    {
        if ($mode === 'userdir') {
            return IPS_GetKernelDir() . 'user' . DIRECTORY_SEPARATOR . 'uv_' . $fileId . '.mp3';
        }
        return $this->GetCacheDir() . DIRECTORY_SEPARATOR . $fileId . '.mp3';
    }

    private function AnnounceViaWebhook(string $fileId, $echoIDs = null): bool
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
        return $this->SendSSMLToEcho($webhookURL, $echoIDs);
    }

    private function AnnounceViaDirect(string $audioURL, $echoIDs = null): bool
    {
        $this->SendDebug('Announce', "Direkt-URL für Alexa: $audioURL", 0);
        return $this->SendSSMLToEcho($audioURL, $echoIDs);
    }

    /**
     * Sendet SSML an ein oder mehrere Echo-Geräte.
     *
     * Einzelnes Gerät  → EchoRemote_TextToSpeech()        (wie bisher)
     * Mehrere Geräte   → ECHOREMOTE_TextToSpeechEx()      (simultan, nicht nacheinander!)
     *
     * @param string         $audioURL  MP3-URL für Alexa
     * @param int|array|null $echoIDs   null  → konfigurierte Instanz
     *                                  int   → einzelne Override-ID
     *                                  array → mehrere IDs gleichzeitig (synchron)
     */
    private function SendSSMLToEcho(string $audioURL, $echoIDs = null): bool
    {
        // IDs auflösen: null → konfiguriert, int → single, array → multi
        if ($echoIDs === null) {
            $ids = [$this->ReadPropertyInteger('EchoRemoteID')];
        } elseif (is_array($echoIDs)) {
            $ids = array_values(array_filter(array_map('intval', $echoIDs), fn($i) => $i > 0));
        } else {
            $ids = [(int)$echoIDs];
        }

        // Ungültige IDs herausfiltern
        $validIds = array_values(array_filter($ids, fn($i) => $i > 0 && IPS_InstanceExists($i)));

        if (empty($validIds)) {
            $this->LogMessage('UV: Kein gültiges Echo-Gerät gefunden.', KL_WARNING);
            $this->SendDebug('EchoRemote', 'FEHLER: Keine gültigen IDs in ' . json_encode($ids), 0);
            return false;
        }

        $ssml = '<speak><audio src="' . htmlspecialchars($audioURL, ENT_XML1) . '"/></speak>';

        if (count($validIds) === 1) {
            // Einzelgerät — klassischer Aufruf
            $echoId = $validIds[0];
            $this->SendDebug('EchoRemote', "Sende an ID=$echoId: $ssml", 0);
            EchoRemote_TextToSpeech($echoId, $ssml);
            $this->LogMessage("UV: Echo-Announce ID=$echoId: $audioURL", KL_MESSAGE);
        } else {
            // Mehrere Geräte — ECHOREMOTE_TextToSpeechEx spielt SIMULTAN auf allen
            $this->SendDebug('EchoRemote', 'Simultan an IDs=' . json_encode($validIds) . ': ' . $ssml, 0);
            ECHOREMOTE_TextToSpeechEx($validIds[0], $ssml, $validIds, []);
            $this->LogMessage('UV: Echo-Announce simultan an ' . count($validIds) . ' Geräten: ' . $audioURL, KL_MESSAGE);
        }

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
        $deleted  = 0;
        $cacheDir = $this->GetCacheDir();
        $userDir  = IPS_GetKernelDir() . 'user' . DIRECTORY_SEPARATOR;

        // Alle lokal gecachten MP3s löschen (sowohl media/ als auch user/)
        if (is_dir($cacheDir)) {
            foreach (glob($cacheDir . DIRECTORY_SEPARATOR . '*.mp3') ?: [] as $file) {
                unlink($file);
                $deleted++;
            }
        }
        // user/-Verzeichnis: alle uv_*.mp3 löschen
        foreach (glob($userDir . 'uv_*.mp3') ?: [] as $file) {
            unlink($file);
        }

        $this->SendDebug('ClearCache', "Geleert — $deleted Dateien gelöscht", 0);
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
