<?php

declare(strict_types=1);

require_once __DIR__ . '/FW101_Asdu.php';
require_once __DIR__ . '/FW101_Link.php';

// Anwendungsschicht der Unterstation: Datenpunkte, Ereigniswarteschlange (Klasse 1),
// Generalabfrage, Befehle und Sollwerte, Entprellung/Flattern, Ausfallverhalten.
// Unabhaengig vom IPS; alle Zugriffe auf Symcon laufen ueber FW101_Host.

interface FW101_Host
{
    public function value(int $varId): mixed;

    /** Sollwert/Befehl in die Ziel-Variable schreiben (RequestAction). true = ausgefuehrt. */
    public function action(int $varId, mixed $value): bool;

    /** IPS-Variablentyp: 0 Boolean, 1 Integer, 2 Float, 3 String. */
    public function varType(int $varId): int;

    public function store(string $key, mixed $value): void;

    public function load(string $key): mixed;

    /** true, wenn die Anlage auf Ort-Betrieb steht (Fernbefehle gesperrt). */
    public function localMode(): bool;

    public function log(string $message): void;

    public function now(): float;
}

final class FW101_Station implements FW101_LinkHost
{
    private const MONITOR = [13, 30, 31, 36];
    private const CONTROL = [45, 46, 50];
    private const Q1_MAX = 1000;

    /** @var array<int,array> Datenpunkte, nach IOA */
    private array $mon = [];
    /** @var array<int,array> */
    private array $ctl = [];
    /** @var array<int,bool> */
    private array $mirrorTargets = [];

    private array $q1 = [];
    private array $st = [];
    private ?float $lastContact = null;
    private bool $failApplied = false;
    private float $lastStore = 0.0;

    /**
     * @param array $cfg ca:int, acceptCaZero:bool, cotLen, caLen, ioaLen, timeMode:string, pct:float,
     *                   minDelta:float, maxInterval:int, failSeconds:int, localBlocksSetpoints:bool, sendInit:bool
     * @param array $points Ausgabe von normalize()
     */
    public function __construct(private array $cfg, array $points, private FW101_Host $host)
    {
        foreach ($points as $p) {
            if (in_array($p['type'], self::MONITOR, true)) {
                $this->mon[$p['ioa']] = $p;
            } elseif (in_array($p['type'], self::CONTROL, true)) {
                $this->ctl[$p['ioa']] = $p;
                if ($p['mirror'] !== null) {
                    $this->mirrorTargets[$p['mirror']] = true;
                }
            }
        }
        $this->lastContact = $this->host->now();
        $stored = $this->host->load('lastContact');
        if (is_numeric($stored) && (float) $stored > 0 && (float) $stored <= $this->lastContact) {
            $this->lastContact = (float) $stored;
        }
    }

