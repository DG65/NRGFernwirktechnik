<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/FW101_Station.php';
require_once __DIR__ . '/../libs/FW101_Presets.php';
require_once __DIR__ . '/../libs/FW_IpsHost.php';
require_once __DIR__ . '/../libs/FW_FormPanels.php';

// IEC101: Symcon als Unterstation (Slave) nach IEC 60870-5-101, unsymmetrisch, am
// Kommunikationsmodul eines Netzbetreibers. Die Protokollschicht liegt in libs/ und ist
// ohne IPS getestet (tests/run.php, Interoperabilitaet mit lib60870 ueber tests/interop_slave.php).
// Dieses Modul ist nur die Anbindung an Symcon: Serial Port, Variablen, Formular.

class IEC101 extends IPSModule
{
    use FW_FormPanels;

    private const PREFIX = 'IEC101';
    private const MODULE_GUID = '{26B77479-45E7-42CC-AD00-2342BF794B8A}';
    private const DATA_TO_PARENT = '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}';

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('Points', '[]');
        $this->RegisterPropertyString('Preset', FW101_Presets::EWERK_V4);
        $this->RegisterPropertyInteger('LinkAddress', 1);
        $this->RegisterPropertyInteger('LinkAddressLength', 1);
        $this->RegisterPropertyInteger('CommonAddress', 1);
        $this->RegisterPropertyInteger('CommonAddressLength', 2);
        $this->RegisterPropertyBoolean('AcceptCommonZero', true);
        $this->RegisterPropertyInteger('CauseLength', 2);
        $this->RegisterPropertyInteger('IOALength', 3);
        $this->RegisterPropertyBoolean('AckSingleChar', false);
        $this->RegisterPropertyBoolean('SendInit', true);
        $this->RegisterPropertyString('TimeMode', 'utc');
        $this->RegisterPropertyFloat('ThresholdPercent', 10.0);
        $this->RegisterPropertyFloat('MinDelta', 0.001);
        $this->RegisterPropertyInteger('MaxInterval', 60);
        $this->RegisterPropertyInteger('FailHours', 12);
        $this->RegisterPropertyInteger('LocalModeVariable', 0);
        $this->RegisterPropertyBoolean('LocalBlocksSetpoints', false);
        $this->RegisterPropertyBoolean('RestoreOnStart', true);

        $this->registerDismissAttributes();
        $this->RegisterTimer('Tick', 0, 'IEC101_Tick($_IPS[\'TARGET\']);');
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        foreach ($this->GetMessageList() as $senderID => $messages) {
            if ($senderID === 0) {
                continue;
            }
            foreach ($messages as $m) {
                $this->UnregisterMessage($senderID, $m);
            }
        }

        $rows = json_decode($this->ReadPropertyString('Points'), true) ?: [];
        $points = FW101_Station::normalize($rows);
        $invalid = count($rows) - count($points);

        $this->RegisterVariableInteger('LastContact', 'Letzter Kontakt des Netzbetreibers', '~UnixTimestamp', 1);
        $this->RegisterVariableBoolean('MasterActive', 'Kommunikationsmodul aktiv', '~Alert.Reversed', 2);
        $this->RegisterVariableInteger('QueueLength', 'Ereignisse in der Warteschlange', '', 3);
        $this->RegisterVariableBoolean('FailSafeActive', 'Ausfallwerte aktiv', '~Alert', 4);
        $pos = 10;
        foreach ($points as $p) {
            if ($p['var'] > 0 && in_array($p['type'], [13, 30, 31, 36], true) && IPS_VariableExists($p['var'])) {
                $this->RegisterMessage($p['var'], VM_UPDATE);
            }
            if ($p['type'] === 50) {
                $this->RegisterVariableFloat('SP_' . dechex($p['ioa']), $p['name'] !== '' ? $p['name'] : 'Sollwert ' . FW101_Asdu::formatIoa($p['ioa']), '', $pos++);
            }
        }

