<?php

declare(strict_types=1);

// Laedt IEC101/module.php gegen einen minimalen IPS-Nachbau, um Laufzeitfehler im Symcon-Teil
// (Eigenschaften, Puffer, Formular, Ereignisse) zu finden. Kein Ersatz fuer einen Test im echten IPS.

require_once __DIR__ . '/ips_stub.php';

require __DIR__ . '/../IEC101/module.php';

$fails = 0;
function chk(bool $c, string $m): void { global $fails; if (!$c) { $fails++; echo "  FEHLER: $m\n"; } }

$GLOBALS['ips']['vars'] += [201 => ['v' => 50.0, 't' => 2], 202 => ['v' => -3.2, 't' => 2], 900 => ['v' => 100.0, 't' => 2]];
$m = new IEC101();
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
function rx(IEC101 $m, string $bytes): string {
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
$form = json_decode($m->GetConfigurationForm(), true);
$panel = array_values(array_filter($form['elements'], fn ($e) => ($e['name'] ?? '') === 'PointReportPanel'))[0] ?? null;
chk($panel !== null, 'Panel "Datenpunkte pruefen" im Formular');
chk($panel !== null && str_contains($panel['items'][0]['caption'], '3 von 91'), 'Panel zeigt denselben Bericht wie GetPointReport()');
chk(!str_contains(json_encode($form), 'echo IEC'), 'kein echo(IEC..._GetPointReport/GetConnectionReport) mehr im Formular (breites Panel statt schmalem Dialog)');
$m->Tick();
chk($GLOBALS['ips']['vars'][$GLOBALS['ips']['ident']['MasterActive']]['v'] === true, 'Master aktiv nach Kontakt');
// Formular nach SUITE.md: Zweck, Neu, Doku vorn; Lizenz zuletzt; Hilfe-Knoepfe vorhanden; Ausblenden wirkt
$caps = array_map(fn ($e) => $e['caption'] ?? ($e['name'] ?? ''), $form['elements']);
chk(str_contains($caps[0], 'Wozu dieses Modul'), 'Panel 1: Wozu dieses Modul');
chk(str_contains($caps[1], 'Neu in Version'), 'Panel 2: Neu in Version');
chk(str_contains($caps[2], 'Dokumentation'), 'Panel 3: Dokumentation & Hilfe');
chk(str_contains(end($caps), 'Über dieses Modul'), 'letztes Panel: Ueber dieses Modul (Lizenz)');
chk(str_contains(json_encode($form), 'PopupButton'), 'Hilfe-Knoepfe (PopupButton) im Formular');
chk(!str_contains(json_encode($form), "'link' =>"), 'kein link-String');
$m->AckPurposeIntro();
$m->AckNews();
$caps = array_map(fn ($e) => $e['caption'] ?? ($e['name'] ?? ''), json_decode($m->GetConfigurationForm(), true)['elements']);
chk(!str_contains(implode('|', $caps), 'Wozu dieses Modul') && !str_contains(implode('|', $caps), 'Neu in Version'), 'nach Bestaetigen sind Zweck und Neu weg');
chk($GLOBALS['ips']['log'] === [], 'keine Fehlermeldungen im Log: ' . json_encode($GLOBALS['ips']['log']));
echo $fails === 0 ? "Modul-Stub: alles in Ordnung\n" : "Modul-Stub: $fails Fehler\n";
exit($fails ? 1 : 0);
