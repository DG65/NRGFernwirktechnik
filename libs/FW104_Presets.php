<?php

declare(strict_types=1);

// Vorlagen der Datenpunktlisten fuer die 104-Anbindung. Es steht nur drin, was die Dokumente hergeben:
//  - EWE NETZ GmbH, "Anforderung zur fernwirktechnischen Anbindung von Erzeugungsanlagen und Speichern
//    mit Pinst >= 100 kW", Version 4.2 (01.01.2026), Anhaenge A (VDE-AR-N 4110), B (VDE-AR-N 4105), C;
//    dazu "IEC 60870-5-104 Kompatibilitaetsliste EWE NETZ Fernwirkgateway" (Stand 27.04.2019).
//  - Energie Waldeck-Frankenberg GmbH (EWF), "Fernwirktechnische Anbindung von Kundenanlagen ...",
//    Version 1.7 (gueltig ab 01.10.2025), Anhang A, Anhang D Tabelle D.1 (Fusszeile des PDF nennt 1.6).
// Was nicht in den Dokumenten steht (z. B. Zeitmarke UTC oder Ortszeit bei Meldungen), wird nicht geraten,
// sondern in der Vorlage als offen gekennzeichnet (siehe docs/KLAERUNG.md).

final class FW104_Presets
{
    public const EWF = 'ewf_v17';

    private const ENERGY = [
        'wea'  => [20, 'Windenergieanlage'],
        'pv'   => [30, 'Photovoltaik'],
        'bga'  => [40, 'Biogas/Biomasse/Klär-/Deponiegas'],
        'konv' => [50, 'Konventionell/Speicher/Sonstige'],
        'kwk'  => [60, 'Kraft-Wärme-Kopplung'],
    ];

    public static function options(): array
    {
        $o = [];
        foreach (['a' => 'Anhang A, VDE-AR-N 4110', 'b' => 'Anhang B, VDE-AR-N 4105'] as $k => $t) {
            foreach (self::ENERGY as $e => [, $name]) {
                $o[] = ['caption' => "EWE NETZ V4.2 – $t – $name", 'value' => "ewe_{$k}_{$e}"];
            }
        }
        $o[] = ['caption' => 'EWF (Energie Waldeck-Frankenberg) V1.7 – Anhang D', 'value' => self::EWF];
        return $o;
    }

    /**
     * Verbindungs- und Verhaltensparameter der Vorlage. Nur was das Dokument vorgibt.
     * Bei EWF fehlen Angaben zur Common-Adresse (Wert) und zu k/w; die bleiben, wie eingestellt.
     * Adress- und Telegrammlaengen sind in der 104 fest (CA 2, COT 2, IOA 3) und keine Eigenschaften.
     */
    public static function link(string $id): ?array
    {
        if (str_starts_with($id, 'ewe_')) {
            return ['Port' => 2404, 'CommonAddress' => 1,
                'T0' => 30, 'T1' => 15, 'T2' => 10, 'T3' => 20, 'K' => 12, 'W' => 8,
                'MaxInterval' => 300, 'FailHours' => 0, 'GiPlain' => true, 'TimeMode' => 'local'];
        }
        if ($id === self::EWF) {
            return [
                'T0' => 30, 'T1' => 250, 'T2' => 240, 'T3' => 255, 'FailHours' => 0, 'GiPlain' => false];
        }
        return null;
    }

