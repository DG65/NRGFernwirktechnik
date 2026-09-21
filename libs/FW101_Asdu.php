<?php

declare(strict_types=1);

// ASDU-Hilfen fuer IEC 60870-5-101: Kopf, Adressen, CP56Time2a.
// Unabhaengig vom IPS, damit es ausserhalb des Kernels getestet werden kann.

final class FW101_Asdu
{
    public const M_SP_NA_1 = 1;
    public const M_ME_NC_1 = 13;
    public const M_SP_TB_1 = 30;
    public const M_DP_TB_1 = 31;
    public const M_ME_TF_1 = 36;
    public const M_EI_NA_1 = 70;
    public const C_SC_NA_1 = 45;
    public const C_DC_NA_1 = 46;
    public const C_SE_NC_1 = 50;
    public const C_SC_TA_1 = 58;
    public const C_DC_TA_1 = 59;
    public const C_SE_TC_1 = 63;
    public const C_IC_NA_1 = 100;
    public const C_CS_NA_1 = 103;
    public const C_TS_NA_1 = 104;
    public const C_TS_TA_1 = 107;

    // Uebertragungsursachen
    public const COT_PERIODIC = 1;
    public const COT_SPONT = 3;
    public const COT_INIT = 4;
    public const COT_ACT = 6;
    public const COT_ACTCON = 7;
    public const COT_DEACT = 8;
    public const COT_DEACTCON = 9;
    public const COT_ACTTERM = 10;
    public const COT_INROGEN = 20;
    public const COT_UNKNOWN_TYPE = 44;
    public const COT_UNKNOWN_COT = 45;
    public const COT_UNKNOWN_CA = 46;
    public const COT_UNKNOWN_IOA = 47;

    /** Laenge der Informationselemente (ohne IOA) je Befehlstyp. */
    public static function commandBodyLength(int $type): ?int
    {
        return match ($type) {
            self::C_SC_NA_1, self::C_DC_NA_1 => 1,
            self::C_SE_NC_1 => 5,
            self::C_SC_TA_1, self::C_DC_TA_1 => 8,
            self::C_SE_TC_1 => 12,
            self::C_IC_NA_1 => 1,
            self::C_CS_NA_1 => 7,
            default => null,
        };
    }

    public static function ioa(int $v, int $len): string
    {
        $s = '';
        for ($i = 0; $i < $len; $i++) {
            $s .= chr(($v >> (8 * $i)) & 0xFF);
        }
        return $s;
    }

    public static function readLe(string $s, int $pos, int $len): int
    {
        $v = 0;
        for ($i = 0; $i < $len; $i++) {
            $v |= ord($s[$pos + $i]) << (8 * $i);
        }
        return $v;
    }

    /** Text "1.0.99" (High.Mid.Low) oder Zahl in die numerische IOA. */
    public static function parseIoa(string $text): ?int
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }
        if (str_contains($text, '.')) {
            $p = explode('.', $text);
            if (count($p) > 3) {
                return null;
            }
            $v = 0;
            foreach ($p as $part) {
                if (!ctype_digit($part) || (int) $part > 255) {
                    return null;
                }
                $v = ($v << 8) | (int) $part;
            }
            return $v;
        }
        return ctype_digit($text) ? (int) $text : null;
    }

    public static function formatIoa(int $v): string
    {
        return (($v >> 16) & 0xFF) . '.' . (($v >> 8) & 0xFF) . '.' . ($v & 0xFF);
    }

    /**
     * ASDU-Kopf.
     * @param array{cotLen:int,caLen:int,ioaLen:int} $cfg
     */
    public static function header(int $type, int $count, int $cause, bool $pn, int $oa, int $ca, array $cfg, bool $sq = false, bool $test = false): string
    {
        $h = chr($type) . chr(($count & 0x7F) | ($sq ? 0x80 : 0));
        $h .= chr(($cause & 0x3F) | ($pn ? 0x40 : 0) | ($test ? 0x80 : 0));
        if ($cfg['cotLen'] === 2) {
            $h .= chr($oa & 0xFF);
        }
        return $h . self::ioa($ca, $cfg['caLen']);
    }

    /**
     * ASDU zerlegen. Gibt null zurueck, wenn er zu kurz ist.
     * @param array{cotLen:int,caLen:int,ioaLen:int} $cfg
     */
    public static function parse(string $asdu, array $cfg): ?array
    {
        $hl = 2 + $cfg['cotLen'] + $cfg['caLen'];
        if (strlen($asdu) < $hl) {
            return null;
        }
        $cot = ord($asdu[2]);
        return [
            'type'  => ord($asdu[0]),
            'count' => ord($asdu[1]) & 0x7F,
            'sq'    => (ord($asdu[1]) & 0x80) !== 0,
            'cause' => $cot & 0x3F,
            'pn'    => ($cot & 0x40) !== 0,
            'test'  => ($cot & 0x80) !== 0,
            'oa'    => $cfg['cotLen'] === 2 ? ord($asdu[3]) : 0,
            'ca'    => self::readLe($asdu, 2 + $cfg['cotLen'], $cfg['caLen']),
            'body'  => substr($asdu, $hl),
        ];
    }

    /** CP56Time2a (7 Byte). $mode 'utc' oder 'local' (mit Sommerzeitbit). */
    public static function cp56(float $ts, string $mode = 'utc', bool $invalid = false): string
    {
        $tz = $mode === 'local' ? new DateTimeZone(date_default_timezone_get()) : new DateTimeZone('UTC');
        $sec = (int) floor($ts);
        $ms = (int) round(($ts - $sec) * 1000);
        if ($ms > 999) {
            $ms = 999;
        }
        $d = (new DateTimeImmutable('@' . $sec))->setTimezone($tz);
        $msec = ((int) $d->format('s')) * 1000 + $ms;
        $su = ($mode === 'local' && (int) $d->format('I') === 1) ? 0x80 : 0;
        return chr($msec & 0xFF) . chr($msec >> 8)
            . chr(((int) $d->format('i')) | ($invalid ? 0x80 : 0))
            . chr(((int) $d->format('G')) | $su)
            . chr(((int) $d->format('j')) | (((int) $d->format('N')) << 5))
            . chr((int) $d->format('n'))
            . chr(((int) $d->format('Y')) % 100);
    }

    /** CP56Time2a lesen. Gibt Unix-Zeit (float) zurueck; $mode wie beim Schreiben. */
    public static function parseCp56(string $b, string $mode = 'utc'): float
    {
        $ms = ord($b[0]) | (ord($b[1]) << 8);
        $min = ord($b[2]) & 0x3F;
        $hour = ord($b[3]) & 0x1F;
        $day = ord($b[4]) & 0x1F;
        $month = ord($b[5]) & 0x0F;
        $year = 2000 + (ord($b[6]) & 0x7F);
        $tz = $mode === 'local' ? new DateTimeZone(date_default_timezone_get()) : new DateTimeZone('UTC');
        $d = new DateTimeImmutable(sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $min, intdiv($ms, 1000)), $tz);
        return $d->getTimestamp() + ($ms % 1000) / 1000;
    }
}
