# Media Negotiator

REDAXO-Addon, das dem Media Manager einen Effekt für **HTTP Content Negotiation** hinzufügt. Der Browser teilt dem Server über den `Accept`-Header mit, welche Bildformate er unterstützt – Media Negotiator liefert daraufhin automatisch das optimale Format aus.

Jetzt mit **libvips**-Support im Negotiator: Wenn die PHP-Extension `vips` installiert ist, wird sie für die AVIF-/WebP-Konvertierung automatisch bevorzugt eingesetzt und ist dort der deutlich schnellere und speicherfreundlichere Weg. Der separate ICC-/sRGB-Fix bleibt bewusst bei Imagick.

---

## Inhaltsverzeichnis

- [Funktionsweise](#funktionsweise)
- [libvips – der schnelle Weg](#libvips--der-schnelle-weg)
- [ICC-Fix und sRGB-Workflow](#icc-fix-und-srgb-workflow)
- [Unterstützte Formate](#unterstützte-formate)
- [Voraussetzungen](#voraussetzungen)
- [GD-Codec-Support manuell aktivieren (Linux/Plesk)](#gd-codec-support-manuell-aktivieren-linuxplesk)
- [Vorlage für Hoster-Anfrage](#vorlage-für-hoster-anfrage)
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

Die Formatkonvertierung des Negotiator-Effekts erfolgt automatisch mit der besten verfügbaren Bibliothek.

- AVIF: standardmäßig **libvips > GD > Imagick** (in den Einstellungen konfigurierbar)
- WebP: **libvips > Imagick > GD**

Der Media Manager Cache wird je Format getrennt verwaltet, sodass Bilder nicht doppelt konvertiert werden.

Weitere Informationen zu HTTP Content Negotiation: [MDN Web Docs](https://developer.mozilla.org/en-US/docs/Web/HTTP/Content_negotiation)

---

## libvips – der schnelle Weg

[libvips](https://www.libvips.org/) ist eine Bildverarbeitungsbibliothek, die für den Einsatz auf Webservern entwickelt wurde. In diesem Addon wird sie aktuell für die schnelle Ausgabe von **AVIF** und **WebP** im Negotiator verwendet. Im Vergleich zu Imagick (ImageMagick) zeigt sie dafür in der Praxis deutliche Vorteile:

| Merkmal | Imagick | libvips |
|---|---|---|
| **Speicherbedarf** | Lädt das gesamte Bild in den RAM | Streaming-Pipeline – ca. **10× weniger RAM** |
| **Geschwindigkeit** | Mittel | **3–8× schneller** bei AVIF/WebP-Erzeugung |
| **Memory-Leaks** | Bekanntes Problem (nativer C-Heap) | Kein vergleichbarer Overhead |
| **AVIF / WebP** | ✓ (Codec-Abhängig) | ✓ |
| **Einsatz in diesem Addon** | Negotiator und ICC-Fix möglich, aktuell ICC-Fix aktiv | aktuell nur Negotiator |

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

Sobald die Extension aktiv ist, verwendet Media Negotiator libvips im Negotiator automatisch – keine weitere Konfiguration nötig. Der Status wird auf der **Setup-Seite** angezeigt.

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
| WebP   | ja, im Negotiator | `imagewebp()` + GD-Flag | `WEBP`-Codec nötig |
| AVIF   | ja, im Negotiator | `imageavif()` + GD-Flag | `AVIF`-Codec nötig |

---

## Voraussetzungen

- REDAXO ≥ 5.18.0
- PHP ≥ 8.1
- Media Manager Addon ≥ 2.17.0
- Für den Negotiator mindestens eines der folgenden:
  - **libvips** PHP-Extension (`pecl install vips`) - empfohlen
  - **Imagick** mit WebP/AVIF-Codec
  - **GD** mit WebP- und/oder AVIF-Unterstützung
- Für den Effekt **„Farbraum nach sRGB konvertieren“** wird **Imagick** benötigt.

---

## GD-Codec-Support manuell aktivieren (Linux/Plesk)

Wenn auf dem Server bei `gd_info()` weder **AVIF Support** noch **WebP Support** aktiv ist, können Sie GD manuell mit den benötigten Codecs neu bauen.

### 1) Linux (Debian/Ubuntu, Docker oder VM)

Voraussetzung: PHP aus dem offiziellen Docker-Image oder aus Quellen, sodass `docker-php-ext-*` bzw. `phpize` verfügbar ist.

```bash
# Build-Abhängigkeiten installieren
apt-get update
apt-get install -y --no-install-recommends \
  libavif-dev libwebp-dev libjpeg62-turbo-dev libpng-dev libfreetype6-dev pkg-config

# GD mit AVIF/WebP konfigurieren und bauen (Docker-PHP Images)
docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp --with-avif
docker-php-ext-install -j"$(nproc)" gd

# Verifikation
php -r 'print_r(gd_info()); echo "imageavif: ".(function_exists("imageavif")?"yes":"no").PHP_EOL; echo "imagewebp: ".(function_exists("imagewebp")?"yes":"no").PHP_EOL;'
```

Hinweis für Docker: Änderungen direkt im laufenden Container sind nicht persistent. Für dauerhafte Nutzung die Schritte im Dockerfile hinterlegen und das Image neu bauen.

### 2) Plesk (Debian/Ubuntu)

Plesk nutzt versionierte PHP-Binaries unter `/opt/plesk/php/<version>/bin/php`. Prüfen und bauen Sie immer gegen genau diesen Handler.

```bash
# Beispiel: Plesk PHP 8.3 prüfen
/opt/plesk/php/8.3/bin/php -r 'print_r(gd_info());'

# Falls AVIF/WebP fehlen: System-Codecs installieren
apt-get update
apt-get install -y --no-install-recommends \
  libavif-dev libwebp-dev libjpeg62-turbo-dev libpng-dev libfreetype6-dev pkg-config

# GD gegen den Plesk-Handler neu bauen (Pfade je Version anpassen)
export PHP_PREFIX=/opt/plesk/php/8.3
export PATH="$PHP_PREFIX/bin:$PATH"

cd /usr/local/src
curl -fsSLO https://www.php.net/distributions/php-8.3.23.tar.xz
tar -xf php-8.3.23.tar.xz
cd php-8.3.23/ext/gd

phpize
./configure --with-php-config="$PHP_PREFIX/bin/php-config" --with-freetype --with-jpeg --with-webp --with-avif
make -j"$(nproc)"
make install

# Extension aktivieren und FPM neu starten
echo "extension=gd.so" > "$PHP_PREFIX/etc/php.d/99-custom-gd.ini"
systemctl restart plesk-php83-fpm

# Verifikation
$PHP_PREFIX/bin/php -r 'print_r(gd_info()); echo "imageavif: ".(function_exists("imageavif")?"yes":"no").PHP_EOL; echo "imagewebp: ".(function_exists("imagewebp")?"yes":"no").PHP_EOL;'
```

Wichtig: Bei Plesk muss die Quellversion zu Ihrer aktiven PHP-Nebenversion passen (z. B. 8.3.x). Wiederholen Sie die Schritte für jede installierte PHP-Version separat.

---

## Vorlage für Hoster-Anfrage

Kurztext zum Kopieren (Deutsch):

```text
Guten Tag,

für unsere REDAXO-Installation benötigen wir auf dem Webserver Unterstützung für moderne Bildformate (AVIF/WebP).

Bevorzugt:
- libvips + PHP-Extension vips (php-vips)

Alternativ mindestens eine der folgenden Varianten:
- GD mit AVIF- und WebP-Support (imageavif/imagewebp)
- Imagick/ImageMagick mit AVIF- und WebP-Codec

Bitte aktivieren Sie den Support für die von uns verwendete PHP-Version und den entsprechenden Web-Handler (FPM/Apache).

Vielen Dank.
```

---

## Installation

Installation über den REDAXO-Installer oder manuell durch Hochladen in `redaxo/src/addons/media_negotiator`.

---

## Einrichtung

1. Im REDAXO-Backend zu **Media Manager → Medientypen** navigieren.
2. Den gewünschten Medientyp öffnen oder einen neuen anlegen.
3. Optional bei farbkritischen Uploads zuerst den Effekt **„Farbraum nach sRGB konvertieren"** hinzufügen.
4. Alle weiteren Effekte (Resize, Crop, Filter …) hinzufügen.
5. Den Effekt **„Negotiate image format"** als **letzten Effekt** hinzufügen.
6. Den Medientyp speichern.

> **⚠ Reihenfolge beachten:** Der Negotiator-Effekt muss immer **als letzter Effekt** in der Kette stehen. Er konvertiert das fertig transformierte Bild ins Zielformat (WebP/AVIF). Nachfolgende Effekte würden auf dem konvertierten Blob arbeiten – GD kann WebP/AVIF nicht lesen und würde fehlschlagen.

Ab sofort liefert der Media Manager Bilder dieses Typs automatisch im optimalen Format aus.

---

## Einstellungen

Unter **Media Manager → Media Negotiator → Einstellungen** stehen folgende Optionen zur Verfügung:

| Option | Beschreibung | Standard |
|--------|-------------|---------|
| **Imagick erzwingen** | Kompatibilitätsoption (Legacy), derzeit ohne Einfluss auf die automatische AVIF/WebP-Konverter-Reihenfolge | Nein |
| **AVIF deaktivieren** | Verhindert AVIF-Ausgabe, z. B. wenn der Server keinen AVIF-Codec besitzt | Nein |
| **WebP-Qualität** | Kompressionsstufe für WebP (0–100) | 80 |
| **AVIF-Qualität** | Kompressionsstufe für AVIF (0–100) | 60 |
| **Bevorzugte AVIF-Pipeline** | Legt den bevorzugten AVIF-Konverter fest (`auto`, `vips`, `gd`, `imagick`). Bei `auto` wird libvips bevorzugt, sobald verfügbar. | `auto` |
| **User-Agent-Fallback** | Format auch anhand des User-Agent ermitteln, wenn der Accept-Header keine expliziten Formate enthält | Nein |
| **Bevorzugtes Format** | Steuert die Priorität der Ausgabe: `avif` oder `webp` | `avif` |

---

## User-Agent-Fallback

Einige Browser – insbesondere **Safari ab Version 16.4** – unterstützen AVIF, senden aber kein `image/avif` im `Accept`-Header. Mit aktiviertem User-Agent-Fallback analysiert Media Negotiator zusätzlich den `User-Agent`-String und wählt das bestmögliche Format:

| Browser | AVIF ab | WebP ab |
|---------|---------|---------|
| Safari | 16.4 | 14.0 |
| Chrome / Chromium | 85 | 32 |
| Firefox | 93 | 65 |

Der UA-Fallback greift nur wenn der Accept-Header keine expliziten Bildformate enthält. Die Aktivierung empfiehlt sich, wenn Safari-Nutzer einen großen Anteil der Besucher ausmachen.

---

## Setup-Seite

Unter **Media Manager → Media Negotiator → Setup** werden die verfügbaren Codecs und Bibliotheken auf dem Server angezeigt, inklusive GD, Imagick und libvips. Die Demo-Bilder werden bedarfsgesteuert geladen und nicht mehr direkt beim Seitenaufbau inline konvertiert.

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
