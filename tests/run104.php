<?php

declare(strict_types=1);

// Tests der 104-Verbindungsschicht ohne IPS und ohne Wartezeit (die Zeit wird simuliert).
// Der Test-Client baut die APDU von Hand nach IEC 60870-5-104, unabhaengig vom Code der Station.
// Aufruf: php tests/run104.php

date_default_timezone_set('Europe/Berlin');
require_once __DIR__ . '/../libs/FW104_Link.php';
require_once __DIR__ . '/../libs/FW101_Presets.php';
require_once __DIR__ . '/../libs/FW104_Presets.php';

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
function section(string $s): void
{
    echo "\n$s\n";
}

final class Host104 implements FW101_Host
{
    public array $vars = [];
    public array $types = [];
    public array $actions = [];
    public array $store = [];
    public float $t = 1790000000.0;
    public function value(int $varId): mixed { return $this->vars[$varId] ?? null; }
    public function action(int $varId, mixed $value): bool { $this->actions[] = [$varId, $value]; return true; }
    public function varType(int $varId): int { return $this->types[$varId] ?? 2; }
    public function store(string $key, mixed $value): void { $this->store[$key] = $value; }
    public function load(string $key): mixed { return $this->store[$key] ?? null; }
    public function localMode(): bool { return false; }
    public function log(string $message): void {}
    public function now(): float { return $this->t; }
}

// ---- Rahmen bauen und zerlegen (unabhaengig vom Code der Station)
function iframe(int $ns, int $nr, string $asdu): string
{
    return "\x68" . chr(4 + strlen($asdu)) . chr(($ns << 1) & 0xFE) . chr($ns >> 7) . chr(($nr << 1) & 0xFE) . chr($nr >> 7) . $asdu;
}
function sframe(int $nr): string
{
    return "\x68\x04\x01\x00" . chr(($nr << 1) & 0xFE) . chr($nr >> 7);
}
function uframe(int $c): string
{
    return "\x68\x04" . chr($c) . "\x00\x00\x00";
}
const STARTDT_ACT = 0x07, STARTDT_CON = 0x0B, STOPDT_ACT = 0x13, STOPDT_CON = 0x23, TESTFR_ACT = 0x43, TESTFR_CON = 0x83;

/** Zerlegt einen Byte-Strom in Rahmen: ['k'=>'I'|'S'|'U', ...] */
function frames(string $s): array
{
    $out = [];
    while ($s !== '') {
        if (ord($s[0]) !== 0x68) {
            $out[] = ['k' => '?'];
            break;
        }
        $l = ord($s[1]);
        $f = substr($s, 2, $l);
        $s = substr($s, 2 + $l);
        $c1 = ord($f[0]);
        if (($c1 & 1) === 0) {
            $out[] = ['k' => 'I', 'ns' => ($c1 >> 1) | (ord($f[1]) << 7), 'nr' => (ord($f[2]) >> 1) | (ord($f[3]) << 7), 'asdu' => substr($f, 4)];
        } elseif (($c1 & 3) === 1) {
            $out[] = ['k' => 'S', 'nr' => (ord($f[2]) >> 1) | (ord($f[3]) << 7)];
        } else {
            $out[] = ['k' => 'U', 'u' => $c1];
        }
    }
    return $out;
}
function asduType(string $a): int { return ord($a[0]); }
function asduCot(string $a): int { return ord($a[2]) & 0x3F; }

/** ASDU-Kopf der Gegenstelle: COT 2 Byte, CA 2 Byte, IOA 3 Byte. */
function asdu(int $type, int $cot, int $ioa, string $body, int $ca = 1): string
{
    return chr($type) . "\x01" . chr($cot) . "\x00" . chr($ca & 255) . chr($ca >> 8) . chr($ioa & 255) . chr(($ioa >> 8) & 255) . chr(($ioa >> 16) & 255) . $body;
}

