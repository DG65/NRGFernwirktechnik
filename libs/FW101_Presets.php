<?php

declare(strict_types=1);

// Vorlagen der Datenpunktlisten von Netzbetreibern. Quelle: "Kunden-Richtlinie fuer
// Fernwirkanbindungen" der E-Werk Netze GmbH & Co. KG, Version 4.0 (Stand 11.02.2026).
// Das Modul ist nicht auf einen Netzbetreiber festgelegt; weitere Vorlagen sind moeglich.

final class FW101_Presets
{
    public const EWERK_V4 = 'ewerk_v4';

    public static function options(): array
    {
        return [['caption' => 'E-Werk Netze V4.0 (11.02.2026)', 'value' => self::EWERK_V4]];
    }

    /** Schnittstellenparameter der Vorlage. */
    public static function link(string $id): ?array
    {
        if ($id !== self::EWERK_V4) {
            return null;
        }
        return ['LinkAddress' => 1, 'LinkAddressLength' => 1, 'CommonAddress' => 1, 'CommonAddressLength' => 2,
            'CauseLength' => 2, 'IOALength' => 3, 'AckSingleChar' => false];
    }

    /**
     * Datenpunkte der Vorlage im Format der Formulartabelle.
     * Die Reihenfolge folgt der Datenpunktliste; "Req" = in der Liste als Umsetzung vorangekreuzt.
     */
    public static function points(string $id): array
    {
        if ($id !== self::EWERK_V4) {
            return [];
        }
        $rows = [];
        $add = static function (string $name, int $type, string $ioa, bool $req = false, string $mirror = '', string $min = '', string $max = '', string $fail = '') use (&$rows) {
            $rows[] = ['Name' => $name, 'Type' => $type, 'IOA' => $ioa, 'Var' => 0, 'Factor' => 1.0, 'Delta' => 0.0,
                'Min' => $min, 'Max' => $max, 'Mirror' => $mirror, 'Fail' => $fail, 'Req' => $req];
        };

        // Energieanlage 1 und 2
        $add('Vorgabe Wirkleistung 1 [%]', 50, '1.0.98', true, '0.0.98', '0', '100', '100');
        $add('Rückmeldung Vorgabe Wirkleistung 1 [%]', 36, '0.0.98', true);
        $add('Wirkleistungsreduktion Vorgabe extern 1 [%]', 36, '0.9.7');
        $add('Blindleistung Energieanlage 1 [Mvar]', 36, '0.0.8');
        $add('Wirkleistung Energieanlage 1 [MW]', 36, '0.0.9');
        $add('Verfügbare Wirkleistung 1 [MW]', 36, '0.9.6');
        $add('Vorgabe Wirkleistung 2 [%]', 50, '1.0.99', false, '0.0.99', '0', '100', '100');
        $add('Rückmeldung Vorgabe Wirkleistung 2 [%]', 36, '0.0.99');
        $add('Wirkleistungsreduktion Vorgabe extern 2 [%]', 36, '0.9.17');
        $add('Blindleistung Energieanlage 2 [Mvar]', 36, '0.0.18');
        $add('Wirkleistung Energieanlage 2 [MW]', 36, '0.0.19');
        $add('Verfügbare Wirkleistung 2 [MW]', 36, '0.9.16');
        $add('Energiespeicher (Ladezustand) [%]', 36, '0.8.1');

        // Blindleistungsregelung
        $add('Q(U)-Regelung einschalten', 45, '1.0.11', true, '0.0.11');
        $add('Rückmeldung Q(U)-Regelung einschalten', 30, '0.0.11', true);
        $add('Vorgabe Referenzspannung U_Q0/U_c', 50, '1.0.21', true, '0.0.21', '0.8', '1.2');
        $add('Rückmeldung Vorgabe Referenzspannung U_Q0/U_c', 36, '0.0.21', true);
        $add('Blindleistungsregelung mit Spannungsbegrenzung einschalten', 45, '1.0.13', true, '0.0.13');
        $add('Rückmeldung Blindleistungsregelung mit Spannungsbegrenzung', 30, '0.0.13', true);
        $add('Vorgabe Referenzblindleistung Q_ref/P_inst [%]', 50, '1.0.22', true, '0.0.22', '-50', '50');
        $add('Rückmeldung Vorgabe Referenzblindleistung Q_ref/P_inst [%]', 36, '0.0.22', true);
        $add('Cos(Phi)-Regelung einschalten', 45, '1.0.14', false, '0.0.14');
        $add('Rückmeldung Cos(Phi)-Regelung einschalten', 30, '0.0.14');
        $add('Vorgabe Cos(Phi)', 50, '1.0.23', false, '0.0.23', '-1', '1');
        $add('Rückmeldung Vorgabe Cos(Phi)', 36, '0.0.23');
        $add('Q(P)-Regelung einschalten', 45, '1.0.12', false, '0.0.12');
        $add('Rückmeldung Q(P)-Regelung einschalten', 30, '0.0.12');
        $add('Verfügbare Blindleistung untererregt [Mvar]', 36, '0.0.1', true);
        $add('Verfügbare Blindleistung übererregt [Mvar]', 36, '0.0.2', true);
        $add('Q-Untergrenze erreicht', 30, '0.0.3', true);
        $add('Q-Obergrenze erreicht', 30, '0.0.4', true);

        // Netzanschlusspunkt
        foreach (['Strom L1 [A]' => '0.3.1', 'Strom L2 [A]' => '0.3.2', 'Strom L3 [A]' => '0.3.3',
            'Spannung L1-N [kV]' => '0.3.4', 'Spannung L2-N [kV]' => '0.3.5', 'Spannung L3-N [kV]' => '0.3.6',
            'Spannung L3-L1 [kV]' => '0.3.7', 'Blindleistung Übergabefeld [Mvar]' => '0.3.8',
            'Wirkleistung Übergabefeld [MW]' => '0.3.9', 'Cos(Phi) Übergabefeld' => '0.3.10',
            'Frequenz [Hz]' => '0.3.11'] as $n => $a) {
            $add($n, 36, $a, true);
        }

        // Schaltanlage Eingangsfeld 1 / 2 / Übergabefeld
        foreach ([1 => 'Eingangsfeld 1', 2 => 'Eingangsfeld 2', 3 => 'Übergabefeld'] as $f => $fn) {
            $add("Kurzschluss vorwärts $fn", 30, "0.$f.111");
            $add("Kurzschluss rückwärts $fn", 30, "0.$f.112");
            $add("Erdschluss vorwärts $fn", 30, "0.$f.113");
            $add("Erdschluss rückwärts $fn", 30, "0.$f.114");
            $add("Erdortung $fn", 30, "0.$f.115");
            $add("Befehl Leistungsschalter $fn", 46, "1.$f.125");
            $add("Stellungsmeldung Leistungsschalter $fn", 31, "0.$f.125");
            if ($f < 3) {
                $add("Befehl Lasttrennschalter $fn", 46, "1.$f.120");
            }
            $add("Stellungsmeldung Lasttrennschalter $fn", 31, "0.$f.120");
            $add("Stellungsmeldung Erdungstrennschalter $fn", 31, "0.$f.122");
        }

        // Schutzgeräte
        foreach ([1 => 'Eingangsfeld 1', 2 => 'Eingangsfeld 2', 3 => 'Übergabefeld'] as $f => $fn) {
            $add("Schutz gestört $fn", 30, "0.$f.51");
            $add("Schutz Anregung $fn", 30, "0.$f.52");
            $add("Schutz Auslösung $fn", 30, "0.$f.53");
        }
        $add('Blindleistungsauslösung Übergabefeld', 30, '0.3.54');
        $add('Spannungsauslösung Übergabefeld', 30, '0.3.55');
        $add('Frequenzauslösung Übergabefeld', 30, '0.3.56');

        // Wetter, Allgemeines
        $add('Windgeschwindigkeit [m/s]', 36, '0.9.1');
        $add('Windrichtung [°]', 36, '0.9.2');
        $add('Globalstrahlung [W/m²]', 36, '0.9.3');
        $add('Außentemperatur [°C]', 36, '0.9.4');
        $add('Luftdruck [mbar]', 36, '0.9.5');
        $add('Fern/Ort-Umschalter (1 = Ort)', 30, '0.0.46');
        $add('Gasraumüberwachung / SF6-Störung', 30, '0.0.41', true);
        $add('USV-Störung', 30, '0.0.50', true);
        return $rows;
    }
}
