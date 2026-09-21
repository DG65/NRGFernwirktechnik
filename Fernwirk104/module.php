<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/FW104_Link.php';
require_once __DIR__ . '/../libs/FW104_Presets.php';
require_once __DIR__ . '/../libs/FW_IpsHost.php';
require_once __DIR__ . '/../libs/FW_FormPanels.php';

// Fernwirk104: Symcon als gesteuerte Station (Server) nach IEC 60870-5-104 am Fernwirkgateway eines
// Netzbetreibers. Die Anwendungsschicht (FW101_Station) ist dieselbe wie beim Modul Fernwirk101, die
// Verbindungsschicht (FW104_Link: APCI, STARTDT/STOPDT/TESTFR, Folgezaehler, k/w, t0-t3) liegt in libs/
// und ist ohne IPS getestet (tests/run104.php, Interoperabilitaet mit lib60870 ueber tests/interop104.php).
// Dieses Modul ist nur die Anbindung an Symcon: Server Socket, Variablen, Formular.

class Fernwirk104 extends IPSModule
{
    use FW_FormPanels;

    private const PREFIX = 'FW104';
    private const MODULE_GUID = '{88828FDD-BD0E-4C91-887C-80AE56387F13}';
    private const DATA_TO_PARENT = '{C8792760-65CF-4C53-B5C7-A30FCC84FEFE}';

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('Points', '[]');
        $this->RegisterPropertyString('Preset', 'ewe_a_pv');
        $this->RegisterPropertyInteger('Port', 2404);
        $this->RegisterPropertyInteger('CommonAddress', 1);
        $this->RegisterPropertyBoolean('AcceptCommonZero', false);
        $this->RegisterPropertyBoolean('SendInit', true);
        $this->RegisterPropertyBoolean('GiPlain', true);
        $this->RegisterPropertyString('TimeMode', 'utc');
        $this->RegisterPropertyInteger('T0', 30);
        $this->RegisterPropertyInteger('T1', 15);
        $this->RegisterPropertyInteger('T2', 10);
        $this->RegisterPropertyInteger('T3', 20);
        $this->RegisterPropertyInteger('K', 12);
        $this->RegisterPropertyInteger('W', 8);
        $this->RegisterPropertyFloat('ThresholdPercent', 1.0);
        $this->RegisterPropertyFloat('MinDelta', 0.001);
        $this->RegisterPropertyInteger('MaxInterval', 300);
        $this->RegisterPropertyInteger('FailHours', 0);
        $this->RegisterPropertyInteger('LocalModeVariable', 0);
        $this->RegisterPropertyBoolean('LocalBlocksSetpoints', false);
        $this->RegisterPropertyBoolean('RestoreOnStart', true);

        $this->registerDismissAttributes();
        $this->RegisterTimer('Tick', 0, 'FW104_Tick($_IPS[\'TARGET\']);');
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

        $this->RegisterVariableInteger('LastContact', 'Letzter Kontakt der Zentralstation', '~UnixTimestamp', 1);
        $this->RegisterVariableBoolean('DataActive', 'Datenübertragung aktiv (STARTDT)', '~Switch', 2);
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

        $t1 = $this->ReadPropertyInteger('T1');
        $timersOk = $this->ReadPropertyInteger('T2') < $t1 && $this->ReadPropertyInteger('W') <= $this->ReadPropertyInteger('K');
        if ($points === []) {
            $this->SetStatus(104);
        } elseif ($invalid > 0) {
            $this->SetStatus(201);
        } else {
            $this->SetStatus($timersOk ? 102 : 202);
        }

