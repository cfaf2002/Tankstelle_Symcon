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
    private const API_V4           = 'https://creativecommons.tankerkoenig.de/api/v4';
    private const API_V1           = 'https://creativecommons.tankerkoenig.de/json';
    private const API_GEOCODE      = 'https://nominatim.openstreetmap.org/search';
    private const USER_AGENT       = 'IPSymcon-Tankstellen/3.0 (+https://github.com/cfaf2002/Tankstelle_Symcon)';
    private const LOCATION_CONTROL = '{45E97A63-F870-408A-B259-2933F7EABF74}';

    /**
     * Alle Sorten, die die Tankerkönig-API liefern kann. Index = Wert der Variable "FuelType".
     * Die Markttransparenzstelle meldet derzeit E5, E10 und Diesel. API v4 sieht zusätzlich
     * LPG und CNG vor – das Modul übernimmt diese automatisch, sobald sie geliefert werden.
     */
    private const FUELS = [
        0 => ['key' => 'e5',        'label' => 'Super E5',      'short' => 'E5',     'prop' => 'FuelE5',        'stat' => 'E5'],
        1 => ['key' => 'e10',       'label' => 'Super E10',     'short' => 'E10',    'prop' => 'FuelE10',       'stat' => 'E10'],
        2 => ['key' => 'diesel',    'label' => 'Diesel',        'short' => 'Diesel', 'prop' => 'FuelDiesel',    'stat' => 'Diesel'],
        3 => ['key' => 'superplus', 'label' => 'Super Plus',    'short' => 'Plus',   'prop' => 'FuelSuperPlus', 'stat' => 'SuperPlus'],
        4 => ['key' => 'lpg',       'label' => 'Autogas (LPG)', 'short' => 'LPG',    'prop' => 'FuelLPG',       'stat' => 'LPG'],
        5 => ['key' => 'cng',       'label' => 'Erdgas (CNG)',  'short' => 'CNG',    'prop' => 'FuelCNG',       'stat' => 'CNG']
    ];

    private const COMPLAINT_TYPES = [
        'wrongPriceE5', 'wrongPriceE10', 'wrongPriceDiesel', 'wrongStatusOpen', 'wrongStatusClosed',
        'wrongPetrolStationName', 'wrongPetrolStationBrand', 'wrongPetrolStationStreet', 'wrongPetrolStationHouseNumber',
        'wrongPetrolStationPostcode', 'wrongPetrolStationPlace', 'wrongPetrolStationLocation'
    ];

    private const MIN_INTERVAL   = 5;        // Minuten – Vorgabe Tankerkönig für Hausautomation
    private const MIN_GAP        = 60;       // Sekunden – Tankerkönig: max. 1 Anfrage pro Minute je API-Key
    private const STATS_MAX_AGE  = 21600;    // 6 Stunden – Bundesdurchschnitt ändert sich langsam
    private const LEGACY_RETRY   = 86400;    // nach Ausfall von v4 einen Tag lang v1 nutzen
    private const MAX_RADIUS     = 25;       // km – Grenze der API
    private const MAX_RESPONSE   = 2097152;  // 2 MB
    private const MAX_IMAGE      = 3145728;  // 3 MB – größere Bilder bremsen die Kachel
    private const IMAGE_TYPES    = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml'];

    private const BG_NONE    = 0;   // kein Hintergrundbild
    private const BG_BUILTIN = 1;   // mitgeliefertes Motiv „Zapfhahn“
    private const BG_MEDIA   = 2;   // eigenes Medienobjekt

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

        // Kraftstoffe – die drei gemeldeten Sorten an, weitere vorbereitet
        foreach (self::FUELS as $i => $fuel) {
            $this->RegisterPropertyBoolean($fuel['prop'], $i <= 2);
        }

        // Abfrage
        $this->RegisterPropertyInteger('UpdateInterval', 15);
        $this->RegisterPropertyString('SortBy', 'price');
        $this->RegisterPropertyBoolean('OnlyOpen', true);
        $this->RegisterPropertyInteger('MaxEntries', 10);

        // Extras
        $this->RegisterPropertyBoolean('EnableNational', true);
        $this->RegisterPropertyBoolean('EnableHTMLBox', false);
        $this->RegisterPropertyBoolean('EnableDetails', true);
        $this->RegisterPropertyBoolean('EnableAlert', false);
        $this->RegisterPropertyFloat('AlertThreshold', 1.70);

        // Darstellung der Kachel
        $this->RegisterPropertyInteger('BackgroundMode', self::BG_BUILTIN);
        $this->RegisterPropertyInteger('BackgroundMedia', 0);
        $this->RegisterPropertyInteger('BackgroundDim', 25);
        $this->RegisterPropertyInteger('BackgroundBlur', 0);
        $this->RegisterPropertyString('BrandLogos', '[]');

        // Interner Speicher
        $this->RegisterAttributeString('GeoCache', '{}');
        $this->RegisterAttributeString('Cache', '{}');
        $this->RegisterAttributeString('Stats', '{}');
        $this->RegisterAttributeInteger('LastRequest', 0);   // jede Anfrage an Tankerkönig (Ratenlimit)
        $this->RegisterAttributeInteger('LegacyUntil', 0);
        $this->RegisterAttributeString('LastError', '');

        $this->RegisterTimer('Update', 0, 'TANK_Update($_IPS[\'TARGET\']);');
        $this->RegisterTimer('Stats', 0, 'TANK_UpdateStats($_IPS[\'TARGET\']);');

        $this->SetVisualizationType(1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->MaintainVariables();
        $this->UpdateMediaReferences();
        if (IPS_GetKernelRunlevel() === KR_READY) {
            // geänderte Bilder sofort an offene Kacheln schicken
            $this->UpdateVisualizationValue(json_encode(['assets' => $this->BuildAssets()]));
        }

        if (!$this->ValidateConfig()) {
            $this->SetTimerInterval('Update', 0);
            $this->SetTimerInterval('Stats', 0);
            if (IPS_GetKernelRunlevel() === KR_READY) {
                $this->Publish();
            }
            return;
        }

        $this->SetUpdateTimer();
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
                $this->Publish(); // alle Sorten liegen im Zwischenspeicher – kein API-Abruf
                break;

            case 'Refresh':
                if ($this->ReadPropertyBoolean('Active') && $this->RequestAllowed()) {
                    $this->Update();
                } else {
                    $this->Publish();
                }
                break;

            default:
                throw new Exception($this->Translate('Unbekannte Aktion'));
        }
    }

    /**
     * Preise aller Sorten mit einem einzigen API-Aufruf abrufen.
     */
    public function Update(): bool
    {
        if (!$this->ValidateConfig()) {
            $this->Publish();
            return false;
        }

        // Ratenlimit: höchstens eine Anfrage pro Minute – sonst kurz verschieben
        if (!$this->RequestAllowed()) {
            $this->SetTimerInterval('Update', (self::MIN_GAP + 5) * 1000);
            $this->Publish();
            return false;
        }
        $this->SetUpdateTimer();

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

            $useLegacy = time() < $this->ReadAttributeInteger('LegacyUntil');
            [$stations, $error, $code] = $useLegacy ? $this->FetchV1($location) : $this->FetchV4($location);

            // v4 nicht verfügbar (z. B. abgeschaltet): beim nächsten Lauf auf die bewährte v1 ausweichen.
            // Nicht sofort – Tankerkönig erlaubt nur eine Anfrage pro Minute.
            if ($stations === null && !$useLegacy && in_array($code, [404, 410], true)) {
                $this->WriteAttributeInteger('LegacyUntil', time() + self::LEGACY_RETRY);
                $this->SendDebug('API', 'v4 nicht verfügbar – nächster Abruf über v1', 0);
            }

            if ($stations === null) {
                $this->SetStatus($code === 401 || stripos($error, 'apikey') !== false ? self::STATUS_BAD_KEY : self::STATUS_API_ERROR);
                return $this->Fail($error);
            }

            $this->WriteAttributeString('Cache', json_encode([
                'fetched' => time(),
                'api'     => $useLegacy ? 'v1' : 'v4',
                'stations' => $stations
            ]));
            $this->WriteAttributeString('LastError', '');
            $this->SetStatus(IS_ACTIVE);
            $this->ScheduleStats();
            $this->Publish();
            return true;
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    /**
     * Bundesweite Durchschnittspreise (API v4 /stats). Wird automatisch alle 6 Stunden
     * zeitversetzt abgerufen, damit das Ratenlimit von 1 Anfrage/Minute eingehalten wird.
     */
    public function UpdateStats(): bool
    {
        $this->SetTimerInterval('Stats', 0);
        if (!$this->ReadPropertyBoolean('EnableNational') || !$this->ValidateConfig()) {
            return false;
        }
        if (!$this->RequestAllowed()) {
            $this->SetTimerInterval('Stats', (self::MIN_GAP + 5) * 1000);
            return false;
        }

        [$code, $body] = $this->HttpRequest(self::API_V4 . '/stats?' . http_build_query(['apikey' => $this->ApiKey()]));
        $data = $body !== null ? json_decode($body, true) : null;
        if ($code !== 200 || !is_array($data)) {
            $this->SendDebug('Stats', 'nicht verfügbar (HTTP ' . $code . ')', 0);
            return false;
        }

        $stats = ['fetched' => time()];
        foreach (self::FUELS as $f) {
            $s = $data[$f['stat']] ?? null;
            if (is_array($s) && isset($s['mean']) && is_numeric($s['mean'])) {
                $stats[$f['key']] = [
                    'mean'   => round((float) $s['mean'], 3),
                    'median' => isset($s['median']) && is_numeric($s['median']) ? round((float) $s['median'], 3) : null,
                    'count'  => (int) ($s['count'] ?? 0)
                ];
            }
        }
        $this->WriteAttributeString('Stats', json_encode($stats));
        $this->Publish();
        return true;
    }

    /**
     * Falsche Daten an die Markttransparenzstelle melden.
     * $Type z. B. "wrongPriceE5", "wrongStatusClosed"; $Correction: richtiger Wert (Preis als 1.799).
     */
    public function ReportError(string $StationID, string $Type, string $Correction = ''): bool
    {
        if (!$this->ValidateConfig()) {
            return false;
        }
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $StationID) || !in_array($Type, self::COMPLAINT_TYPES, true)) {
            throw new Exception($this->Translate('Ungültige Station oder ungültiger Meldungstyp'));
        }
        if (!$this->RequestAllowed()) {
            throw new Exception($this->Translate('Bitte eine Minute warten (Tankerkönig erlaubt eine Anfrage pro Minute).'));
        }
        $fields = ['apikey' => $this->ApiKey(), 'id' => $StationID, 'type' => $Type];
        if ($Correction !== '') {
            $fields['correction'] = mb_substr($Correction, 0, 100);
        }
        [$code, $body] = $this->HttpRequest(self::API_V1 . '/complaint.php', $fields);
        $data = $body !== null ? json_decode($body, true) : null;
        return $code === 200 && is_array($data) && ($data['ok'] ?? false) === true;
    }

    /**
     * Stationen einer Sorte als Array für eigene Skripte.
     * $Fuel: "e5", "e10", "diesel", "superplus", "lpg", "cng" oder leer für die gewählte Sorte.
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

    public function UpdateBackgroundForm(int $Mode): void
    {
        $this->UpdateFormField('BackgroundMedia', 'visible', $Mode === self::BG_MEDIA);
        $this->UpdateFormField('BackgroundRow', 'visible', $Mode !== self::BG_NONE);
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

        // Welche Sorten hat die letzte Abfrage geliefert?
        $seen = [];
        foreach ($this->ReadCache()['stations'] as $s) {
            foreach (array_keys(array_filter($s['p'] ?? [])) as $k) {
                $seen[$k] = true;
            }
        }
        $labels = [];
        foreach (self::FUELS as $f) {
            if (isset($seen[$f['key']])) {
                $labels[] = $f['label'];
            }
        }
        $fuelInfo = empty($labels)
            ? $this->Translate('Noch keine Abfrage – geliefert werden derzeit üblicherweise E5, E10 und Diesel.')
            : sprintf($this->Translate('Bei der letzten Abfrage geliefert: %s'), implode(', ', $labels));

        $bgMode = $this->ReadPropertyInteger('BackgroundMode');
        $this->WalkForm($form['elements'], function (array &$el) use ($source, $info, $fuelInfo, $bgMode) {
            switch ($el['name'] ?? '') {
                case 'BackgroundMedia':
                    $el['visible'] = $bgMode === self::BG_MEDIA;
                    break;
                case 'BackgroundRow':
                    $el['visible'] = $bgMode !== self::BG_NONE;
                    break;
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
                case 'FuelInfo':
                    $el['caption'] = $fuelInfo;
                    break;
            }
        });
        return json_encode($form);
    }

    public function GetVisualizationTile()
    {
        $html = file_get_contents(__DIR__ . '/module.html');
        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
        // Bilder nur einmal beim Laden der Kachel übertragen, nicht bei jeder Preisänderung
        $assets = json_encode(['assets' => $this->BuildAssets()], $flags);
        $payload = json_encode($this->BuildTileData(), $flags);
        return $html . '<script>handleMessage(' . $assets . ');handleMessage(' . $payload . ');</script>';
    }

    // ------------------------------------------------------------------
    // Bilder: Hintergrund und eigene Bilder für die Liste (Medienobjekte des Nutzers)
    // ------------------------------------------------------------------

    private function BuildAssets(): array
    {
        $logos = [];
        foreach ($this->ReadBrandLogos() as $brand => $mediaID) {
            $uri = $this->MediaDataUri($mediaID, 524288); // Bilder für die Liste max. 512 KB
            if ($uri !== null) {
                $logos[$brand] = $uri;
            }
        }
        switch ($this->ReadPropertyInteger('BackgroundMode')) {
            case self::BG_BUILTIN:
                // mitgeliefertes SVG – klein, scharf in jeder Größe, Motiv rechts
                $svg = @file_get_contents(__DIR__ . '/assets/zapfhahn.svg');
                $background = $svg !== false ? 'data:image/svg+xml;base64,' . base64_encode($svg) : null;
                $position = 'right center';
                break;
            case self::BG_MEDIA:
                $background = $this->MediaDataUri($this->ReadPropertyInteger('BackgroundMedia'), self::MAX_IMAGE);
                $position = 'center';
                break;
            default:
                $background = null;
                $position = 'center';
        }
        return [
            'background' => $background,
            'position'   => $position,
            'dim'        => max(0, min(90, $this->ReadPropertyInteger('BackgroundDim'))),
            'blur'       => max(0, min(20, $this->ReadPropertyInteger('BackgroundBlur'))),
            'logos'      => $logos
        ];
    }

    /** Liste „Name → Medienobjekt“, Name normalisiert (klein, nur Buchstaben/Ziffern) */
    private function ReadBrandLogos(): array
    {
        $list = json_decode($this->ReadPropertyString('BrandLogos'), true);
        $map = [];
        foreach (is_array($list) ? $list : [] as $row) {
            $key = $this->BrandKey((string) ($row['Brand'] ?? ''));
            $media = (int) ($row['Media'] ?? 0);
            if ($key !== '' && $media > 0) {
                $map[$key] = $media;
            }
        }
        return $map;
    }

    private function BrandKey(string $brand): string
    {
        return preg_replace('/[^a-z0-9äöüß]/u', '', mb_strtolower($brand));
    }

    /** Bild-Medienobjekt als data-URI; nur bekannte Bildformate, mit Größenlimit */
    private function MediaDataUri(int $id, int $limit): ?string
    {
        if ($id <= 0 || !IPS_MediaExists($id)) {
            return null;
        }
        $media = IPS_GetMedia($id);
        if ((int) ($media['MediaType'] ?? -1) !== MEDIATYPE_IMAGE) {
            return null;
        }
        $ext = strtolower(pathinfo((string) ($media['MediaFile'] ?? ''), PATHINFO_EXTENSION));
        $mime = self::IMAGE_TYPES[$ext] ?? null;
        $content = (string) IPS_GetMediaContent($id); // bereits base64
        if ($mime === null || $content === '' || strlen($content) > $limit * 4 / 3) {
            $this->SendDebug('Bild', sprintf('Medienobjekt %d übersprungen (Format/Größe)', $id), 0);
            return null;
        }
        return 'data:' . $mime . ';base64,' . preg_replace('/[^A-Za-z0-9+\/=]/', '', $content);
    }

    /** Verwendete Medienobjekte als Referenz melden (Symcon warnt dann vor dem Löschen) */
    private function UpdateMediaReferences(): void
    {
        foreach ($this->GetReferenceList() as $ref) {
            $this->UnregisterReference($ref);
        }
        $ids = array_values($this->ReadBrandLogos());
        if ($this->ReadPropertyInteger('BackgroundMode') === self::BG_MEDIA) {
            $ids[] = $this->ReadPropertyInteger('BackgroundMedia');
        }
        foreach (array_unique(array_filter($ids)) as $id) {
            if (IPS_ObjectExists($id)) {
                $this->RegisterReference($id);
            }
        }
    }

    // ------------------------------------------------------------------
    // Abruf
    // ------------------------------------------------------------------

    /** API v4: alle Sorten, Öffnungszeiten, letzte Preisänderung */
    private function FetchV4(array $loc): array
    {
        [$code, $body] = $this->HttpRequest(self::API_V4 . '/stations/search?' . http_build_query([
            'apikey' => $this->ApiKey(),
            'lat'    => $loc['lat'],
            'lng'    => $loc['lon'],
            'rad'    => $this->GetRadius()
        ]));
        $data = $body !== null ? json_decode($body, true) : null;

        if ($code !== 200 || !is_array($data) || !isset($data['stations']) || !is_array($data['stations'])) {
            return [null, $this->ApiError($code, $data), $code];
        }
        return [$this->ParseV4($data['stations']), '', $code];
    }

    private function ParseV4(array $raw): array
    {
        $stations = [];
        foreach ($raw as $s) {
            if (!is_array($s)) {
                continue;
            }
            $prices = [];
            $changes = [];
            foreach ((array) ($s['fuels'] ?? []) as $fuel) {
                if (!is_array($fuel) || !isset($fuel['price']) || !is_numeric($fuel['price']) || (float) $fuel['price'] <= 0) {
                    continue;
                }
                $key = $this->FuelKey((string) ($fuel['name'] ?? ''), (string) ($fuel['category'] ?? ''));
                if ($key === null) {
                    $this->SendDebug('Unbekannte Sorte', json_encode($fuel), 0);
                    continue;
                }
                if (isset($prices[$key])) {
                    continue; // erste Angabe gewinnt (z. B. "Diesel" vor "Premium Diesel")
                }
                $prices[$key] = round((float) $fuel['price'], 3);
                $lc = $fuel['lastChange'] ?? null;
                if (is_array($lc) && isset($lc['amount']) && is_numeric($lc['amount'])) {
                    $ts = isset($lc['timestamp']) ? strtotime((string) $lc['timestamp']) : false;
                    $changes[$key] = ['a' => round((float) $lc['amount'], 3), 't' => $ts ?: 0];
                }
            }
            $station = $this->BaseStation(
                $s,
                trim((string) ($s['street'] ?? '')),
                trim((string) ($s['postalCode'] ?? '') . ' ' . ($s['place'] ?? '')),
                $prices
            );
            if ($station === null) {
                continue;
            }
            $station['c']  = $changes;
            $station['oa'] = isset($s['opensAt']) ? (int) strtotime((string) $s['opensAt']) : 0;
            $station['ca'] = isset($s['closesAt']) ? (int) strtotime((string) $s['closesAt']) : 0;
            $stations[] = $station;
        }
        return $stations;
    }

    /** API v1 (list.php, type=all): Rückfallebene – E5, E10, Diesel */
    private function FetchV1(array $loc): array
    {
        [$code, $body] = $this->HttpRequest(self::API_V1 . '/list.php?' . http_build_query([
            'lat'    => $loc['lat'],
            'lng'    => $loc['lon'],
            'rad'    => $this->GetRadius(),
            'sort'   => 'dist',
            'type'   => 'all',
            'apikey' => $this->ApiKey()
        ]));
        $data = $body !== null ? json_decode($body, true) : null;
        if ($code !== 200 || !is_array($data) || ($data['ok'] ?? false) !== true || !isset($data['stations']) || !is_array($data['stations'])) {
            return [null, $this->ApiError($code, $data), $code];
        }
        return [$this->ParseV1($data['stations']), '', $code];
    }

    private function ParseV1(array $raw): array
    {
        $stations = [];
        foreach ($raw as $s) {
            if (!is_array($s)) {
                continue;
            }
            $prices = [];
            foreach (['e5', 'e10', 'diesel'] as $k) {
                $p = $s[$k] ?? null;
                if (is_numeric($p) && (float) $p > 0) {
                    $prices[$k] = round((float) $p, 3);
                }
            }
            $street = trim(($s['street'] ?? '') . ' ' . ($s['houseNumber'] ?? ''));
            $station = $this->BaseStation($s, $street, trim(($s['postCode'] ?? '') . ' ' . ($s['place'] ?? '')), $prices);
            if ($station !== null) {
                $station['c'] = [];
                $station['oa'] = 0;
                $station['ca'] = 0;
                $stations[] = $station;
            }
        }
        return $stations;
    }

    private function BaseStation(array $s, string $street, string $place, array $prices): ?array
    {
        if (empty($prices)) {
            return null;
        }
        $brand = trim((string) ($s['brand'] ?? ''));
        $name  = trim((string) ($s['name'] ?? ''));
        return [
            'id' => mb_substr((string) ($s['id'] ?? ''), 0, 64),
            'n'  => mb_substr($brand !== '' ? $brand : $name, 0, 80),
            'b'  => mb_substr($brand, 0, 40),
            'a'  => mb_substr(trim($street . ', ' . $place, ' ,'), 0, 120),
            'd'  => round((float) ($s['dist'] ?? 0), 1),
            'o'  => !empty($s['isOpen']),
            'p'  => $prices
        ];
    }

    /** Ordnet einen Sortennamen der API einer Sorte des Moduls zu */
    private function FuelKey(string $name, string $category): ?string
    {
        $n = strtolower($name);
        switch (strtolower($category)) {
            case 'lpg':
                return 'lpg';
            case 'cng':
                return 'cng';
            case 'diesel':
                return (strpos($n, 'premium') === false && strpos($n, 'plus') === false) ? 'diesel' : null;
            case 'gasoline':
                if (strpos($n, 'e10') !== false) {
                    return 'e10';
                }
                if (strpos($n, 'plus') !== false || strpos($n, '98') !== false || strpos($n, '100') !== false) {
                    return 'superplus';
                }
                if (strpos($n, 'e5') !== false || $n === 'super') {
                    return 'e5';
                }
                return null;
        }
        return null;
    }

    private function ApiError(int $code, $data): string
    {
        $message = is_array($data) && isset($data['message']) && is_string($data['message']) ? mb_substr($data['message'], 0, 200) : '';
        switch ($code) {
            case 0:
                return $this->Translate('Tankerkönig-API nicht erreichbar.');
            case 401:
                return $this->Translate('API-Key ungültig.');
            case 503:
                return $this->Translate('Tankerkönig: zu viele Anfragen – bitte Intervall erhöhen.');
        }
        return 'Tankerkönig: ' . ($message !== '' ? $message : sprintf($this->Translate('Fehler (HTTP %d)'), $code));
    }

    private function ScheduleStats(): void
    {
        if (!$this->ReadPropertyBoolean('EnableNational')) {
            return;
        }
        $stats = json_decode($this->ReadAttributeString('Stats'), true);
        if (time() - (int) ($stats['fetched'] ?? 0) > self::STATS_MAX_AGE) {
            // zeitversetzt nach dem Preisabruf, damit das Ratenlimit eingehalten wird
            $this->SetTimerInterval('Stats', (self::MIN_GAP + random_int(5, 30)) * 1000);
        }
    }

    /** Tankerkönig bittet um zufällige Abfragezeitpunkte – jedes Intervall bekommt einen neuen Versatz */
    private function SetUpdateTimer(): void
    {
        $seconds = max(self::MIN_INTERVAL, $this->ReadPropertyInteger('UpdateInterval')) * 60 + random_int(0, 59);
        $this->SetTimerInterval('Update', $seconds * 1000);
    }

    private function RequestAllowed(): bool
    {
        return time() - $this->ReadAttributeInteger('LastRequest') >= self::MIN_GAP;
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
        $delta    = $modern ? ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' ct', 'DIGITS' => 1, 'ICON' => 'scale-balanced'] : 'TANK.Cent';
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

        $this->MaintainVariable('CheapestPrice', $this->Translate('Günstigster Preis'), VARIABLETYPE_FLOAT, $price, 20, true);
        $this->MaintainVariable('CheapestName', $this->Translate('Günstigste Tankstelle'), VARIABLETYPE_STRING, $text, 21, true);

        foreach (self::FUELS as $i => $f) {
            $this->MaintainVariable('Price_' . $f['key'], sprintf($this->Translate('Bestpreis %s'), $f['label']), VARIABLETYPE_FLOAT, $price, 30 + $i, in_array($i, $enabled, true));
        }

        $details = $this->ReadPropertyBoolean('EnableDetails');
        $this->MaintainVariable('CheapestAddress', $this->Translate('Adresse'), VARIABLETYPE_STRING, $text, 40, $details);
        $this->MaintainVariable('CheapestDistance', $this->Translate('Entfernung'), VARIABLETYPE_FLOAT, $distance, 41, $details);
        $this->MaintainVariable('AveragePrice', $this->Translate('Durchschnittspreis'), VARIABLETYPE_FLOAT, $price, 42, $details);
        $this->MaintainVariable('HighestPrice', $this->Translate('Höchster Preis'), VARIABLETYPE_FLOAT, $price, 43, $details);
        $this->MaintainVariable('StationCount', $this->Translate('Anzahl Tankstellen'), VARIABLETYPE_INTEGER, $count, 44, $details);

        $national = $this->ReadPropertyBoolean('EnableNational');
        $this->MaintainVariable('NationalAverage', $this->Translate('Bundesdurchschnitt'), VARIABLETYPE_FLOAT, $price, 45, $national);
        $this->MaintainVariable('SavingVsNational', $this->Translate('Ersparnis zum Bundesschnitt'), VARIABLETYPE_FLOAT, $delta, 46, $national);

        $this->MaintainVariable('HTML', $this->Translate('Übersicht'), VARIABLETYPE_STRING, $html, 50, $this->ReadPropertyBoolean('EnableHTMLBox'));
        $this->MaintainVariable('PriceAlert', $this->Translate('Preis unter Schwelle'), VARIABLETYPE_BOOLEAN, $alert, 60, $this->ReadPropertyBoolean('EnableAlert'));
        $this->MaintainVariable('LastUpdate', $this->Translate('Letzte Aktualisierung'), VARIABLETYPE_INTEGER, $stamp, 90, true);
    }

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
        }
        foreach (self::FUELS as $i => $f) {
            IPS_SetVariableProfileAssociation('TANK.FuelType', $i, $f['label'], '', -1);
        }
        foreach (['TANK.Price' => [3, ' €', 'Euro'], 'TANK.Distance' => [1, ' km', 'Distance'], 'TANK.Cent' => [1, ' ct', 'Euro']] as $name => [$digits, $suffix, $icon]) {
            if (!IPS_VariableProfileExists($name)) {
                IPS_CreateVariableProfile($name, VARIABLETYPE_FLOAT);
                IPS_SetVariableProfileDigits($name, $digits);
                IPS_SetVariableProfileText($name, '', $suffix);
                IPS_SetVariableProfileIcon($name, $icon);
            }
        }
    }

    // ------------------------------------------------------------------
    // Daten aufbereiten & veröffentlichen
    // ------------------------------------------------------------------

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

    private function ReadCache(): array
    {
        $cache = json_decode($this->ReadAttributeString('Cache'), true);
        return is_array($cache) && isset($cache['stations']) && is_array($cache['stations']) ? $cache : ['fetched' => 0, 'stations' => []];
    }

    private function NationalFor(int $index): ?array
    {
        if (!$this->ReadPropertyBoolean('EnableNational')) {
            return null;
        }
        $stats = json_decode($this->ReadAttributeString('Stats'), true);
        $s = is_array($stats) ? ($stats[self::FUELS[$index]['key']] ?? null) : null;
        return is_array($s) ? $s : null;
    }

    private function BuildFuelResult(?int $index, ?array $cache = null): array
    {
        $empty = ['stations' => [], 'cheapest' => null, 'min' => null, 'max' => null, 'avg' => null, 'count' => 0, 'national' => null];
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
            $change = $s['c'][$key] ?? null;
            $list[] = [
                'id'       => $s['id'],
                'name'     => $s['n'],
                'brand'    => $s['b'] ?? '',
                'address'  => $s['a'],
                'distance' => $s['d'],
                'isOpen'   => $s['o'],
                'price'    => $price,
                'change'   => $change['a'] ?? null,
                'changed'  => $change['t'] ?? null,
                'opensAt'  => ($s['oa'] ?? 0) ?: null,
                'closesAt' => ($s['ca'] ?? 0) ?: null
            ];
        }
        $national = $this->NationalFor($index);
        if (empty($list)) {
            return array_merge($empty, ['national' => $national]);
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
            'count'    => count($prices),
            'national' => $national
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
        if ($this->ReadPropertyBoolean('EnableNational')) {
            $mean = $r['national']['mean'] ?? null;
            $this->SetValueIfChanged('NationalAverage', (float) ($mean ?? 0));
            $this->SetValueIfChanged('SavingVsNational', ($mean !== null && $c) ? round(($mean - $c['price']) * 100, 1) : 0.0);
        }
        if ($this->ReadPropertyBoolean('EnableAlert')) {
            $this->SetValueIfChanged('PriceAlert', $c !== null && $c['price'] <= $this->ReadPropertyFloat('AlertThreshold'));
        }
        if ((int) $cache['fetched'] > 0) {
            $this->SetValueIfChanged('LastUpdate', (int) $cache['fetched']);
        }
    }

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
                'national' => $r['national']['mean'] ?? null,
                'cheapest' => $r['cheapest'],
                'stations' => $r['stations']
            ];
        }

        return [
            'selected'  => $this->SelectedFuelIndex(),
            'fuels'     => $fuels,
            'radius'    => $this->GetRadius(),
            'updated'   => (int) $cache['fetched'],
            'interval'  => max(self::MIN_INTERVAL, $this->ReadPropertyInteger('UpdateInterval')),
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
            . '.tk{font-family:Poppins,"Segoe UI",system-ui,Arial,sans-serif;color:var(--tk-text,#fff);line-height:1.4}.tk *{box-sizing:border-box}'
            . '.tk-head{display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:6px;margin-bottom:10px}'
            . '.tk-head b{font-size:18px}.tk-meta{font-size:12px;opacity:.65}'
            . '.tk-best{background:rgba(34,177,76,.15);border-radius:16px;padding:12px 14px;margin-bottom:10px;display:flex;justify-content:space-between;align-items:center;gap:10px}'
            . '.tk-best .nm{font-size:16px;font-weight:600}.tk-best .ad{font-size:12px;opacity:.7}.tk-best .pr{font-size:30px;font-weight:600;color:#22b14c;white-space:nowrap}'
            . '.tk sup{font-size:.55em}.tk table{width:100%;border-collapse:collapse}'
            . '.tk td{padding:7px 4px;border-top:1px solid rgba(127,127,127,.18)}.tk .r{text-align:right;white-space:nowrap}'
            . '.tk .p{font-weight:600}.tk .a{font-size:11px;opacity:.65}.tk .best .p{color:#22b14c}.tk .closed{opacity:.45}'
            . '.tk-msg{padding:10px;border-radius:10px;background:rgba(127,127,127,.15);margin-bottom:10px}.tk-err{background:rgba(229,72,77,.15);color:#e5484d}'
            . '.tk-foot{font-size:10px;opacity:.5;text-align:right;margin-top:6px}'
            . '</style><div class="tk">';

        $h .= '<div class="tk-head"><b>' . $e($label) . '</b><span class="tk-meta">' . $e((int) $this->GetRadius()) . ' km'
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

        $theme = '<script>(function(){try{var n=window.frameElement;while(n&&n.nodeType===1){var v=(getComputedStyle(n).backgroundColor.match(/[\\d.]+/g)||[]).map(Number);'
            . 'if(v.length>=3&&(v.length<4||v[3]>0.5)){document.documentElement.style.setProperty("--tk-text",(0.2126*v[0]+0.7152*v[1]+0.0722*v[2])/255<0.5?"#fff":"#1c1c1e");return;}n=n.parentElement;}}catch(e){}})();</script>';

        return $h . '<div class="tk-foot">Tankerkönig / MTS-K · CC BY 4.0</div></div>' . $theme;
    }

    // ------------------------------------------------------------------
    // Hilfsfunktionen
    // ------------------------------------------------------------------

    private function ApiKey(): string
    {
        return trim($this->ReadPropertyString('APIKey'));
    }

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
        $key = $this->ApiKey();
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
        [$code, $body] = $this->HttpRequest(self::API_GEOCODE . '?' . http_build_query([
            'postalcode' => $plz,
            'country'    => 'Germany',
            'format'     => 'json',
            'limit'      => 1
        ]));
        $data = $code === 200 && $body !== null ? json_decode($body, true) : null;
        if (!is_array($data) || !isset($data[0]['lat'], $data[0]['lon']) || !is_numeric($data[0]['lat']) || !is_numeric($data[0]['lon'])) {
            $this->LogMessage(sprintf('Geocoding fehlgeschlagen: PLZ %s', $plz), KL_WARNING);
            return null;
        }
        return ['lat' => round((float) $data[0]['lat'], 5), 'lon' => round((float) $data[0]['lon'], 5)];
    }

    /**
     * HTTPS-Anfrage. Liefert [HTTP-Code, Body|null]; Code 0 = keine Verbindung.
     * Mit $post wird ein Formular per POST gesendet.
     */
    private function HttpRequest(string $url, ?array $post = null): array
    {
        $isTankerkoenig = strpos($url, 'tankerkoenig.de') !== false;
        if ($isTankerkoenig) {
            $this->WriteAttributeInteger('LastRequest', time());
        }
        // API-Key nie im Klartext ins Debug schreiben
        $this->SendDebug($post === null ? 'GET' : 'POST', preg_replace('/apikey=[^&]+/', 'apikey=***', $url), 0);

        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER   => true,
            CURLOPT_CONNECTTIMEOUT   => 5,
            CURLOPT_TIMEOUT          => 10,
            CURLOPT_USERAGENT        => self::USER_AGENT,
            CURLOPT_FOLLOWLOCATION   => false,
            CURLOPT_SSL_VERIFYPEER   => true,
            CURLOPT_SSL_VERIFYHOST   => 2,
            CURLOPT_PROTOCOLS        => CURLPROTO_HTTPS,
            CURLOPT_ENCODING         => '',
            CURLOPT_HTTPHEADER       => ['Accept: application/json'],
            CURLOPT_NOPROGRESS       => false,
            CURLOPT_PROGRESSFUNCTION => fn ($ch, $dlTotal, $dlNow) => $dlNow > self::MAX_RESPONSE ? 1 : 0
        ];
        if ($post !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query($post);
        }
        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if (!is_string($body) || $body === '') {
            $this->SendDebug('HTTP-Fehler', sprintf('Code %d %s', $code, $err), 0);
            return [$code, null];
        }
        $this->SendDebug('Antwort ' . $code, mb_substr($body, 0, 3000), 0);
        return [$code, $body];
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