    /** Hinweise zur Vorlage: was belegt ist und was offen bleibt. */
    public static function notes(string $id): array
    {
        if (str_starts_with($id, 'ewe_')) {
            return [
                'Belegt (Dokument): Port 2404, Common-Adresse 1 (16 Bit, unstrukturiert), COT 2 Byte, IOA 3 Byte strukturiert, t0 30 s, t1 15 s, t2 10 s, t3 20 s, k 12, w 8, Zwangsaktualisierung der Messwerte nach 5 Minuten, Sollwerte bleiben bei Ausfall erhalten (kein Ausfallwert).',
                'Belegt (Kompatibilitätsliste): Generalabfrage mit Typen ohne Zeitmarke (1, 3, 13); Sollwerte und Befehle mit Zeitmarke (58, 59, 63); die Uhrzeit der Zentralstation ist Ortszeit mit Sommerzeitbit.',
                'Offen: ob die Zeitmarken der Meldungen und Messwerte (Typ 30, 31, 36) Ortszeit oder UTC sein sollen, steht in keinem der beiden Dokumente. Die Vorlage setzt „Ortszeit“ nach der Uhrzeit-Angabe der Zentralstation; bei EWE NETZ bestätigen lassen.',
                'Nicht umgesetzt: „AUS mit Netztrennung“ (Binärkontakt, hart verdrahtet, nie über 104 oder Symcon), Erstanlauf-Grundeinstellung (P 100 %, cos φ 1) – die Ziel-Variablen entsprechend vorbelegen.',
                'Vorzeichen: Verbraucherzählpfeilsystem am NAP, Erzeugung negativ. In der Vorlage ist der Faktor −1 bei Pges und P verfügbar gesetzt (Annahme: die Symcon-Variable zählt Erzeugung positiv); prüfen.',
            ];
        }
        if ($id === self::EWF) {
            return [
                'Belegt (Dokument): t0 30 s, t1 250 s, t2 240 s, t3 255 s, Common-Adresse und IOA wie angegeben (2 Byte, 3 Byte strukturiert), COT 2 Byte, Sollwerte 50, Befehle 45/46, Meldungen 30/31, Messwerte 36; Werte in kW, kvar, A, V (bei Mittelspannung in kV); Vorzeichen P: Bezug positiv, Erzeugung negativ.',
                'Nicht im Dokument: Portnummer (Standard 2404), Wert der Common-Adresse, k und w, Übertragungsschwellen, Zeitmarke UTC oder Ortszeit. Die Anbindung läuft bei EWF über einen VPN-Router (SAE FW5), Adressen vergibt EWF im Projekt.',
                'Anmerkung EWF: Sofort-AUS wird grundsätzlich über physische Schnittstellen am Gateway realisiert; der Befehl 45 in der Tabelle ist kein Ersatz dafür.',
            ];
        }
        return [];
    }

    /**
     * Datenpunkte im Format der Formulartabelle.
     * @param array{index?:int,pinst?:float,pav?:float,emax?:float,grid?:string} $o
     */
    public static function points(string $id, array $o = []): array
    {
        if ($id === self::EWF) {
            return self::ewf(max(1, (int) ($o['index'] ?? 1)));
        }
        if (preg_match('/^ewe_([ab])_(wea|pv|bga|konv|kwk)$/', $id, $m) === 1) {
            return self::ewe($m[1], $m[2], $o);
        }
        return [];
    }

    private static function row(string $name, int $type, string $ioa, array $x = []): array
    {
        return $x + ['Name' => $name, 'Type' => $type, 'IOA' => $ioa, 'Var' => 0, 'Factor' => 1.0, 'Delta' => 0.0,
            'Min' => '', 'Max' => '', 'Mirror' => '', 'Fail' => '', 'Req' => false];
    }

    // ---------------------------------------------------------------- EWE NETZ

