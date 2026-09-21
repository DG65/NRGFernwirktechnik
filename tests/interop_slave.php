<?php

declare(strict_types=1);

// Interoperabilitaetstest: die Unterstation ueber ein serielles Geraet (Pseudo-Terminal) laufen lassen
// und mit einem fremden Master (lib60870, CS101 unbalanced) sprechen. Aufruf: php tests/interop_slave.php /dev/ttysNNN [Sekunden]

date_default_timezone_set('Europe/Berlin');
require_once __DIR__ . '/../libs/FW101_Station.php';
require_once __DIR__ . '/../libs/FW101_Presets.php';

final class DemoHost implements FW101_Host
{
    public array $vars = [201 => 50.02, 202 => -3.2, 203 => false, 204 => 2, 205 => 0.0];
    public array $store = [];
    public function value(int $varId): mixed { return $this->vars[$varId] ?? null; }
    public function action(int $varId, mixed $value): bool { fwrite(STDERR, "[Host] Aktion Variable $varId = " . json_encode($value) . "\n"); return true; }
    public function varType(int $varId): int { return $varId === 905 ? 1 : 2; }
    public function store(string $key, mixed $value): void { $this->store[$key] = $value; }
    public function load(string $key): mixed { return $this->store[$key] ?? null; }
    public function localMode(): bool { return false; }
    public function log(string $message): void { fwrite(STDERR, "[Log] $message\n"); }
    public function now(): float { return microtime(true); }
}

$path = $argv[1] ?? die("Geraet fehlt\n");
$secs = (int) ($argv[2] ?? 30);
exec('stty -f ' . escapeshellarg($path) . ' raw -echo 19200 cs8 parenb -parodd -cstopb 2>&1');
$fh = fopen($path, 'r+b');
stream_set_blocking($fh, false);

$rows = FW101_Presets::points(FW101_Presets::EWERK_V4);
foreach ($rows as &$r) {
    switch ($r['IOA']) {
        case '0.3.11': $r['Var'] = 201; break;
        case '0.3.9': $r['Var'] = 202; $r['Factor'] = -1.0; break;
        case '0.1.51': $r['Var'] = 203; break;
        case '0.3.125': $r['Var'] = 204; break;
        case '1.0.98': $r['Var'] = 900; break;
        case '1.3.125': $r['Var'] = 905; break;
    }
}
unset($r);
$host = new DemoHost();
$station = new FW101_Station(['ca' => 1, 'acceptCaZero' => true, 'cotLen' => 2, 'caLen' => 2, 'ioaLen' => 3,
    'timeMode' => 'utc', 'pct' => 10.0, 'minDelta' => 0.001, 'maxInterval' => 60, 'failSeconds' => 43200,
    'localBlocksSetpoints' => false, 'sendInit' => true], FW101_Station::normalize($rows), $host);
$station->preload();
$link = new FW101_Link(['linkAddr' => 1, 'linkLen' => 1, 'ackSingleChar' => false], $station);

$end = microtime(true) + $secs;
$lastTick = 0;
while (microtime(true) < $end) {
    $in = fread($fh, 4096);
    if ($in !== false && $in !== '') {
        $out = $link->feed($in);
        if ($out !== '') {
            fwrite($fh, $out);
            fflush($fh);
        }
    } else {
        usleep(2000);
    }
    if (microtime(true) - $lastTick > 1) {
        $station->tick();
        $lastTick = microtime(true);
    }
}
fwrite(STDERR, "[Ende]\n");
