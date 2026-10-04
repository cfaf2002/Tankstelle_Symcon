<?php

declare(strict_types=1);

/**
 * Tankstellen – Spritpreise in der Umgebung über die Tankerkönig-API
 *
 * Code:   MIT-Lizenz, © 2026 Armin Frohwerk – siehe LICENSE
 * Daten:  Tankerkönig / MTS-K, CC BY 4.0 (https://creativecommons.tankerkoenig.de)
 * Geo:    © OpenStreetMap-Mitwirkende, ODbL (nur bei Standortquelle „Postleitzahl“)
 *
 * @author  Armin Frohwerk
 * @license MIT
 */
class Tankstellen extends IPSModule
{
    private const API_LIST         = 'https://creativecommons.tankerkoenig.de/json/list.php';
    private const API_GEOCODE      = 'https://nominatim.openstreetmap.org/search';
    private const USER_AGENT       = 'IPSymcon-Tankstellen/2.0 (+https://github.com/cfaf2002/Tankstelle_Symcon)';
    private const LOCATION_CONTROL = '{45E97A63-F870-408A-B259-2933F7EABF74}';

    // Kraftstoffe der Markttransparenzstelle: Index = Wert der Variable "FuelType"
    private const FUELS = [
        0 => ['key' => 'e5',     'label' => 'Super E5',  'short' => 'E5',     'prop' => 'FuelE5'],
        1 => ['key' => 'e10',    'label' => 'Super E10', 'short' => 'E10',    'prop' => 'FuelE10'],
        2 => ['key' => 'diesel', 'label' => 'Diesel',    'short' => 'Diesel', 'prop' => 'FuelDiesel']
    ];

    private const MIN_INTERVAL     = 5;       // Minuten – Vorgabe Tankerkönig für automatische Abfragen
    private const MIN_MANUAL_GAP   = 60;      // Sekunden – Schutz vor Klick-Serien in der Kachel
    private const MAX_RADIUS       = 25;      // km – Grenze der API
    private const MAX_RESPONSE     = 2097152; // 2 MB – größere Antworten werden abgebrochen

    private const SOURCE_SYMCON = 0;
    private const SOURCE_CUSTOM = 1;
    private const SOURCE_PLZ    = 2;

    private const STATUS_NO_KEY      = 201;
    private const STATUS_BAD_KEY     = 202;
    private const STATUS_NO_LOCATION = 203;
    private const STATUS_API_ERROR   = 204;
    private const STATUS_NO_FUEL     = 205;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyBoolean('Active', true);

        // Zugang & Standort
        $this->RegisterPropertyString('APIKey', '');
        $this->RegisterPropertyInteger('LocationSource', self::SOURCE_SYMCON);
        $this->RegisterPropertyString('Location', json_encode($this->ReadSymconLocation() ?? ['latitude' => 0, 'longitude' => 0]));
        $this->RegisterPropertyString('PLZ', '');
        $this->RegisterPropertyInteger('Radius', 5);

        // Kraftstoffe
        foreach (self::FUELS as $fuel) {
            $this->RegisterPropertyBoolean($fuel['prop'], true);
        }

        // Abfrage
        $this->RegisterPropertyInteger('UpdateInterval', 15);
        $this->RegisterPropertyString('SortBy', 'price');
        $this->RegisterPropertyBoolean('OnlyOpen', true);
        $this->RegisterPropertyInteger('MaxEntries', 10);

        // Extras
        $this->RegisterPropertyBoolean('EnableHTMLBox', false);
        $this->RegisterPropertyBoolean('EnableDetails', true);
        $this->RegisterPropertyBoolean('EnableAlert', false);
        $this->RegisterPropertyFloat('AlertThreshold', 1.70);

        // Interner Speicher
        $this->RegisterAttributeString('GeoCache', '{}');
        $this->RegisterAttributeString('Cache', '{}');     // normalisierte Stationsdaten aller Kraftstoffe
        $this->RegisterAttributeInteger('LastFetch', 0);
        $this->RegisterAttributeString('LastError', '');

        $this->RegisterTimer('Update', 0, 'TANK_Update($_IPS[\'TARGET\']);');