        if ($points === []) {
            $this->SetStatus(104);
        } else {
            $this->SetStatus($invalid > 0 ? 201 : 102);
        }

        // Neuer, leerer Zustand mit den aktuellen Werten (keine Ereignisse durch das Speichern)
        $this->SetBuffer('state', '');
        $this->withStation(function (FW101_Station $st) {
            $st->preload();
        });
        $this->SetTimerInterval('Tick', $points === [] ? 0 : 5000);

        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->updateStatusVariables();
        }
        $this->adoptDismissFromSibling();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELMESSAGE && $SenderID === 0) {
            if ($Data[0] === KR_READY && $this->ReadPropertyBoolean('RestoreOnStart')) {
                $this->withStation(function (FW101_Station $st) {
                    $st->preload();
                    $st->applyStoredSetpoints();
                });
            }
            return;
        }
        if ($Message === VM_UPDATE) {
            $this->withStation(function (FW101_Station $st) use ($SenderID) {
                $st->variableChanged((int) $SenderID);
            });
        }
    }

    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data) || !isset($data['Buffer'])) {
            return '';
        }
        $bytes = mb_convert_encoding((string) $data['Buffer'], 'ISO-8859-1', 'UTF-8');
        $out = $this->withStation(function (FW101_Station $st, FW101_Link $link) use ($bytes) {
            return $link->feed($bytes);
        });
        if (is_string($out) && $out !== '') {
            $this->SendDataToParent(json_encode([
                'DataID' => self::DATA_TO_PARENT,
                'Buffer' => mb_convert_encoding($out, 'UTF-8', 'ISO-8859-1'),
            ]));
        }
        return '';
    }

    /** Regelmaessig (5 s): Zeitgesteuerte Meldungen, Ausfallverhalten, Statusvariablen. */
    public function Tick(): void
    {
        $this->withStation(function (FW101_Station $st) {
            $st->tick();
        });
        $this->updateStatusVariables();
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        foreach ($form['elements'] as &$e) {
            if (($e['name'] ?? '') === 'StatusText') {
                $e['caption'] = $this->statusText();
            }
            if (($e['caption'] ?? '') === 'Schnittstelle zum Kommunikationsmodul des Netzbetreibers') {
                array_unshift($e['items'], $this->helpButton('Zeitmarken: Ortszeit oder UTC?', [
                    'Jede Meldung und jeder zeitgestempelte Messwert trägt eine Zeitmarke (CP56Time2a). Ob der Netzbetreiber sie als Ortszeit (mit Sommerzeitbit) oder als UTC erwartet, hängt von seiner Zentralstation ab.',
                    'In den Unterlagen von E-Werk Netze V4.0 steht dazu nichts. Bis das mit dem Netzbetreiber geklärt ist, ist „UTC“ die Vorgabe. Stellt sich beim Test ein Versatz von ein bis zwei Stunden heraus, hier umstellen.',
                ], 480));
            }
            if (($e['caption'] ?? '') === 'Datenpunkte') {
                array_splice($e['items'], 1, 0, [$this->helpButton('Was bedeuten Faktor, Schwelle, Rückmeldung und Pflicht?', [
                    'Faktor: Wert auf der Leitung = Wert der Variable × Faktor. −1 dreht das Vorzeichen (z. B. „Erzeugung negativ“).',
                    'Schwelle: ab welcher Änderung ein Messwert spontan gesendet wird (in der Einheit des Messwerts). 0 = die allgemeine Schwelle in Prozent aus „Verhalten“.',
                    'Min/Max: erlaubter Bereich eines Sollwerts; außerhalb wird der Sollwert mit negativer Quittung abgelehnt.',
                    'Rückmeldung: Adresse des Messwerts, der nach einem Sollwert oder Befehl exakt den empfangenen Wert meldet. Ausfallwert: Sollwert, auf den nach Ausfall des Kommunikationsmoduls zurückgefallen wird.',
                    'Pflicht: in der Datenpunktliste des Netzbetreibers vorangekreuzt. „Datenpunkte prüfen“ zeigt Pflichtpunkte ohne Variable.',
                ], 520)]);
            }
        }
        unset($e);
        foreach ($form['actions'] as &$a) {
            if (($a['name'] ?? '') === 'Preset') {
                $a['options'] = FW101_Presets::options();
                $a['value'] = $this->ReadPropertyString('Preset');
            }
        }
        unset($a);
        $form['elements'] = $this->assembleForm($form['elements']);
        return json_encode($form);
    }

    protected function licenseUrl(): string
    {
        return 'https://github.com/DG65/NRGFernwirktechnik/blob/beta/LICENSE';
    }

    protected function formTexts(): array
    {
        return [
            'purpose' => [
                'Dieses Modul macht IP-Symcon zur kundeneigenen Fernwirkstation (Unterstation) gegenüber dem Netzbetreiber: Das Kommunikationsmodul des Netzbetreibers fragt über RS-485 nach IEC 60870-5-101 Messwerte und Meldungen ab und schickt Sollwerte und Befehle, etwa die Wirkleistungsbegrenzung.',
                'Der Nutzen: Wer ohnehin Symcon zur Anlagensteuerung nutzt, kann die Vorgaben des Netzbetreibers direkt umsetzen, statt ein zusätzliches Fernwirkgerät zu kaufen. Nicht zu verwechseln mit der Schnittstelle zum Direktvermarkter (getrennter Kanal, z. B. Modbus TCP; dafür gibt es den Modbus-TCP-Server). Für Netzbetreiber mit IEC 104 über Ethernet gibt es das Modul IEC104.',
            ],
            'news' => [
                '• 🆕 Bibliothek „Fernwirk“ mit dem neuen Modul IEC104 (IEC 60870-5-104, Ethernet); dieses Modul (101) bleibt im Kern unverändert.',
                '• 🔧 Rückmeldung eines Doppelbefehls (46) auf eine Doppelmeldung (31) wird jetzt richtig abgebildet (1 = AUS, 2 = EIN); vorher kam 0/1 heraus.',
                '• 🔧 Generalabfrage kann jetzt optional mit Typen ohne Zeitmarke antworten (nur im 104-Modul eingestellt); Prüfbefehle 104/107 werden bestätigt.',
                '• 📖 Neue Panels: „Wozu dieses Modul?“, Doku mit Klärungsstand zu Zeitmarken, Abnahme und Netztrennung, Hilfe-Knöpfe an den erklärungsbedürftigen Feldern.',
            ],
            'newsVersion' => '0.2',
            'doc' => [
                'Dieses Modul macht Symcon zur kundeneigenen Fernwirkstation gegenüber dem Kommunikationsmodul eines Netzbetreibers (IEC 60870-5-101 über RS-485). Es ersetzt keine Abnahme: Ob ein Netzbetreiber Symcon als Fernwirkgerät akzeptiert, ist mit ihm vorher zu klären, ebenso Inbetriebnahmeprotokoll, Wirk- und Blindleistungstest.',
                'Vorlage E-Werk Netze V4.0: Datenpunktliste der „Kunden-Richtlinie für Fernwirkanbindungen“ (Stand 11.02.2026). Andere Netzbetreiber nutzen dieselbe Grundstruktur mit abweichenden Adressen und Einheiten; Adresslängen, Zeitmarken, Faktoren und Punkte sind deshalb frei einstellbar.',
                'Entprellung im Millisekundenbereich (10 ms) ist im Symcon-Kernel nicht möglich; die Flatterunterdrückung (mehr als 0,5 Hz, 30 s Stillsetzung) und die Zwischen- und Störstellungsunterdrückung sind umgesetzt.',
                'Stand der Prüfung: Protokoll gegen einen unabhängigen Master (lib60870) und eigene Tests geprüft. Der Serial Port im IPS, das Zeitverhalten, eine echte Gegenstelle und die Abnahme durch den Netzbetreiber sind noch nicht getestet.',
                'Getrennte Kanäle: Diese Anbindung ist die Fernwirktechnik zum Netzbetreiber (§ 9 EEG, § 13 EnWG). Die Direktvermarkter-Schnittstelle (§ 10b EEG) ist ein eigener Kanal; Netzbetreiber verlangen ausdrücklich die Trennung.',
                'Zeitmarken, Abnahme und Netztrennung (ausführlich in docs/KLAERUNG.md): Ob Zeitmarken UTC oder Ortszeit sein sollen, steht in den vorliegenden Unterlagen nicht. Die Abnahme erfolgt durch den Netzbetreiber am Netzanschlusspunkt. Bei EWE NETZ gilt zusätzlich: die kundenseitige Fernwirkhardware darf während der Verbindung nicht zugleich über dieselbe Hardware mit einem WAN (z. B. Internet) verbunden sein.',
            ],
            'feedbackUrl' => '',
        ];
    }

    /** Vorlage laden; bereits eingetragene Variablen und Einstellungen je Adresse bleiben erhalten. */
    public function LoadPreset(string $Preset): void
    {
        $new = FW101_Presets::points($Preset);
        if ($new === []) {
            echo 'Unbekannte Vorlage.';
            return;
        }
        $old = json_decode($this->ReadPropertyString('Points'), true) ?: [];
        $byIoa = [];
        foreach ($old as $r) {
            $ioa = FW101_Asdu::parseIoa((string) ($r['IOA'] ?? ''));
            if ($ioa !== null) {
                $byIoa[$ioa . ':' . (int) ($r['Type'] ?? 0)] = $r;
            }
        }
        $used = [];
        foreach ($new as &$r) {
            $key = FW101_Asdu::parseIoa($r['IOA']) . ':' . $r['Type'];
            if (isset($byIoa[$key])) {
                foreach (['Var', 'Factor', 'Delta', 'Min', 'Max', 'Fail'] as $f) {
                    if (array_key_exists($f, $byIoa[$key])) {
                        $r[$f] = $byIoa[$key][$f];
                    }
                }
                $used[$key] = true;
            }
        }
        unset($r);
        foreach ($byIoa as $key => $r) {
            if (!isset($used[$key])) {
                $new[] = $r;
            }
        }
        IPS_SetProperty($this->InstanceID, 'Points', json_encode($new));
        IPS_SetProperty($this->InstanceID, 'Preset', $Preset);
        $link = FW101_Presets::link($Preset);
        foreach ($link ?? [] as $prop => $val) {
            IPS_SetProperty($this->InstanceID, $prop, $val);
        }
        IPS_ApplyChanges($this->InstanceID);
        $this->ReloadForm();
    }

    /** Bericht ueber alle Datenpunkte: belegt, unbelegt, aktuelle Werte auf der Leitung. */
    public function GetPointReport(): string
    {
        $rows = json_decode($this->ReadPropertyString('Points'), true) ?: [];
        $points = FW101_Station::normalize($rows);
        $lines = [];
        $mapped = 0;
        foreach ($points as $p) {
            $addr = FW101_Asdu::formatIoa($p['ioa']);
            $state = 'nicht belegt';
            if ($p['var'] > 0) {
                if (!IPS_VariableExists($p['var'])) {
                    $state = 'Variable #' . $p['var'] . ' existiert nicht';
                } else {
                    $raw = GetValue($p['var']);
                    $state = '#' . $p['var'] . ' = ' . json_encode($raw) . ($p['factor'] != 1.0 && is_numeric($raw) ? ' -> ' . ($raw * $p['factor']) . ' auf der Leitung' : '');
                    $mapped++;
                }
            }
            $lines[] = sprintf('%-8s Typ %d  %s: %s%s', $addr, $p['type'], $p['name'], $state, $p['req'] && $p['var'] <= 0 ? '   [Pflicht!]' : '');
        }
        return $mapped . ' von ' . count($points) . " Datenpunkten mit Variable belegt.\n\n" . implode("\n", $lines);
    }

    // ------------------------------------------------------------------- intern

    /** Station und Verbindungsschicht mit dem gespeicherten Zustand ausfuehren. */
    private function withStation(callable $fn): mixed
    {
        $sem = 'IEC101_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($sem, 3000)) {
            $this->SendDebug('Sperre', 'Semaphore nicht erhalten', 0);
            return null;
        }
        try {
            [$station, $link] = $this->build();
            $state = $this->GetBuffer('state');
            if ($state !== '') {
                $s = @unserialize($state, ['allowed_classes' => false]);
                if (is_array($s)) {
                    $station->import($s['station'] ?? []);
                    $link->import($s['link'] ?? []);
                }
            }
            $result = $fn($station, $link);
            $this->SetBuffer('state', serialize(['station' => $station->export(), 'link' => $link->export()]));
            return $result;
        } catch (Throwable $e) {
            $this->LogMessage('IEC101: ' . $e->getMessage(), KL_ERROR);
            return null;
        } finally {
            IPS_SemaphoreLeave($sem);
        }
    }

    /** @return array{0:FW101_Station,1:FW101_Link} */
    private function build(): array
    {
        $cfg = [
            'ca'                   => $this->ReadPropertyInteger('CommonAddress'),
            'acceptCaZero'         => $this->ReadPropertyBoolean('AcceptCommonZero'),
            'cotLen'               => $this->ReadPropertyInteger('CauseLength'),
            'caLen'                => $this->ReadPropertyInteger('CommonAddressLength'),
            'ioaLen'               => $this->ReadPropertyInteger('IOALength'),
            'timeMode'             => $this->ReadPropertyString('TimeMode'),
            'pct'                  => $this->ReadPropertyFloat('ThresholdPercent'),
            'minDelta'             => $this->ReadPropertyFloat('MinDelta'),
            'maxInterval'          => $this->ReadPropertyInteger('MaxInterval'),
            'failSeconds'          => $this->ReadPropertyInteger('FailHours') * 3600,
            'localBlocksSetpoints' => $this->ReadPropertyBoolean('LocalBlocksSetpoints'),
            'sendInit'             => $this->ReadPropertyBoolean('SendInit'),
        ];
        $points = FW101_Station::normalize(json_decode($this->ReadPropertyString('Points'), true) ?: []);
        $host = new FW_IpsHost([
            'store' => function (string $key, mixed $value): void {
                $this->storeValue($key, $value);
            },
            'load'  => fn (string $key): mixed => $this->loadValue($key),
            'local' => function (): bool {
                $v = $this->ReadPropertyInteger('LocalModeVariable');
                return $v > 0 && IPS_VariableExists($v) && (bool) GetValue($v);
            },
            'log'   => function (string $m): void {
                $this->LogMessage('IEC101: ' . $m, KL_NOTIFY);
                $this->SendDebug('Log', $m, 0);
            },
        ]);
        $station = new FW101_Station($cfg, $points, $host);
        $link = new FW101_Link([
            'linkAddr'      => $this->ReadPropertyInteger('LinkAddress'),
            'linkLen'       => $this->ReadPropertyInteger('LinkAddressLength'),
            'ackSingleChar' => $this->ReadPropertyBoolean('AckSingleChar'),
        ], $station);
        return [$station, $link];
    }

    private function storeValue(string $key, mixed $value): void
    {
        if ($key === 'lastContact') {
            $id = @$this->GetIDForIdent('LastContact');
            if ($id) {
                SetValueInteger($id, (int) $value);
            }
            return;
        }
        if (str_starts_with($key, 'sp_')) {
            $id = @$this->GetIDForIdent('SP_' . substr($key, 3));
            if ($id) {
                SetValueFloat($id, (float) $value);
            }
        }
    }

    private function loadValue(string $key): mixed
    {
        $ident = $key === 'lastContact' ? 'LastContact' : (str_starts_with($key, 'sp_') ? 'SP_' . substr($key, 3) : '');
        if ($ident === '') {
            return null;
        }
        $id = @$this->GetIDForIdent($ident);
        if (!$id || (int) IPS_GetVariable($id)['VariableUpdated'] === 0) {
            return null; // noch nie geschrieben
        }
        return GetValue($id);
    }

    private function updateStatusVariables(): void
    {
        $stats = $this->withStation(fn (FW101_Station $st) => $st->stats());
        if (!is_array($stats)) {
            return;
        }
        $last = $stats['lastContact'];
        $this->SetValue('MasterActive', $last !== null && microtime(true) - $last < 120);
        $this->SetValue('QueueLength', (int) $stats['queue']);
        $this->SetValue('FailSafeActive', (bool) $stats['failApplied']);
    }

    private function statusText(): string
    {
        $lines = [];
        $parent = (int) IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($parent <= 0) {
            $lines[] = '⛔ Kein Serial Port verbunden. Oben rechts einen Serial Port (RS-485-Adapter) als übergeordnete Instanz wählen.';
        } else {
            $st = (int) IPS_GetInstance($parent)['InstanceStatus'];
            $lines[] = ($st === 102 ? '✅' : '⚠️') . ' Serial Port #' . $parent . ' (' . IPS_GetName($parent) . '), Status ' . $st . ($st === 102 ? '' : ' – nicht aktiv');
            $cfg = @json_decode(IPS_GetConfiguration($parent), true);
            if (is_array($cfg)) {
                $have = [];
                foreach (['BaudRate', 'DataBits', 'Parity', 'StopBits'] as $k) {
                    if (isset($cfg[$k])) {
                        $have[] = $k . ' ' . $cfg[$k];
                    }
                }
                if ($have !== []) {
                    $lines[] = 'ℹ️ Serial Port: ' . implode(', ', $have) . '. Vorgabe der Richtlinie: 19200, 8 Datenbits, gerade Parität, 1 Stoppbit.';
                }
            }
        }
        $rows = json_decode($this->ReadPropertyString('Points'), true) ?: [];
        $points = FW101_Station::normalize($rows);
        if ($rows === []) {
            $lines[] = 'ℹ️ Noch keine Datenpunkte. Unten „Vorlage laden“ wählen.';
        } else {
            if (count($points) < count($rows)) {
                $lines[] = '⛔ ' . (count($rows) - count($points)) . ' Zeile(n) mit ungültiger Adresse oder ungültigem Typ werden ignoriert.';
            }
            $stats = $this->withStation(fn (FW101_Station $st) => $st->stats());
            if (is_array($stats)) {
                $lines[] = ($stats['mapped'] > 0 ? '✅' : 'ℹ️') . ' ' . $stats['mapped'] . ' von ' . count($points) . ' Datenpunkten belegt.';
                if ($stats['reqMissing'] !== []) {
                    $lines[] = '⚠️ Pflichtpunkte ohne Variable: ' . implode(', ', array_slice($stats['reqMissing'], 0, 8)) . (count($stats['reqMissing']) > 8 ? ' … (+' . (count($stats['reqMissing']) - 8) . ')' : '');
                }
                if ($stats['lastContact'] !== null) {
                    $age = (int) (microtime(true) - $stats['lastContact']);
                    $lines[] = ($age < 120 ? '✅' : 'ℹ️') . ' Letzter Kontakt des Kommunikationsmoduls vor ' . $age . ' s.';
                }
                if ($stats['failApplied']) {
                    $lines[] = '⚠️ Ausfallwerte sind aktiv (Kommunikationsmodul länger nicht erreichbar).';
                }
            }
        }
        return implode("\n", $lines);
    }
}
