<?php

declare(strict_types=1);

// Verbindungsschicht FT1.2 der IEC 60870-5-101, unsymmetrisch, als Unterstation (Slave).
// Das Kommunikationsmodul des Netzbetreibers ist die Zentralstation (Master) und fragt ab.
// Unabhaengig vom IPS, damit es ausserhalb des Kernels getestet werden kann.

interface FW101_LinkHost
{
    /** Verbindung wurde zurueckgesetzt (Reset der Verbindung / Prozess). */
    public function linkReset(): void;

    /** Gibt es Daten der Klasse 1 (Ereignisse)? Steuert das ACD-Bit. */
    public function class1Pending(): bool;

    /** Naechste ASDU der Klasse 1 oder null. */
    public function popClass1(): ?string;

    /** Naechste ASDU der Klasse 2 oder null. */
    public function popClass2(): ?string;

    /** Vom Master empfangene ASDU (Befehl, Sollwert, Generalabfrage ...). */
    public function receiveAsdu(string $asdu): void;

    /** Ein gueltiger, an uns adressierter Rahmen wurde empfangen. */
    public function contact(): void;
}

final class FW101_Link
{
    private const START_FIXED = 0x10;
    private const START_VAR = 0x68;
    private const END = 0x16;
    private const SINGLE_ACK = 0xE5;

    // Steuerfeld vom Master (PRM = 1)
    private const FC_RESET_LINK = 0;
    private const FC_RESET_USER = 1;
    private const FC_USER_CONFIRM = 3;
    private const FC_USER_NOREPLY = 4;
    private const FC_ACCESS_DEMAND = 8;
    private const FC_REQ_STATUS = 9;
    private const FC_REQ_CLASS1 = 10;
    private const FC_REQ_CLASS2 = 11;

    // Steuerfeld von der Unterstation (PRM = 0)
    private const RC_ACK = 0;
    private const RC_USER_DATA = 8;
    private const RC_NO_DATA = 9;
    private const RC_STATUS = 11;
    private const RC_NOT_IMPLEMENTED = 15;

    private string $rx = '';
    private ?int $lastFcb = null;
    private string $lastReply = '';

    /**
     * @param array{linkAddr:int,linkLen:int,ackSingleChar:bool} $cfg
     */
    public function __construct(private array $cfg, private FW101_LinkHost $host)
    {
    }

    public function export(): array
    {
        return ['rx' => $this->rx, 'fcb' => $this->lastFcb, 'reply' => $this->lastReply];
    }

    public function import(array $s): void
    {
        $this->rx = (string) ($s['rx'] ?? '');
        $this->lastFcb = $s['fcb'] ?? null;
        $this->lastReply = (string) ($s['reply'] ?? '');
    }

    /** Empfangene Bytes verarbeiten. Gibt die zu sendenden Bytes zurueck (kann leer sein). */
    public function feed(string $data): string
    {
        $this->rx .= $data;
        $out = '';
        $al = $this->cfg['linkLen'];
        while ($this->rx !== '') {
            $b = ord($this->rx[0]);
            if ($b === self::SINGLE_ACK) {
                $this->rx = substr($this->rx, 1);
                continue;
            }
            if ($b === self::START_FIXED) {
                $len = 2 + $al + 2;
                if (strlen($this->rx) < $len) {
                    break;
                }
                $f = substr($this->rx, 0, $len);
                if (ord($f[$len - 1]) !== self::END || self::checksum(substr($f, 1, 1 + $al)) !== ord($f[$len - 2])) {
                    $this->rx = substr($this->rx, 1);
                    continue;
                }
                $this->rx = substr($this->rx, $len);
                $out .= $this->onFrame(ord($f[1]), $this->readAddr(substr($f, 2, $al)), null);
                continue;
            }
            if ($b === self::START_VAR) {
                if (strlen($this->rx) < 4) {
                    break;
                }
                $l = ord($this->rx[1]);
                if ($this->rx[2] !== $this->rx[1] || ord($this->rx[3]) !== self::START_VAR || $l < 1 + $al) {
                    $this->rx = substr($this->rx, 1);
                    continue;
                }
                $total = 4 + $l + 2;
                if (strlen($this->rx) < $total) {
                    break;
                }
                $f = substr($this->rx, 0, $total);
                if (ord($f[$total - 1]) !== self::END || self::checksum(substr($f, 4, $l)) !== ord($f[$total - 2])) {
                    $this->rx = substr($this->rx, 1);
                    continue;
                }
                $this->rx = substr($this->rx, $total);
                $out .= $this->onFrame(ord($f[4]), $this->readAddr(substr($f, 5, $al)), substr($f, 5 + $al, $l - 1 - $al));
                continue;
            }
            $this->rx = substr($this->rx, 1); // Muell vor dem Rahmenanfang verwerfen
        }
        if (strlen($this->rx) > 1024) {
            $this->rx = '';
        }
        return $out;
    }

