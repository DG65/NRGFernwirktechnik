<?php

declare(strict_types=1);

require_once __DIR__ . '/FW101_Station.php';

// Verbindungsschicht der IEC 60870-5-104 als gesteuerte Station (Server).
// Die Zentralstation (Fernwirkgateway des Netzbetreibers) baut die TCP-Verbindung auf.
// APCI: I-Format (Daten mit Sende- und Empfangsfolgezaehler), S-Format (Quittung),
// U-Format (STARTDT, STOPDT, TESTFR), Timer t0-t3, Fenster k und w.
// Die Anwendungsschicht ist FW101_Station (dieselbe wie beim 101-Modul).
// Unabhaengig vom IPS; die Zeit wird von aussen uebergeben, damit alles ohne Wartezeit testbar ist.

final class FW104_Link
{
    private const START = 0x68;
    private const MOD = 32768;

    private const U_STARTDT_ACT = 0x07;
    private const U_STARTDT_CON = 0x0B;
    private const U_STOPDT_ACT = 0x13;
    private const U_STOPDT_CON = 0x23;
    private const U_TESTFR_ACT = 0x43;
    private const U_TESTFR_CON = 0x83;

    /** APDU-Laenge maximal 253 (Steuerfeld 4 + ASDU 249). */
    private const MAX_ASDU = 249;

    /** @var array<string,array> Verbindungen nach "ip:port" */
    private array $conn = [];
    private ?string $active = null;
    private array $stats = ['protocolErrors' => 0, 'timeouts' => 0, 'takeovers' => 0];

    /** @var array<int,string> letzte Ereignisse (fuer Diagnose) */
    private array $events = [];

    /**
     * @param array{t0:int,t1:int,t2:int,t3:int,k:int,w:int} $cfg
     */
    public function __construct(private array $cfg, private FW101_Station $station, private ?FW101_Host $host = null)
    {
    }

    public function export(): array
    {
        return ['conn' => $this->conn, 'active' => $this->active, 'stats' => $this->stats, 'events' => $this->events];
    }

    public function import(array $s): void
    {
        $this->conn = $s['conn'] ?? [];
        $this->active = $s['active'] ?? null;
        $this->stats = ($s['stats'] ?? []) + $this->stats;
        $this->events = $s['events'] ?? [];
    }

    public function diagnose(): array
    {
        $a = $this->active !== null ? ($this->conn[$this->active] ?? null) : null;
        return [
            'connections' => array_keys($this->conn),
            'active'      => $this->active,
            'started'     => $a !== null && $a['started'],
            'unacked'     => $a !== null ? count($a['unacked']) : 0,
            'stats'       => $this->stats,
            'events'      => $this->events,
        ];
    }

    // ------------------------------------------------------------ Verbindung

    public function connected(string $key, float $now): void
    {
        $this->conn[$key] = $this->fresh($now);
        $this->note('Verbindung ' . $key);
    }

    public function disconnected(string $key): void
    {
        if (isset($this->conn[$key])) {
            $this->note('Getrennt ' . $key);
        }
        unset($this->conn[$key]);
        if ($this->active === $key) {
            $this->active = null;
        }
    }

    private function fresh(float $now): array
    {
        return [
            'rx' => '', 'started' => false, 'vs' => 0, 'vr' => 0, 'ack' => 0, 'unacked' => [],
            'rcvUnacked' => 0, 't2' => null, 'lastRx' => $now, 'lastTx' => $now, 'connAt' => $now,
            'testAt' => null, 'gotFrame' => false,
        ];
    }

    private function note(string $m): void
    {
        $this->events[] = date('H:i:s') . ' ' . $m;
        if (count($this->events) > 12) {
            array_shift($this->events);
        }
        $this->host?->log($m);
    }

    /** Verbindung als tot markieren: nichts mehr senden, neue STARTDT darf uebernehmen. */
    private function kill(string $key, string $why): void
    {
        $this->note("Verbindung $key beendet: $why");
        $this->conn[$key] = $this->fresh(0.0);
        $this->conn[$key]['dead'] = true;
        if ($this->active === $key) {
            $this->active = null;
        }
    }

    // --------------------------------------------------------------- Empfang

