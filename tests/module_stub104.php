<?php

declare(strict_types=1);

// Laedt IEC104/module.php gegen einen minimalen IPS-Nachbau, um Laufzeitfehler im Symcon-Teil
// (Eigenschaften, Puffer, Formular, Server-Socket-Nachrichten) zu finden. Kein Ersatz fuer einen Test im echten IPS.

require_once __DIR__ . '/ips_stub.php';
$GLOBALS['ips']['parent'] = 7100;
$GLOBALS['ips']['self'] = 1234;
require __DIR__ . '/../IEC104/module.php';

$fails = 0;
function chk(bool $c, string $m): void { global $fails; if (!$c) { $fails++; echo "  FEHLER: $m\n"; } }

$GLOBALS['ips']['vars'] += [201 => ['v' => 50.0, 't' => 2], 202 => ['v' => 3.2, 't' => 2], 900 => ['v' => 100.0, 't' => 2]];
$m = new IEC104();
$GLOBALS['ips']['module'] = $m;
$m->Create();
$m->ApplyChanges();
chk(($GLOBALS['ips']['status'] ?? 0) === 104, 'ohne Datenpunkte Status 104');

$m->LoadPreset('ewe_a_pv', 0, 5.0, 4.0, 0.0, 'ms');
$rows = json_decode($GLOBALS['ips']['props']['Points'], true);
chk(count($rows) > 15, 'Vorlage laedt Punkte: ' . count($rows));
chk(($GLOBALS['ips']['props']['T1'] ?? null) === 15 && ($GLOBALS['ips']['props']['Port'] ?? null) === 2404, 'Vorlage setzt t1 und Port');
chk(($GLOBALS['ips']['props']['TimeMode'] ?? null) === 'local', 'EWE-Vorlage: Ortszeit (Annahme, gekennzeichnet)');
foreach ($rows as &$r) {
    if ($r['IOA'] === '30.0.12') { $r['Var'] = 202; }
    if ($r['IOA'] === '30.0.1') { $r['Var'] = 900; }
}
unset($r);
$GLOBALS['ips']['props']['Points'] = json_encode($rows);
$m->ApplyChanges();
chk($GLOBALS['ips']['status'] === 102, 'mit Datenpunkten Status 102');
$m->LoadPreset('ewe_a_pv', 0, 5.0, 4.0, 0.0, 'ms');
$rows = json_decode($GLOBALS['ips']['props']['Points'], true);
$kept = array_values(array_filter($rows, fn ($r) => $r['IOA'] === '30.0.12'))[0]['Var'];
chk($kept === 202, 'Neuladen der Vorlage behaelt die Variable');
$m->ApplyChanges();

function send104(IEC104 $m, string $bytes, int $type = 0, string $ip = '10.0.0.1', int $port = 50000): string
{
    $GLOBALS['ips']['sent'] = [];
    $m->ReceiveData(json_encode(['DataID' => '{7A1272A4-CBDB-46EF-BFC6-DCF4A53D2FC7}', 'Type' => $type, 'ClientIP' => $ip, 'ClientPort' => $port,
        'Buffer' => mb_convert_encoding($bytes, 'UTF-8', 'ISO-8859-1')]));
    $out = '';
    foreach ($GLOBALS['ips']['sent'] as $j) {
        $d = json_decode($j, true);
        chk($d['DataID'] === '{C8792760-65CF-4C53-B5C7-A30FCC84FEFE}', 'Sende-DataID');
        chk($d['ClientIP'] === $ip && $d['ClientPort'] === $port, 'Antwort an denselben Client');
        $out .= mb_convert_encoding($d['Buffer'], 'ISO-8859-1', 'UTF-8');
    }
    return $out;
}
send104($m, '', 1);
$r = send104($m, "\x68\x04\x07\x00\x00\x00");
chk(str_starts_with(bin2hex($r), '68040b000000'), 'STARTDT act -> con (Bytes > 127 ueberleben die UTF-8-Huelle): ' . bin2hex($r));
chk(strlen($r) > 6 && ord($r[6]) === 0x68 && ord($r[12]) === 70, '"Initialisierung beendet" folgt als I-Rahmen');
// Generalabfrage
$ga = "\x64\x01\x06\x00\x01\x00\x00\x00\x00\x14";
$r = send104($m, "\x68" . chr(4 + strlen($ga)) . "\x00\x00\x02\x00" . $ga);
chk(strlen($r) > 20 && ord($r[6]) === 100, 'Generalabfrage: ACTCON zuerst');
// Sollwert (Typ 63) an 30.0.1
$sp = "\x3F\x01\x06\x00\x01\x00" . "\x01\x00\x1E" . pack('g', 55.0) . "\x00" . str_repeat("\x00", 7);
$r = send104($m, "\x68" . chr(4 + strlen($sp)) . "\x02\x00\x02\x00" . $sp);
chk(($GLOBALS['ips']['actions'][0] ?? null) === [900, 55.0], 'Sollwert erreicht die Ziel-Variable');
chk($r !== '' && ord($r[6]) === 63 && (ord($r[8]) & 0x3F) === 7, 'Sollwert wird mit ACTCON bestaetigt');
$spId = $GLOBALS['ips']['ident']['SP_1e0001'] ?? null;
chk($spId !== null && GetValue($spId) === 55.0, 'zuletzt gueltiger Sollwert in Modulvariable');
// Aenderung der Quellvariable -> spontan gesendet
$GLOBALS['ips']['vars'][202]['v'] = 4.0;
$GLOBALS['ips']['sent'] = [];
$m->MessageSink(0, 202, VM_UPDATE, []);
$got = false;
foreach ($GLOBALS['ips']['sent'] as $j) {
    $b = mb_convert_encoding(json_decode($j, true)['Buffer'], 'ISO-8859-1', 'UTF-8');
    if (ord($b[6]) === 36 && (ord($b[8]) & 0x3F) === 3) { $got = true; }
}
chk($got, 'Wertaenderung wird spontan als Typ 36 gesendet');
// Trennen
send104($m, '', 2);
$rep = $m->GetConnectionReport();
chk(str_contains($rep, 'Verbindungen: keine'), 'Bericht nach Trennen: keine Verbindung: ' . str_replace("\n", ' | ', $rep));
// Tick
$m->Tick();
chk($GLOBALS['ips']['vars'][$GLOBALS['ips']['ident']['DataActive']]['v'] === false, 'DataActive false ohne Verbindung');