        // Neuer, leerer Zustand mit den aktuellen Werten (Verbindungen fallen weg: die Zentralstation baut neu auf)
        $this->SetBuffer('state', '');
        $this->withStation(function (FW101_Station $st) {
            $st->preload();
        });
        $this->SetTimerInterval('Tick', $points === [] ? 0 : 1000);

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
            $this->withStation(function (FW101_Station $st, FW104_Link $link, callable $send) use ($SenderID) {
                $st->variableChanged((int) $SenderID);
                $send($link->pump(microtime(true)));
            });
        }
    }

    /** Daten und Verbindungsereignisse des Server Sockets (Type 0 Daten, 1 verbunden, 2 getrennt). */
    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data)) {
            return '';
        }
        $ip = (string) ($data['ClientIP'] ?? '');
        $port = (int) ($data['ClientPort'] ?? 0);
        $type = (int) ($data['Type'] ?? 0);
        $key = $ip . ':' . $port;
        $bytes = mb_convert_encoding((string) ($data['Buffer'] ?? ''), 'ISO-8859-1', 'UTF-8');
        $this->withStation(function (FW101_Station $st, FW104_Link $link, callable $send) use ($type, $key, $bytes) {
            $now = microtime(true);
            if ($type === 1) {
                $link->connected($key, $now);
            } elseif ($type === 2) {
                $link->disconnected($key);
            } elseif ($bytes !== '') {
                $this->SendDebug('RX ' . $key, $bytes, 1);
                $send($link->feed($key, $bytes, $now));
            }
        });
        return '';
    }

    /** Jede Sekunde: Timer t0-t3, zeitgesteuerte Meldungen, Ausfallverhalten, Statusvariablen. */
    public function Tick(): void
    {
        $r = $this->withStation(function (FW101_Station $st, FW104_Link $link, callable $send) {
            $st->tick();
            $send($link->tick(microtime(true)));
            return [$st->stats(), $link->diagnose()];
        });
        if (is_array($r)) {
            $this->applyStatusVariables($r[0], $r[1]);
        }
    }

    public function ShowPresetNotes(string $Preset): void
    {
        $this->UpdateFormField('PresetNotes', 'caption', implode("\n", FW104_Presets::notes($Preset)));
    }

    protected function licenseUrl(): string
    {
        return 'https://github.com/DG65/NRGFernwirktechnik/blob/beta/LICENSE';
    }

    protected function formTexts(): array
    {
        return [
            'purpose' => [
                'Dieses Modul macht IP-Symcon zur kundeneigenen Fernwirkstation (gesteuerte Station, Server) gegenüber dem Fernwirkgateway eines Netzbetreibers: Das Gateway verbindet sich über Ethernet nach IEC 60870-5-104, liest Messwerte und Meldungen und schickt Sollwerte und Befehle, etwa die Wirkleistungsbegrenzung (P/Pinst) oder cos φ.',
                'Der Nutzen: Wer ohnehin Symcon zur Anlagensteuerung nutzt, kann die Vorgaben des Netzbetreibers direkt umsetzen, statt ein zusätzliches Fernwirkgerät zu kaufen. Nicht zu verwechseln mit der Schnittstelle zum Direktvermarkter (getrennter Kanal, z. B. Modbus TCP; dafür gibt es den Modbus-TCP-Server). Für Netzbetreiber mit IEC 101 über RS-485 gibt es das Modul Fernwirk101.',
            ],
            'news' => [
                '• 🆕 Erste Fassung: IEC 60870-5-104 als gesteuerte Station mit STARTDT/STOPDT/TESTFR, Sende- und Empfangsfolgezähler, Fenster k und w und einstellbaren Timern t0 bis t3.',
                '• 🆕 Vorlagen aus den Unterlagen von EWE NETZ (V4.2, Anhang A und B je Energieart) und EWF (V1.7); Schwellen aus Pinst und PAV, Verbindungsparameter aus der Kompatibilitätsliste.',
                '• ⚠️ Getestet gegen einen unabhängigen 104-Client (lib60870) im lokalen Netz und mit eigenen Tests; noch nicht im IPS, nicht an einer echten Gegenstelle, nicht vom Netzbetreiber abgenommen.',
            ],
            'newsVersion' => '0.2',
            'doc' => [
                'Symcon ist die gesteuerte Station (Server). Das Fernwirkgateway des Netzbetreibers ist die Zentralstation (Client) und baut die TCP-Verbindung auf (EWE NETZ: Port 2404; Gateway 10.0.0.1/30, Unterstation 10.0.0.2/30). Diesem Modul einen Server Socket als übergeordnete Instanz geben und den Port dort einstellen.',
                'Eine aktive Verbindung: Nach STARTDT werden Daten gesendet. Baut die Zentralstation eine neue Verbindung auf und sendet STARTDT, übernimmt sie; die alte bekommt keine Daten mehr. Rahmen einer nicht gestarteten Verbindung werden ignoriert. Über t1 nicht quittierte Daten oder unbeantwortete Testrahmen beenden die Sitzung.',
                'Nach einem Verbindungsabbruch gehen nicht quittierte Meldungen verloren (die 104 setzt nicht über Verbindungen hinweg auf); die Zentralstation holt den Stand mit einer Generalabfrage. Beim STARTDT wird die Warteschlange geleert und „Initialisierung beendet“ gemeldet.',
                'Nicht umgesetzt: Dateiübertragung, Zählwerte, Uhrzeitsynchronisation der Symcon-Uhr (wird nur quittiert), „AUS mit Netztrennung“ bzw. Sofort-AUS (das ist bei EWE NETZ ein Binärkontakt am Gateway, hart verdrahtet und nie über 104 oder Symcon), Erstanlauf-Grundeinstellung (P 100 %, cos φ 1: Ziel-Variablen entsprechend vorbelegen).',
                'Entprellung im Millisekundenbereich (10 ms) ist im Symcon-Kernel nicht möglich; Flatterunterdrückung (mehr als 0,5 Hz, 30 s Stillsetzung) und Zwischen- und Störstellungsunterdrückung sind umgesetzt.',
                'Sicherheit bei EWE NETZ (Kapitel 7.3): Für die Dauer der Verbindung mit dem Fernwirkgateway darf die Hardware nicht gleichzeitig mit einem anderen Weitverkehrsnetz (z. B. Internet) verbunden sein. Ein Symcon-Rechner, der ins Internet geht, erfüllt das nur mit einer getrennten, nicht routenden Netzwerkschnittstelle zum Gateway; ob das für die Abnahme reicht, entscheidet der Netzbetreiber. EWF bindet über einen VPN-Router an. Zeitmarken (UTC oder Ortszeit), Abnahme und Netztrennung: siehe docs/KLAERUNG.md.',
                'Getrennte Kanäle: Diese Anbindung ist die Fernwirktechnik zum Netzbetreiber (§ 9 EEG, § 13 EnWG). Die Direktvermarkter-Schnittstelle (§ 10b EEG) ist ein eigener Kanal; Netzbetreiber verlangen ausdrücklich die Trennung.',
            ],
            'feedbackUrl' => '',
        ];
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $notesOf = static fn (string $p): string => implode("\n", FW104_Presets::notes($p));
        foreach ($form['elements'] as &$e) {
            if (($e['name'] ?? '') === 'StatusText') {
                $e['caption'] = $this->statusText();
            }
            if (($e['caption'] ?? '') === 'Verbindung zur Zentralstation des Netzbetreibers') {
                array_unshift($e['items'], $this->helpButton('Zeitmarken: Ortszeit oder UTC?', [
                    'Jede Meldung und jeder zeitgestempelte Messwert trägt eine Zeitmarke (CP56Time2a). Ob die Zentralstation sie als Ortszeit (mit Sommerzeitbit) oder als UTC erwartet, ist in den vorliegenden Unterlagen nicht festgelegt.',
                    'Belegt ist nur: die Uhrzeit, die die EWE-NETZ-Zentralstation sendet, ist Ortszeit mit Sommerzeitbit. Die EWE-Vorlage stellt deshalb „Ortszeit“ ein; das ist eine Annahme, bei EWE NETZ bestätigen lassen. Zeigt der Test einen Versatz von ein bis zwei Stunden, hier umstellen.',
                ], 480));
            }
            if (($e['caption'] ?? '') === 'Zeitüberwachung und Fenster (t0–t3, k, w)') {
                array_unshift($e['items'], $this->helpButton('Was sind t0 bis t3, k und w?', [
                    't1: so lange darf eine gesendete Meldung oder ein Testrahmen unquittiert bleiben, bevor die Sitzung beendet wird. t2: so lange wartet das Modul mit der Quittung eines Empfangs, wenn keine Daten zurückgehen (t2 kleiner als t1). t3: nach so viel Ruhe wird ein Testrahmen (TESTFR) gesendet. t0: so lange darf eine neue Verbindung ohne jeden Rahmen bleiben.',
                    'k: so viele Meldungen dürfen unquittiert unterwegs sein. w: nach so vielen empfangenen Rahmen wird spätestens quittiert (höchstens k).',
                    'EWE NETZ (Kompatibilitätsliste): t0 30 s, t1 15 s, t2 10 s, t3 20 s, k 12, w 8. EWF: t0 30 s, t1 250 s, t2 240 s, t3 255 s (k und w nicht angegeben). „Vorlage laden“ trägt diese Werte ein.',
                ], 520));
            }
            if (($e['caption'] ?? '') === 'Datenpunkte') {
                array_splice($e['items'], 1, 0, [$this->helpButton('Was bedeuten Faktor, Schwelle, Rückmeldung und Pflicht?', [
                    'Faktor: Wert auf der Leitung = Wert der Variable × Faktor. −1 dreht das Vorzeichen (z. B. „Erzeugung negativ“ nach dem Verbraucherzählpfeilsystem).',
                    'Schwelle: ab welcher Änderung ein Messwert spontan gesendet wird (in der Einheit des Messwerts, z. B. MW). 0 = die allgemeine Schwelle in Prozent aus „Verhalten“. „Vorlage laden“ setzt sie aus Pinst und PAV.',
                    'Min/Max: erlaubter Bereich eines Sollwerts; außerhalb wird der Sollwert mit negativer Quittung abgelehnt.',
                    'Rückmeldung: Adresse des Messwerts, der nach einem Sollwert oder Befehl exakt den empfangenen Wert meldet. Ausfallwert: Sollwert, auf den nach Ausfall der Zentralstation zurückgefallen wird (leer = letzter Sollwert bleibt, wie EWE NETZ es verlangt).',
                    'Pflicht: in der Vorlage vorangekreuzt. „Datenpunkte prüfen“ zeigt Pflichtpunkte ohne Variable.',
                ], 520)]);
            }
        }
        unset($e);
        foreach ($form['actions'] as &$a) {
            if (($a['name'] ?? '') === 'Preset') {
                $a['options'] = FW104_Presets::options();
            }
            if (($a['name'] ?? '') === 'PresetNotes') {
                $a['caption'] = $notesOf($this->ReadPropertyString('Preset'));
            }
        }
        unset($a);
        $form['elements'] = $this->assembleForm($form['elements']);
        return json_encode($form);
    }

    /** Vorlage laden; bereits eingetragene Variablen und Einstellungen je Adresse bleiben erhalten. */
    public function LoadPreset(string $Preset, int $ResourceIndex = 0, float $PinstMW = 0.0, float $PavMW = 0.0, float $EmaxMWh = 0.0, string $GridLevel = 'ms'): void
    {
        $new = FW104_Presets::points($Preset, ['index' => $ResourceIndex, 'pinst' => $PinstMW, 'pav' => $PavMW, 'emax' => $EmaxMWh, 'grid' => $GridLevel]);
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
                foreach (['Var', 'Factor', 'Min', 'Max', 'Fail'] as $f) {
                    if (array_key_exists($f, $byIoa[$key])) {
                        $r[$f] = $byIoa[$key][$f];
                    }
                }
                if ((float) $r['Delta'] <= 0 && array_key_exists('Delta', $byIoa[$key])) {
                    $r['Delta'] = $byIoa[$key]['Delta']; // eine neu berechnete Schwelle hat Vorrang
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
        foreach (FW104_Presets::link($Preset) ?? [] as $prop => $val) {
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
            $lines[] = sprintf('%-10s Typ %d  %s: %s%s', $addr, $p['type'], $p['name'], $state, $p['req'] && $p['var'] <= 0 ? '   [Pflicht!]' : '');
        }
        return $mapped . ' von ' . count($points) . " Datenpunkten mit Variable belegt.\n\n" . implode("\n", $lines);
    }

    /** Verbindungen der Zentralstation, Zaehler und letzte Ereignisse. */
    public function GetConnectionReport(): string
    {
        $d = $this->withStation(fn (FW101_Station $st, FW104_Link $link) => $link->diagnose());
        if (!is_array($d)) {
            return 'Keine Auskunft (Sperre nicht erhalten).';
        }
        $lines = [
            'Verbindungen: ' . ($d['connections'] === [] ? 'keine' : implode(', ', $d['connections'])),
            'Aktiv (STARTDT): ' . ($d['started'] ? $d['active'] : 'keine'),
            'Unquittierte Datenrahmen: ' . $d['unacked'],
            'Protokollfehler: ' . $d['stats']['protocolErrors'] . ', Zeitüberschreitungen: ' . $d['stats']['timeouts'] . ', Übernahmen: ' . $d['stats']['takeovers'],
            '',
            'Letzte Ereignisse:',
        ];
        return implode("\n", array_merge($lines, $d['events'] === [] ? ['(keine)'] : $d['events']));
    }

    // ------------------------------------------------------------------- intern

    /**
     * Station und Verbindungsschicht mit dem gespeicherten Zustand ausfuehren.
     * Gesendet wird innerhalb der Sperre, damit die Sendefolgenummern in der Reihenfolge auf die
     * Leitung gehen, in der sie vergeben wurden.
     */
    private function withStation(callable $fn): mixed
    {
        $sem = 'FW104_' . $this->InstanceID;
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
            $send = function (array $out): void {
                foreach ($out as $key => $bytes) {
                    if ($bytes === '') {
                        continue;
                    }
                    $pos = strrpos((string) $key, ':');
                    if ($pos === false) {
                        continue;
                    }
                    $this->SendDebug('TX ' . $key, $bytes, 1);
                    $this->SendDataToParent(json_encode([
                        'DataID'     => self::DATA_TO_PARENT,
                        'Buffer'     => mb_convert_encoding($bytes, 'UTF-8', 'ISO-8859-1'),
                        'ClientIP'   => substr((string) $key, 0, $pos),
                        'ClientPort' => (int) substr((string) $key, $pos + 1),
                        'Type'       => 0,
                    ]));
                }
            };
            $result = $fn($station, $link, $send);
            $this->SetBuffer('state', serialize(['station' => $station->export(), 'link' => $link->export()]));
            return $result;
        } catch (Throwable $e) {
            $this->LogMessage('Fernwirk104: ' . $e->getMessage(), KL_ERROR);
            return null;
        } finally {
            IPS_SemaphoreLeave($sem);
        }
    }

    /** @return array{0:FW101_Station,1:FW104_Link} */
    private function build(): array
    {
        $cfg = [
            'ca'                   => $this->ReadPropertyInteger('CommonAddress'),
            'acceptCaZero'         => $this->ReadPropertyBoolean('AcceptCommonZero'),
            'cotLen'               => 2,
            'caLen'                => 2,
            'ioaLen'               => 3,
            'timeMode'             => $this->ReadPropertyString('TimeMode'),
            'pct'                  => $this->ReadPropertyFloat('ThresholdPercent'),
            'minDelta'             => $this->ReadPropertyFloat('MinDelta'),
            'maxInterval'          => $this->ReadPropertyInteger('MaxInterval'),
            'failSeconds'          => $this->ReadPropertyInteger('FailHours') * 3600,
            'localBlocksSetpoints' => $this->ReadPropertyBoolean('LocalBlocksSetpoints'),
            'sendInit'             => $this->ReadPropertyBoolean('SendInit'),
            'giPlain'              => $this->ReadPropertyBoolean('GiPlain'),
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
                $this->LogMessage('Fernwirk104: ' . $m, KL_NOTIFY);
                $this->SendDebug('Log', $m, 0);
            },
        ]);
        $station = new FW101_Station($cfg, $points, $host);
        $link = new FW104_Link([
            't0' => $this->ReadPropertyInteger('T0'),
            't1' => $this->ReadPropertyInteger('T1'),
            't2' => $this->ReadPropertyInteger('T2'),
            't3' => $this->ReadPropertyInteger('T3'),
            'k'  => max(1, $this->ReadPropertyInteger('K')),
            'w'  => max(1, $this->ReadPropertyInteger('W')),
        ], $station, $host);
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
        $r = $this->withStation(fn (FW101_Station $st, FW104_Link $link) => [$st->stats(), $link->diagnose()]);
        if (is_array($r)) {
            $this->applyStatusVariables($r[0], $r[1]);
        }
    }

    private function applyStatusVariables(array $stats, array $diag): void
    {
        $this->setIfChanged('DataActive', (bool) $diag['started']);
        $this->setIfChanged('QueueLength', (int) $stats['queue']);
        $this->setIfChanged('FailSafeActive', (bool) $stats['failApplied']);
    }

    private function setIfChanged(string $ident, mixed $value): void
    {
        if ($this->GetValue($ident) !== $value) {
            $this->SetValue($ident, $value);
        }
    }

    private function statusText(): string
    {
        $lines = [];
        $parent = (int) IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($parent <= 0) {
            $lines[] = '⛔ Kein Server Socket verbunden. Oben rechts einen Server Socket als übergeordnete Instanz wählen (Port am Socket einstellen, Standard 2404).';
        } else {
            $st = (int) IPS_GetInstance($parent)['InstanceStatus'];
            $port = (int) @IPS_GetProperty($parent, 'Port');
            $open = (bool) @IPS_GetProperty($parent, 'Open');
            $lines[] = ($st === 102 ? '✅' : ($open ? '⛔' : 'ℹ️')) . ' Server Socket #' . $parent . ' (' . IPS_GetName($parent) . '), Port ' . $port . ', Status ' . $st . ($st === 102 ? '' : ($open ? ' – nicht aktiv (Port belegt?)' : ' – geschlossen'));
            $want = $this->ReadPropertyInteger('Port');
            if ($port !== $want) {
                $lines[] = '⚠️ Der Server Socket lauscht auf Port ' . $port . ', erwartet ist ' . $want . ' (Vorgabe des Netzbetreibers).';
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
            if ($this->ReadPropertyInteger('T2') >= $this->ReadPropertyInteger('T1')) {
                $lines[] = '⛔ t2 muss kleiner als t1 sein.';
            }
            if ($this->ReadPropertyInteger('W') > $this->ReadPropertyInteger('K')) {
                $lines[] = '⛔ w darf nicht größer als k sein.';
            }
            $r = $this->withStation(fn (FW101_Station $s, FW104_Link $link) => [$s->stats(), $link->diagnose()]);
            if (is_array($r)) {
                [$stats, $diag] = $r;
                $lines[] = ($stats['mapped'] > 0 ? '✅' : 'ℹ️') . ' ' . $stats['mapped'] . ' von ' . count($points) . ' Datenpunkten belegt.';
                if ($stats['reqMissing'] !== []) {
                    $lines[] = '⚠️ Pflichtpunkte ohne Variable: ' . implode(', ', array_slice($stats['reqMissing'], 0, 8)) . (count($stats['reqMissing']) > 8 ? ' … (+' . (count($stats['reqMissing']) - 8) . ')' : '');
                }
                if ($diag['started']) {
                    $lines[] = '✅ Zentralstation ' . $diag['active'] . ' verbunden, Datenübertragung aktiv.';
                } elseif ($diag['connections'] !== []) {
                    $lines[] = '⚠️ Verbindung von ' . implode(', ', $diag['connections']) . ', aber kein STARTDT (Datenübertragung nicht gestartet).';
                } else {
                    $lines[] = 'ℹ️ Noch keine Verbindung der Zentralstation.';
                }
                if ($stats['lastContact'] !== null && ($diag['active'] === null || !$diag['started'])) {
                    $lines[] = 'ℹ️ Letzter Kontakt vor ' . (int) (microtime(true) - $stats['lastContact']) . ' s.';
                }
                if ($stats['failApplied']) {
                    $lines[] = '⚠️ Ausfallwerte sind aktiv (Zentralstation länger nicht erreichbar).';
                }
            }
        }
        return implode("\n", $lines);
    }
}
