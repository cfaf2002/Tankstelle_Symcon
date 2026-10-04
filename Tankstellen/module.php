<?php

declare(strict_types=1);

/**
 * Tankstellen – Spritpreise in der Umgebung über die Tankerkönig-API
 *
 * @author  Armin Frohwerk
 * @license MIT
 */
class Tankstellen extends IPSModule
{
    private const API_LIST      = 'https://creativecommons.tankerkoenig.de/json/list.php';
    private const API_GEOCODE   = 'https://nominatim.openstreetmap.org/search';
    private const USER_AGENT    = 'IPSymcon-Tankstellen/1.0 (+https://github.com/cfaf2002/Tankstelle_Symcon)';
    private const FUEL_TYPES    = [0 => 'e5', 1 => 'e10', 2 => 'diesel'];
    private const FUEL_LABELS   = [0 => 'Super E5', 1 => 'Super E10', 2 => 'Diesel'];
    private const MIN_INTERVAL  = 5;   // Tankerkönig: nicht öfter als alle 5 Minuten abfragen
    private const MAX_RADIUS    = 25;  // Tankerkönig: max. 25 km
    private const LOCATION_CONTROL = '{45E97A63-F870-408A-B259-2933F7EABF74}'; // Symcon Location Control

    // Standortquellen
    private const SOURCE_SYMCON = 0;   // Standort aus Symcon (Location Control)
    private const SOURCE_CUSTOM = 1;   // eigener Standort per Karte
    private const SOURCE_PLZ    = 2;   // Postleitzahl

    // Status-Codes
    private const STATUS_NO_KEY        = 201;
    private const STATUS_BAD_KEY       = 202;
    private const STATUS_NO_LOCATION   = 203;
    private const STATUS_API_ERROR     = 204;

