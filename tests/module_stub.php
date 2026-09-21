<?php

declare(strict_types=1);

// Laedt Fernwirk101/module.php gegen einen minimalen IPS-Nachbau, um Laufzeitfehler im Symcon-Teil
// (Eigenschaften, Puffer, Formular, Ereignisse) zu finden. Kein Ersatz fuer einen Test im echten IPS.

date_default_timezone_set('Europe/Berlin');
const KR_READY = 10103;
const IPS_KERNELMESSAGE = 10100;
const VM_UPDATE = 10603;
const KL_ERROR = 4;
const KL_NOTIFY = 1;

$GLOBALS['ips'] = ['vars' => [], 'ident' => [], 'props' => [], 'buf' => [], 'sent' => [], 'log' => [], 'next' => 5000, 'actions' => []];

function IPS_VariableExists(int $id): bool { return isset($GLOBALS['ips']['vars'][$id]); }
function GetValue(int $id): mixed { return $GLOBALS['ips']['vars'][$id]['v']; }
function SetValueInteger(int $id, int $v): void { $GLOBALS['ips']['vars'][$id]['v'] = $v; $GLOBALS['ips']['vars'][$id]['u'] = time(); }
function SetValueFloat(int $id, float $v): void { $GLOBALS['ips']['vars'][$id]['v'] = $v; $GLOBALS['ips']['vars'][$id]['u'] = time(); }
function RequestAction(int $id, mixed $v): void { $GLOBALS['ips']['actions'][] = [$id, $v]; $GLOBALS['ips']['vars'][$id]['v'] = $v; }
function IPS_GetVariable(int $id): array { return ['VariableType' => $GLOBALS['ips']['vars'][$id]['t'], 'VariableUpdated' => $GLOBALS['ips']['vars'][$id]['u'] ?? 0]; }
function IPS_GetKernelRunlevel(): int { return KR_READY; }
function IPS_SemaphoreEnter(string $n, int $t): bool { return true; }
function IPS_SemaphoreLeave(string $n): bool { return true; }
function IPS_GetInstance(int $id): array { return ['ConnectionID' => 7000, 'InstanceStatus' => 102]; }
function IPS_GetName(int $id): string { return 'Serial Port'; }
function IPS_GetConfiguration(int $id): string { return json_encode(['BaudRate' => '19200', 'DataBits' => '8', 'Parity' => 'Even', 'StopBits' => '1']); }
function IPS_SetProperty(int $id, string $n, mixed $v): void { $GLOBALS['ips']['props'][$n] = $v; }
function IPS_ApplyChanges(int $id): void { $GLOBALS['ips']['module']->ApplyChanges(); }

class IPSModule
{
    public int $InstanceID = 1234;
    private array $regProps = [];
    public function Create() {}
    public function ApplyChanges() {}
    private function reg($n, $d) { $this->regProps[$n] = $d; }
    protected function RegisterPropertyString($n, $d) { $this->reg($n, $d); }
    protected function RegisterPropertyInteger($n, $d) { $this->reg($n, $d); }
    protected function RegisterPropertyBoolean($n, $d) { $this->reg($n, $d); }
    protected function RegisterPropertyFloat($n, $d) { $this->reg($n, $d); }
    private function prop($n) { return $GLOBALS['ips']['props'][$n] ?? $this->regProps[$n]; }
    protected function ReadPropertyString($n) { return (string) $this->prop($n); }
    protected function ReadPropertyInteger($n) { return (int) $this->prop($n); }
    protected function ReadPropertyBoolean($n) { return (bool) $this->prop($n); }
    protected function ReadPropertyFloat($n) { return (float) $this->prop($n); }
    protected function RegisterTimer($n, $i, $s) {}
    protected function SetTimerInterval($n, $i) {}
    protected function RegisterMessage($s, $m) { $GLOBALS['ips']['msgs'][$s][] = $m; }
    protected function UnregisterMessage($s, $m) {}
    protected function GetMessageList() { return $GLOBALS['ips']['msgs'] ?? []; }
    protected function SetStatus($s) { $GLOBALS['ips']['status'] = $s; }
    protected function SetBuffer($n, $v) { $GLOBALS['ips']['buf'][$n] = $v; }
    protected function GetBuffer($n) { return $GLOBALS['ips']['buf'][$n] ?? ''; }
    protected function SendDebug($a, $b, $c) {}
    protected function LogMessage($m, $k) { $GLOBALS['ips']['log'][] = $m; }
    protected function ReloadForm() {}
    protected function SendDataToParent($j) { $GLOBALS['ips']['sent'][] = $j; }
    private function regVar($i, $t, $init) {
        if (!isset($GLOBALS['ips']['ident'][$i])) {
            $id = $GLOBALS['ips']['next']++;
            $GLOBALS['ips']['ident'][$i] = $id;
            $GLOBALS['ips']['vars'][$id] = ['v' => $init, 't' => $t];
        }
    }
    protected function RegisterVariableInteger($i, $n, $p = '', $pos = 0) { $this->regVar($i, 1, 0); }
    protected function RegisterVariableBoolean($i, $n, $p = '', $pos = 0) { $this->regVar($i, 0, false); }
    protected function RegisterVariableFloat($i, $n, $p = '', $pos = 0) { $this->regVar($i, 2, 0.0); }
    protected function GetIDForIdent($i) { return $GLOBALS['ips']['ident'][$i] ?? false; }
    protected function SetValue($i, $v) { $id = $GLOBALS['ips']['ident'][$i]; $GLOBALS['ips']['vars'][$id]['v'] = $v; $GLOBALS['ips']['vars'][$id]['u'] = time(); }
}