$cfgSt = ['ca' => 1, 'acceptCaZero' => true, 'cotLen' => 2, 'caLen' => 2, 'ioaLen' => 3, 'timeMode' => 'utc', 'pct' => 10.0,
    'minDelta' => 0.001, 'maxInterval' => 300, 'failSeconds' => 0, 'localBlocksSetpoints' => false, 'sendInit' => true, 'giPlain' => true];
$cfgLk = ['t0' => 30, 't1' => 15, 't2' => 10, 't3' => 20, 'k' => 12, 'w' => 8];

function rows(): array
{
    $r = static fn (string $n, int $t, string $ioa, array $x = []) => $x + ['Name' => $n, 'Type' => $t, 'IOA' => $ioa, 'Var' => 0, 'Factor' => 1.0, 'Delta' => 0.0, 'Min' => '', 'Max' => '', 'Mirror' => '', 'Fail' => '', 'Req' => false];
    return [
        $r('Frequenz', 36, '0.0.7', ['Var' => 201]),
        $r('Meldung', 30, '0.0.1', ['Var' => 202]),
        $r('Schalter', 31, '0.0.6', ['Var' => 203]),
        $r('P Soll', 50, '30.0.1', ['Var' => 900, 'Mirror' => '30.0.4', 'Min' => '0', 'Max' => '100']),
        $r('P Soll Rueck', 36, '30.0.4'),
        $r('Befehl', 45, '0.0.30', ['Var' => 901]),
    ];
}

/** @return array{0:FW104_Link,1:FW101_Station,2:Host104} */
function make(array $cfgLk, array $cfgSt): array
{
    $host = new Host104();
    $host->vars = [201 => 50.02, 202 => false, 203 => 2];
    $host->types = [900 => 2, 901 => 0];
    $st = new FW101_Station($cfgSt, FW101_Station::normalize(rows()), $host);
    $st->preload();
    return [new FW104_Link($cfgLk, $st, $host), $st, $host];
}
function flat(array $out): string
{
    return implode('', $out);
}

$K = '10.0.0.1:50000';
$cp56 = str_repeat("\x00", 7);

// ---------------------------------------------------------------------------
section('1. Verbindungsaufbau, STARTDT, TESTFR');
[$lk, $st, $h] = make($cfgLk, $cfgSt);
$lk->connected($K, $h->t);
$out = $lk->feed($K, uframe(TESTFR_ACT), $h->t);
eq(frames($out[$K])[0]['u'] ?? null, TESTFR_CON, 'TESTFR act vor STARTDT wird mit con beantwortet');
$out = $lk->feed($K, uframe(STARTDT_ACT), $h->t);
$fr = frames($out[$K]);
eq($fr[0]['u'] ?? null, STARTDT_CON, 'STARTDT act -> con');
eq(count($fr), 2, 'danach kommt "Initialisierung beendet" als I-Rahmen');
eq(asduType($fr[1]['asdu']), 70, 'Typ 70 (M_EI_NA_1)');
eq(asduCot($fr[1]['asdu']), 4, 'Ursache initialisiert');
eq($fr[1]['ns'], 0, 'erste Sendefolgenummer 0');
$out = $lk->feed($K, sframe(1), $h->t);
eq($out, [], 'S-Rahmen quittiert, keine Antwort');
eq($lk->diagnose()['unacked'], 0, 'nichts mehr unquittiert');

section('2. Keine Daten vor STARTDT');
[$lk, $st, $h] = make($cfgLk, $cfgSt);
$lk->connected($K, $h->t);
$h->vars[201] = 60.0;
$st->variableChanged(201);
eq($lk->pump($h->t), [], 'ohne STARTDT wird nichts gesendet');
$out = $lk->feed($K, iframe(0, 0, asdu(100, 6, 0, "\x14")), $h->t);
eq($out, [], 'I-Rahmen vor STARTDT wird ignoriert');
eq($lk->diagnose()['stats']['protocolErrors'], 1, 'als Protokollfehler gezaehlt');

