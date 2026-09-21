<?php

declare(strict_types=1);

// Protokolltests ohne IPS. Der Test-Master baut die Rahmen von Hand nach IEC 60870-5-101 (FT1.2),
// unabhaengig vom Code der Unterstation. Aufruf: php tests/run.php

date_default_timezone_set('Europe/Berlin');
require_once __DIR__ . '/../libs/FW101_Station.php';
require_once __DIR__ . '/../libs/FW101_Presets.php';

$GLOBALS['fails'] = 0;
$GLOBALS['checks'] = 0;
function ok(bool $cond, string $msg): void
{
    $GLOBALS['checks']++;
    if (!$cond) {
        $GLOBALS['fails']++;
        echo "  FEHLER: $msg\n";
    }
}
function eq(mixed $a, mixed $b, string $msg): void
{
    ok($a === $b, $msg . ' (erwartet ' . json_encode($b) . ', erhalten ' . json_encode($a) . ')');
}
function hx(string $s): string
{
    return strtoupper(bin2hex($s));
}

final class TestHost implements FW101_Host
{
    public array $vars = [];
    public array $types = [];
    public array $actions = [];
    public array $store = [];
    public bool $local = false;
    public float $t = 1790000000.0; // 2026-09-21 ...
    public array $logs = [];
    public bool $actionResult = true;

    public function value(int $varId): mixed
    {
        return $this->vars[$varId] ?? null;
    }
    public function action(int $varId, mixed $value): bool
    {
        $this->actions[] = [$varId, $value];
        return $this->actionResult;
    }
    public function varType(int $varId): int
    {
        return $this->types[$varId] ?? 2;
    }
    public function store(string $key, mixed $value): void
    {
        $this->store[$key] = $value;
    }
    public function load(string $key): mixed
    {
        return $this->store[$key] ?? null;
    }
    public function localMode(): bool
    {
        return $this->local;
    }
    public function log(string $message): void
    {
        $this->logs[] = $message;
    }
    public function now(): float
    {
        return $this->t;
    }
}

// ---- Test-Master (unsymmetrisch): baut Rahmen laut Norm
final class Master
{
    private int $fcb = 0;
    public function __construct(private int $addr = 1, private int $al = 1)
    {
    }
    private function a(): string
    {
        return $this->al === 1 ? chr($this->addr) : chr($this->addr & 0xFF) . chr($this->addr >> 8);
    }
    private static function cs(string $s): int
    {
        return array_sum(array_map('ord', str_split($s))) & 0xFF;
    }
    public function fixed(int $c): string
    {
        return "\x10" . chr($c) . $this->a() . chr(self::cs(chr($c) . $this->a())) . "\x16";
    }
    public function variable(int $c, string $asdu): string
    {
        $b = chr($c) . $this->a() . $asdu;
        return "\x68" . chr(strlen($b)) . chr(strlen($b)) . "\x68" . $b . chr(self::cs($b)) . "\x16";
    }
    public function resetLink(): string
    {
        $this->fcb = 0;
        return $this->fixed(0x40);
    }
    private function toggle(): int
    {
        $this->fcb ^= 1;
        return $this->fcb;
    }
    public function class1(?int $forceFcb = null): string
    {
        $f = $forceFcb ?? $this->toggle();
        return $this->fixed(0x40 | ($f ? 0x20 : 0) | 0x10 | 0x0A);
    }
    public function class2(): string
    {
        return $this->fixed(0x40 | ($this->toggle() ? 0x20 : 0) | 0x10 | 0x0B);
    }
    public function status(): string
    {
        return $this->fixed(0x49);
    }
    public function userData(string $asdu): string
    {
        return $this->variable(0x40 | ($this->toggle() ? 0x20 : 0) | 0x10 | 0x03, $asdu);
    }
    public function lastFcb(): int
    {
        return $this->fcb;
    }
}

function asdu(int $type, int $cause, string $body, int $ca = 1, int $count = 1): string
{
    return chr($type) . chr($count) . chr($cause) . "\x00" . chr($ca & 0xFF) . chr($ca >> 8) . $body;
}
function ioa3(int $h, int $m, int $l): string
{
    return chr($l) . chr($m) . chr($h); // Wire: low, mid, high
}