    /**
     * Empfangene Bytes einer Verbindung verarbeiten.
     * @return array<string,string> zu sendende Bytes je Verbindung
     */
    public function feed(string $key, string $data, float $now): array
    {
        if (!isset($this->conn[$key])) {
            $this->connected($key, $now);
        } elseif (!empty($this->conn[$key]['dead'])) {
            // Die Gegenstelle spricht weiter: neue Sitzung auf derselben TCP-Verbindung
            $this->conn[$key] = $this->fresh($now);
        }
        $this->conn[$key]['rx'] .= $data;
        $out = [];
        while (isset($this->conn[$key]) && $this->conn[$key]['rx'] !== '') {
            $rx = $this->conn[$key]['rx'];
            if (ord($rx[0]) !== self::START) {
                $this->conn[$key]['rx'] = substr($rx, 1); // Muell vor dem Rahmenanfang verwerfen
                continue;
            }
            if (strlen($rx) < 2) {
                break;
            }
            $len = ord($rx[1]);
            if ($len < 4 || $len > 253) {
                $this->stats['protocolErrors']++;
                $this->conn[$key]['rx'] = substr($rx, 1);
                continue;
            }
            if (strlen($rx) < 2 + $len) {
                break;
            }
            $this->conn[$key]['rx'] = substr($rx, 2 + $len);
            $this->conn[$key]['lastRx'] = $now;
            $this->conn[$key]['gotFrame'] = true;
            $this->conn[$key]['testAt'] = null; // jeder Rahmen zeigt: Gegenstelle lebt
            $this->handleApdu($key, substr($rx, 2, $len), $now, $out);
            if (!empty($this->conn[$key]['dead'])) {
                break;
            }
        }
        if (strlen($this->conn[$key]['rx'] ?? '') > 1024) {
            $this->conn[$key]['rx'] = '';
        }
        $this->flush($now, $out);
        return $out;
    }

    private function handleApdu(string $key, string $apdu, float $now, array &$out): void
    {
        $c = &$this->conn[$key];
        $c1 = ord($apdu[0]);
        if (($c1 & 1) === 0) { // I-Format
            if (!$c['started'] || $key !== $this->active) {
                $this->stats['protocolErrors']++;
                $this->note("I-Rahmen ohne STARTDT von $key ignoriert");
                return;
            }
            $ns = ($c1 >> 1) | (ord($apdu[1]) << 7);
            $nr = (ord($apdu[2]) >> 1) | (ord($apdu[3]) << 7);
            if (!$this->ackUpTo($key, $nr)) {
                $this->kill($key, 'ungueltige Empfangsfolgenummer');
                $this->stats['protocolErrors']++;
                return;
            }
            if ($ns !== $c['vr']) {
                $this->kill($key, "Sendefolgenummer $ns statt {$c['vr']}");
                $this->stats['protocolErrors']++;
                return;
            }
            $c['vr'] = ($c['vr'] + 1) % self::MOD;
            $c['rcvUnacked']++;
            $c['t2'] ??= $now;
            $this->station->contact();
            $asdu = substr($apdu, 4);
            if ($asdu !== '') {
                $this->station->receiveAsdu($asdu);
            }
            if ($c['rcvUnacked'] >= $this->cfg['w']) {
                $out[$key] = ($out[$key] ?? '') . $this->sFrame($key, $now);
            }
            return;
        }
        if (($c1 & 3) === 1) { // S-Format
            if (strlen($apdu) < 4) {
                return;
            }
            $nr = (ord($apdu[2]) >> 1) | (ord($apdu[3]) << 7);
            $this->station->contact();
            if (!$this->ackUpTo($key, $nr)) {
                $this->kill($key, 'ungueltige Empfangsfolgenummer im S-Rahmen');
                $this->stats['protocolErrors']++;
            }
            return;
        }
        // U-Format
        $this->station->contact();
        switch ($c1) {
            case self::U_STARTDT_ACT:
                if ($this->active !== null && $this->active !== $key) {
                    $this->stats['takeovers']++;
                    $this->note("STARTDT von $key uebernimmt die aktive Verbindung {$this->active}");
                    if (isset($this->conn[$this->active])) {
                        $this->conn[$this->active]['started'] = false;
                    }
                }
                $first = $this->active !== $key || !$c['started'];
                $this->active = $key;
                $c['started'] = true;
                $out[$key] = ($out[$key] ?? '') . $this->uFrame(self::U_STARTDT_CON);
                if ($first) {
                    $this->station->linkReset(); // Warteschlange leeren, "Initialisierung beendet" melden
                }
                $this->note("STARTDT von $key");
                break;
            case self::U_STOPDT_ACT:
                $c['started'] = false;
                if ($this->active === $key) {
                    $this->active = null;
                }
                $out[$key] = ($out[$key] ?? '') . $this->uFrame(self::U_STOPDT_CON);
                $this->note("STOPDT von $key");
                break;
            case self::U_TESTFR_ACT:
                $out[$key] = ($out[$key] ?? '') . $this->uFrame(self::U_TESTFR_CON);
                break;
            case self::U_TESTFR_CON:
                break;
            default:
                $this->stats['protocolErrors']++;
        }
    }