section('3. Generalabfrage: Typen ohne Zeitmarke, ACTCON/ACTTERM, Folgezaehler');
[$lk, $st, $h] = make($cfgLk, $cfgSt);
$lk->connected($K, $h->t);
$lk->feed($K, uframe(STARTDT_ACT), $h->t);
$out = $lk->feed($K, iframe(0, 1, asdu(100, 6, 0, "\x14")), $h->t);
$fr = frames($out[$K]);
$types = array_map(fn ($f) => asduType($f['asdu']) . '/' . asduCot($f['asdu']), $fr);
eq($types, ['100/7', '1/20', '3/20', '13/20', '100/10'], 'GA: actcon, Einzel (1), Doppel (3), Messwert (13), actterm');
eq(array_column($fr, 'ns'), [1, 2, 3, 4, 5], 'Sendefolgenummern fortlaufend (Init hatte 0)');
eq($fr[0]['nr'], 1, 'N(R) quittiert den empfangenen I-Rahmen');
eq(bin2hex($fr[2]['asdu']), bin2hex(chr(3) . chr(1) . chr(20) . "\x00" . "\x01\x00" . "\x06\x00\x00" . chr(2)), 'Doppelmeldung Wert 2 (EIN), Ursache 20, ohne Zeitmarke');
$f13 = $fr[3]['asdu'];
eq(asduType($f13), 13, 'Messwert ohne Zeitmarke');
eq(unpack('g', substr($f13, 9, 4))[1], 50.02000045776367, 'Messwert 50,02 als Gleitkomma');

section('4. Spontane Meldung mit Zeitmarke');
$h->vars[201] = 60.0;
$st->variableChanged(201);
$out = $lk->pump($h->t);
$fr = frames($out[$K]);
eq(count($fr), 1, 'ein I-Rahmen');
eq(asduType($fr[0]['asdu']), 36, 'Typ 36 mit Zeitmarke');
eq(asduCot($fr[0]['asdu']), 3, 'Ursache spontan');
eq(strlen($fr[0]['asdu']), 6 + 3 + 4 + 1 + 7, 'Laenge: Kopf 6 + IOA 3 + Wert 4 + Qualitaet 1 + CP56 7');

section('5. Fenster k: nicht mehr als k unquittierte I-Rahmen');
[$lk, $st, $h] = make(['k' => 4] + $cfgLk, $cfgSt);
$lk->connected($K, $h->t);
$out = $lk->feed($K, uframe(STARTDT_ACT), $h->t);
$lk->feed($K, sframe(1), $h->t);
for ($i = 0; $i < 10; $i++) {
    $h->vars[201] = 100.0 + $i * 100;
    $st->variableChanged(201);
    $o = $lk->pump($h->t);
    $sent = ($sent ?? 0) + count(frames($o[$K] ?? ''));
}
eq($sent, 4, 'mit k=4 gehen genau 4 Rahmen raus');
eq($st->stats()['queue'], 6, 'die uebrigen 6 warten in der Station');
$o = $lk->feed($K, sframe(3), $h->t); // nr=3: Rahmen 0, 1, 2 empfangen; 0 war schon quittiert, also 1 und 2 neu
$fr = frames($o[$K] ?? '');
eq(count($fr), 2, 'nach Quittung von 2 Rahmen gehen 2 weitere raus');
eq($st->stats()['queue'], 4, 'Rest 4');
$o = $lk->feed($K, sframe(99), $h->t);
eq($lk->diagnose()['active'], null, 'unsinniges N(R) beendet die Verbindung');