    private static function ewe(string $annex, string $energy, array $o): array
    {
        $x = max(0, (int) ($o['index'] ?? 0));
        $pinst = (float) ($o['pinst'] ?? 0);
        $pav = (float) ($o['pav'] ?? 0);
        $emax = (float) ($o['emax'] ?? 0);
        $volt = ($o['grid'] ?? 'ms') === 'ns' ? 0.002 : 0.05; // 50 V (MS) bzw. 2 V (NS), in kV
        $dP = $pinst > 0 ? round(0.01 * $pinst, 6) : 0.0;
        $dNap = $pav > 0 ? round(0.01 * $pav, 6) : 0.0;
        $dE = $emax > 0 ? round(0.01 * $emax, 6) : 0.0;
        [$h] = self::ENERGY[$energy];
        $a = static fn (int $low): string => "$h.$x.$low";
        $r = [];
        $add = static function (string $n, int $t, string $ioa, array $extra = []) use (&$r) {
            $r[] = self::row($n, $t, $ioa, $extra);
        };

        // Netzanschlusspunkt (Adressen 10.0.x, unabhängig von der Ressource)
        if ($annex === 'a') {
            foreach (['U12' => 7, 'U23' => 8, 'U31' => 9] as $n => $l) {
                $add("$n [kV]", 36, "10.0.$l", ['Delta' => $volt]);
            }
            foreach (['P1' => 10, 'P2' => 11, 'P3' => 12] as $n => $l) {
                $add("$n Netzanschlusspunkt [MW]", 36, "10.0.$l", ['Delta' => $dNap]);
            }
        }
        $add('Pgesamt Netzanschlusspunkt [MW] (Verbraucherzählpfeil)', 36, '10.0.13', ['Delta' => $dNap]);
        if ($annex === 'a') {
            foreach (['Q1' => 14, 'Q2' => 15, 'Q3' => 16] as $n => $l) {
                $add("$n Netzanschlusspunkt [Mvar]", 36, "10.0.$l", ['Delta' => $dNap]);
            }
        }
        $add('Qgesamt Netzanschlusspunkt [Mvar]', 36, '10.0.17', ['Delta' => $dNap]);

        // Steuerbare Ressource
        $add('P_Soll Erzeugung [% von Pinst]', 50, $a(1), ['Mirror' => $a(4), 'Min' => '0', 'Max' => '100', 'Req' => true]);
        $add('Rückmeldung P_Soll Erzeugung [%]', 36, $a(4), ['Req' => true]);
        if ($annex === 'a') {
            $add('Q_Soll [Mvar] (nur UW-Direktanschluss)', 50, $a(2), ['Mirror' => $a(5), 'Min' => '-100', 'Max' => '100']);
            $add('cosPhi_Soll (nur MS-Anschluss; negativ = übererregt)', 50, $a(3), ['Mirror' => $a(6), 'Min' => '-1', 'Max' => '1']);
            $add('Rückmeldung Q_Soll [Mvar]', 36, $a(5));
            $add('Rückmeldung cosPhi_Soll', 36, $a(6));
            if (in_array($energy, ['wea', 'pv'], true)) {
                $add('P verfügbar [MW] (nur negativ; Erzeugung negativ)', 36, $a(7), ['Delta' => $dP, 'Factor' => -1.0]);
            }
            $add('Q verfügbar untererregt [Mvar] (nur positiv)', 36, $a(8), ['Delta' => $dP]);
            $add('Q verfügbar übererregt [Mvar] (nur negativ)', 36, $a(9), ['Delta' => $dP, 'Factor' => -1.0]);
        }
        $add("Pges " . strtoupper($energy) . ' [MW] (Erzeugung negativ)', 36, $a(12), ['Delta' => $dP, 'Factor' => -1.0, 'Req' => true]);
        if ($annex === 'a') {
            $add("Qges " . strtoupper($energy) . ' [Mvar]', 36, $a(13), ['Delta' => $dP]);
        }
        if (in_array($energy, ['wea', 'pv'], true)) {
            $add('Veränderung der Fahrweise (Wirkleistung) [MW]', 36, $a(15), ['Delta' => $dP]);
        }
        $add('aktuell nutzbarer Energieinhalt [MWh] (nur Speicher)', 36, $a(16), ['Delta' => $dE]);
        if ($energy === 'konv') {
            $add('P_Soll Verbrauch [%] (nur Graustromspeicher)', 50, $a(21), ['Mirror' => $a(24), 'Min' => '0', 'Max' => '100']);
            $add('Rückmeldung P_Soll Verbrauch [%]', 36, $a(24));
        }
        return $r;
    }