/** Alle anstehenden Klasse-1-Antworten abholen. Gibt die ASDUs zurueck. */
function drain(FW101_Link $link, Master $m, int $max = 40): array
{
    $out = [];
    for ($i = 0; $i < $max; $i++) {
        $r = $link->feed($m->class1());
        if ($r === '' || $r[0] !== "\x68") {
            break;
        }
        $l = ord($r[1]);
        $out[] = substr($r, 6, $l - 2);   // ASDU ohne C und A
    }
    return $out;
}

function mkRows(): array
{
    $r = [];
    $add = function (string $n, int $t, string $ioa, int $var = 0, array $x = []) use (&$r) {
        $r[] = array_merge(['Name' => $n, 'Type' => $t, 'IOA' => $ioa, 'Var' => $var, 'Factor' => 1.0, 'Delta' => 0.0, 'Min' => '', 'Max' => '', 'Mirror' => '', 'Fail' => '', 'Req' => false], $x);
    };
    $add('Vorgabe P', 50, '1.0.98', 900, ['Mirror' => '0.0.98', 'Min' => '0', 'Max' => '100', 'Fail' => '100']);
    $add('Rückm P', 36, '0.0.98');
    $add('P Anlage', 36, '0.0.9', 101, ['Factor' => -1.0]);
    $add('Frequenz', 36, '0.3.11', 102);
    $add('Schutz gestoert', 30, '0.1.51', 103);
    $add('LS Stellung', 31, '0.3.125', 104);
    $add('Befehl LS', 46, '1.3.125', 905);
    $add('QU ein', 45, '1.0.11', 906, ['Mirror' => '0.0.11']);
    $add('QU Rückm', 30, '0.0.11');
    $add('Unbelegt', 36, '0.9.5');
    return FW101_Station::normalize($r);
}

function build(?TestHost $h = null, array $over = []): array
{
    $h ??= new TestHost();
    $h->vars = [101 => 5.0, 102 => 50.0, 103 => false, 104 => 2];
    $h->types = [900 => 2, 905 => 1, 906 => 0, 101 => 2];
    $cfg = array_merge(['ca' => 1, 'acceptCaZero' => true, 'cotLen' => 2, 'caLen' => 2, 'ioaLen' => 3,
        'timeMode' => 'utc', 'pct' => 10.0, 'minDelta' => 0.001, 'maxInterval' => 60, 'failSeconds' => 43200,
        'localBlocksSetpoints' => false, 'sendInit' => true], $over);
    $st = new FW101_Station($cfg, mkRows(), $h);
    $st->preload();
    $link = new FW101_Link(['linkAddr' => 1, 'linkLen' => 1, 'ackSingleChar' => false], $st);
    return [$h, $st, $link, new Master()];
}

echo "1. Zeitmarke CP56Time2a\n";
$ts = (new DateTimeImmutable('2026-09-21 14:05:03.500', new DateTimeZone('UTC')))->format('U.u');
eq(hx(FW101_Asdu::cp56((float) $ts, 'utc')), 'AC0D050E35091A', 'CP56 21.09.2026 14:05:03.500 (Montag)');
eq(round(FW101_Asdu::parseCp56(hex2bin('AC0D050E35091A'), 'utc'), 3), round((float) $ts, 3), 'CP56 zurueckgelesen');
eq(substr(hx(FW101_Asdu::cp56((float) $ts, 'local')), 6, 2), '90', 'local: 16 Uhr (0x10) mit Sommerzeitbit (0x80)');

echo "2. IOA-Schreibweise\n";
eq(FW101_Asdu::parseIoa('1.0.98'), 0x010062, 'High.Mid.Low');
eq(FW101_Asdu::formatIoa(0x010062), '1.0.98', 'zurueck');
eq(FW101_Asdu::parseIoa('0.3.125'), 0x00037D, '0.3.125');
eq(FW101_Asdu::parseIoa('a.b'), null, 'ungueltig');
eq(FW101_Asdu::parseIoa('1.0.300'), null, 'Byte > 255');
eq(hx(FW101_Asdu::ioa(0x010062, 3)), '620001', 'Wire-Reihenfolge low, mid, high');