    /** Quittung N(R) verarbeiten. false = ausserhalb des gueltigen Bereichs (ack .. vs). */
    private function ackUpTo(string $key, int $nr): bool
    {
        $c = &$this->conn[$key];
        $n = ($nr - $c['ack'] + self::MOD) % self::MOD;
        if ($n > count($c['unacked'])) {
            return false;
        }
        for ($i = 0; $i < $n; $i++) {
            array_shift($c['unacked']);
        }
        $c['ack'] = ($c['ack'] + $n) % self::MOD;
        return true;
    }

    // ---------------------------------------------------------------- Senden

    /**
     * Zeitgesteuerte Aufgaben: t1, t2, t3, t0; danach anstehende Daten senden.
     * @return array<string,string>
     */
    public function tick(float $now): array
    {
        $out = [];
        foreach (array_keys($this->conn) as $key) {
            $c = &$this->conn[$key];
            if (!empty($c['dead'])) {
                unset($c);
                continue;
            }
            // t0: Verbindung ohne jeden Rahmen (Server-Auslegung: nichts empfangen)
            if (!$c['gotFrame'] && $now - $c['connAt'] >= $this->cfg['t0']) {
                $this->stats['timeouts']++;
                $this->kill($key, 't0: kein Rahmen empfangen');
                unset($c);
                continue;
            }
            // t1: aelteste unquittierte I-Meldung oder unbeantwortetes TESTFR
            if ($c['unacked'] !== [] && $now - $c['unacked'][0] >= $this->cfg['t1']) {
                $this->stats['timeouts']++;
                $this->kill($key, 't1: keine Quittung');
                unset($c);
                continue;
            }
            if ($c['testAt'] !== null && $now - $c['testAt'] >= $this->cfg['t1']) {
                $this->stats['timeouts']++;
                $this->kill($key, 't1: TESTFR nicht beantwortet');
                unset($c);
                continue;
            }
            // t2: Empfang quittieren, wenn keine Daten in Gegenrichtung gehen
            if ($c['rcvUnacked'] > 0 && $c['t2'] !== null && $now - $c['t2'] >= $this->cfg['t2']) {
                $out[$key] = ($out[$key] ?? '') . $this->sFrame($key, $now);
            }
            // t3: lange Ruhe -> TESTFR
            if ($c['testAt'] === null && $c['gotFrame'] && $now - max($c['lastRx'], $c['lastTx']) >= $this->cfg['t3']) {
                $c['testAt'] = $now;
                $c['lastTx'] = $now;
                $out[$key] = ($out[$key] ?? '') . $this->uFrame(self::U_TESTFR_ACT);
            }
            unset($c);
        }
        $this->flush($now, $out);
        return $out;
    }

    /** Warteschlange der Station in I-Rahmen an die aktive Verbindung schicken (Fenster k). */
    public function flush(float $now, array &$out): void
    {
        if ($this->active === null) {
            return;
        }
        $key = $this->active;
        $c = &$this->conn[$key];
        if (!$c['started'] || !empty($c['dead'])) {
            return;
        }
        while (count($c['unacked']) < $this->cfg['k'] && $this->station->class1Pending()) {
            $asdu = $this->station->popClass1();
            if ($asdu === null) {
                break;
            }
            if (strlen($asdu) > self::MAX_ASDU) {
                $this->host?->log('ASDU zu lang, verworfen: ' . strlen($asdu));
                continue;
            }
            $out[$key] = ($out[$key] ?? '') . $this->iFrame($key, $asdu, $now);
        }
    }

    /** Nach einer Aenderung in der Station (Variable, Befehl) anstehende Daten senden. */
    public function pump(float $now): array
    {
        $out = [];
        $this->flush($now, $out);
        return $out;
    }

    private function iFrame(string $key, string $asdu, float $now): string
    {
        $c = &$this->conn[$key];
        $ns = $c['vs'];
        $nr = $c['vr'];
        $c['vs'] = ($c['vs'] + 1) % self::MOD;
        $c['unacked'][] = $now;
        $c['rcvUnacked'] = 0;
        $c['t2'] = null;
        $c['lastTx'] = $now;
        return chr(self::START) . chr(4 + strlen($asdu))
            . chr(($ns << 1) & 0xFE) . chr($ns >> 7)
            . chr(($nr << 1) & 0xFE) . chr($nr >> 7) . $asdu;
    }

    private function sFrame(string $key, float $now): string
    {
        $c = &$this->conn[$key];
        $nr = $c['vr'];
        $c['rcvUnacked'] = 0;
        $c['t2'] = null;
        $c['lastTx'] = $now;
        return chr(self::START) . chr(4) . chr(0x01) . chr(0x00) . chr(($nr << 1) & 0xFE) . chr($nr >> 7);
    }

    private function uFrame(int $code): string
    {
        return chr(self::START) . chr(4) . chr($code) . "\x00\x00\x00";
    }
}