$form = json_decode($m->GetConfigurationForm(), true);
$caps = array_map(fn ($e) => $e['caption'] ?? ($e['name'] ?? ''), $form['elements']);
chk(str_contains($caps[0], 'Wozu dieses Modul'), 'Panel 1: Wozu dieses Modul');
chk(str_contains($caps[1], 'Neu in Version'), 'Panel 2: Neu in Version');
chk(str_contains($caps[2], 'Dokumentation'), 'Panel 3: Dokumentation & Hilfe');
chk(str_contains(end($caps), 'Über dieses Modul'), 'letztes Panel: Lizenz');
chk(substr_count(json_encode($form), '"PopupButton"') >= 3, 'Hilfe-Knoepfe vorhanden');
$txt = '';
foreach ($form['elements'] as $e) { if (($e['name'] ?? '') === 'StatusText') { $txt = $e['caption']; } }
chk(str_contains($txt, 'Server Socket #7100') && str_contains($txt, 'Port 2404'), 'Statuszeile nennt Server Socket und Port');
echo $txt . "\n";
$notes = '';
foreach ($form['actions'] as $a) { if (($a['name'] ?? '') === 'PresetNotes') { $notes = $a['caption']; } }
chk(str_contains($notes, 'Offen:'), 'Vorlagen-Hinweis nennt Offenes');
$m->ShowPresetNotes('ewf_v17');
chk(str_contains(json_encode($GLOBALS['ips']['formUpdates']), 'EWF'), 'Hinweis folgt der Auswahl');
$rep = $m->GetPointReport();
chk(str_contains($rep, '2 von '), 'Bericht: 2 Punkte belegt');
$form = json_decode($m->GetConfigurationForm(), true);
$panel = array_values(array_filter($form['elements'], fn ($e) => ($e['name'] ?? '') === 'PointReportPanel'))[0] ?? null;
chk($panel !== null && str_contains($panel['items'][0]['caption'], '2 von '), 'Panel "Datenpunkte pruefen" zeigt den Bericht');
$connPanel = array_values(array_filter($form['elements'], fn ($e) => ($e['name'] ?? '') === 'ConnectionReportPanel'))[0] ?? null;
chk($connPanel !== null && str_contains($connPanel['items'][0]['caption'], 'Verbindungen:'), 'Panel "Verbindungen" zeigt den Bericht');
chk(!str_contains(json_encode($form), 'echo IEC'), 'kein echo(IEC..._GetPointReport/GetConnectionReport) mehr im Formular');
$m->LoadPreset('ewf_v17', 1);
chk(($GLOBALS['ips']['props']['T1'] ?? null) === 250, 'EWF-Vorlage setzt t1 = 250');
$m->AckPurposeIntro();
$m->AckNews();
$caps = implode('|', array_map(fn ($e) => $e['caption'] ?? '', json_decode($m->GetConfigurationForm(), true)['elements']));
chk(!str_contains($caps, 'Wozu dieses Modul') && !str_contains($caps, 'Neu in Version'), 'nach Bestaetigen sind Zweck und Neu weg');
chk($GLOBALS['ips']['log'] === [] || !array_filter($GLOBALS['ips']['log'], fn ($l) => !str_contains($l, 'STARTDT') && !str_contains($l, 'Verbindung') && !str_contains($l, 'Getrennt') && !str_contains($l, 'ausgefallen')), 'keine Fehlermeldungen im Log: ' . json_encode($GLOBALS['ips']['log']));
echo $fails === 0 ? "Modul-Stub 104: alles in Ordnung\n" : "Modul-Stub 104: $fails Fehler\n";
exit($fails ? 1 : 0);
