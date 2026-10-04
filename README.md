# Tankstellen

[![IP-Symcon](https://img.shields.io/badge/IP--Symcon-7.1%2B-blue.svg)](https://www.symcon.de)
[![Symcon 9.0](https://img.shields.io/badge/optimiert%20f%C3%BCr-Symcon%209.0-0a6ebd.svg)](https://www.symcon.de)
[![Darstellungen](https://img.shields.io/badge/Darstellungen-ab%208.0-0a6ebd.svg)](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/darstellungen/)
[![Kachel](https://img.shields.io/badge/Kachel-HTML--SDK-22b14c.svg)](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/html-sdk/)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777bb4.svg)](https://www.php.net)
[![Sorten](https://img.shields.io/badge/Sorten-E5%20%7C%20E10%20%7C%20Diesel%20%7C%20Plus%20%7C%20LPG%20%7C%20CNG-2fbf5b.svg)](#funktionen)
[![Sicherheit](https://img.shields.io/badge/Sicherheit-HTTPS%20%C2%B7%20Ratenlimit%20%C2%B7%20Escaping-555.svg)](#sicherheit)
[![Version](https://img.shields.io/badge/Version-3.7-green.svg)](#changelog)
[![Lizenz](https://img.shields.io/badge/Lizenz-MIT-lightgrey.svg)](LICENSE)
[![Daten](https://img.shields.io/badge/Daten-Tankerk%C3%B6nig%20CC%20BY%204.0-orange.svg)](https://creativecommons.tankerkoenig.de)
[![GitHub](https://img.shields.io/badge/GitHub-cfaf2002%2FTankstelle__Symcon-181717.svg?logo=github)](https://github.com/cfaf2002/Tankstelle_Symcon)

IP-Symcon-Modul für aktuelle Spritpreise aller Tankstellen im Umkreis über die
[Tankerkönig-API](https://creativecommons.tankerkoenig.de) – mit allen Sorten, die die API liefert, Preistrend,
Öffnungszeiten, Vergleich zum Bundesschnitt und einer modernen Kachel.

## Inhalt

1. [Funktionen](#funktionen)
2. [Voraussetzungen](#voraussetzungen)
3. [Installation](#installation)
4. [Konfiguration](#konfiguration)
5. [Variablen](#variablen)
6. [Kachel](#kachel)
7. [PHP-Befehle](#php-befehle)
8. [Was die Tankerkönig-API kann](#was-die-tankerkönig-api-kann)
9. [Geschwindigkeit](#geschwindigkeit)
10. [Sicherheit](#sicherheit)
11. [Lizenzen und Quellen](#lizenzen-und-quellen)
12. [Changelog](#changelog)

## Funktionen

- **Alle Sorten der API:** Super E5, Super E10, Diesel sowie Super Plus, Autogas (LPG) und Erdgas (CNG), sobald Tankerkönig sie liefert – per Haken an-/abwählbar, Umschalten per Klick in Kachel oder Variable
- **Eine Abfrage für alles:** alle Sorten kommen mit einem einzigen API-Aufruf (API v4, Rückfall auf v1) – Umschalten ohne Wartezeit
- **Preistrend:** letzte Preisänderung je Tankstelle (↑/↓ in Cent)
- **Öffnungszeiten:** „bis 22:00“ bzw. „öffnet 06:00“ direkt in Kachel und Liste
- **Bundesdurchschnitt:** Vergleich mit dem deutschlandweiten Ø-Preis, Ersparnis in Cent als Variable
- **Standort aus Symcon** (Location Control), alternativ per Karte oder Postleitzahl
- **Aktiv-Schalter** zum Pausieren ohne Löschen
- **Bestpreis je Kraftstoff** als eigene Variable – ideal fürs Archiv und für Preisverläufe
- Günstigste Tankstelle mit Adresse und Entfernung, Ø- und Höchstpreis, Anzahl Stationen
- **Moderne Kachel** im Stil der Symcon-Kacheln (HTML-SDK): Segment-Schalter, Preis in Tankstellen-Optik, Preisspanne, Zeitpunkt der letzten Aktualisierung, passt sich Größe und Hell/Dunkel an
- **Darstellungen** (Symcon 8.0+/9.0) statt Variablenprofile – automatischer Rückfall auf Profile bei älteren Versionen
- **Hintergrundbild:** mitgeliefertes Motiv „Zapfhahn“ oder ein eigenes Bild, mit Abdunklung und Unschärfe
- **Eigene Bilder** für die Stationen in der Liste hinterlegbar (Medienobjekte), sonst Kürzel
- Optionale HTML-Variable (Webinhalt) für WebFront und ältere Visualisierungen
- Optionaler **Preisalarm** mit einstellbarer Schwelle

> Die Markttransparenzstelle für Kraftstoffe meldet derzeit **Super E5, Super E10 und Diesel**. Die API v4 sieht
> zusätzlich LPG und CNG vor. Das Modul übernimmt weitere Sorten automatisch, sobald Tankerkönig sie liefert –
> welche Sorten zuletzt geliefert wurden, steht im Konfigurationsformular. AdBlue wird von keiner Quelle gemeldet.

## Voraussetzungen

- IP-Symcon ab 7.1 (Kachel), empfohlen 8.0 oder neuer (Darstellungen), optimiert für 9.0
- Kostenloser Tankerkönig-API-Key: <https://creativecommons.tankerkoenig.de>

## Installation

Im **Module Control** folgende URL hinzufügen:

```
https://github.com/cfaf2002/Tankstelle_Symcon
```

Danach eine neue Instanz **Tankstellen** anlegen.

## Konfiguration

| Einstellung | Beschreibung |
|---|---|
| Aktiv | Schaltet Abfrage und Timer ein bzw. aus (Status „inaktiv“) |
| Tankerkönig API-Key | Persönlicher Schlüssel (verdeckte Eingabe) |
| Standortquelle | **Symcon-Standort** (Standard): aus Kern-Instanzen → Location Control, Änderungen dort greifen automatisch<br>**Eigener Standort**: Punkt auf der Karte wählen<br>**Postleitzahl**: Koordinaten über OpenStreetMap, Ergebnis wird zwischengespeichert |
| Suchradius | 1–25 km (Grenze der API) |
| Kraftstoffe | Super E5, Super E10, Diesel, Super Plus, LPG, CNG per Haken an-/abwählen |
| Aktualisierungsintervall | Mindestens 5 Minuten (Vorgabe von Tankerkönig) |
| Sortierung der Liste | Nach Preis oder Entfernung |
| Nur geöffnete Tankstellen | Geschlossene Stationen ausblenden |
| Max. Einträge | Begrenzung für Liste in Kachel und HTML-Variable (0 = alle) |
| Bundesdurchschnitt | Deutschlandweiten Ø-Preis abrufen (alle 6 Stunden, zeitversetzt) |
| HTML-Variable | Zuschaltbar, für die Kachel nicht nötig |
| Hintergrund | **Zapfhahn (mitgeliefert)**, **Eigenes Bild** (Medienobjekt, max. 3 MB) oder **Kein Bild**; dazu Abdunkeln (0–90 %) und Unschärfe (0–20 px) |
| Eigene Symbole | Ersetzt in der Liste den farbigen Kreis mit zwei Buchstaben durch ein eigenes Bild: Tankstellen-Name (wie in der Kachel) und Bild aus Symcon (max. 512 KB) |
| Detailvariablen | Adresse, Entfernung, Ø-Preis, Höchstpreis, Anzahl |
| Preisalarm + Schwelle | Für den gewählten Kraftstoff, Schwelle in € |

## Variablen

| Ident | Name | Typ | Darstellung |
|---|---|---|---|
| FuelType | Kraftstoff | Integer (Aktion) | Aufzählung, nebeneinander zum Antippen |
| CheapestPrice | Günstigster Preis | Float | Wertanzeige, 3 Nachkommastellen, € |
| CheapestName | Günstigste Tankstelle | String | Wertanzeige |
| Price_e5 / _e10 / _diesel / _superplus / _lpg / _cng | Bestpreis je aktivierter Sorte | Float | Wertanzeige, € |
| CheapestAddress | Adresse *(Detail)* | String | Wertanzeige |
| CheapestDistance | Entfernung *(Detail)* | Float | Wertanzeige, km |
| AveragePrice | Durchschnittspreis *(Detail)* | Float | Wertanzeige, € |
| HighestPrice | Höchster Preis *(Detail)* | Float | Wertanzeige, € |
| StationCount | Anzahl Tankstellen *(Detail)* | Integer | Wertanzeige |
| NationalAverage | Bundesdurchschnitt *(optional)* | Float | Wertanzeige, € |
| SavingVsNational | Ersparnis zum Bundesschnitt *(optional)* | Float | Wertanzeige, ct |
| HTML | Übersicht *(optional)* | String | Webinhalt |
| PriceAlert | Preis unter Schwelle *(optional)* | Boolean | Wertanzeige |
| LastUpdate | Letzte Aktualisierung | Integer | Datum/Uhrzeit |

Unter Symcon 7.x werden statt Darstellungen die Profile `TANK.FuelType`, `TANK.Price`, `TANK.Distance`, `TANK.Cent` angelegt.

## Kachel

Die Instanz bringt eine eigene Kachel mit (HTML-SDK). Einfach die Instanz in der Kachel-Visualisierung hinzufügen.

- **Segment-Schalter** für alle aktivierten Sorten mit aktuellem Bestpreis – ein Klick schaltet sofort um
- **Bestpreis groß** in Tankstellen-Optik (2,10⁹ €) mit Preistrend der letzten Änderung
- Tankstelle mit Adresse, Entfernung und Öffnungszeit
- **Preisspanne:** Balken von günstigster bis teuerster Station, Markierung für Ø hier und Ø Deutschland
- Liste aller Stationen mit Kürzel oder eigenem Bild, Trendpfeil und Öffnungszeit; geschlossene ausgegraut
- **Letzte Aktualisierung** immer sichtbar (Uhrzeit und „vor x Min.“), rot bei veralteten Daten – ein Klick darauf aktualisiert
- **Passt sich an:** breit zweispaltig, hoch mit Liste, mittel ohne Liste, klein nur Preis
- Übernimmt Schrift sowie Hell/Dunkel der Symcon-Visualisierung; lässt Platz für Kacheltitel und Vollbild-Symbol

### Hintergrundbild und eigene Bilder

Ab Werk zeigt die Kachel das mitgelieferte Motiv **Zapfhahn** (SVG, scharf in jeder Größe). Für ein eigenes Bild:

1. Bild in Symcon als **Medienobjekt** vom Typ *Bild* anlegen (PNG, JPG, WebP, GIF oder SVG).
2. In der Instanz unter **Darstellung der Kachel** bei *Hintergrund* „Eigenes Bild“ wählen und das Medienobjekt auswählen bzw. in der Liste **Eigene Symbole**
   auf „Hinzufügen“ klicken, den Tankstellen-Namen eintragen (so wie er in der Kachel fett über der Adresse steht)
   und das Bild auswählen. Das Symbol ersetzt dann den farbigen Kreis mit zwei Buchstaben.
3. Bilder werden nur beim Laden der Kachel übertragen, nicht bei jeder Preisänderung. Auf einem Hintergrundbild
   ist die Schrift immer hell.

> Für die Liste liefert das Modul keine Bilder mit; ohne eigenes Bild zeigt die Kachel ein Kürzel.

## PHP-Befehle

```php
// Preise sofort abrufen (true/false)
TANK_Update(int $InstanzID);

// Stationsliste einer Sorte ("e5", "e10", "diesel", "superplus", "lpg", "cng" oder leer = gewählte)
$stations = TANK_GetStations(int $InstanzID, string $Sorte);

// Bundesdurchschnitt sofort abrufen (sonst automatisch alle 6 Stunden)
TANK_UpdateStats(int $InstanzID);

// Falsche Daten an die Markttransparenzstelle melden
// Typen z. B.: wrongPriceE5, wrongPriceE10, wrongPriceDiesel, wrongStatusOpen, wrongStatusClosed,
//             wrongPetrolStationStreet, wrongPetrolStationPlace, wrongPetrolStationLocation
TANK_ReportError(int $InstanzID, string $StationsID, string $Typ, string $Korrektur);

// Sorte umschalten (0 = E5, 1 = E10, 2 = Diesel, 3 = Super Plus, 4 = LPG, 5 = CNG)
RequestAction(IPS_GetObjectIDByIdent('FuelType', $InstanzID), 2);
```

## Was die Tankerkönig-API kann

| Funktion | Endpunkt | Im Modul |
|---|---|---|
| Umkreissuche mit allen Sorten, Öffnungszeiten und letzter Preisänderung | v4 `/stations/search` | ✔ Hauptabruf |
| Umkreissuche E5/E10/Diesel | v1 `list.php` | ✔ automatischer Rückfall, falls v4 ausfällt |
| Bundesweite Statistik (Ø, Median, Anzahl je Sorte) | v4 `/stats` | ✔ alle 6 Stunden, Vergleich in Kachel und Variable |
| Falsche Preise, Öffnungsstatus oder Stammdaten melden | v1 `complaint.php` | ✔ `TANK_ReportError` |
| Preise bestimmter Stationen per ID | v4 `/stations/ids`, v1 `prices.php` | – nicht nötig, die Umkreissuche enthält alle Stationen |
| Stationen einer Postleitzahl | v4 `/stations/postalcode` | – Umkreissuche ist genauer |
| Stationsdetails mit Öffnungszeiten | v1 `detail.php` | – in v4 bereits in der Umkreissuche enthalten |

**Nutzungsregeln von Tankerkönig**, die das Modul einhält: höchstens eine Anfrage pro Minute je API-Key,
für Hausautomation höchstens alle 5 Minuten mit zufälligem Zeitversatz, Umkreis bis 25 km, Quellenangabe.

## Geschwindigkeit

- **Ein API-Aufruf** liefert alle Sorten gleichzeitig, statt einem Aufruf pro Sorte
- Kraftstoffwechsel rechnet aus dem Zwischenspeicher – **kein** neuer Abruf, in der Kachel ohne Server-Rundreise
- Variablen werden nur geschrieben, wenn sich der Wert ändert (weniger Ereignisse, kleineres Archiv)
- Komprimierte Übertragung (gzip), kurze Timeouts, PLZ-Koordinaten werden zwischengespeichert
- HTML-Variable wird nur erzeugt, wenn sie eingeschaltet ist

## Sicherheit

- API-Key in verdeckter Eingabe; im Debug-Fenster wird er maskiert und nie an die Kachel übertragen
- Nur HTTPS mit Zertifikatsprüfung, keine Weiterleitungen, Antwortgröße begrenzt
- Alle Texte aus der API werden vor der Ausgabe escaped; die Kachel baut ihre Inhalte per DOM, nicht per HTML-String
- Aktionen aus der Kachel werden geprüft: nur bekannte Aktionen, nur aktivierte Kraftstoffe
- Ratenlimit: höchstens eine Anfrage pro Minute für alle Abrufe zusammen (Preise, Statistik, Meldungen)
- Meldungen an die MTS-K nur mit geprüfter Stations-ID und festgelegten Meldungstypen
- Sperre gegen parallele Abfragen (Timer, Button und Kachel gleichzeitig)
- Kachel-Kommunikation läuft über das passwortgeschützte HTML-SDK der Visualisierung

## Lizenzen und Quellen

| Bestandteil | Lizenz |
|---|---|
| Quellcode dieses Moduls | [MIT](LICENSE) – © 2026 Armin Frohwerk |
| Preisdaten | Markttransparenzstelle für Kraftstoffe (MTS-K) über [Tankerkönig](https://creativecommons.tankerkoenig.de), [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/deed.de) – Quellenhinweis wird in Kachel und HTML-Variable angezeigt |
| Geocoding (nur Quelle „Postleitzahl“) | © [OpenStreetMap-Mitwirkende](https://www.openstreetmap.org/copyright), [ODbL](https://opendatacommons.org/licenses/odbl/); Nutzung gemäß [Nominatim-Richtlinie](https://operations.osmfoundation.org/policies/nominatim/) (eigener User-Agent, Zwischenspeicher) |
| Icons | Werden von der Symcon-Visualisierung bereitgestellt |
| Motiv „Zapfhahn“ (`assets/zapfhahn.svg`) | Eigene Grafik, MIT wie der Code |
| Eigene Bilder | Nicht enthalten – vom Nutzer selbst hinterlegt |

Bitte den eigenen API-Key **nicht** im Repository oder in Foren veröffentlichen.

## Changelog

### 3.7
- „Eigene Symbole“: verständliche Erklärung und Spaltennamen im Formular

### 3.6
- Hintergrundmotiv „Zapfhahn“ neu gezeichnet: modern, schwarz-weiß mit Chrom-Akzenten

### 3.5
- Mitgeliefertes Hintergrundmotiv „Zapfhahn“ (SVG) als Standard, wählbar: Zapfhahn, eigenes Bild oder kein Bild
- Standard-Abdunklung 25 %

### 3.4
- Handy/schmale Kacheln: kein Überlauf mehr am rechten Rand, Adresse bis zu zwei Zeilen, Legende bricht sauber um
- Fußzeile einzeilig mit kurzer Quellenangabe auf schmalen Kacheln

### 3.3
- Kachel zeigt immer, wann zuletzt aktualisiert wurde (Uhrzeit, Alter, Warnung bei veralteten Daten)
- Formular und Doku neutral formuliert

### 3.2
- Hintergrundbild für die Kachel (Medienobjekt) mit Abdunkeln und Unschärfe
- Eigene Bilder für die Liste per „Name → Medienobjekt“
- Bilder werden als Referenz registriert, nur bekannte Bildformate, Größenlimits

### 3.1
- Kachel: Seitenabstand bündig zum Titel, Symcon-Schrift (Poppins) im Kachelrahmen, Trend direkt am Preis
- Breite Kachel: Kennzahlen Ø im Umkreis, Ø Deutschland, teuerste Station, letzte Preisänderung
- Preisspanne: Markierungen an den Enden nicht mehr abgeschnitten

### 3.0
- API v4: alle Sorten, die Tankerkönig liefert (inkl. Super Plus, LPG, CNG), Preistrend, Öffnungszeiten
- Automatischer Rückfall auf API v1
- Bundesdurchschnitt mit Ersparnis-Variable
- Meldefunktion für falsche Daten (`TANK_ReportError`)
- Kachel neu gestaltet: Segment-Schalter, Preistafel-Optik, Preisspanne, Kürzel, breite Ansicht
- Ratenlimit und zufälliger Zeitversatz nach Tankerkönig-Vorgaben

### 2.0
- Alle Kraftstoffe per Klick an-/abwählbar, Umschalten in Kachel ohne Wartezeit
- Ein API-Aufruf für alle Kraftstoffe (`type=all`), Zwischenspeicher
- Bestpreis je Kraftstoff als eigene Variable
- Darstellungen für Symcon 8.0/9.0, Rückfall auf Profile
- Kachel nach Symcon-Kachelschema mit Symcon-Icons
- Aktiv-Schalter, Standort aus Symcon (Location Control), Karte oder PLZ
- Sicherheit: Maskierung, Eingabeprüfung, Klick-Schutz, Sperre, nur HTTPS

### 1.0
- Erste Version als Modul (Ablösung des bisherigen Skripts)