section('6. w: nach 8 empfangenen I-Rahmen sofort quittieren; t2 sonst');
[$lk, $st, $h] = make($cfgLk, $cfgSt);
$lk->connected($K, $h->t);
$lk->feed($K, uframe(STARTDT_ACT), $h->t);
$got = [];
for ($i = 0; $i < 8; $i++) {
    $o = $lk->feed($K, iframe($i, 1, ''), $h->t); // leere ASDU: keine Antwortdaten
    $got[] = frames($o[$K] ?? '');
}
$fr = array_merge(...$got);
eq(count($fr), 1, 'nach 7 Rahmen keine Quittung, nach dem 8. genau eine');
eq($fr[0]['k'] ?? null, 'S', 'als S-Rahmen');
eq($fr[0]['nr'] ?? null, 8, 'S-Rahmen quittiert 8');
// Antworten gehen als I-Rahmen mit N(R) raus (Huckepack): Prüfbefehl 104 wird bestaetigt
[$lk, $st, $h] = make($cfgLk, $cfgSt);
$lk->connected($K, $h->t);
$lk->feed($K, uframe(STARTDT_ACT), $h->t);
$o = $lk->feed($K, iframe(0, 1, asdu(104, 6, 0, "\x55\xAA")), $h->t);
$fr = frames($o[$K] ?? '');
ok(count($fr) === 1 && asduType($fr[0]['asdu']) === 104 && asduCot($fr[0]['asdu']) === 7, 'Prüfbefehl 104 wird mit ACTCON bestaetigt');
eq($fr[0]['nr'] ?? null, 1, 'die Antwort quittiert den empfangenen Rahmen huckepack');

[$lk, $st, $h] = make(['t2' => 5] + $cfgLk, $cfgSt);
$lk->connected($K, $h->t);
$lk->feed($K, uframe(STARTDT_ACT), $h->t);
$lk->feed($K, sframe(1), $h->t);
$lk->feed($K, iframe(0, 1, asdu(103, 6, 0, str_repeat("\x00", 7))), $h->t); // Uhrzeit: Antwort geht als I-Rahmen raus und quittiert huckepack
$lk->feed($K, sframe(2), $h->t);
$lk->feed($K, iframe(1, 2, asdu(58, 6, 0x999999, "\x01" . $cp56)), $h->t);
$lk->feed($K, sframe(3), $h->t);
// jetzt ein Rahmen, auf den wir keine Daten zurueckschicken: Verzweigung ueber "Abbruch der Aktivierung" der Generalabfrage ist ebenfalls beantwortet;
// daher direkt den Zaehler pruefen: ein empfangener Rahmen ohne eigene Antwort
$before = $lk->diagnose();
$lk->feed($K, iframe(2, 3, ''), $h->t); // leere ASDU: keine Antwort
$h->t += 4;
eq($lk->tick($h->t), [], 'vor t2 keine Quittung');
$h->t += 2;
$o = $lk->tick($h->t);
$fr = frames($o[$K] ?? '');
eq(count($fr), 1, 'nach t2 (5 s) genau eine Quittung');
eq($fr[0]['k'] ?? null, 'S', 'als S-Rahmen');
eq($fr[0]['nr'] ?? null, 3, 'sie quittiert alle 3 empfangenen Rahmen (N(R) = 3)');

section('7. t3/t1: Testrahmen bei Ruhe, Abbruch ohne Antwort');
[$lk, $st, $h] = make($cfgLk, $cfgSt);
$lk->connected($K, $h->t);
$lk->feed($K, uframe(STARTDT_ACT), $h->t);
$lk->feed($K, sframe(1), $h->t);
$h->t += 19;
eq($lk->tick($h->t), [], 'vor t3 nichts');
$h->t += 2;
$o = $lk->tick($h->t);
eq(frames($o[$K] ?? '')[0]['u'] ?? null, TESTFR_ACT, 'nach t3 (20 s) TESTFR act');
$h->t += 10;
eq($lk->tick($h->t), [], 'kein zweites TESTFR');
eq($lk->diagnose()['active'], $K, 'noch aktiv');
$lk->feed($K, uframe(TESTFR_CON), $h->t);
$h->t += 5;
eq($lk->diagnose()['active'], $K, 'TESTFR con haelt die Verbindung');
$h->t += 30;
$lk->tick($h->t); // erneut TESTFR, dann ohne Antwort
$h->t += 16;
$lk->tick($h->t);
eq($lk->diagnose()['active'], null, 'TESTFR ohne Antwort binnen t1 (15 s): Verbindung beendet');
eq($lk->diagnose()['stats']['timeouts'], 1, 'als Timeout gezaehlt');