    public function Create()
    {
        parent::Create();

        // Aktiv-Schalter
        $this->RegisterPropertyBoolean('Active', true);

        // Zugang & Standort
        $this->RegisterPropertyString('APIKey', '');
        $this->RegisterPropertyInteger('LocationSource', self::SOURCE_SYMCON);
        $this->RegisterPropertyString('Location', json_encode($this->ReadSymconLocation() ?? ['latitude' => 0, 'longitude' => 0]));
        $this->RegisterPropertyString('PLZ', '');
        $this->RegisterPropertyInteger('Radius', 5);

        // Abfrage
        $this->RegisterPropertyInteger('DefaultFuel', 0);
        $this->RegisterPropertyInteger('UpdateInterval', 15);
        $this->RegisterPropertyString('SortBy', 'price');
        $this->RegisterPropertyBoolean('OnlyOpen', true);
        $this->RegisterPropertyInteger('MaxEntries', 10);

        // Extras
        $this->RegisterPropertyBoolean('EnableHTMLBox', true);
        $this->RegisterPropertyBoolean('EnableDetails', true);
        $this->RegisterPropertyBoolean('EnableAlert', false);
        $this->RegisterPropertyFloat('AlertThreshold', 1.70);

        // Interne Daten
        $this->RegisterAttributeString('GeoCache', '{}');
        $this->RegisterAttributeString('LastResult', '{}');

        $this->RegisterTimer('Update', 0, 'TANK_Update($_IPS[\'TARGET\']);');

        // Kachel-Visualisierung
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->CreateProfiles();

        // Kernvariablen
        $this->RegisterVariableInteger('FuelType', $this->Translate('Kraftstoff'), 'TANK.FuelType', 10);
        $this->EnableAction('FuelType');
        $this->RegisterVariableFloat('CheapestPrice', $this->Translate('Günstigster Preis'), 'TANK.Price', 20);
        $this->RegisterVariableString('CheapestName', $this->Translate('Günstigste Tankstelle'), '', 30);
        $this->RegisterVariableInteger('LastUpdate', $this->Translate('Letzte Aktualisierung'), '~UnixTimestamp', 90);

        // Zuschaltbare Details
        $details = $this->ReadPropertyBoolean('EnableDetails');
        $this->MaintainVariable('CheapestAddress', $this->Translate('Adresse'), VARIABLETYPE_STRING, '', 31, $details);
        $this->MaintainVariable('CheapestDistance', $this->Translate('Entfernung'), VARIABLETYPE_FLOAT, 'TANK.Distance', 32, $details);
        $this->MaintainVariable('AveragePrice', $this->Translate('Durchschnittspreis'), VARIABLETYPE_FLOAT, 'TANK.Price', 40, $details);
        $this->MaintainVariable('HighestPrice', $this->Translate('Höchster Preis'), VARIABLETYPE_FLOAT, 'TANK.Price', 41, $details);
        $this->MaintainVariable('StationCount', $this->Translate('Anzahl Tankstellen'), VARIABLETYPE_INTEGER, '', 42, $details);

        // Zuschaltbare HTML-Box
        $this->MaintainVariable('HTML', $this->Translate('Übersicht'), VARIABLETYPE_STRING, '~HTMLBox', 50, $this->ReadPropertyBoolean('EnableHTMLBox'));

        // Zuschaltbarer Preisalarm
        $this->MaintainVariable('PriceAlert', $this->Translate('Preis unter Schwelle'), VARIABLETYPE_BOOLEAN, '~Alert.Reversed', 60, $this->ReadPropertyBoolean('EnableAlert'));

        // Standardkraftstoff setzen, falls Variable frisch angelegt
        $fuelID = $this->GetIDForIdent('FuelType');
        if (IPS_GetVariable($fuelID)['VariableUpdated'] === 0) {
            $this->SetValue('FuelType', $this->ReadPropertyInteger('DefaultFuel'));
        }

        // Aktiv-Schalter und Validierung
        if (!$this->ValidateConfig()) {
            $this->SetTimerInterval('Update', 0);
            if (!$this->ReadPropertyBoolean('Active') && IPS_GetKernelRunlevel() === KR_READY) {
                $this->RenderAll($this->BuildResult([], $this->Translate('Instanz ist deaktiviert.')));
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
                $value = (int) $Value;
                if (!array_key_exists($value, self::FUEL_TYPES)) {
                    throw new Exception($this->Translate('Ungültige Kraftstoffart'));
                }
                $this->SetValue('FuelType', $value);
                if ($this->ReadPropertyBoolean('Active')) {
                    $this->Update();
                }
                break;

            case 'TileFuel':
                // Kraftstoffwechsel direkt aus der Kachel
                $this->RequestAction('FuelType', $Value);
                break;

            case 'TileRefresh':
                $this->Update();
                break;

            default:
                throw new Exception(sprintf($this->Translate('Unbekannter Ident: %s'), $Ident));
        }
    }

    /**
     * Preise abrufen und Variablen, HTML-Box und Kachel aktualisieren.
     */
    public function Update(): bool
    {
        if (!$this->ValidateConfig()) {
            return false;
        }

        $location = $this->ResolveLocation();
        if ($location === null) {
            $this->SetStatus(self::STATUS_NO_LOCATION);
            $this->RenderAll($this->BuildResult([], $this->Translate('Kein Standort – bitte Standort in Symcon, auf der Karte oder per PLZ festlegen.')));
            return false;
        }

        $fuelIndex = $this->GetValue('FuelType');
        $fuel = self::FUEL_TYPES[$fuelIndex] ?? 'e5';
        $sortBy = $this->ReadPropertyString('SortBy') === 'dist' ? 'dist' : 'price';

        $url = self::API_LIST . '?' . http_build_query([
            'lat'    => $location['lat'],
            'lng'    => $location['lon'],
            'rad'    => $this->GetRadius(),
            'sort'   => $sortBy,
            'type'   => $fuel,
            'apikey' => trim($this->ReadPropertyString('APIKey'))
        ]);

        $response = $this->HttpGet($url);
        if ($response === null) {
            $this->SetStatus(self::STATUS_API_ERROR);
            $this->RenderAll($this->BuildResult([], $this->Translate('Tankerkönig-API nicht erreichbar.')));
            return false;
        }

        $data = json_decode($response, true);
        if (!is_array($data) || ($data['ok'] ?? false) !== true || !isset($data['stations']) || !is_array($data['stations'])) {
            $message = is_array($data) && isset($data['message']) ? (string) $data['message'] : $this->Translate('Ungültige Antwort');
            $this->LogMessage('Tankerkönig: ' . $message, KL_WARNING);
            $this->SendDebug('API-Fehler', $response, 0);
            $this->SetStatus(stripos($message, 'apikey') !== false ? self::STATUS_BAD_KEY : self::STATUS_API_ERROR);
            $this->RenderAll($this->BuildResult([], 'Tankerkönig: ' . $message));
            return false;
        }

        $onlyOpen = $this->ReadPropertyBoolean('OnlyOpen');
        $stations = [];
        foreach ($data['stations'] as $s) {
            if ($onlyOpen && empty($s['isOpen'])) {
                continue;
            }
            if (!isset($s['price']) || !is_numeric($s['price']) || (float) $s['price'] <= 0) {
                continue; // Station führt diesen Kraftstoff nicht oder hat keinen Preis
            }
            $street = trim(($s['street'] ?? '') . ' ' . ($s['houseNumber'] ?? ''));
            $place  = trim(($s['postCode'] ?? '') . ' ' . ($s['place'] ?? ''));
            $stations[] = [
                'id'       => (string) ($s['id'] ?? ''),
                'name'     => trim((string) ($s['name'] ?? '')),
                'brand'    => trim((string) ($s['brand'] ?? '')),
                'price'    => (float) $s['price'],
                'address'  => trim($street . ', ' . $place, ' ,'),
                'distance' => (float) ($s['dist'] ?? 0),
                'isOpen'   => !empty($s['isOpen']),
                'lat'      => (float) ($s['lat'] ?? 0),
                'lng'      => (float) ($s['lng'] ?? 0)
            ];
        }

        $result = $this->BuildResult($stations, '');
        $this->WriteAttributeString('LastResult', json_encode($result));
        $this->UpdateVariables($result);
        $this->RenderAll($result);
        $this->SetStatus(IS_ACTIVE);

        return true;
    }

    /**
     * Liefert die zuletzt abgerufenen Daten als Array (z. B. für eigene Skripte).
     */
    public function GetStations(): array
    {
        $result = json_decode($this->ReadAttributeString('LastResult'), true);
        return is_array($result) ? ($result['stations'] ?? []) : [];
    }

    /**
     * Prüft eine PLZ und zeigt die gefundenen Koordinaten an (Button im Formular).
     */
    public function LookupPLZ(string $PLZ): void
    {
        $coords = $this->Geocode(trim($PLZ));
        if ($coords === null) {
            echo $this->Translate('Für diese PLZ wurde kein Standort gefunden.');
            return;
        }
        echo sprintf($this->Translate('PLZ %s gefunden: %s, %s'), trim($PLZ), $coords['lat'], $coords['lon']);
    }

    /**
     * Blendet je nach Standortquelle die passenden Felder im Formular ein.
     */
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
        $result = json_decode($this->ReadAttributeString('LastResult'), true);
        if (!is_array($result) || empty($result)) {
            $result = $this->BuildResult([], $this->Translate('Noch keine Daten – Aktualisierung läuft …'));
        }
        return $html . '<script>handleMessage(' . json_encode(json_encode($result)) . ');</script>';
    }