    private function onFrame(int $c, int $addr, ?string $asdu): string
    {
        if (($c & 0x40) === 0) {
            return ''; // Antwort einer anderen Unterstation, nicht fuer uns
        }
        $broadcast = $addr === (1 << (8 * $this->cfg['linkLen'])) - 1;
        if ($addr !== $this->cfg['linkAddr'] && !$broadcast) {
            return '';
        }
        $fc = $c & 0x0F;
        if ($broadcast && $fc !== self::FC_USER_NOREPLY) {
            return '';
        }
        $fcb = ($c & 0x20) !== 0 ? 1 : 0;
        $fcv = ($c & 0x10) !== 0;
        $this->host->contact();

        switch ($fc) {
            case self::FC_RESET_LINK:
            case self::FC_RESET_USER:
                $this->lastFcb = null;
                $this->lastReply = '';
                $this->host->linkReset();
                return $this->ack();

            case self::FC_REQ_STATUS:
            case self::FC_ACCESS_DEMAND:
                return $this->fixed(self::RC_STATUS | $this->acd());

            case self::FC_USER_CONFIRM:
            case self::FC_USER_NOREPLY:
                if ($fc === self::FC_USER_CONFIRM && $fcv && $this->isRepeat($fcb)) {
                    return $this->lastReply;
                }
                if ($asdu !== null && $asdu !== '') {
                    $this->host->receiveAsdu($asdu);
                }
                if ($fc === self::FC_USER_NOREPLY) {
                    return '';
                }
                return $this->remember($fcv, $fcb, $this->ack());

            case self::FC_REQ_CLASS1:
            case self::FC_REQ_CLASS2:
                if ($fcv && $this->isRepeat($fcb)) {
                    return $this->lastReply;
                }
                $data = $fc === self::FC_REQ_CLASS1 ? $this->host->popClass1() : $this->host->popClass2();
                $reply = $data === null
                    ? $this->fixed(self::RC_NO_DATA | $this->acd())
                    : $this->variable(self::RC_USER_DATA | $this->acd(), $data);
                return $this->remember($fcv, $fcb, $reply);

            default:
                return $this->fixed(self::RC_NOT_IMPLEMENTED | $this->acd());
        }
    }

    private function remember(bool $fcv, int $fcb, string $reply): string
    {
        if ($fcv) {
            $this->lastFcb = $fcb;
            $this->lastReply = $reply;
        }
        return $reply;
    }

    private function isRepeat(int $fcb): bool
    {
        return $this->lastFcb !== null && $this->lastFcb === $fcb;
    }

    private function acd(): int
    {
        return $this->host->class1Pending() ? 0x20 : 0;
    }

    private function ack(): string
    {
        if ($this->cfg['ackSingleChar'] && !$this->host->class1Pending()) {
            return chr(self::SINGLE_ACK);
        }
        return $this->fixed(self::RC_ACK | $this->acd());
    }

    private function fixed(int $c): string
    {
        $a = $this->addrBytes();
        return chr(self::START_FIXED) . chr($c) . $a . chr(self::checksum(chr($c) . $a)) . chr(self::END);
    }

    private function variable(int $c, string $asdu): string
    {
        $a = $this->addrBytes();
        $body = chr($c) . $a . $asdu;
        $l = strlen($body);
        if ($l > 255) {
            throw new RuntimeException('ASDU zu lang: ' . $l);
        }
        return chr(self::START_VAR) . chr($l) . chr($l) . chr(self::START_VAR) . $body . chr(self::checksum($body)) . chr(self::END);
    }

    private function addrBytes(): string
    {
        $s = '';
        for ($i = 0; $i < $this->cfg['linkLen']; $i++) {
            $s .= chr(($this->cfg['linkAddr'] >> (8 * $i)) & 0xFF);
        }
        return $s;
    }

    private function readAddr(string $s): int
    {
        $v = 0;
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $v |= ord($s[$i]) << (8 * $i);
        }
        return $v;
    }

    public static function checksum(string $s): int
    {
        $sum = 0;
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $sum += ord($s[$i]);
        }
        return $sum & 0xFF;
    }
}