[$lk, $st, $h] = make($cfgLk, $cfgSt);
$lk->connected($K, $h->t);
$lk->feed($K, uframe(STARTDT_ACT), $h->t); // schickt I (Init) ohne Quittung
$h->t += 16;
$lk->tick($h->t);
eq($lk->diagnose()['active'], null, 't1: unquittierter I-Rahmen nach 15 s beendet die Verbindung');

section('8. t0 und Folgenummernfehler');
[$lk, $st, $h] = make($cfgLk, $cfgSt);
$lk->connected($K, $h->t);
$h->t += 31;
$lk->tick($h->t);
eq($lk->diagnose()['stats']['timeouts'], 1, 't0: Verbindung ohne jeden Rahmen nach 30 s beendet');
[$lk, $st, $h] = make($cfgLk, $cfgSt);
$lk->connected($K, $h->t);
$lk->feed($K, uframe(STARTDT_ACT), $h->t);
$lk->feed($K, sframe(1), $h->t);
$o = $lk->feed($K, iframe(5, 1, asdu(100, 6, 0, "\x14")), $h->t);
eq($lk->diagnose()['active'], null, 'falsche Sendefolgenummer: Verbindung beendet');
eq($o, [], 'ohne Antwort');
$o = $lk->feed($K, uframe(STARTDT_ACT), $h->t);
eq(frames($o[$K] ?? '')[0]['u'] ?? null, STARTDT_CON, 'ein neues STARTDT auf derselben Verbindung startet eine neue Sitzung');

section('9. STOPDT, Uebernahme durch neue Verbindung');
[$lk, $st, $h] = make($cfgLk, $cfgSt);
$lk->connected($K, $h->t);
$lk->feed($K, uframe(STARTDT_ACT), $h->t);
$o = $lk->feed($K, uframe(STOPDT_ACT), $h->t);
eq(frames($o[$K])[0]['u'] ?? null, STOPDT_CON, 'STOPDT act -> con');
$h->vars[201] = 70.0;
$st->variableChanged(201);
eq($lk->pump($h->t), [], 'nach STOPDT keine Daten');
$K2 = '10.0.0.1:50001';
$lk->connected($K2, $h->t);
$lk->feed($K, uframe(STARTDT_ACT), $h->t);
$o = $lk->feed($K2, uframe(STARTDT_ACT), $h->t);
eq(frames($o[$K2])[0]['u'] ?? null, STARTDT_CON, 'zweite Verbindung darf STARTDT');
eq($lk->diagnose()['active'], $K2, 'und wird aktiv (Uebernahme)');
eq($lk->diagnose()['stats']['takeovers'], 1, 'Uebernahme gezaehlt');
$h->vars[201] = 90.0;
$st->variableChanged(201);
$o = $lk->pump($h->t);
eq(array_keys($o), [$K2], 'Daten gehen nur an die aktive Verbindung');