    // ------------------------------------------------------------------
    // Intern
    // ------------------------------------------------------------------

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
                return $loc !== null ? ['lat' => $loc['latitude'], 'lon' => $loc['longitude']] : null;

            case self::SOURCE_CUSTOM:
                $loc = $this->ParseLocation($this->ReadPropertyString('Location'));
                return $loc !== null ? ['lat' => $loc['latitude'], 'lon' => $loc['longitude']] : null;

            case self::SOURCE_PLZ:
                return $this->ResolvePLZ(trim($this->ReadPropertyString('PLZ')));
        }
        return null;
    }

    /**
     * Liest den in Symcon hinterlegten Standort (Kern-Instanz „Location Control“).
     */
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

    /**
     * Wandelt das Symcon-Standortformat {"latitude":…,"longitude":…} in ein Array um.
     */
    private function ParseLocation(string $json): ?array
    {
        $d = json_decode($json, true);
        if (!is_array($d) || !isset($d['latitude'], $d['longitude'])) {
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

        // Geocoding-Ergebnis zwischenspeichern, Nominatim nicht bei jedem Update fragen
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

    private function Geocode(string $plz): ?array
    {
        if (!preg_match('/^\d{5}$/', $plz)) {
            $this->LogMessage(sprintf('Ungültige PLZ: %s', $plz), KL_WARNING);
            return null;
        }
        $url = self::API_GEOCODE . '?' . http_build_query([
            'postalcode' => $plz,
            'country'    => 'Germany',
            'format'     => 'json',
            'limit'      => 1
        ]);
        $response = $this->HttpGet($url);
        $data = $response !== null ? json_decode($response, true) : null;
        if (!is_array($data) || empty($data[0]['lat'])) {
            $this->LogMessage(sprintf('Geocoding fehlgeschlagen: PLZ %s', $plz), KL_WARNING);
            return null;
        }
        return ['lat' => round((float) $data[0]['lat'], 5), 'lon' => round((float) $data[0]['lon'], 5)];
    }

    private function HttpGet(string $url): ?string
    {
        $this->SendDebug('GET', preg_replace('/apikey=[^&]+/', 'apikey=***', $url), 0);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false || $code < 200 || $code >= 300) {
            $this->SendDebug('HTTP-Fehler', sprintf('Code %d %s', $code, $err), 0);
            $this->LogMessage(sprintf('HTTP-Fehler %d: %s', $code, $err), KL_WARNING);
            return is_string($body) && $body !== '' ? $body : null;
        }
        $this->SendDebug('Antwort', (string) $body, 0);
        return (string) $body;
    }

    private function BuildResult(array $stations, string $error): array
    {
        $fuelIndex = @$this->GetValue('FuelType');
        $fuelIndex = is_int($fuelIndex) && isset(self::FUEL_LABELS[$fuelIndex]) ? $fuelIndex : 0;

        $prices = array_column($stations, 'price');
        $cheapest = null;
        if (!empty($stations)) {
            $sorted = $stations;
            usort($sorted, fn ($a, $b) => $a['price'] <=> $b['price'] ?: $a['distance'] <=> $b['distance']);
            $cheapest = $sorted[0];
        }

        $display = $stations;
        if ($this->ReadPropertyString('SortBy') !== 'dist') {
            usort($display, fn ($a, $b) => $a['price'] <=> $b['price'] ?: $a['distance'] <=> $b['distance']);
        } else {
            usort($display, fn ($a, $b) => $a['distance'] <=> $b['distance']);
        }
        $max = $this->ReadPropertyInteger('MaxEntries');
        if ($max > 0) {
            $display = array_slice($display, 0, $max);
        }

        return [
            'fuelIndex' => $fuelIndex,
            'fuel'      => self::FUEL_LABELS[$fuelIndex],
            'radius'    => $this->GetRadius(),
            'updated'   => time(),
            'error'     => $error,
            'cheapest'  => $cheapest,
            'min'       => empty($prices) ? null : min($prices),
            'max'       => empty($prices) ? null : max($prices),
            'avg'       => empty($prices) ? null : round(array_sum($prices) / count($prices), 3),
            'count'     => count($stations),
            'threshold' => $this->ReadPropertyBoolean('EnableAlert') ? $this->ReadPropertyFloat('AlertThreshold') : null,
            'stations'  => $display
        ];
    }

    private function UpdateVariables(array $r): void
    {
        $c = $r['cheapest'];
        $this->SetValueIfChanged('CheapestPrice', $c ? (float) $c['price'] : 0.0);
        $this->SetValueIfChanged('CheapestName', $c ? ($c['brand'] !== '' && stripos($c['name'], $c['brand']) === false ? $c['brand'] . ' – ' . $c['name'] : $c['name']) : '');

        if ($this->ReadPropertyBoolean('EnableDetails')) {
            $this->SetValueIfChanged('CheapestAddress', $c ? $c['address'] : '');
            $this->SetValueIfChanged('CheapestDistance', $c ? (float) $c['distance'] : 0.0);
            $this->SetValueIfChanged('AveragePrice', (float) ($r['avg'] ?? 0));
            $this->SetValueIfChanged('HighestPrice', (float) ($r['max'] ?? 0));
            $this->SetValueIfChanged('StationCount', (int) $r['count']);
        }

        if ($this->ReadPropertyBoolean('EnableAlert')) {
            $below = $c !== null && $c['price'] <= $this->ReadPropertyFloat('AlertThreshold');
            $this->SetValueIfChanged('PriceAlert', $below);
        }

        $this->SetValue('LastUpdate', time());
    }

    private function SetValueIfChanged(string $ident, $value): void
    {
        if (@$this->GetIDForIdent($ident) && $this->GetValue($ident) !== $value) {
            $this->SetValue($ident, $value);
        }
    }

    private function RenderAll(array $result): void
    {
        if ($this->ReadPropertyBoolean('EnableHTMLBox') && @$this->GetIDForIdent('HTML')) {
            $this->SetValue('HTML', $this->RenderHTMLBox($result));
        }
        $this->UpdateVisualizationValue(json_encode($result));
    }

    private function RenderHTMLBox(array $r): string
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        // Tankstellen-Optik: 1,75⁹ € (dritte Stelle hochgestellt, nicht gerundet)
        $price = function ($p) {
            $s = number_format((float) $p, 3, '.', '');
            return str_replace('.', ',', substr($s, 0, -1)) . '<sup>' . substr($s, -1) . '</sup>&nbsp;€';
        };
        $km = fn ($d) => number_format((float) $d, 1, ',', '.') . '&nbsp;km';

        $css = <<<'CSS'
<style>
.tk{font-family:"Segoe UI",system-ui,Arial,sans-serif;color:#f3f3f3;line-height:1.4}
.tk *{box-sizing:border-box}
.tk-head{display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:6px;margin-bottom:12px}
.tk-head h2{margin:0;font-size:20px}
.tk-meta{font-size:12px;opacity:.7}
.tk-best{background:linear-gradient(135deg,#107c10,#18a018);border-radius:12px;padding:16px 18px;margin-bottom:14px}
.tk-best .lbl{font-size:12px;opacity:.85;text-transform:uppercase;letter-spacing:.5px}
.tk-best .nm{font-size:20px;font-weight:700;margin-top:2px}
.tk-best .pr{font-size:34px;font-weight:700;margin:6px 0}
.tk-best .dt{font-size:13px;opacity:.9}
.tk sup{font-size:.6em}
.tk-stats{display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap}
.tk-stat{flex:1;min-width:90px;background:rgba(255,255,255,.06);border-radius:10px;padding:8px 10px}
.tk-stat .v{font-size:16px;font-weight:700}.tk-stat .l{font-size:11px;opacity:.7}
.tk table{width:100%;border-collapse:collapse;background:rgba(255,255,255,.04);border-radius:12px;overflow:hidden}
.tk th{font-size:11px;text-transform:uppercase;letter-spacing:.5px;text-align:left;padding:9px 10px;background:rgba(255,255,255,.08)}
.tk td{padding:9px 10px;border-top:1px solid rgba(255,255,255,.07);vertical-align:top}
.tk tr.best td{background:rgba(16,124,16,.25)}
.tk .r{text-align:right;white-space:nowrap}.tk .rk{width:28px;opacity:.6;font-weight:700}
.tk .p{color:#5bd65b;font-weight:700;font-size:15px}
.tk .a{font-size:12px;opacity:.7}
.tk .closed{opacity:.45}
.tk-err{background:#5c1f1f;color:#ffd6d6;padding:12px;border-radius:8px;border-left:4px solid #ff4d4d;margin-bottom:12px}
.tk-empty{background:rgba(255,255,255,.06);padding:12px;border-radius:8px;text-align:center}
.tk-foot{font-size:10px;opacity:.5;margin-top:8px;text-align:right}
@media(max-width:560px){.tk .hide-s{display:none}}
</style>
CSS;

        $h = $css . '<div class="tk">';
        $h .= '<div class="tk-head"><h2>⛽ ' . $e($r['fuel']) . '</h2><div class="tk-meta">'
            . $e($this->Translate('Umkreis')) . ' ' . $e((int) $r['radius']) . ' km · ' . date('d.m.Y H:i', (int) $r['updated']) . '</div></div>';

        if ($r['error'] !== '') {
            $h .= '<div class="tk-err">' . $e($r['error']) . '</div>';
        }

        if ($r['cheapest'] !== null) {
            $c = $r['cheapest'];
            $h .= '<div class="tk-best"><div class="lbl">🏆 ' . $e($this->Translate('Günstigste Tankstelle')) . '</div>'
                . '<div class="nm">' . $e($c['brand'] !== '' ? $c['brand'] : $c['name']) . '</div>'
                . '<div class="pr">' . $price($c['price']) . '</div>'
                . '<div class="dt">' . $e($c['address']) . ' · ' . $km($c['distance']) . '</div></div>';

            $h .= '<div class="tk-stats">'
                . '<div class="tk-stat"><div class="v">' . $price($r['avg']) . '</div><div class="l">Ø ' . $e($this->Translate('Preis')) . '</div></div>'
                . '<div class="tk-stat"><div class="v">' . $price($r['max']) . '</div><div class="l">' . $e($this->Translate('Teuerste')) . '</div></div>'
                . '<div class="tk-stat"><div class="v">' . $e($r['count']) . '</div><div class="l">' . $e($this->Translate('Tankstellen')) . '</div></div>'
                . '</div>';

            $h .= '<table><tr><th>#</th><th>' . $e($this->Translate('Tankstelle')) . '</th><th class="r">'
                . $e($this->Translate('Preis')) . '</th><th class="r hide-s">' . $e($this->Translate('Entfernung')) . '</th></tr>';
            foreach ($r['stations'] as $i => $s) {
                $isBest = $s['id'] === $c['id'];
                $cls = trim(($isBest ? 'best ' : '') . ($s['isOpen'] ? '' : 'closed'));
                $title = $s['brand'] !== '' ? $s['brand'] : $s['name'];
                $h .= '<tr' . ($cls !== '' ? ' class="' . $cls . '"' : '') . '>'
                    . '<td class="rk">' . ($i + 1) . '</td>'
                    . '<td><b>' . $e($title) . '</b><div class="a">' . $e($s['address']) . '</div></td>'
                    . '<td class="r p">' . $price($s['price']) . '</td>'
                    . '<td class="r hide-s">' . $km($s['distance']) . '</td></tr>';
            }
            $h .= '</table>';
        } elseif ($r['error'] === '') {
            $h .= '<div class="tk-empty">' . $e($this->Translate('Keine passenden Tankstellen gefunden.')) . '</div>';
        }

        $h .= '<div class="tk-foot">' . $e($this->Translate('Daten')) . ': Tankerkönig / MTS-K (CC BY 4.0)</div></div>';
        return $h;
    }

    private function CreateProfiles(): void
    {
        if (!IPS_VariableProfileExists('TANK.FuelType')) {
            IPS_CreateVariableProfile('TANK.FuelType', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileIcon('TANK.FuelType', 'Gauge');
            foreach (self::FUEL_LABELS as $value => $label) {
                IPS_SetVariableProfileAssociation('TANK.FuelType', $value, $label, '', -1);
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
}