    // ---------------------------------------------------------------------- EWF

    private static function ewf(int $plant): array
    {
        $r = [];
        $add = static function (string $n, int $t, string $ioa, array $extra = []) use (&$r) {
            $r[] = self::row($n, $t, $ioa, $extra);
        };

        // Kundenstation (Tabelle D.1, Netzsteuerung Station und Übergabefeld)
        $add('Fernsteuerung AUS', 30, '0.0.1');
        $add('SF6-Verlust', 30, '0.0.2');
        $add('Störung Kundenanlage', 30, '0.0.3');
        $add('DC gestört', 30, '0.0.4');
        $add('Ausfall Wandlerspannung', 30, '0.0.5');
        $add('Leistungsschalterfall Übergabeschaltfeld', 31, '0.0.6');
        $add('HH-Sicherungsauslösung', 30, '0.0.7');
        $add('Schutz Generalanregung', 30, '0.0.8');
        $add('Schutz Auslösung', 30, '0.0.9');
        $add('Schutz Störung', 30, '0.0.10');
        $add('Q/U-Schutzauslösung', 30, '0.0.11');
        $add('Entkupplungsschutz Auslösung', 30, '0.0.12');
        $add('Erdschluss vorwärts (Richtung Kundenanlage)', 30, '0.0.13');
        $add('Erdschluss rückwärts (Richtung EWF-Netz)', 30, '0.0.14');
        $add('Kurzschluss vorwärts (Richtung Kundenanlage)', 30, '0.0.15');
        $add('Kurzschluss rückwärts (Richtung EWF-Netz)', 30, '0.0.16');
        foreach (['Leiterstrom IL1 [A]' => 17, 'Leiterstrom IL2 [A]' => 18, 'Leiterstrom IL3 [A]' => 19,
            'Leiter-Erde-Spannung UL1-N [V]' => 20, 'Leiter-Erde-Spannung UL2-N [V]' => 21,
            'Leiter-Erde-Spannung UL3-N [V]' => 22, 'Leiter-Leiter-Spannung UL3-L1 [V]' => 23] as $n => $l) {
            $add($n, 36, "0.0.$l");
        }
        $add('Wirkleistung P [kW] (Bezug positiv, Erzeugung negativ)', 36, '0.0.24');
        $add('Blindleistung Q [kvar] (untererregt positiv)', 36, '0.0.25');
        $add('Verschiebungsfaktor cos φ', 36, '0.0.26');

        // Eingangsfelder (Tabelle D.1)
        foreach ([1, 2] as $f) {
            $add("Lasttrennschalter Feld #$f Befehl", 46, "$f.0.1");
            $add("Lasttrennschalter Feld #$f Rückmeldung", 31, "$f.0.2");
            $add("Erdschluss Feld #$f vorwärts", 30, "$f.0.3");
            $add("Erdschluss Feld #$f rückwärts", 30, "$f.0.4");
            $add("Kurzschluss Feld #$f vorwärts", 30, "$f.0.5");
            $add("Kurzschluss Feld #$f rückwärts", 30, "$f.0.6");
            $add("Erdungsschalter Feld #$f Rückmeldung", 31, "$f.0.7");
        }

        // P/Q-Steuerung und Messwerte der Anlage (H-Byte = Anlagennummer)
        $h = $plant;
        $a = static fn (int $l): string => "$h.0.$l";
        $add("EZA #$h P/Pinst Erzeugung Vorgabe [%]", 50, $a(11), ['Mirror' => $a(12), 'Min' => '0', 'Max' => '100', 'Req' => true]);
        $add("EZA #$h P/Pinst Erzeugung Rückmeldung [%]", 36, $a(12), ['Req' => true]);
        $add("EZA #$h cos φ Erzeugung Vorgabe (>0 untererregt, <0 übererregt)", 50, $a(13), ['Mirror' => $a(14), 'Min' => '-1', 'Max' => '1']);
        $add("EZA #$h cos φ Erzeugung Rückmeldung", 36, $a(14));
        $add("EZA #$h P/Pinst Bezug Vorgabe [%]", 50, $a(15), ['Mirror' => $a(16), 'Min' => '0', 'Max' => '100']);
        $add("EZA #$h P/Pinst Bezug Rückmeldung [%]", 36, $a(16));
        $add("EZA #$h cos φ Bezug Vorgabe", 50, $a(17), ['Mirror' => $a(18), 'Min' => '-1', 'Max' => '1']);
        $add("EZA #$h cos φ Bezug Rückmeldung", 36, $a(18));
        $add("EZA #$h Wirkleistungsreduzierung P/Pinst extern [%] (Vorgabe des Direktvermarkters)", 36, $a(19));
        $add("EZA #$h manuelle Q-Regelung aktivieren", 46, $a(20), ['Mirror' => $a(21)]);
        $add("EZA #$h manuelle Q-Regelung aktiviert", 31, $a(21));
        $add("EZA #$h Q(U)-Regelung aktivieren", 46, $a(22), ['Mirror' => $a(23)]);
        $add("EZA #$h Q(U)-Regelung aktiviert", 31, $a(23));
        $add("EZA #$h Q(U) Spannungssollwert Vorgabe [V]", 50, $a(24), ['Mirror' => $a(25), 'Min' => '0', 'Max' => '15000']);
        $add("EZA #$h Q(U) Spannungssollwert Rückmeldung [V]", 36, $a(25));
        $add("EZA #$h Q-Bereitstellung Qref/Pbinst aktivieren", 46, $a(26), ['Mirror' => $a(27)]);
        $add("EZA #$h Q-Bereitstellung Qref/Pbinst aktiviert", 31, $a(27));
        $add("EZA #$h Q-Bereitstellung Qref/Pbinst Sollwert Vorgabe [%]", 50, $a(28), ['Mirror' => $a(29), 'Min' => '0', 'Max' => '100']);
        $add("EZA #$h Q-Bereitstellung Qref/Pbinst Sollwert Rückmeldung [%]", 36, $a(29));
        $add("EZA #$h Sofort-AUS Befehl (Schließer, physisch am Gateway)", 45, $a(30));
        $add("EZA #$h Kuppelschalter Aus", 30, $a(31));
        foreach ([41 => 'Leiterstrom IL1 [A]', 42 => 'Leiterstrom IL2 [A]', 43 => 'Leiterstrom IL3 [A]',
            44 => 'Leiter-Erde-Spannung UL1-N [V]', 45 => 'Leiter-Erde-Spannung UL2-N [V]',
            46 => 'Leiter-Erde-Spannung UL3-N [V]', 47 => 'Leiter-Leiter-Spannung UL3-L1 [V]',
            48 => 'Wirkleistung P [kW] (Bezug positiv, Erzeugung negativ)', 49 => 'Blindleistung Q [kvar] (untererregt positiv)',
            50 => 'Verschiebungsfaktor cos φ', 51 => 'P verfügbar [kW]', 52 => 'Q untererregt verfügbar [kvar]',
            53 => 'Q übererregt verfügbar [kvar]', 54 => 'Verfügbarkeit der Anlage [%]', 55 => 'Windrichtung [°] (nur Wind)',
            56 => 'Windgeschwindigkeit [m/s] (nur Wind)', 57 => 'Globalstrahlung [W/m²] (nur PV)',
            58 => 'Ladezustand E/Einst [%] (nur Speicher)'] as $l => $n) {
            $add("EZA #$h $n", 36, $a($l));
        }
        return $r;
    }
}