section('10. Sollwert (Typ 63) und Befehl (Typ 58), Rueckmeldung');
[$lk, $st, $h] = make($cfgLk, $cfgSt);
$lk->connected($K, $h->t);
$lk->feed($K, uframe(STARTDT_ACT), $h->t);
$lk->feed($K, sframe(1), $h->t);
$cp56 = str_repeat("\x00", 7);
$body = pack('g', 60.0) . "\x00" . $cp56;
$o = $lk->feed($K, iframe(0, 1, asdu(63, 6, (30 << 16) | 1, $body)), $h->t);
$fr = frames($o[$K]);
$seq = array_map(fn ($f) => asduType($f['asdu']) . '/' . asduCot($f['asdu']), $fr);
eq($seq, ['63/7', '36/3'], 'Sollwert: ACTCON, dann spontane Rueckmeldung (36)');
eq($h->actions, [[900, 60.0]], 'Ziel-Variable wurde geschrieben');
eq(round(unpack('g', substr($fr[1]['asdu'], 9, 4))[1], 3), 60.0, 'Rueckmeldung = empfangener Wert');
eq(ord($fr[1]['asdu'][6]) . '.' . ord($fr[1]['asdu'][7]) . '.' . ord($fr[1]['asdu'][8]), '4.0.30', 'Rueckmeldung liegt auf 30.0.4 (LE-Bytes 4,0,30)');
$body = pack('g', 120.0) . "\x00" . $cp56;
$o = $lk->feed($K, iframe(1, 3, asdu(63, 6, (30 << 16) | 1, $body)), $h->t);
$fr = frames($o[$K]);
eq(asduCot($fr[0]['asdu']), 7, 'ausserhalb des Bereichs: ACTCON');
ok((ord($fr[0]['asdu'][2]) & 0x40) !== 0, 'mit P/N-Bit (negativ)');
eq(count($h->actions), 1, 'kein zweiter Schreibzugriff');
$o = $lk->feed($K, iframe(2, 4, asdu(58, 6, 30, "\x01" . $cp56)), $h->t);
$fr = frames($o[$K]);
eq(array_map(fn ($f) => asduType($f['asdu']) . '/' . asduCot($f['asdu']), $fr), ['58/7', '58/10'], 'Einzelbefehl mit Zeitmarke: ACTCON und ACTTERM');
eq($h->actions[1], [901, true], 'Befehl EIN geschrieben');
$o = $lk->feed($K, iframe(3, 6, asdu(63, 6, 0x999999, $body)), $h->t);
eq(asduCot(frames($o[$K])[0]['asdu']), 47, 'unbekannte Objektadresse: Ursache 47');

section('11. Rahmen in Stuecken, Muell, Zustand sichern');
[$lk, $st, $h] = make($cfgLk, $cfgSt);
$lk->connected($K, $h->t);
$raw = "\xFF\x00" . uframe(STARTDT_ACT);
$o1 = $lk->feed($K, substr($raw, 0, 4), $h->t);
eq($o1, [], 'halber Rahmen: keine Antwort');
$o2 = $lk->feed($K, substr($raw, 4), $h->t);
eq(frames($o2[$K])[0]['u'] ?? null, STARTDT_CON, 'zusammengesetzt und Muell davor verworfen');
$state = serialize(['l' => $lk->export(), 's' => $st->export()]);
[$lk2, $st2, $h2] = make($cfgLk, $cfgSt);
$x = unserialize($state, ['allowed_classes' => false]);
$lk2->import($x['l']);
$st2->import($x['s']);
$h2->t = $h->t;
$h2->vars[201] = 99.0;
$st2->variableChanged(201);
$o = $lk2->pump($h->t);
eq(frames($o[$K])[0]['ns'] ?? null, 1, 'nach Export/Import geht die Sendefolge weiter (Init hatte 0)');