        $this->SetVisualizationType(1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->MaintainVariables();

        if (!$this->ValidateConfig()) {
            $this->SetTimerInterval('Update', 0);
            if (IPS_GetKernelRunlevel() === KR_READY) {
                $this->Publish();
            }
            return;
        }

        $interval = max(self::MIN_INTERVAL, $this->ReadPropertyInteger('UpdateInterval'));
        $this->SetTimerInterval('Update', $interval * 60 * 1000);
        $this->SetStatus(IS_ACTIVE);

        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->Update();
        } else {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->UnregisterMessage(0, IPS_KERNELSTARTED);
            $this->ApplyChanges();
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'FuelType':
                $index = filter_var($Value, FILTER_VALIDATE_INT);
                if ($index === false || !in_array($index, $this->EnabledFuelIndexes(), true)) {
                    throw new Exception($this->Translate('Ungültige oder deaktivierte Kraftstoffart'));
                }
                $this->SetValue('FuelType', $index);
                // Kein API-Abruf nötig: alle Kraftstoffe liegen bereits im Cache
                $this->Publish();
                break;

            case 'Refresh':
                if (!$this->ReadPropertyBoolean('Active')) {
                    return;
                }
                if (time() - $this->ReadAttributeInteger('LastFetch') < self::MIN_MANUAL_GAP) {
                    $this->Publish(); // zu früh – vorhandene Daten erneut senden
                    return;
                }
                $this->Update();
                break;

            default:
                throw new Exception($this->Translate('Unbekannte Aktion'));
        }
    }

    /**
     * Preise aller Kraftstoffe mit einem einzigen API-Aufruf abrufen.
     */
    public function Update(): bool
    {
        if (!$this->ValidateConfig()) {
            $this->Publish();
            return false;
        }

        // Parallele Aufrufe (Timer + Button + Kachel) verhindern
        $lock = 'TANK_Update_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 15000)) {
            return false;
        }

        try {
            $location = $this->ResolveLocation();
            if ($location === null) {
                $this->SetStatus(self::STATUS_NO_LOCATION);
                return $this->Fail($this->Translate('Kein Standort – bitte Standort in Symcon, auf der Karte oder per PLZ festlegen.'));
            }

            $url = self::API_LIST . '?' . http_build_query([
                'lat'    => $location['lat'],
                'lng'    => $location['lon'],
                'rad'    => $this->GetRadius(),
                'sort'   => 'dist',  // bei type=all von der API vorgeschrieben
                'type'   => 'all',
                'apikey' => trim($this->ReadPropertyString('APIKey'))
            ]);

            $response = $this->HttpGet($url);
            if ($response === null) {
                $this->SetStatus(self::STATUS_API_ERROR);
                return $this->Fail($this->Translate('Tankerkönig-API nicht erreichbar.'));
            }

            $data = json_decode($response, true);
            if (!is_array($data) || ($data['ok'] ?? false) !== true || !isset($data['stations']) || !is_array($data['stations'])) {
                $message = is_array($data) && isset($data['message']) ? mb_substr((string) $data['message'], 0, 200) : $this->Translate('Ungültige Antwort');
                $this->SetStatus(stripos($message, 'apikey') !== false ? self::STATUS_BAD_KEY : self::STATUS_API_ERROR);
                return $this->Fail('Tankerkönig: ' . $message);
            }

            $this->WriteAttributeString('Cache', json_encode([
                'fetched'  => time(),
                'stations' => $this->NormalizeStations($data['stations'])
            ]));
            $this->WriteAttributeInteger('LastFetch', time());
            $this->WriteAttributeString('LastError', '');
            $this->SetStatus(IS_ACTIVE);
            $this->Publish();
            return true;
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    /**
     * Stationen eines Kraftstoffs als Array für eigene Skripte.
     * $Fuel: "e5", "e10", "diesel" oder leer für den aktuell gewählten Kraftstoff.
     */
    public function GetStations(string $Fuel = ''): array
    {
        $index = $this->SelectedFuelIndex();
        foreach (self::FUELS as $i => $f) {
            if ($f['key'] === strtolower(trim($Fuel))) {
                $index = $i;
            }
        }
        return $this->BuildFuelResult($index)['stations'];
    }

    public function LookupPLZ(string $PLZ): void
    {
        $coords = $this->Geocode(trim($PLZ));
        echo $coords === null
            ? $this->Translate('Für diese PLZ wurde kein Standort gefunden.')
            : sprintf($this->Translate('PLZ %s gefunden: %s, %s'), trim($PLZ), $coords['lat'], $coords['lon']);
    }

    public function UpdateLocationForm(int $Source): void
    {
        $this->UpdateFormField('SymconLocationInfo', 'visible', $Source === self::SOURCE_SYMCON);
        $this->UpdateFormField('Location', 'visible', $Source === self::SOURCE_CUSTOM);
        $this->UpdateFormField('PLZRow', 'visible', $Source === self::SOURCE_PLZ);
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $source = $this->ReadPropertyInteger('LocationSource');
        $symcon = $this->ReadSymconLocation();
        $info = $symcon !== null
            ? sprintf($this->Translate('Symcon-Standort: %s, %s'), round($symcon['latitude'], 5), round($symcon['longitude'], 5))
            : $this->Translate('Kein Symcon-Standort gefunden. Bitte unter Kern-Instanzen → Location Control festlegen oder eine andere Quelle wählen.');

        $this->WalkForm($form['elements'], function (array &$el) use ($source, $info) {
            switch ($el['name'] ?? '') {
                case 'SymconLocationInfo':
                    $el['caption'] = $info;
                    $el['visible'] = $source === self::SOURCE_SYMCON;
                    break;
                case 'Location':
                    $el['visible'] = $source === self::SOURCE_CUSTOM;
                    break;
                case 'PLZRow':
                    $el['visible'] = $source === self::SOURCE_PLZ;
                    break;
            }
        });
        return json_encode($form);
    }

    public function GetVisualizationTile()
    {
        $html = file_get_contents(__DIR__ . '/module.html');
        // JSON_HEX_* verhindert, dass Inhalte das <script>-Tag aufbrechen
        $payload = json_encode($this->BuildTileData(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return $html . '<script>handleMessage(' . $payload . ');</script>';
    }

    // ------------------------------------------------------------------
    // Variablen & Darstellungen
    // ------------------------------------------------------------------

    private function MaintainVariables(): void
    {
        $modern = $this->HasPresentations();
        if (!$modern) {
            $this->CreateLegacyProfiles();
        }

        $enabled = $this->EnabledFuelIndexes();

        // Auswahl des Kraftstoffs – nur aktivierte Sorten als Optionen, nebeneinander zum Antippen
        $options = [];
        foreach ($enabled as $i) {
            $options[] = ['Value' => $i, 'Caption' => self::FUELS[$i]['label'], 'IconActive' => false, 'IconValue' => '', 'Color' => -1];
        }
        $fuelPresentation = $modern
            ? ['PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION, 'ICON' => 'gas-pump', 'LAYOUT' => 1, 'DISPLAY' => 0, 'OPTIONS' => json_encode($options)]
            : 'TANK.FuelType';
        $this->MaintainVariable('FuelType', $this->Translate('Kraftstoff'), VARIABLETYPE_INTEGER, $fuelPresentation, 10, !empty($enabled));
        if (!empty($enabled)) {
            $this->EnableAction('FuelType');
            if (!in_array($this->GetValue('FuelType'), $enabled, true)) {
                $this->SetValue('FuelType', $enabled[0]);
            }
        }

        $price    = $modern ? ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' €', 'DIGITS' => 3, 'ICON' => 'euro-sign'] : 'TANK.Price';
        $distance = $modern ? ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' km', 'DIGITS' => 1, 'ICON' => 'route'] : 'TANK.Distance';
        $text     = $modern ? ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'gas-pump'] : '';
        $count    = $modern ? ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'hashtag'] : '';
        $stamp    = $modern ? ['PRESENTATION' => VARIABLE_PRESENTATION_DATE_TIME, 'DATE' => 1, 'TIME' => 1] : '~UnixTimestamp';
        $html     = $modern ? ['PRESENTATION' => VARIABLE_PRESENTATION_WEB_CONTENT, 'HTML_TYPE' => 0, 'PADDING' => false] : '~HTMLBox';
        $alert    = $modern
            ? ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'bell', 'OPTIONS' => json_encode([
                ['Value' => false, 'Caption' => $this->Translate('Nein'), 'IconActive' => false, 'IconValue' => '', 'Color' => -1],
                ['Value' => true, 'Caption' => $this->Translate('Ja'), 'IconActive' => false, 'IconValue' => '', 'Color' => 0x22B14C]
            ])]
            : '~Alert.Reversed';

        // Gewählter Kraftstoff
        $this->MaintainVariable('CheapestPrice', $this->Translate('Günstigster Preis'), VARIABLETYPE_FLOAT, $price, 20, true);
        $this->MaintainVariable('CheapestName', $this->Translate('Günstigste Tankstelle'), VARIABLETYPE_STRING, $text, 21, true);

        // Bestpreis je aktiviertem Kraftstoff – ideal fürs Archiv
        foreach (self::FUELS as $i => $f) {
            $this->MaintainVariable('Price_' . $f['key'], sprintf($this->Translate('Bestpreis %s'), $f['label']), VARIABLETYPE_FLOAT, $price, 30 + $i, in_array($i, $enabled, true));
        }

        $details = $this->ReadPropertyBoolean('EnableDetails');
        $this->MaintainVariable('CheapestAddress', $this->Translate('Adresse'), VARIABLETYPE_STRING, $text, 40, $details);
        $this->MaintainVariable('CheapestDistance', $this->Translate('Entfernung'), VARIABLETYPE_FLOAT, $distance, 41, $details);
        $this->MaintainVariable('AveragePrice', $this->Translate('Durchschnittspreis'), VARIABLETYPE_FLOAT, $price, 42, $details);
        $this->MaintainVariable('HighestPrice', $this->Translate('Höchster Preis'), VARIABLETYPE_FLOAT, $price, 43, $details);
        $this->MaintainVariable('StationCount', $this->Translate('Anzahl Tankstellen'), VARIABLETYPE_INTEGER, $count, 44, $details);

        $this->MaintainVariable('HTML', $this->Translate('Übersicht'), VARIABLETYPE_STRING, $html, 50, $this->ReadPropertyBoolean('EnableHTMLBox'));
        $this->MaintainVariable('PriceAlert', $this->Translate('Preis unter Schwelle'), VARIABLETYPE_BOOLEAN, $alert, 60, $this->ReadPropertyBoolean('EnableAlert'));
        $this->MaintainVariable('LastUpdate', $this->Translate('Letzte Aktualisierung'), VARIABLETYPE_INTEGER, $stamp, 90, true);
    }

    /** Darstellungen gibt es ab Symcon 8.0 */
    private function HasPresentations(): bool
    {
        return defined('VARIABLE_PRESENTATION_VALUE_PRESENTATION') && defined('VARIABLE_PRESENTATION_ENUMERATION')
            && defined('VARIABLE_PRESENTATION_DATE_TIME') && defined('VARIABLE_PRESENTATION_WEB_CONTENT');
    }

    private function CreateLegacyProfiles(): void
    {
        if (!IPS_VariableProfileExists('TANK.FuelType')) {
            IPS_CreateVariableProfile('TANK.FuelType', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileIcon('TANK.FuelType', 'Gauge');
            foreach (self::FUELS as $i => $f) {
                IPS_SetVariableProfileAssociation('TANK.FuelType', $i, $f['label'], '', -1);
            }
        }
        if (!IPS_VariableProfileExists('TANK.Price')) {
            IPS_CreateVariableProfile('TANK.Price', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileDigits('TANK.Price', 3);
            IPS_SetVariableProfileText('TANK.Price', '', ' €');
            IPS_SetVariableProfileIcon('TANK.Price', 'Euro');
        }
        if (!IPS_VariableProfileExists('TANK.Distance')) {
            IPS_CreateVariableProfile('TANK.Distance', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileDigits('TANK.Distance', 1);
            IPS_SetVariableProfileText('TANK.Distance', '', ' km');
            IPS_SetVariableProfileIcon('TANK.Distance', 'Distance');
        }
    }

    // ------------------------------------------------------------------
    // Daten aufbereiten & veröffentlichen
    // ------------------------------------------------------------------

    /**
     * Verteilt die gecachten Daten auf Variablen, HTML-Box und Kachel – ohne API-Aufruf.
     */
    private function Publish(): void
    {
        $selected = $this->SelectedFuelIndex();
        if ($this->ReadPropertyBoolean('Active') && $selected !== null) {
            $this->UpdateVariables($selected);
        }
        if ($this->ReadPropertyBoolean('EnableHTMLBox')) {
            $this->SetValueIfChanged('HTML', $this->RenderHTMLBox($selected));
        }
        $this->UpdateVisualizationValue(json_encode($this->BuildTileData()));
    }

    private function Fail(string $message): bool
    {
        $this->LogMessage($message, KL_WARNING);
        $this->WriteAttributeString('LastError', $message);
        $this->Publish();
        return false;
    }

    private function NormalizeStations(array $raw): array
    {
        $stations = [];
        foreach ($raw as $s) {
            if (!is_array($s)) {
                continue;
            }
            $prices = [];
            foreach (self::FUELS as $f) {
                $p = $s[$f['key']] ?? null;
                $prices[$f['key']] = is_numeric($p) && (float) $p > 0 ? round((float) $p, 3) : null;
            }
            if (count(array_filter($prices)) === 0) {
                continue;
            }
            $street = trim(($s['street'] ?? '') . ' ' . ($s['houseNumber'] ?? ''));
            $place  = trim(($s['postCode'] ?? '') . ' ' . ($s['place'] ?? ''));
            $brand  = trim((string) ($s['brand'] ?? ''));
            $name   = trim((string) ($s['name'] ?? ''));
            $stations[] = [
                'id' => mb_substr((string) ($s['id'] ?? ''), 0, 64),
                'n'  => mb_substr($brand !== '' ? $brand : $name, 0, 80),
                'a'  => mb_substr(trim($street . ', ' . $place, ' ,'), 0, 120),
                'd'  => round((float) ($s['dist'] ?? 0), 1),
                'o'  => !empty($s['isOpen']),
                'p'  => $prices
            ];
        }
        return $stations;
    }

    private function ReadCache(): array
    {
        $cache = json_decode($this->ReadAttributeString('Cache'), true);
        return is_array($cache) && isset($cache['stations']) ? $cache : ['fetched' => 0, 'stations' => []];
    }

    /**
     * Auswertung für einen Kraftstoff: gefilterte, sortierte Liste + Kennzahlen.
     */
    private function BuildFuelResult(?int $index, ?array $cache = null): array
    {
        $empty = ['stations' => [], 'cheapest' => null, 'min' => null, 'max' => null, 'avg' => null, 'count' => 0];
        if ($index === null || !isset(self::FUELS[$index])) {
            return $empty;
        }
        $key = self::FUELS[$index]['key'];
        $onlyOpen = $this->ReadPropertyBoolean('OnlyOpen');
        $cache = $cache ?? $this->ReadCache();

        $list = [];
        foreach ($cache['stations'] as $s) {
            $price = $s['p'][$key] ?? null;
            if ($price === null || ($onlyOpen && !$s['o'])) {
                continue;
            }
            $list[] = ['id' => $s['id'], 'name' => $s['n'], 'address' => $s['a'], 'distance' => $s['d'], 'isOpen' => $s['o'], 'price' => $price];
        }
        if (empty($list)) {
            return $empty;
        }

        $sorted = $list;
        usort($sorted, fn ($a, $b) => $a['price'] <=> $b['price'] ?: $a['distance'] <=> $b['distance']);
        $prices = array_column($list, 'price');

        if ($this->ReadPropertyString('SortBy') === 'dist') {
            usort($list, fn ($a, $b) => $a['distance'] <=> $b['distance']);
        } else {
            $list = $sorted;
        }
        $max = $this->ReadPropertyInteger('MaxEntries');

        return [
            'stations' => $max > 0 ? array_slice($list, 0, $max) : $list,
            'cheapest' => $sorted[0],
            'min'      => min($prices),
            'max'      => max($prices),
            'avg'      => round(array_sum($prices) / count($prices), 3),
            'count'    => count($prices)
        ];
    }

    private function UpdateVariables(int $selected): void
    {
        $cache = $this->ReadCache();
        $r = $this->BuildFuelResult($selected, $cache);
        $c = $r['cheapest'];

        $this->SetValueIfChanged('CheapestPrice', $c ? (float) $c['price'] : 0.0);
        $this->SetValueIfChanged('CheapestName', $c ? $c['name'] : '');

        foreach ($this->EnabledFuelIndexes() as $i) {
            $min = $i === $selected ? $r['min'] : $this->BuildFuelResult($i, $cache)['min'];
            $this->SetValueIfChanged('Price_' . self::FUELS[$i]['key'], (float) ($min ?? 0));
        }

        if ($this->ReadPropertyBoolean('EnableDetails')) {
            $this->SetValueIfChanged('CheapestAddress', $c ? $c['address'] : '');
            $this->SetValueIfChanged('CheapestDistance', $c ? (float) $c['distance'] : 0.0);
            $this->SetValueIfChanged('AveragePrice', (float) ($r['avg'] ?? 0));
            $this->SetValueIfChanged('HighestPrice', (float) ($r['max'] ?? 0));
            $this->SetValueIfChanged('StationCount', (int) $r['count']);
        }
        if ($this->ReadPropertyBoolean('EnableAlert')) {
            $this->SetValueIfChanged('PriceAlert', $c !== null && $c['price'] <= $this->ReadPropertyFloat('AlertThreshold'));
        }
        if ((int) $cache['fetched'] > 0) {
            $this->SetValueIfChanged('LastUpdate', (int) $cache['fetched']);
        }
    }

    /**
     * Kompakte Daten für die Kachel: alle aktivierten Kraftstoffe auf einmal,
     * damit das Umschalten in der Kachel ohne Server-Rundreise sofort erfolgt.
     */
    private function BuildTileData(): array
    {
        $cache = $this->ReadCache();
        $enabled = $this->EnabledFuelIndexes();
        $fuels = [];
        foreach ($enabled as $i) {
            $r = $this->BuildFuelResult($i, $cache);
            $fuels[] = [
                'index'    => $i,
                'short'    => self::FUELS[$i]['short'],
                'label'    => self::FUELS[$i]['label'],
                'min'      => $r['min'],
                'max'      => $r['max'],
                'avg'      => $r['avg'],
                'count'    => $r['count'],
                'cheapest' => $r['cheapest'],
                'stations' => $r['stations']
            ];
        }

        return [
            'selected'  => $this->SelectedFuelIndex(),
            'fuels'     => $fuels,
            'radius'    => $this->GetRadius(),
            'updated'   => (int) $cache['fetched'],
            'error'     => $this->CurrentError($enabled),
            'threshold' => $this->ReadPropertyBoolean('EnableAlert') ? $this->ReadPropertyFloat('AlertThreshold') : null
        ];
    }

    private function CurrentError(array $enabled): string
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return $this->Translate('Instanz ist deaktiviert.');
        }
        if (empty($enabled)) {
            return $this->Translate('Kein Kraftstoff aktiviert.');
        }
        return $this->ReadAttributeString('LastError');
    }

    private function RenderHTMLBox(?int $selected): string
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $price = function ($p) {
            if ($p === null) {
                return '–';
            }
            $s = number_format((float) $p, 3, '.', '');
            return str_replace('.', ',', substr($s, 0, -1)) . '<sup>' . substr($s, -1) . '</sup>&nbsp;€';
        };
        $km = fn ($d) => number_format((float) $d, 1, ',', '.') . '&nbsp;km';

        $cache = $this->ReadCache();
        $r = $this->BuildFuelResult($selected, $cache);
        $label = $selected !== null ? self::FUELS[$selected]['label'] : '';
        $fetched = (int) $cache['fetched'];
        $error = $this->CurrentError($this->EnabledFuelIndexes());

        $h = '<style>'
            . '.tk{font-family:inherit;color:inherit;line-height:1.4}.tk *{box-sizing:border-box}'
            . '.tk-head{display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:6px;margin-bottom:10px}'
            . '.tk-head b{font-size:18px}.tk-meta{font-size:12px;opacity:.65}'
            . '.tk-best{background:rgba(34,177,76,.15);border:1px solid rgba(34,177,76,.35);border-radius:12px;padding:12px 14px;margin-bottom:10px;display:flex;justify-content:space-between;align-items:center;gap:10px}'
            . '.tk-best .nm{font-size:16px;font-weight:700}.tk-best .ad{font-size:12px;opacity:.7}.tk-best .pr{font-size:30px;font-weight:800;color:#22b14c;white-space:nowrap}'
            . '.tk sup{font-size:.55em}.tk table{width:100%;border-collapse:collapse}'
            . '.tk td{padding:7px 4px;border-top:1px solid rgba(127,127,127,.2)}.tk .r{text-align:right;white-space:nowrap}'
            . '.tk .p{font-weight:700}.tk .a{font-size:11px;opacity:.65}.tk .best .p{color:#22b14c}.tk .closed{opacity:.45}'
            . '.tk-msg{padding:10px;border-radius:8px;background:rgba(127,127,127,.15);margin-bottom:10px}.tk-err{background:rgba(229,72,77,.15);color:#e5484d}'
            . '.tk-foot{font-size:10px;opacity:.5;text-align:right;margin-top:6px}'
            . '</style><div class="tk">';

        $h .= '<div class="tk-head"><b>⛽ ' . $e($label) . '</b><span class="tk-meta">' . $e((int) $this->GetRadius()) . ' km'
            . ($fetched > 0 ? ' · ' . date('d.m.Y H:i', $fetched) : '') . '</span></div>';

        if ($error !== '') {
            $h .= '<div class="tk-msg tk-err">' . $e($error) . '</div>';
        }

        if ($r['cheapest'] !== null) {
            $c = $r['cheapest'];
            $h .= '<div class="tk-best"><div><div class="nm">' . $e($c['name']) . '</div><div class="ad">' . $e($c['address']) . ' · ' . $km($c['distance']) . '</div></div>'
                . '<div class="pr">' . $price($c['price']) . '</div></div><table>';
            foreach ($r['stations'] as $i => $s) {
                $cls = trim(($s['id'] === $c['id'] ? 'best ' : '') . ($s['isOpen'] ? '' : 'closed'));
                $h .= '<tr' . ($cls !== '' ? ' class="' . $cls . '"' : '') . '><td>' . ($i + 1) . '</td><td><b>' . $e($s['name']) . '</b><div class="a">' . $e($s['address']) . '</div></td>'
                    . '<td class="r p">' . $price($s['price']) . '</td><td class="r">' . $km($s['distance']) . '</td></tr>';
            }
            $h .= '</table>';
        } elseif ($error === '') {
            $h .= '<div class="tk-msg">' . $e($this->Translate('Keine passenden Tankstellen gefunden.')) . '</div>';
        }

        return $h . '<div class="tk-foot">Tankerkönig / MTS-K · CC BY 4.0</div></div>';
    }

    // ------------------------------------------------------------------
    // Hilfsfunktionen
    // ------------------------------------------------------------------

    private function EnabledFuelIndexes(): array
    {
        $list = [];
        foreach (self::FUELS as $i => $f) {
            if ($this->ReadPropertyBoolean($f['prop'])) {
                $list[] = $i;
            }
        }
        return $list;
    }

    private function SelectedFuelIndex(): ?int
    {
        $enabled = $this->EnabledFuelIndexes();
        if (empty($enabled)) {
            return null;
        }
        $value = @$this->GetIDForIdent('FuelType') ? $this->GetValue('FuelType') : null;
        return in_array($value, $enabled, true) ? $value : $enabled[0];
    }

    private function ValidateConfig(): bool
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetStatus(IS_INACTIVE);
            return false;
        }
        $key = trim($this->ReadPropertyString('APIKey'));
        if ($key === '') {
            $this->SetStatus(self::STATUS_NO_KEY);
            return false;
        }
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $key)) {
            $this->SetStatus(self::STATUS_BAD_KEY);
            return false;
        }
        if (empty($this->EnabledFuelIndexes())) {
            $this->SetStatus(self::STATUS_NO_FUEL);
            return false;
        }
        return true;
    }

    private function GetRadius(): float
    {
        return (float) min(self::MAX_RADIUS, max(1, $this->ReadPropertyInteger('Radius')));
    }

    private function ResolveLocation(): ?array
    {
        switch ($this->ReadPropertyInteger('LocationSource')) {
            case self::SOURCE_SYMCON:
                $loc = $this->ReadSymconLocation();
                break;
            case self::SOURCE_CUSTOM:
                $loc = $this->ParseLocation($this->ReadPropertyString('Location'));
                break;
            case self::SOURCE_PLZ:
                return $this->ResolvePLZ(trim($this->ReadPropertyString('PLZ')));
            default:
                return null;
        }
        return $loc !== null ? ['lat' => $loc['latitude'], 'lon' => $loc['longitude']] : null;
    }

    /** Standort aus der Kern-Instanz „Location Control“ */
    private function ReadSymconLocation(): ?array
    {
        if (!function_exists('IPS_GetInstanceListByModuleID')) {
            return null;
        }
        foreach (IPS_GetInstanceListByModuleID(self::LOCATION_CONTROL) as $id) {
            $loc = $this->ParseLocation((string) @IPS_GetProperty($id, 'Location'));
            if ($loc !== null) {
                return $loc;
            }
        }
        return null;
    }

    private function ParseLocation(string $json): ?array
    {
        $d = json_decode($json, true);
        if (!is_array($d) || !isset($d['latitude'], $d['longitude']) || !is_numeric($d['latitude']) || !is_numeric($d['longitude'])) {
            return null;
        }
        $lat = (float) $d['latitude'];
        $lon = (float) $d['longitude'];
        if (($lat == 0.0 && $lon == 0.0) || abs($lat) > 90 || abs($lon) > 180) {
            return null;
        }
        return ['latitude' => $lat, 'longitude' => $lon];
    }

    private function ResolvePLZ(string $plz): ?array
    {
        if ($plz === '') {
            return null;
        }
        $cache = json_decode($this->ReadAttributeString('GeoCache'), true) ?: [];
        if (isset($cache[$plz]['lat'], $cache[$plz]['lon'])) {
            return ['lat' => (float) $cache[$plz]['lat'], 'lon' => (float) $cache[$plz]['lon']];
        }
        $coords = $this->Geocode($plz);
        if ($coords !== null) {
            $this->WriteAttributeString('GeoCache', json_encode([$plz => $coords]));
        }
        return $coords;
    }

    private function Geocode(string $plz): ?array
    {
        if (!preg_match('/^\d{5}$/', $plz)) {
            return null;
        }
        $response = $this->HttpGet(self::API_GEOCODE . '?' . http_build_query([
            'postalcode' => $plz,
            'country'    => 'Germany',
            'format'     => 'json',
            'limit'      => 1
        ]));
        $data = $response !== null ? json_decode($response, true) : null;
        if (!is_array($data) || !isset($data[0]['lat'], $data[0]['lon']) || !is_numeric($data[0]['lat']) || !is_numeric($data[0]['lon'])) {
            $this->LogMessage(sprintf('Geocoding fehlgeschlagen: PLZ %s', $plz), KL_WARNING);
            return null;
        }
        return ['lat' => round((float) $data[0]['lat'], 5), 'lon' => round((float) $data[0]['lon'], 5)];
    }

    private function HttpGet(string $url): ?string
    {
        // API-Key nie im Klartext ins Debug schreiben
        $this->SendDebug('GET', preg_replace('/apikey=[^&]+/', 'apikey=***', $url), 0);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER   => true,
            CURLOPT_CONNECTTIMEOUT   => 5,
            CURLOPT_TIMEOUT          => 10,
            CURLOPT_USERAGENT        => self::USER_AGENT,
            CURLOPT_FOLLOWLOCATION   => false,
            CURLOPT_SSL_VERIFYPEER   => true,
            CURLOPT_SSL_VERIFYHOST   => 2,
            CURLOPT_PROTOCOLS        => CURLPROTO_HTTPS,
            CURLOPT_ENCODING         => '',   // gzip/deflate annehmen – spart Bandbreite
            CURLOPT_HTTPHEADER       => ['Accept: application/json'],
            CURLOPT_NOPROGRESS       => false,
            CURLOPT_PROGRESSFUNCTION => fn ($ch, $dlTotal, $dlNow) => $dlNow > self::MAX_RESPONSE ? 1 : 0
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if (!is_string($body) || $body === '') {
            $this->SendDebug('HTTP-Fehler', sprintf('Code %d %s', $code, $err), 0);
            return null;
        }
        $this->SendDebug('Antwort ' . $code, mb_substr($body, 0, 2000), 0);
        // Tankerkönig liefert Fehlermeldungen als JSON auch bei 4xx – Auswertung übernimmt Update()
        return ($code >= 200 && $code < 500) ? $body : null;
    }

    private function SetValueIfChanged(string $ident, $value): void
    {
        if (!@$this->GetIDForIdent($ident)) {
            return;
        }
        $current = $this->GetValue($ident);
        $same = is_float($value) ? abs((float) $current - $value) < 0.0001 : $current === $value;
        if (!$same) {
            $this->SetValue($ident, $value);
        }
    }

    private function WalkForm(array &$elements, callable $fn): void
    {
        foreach ($elements as &$el) {
            $fn($el);
            if (isset($el['items']) && is_array($el['items'])) {
                $this->WalkForm($el['items'], $fn);
            }
        }
        unset($el);
    }
}
