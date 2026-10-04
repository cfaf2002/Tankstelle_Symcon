# Tankstellen

[![IP-Symcon](https://img.shields.io/badge/IP--Symcon-7.0%2B-blue.svg)](https://www.symcon.de)
[![Version](https://img.shields.io/badge/Version-1.0-green.svg)](#changelog)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777bb4.svg)](https://www.php.net)
[![Lizenz](https://img.shields.io/badge/Lizenz-MIT-lightgrey.svg)](LICENSE)
[![Daten](https://img.shields.io/badge/Daten-Tankerk%C3%B6nig%20CC%20BY%204.0-orange.svg)](https://creativecommons.tankerkoenig.de)

IP-Symcon-Modul, das aktuelle Spritpreise (Super E5, Super E10, Diesel) aller Tankstellen im Umkreis über die
[Tankerkönig-API](https://creativecommons.tankerkoenig.de) abruft – mit eigener Kachel für die Kachel-Visualisierung,
optionaler HTML-Box und Preisalarm.

## Inhalt

1. [Funktionen](#funktionen)
2. [Voraussetzungen](#voraussetzungen)
3. [Installation](#installation)
4. [Konfiguration](#konfiguration)
5. [Variablen](#variablen)
6. [Kachel](#kachel)
7. [PHP-Befehle](#php-befehle)
8. [Hinweise](#hinweise)
9. [Changelog](#changelog)

## Funktionen

- Preise für **Super E5, Super E10 und Diesel** im Umkreis von 1–25 km
- Standort direkt aus dem **Symcon-Standort** (Location Control), alternativ per **Karte** oder **Postleitzahl** (OpenStreetMap/Nominatim, Ergebnis wird zwischengespeichert)
- **Aktiv-Schalter** zum Pausieren der Instanz ohne Löschen
- Kraftstoff umschaltbar über eine Variable mit Aktion – oder direkt in der Kachel
- Günstigste Tankstelle als eigene Variablen (Preis, Name, Adresse, Entfernung) – archivierbar für Preisverläufe
- Durchschnitts- und Höchstpreis, Anzahl Tankstellen
- **Moderne Kachel** mit Kraftstoff-Umschalter, Top-Tankstelle, Statistik und Liste; passt sich der Kachelgröße an
- Optionale **HTML-Box** für WebFront und ältere Visualisierungen
- Optionaler **Preisalarm** (Variable wird `true`, sobald der günstigste Preis unter einer Schwelle liegt)
- Alle Extras in der Instanz zuschaltbar

## Voraussetzungen

- IP-Symcon ab Version 7.0
- Kostenloser Tankerkönig-API-Key: <https://creativecommons.tankerkoenig.de>

## Installation

Über das **Module Control** folgende URL hinzufügen:

```
https://github.com/cfaf2002/Tankstelle_Symcon
```

Danach eine neue Instanz **Tankstellen** anlegen.

## Konfiguration

| Einstellung | Beschreibung |
|---|---|
| Aktiv | Schaltet Abfrage und Timer ein bzw. aus (Status „inaktiv“) |
| Tankerkönig API-Key | Persönlicher Schlüssel (wird verdeckt gespeichert) |
| Standortquelle | **Symcon-Standort** (Standard): Breiten- und Längengrad aus Kern-Instanzen → Location Control, Änderungen dort greifen automatisch<br>**Eigener Standort**: Punkt auf der Karte wählen<br>**Postleitzahl**: Koordinaten werden über OpenStreetMap ermittelt |
| Postleitzahl | Nur bei Quelle „Postleitzahl“; der Button prüft die PLZ |
| Suchradius | 1–25 km (Grenze der API) |
| Standard-Kraftstoff | Startwert der Variable *Kraftstoff* |
| Aktualisierungsintervall | Mindestens 5 Minuten (Vorgabe von Tankerkönig) |
| Sortierung der Liste | Nach Preis oder Entfernung |
| Nur geöffnete Tankstellen | Geschlossene Stationen ausblenden |
| Max. Einträge | Begrenzung für Liste in Kachel und HTML-Box (0 = alle) |
| HTML-Box-Variable | Zuschaltbar |
| Detailvariablen | Adresse, Entfernung, Ø-Preis, Höchstpreis, Anzahl |
| Preisalarm + Schwelle | Zuschaltbar, Schwelle in € |

## Variablen

| Ident | Name | Typ | Profil |
|---|---|---|---|
| FuelType | Kraftstoff | Integer (Aktion) | TANK.FuelType |
| CheapestPrice | Günstigster Preis | Float | TANK.Price |
| CheapestName | Günstigste Tankstelle | String | – |
| CheapestAddress | Adresse *(Detail)* | String | – |
| CheapestDistance | Entfernung *(Detail)* | Float | TANK.Distance |
| AveragePrice | Durchschnittspreis *(Detail)* | Float | TANK.Price |
| HighestPrice | Höchster Preis *(Detail)* | Float | TANK.Price |
| StationCount | Anzahl Tankstellen *(Detail)* | Integer | – |
| HTML | Übersicht *(optional)* | String | ~HTMLBox |
| PriceAlert | Preis unter Schwelle *(optional)* | Boolean | ~Alert.Reversed |
| LastUpdate | Letzte Aktualisierung | Integer | ~UnixTimestamp |

Tipp: *Günstigster Preis* im Archiv loggen, dann gibt es den Preisverlauf gratis dazu.

## Kachel

Die Instanz bringt eine eigene Kachel mit. Einfach die Instanz in der Kachel-Visualisierung hinzufügen.

- Oben: Umschalter E5 / E10 / Diesel und Aktualisieren-Button
- Günstigste Tankstelle groß, darunter Ø-Preis, Höchstpreis und Anzahl
- Scrollbare Liste aller Tankstellen
- Kleine Kacheln zeigen automatisch nur Preis und Tankstelle

## PHP-Befehle

```php
// Preise sofort aktualisieren (liefert true/false)
TANK_Update(int $InstanzID);

// Letzte Ergebnisliste als Array
$stations = TANK_GetStations(int $InstanzID);

// Koordinaten zu einer PLZ ermitteln (im Konfigurationsformular)
TANK_LookupPLZ(int $InstanzID, string $PLZ);

// Kraftstoff per Skript umschalten (0 = E5, 1 = E10, 2 = Diesel)
RequestAction(IPS_GetObjectIDByIdent('FuelType', $InstanzID), 2);
```

## Hinweise

- Die Preisdaten stammen von der Markttransparenzstelle für Kraftstoffe (MTS-K) und werden von Tankerkönig unter
  [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/deed.de) bereitgestellt. Der Quellenhinweis wird in Kachel und HTML-Box angezeigt.
- Tankerkönig bittet darum, nicht häufiger als alle 5 Minuten abzufragen – das Modul erzwingt dieses Minimum.
- Der API-Key wird im Debug-Fenster maskiert.

## Changelog

### 1.0
- Erste Version als Modul (Ablösung des bisherigen Skripts)

## Lizenz

[MIT](LICENSE) – © 2026 Armin Frohwerk