echo "3. Verbindungsaufbau, Byte-genau\n";
[$h, $st, $link, $m] = build();
eq(hx($m->resetLink()), '10400141' . '16', 'Master: Reset Verbindung');
$r = $link->feed($m->resetLink());
eq(hx($r), '1020012116', 'Antwort: ACK (Fixrahmen) mit ACD, weil M_EI ansteht');
$r = $link->feed($m->status());
eq(hx($r), '102B01' . '2C16', 'Verbindungsstatus mit ACD (0x20|0x0B)');
$r = $link->feed($m->class1());
$asdu = '460104' . '0001' . '00' . '000000' . '00';   // M_EI: Typ 70, 1 Obj, COT 4, OA 0, CA 1
$asdu = "\x46\x01\x04\x00\x01\x00\x00\x00\x00\x00";
$body = "\x08\x01" . $asdu;                    // C=Nutzdaten ohne ACD, A=1
$cs = array_sum(array_map('ord', str_split($body))) & 0xFF;
$exp = "\x68" . chr(strlen($body)) . chr(strlen($body)) . "\x68" . $body . chr($cs) . "\x16";
eq(hx($r), hx($exp), 'Klasse 1: M_EI_NA_1 (Initialisierung beendet)');
$r2 = $link->feed($m->class1());
eq(hx($r2), '1009010A16', 'kein Datum mehr: NACK "keine Daten" (0x09)');

echo "4. Wiederholung bei gleichem FCB (Uebertragungsfehler)\n";
[$h, $st, $link, $m] = build();
$link->feed($m->resetLink());
$a = $link->feed($m->class1());
$b = $link->feed($m->class1($m->lastFcb()));  // gleicher FCB: Wiederholung
eq(hx($b), hx($a), 'gleiche Antwort bei wiederholter Anfrage');
$c = $link->feed($m->class1());
eq(hx($c), '1009010A16', 'naechster FCB liefert nichts Neues (Queue war einmal abgeholt)');

echo "5. Klasse 2 und Status\n";
[$h, $st, $link, $m] = build();
$link->feed($m->resetLink());
$r = $link->feed($m->class2());
eq(hx($r), '1029012A16', 'Klasse 2 leer: NACK mit ACD (Klasse-1-Ereignis ansteht)');

echo "6. Zerstueckelter Empfang, Muell, Pruefsumme\n";
[$h, $st, $link, $m] = build();
$frame = $m->resetLink();
$out = '';
foreach (str_split("\xFF\x00" . $frame) as $byte) {
    $out .= $link->feed($byte);
}
eq(hx($out), '1020012116', 'byteweise + Muell vor dem Rahmen');
[$h, $st, $link, $m] = build();
$bad = "\x10\x40\x01\x42\x16";
eq($link->feed($bad), '', 'falsche Pruefsumme wird ignoriert');
$good = $link->feed($m->resetLink());
eq(hx($good), '1020012116', 'danach normal weiter');
[$h, $st, $link, $m] = build();
$other = (new Master(2))->resetLink();
eq($link->feed($other), '', 'fremde Linkadresse wird ignoriert');

