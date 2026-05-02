# Media Negotiator

REDAXO-Addon, das dem Media Manager einen Effekt für **HTTP Content Negotiation** hinzufügt. Der Browser teilt dem Server über den `Accept`-Header mit, welche Bildformate er unterstützt – Media Negotiator liefert daraufhin automatisch das optimale Format aus.

---

## Inhaltsverzeichnis

- [Funktionsweise](#funktionsweise)
- [ICC-Fix und sRGB-Workflow](#icc-fix-und-srgb-workflow)
- [Unterstützte Formate](#unterstützte-formate)
- [Voraussetzungen](#voraussetzungen)
- [Installation](#installation)
- [Einrichtung](#einrichtung)
- [Einstellungen](#einstellungen)
- [User-Agent-Fallback](#user-agent-fallback)
- [CLI-Cache-Warmup](#cli-cache-warmup)
- [Setup-Seite](#setup-seite)
- [ICC-Fix-Seite](#icc-fix-seite)
- [Changelog](#changelog)

---

## Funktionsweise

Der Effekt „Negotiate image format" liest den `Accept`-Header des Browsers und gibt das bestmögliche Format zurück:

1. **AVIF** – falls Browser und Server AVIF unterstützen
2. **WebP** – falls Browser und Server WebP unterstützen
3. **Original** – als Fallback ohne Konvertierung

Die Konvertierung erfolgt entweder über GD (Standard) oder Imagick (konfigurierbar). Der Media Manager Cache wird je Format getrennt verwaltet, sodass Bilder nicht doppelt konvertiert werden.

Weitere Informationen zu HTTP Content Negotiation: [MDN Web Docs](https://developer.mozilla.org/en-US/docs/Web/HTTP/Content_negotiation)

---

## ICC-Fix und sRGB-Workflow

Zusätzlich zum Negotiator-Effekt bringt das Addon den Effekt **„Farbraum nach sRGB konvertieren"** mit. Er ist für Uploads gedacht, die mit eingebettetem ICC-Profil kommen, zum Beispiel Adobe RGB aus Kameras oder Bildbearbeitung.

Der Effekt arbeitet als Vorverarbeitung für Web-Derivate:

1. Bild per Imagick laden
2. EXIF-Orientierung berücksichtigen
3. Farbverwaltet nach **sRGB** konvertieren
4. ICC/Metadaten entfernen
5. Weitere Media-Manager-Effekte auf bereits webtauglichem Material ausführen

Für typische Web-Ausgaben reicht das aus. Das ICC-Profil muss danach normalerweise nicht mit ausgeliefert werden, weil die Pixel bereits korrekt nach sRGB transformiert wurden.

Wichtig: Den ICC-Fix-Effekt in der Effektkette möglichst **vor** Resize, Crop und vor dem Negotiator-Effekt platzieren.

---

## Unterstützte Formate

| Format | GD                     | Imagick              |
|--------|------------------------|----------------------|
| WebP   | `imagewebp()` + GD-Flag | `WEBP`-Codec nötig  |
| AVIF   | `imageavif()` + GD-Flag | `AVIF`-Codec nötig  |

---

## Voraussetzungen

- REDAXO ≥ 5.18.0
- PHP ≥ 8.1
- Media Manager Addon ≥ 2.17.0
- GD mit WebP- und/oder AVIF-Unterstützung **oder** Imagick mit entsprechenden Codecs

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

## CLI-Cache-Warmup

Das Kommando `media:negotiator:warmup` füllt den Media-Manager-Cache vorab, ohne dass Seitenbesucher auf die erste Konvertierung warten müssen.

```bash
php redaxo/bin/console media:negotiator:warmup --type=mein-medientyp
```

### Optionen

| Option | Beschreibung | Standard |
|--------|-------------|---------|
| `--type` | Medientyp-Name (mehrfach verwendbar) | alle Typen mit Negotiator-Effekt |
| `--formats` | Comma-separierte Formate: `avif,webp,default` | `avif,webp` |
| `--limit` | Maximale Anzahl Bilder | unbegrenzt |
| `--base-url` | Basis-URL für Requests | REDAXO `server`-Konfiguration |
| `--dry-run` | Nur anzeigen, was konvertiert würde | – |

---

## Setup-Seite

Unter **Media Manager → Media Negotiator → Setup** werden die verfügbaren Codecs und Bibliotheken auf dem Server angezeigt. Außerdem werden Demo-Bilder in allen verfügbaren Formaten gerendert, um die Konvertierung direkt zu überprüfen.

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