    /**
     * Zeilen des Formulars in Datenpunkte umwandeln.
     * @param array<int,array> $rows
     * @return array<int,array>
     */
    public static function normalize(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $ioa = FW101_Asdu::parseIoa((string) ($r['IOA'] ?? ''));
            $type = (int) ($r['Type'] ?? 0);
            if ($ioa === null || !in_array($type, array_merge(self::MONITOR, self::CONTROL), true)) {
                continue;
            }
            $num = static fn ($v): ?float => (is_numeric($v) && trim((string) $v) !== '') ? (float) $v : null;
            $factor = (float) ($r['Factor'] ?? 1);
            $out[] = [
                'name'   => (string) ($r['Name'] ?? ''),
                'type'   => $type,
                'ioa'    => $ioa,
                'var'    => (int) ($r['Var'] ?? 0),
                'factor' => $factor == 0.0 ? 1.0 : $factor,
                'delta'  => (float) ($r['Delta'] ?? 0),
                'min'    => $num($r['Min'] ?? ''),
                'max'    => $num($r['Max'] ?? ''),
                'mirror' => FW101_Asdu::parseIoa((string) ($r['Mirror'] ?? '')),
                'fail'   => $num($r['Fail'] ?? ''),
                'req'    => (bool) ($r['Req'] ?? false),
            ];
        }
        return $out;
    }

    // ---------------------------------------------------------------- Zustand

    public function export(): array
    {
        return ['q1' => $this->q1, 'st' => $this->st, 'lc' => $this->lastContact, 'fa' => $this->failApplied, 'ls' => $this->lastStore];
    }

    public function import(array $s): void
    {
        $this->q1 = $s['q1'] ?? [];
        $this->st = $s['st'] ?? [];
        $this->lastContact = $s['lc'] ?? $this->lastContact;
        $this->failApplied = (bool) ($s['fa'] ?? false);
        $this->lastStore = (float) ($s['ls'] ?? 0);
    }

    /** Aktuelle Werte einlesen, ohne Ereignisse zu erzeugen (Start, Formular speichern). */
    public function preload(): void
    {
        $now = $this->host->now();
        foreach ($this->mon as $ioa => $p) {
            $s = $this->stateOf($ioa);
            if ($p['var'] > 0) {
                $v = $this->readConvert($p);
                if ($v !== null) {
                    $s['v'] = $v;
                    $s['sentV'] = $v;
                    $s['ts'] = $now;
                    $s['sentAt'] = $now;
                }
            } elseif (isset($this->mirrorTargets[$ioa])) {
                $stored = $this->mirrorStored($ioa);
                if ($stored !== null) {
                    $s['v'] = $this->convertWire($p, $stored);
                    $s['sentV'] = $s['v'];
                    $s['ts'] = $now;
                    $s['sentAt'] = $now;
                }
            }
            $this->st[$ioa] = $s;
        }
    }

    public function stats(): array
    {
        $mapped = 0;
        $reqMissing = [];
        foreach ($this->mon as $ioa => $p) {
            if ($p['var'] > 0 || isset($this->mirrorTargets[$ioa])) {
                $mapped++;
            } elseif ($p['req']) {
                $reqMissing[] = $p['name'] !== '' ? $p['name'] : FW101_Asdu::formatIoa($ioa);
            }
        }
        foreach ($this->ctl as $p) {
            if ($p['var'] <= 0 && $p['req']) {
                $reqMissing[] = $p['name'] !== '' ? $p['name'] : FW101_Asdu::formatIoa($p['ioa']);
            }
        }
        return [
            'queue'       => count($this->q1),
            'lastContact' => $this->lastContact,
            'failApplied' => $this->failApplied,
            'mapped'      => $mapped,
            'reqMissing'  => $reqMissing,
        ];
    }

    private function stateOf(int $ioa): array
    {
        return $this->st[$ioa] ?? ['v' => null, 'ts' => null, 'iv' => false, 'sentV' => null, 'sentAt' => null, 'hist' => [], 'flutter' => false, 'lastChange' => 0.0, 'pend' => null];
    }

    // ------------------------------------------------------- LinkHost-Schnittstelle

    public function linkReset(): void
    {
        $this->q1 = [];
        if ($this->cfg['sendInit']) {
            $this->push($this->asduHeader(FW101_Asdu::M_EI_NA_1, 1, FW101_Asdu::COT_INIT) . FW101_Asdu::ioa(0, $this->cfg['ioaLen']) . chr(0));
        }
    }

    public function class1Pending(): bool
    {
        return $this->q1 !== [];
    }

    public function popClass1(): ?string
    {
        return array_shift($this->q1);
    }

    public function popClass2(): ?string
    {
        return null;
    }

    public function contact(): void
    {
        $now = $this->host->now();
        $this->lastContact = $now;
        $this->failApplied = false;
        if ($now - $this->lastStore >= 60) {
            $this->lastStore = $now;
            $this->host->store('lastContact', $now);
        }
    }

    // -------------------------------------------------- Werte aus Symcon (Meldungen/Messwerte)

    /** Eine Quellvariable hat sich geaendert. */
    public function variableChanged(int $varId): void
    {
        foreach ($this->mon as $ioa => $p) {
            if ($p['var'] === $varId) {
                $v = $this->readConvert($p);
                if ($v !== null) {
                    $this->update($p, $v, $this->host->now());
                }
            }
        }
    }

    private function readConvert(array $p): int|float|null
    {
        try {
            $raw = $this->host->value($p['var']);
        } catch (Throwable) {
            return null;
        }
        if ($raw === null) {
            return null;
        }
        return match ($p['type']) {
            30 => $raw ? 1 : 0,
            31 => is_bool($raw) ? ($raw ? 2 : 1) : ((int) $raw & 3),
            default => (float) $raw * $p['factor'],
        };
    }

    private function convertWire(array $p, float $wire): int|float
    {
        return match ($p['type']) {
            30 => $wire != 0.0 ? 1 : 0,
            31 => (int) $wire & 3,
            default => $wire,
        };
    }

    private function update(array $p, int|float $v, float $now): void
    {
        $ioa = $p['ioa'];
        $s = $this->stateOf($ioa);
        $old = $s['v'];
        $s['v'] = $v;
        $s['ts'] = $now;
        $this->st[$ioa] = $s;

        if ($p['type'] === 30 || $p['type'] === 31) {
            if ($old !== null && $old !== $v) {
                $this->binaryChange($p, $v, $now);
            } elseif ($old === null) {
                $s = $this->stateOf($ioa);
                $s['sentV'] = $v;
                $s['sentAt'] = $now;
                $this->st[$ioa] = $s;
            }
            return;
        }
        $sent = $s['sentV'];
        if ($sent === null) {
            $this->sendPoint($p, FW101_Asdu::COT_SPONT);
            return;
        }
        $thr = $p['delta'] > 0 ? $p['delta'] : max($this->cfg['pct'] / 100 * abs((float) $sent), $this->cfg['minDelta']);
        if (abs($v - (float) $sent) >= $thr) {
            $this->sendPoint($p, FW101_Asdu::COT_SPONT);
        }
    }

    /** Meldungen: Flattererkennung (>= 6 Wechsel in 10 s), Zwischen-/Stoerstellung verzoegert. */
    private function binaryChange(array $p, int $v, float $now): void
    {
        $ioa = $p['ioa'];
        $s = $this->stateOf($ioa);
        $s['hist'] = array_values(array_filter($s['hist'], static fn ($t) => $t >= $now - 10));
        $s['hist'][] = $now;
        $s['lastChange'] = $now;
        if ($s['flutter']) {
            $this->st[$ioa] = $s;
            return;
        }
        if (count($s['hist']) >= 6) {
            $s['flutter'] = true;
            $s['iv'] = true;
            $s['pend'] = null;
            $this->st[$ioa] = $s;
            $this->sendPoint($p, FW101_Asdu::COT_SPONT);
            return;
        }
        if ($p['type'] === 31 && ($v === 0 || $v === 3)) {
            $s['pend'] = [$v, $now + ($v === 0 ? 10.0 : 1.0)];
            $this->st[$ioa] = $s;
            return;
        }
        $s['pend'] = null;
        $this->st[$ioa] = $s;
        if ($v !== $s['sentV']) {
            $this->sendPoint($p, FW101_Asdu::COT_SPONT);
        }
    }

    /** Regelmaessig aufrufen (ca. alle 5 s). */
    public function tick(): void
    {
        $now = $this->host->now();
        foreach ($this->mon as $ioa => $p) {
            $s = $this->stateOf($ioa);
            if ($s['v'] === null) {
                continue;
            }
            if ($s['flutter'] && $now - $s['lastChange'] >= 30) {
                $s['flutter'] = false;
                $s['iv'] = false;
                $s['hist'] = [];
                $this->st[$ioa] = $s;
                $this->sendPoint($p, FW101_Asdu::COT_SPONT);
                continue;
            }
            if ($s['pend'] !== null && $now >= $s['pend'][1]) {
                $pend = $s['pend'];
                $s['pend'] = null;
                $this->st[$ioa] = $s;
                if ($s['v'] === $pend[0] && $s['sentV'] !== $pend[0]) {
                    $this->sendPoint($p, FW101_Asdu::COT_SPONT);
                }
                continue;
            }
            if (($p['type'] === 36 || $p['type'] === 13) && $s['sentAt'] !== null && $now - $s['sentAt'] >= $this->cfg['maxInterval']) {
                $this->sendPoint($p, FW101_Asdu::COT_SPONT);
            }
        }
        if ($this->cfg['failSeconds'] > 0 && !$this->failApplied && $this->lastContact !== null
            && $now - $this->lastContact >= $this->cfg['failSeconds']) {
            $this->applyFailSafe();
        }
    }

    private function applyFailSafe(): void
    {
        $this->failApplied = true;
        $this->host->log('Kommunikationsmodul ausgefallen (> ' . $this->cfg['failSeconds'] . ' s): Sollwerte auf Ausfallwert');
        foreach ($this->ctl as $p) {
            if ($p['type'] === 50 && $p['fail'] !== null) {
                $this->executeSetpoint($p, $p['fail']);
            }
        }
    }

    /** Beim Start die zuletzt gueltigen Sollwerte erneut in die Ziel-Variablen schreiben. */
    public function applyStoredSetpoints(): void
    {
        foreach ($this->ctl as $p) {
            if ($p['type'] !== 50 || $p['var'] <= 0) {
                continue;
            }
            $v = $this->host->load('sp_' . dechex($p['ioa']));
            if (is_numeric($v)) {
                $this->host->action($p['var'], $this->actionValue($p, (float) $v / $p['factor']));
            }
        }
    }

    // -------------------------------------------------------------- Kodierung

    private function asduHeader(int $type, int $count, int $cause, bool $pn = false, ?int $ca = null): string
    {
        return FW101_Asdu::header($type, $count, $cause, $pn, 0, $ca ?? $this->cfg['ca'], $this->cfg);
    }

    private function push(string $asdu): void
    {
        $this->q1[] = $asdu;
        if (count($this->q1) > self::Q1_MAX) {
            array_shift($this->q1);
        }
    }

    private function objectBytes(array $p, array $s, bool $known): string
    {
        $ts = $s['ts'] ?? $this->host->now();
        $v = $s['v'] ?? 0;
        $q = ($s['iv'] ? 0x80 : 0) | ($known ? 0 : 0x40);
        $o = FW101_Asdu::ioa($p['ioa'], $this->cfg['ioaLen']);
        switch ($p['type']) {
            case 30:
                return $o . chr(((int) $v & 1) | $q) . FW101_Asdu::cp56($ts, $this->cfg['timeMode']);
            case 31:
                return $o . chr(((int) $v & 3) | $q) . FW101_Asdu::cp56($ts, $this->cfg['timeMode']);
            case 1:
                return $o . chr(((int) $v & 1) | $q);
            case 3:
                return $o . chr(((int) $v & 3) | $q);
            case 36:
                return $o . pack('g', (float) $v) . chr($q) . FW101_Asdu::cp56($ts, $this->cfg['timeMode']);
            default: // 13
                return $o . pack('g', (float) $v) . chr($q);
        }
    }

    private function sendPoint(array $p, int $cause): void
    {
        $ioa = $p['ioa'];
        $s = $this->stateOf($ioa);
        $this->push($this->asduHeader($p['type'], 1, $cause) . $this->objectBytes($p, $s, true));
        $s['sentV'] = $s['v'];
        $s['sentAt'] = $this->host->now();
        $this->st[$ioa] = $s;
    }

    // ------------------------------------------------------------ Befehle / Abfragen

    public function receiveAsdu(string $asdu): void
    {
        $h = FW101_Asdu::parse($asdu, $this->cfg);
        if ($h === null || $h['test']) {
            return;
        }
        $bcast = $h['ca'] === (1 << (8 * $this->cfg['caLen'])) - 1;
        if ($h['ca'] !== $this->cfg['ca'] && !$bcast && !($this->cfg['acceptCaZero'] && $h['ca'] === 0)) {
            $this->push($this->mirror($asdu, FW101_Asdu::COT_UNKNOWN_CA));
            return;
        }
        switch ($h['type']) {
            case FW101_Asdu::C_IC_NA_1:
                $this->handleInterrogation($asdu, $h);
                return;
            case FW101_Asdu::C_CS_NA_1:
            case FW101_Asdu::C_TS_NA_1:
            case FW101_Asdu::C_TS_TA_1:
                $this->push($this->mirror($asdu, FW101_Asdu::COT_ACTCON));
                return;
            case FW101_Asdu::C_SC_NA_1:
            case FW101_Asdu::C_DC_NA_1:
            case FW101_Asdu::C_SE_NC_1:
            case FW101_Asdu::C_SC_TA_1:
            case FW101_Asdu::C_DC_TA_1:
            case FW101_Asdu::C_SE_TC_1:
                $this->handleCommand($asdu, $h);
                return;
            default:
                $this->push($this->mirror($asdu, FW101_Asdu::COT_UNKNOWN_TYPE));
        }
    }

    private function mirror(string $asdu, int $cause, bool $pn = false): string
    {
        $keep = ord($asdu[2]) & 0x80;
        return substr($asdu, 0, 2) . chr(($cause & 0x3F) | ($pn ? 0x40 : 0) | $keep) . substr($asdu, 3);
    }

    private function handleInterrogation(string $asdu, array $h): void
    {
        $il = $this->cfg['ioaLen'];
        if ($h['cause'] === FW101_Asdu::COT_DEACT) {
            $this->push($this->mirror($asdu, FW101_Asdu::COT_DEACTCON));
            return;
        }
        if ($h['cause'] !== FW101_Asdu::COT_ACT || strlen($h['body']) < $il + 1) {
            $this->push($this->mirror($asdu, FW101_Asdu::COT_UNKNOWN_COT));
            return;
        }
        $qoi = ord($h['body'][$il]);
        if ($qoi !== 20) {
            $this->push($this->mirror($asdu, FW101_Asdu::COT_ACTCON, true));
            return;
        }
        $this->push($this->mirror($asdu, FW101_Asdu::COT_ACTCON));
        $this->queueInterrogationData();
        $this->push($this->mirror($asdu, FW101_Asdu::COT_ACTTERM));
    }

    /** Alle belegten Meldungen und Messwerte mit Ursache "abgefragt". */
    public function queueInterrogationData(): void
    {
        $byType = [];
        foreach ($this->mon as $ioa => $p) {
            $s = $this->stateOf($ioa);
            $known = $s['v'] !== null;
            if ($p['var'] > 0) {
                $v = $this->readConvert($p);
                if ($v !== null) {
                    $s['v'] = $v;
                    $s['ts'] = $s['ts'] ?? $this->host->now();
                    $known = true;
                }
            } elseif (!isset($this->mirrorTargets[$ioa])) {
                continue;
            }
            $wireType = $p['type'];
            if (!empty($this->cfg['giPlain'])) {
                $wireType = [30 => 1, 31 => 3, 36 => 13][$wireType] ?? $wireType;
            }
            $byType[$wireType][] = $this->objectBytes(['type' => $wireType] + $p, $s, $known);
        }
        ksort($byType);
        foreach ($byType as $type => $objs) {
            foreach (array_chunk($objs, 8) as $chunk) {
                $this->push($this->asduHeader($type, count($chunk), FW101_Asdu::COT_INROGEN) . implode('', $chunk));
            }
        }
    }

    private function handleCommand(string $asdu, array $h): void
    {
        $t = $h['type'];
        $il = $this->cfg['ioaLen'];
        $blen = FW101_Asdu::commandBodyLength($t);
        if ($h['cause'] === FW101_Asdu::COT_DEACT) {
            $this->push($this->mirror($asdu, FW101_Asdu::COT_DEACTCON));
            return;
        }
        if ($h['cause'] !== FW101_Asdu::COT_ACT) {
            $this->push($this->mirror($asdu, FW101_Asdu::COT_UNKNOWN_COT));
            return;
        }
        if ($h['count'] !== 1 || strlen($h['body']) < $il + $blen) {
            $this->push($this->mirror($asdu, FW101_Asdu::COT_ACTCON, true));
            return;
        }
        $ioa = FW101_Asdu::readLe($h['body'], 0, $il);
        $base = match ($t) {
            FW101_Asdu::C_SC_TA_1 => FW101_Asdu::C_SC_NA_1,
            FW101_Asdu::C_DC_TA_1 => FW101_Asdu::C_DC_NA_1,
            FW101_Asdu::C_SE_TC_1 => FW101_Asdu::C_SE_NC_1,
            default => $t,
        };
        $p = $this->ctl[$ioa] ?? null;
        if ($p === null || $p['type'] !== $base) {
            $this->push($this->mirror($asdu, FW101_Asdu::COT_UNKNOWN_IOA));
            return;
        }
        $isSetpoint = $base === FW101_Asdu::C_SE_NC_1;
        if ($this->host->localMode() && (!$isSetpoint || $this->cfg['localBlocksSetpoints'])) {
            $this->host->log('Befehl ' . FW101_Asdu::formatIoa($ioa) . ' abgelehnt: Ort-Betrieb');
            $this->push($this->mirror($asdu, FW101_Asdu::COT_ACTCON, true));
            return;
        }
        $e = substr($h['body'], $il, $blen);
        $select = false;
        $ok = false;
        if ($base === FW101_Asdu::C_SC_NA_1) {
            $select = (ord($e[0]) & 0x80) !== 0;
            $ok = true;
            $val = ord($e[0]) & 1;
        } elseif ($base === FW101_Asdu::C_DC_NA_1) {
            $dcs = ord($e[0]) & 3;
            $select = (ord($e[0]) & 0x80) !== 0;
            $ok = $dcs === 1 || $dcs === 2;
            $val = $dcs;
        } else {
            $val = unpack('g', substr($e, 0, 4))[1];
            $select = (ord($e[4]) & 0x80) !== 0;
            $ok = is_finite($val)
                && ($p['min'] === null || $val >= $p['min'])
                && ($p['max'] === null || $val <= $p['max']);
        }
        if (!$ok) {
            $this->push($this->mirror($asdu, FW101_Asdu::COT_ACTCON, true));
            return;
        }
        if ($select) {
            $this->push($this->mirror($asdu, FW101_Asdu::COT_ACTCON));
            return;
        }
        if ($isSetpoint) {
            $done = $this->executeSetpoint($p, (float) $val, $asdu);
        } else {
            $done = $this->executeCommand($p, (int) $val);
        }
        $this->push($this->mirror($asdu, FW101_Asdu::COT_ACTCON, !$done));
        if ($done && !$isSetpoint) {
            $this->push($this->mirror($asdu, FW101_Asdu::COT_ACTTERM));
        }
        if ($done) {
            $this->applyMirror($p, (float) $val);
        }
    }

    private function actionValue(array $p, float $local): mixed
    {
        if ($p['var'] <= 0) {
            return $local;
        }
        return match ($this->host->varType($p['var'])) {
            0 => $local != 0.0,
            1 => (int) round($local),
            default => $local,
        };
    }

    private function executeCommand(array $p, int $val): bool
    {
        if ($p['var'] <= 0) {
            return true;
        }
        try {
            if ($p['type'] === 45) {
                return $this->host->action($p['var'], $this->actionValue($p, (float) $val));
            }
            $t = $this->host->varType($p['var']);
            return $this->host->action($p['var'], $t === 0 ? $val === 2 : ($t === 1 ? $val : (float) $val));
        } catch (Throwable $e) {
            $this->host->log('Befehl fehlgeschlagen: ' . $e->getMessage());
            return false;
        }
    }

    /** Sollwert setzen. $asdu nur fuer den Ausfallfall null. Gibt true bei Erfolg zurueck. */
    private function executeSetpoint(array $p, float $wire, ?string $asdu = null): bool
    {
        try {
            if ($p['var'] > 0 && !$this->host->action($p['var'], $this->actionValue($p, $wire / $p['factor']))) {
                return false;
            }
        } catch (Throwable $e) {
            $this->host->log('Sollwert fehlgeschlagen: ' . $e->getMessage());
            return false;
        }
        $this->host->store('sp_' . dechex($p['ioa']), $wire);
        if ($asdu === null) {
            $this->applyMirror($p, $wire);
        }
        return true;
    }

    /** Rueckmeldepunkt (ohne eigene Quellvariable) auf den empfangenen Wert setzen und sofort senden. */
    private function applyMirror(array $p, float $wire): void
    {
        if ($p['mirror'] === null || !isset($this->mon[$p['mirror']])) {
            return;
        }
        $m = $this->mon[$p['mirror']];
        if ($m['var'] > 0) {
            return; // echte Rueckmeldung uebernimmt
        }
        if ($p['type'] === 46) {
            $v = $m['type'] === 31 ? ((int) $wire & 3) : ($wire === 2.0 ? 1 : 0);
        } elseif ($p['type'] === 45 && $m['type'] === 31) {
            $v = $wire != 0.0 ? 2 : 1; // Doppelmeldung: 1 = AUS, 2 = EIN
        } else {
            $v = $this->convertWire($m, $wire);
        }
        $s = $this->stateOf($m['ioa']);
        $s['v'] = $v;
        $s['ts'] = $this->host->now();
        $s['iv'] = false;
        $this->st[$m['ioa']] = $s;
        $this->sendPoint($m, FW101_Asdu::COT_SPONT);
    }

    private function mirrorStored(int $mirrorIoa): ?float
    {
        foreach ($this->ctl as $p) {
            if ($p['mirror'] === $mirrorIoa && $p['type'] === 50) {
                $v = $this->host->load('sp_' . dechex($p['ioa']));
                return is_numeric($v) ? (float) $v : null;
            }
        }
        return null;
    }
}