echo "7. Generalabfrage\n";
[$h, $st, $link, $m] = build();
$link->feed($m->resetLink());
drain($link, $m); // M_EI
$gi = asdu(100, 6, ioa3(0, 0, 0) . chr(20));
$r = $link->feed($m->userData($gi));
ok($r !== '' && $r[0] === "\x10" && (ord($r[1]) & 0x0F) === 0, 'ACK (Fixrahmen, Funktion 0) auf Nutzdaten');
$msgs = drain($link, $m);
ok(count($msgs) >= 4, 'ACTCON, Daten, ACTTERM: ' . count($msgs));
eq(ord($msgs[0][0]), 100, 'erste ASDU: C_IC_NA_1');
eq(ord($msgs[0][2]) & 0x3F, 7, 'ACTCON');
eq(ord($msgs[0][2]) & 0x40, 0, 'positiv');
$last = end($msgs);
eq(ord($last[0]), 100, 'letzte ASDU: C_IC_NA_1');
eq(ord($last[2]) & 0x3F, 10, 'ACTTERM');
$types = [];
$objs = 0;
foreach (array_slice($msgs, 1, -1) as $x) {
    eq(ord($x[2]) & 0x3F, 20, 'Ursache "abgefragt" (20)');
    $types[] = ord($x[0]);
    $objs += ord($x[1]) & 0x7F;
}
ok(in_array(30, $types, true) && in_array(31, $types, true) && in_array(36, $types, true), 'Typen 30, 31, 36 vorhanden: ' . implode(',', $types));
// belegt: P Anlage, Frequenz, Schutz, LS, Rueckm P (intern), QU Rueckm (intern) = 6; "Unbelegt" fehlt
eq($objs, 6, 'nur belegte Punkte, unbelegte fehlen');
// Wert pruefen: Frequenz 50.0 (Typ 36, IOA 0.3.11)
$found = false;
foreach ($msgs as $x) {
    if (ord($x[0]) !== 36) {
        continue;
    }
    $n = ord($x[1]) & 0x7F;
    for ($i = 0; $i < $n; $i++) {
        $o = substr($x, 6 + $i * 15, 15);
        if (hx(substr($o, 0, 3)) === '0B0300') {
            $found = true;
            eq(round(unpack('g', substr($o, 3, 4))[1], 3), 50.0, 'Frequenz 50.0');
            eq(ord($o[7]), 0, 'Qualitaet gut');
        }
        if (hx(substr($o, 0, 3)) === '090000') {
            eq(round(unpack('g', substr($o, 3, 4))[1], 3), -5.0, 'Faktor -1: Erzeugung negativ');
        }
    }
}
ok($found, 'Frequenz in der Generalabfrage');

echo "8. Sollwert Wirkleistung\n";
[$h, $st, $link, $m] = build();
$link->feed($m->resetLink());
drain($link, $m);
$sp = asdu(50, 6, ioa3(1, 0, 98) . pack('g', 75.5) . chr(0));
$link->feed($m->userData($sp));
$msgs = drain($link, $m);
eq(count($h->actions), 1, 'Ziel-Variable wurde geschrieben');
eq($h->actions[0][0], 900, 'auf Variable 900');
eq(round($h->actions[0][1], 3), 75.5, 'Wert 75.5');
eq($h->store['sp_10062'], 75.5, 'zuletzt gueltiger Sollwert gespeichert');
eq(ord($msgs[0][0]), 50, 'ACTCON C_SE_NC_1');
eq(ord($msgs[0][2]) & 0x7F, 7, 'ACTCON positiv');
eq(ord($msgs[1][0]), 36, 'Rueckmeldung als Messwert 36');
eq(ord($msgs[1][2]) & 0x3F, 3, 'spontan');
eq(hx(substr($msgs[1], 6, 3)), '620000', 'Rueckmelde-IOA 0.0.98');
eq(round(unpack('g', substr($msgs[1], 9, 4))[1], 3), 75.5, 'Rueckmeldung = exakt der empfangene Wert');
// ausserhalb des Bereichs
[$h, $st, $link, $m] = build();
$link->feed($m->resetLink());
drain($link, $m);
$link->feed($m->userData(asdu(50, 6, ioa3(1, 0, 98) . pack('g', 120.0) . chr(0))));
$msgs = drain($link, $m);
eq(count($h->actions), 0, '120 % wird nicht geschrieben');
eq(ord($msgs[0][2]) & 0x40, 0x40, 'negative Quittung (P/N)');
// Auswahl ohne Ausfuehren
[$h, $st, $link, $m] = build();
$link->feed($m->resetLink());
drain($link, $m);
$link->feed($m->userData(asdu(50, 6, ioa3(1, 0, 98) . pack('g', 50.0) . chr(0x80))));
$msgs = drain($link, $m);
eq(count($h->actions), 0, 'Select fuehrt nicht aus');
eq(count($msgs), 1, 'nur ACTCON');
// unbekannte IOA
$link->feed($m->userData(asdu(50, 6, ioa3(1, 0, 77) . pack('g', 50.0) . chr(0))));
$msgs = drain($link, $m);
eq(ord($msgs[0][2]) & 0x3F, 47, 'unbekannte Objektadresse (COT 47)');
// falsche Common-Adresse
$link->feed($m->userData(asdu(50, 6, ioa3(1, 0, 98) . pack('g', 50.0) . chr(0), 5)));
$msgs = drain($link, $m);
eq(ord($msgs[0][2]) & 0x3F, 46, 'unbekannte Common-Adresse (COT 46)');
// Common-Adresse 0 wird akzeptiert
$link->feed($m->userData(asdu(50, 6, ioa3(1, 0, 98) . pack('g', 60.0) . chr(0), 0)));
drain($link, $m);
eq(round($h->actions[0][1] ?? -1, 3), 60.0, 'CA 0 akzeptiert');
// unbekannter Typ
$link->feed($m->userData(asdu(200, 6, ioa3(0, 0, 1) . chr(0))));
$msgs = drain($link, $m);
eq(ord($msgs[0][2]) & 0x3F, 44, 'unbekannter Typ (COT 44)');

