# Ultimate Voice — IP-Symcon Modul

Verwandelt dein Smart Home in eine lebendige Umgebung mit Persönlichkeit. Statt monotoner Roboter-Ansagen spricht ein Charakter — ein englischer Butler, ein frecher Frosch oder saisonale Gäste wie der Weihnachtsmann.

Dieses Modul verbindet IP-Symcon mit dem **Ultimate Voice Service** und übernimmt alles: Event erfassen, Audio abrufen, lokal zwischenspeichern und auf deinem Echo-Gerät abspielen.

---

## Voraussetzungen

- IP-Symcon ab Version 6.0
- Einen aktiven **Ultimate Voice Account** (Service-URL + API-Key)
- EchoRemote-Modul (für Echo/Alexa-Ausgabe) oder anderen Media Player

---

## Installation

### Über das IP-Symcon Modul-Control (empfohlen)

1. IP-Symcon Verwaltungskonsole öffnen
2. **Kern-Instanzen → Modules → Hinzufügen**
3. URL einfügen:
   ```
   https://github.com/Hagbard235/Ultimate-Voice
   ```
4. Modul wird automatisch installiert

### Manuell

Ordner `UltimateVoiceDevice` in das IP-Symcon Modulverzeichnis kopieren und Module neu einlesen.

---

## Einrichtung

1. **Neue Instanz erstellen:** Instanzen → Hinzufügen → "Ultimate Voice Device"
2. **Konfigurieren:**

| Feld | Beschreibung |
|---|---|
| Server URL | URL deines Ultimate Voice Servers |
| API Key | Dein persönlicher API-Key (aus dem Ultimate Voice Dashboard) |
| Charakter ID | z.B. `butler_de` — aus deinem Account |
| EchoRemote Instanz | Dein Echo-Gerät in IP-Symcon |
| FTP-Einstellungen | Für die Bereitstellung der Audio-Datei an Alexa |

---

## Verwendung

### Per Automatisierung (Ereignis-Aktion)

```php
// Klingel-Ansage
UVD_Speak(12345 /*InstanzID*/, "doorbell");

// Waschmaschine fertig
UVD_Speak(12345, "washer_done");

// Mit Raum (für raumspezifische Ansagen)
UVD_Speak(12345, "battery_low");
```

### Verfügbare Events

| Event | Bedeutung |
|---|---|
| `doorbell` | Klingel |
| `battery_low` | Batterie leer |
| `washer_done` | Waschmaschine fertig |
| `window_open` | Fenster offen |
| `motion_detected` | Bewegung erkannt |
| `welcome` | Jemand kommt nach Hause |
| `goodbye` | Jemand verlässt das Haus |
| `temperature_alert` | Temperaturwarnung |
| `rain_alert` | Regenwarnung |
| `timer_done` | Timer abgelaufen |

---

## Wie es funktioniert

Das Modul speichert Audio-Dateien **lokal** auf deinem IP-Symcon-Server zwischen. Das bedeutet:

- **Schnell:** Bekannte Events werden sofort aus dem lokalen Cache abgespielt — kein Warten auf den Server
- **Zuverlässig:** Auch ohne Internetverbindung funktionieren bereits gespielte Events weiterhin
- **Sparsam:** Der Ultimate Voice Server wird nur beim ersten Abruf oder bei Updates kontaktiert

Die gesamte KI-Verarbeitung (Texterzeugung + Sprachsynthese) findet ausschließlich auf dem Ultimate Voice Server statt — das Modul enthält keine API-Keys für OpenAI oder ElevenLabs.

---

## Lizenz

Dieses Modul ist kostenlos. Für die Nutzung wird ein aktiver **Ultimate Voice Account** benötigt.

---

## Support & Feedback

Probleme oder Fragen? [Issue erstellen](https://github.com/Hagbard235/Ultimate-Voice/issues)