section('12. Grosse Generalabfrage (91 Punkte der Vorlage) passt in APDUs');
$host = new Host104();
$rowsAll = FW101_Presets::points(FW101_Presets::EWERK_V4);
foreach ($rowsAll as $i => &$r) {
    if (in_array($r['Type'], [30, 31, 36], true)) {
        $r['Var'] = 1000 + $i;
        $host->vars[1000 + $i] = $r['Type'] === 36 ? 1.5 : ($r['Type'] === 31 ? 2 : true);
    }
}
unset($r);
$st = new FW101_Station($cfgSt, FW101_Station::normalize($rowsAll), $host);
$st->preload();
$lk = new FW104_Link(['k' => 500, 'w' => 8] + $cfgLk, $st, $host);
$lk->connected($K, $host->t);
$lk->feed($K, uframe(STARTDT_ACT), $host->t);
$o = $lk->feed($K, iframe(0, 1, asdu(100, 6, 0, "\x14")), $host->t);
$fr = frames($o[$K]);
$maxLen = max(array_map(fn ($f) => strlen($f['asdu']), $fr));
ok($maxLen <= 249, "laengste ASDU $maxLen Byte <= 249");
$objs = 0;
foreach ($fr as $f) {
    if (asduCot($f['asdu']) === 20) {
        $objs += ord($f['asdu'][1]) & 0x7F;
    }
}
ok($objs > 50, "GA liefert $objs Objekte");

// ---------------------------------------------------------------------------
section('13. Vorlagen');
foreach (FW104_Presets::options() as $opt) {
    $pts = FW104_Presets::points($opt['value'], ['index' => 0]);
    ok(count($pts) > 5, $opt['caption'] . ': ' . count($pts) . ' Punkte');
    $norm = FW101_Station::normalize($pts);
    eq(count($norm), count($pts), $opt['caption'] . ': alle Zeilen gueltig');
    $ioas = array_map(fn ($p) => $p['ioa'] . ':' . $p['type'], $norm);
    eq(count(array_unique($ioas)), count($ioas), $opt['caption'] . ': keine doppelten Adressen');
}
$pv = FW101_Station::normalize(FW104_Presets::points('ewe_a_pv', ['index' => 0]));
$byIoa = [];
foreach ($pv as $p) {
    $byIoa[FW101_Asdu::formatIoa($p['ioa'])] = $p;
}
eq($byIoa['30.0.1']['type'] ?? null, 50, 'EWE PV: P_Soll auf 30.0.1');
eq($byIoa['30.0.4']['type'] ?? null, 36, 'EWE PV: Rueckmeldung auf 30.0.4');
eq(FW101_Asdu::formatIoa($byIoa['30.0.1']['mirror'] ?? 0), '30.0.4', 'Sollwert zeigt auf die Rueckmeldung');
$pv1 = FW101_Station::normalize(FW104_Presets::points('ewe_a_pv', ['index' => 1]));
$a1 = array_map(fn ($p) => FW101_Asdu::formatIoa($p['ioa']), $pv1);
ok(in_array('30.1.1', $a1, true) && !in_array('30.0.1', $a1, true), 'zweite Ressource (X=1): 30.1.1');
$ewf = FW101_Station::normalize(FW104_Presets::points('ewf_v17', ['index' => 1]));
$e = array_map(fn ($p) => FW101_Asdu::formatIoa($p['ioa']), $ewf);
ok(in_array('1.0.11', $e, true) && in_array('0.0.24', $e, true), 'EWF: Anlage #1 auf 1.0.11, Station auf 0.0.24');
$thr = FW104_Presets::points('ewe_a_pv', ['index' => 0, 'pinst' => 5.0, 'pav' => 4.0, 'emax' => 0.0, 'grid' => 'ms']);
$d = [];
foreach ($thr as $r) {
    $d[$r['IOA']] = $r['Delta'];
}
eq(round($d['30.0.12'], 6), 0.05, 'Schwelle Pges PV = 1 % von 5 MW');
eq(round($d['10.0.13'], 6), 0.04, 'Schwelle Pgesamt am NAP = 1 % von PAV 4 MW');
eq(round($d['10.0.7'], 6), 0.05, 'Schwelle Spannung MS = 50 V = 0,05 kV');

echo "\n" . $GLOBALS['checks'] . ' Pruefungen, ' . $GLOBALS['fails'] . " Fehler\n";
exit($GLOBALS['fails'] > 0 ? 1 : 0);