echo "9. Befehle\n";
[$h, $st, $link, $m] = build();
$link->feed($m->resetLink());
drain($link, $m);
$link->feed($m->userData(asdu(46, 6, ioa3(1, 3, 125) . chr(0x02))));  // EIN
$msgs = drain($link, $m);
eq($h->actions[0] ?? null, [905, 2], 'Doppelbefehl EIN (Integer-Variable erhaelt 2)');
eq(ord($msgs[0][2]) & 0x7F, 7, 'ACTCON');
eq(ord($msgs[1][2]) & 0x3F, 10, 'ACTTERM');
$h->actions = [];
$link->feed($m->userData(asdu(46, 6, ioa3(1, 3, 125) . chr(0x00))));  // ungueltig
$msgs = drain($link, $m);
eq(count($h->actions), 0, 'Doppelbefehl 0 wird abgelehnt');
eq(ord($msgs[0][2]) & 0x40, 0x40, 'negativ');
// Einzelbefehl mit Rueckmeldespiegel
$link->feed($m->userData(asdu(45, 6, ioa3(1, 0, 11) . chr(0x01))));
$msgs = drain($link, $m);
eq($h->actions[0] ?? null, [906, true], 'Einzelbefehl an Boolean-Variable');
$sp = array_values(array_filter($msgs, fn ($x) => ord($x[0]) === 30));
eq(count($sp), 1, 'Rueckmeldung Typ 30');
eq(ord($sp[0][9]) & 1, 1, 'Rueckmeldung 1 (kommt)');
// Ort-Betrieb
$h->local = true;
$h->actions = [];
$link->feed($m->userData(asdu(46, 6, ioa3(1, 3, 125) . chr(0x02))));
$msgs = drain($link, $m);
eq(count($h->actions), 0, 'Ort-Betrieb sperrt Befehle');
eq(ord($msgs[0][2]) & 0x40, 0x40, 'negativ quittiert');
$link->feed($m->userData(asdu(50, 6, ioa3(1, 0, 98) . pack('g', 40.0) . chr(0))));
drain($link, $m);
eq(count($h->actions), 1, 'Sollwerte im Ort-Betrieb erlaubt (Standard)');

echo "10. Messwerte: Schwelle und Zeit\n";
[$h, $st, $link, $m] = build();
$link->feed($m->resetLink());
drain($link, $m);
$h->vars[102] = 51.0;   // +2 %
$st->variableChanged(102);
eq(count(drain($link, $m)), 0, '2 % Aenderung: nichts gesendet');
$h->vars[102] = 56.0;   // > 10 % gegenueber zuletzt gesendetem 50
$st->variableChanged(102);
$msgs = drain($link, $m);
eq(count($msgs), 1, 'ueber 10 %: gesendet');
eq(ord($msgs[0][2]) & 0x3F, 3, 'spontan');
$h->t += 61;
$st->tick();
$msgs = drain($link, $m);
ok(count($msgs) >= 1, 'nach 60 s ohne Aenderung wird trotzdem gesendet');
// Zeitmarke
eq(strlen($msgs[0]), 6 + 3 + 4 + 1 + 7, 'Laenge Typ 36 mit CP56');

