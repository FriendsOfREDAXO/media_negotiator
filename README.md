# Media Negotiator

REDAXO-Addon, das dem Media Manager einen Effekt für **HTTP Content Negotiation** hinzufügt. Der Browser teilt dem Server über den `Accept`-Header mit, welche Bildformate er unterstützt – Media Negotiator liefert daraufhin automatisch das optimale Format aus.

Jetzt mit **libvips**-Support: Wenn die PHP-Extension `vips` installiert ist, wird sie automatisch bevorzugt eingesetzt und ist der deutlich schnellere und speicherfreundlichere Weg zu AVIF und WebP auf dem Server.

---

## Inhaltsverzeichnis

- [Funktionsweise](#funktionsweise)
- [libvips – der schnelle Weg](#libvips--der-schnelle-weg)
- [ICC-Fix und sRGB-Workflow](#icc-fix-und-srgb-workflow)
- [Unterstützte Formate](#unterstützte-formate)
- [Voraussetzungen](#voraussetzungen)
- [Installation](#installation)
- [Einrichtung](#einrichtung)
- [Einstellungen](#einstellungen)
- [User-Agent-Fallback](#user-agent-fallback)
- [Setup-Seite](#setup-seite)
- [ICC-Fix-Seite](#icc-fix-seite)
- [Changelog](#changelog)

---

## Funktionsweise

Der Effekt „Negotiate image format" liest den `Accept`-Header des Browsers und gibt das bestmögliche Format zurück:

1. **AVIF** – falls Browser und Server AVIF unterstützen
2. **WebP** – falls Browser und Server WebP unterstützen
3. **Original** – als Fallback ohne Konvertierung

Die Konvertierung erfolgt automatisch mit der besten verfügbaren Bibliothek (Priorität: **libvips › Imagick › GD**). Der Media Manager Cache wird je Format getrennt verwaltet, sodass Bilder nicht doppelt konvertiert werden.

Weitere Informationen zu HTTP Content Negotiation: [MDN Web Docs](https://developer.mozilla.org/en-US/docs/Web/HTTP/Content_negotiation)

---

## libvips – der schnelle Weg

[libvips](https://www.libvips.org/) ist eine Bildverarbeitungsbibliothek, die für den Einsatz auf Webservern entwickelt wurde. Im Vergleich zu Imagick (ImageMagick) zeigt sie in der Praxis deutliche Vorteile:

| Merkmal | Imagick | libvips |
|---|---|---|
| **Speicherbedarf** | Lädt das gesamte Bild in den RAM | Streaming-Pipeline – ca. **10× weniger RAM** |
| **Geschwindigkeit** | Mittel | **3–8× schneller** bei AVIF/WebP-Erzeugung |
| **Memory-Leaks** | Bekanntes Problem (nativer C-Heap) | Kein vergleichbarer Overhead |
| **AVIF / WebP** | ✓ (Codec-Abhängig) | ✓ |
| **ICC-Profile** | ✓ | ✓ (nativ via `icc_transform`) |

Gerade bei **vielen parallelen Requests** oder **großen Bildern** macht sich der Unterschied deutlich bemerkbar: Imagick lädt das Bild komplett in den PHP-Prozess-Speicher, libvips streamt es in Kacheln durch die Pipeline und schreibt direkt in das Zielformat. Das ist der Hauptgrund warum es unter Last zu Speicherüberlaufen und Server-Abstürzen kommen kann, wenn Imagick für Massenoperationen eingesetzt wird.

### libvips installieren (Ubuntu / Plesk)

```bash
# Systembibliothek
apt-get install -y libvips-dev libvips42

# PHP-Extension via PECL
pecl install vips
echo "extension=vips.so" > /etc/php/8.3/mods-available/vips.ini
phpenmod -v 8.3 vips
service php8.3-fpm restart

# Prüfen
php8.3 -r "echo vips_version() . PHP_EOL;"
```

Sobald die Extension aktiv ist, verwendet Media Negotiator libvips automatisch – keine weitere Konfiguration nötig. Der Status wird auf der **Setup-Seite** angezeigt.

---

## ICC-Fix und sRGB-Workflow

Zusätzlich zum Negotiator-Effekt bringt das Addon den Effekt **„Farbraum nach sRGB konvertieren"** mit. Er ist für Uploads gedacht, die mit eingebettetem ICC-Profil kommen, zum Beispiel Adobe RGB aus Kameras oder Bildbearbeitung.

Der Effekt arbeitet als Vorverarbeitung für Web-Derivate und verwendet bewusst weiter **ImageMagick/Imagick** für die ICC-Profiltransformation. Der Negotiator selbst darf für AVIF/WebP automatisch **libvips** bevorzugen, aber die eigentliche Farbraumkonvertierung bleibt auf dem bewährten Imagick-Pfad, da dieser in der Praxis bei eingebetteten ICC-Profilen die zuverlässigeren und visuell näher am Original liegenden Ergebnisse liefert.

Der Effekt arbeitet als Vorverarbeitung für Web-Derivate:

1. Bild per Imagick laden
2. EXIF-Orientierung berücksichtigen
3. Eingebettetes Quell-ICC gegen ein mitgeliefertes **sRGB-ICC-Profil** transformieren
4. Metadaten entfernen und das sRGB-Profil wieder explizit einbetten
5. Weitere Media-Manager-Effekte auf bereits webtauglichem Material ausführen

Für typische Web-Ausgaben reicht das aus. Das Derivat bleibt dabei explizit als sRGB getaggt, sodass Browser es konsistent zum Original mit eingebettetem Profil rendern.

Wichtig: Den ICC-Fix-Effekt in der Effektkette möglichst **vor** Resize, Crop und vor dem Negotiator-Effekt platzieren.

---

## Unterstützte Formate

| Format | libvips | GD                     | Imagick              |
|--------|---------|------------------------|----------------------|
| WebP   | ✓       | `imagewebp()` + GD-Flag | `WEBP`-Codec nötig  |
| AVIF   | ✓       | `imageavif()` + GD-Flag | `AVIF`-Codec nötig  |

---

## Voraussetzungen

- REDAXO ≥ 5.18.0
- PHP ≥ 8.1
- Media Manager Addon ≥ 2.17.0
- Eines der folgenden (in Prioritätsreihenfolge):
  - **libvips** PHP-Extension (`pecl install vips`) – empfohlen
  - **Imagick** mit WebP/AVIF-Codec
  - **GD** mit WebP- und/oder AVIF-Unterstützung

---

## Installation

Installation über den REDAXO-Installer oder manuell durch Hochladen in `redaxo/src/addons/media_negotiator`.

---

## Einrichtung

1. Im REDAXO-Backend zu **Media Manager → Medientypen** navigieren.
2. Den gewünschten Medientyp öffnen oder einen neuen anlegen.
3. Optional bei farbkritischen Uploads zuerst den Effekt **„Farbraum nach sRGB konvertieren"** hinzufügen.
4. Danach den Effekt **„Negotiate image format"** hinzufügen.
5. Den Medientyp speichern.

Ab sofort liefert der Media Manager Bilder dieses Typs automatisch im optimalen Format aus.

---

## Einstellungen

Unter **Media Manager → Media Negotiator → Einstellungen** stehen folgende Optionen zur Verfügung:

| Option | Beschreibung | Standard |
|--------|-------------|---------|
| **Imagick erzwingen** | Imagick wird auch dann verwendet, wenn GD-Funktionen verfügbar sind | Nein |
| **AVIF deaktivieren** | Verhindert AVIF-Ausgabe, z. B. wenn der Server keinen AVIF-Codec besitzt | Nein |
| **WebP-Qualität** | Kompressionsstufe für WebP (0–100) | 80 |
| **AVIF-Qualität** | Kompressionsstufe für AVIF (0–100) | 60 |
| **User-Agent-Fallback** | Format auch anhand des User-Agent ermitteln, wenn der Accept-Header keine expliziten Formate enthält | Nein |

---

## User-Agent-Fallback

Einige Browser – insbesondere **Safari ab Version 16.4** – unterstützen AVIF, senden aber kein `image/avif` im `Accept`-Header. Mit aktiviertem User-Agent-Fallback analysiert Media Negotiator zusätzlich den `User-Agent`-String und wählt das bestmögliche Format:

| Browser | AVIF ab | WebP ab |
|---------|---------|---------|
| Safari | 16.4 | 14.0 |
| Chrome / Chromium | 85 | 32 |
| Firefox | 93 | 65 |

Der UA-Fallback greift nur wenn der Accept-Header keine expliziten Bildformate enthält. Die Aktivierung empfiehlt sich, wenn Safari-Nutzer ein großes Anteil der Besucher ausmachen.

---

## Setup-Seite

Unter **Media Manager → Media Negotiator → Setup** werden die verfügbaren Codecs und Bibliotheken auf dem Server angezeigt. Die Demo-Bilder werden bedarfsgesteuert geladen und nicht mehr direkt beim Seitenaufbau inline konvertiert.

---

## ICC-Fix-Seite

Unter **Media Manager → Media Negotiator → ICC Fix** können Sie den Effekt **„Farbraum nach sRGB konvertieren"** mehreren Media-Manager-Typen gleichzeitig zuweisen oder wieder entfernen.

Die Seite funktioniert analog zur Typen-Zuweisung des Negotiator-Effekts:

- Mehrfachauswahl von Medientypen
- Sammelaktion zum Hinzufügen oder Entfernen

Beim Hinzufügen setzt das Addon den Effekt immer automatisch **an den Anfang der Effektkette**, weil die sRGB-Konvertierung fachlich vor allen weiteren Bildoperationen stattfinden muss.

---

## Changelog

Alle Änderungen sind in der [CHANGELOG.md](CHANGELOG.md) dokumentiert.
