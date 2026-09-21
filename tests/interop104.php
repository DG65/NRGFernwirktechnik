<?php

declare(strict_types=1);

// Interoperabilitaetstest 104: die Unterstation (FW104_Link + FW101_Station) an einem lokalen TCP-Port,
// dagegen laeuft der lib60870-CS104-Client tests/interop/cs104_master.
// Aufruf: php tests/interop104.php [Port] [Sekunden]   (dann in einem zweiten Terminal: tests/interop/cs104_master 127.0.0.1 <Port>)

date_default_timezone_set('Europe/Berlin');
require_once __DIR__ . '/../libs/FW104_Link.php';
require_once __DIR__ . '/../libs/FW101_Presets.php';
require_once __DIR__ . '/../libs/FW104_Presets.php';

final class DemoHost implements FW101_Host
{
    public array $vars = [301 => 1.234, 302 => 2.0, 303 => true, 304 => 2];
    public array $store = [];
    public function value(int $varId): mixed { return $this->vars[$varId] ?? null; }
    public function action(int $varId, mixed $value): bool { fwrite(STDERR, "[Host] Aktion Variable $varId = " . json_encode($value) . "\n"); return true; }
    public function varType(int $varId): int { return $varId === 905 ? 0 : 2; }
    public function store(string $key, mixed $value): void { $this->store[$key] = $value; }
    public function load(string $key): mixed { return $this->store[$key] ?? null; }
    public function localMode(): bool { return false; }
    public function log(string $message): void { fwrite(STDERR, "[Log] $message\n"); }
    public function now(): float { return microtime(true); }
}

$port = (int) ($argv[1] ?? 2404);
$secs = (int) ($argv[2] ?? 60);

$rows = FW104_Presets::points('ewe_a_pv', ['index' => 0]);
foreach ($rows as &$r) {
    switch ($r['IOA']) {
        case '10.0.13': $r['Var'] = 301; break;
        case '30.0.12': $r['Var'] = 302; break; // Faktor -1 aus der Vorlage
        case '30.0.1': $r['Var'] = 900; break;
    }
}
unset($r);
$rows[] = ['Name' => 'Meldung', 'Type' => 30, 'IOA' => '0.0.11', 'Var' => 303, 'Factor' => 1.0, 'Delta' => 0.0, 'Min' => '', 'Max' => '', 'Mirror' => '', 'Fail' => '', 'Req' => false];
$rows[] = ['Name' => 'Schalter', 'Type' => 31, 'IOA' => '0.1.125', 'Var' => 304, 'Factor' => 1.0, 'Delta' => 0.0, 'Min' => '', 'Max' => '', 'Mirror' => '', 'Fail' => '', 'Req' => false];
$rows[] = ['Name' => 'Befehl', 'Type' => 45, 'IOA' => '1.0.11', 'Var' => 905, 'Factor' => 1.0, 'Delta' => 0.0, 'Min' => '', 'Max' => '', 'Mirror' => '', 'Fail' => '', 'Req' => false];

$link = FW104_Presets::link('ewe_a_pv');
$host = new DemoHost();
$station = new FW101_Station(['ca' => 1, 'acceptCaZero' => false, 'cotLen' => 2, 'caLen' => 2, 'ioaLen' => 3,
    'timeMode' => 'local', 'pct' => 1.0, 'minDelta' => 0.001, 'maxInterval' => $link['MaxInterval'], 'failSeconds' => 0,
    'localBlocksSetpoints' => false, 'sendInit' => true, 'giPlain' => $link['GiPlain']], FW101_Station::normalize($rows), $host);
$station->preload();
$lk = new FW104_Link(['t0' => $link['T0'], 't1' => $link['T1'], 't2' => $link['T2'], 't3' => 6, 'k' => $link['K'], 'w' => $link['W']], $station, $host);

$srv = stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
if (!$srv) {
    die("Port $port: $errstr\n");
}
stream_set_blocking($srv, false);
fwrite(STDERR, "[Start] lausche auf 127.0.0.1:$port fuer $secs s\n");
$clients = [];
$end = microtime(true) + $secs;
$startedAt = null;
$changed = false;
$lastTick = 0.0;
while (microtime(true) < $end) {
    $read = array_merge([$srv], array_map(fn ($c) => $c['s'], $clients));
    $w = $e = null;
    if (@stream_select($read, $w, $e, 0, 100000) > 0) {
        foreach ($read as $s) {
            if ($s === $srv) {
                $c = @stream_socket_accept($srv, 0);
                if ($c) {
                    stream_set_blocking($c, false);
                    $key = stream_socket_get_name($c, true);
                    $clients[$key] = ['s' => $c];
                    $lk->connected($key, microtime(true));
                }
                continue;
            }
            $key = array_search($s, array_map(fn ($c) => $c['s'], $clients), true);
            $in = fread($s, 8192);
            if ($in === '' || $in === false) {
                if (feof($s)) {
                    $lk->disconnected($key);
                    fclose($s);
                    unset($clients[$key]);
                }
                continue;
            }
            $out = $lk->feed($key, $in, microtime(true));
            foreach ($out as $k => $bytes) {
                if (isset($clients[$k])) {
                    fwrite($clients[$k]['s'], $bytes);
                }
            }
            if ($startedAt === null && $lk->diagnose()['started']) {
                $startedAt = microtime(true);
            }
        }
    }
    if (microtime(true) - $lastTick >= 0.25) {
        $lastTick = microtime(true);
        $station->tick();
        foreach ($lk->tick($lastTick) as $k => $bytes) {
            if (isset($clients[$k])) {
                fwrite($clients[$k]['s'], $bytes);
            }
        }
    }
    if ($startedAt !== null && !$changed && microtime(true) - $startedAt >= 7.0) {
        $changed = true;
        $host->vars[301] = 3.0;
        $station->variableChanged(301);
        foreach ($lk->pump(microtime(true)) as $k => $bytes) {
            if (isset($clients[$k])) {
                fwrite($clients[$k]['s'], $bytes);
            }
        }
        fwrite(STDERR, "[Host] Pgesamt auf 3,0 geaendert\n");
    }
}
$d = $lk->diagnose();
fwrite(STDERR, '[Ende] Protokollfehler ' . $d['stats']['protocolErrors'] . ', Zeitueberschreitungen ' . $d['stats']['timeouts'] . ', Uebernahmen ' . $d['stats']['takeovers'] . "\n");
fwrite(STDERR, implode("\n", array_map(fn ($e) => '[Ereignis] ' . $e, $d['events'])) . "\n");