echo "11. Meldungen: Flattern und Zwischenstellung\n";
[$h, $st, $link, $m] = build();
$link->feed($m->resetLink());
drain($link, $m);
$h->vars[103] = true;
$st->variableChanged(103);
$msgs = drain($link, $m);
eq(count($msgs), 1, 'Wechsel wird gemeldet');
eq(ord($msgs[0][2]) & 0x3F, 3, 'spontan');
eq(ord($msgs[0][9]) & 0x81, 0x01, 'SPI = 1, gueltig');
for ($i = 0; $i < 7; $i++) {
    $h->t += 0.5;
    $h->vars[103] = !$h->vars[103];
    $st->variableChanged(103);
}
$msgs = drain($link, $m);
$iv = array_filter($msgs, fn ($x) => (ord($x[9]) & 0x80) !== 0);
ok(count($iv) >= 1, 'Flattern: Meldung mit IV-Bit');
$before = count($msgs);
$h->t += 1;
$h->vars[103] = !$h->vars[103];
$st->variableChanged(103);
eq(count(drain($link, $m)), 0, 'waehrend des Flatterns still');
$h->t += 31;
$st->tick();
$msgs = drain($link, $m);
eq(count($msgs), 1, 'nach 30 s Ruhe wieder gueltig');
eq(ord($msgs[0][9]) & 0x80, 0, 'IV zurueckgenommen');
// Doppelmeldung: Zwischenstellung wird erst nach 10 s gemeldet, Rueckfall unterdrueckt
[$h, $st, $link, $m] = build();
$link->feed($m->resetLink());
drain($link, $m);
$h->vars[104] = 0;
$st->variableChanged(104);
eq(count(drain($link, $m)), 0, 'Zwischenstellung sofort: nichts');
$h->t += 4;
$h->vars[104] = 2;
$st->variableChanged(104);
$h->t += 20;
$st->tick();
eq(count(drain($link, $m)), 0, 'Rueckfall innerhalb 10 s: nichts');
$h->vars[104] = 0;
$st->variableChanged(104);
$h->t += 11;
$st->tick();
$msgs = drain($link, $m);
eq(count($msgs), 1, 'Zwischenstellung > 10 s: gemeldet');
eq(ord($msgs[0][0]), 31, 'Typ 31');
eq(ord($msgs[0][9]) & 3, 0, 'DPI 0');
$h->vars[104] = 3;
$st->variableChanged(104);
$h->t += 1.2;
$st->tick();
$msgs = drain($link, $m);
eq(ord($msgs[0][9] ?? "\xFF") & 3, 3, 'Stoerstellung nach 1 s');

echo "12. Ausfall der Kommunikation\n";
[$h, $st, $link, $m] = build();
$link->feed($m->resetLink());
drain($link, $m);
$link->feed($m->userData(asdu(50, 6, ioa3(1, 0, 98) . pack('g', 30.0) . chr(0))));
drain($link, $m);
$h->actions = [];
$h->t += 43200 - 60;
$st->tick();
eq(count($h->actions), 0, 'vor 12 h noch nichts');
$h->t += 120;
$st->tick();
eq($h->actions[0] ?? null, [900, 100.0], 'nach 12 h: Ausfallwert 100 %');
$st->tick();
eq(count($h->actions), 1, 'nur einmal');
$link->feed($m->status());
$h->t += 100000;
$st->tick();
eq(count($h->actions), 2, 'nach neuem Kontakt und erneutem Ausfall wieder');
eq($h->store['sp_10062'], 100.0, 'Ausfallwert wird als zuletzt gueltiger Wert gespeichert');

echo "13. Zustand ueber Aufrufe erhalten (Export/Import)\n";
[$h, $st, $link, $m] = build();
$link->feed($m->resetLink());
$h->vars[102] = 90.0;
$st->variableChanged(102);
$st2 = new FW101_Station(['ca' => 1, 'acceptCaZero' => true, 'cotLen' => 2, 'caLen' => 2, 'ioaLen' => 3,
    'timeMode' => 'utc', 'pct' => 10.0, 'minDelta' => 0.001, 'maxInterval' => 60, 'failSeconds' => 43200,
    'localBlocksSetpoints' => false, 'sendInit' => true], mkRows(), $h);