require __DIR__ . '/../Fernwirk101/module.php';

$fails = 0;
function chk(bool $c, string $m): void { global $fails; if (!$c) { $fails++; echo "  FEHLER: $m\n"; } }

$GLOBALS['ips']['vars'] += [201 => ['v' => 50.0, 't' => 2], 202 => ['v' => -3.2, 't' => 2], 900 => ['v' => 100.0, 't' => 2]];
$m = new Fernwirk101();
$GLOBALS['ips']['module'] = $m;
$m->Create();
$m->ApplyChanges();
chk(($GLOBALS['ips']['status'] ?? 0) === 104, 'ohne Datenpunkte Status 104');

$m->LoadPreset('ewerk_v4');
$rows = json_decode($GLOBALS['ips']['props']['Points'], true);
chk(count($rows) === 91, 'Vorlage laedt 91 Punkte');
foreach ($rows as &$r) {
    if ($r['IOA'] === '0.3.11') { $r['Var'] = 201; }
    if ($r['IOA'] === '0.3.9') { $r['Var'] = 202; $r['Factor'] = -1.0; }
    if ($r['IOA'] === '1.0.98') { $r['Var'] = 900; }
}
unset($r);
$GLOBALS['ips']['props']['Points'] = json_encode($rows);
$m->ApplyChanges();
chk($GLOBALS['ips']['status'] === 102, 'mit Datenpunkten Status 102');

$m->LoadPreset('ewerk_v4');
$rows = json_decode($GLOBALS['ips']['props']['Points'], true);
$kept = array_values(array_filter($rows, fn ($r) => $r['IOA'] === '0.3.11'))[0]['Var'];
chk($kept === 201, 'Neuladen der Vorlage behaelt die Variable');

function frame(string $body): string { return "\x68" . chr(strlen($body)) . chr(strlen($body)) . "\x68" . $body . chr(array_sum(array_map('ord', str_split($body))) & 0xFF) . "\x16"; }
function fixed(int $c): string { return "\x10" . chr($c) . "\x01" . chr(($c + 1) & 0xFF) . "\x16"; }
function rx(Fernwirk101 $m, string $bytes): string {
    $GLOBALS['ips']['sent'] = [];
    $m->ReceiveData(json_encode(['DataID' => '{018EF6B5-AB94-40C6-AA53-46943E824ACF}', 'Buffer' => mb_convert_encoding($bytes, 'UTF-8', 'ISO-8859-1')]));
    $out = '';
    foreach ($GLOBALS['ips']['sent'] as $j) {
        $d = json_decode($j, true);
        chk($d['DataID'] === '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}', 'Sende-DataID');
        $out .= mb_convert_encoding($d['Buffer'], 'ISO-8859-1', 'UTF-8');
    }
    return $out;
}
$r = rx($m, fixed(0x40));
chk(bin2hex($r) === '1020012116', 'Reset-Antwort ueber ReceiveData (Bytes > 127 ueberleben die UTF-8-Huelle): ' . bin2hex($r));
$r = rx($m, fixed(0x7A));
chk($r !== '' && $r[0] === "\x68", 'Klasse 1 liefert M_EI');
$sp = "\x32\x01\x06\x00\x01\x00" . "\x62\x00\x01" . pack('g', 55.0) . "\x00";
rx($m, frame("\x53\x01" . $sp));   // FCB 0: nach dem Klasse-1-Abruf mit FCB 1 umgeschaltet
chk(($GLOBALS['ips']['actions'][0] ?? null) === [900, 55.0], 'Sollwert erreicht die Ziel-Variable');
$spId = $GLOBALS['ips']['ident']['SP_10062'] ?? null;
chk($spId !== null && GetValue($spId) === 55.0, 'zuletzt gueltiger Sollwert in Modulvariable');
$GLOBALS['ips']['vars'][201]['v'] = 60.0;
$m->MessageSink(0, 201, VM_UPDATE, []);
$got = false;
for ($i = 0, $c = 0x7A; $i < 8 && !$got; $i++, $c ^= 0x20) {
    $r = rx($m, fixed($c));
    if ($r !== '' && $r[0] === "\x68" && ord($r[6]) === 36 && (ord($r[8]) & 0x3F) === 3) {
        $got = true; // spontaner Messwert (nach ACTCON und Rueckmeldung des Sollwerts)
    }
}
chk($got, 'Ereignis nach Wertaenderung abrufbar');
$form = json_decode($m->GetConfigurationForm(), true);
$txt = '';
foreach ($form['elements'] as $e) { if (($e['name'] ?? '') === 'StatusText') { $txt = $e['caption']; } }
chk(str_contains($txt, 'Serial Port #7000'), 'Statuszeile nennt den Serial Port');
chk(str_contains($txt, 'Datenpunkten belegt'), 'Statuszeile zaehlt belegte Punkte');
echo $txt . "\n";
$rep = $m->GetPointReport();
chk(str_contains($rep, '3 von 91'), 'Bericht: 3 von 91 belegt');
$m->Tick();
chk($GLOBALS['ips']['vars'][$GLOBALS['ips']['ident']['MasterActive']]['v'] === true, 'Master aktiv nach Kontakt');
chk($GLOBALS['ips']['log'] === [], 'keine Fehlermeldungen im Log: ' . json_encode($GLOBALS['ips']['log']));
echo $fails === 0 ? "Modul-Stub: alles in Ordnung\n" : "Modul-Stub: $fails Fehler\n";
exit($fails ? 1 : 0);