$st2->import(unserialize(serialize($st->export()), ['allowed_classes' => false]));
$link2 = new FW101_Link(['linkAddr' => 1, 'linkLen' => 1, 'ackSingleChar' => false], $st2);
$link2->import(unserialize(serialize($link->export()), ['allowed_classes' => false]));
$msgs = drain($link2, $m);
eq(count($msgs), 2, 'M_EI und Messwert ueberleben Serialisierung');

echo "14. Andere Adresslaengen\n";
$h = new TestHost();
$h->vars = [101 => 5.0];
$cfg = ['ca' => 3, 'acceptCaZero' => false, 'cotLen' => 1, 'caLen' => 1, 'ioaLen' => 2, 'timeMode' => 'utc',
    'pct' => 10.0, 'minDelta' => 0.001, 'maxInterval' => 60, 'failSeconds' => 0, 'localBlocksSetpoints' => false, 'sendInit' => true];
$rows = FW101_Station::normalize([['Name' => 'x', 'Type' => 36, 'IOA' => '0.9', 'Var' => 101, 'Factor' => 1]]);
$st = new FW101_Station($cfg, $rows, $h);
$st->preload();
$link = new FW101_Link(['linkAddr' => 0x0102, 'linkLen' => 2, 'ackSingleChar' => true], $st);
$m = new Master(0x0102, 2);
$r = $link->feed($m->resetLink());
eq(hx($r), '10200201' . '23' . '16', 'zweibytige Linkadresse (Little Endian), Pruefsumme');
$r = $link->feed($m->class1());
$l = ord($r[1]);
$asdu = substr($r, 7, $l - 3);
eq(strlen($asdu), 2 + 1 + 1 + 2 + 1, 'COT 1 Byte, CA 1 Byte, IOA 2 Byte: M_EI hat 7 Byte');
// E5 wenn nichts ansteht
$r = $link->feed($m->userData(asdu(200, 6, '', 3)));
ok($r !== '', 'Antwort auf Nutzdaten');

echo "15. Vorlage E-Werk Netze V4.0\n";
$rows = FW101_Presets::points(FW101_Presets::EWERK_V4);
eq(count($rows), 91, 'Anzahl Punkte laut Datenpunktliste (13+18+11+29+12+5+3)');
$pts = FW101_Station::normalize($rows);
eq(count($pts), count($rows), 'alle Zeilen gueltig');
$ioas = array_map(fn ($p) => $p['ioa'], $pts);
eq(count($ioas), count(array_unique($ioas)), 'keine doppelte IOA');
$byName = [];
foreach ($pts as $p) {
    $byName[$p['name']] = $p;
}
eq(FW101_Asdu::formatIoa($byName['Vorgabe Wirkleistung 1 [%]']['ioa']), '1.0.98', 'Vorgabe Wirkleistung 1');
eq(FW101_Asdu::formatIoa($byName['Vorgabe Wirkleistung 2 [%]']['ioa']), '1.0.99', 'Vorgabe Wirkleistung 2');
eq(FW101_Asdu::formatIoa($byName['Stellungsmeldung Erdungstrennschalter Übergabefeld']['ioa']), '0.3.122', 'Erdungstrennschalter Uebergabefeld 0.3.122');
eq($byName['Vorgabe Wirkleistung 1 [%]']['fail'], 100.0, 'Ausfallwert 100 %');
$mirrors = array_filter($pts, fn ($p) => $p['mirror'] !== null);
foreach ($mirrors as $p) {
    ok(isset(array_flip($ioas)[$p['mirror']]), 'Rueckmeldepunkt existiert: ' . $p['name']);
}

echo "\n" . $GLOBALS['checks'] . " Pruefungen, " . $GLOBALS['fails'] . " Fehler\n";
exit($GLOBALS['fails'] > 0 ? 1 : 0);
