# Fernwirk101

Symcon als kundeneigene Fernwirkstation (Unterstation, Slave) nach **IEC 60870-5-101**, unsymmetrisch, am Kommunikationsmodul eines Netzbetreibers (RS-485).

Stand 0.1.0: Protokoll gegen einen unabhängigen Master (lib60870) und gegen eigene Tests geprüft. **An einer echten Gegenstelle und am Serial Port im IPS noch nicht getestet.**

## Was es macht
- Antwortet auf die Abfragen der Zentralstation: Verbindungsaufbau, Status, Klasse-1-/Klasse-2-Abruf, Wiederholung bei gleichem FCB.
- Meldungen (30, 31) und Messwerte (36, 13) aus Symcon-Variablen, spontan bei Änderung (Messwerte nach Schwelle, spätestens nach einer Minute), mit Zeitmarke CP56Time2a.
- Generalabfrage (Ursache „abgefragt“, Abschluss mit ACTTERM).
- Befehle (45, 46) und Sollwerte (50, Gleitkomma) mit positiver oder negativer Quittung (Bereichsprüfung, Ort-Betrieb).
- Rückmeldepunkte für Sollwerte und Befehle: exakt der empfangene Wert wird sofort gemeldet.
- Sollwerte werden als Modulvariable gespeichert (überleben Neustart); nach Ausfall des Kommunikationsmoduls (Standard 12 h) fallen sie auf den eingetragenen Ausfallwert.
- Flatterunterdrückung (mehr als 0,5 Hz: Ungültig-Kennung, 30 s Stillsetzung), Zwischenstellung 10 s und Störstellung 1 s unterdrückt.
- Vorlage „E-Werk Netze V4.0“ (91 Datenpunkte laut Datenpunktliste, Stand 11.02.2026). Adresslängen, Zeitmarken, Faktor (z. B. −1 für „Erzeugung negativ“) und Punkte sind einstellbar, damit andere Netzbetreiber möglich sind.

## Was es nicht macht
- Kein symmetrischer Betrieb, keine Dateiübertragung, keine Zeitsynchronisation der Symcon-Uhr (die Uhrzeitsynchronisation wird nur quittiert).
- Entprellung im Millisekundenbereich (10 ms): im Symcon-Kernel nicht möglich.
- Es ersetzt keine Abnahme durch den Netzbetreiber (Inbetriebnahmeprotokoll, Wirk-/Blindleistungstest). Vorher klären, ob Symcon als Fernwirkgerät akzeptiert wird.

## Einrichtung
1. Serial Port (RS-485-Adapter) anlegen: 19200 Baud, 8 Datenbits, gerade Parität, 1 Stoppbit (Vorgabe der Richtlinie).
2. Instanz „Fernwirk101“ anlegen, den Serial Port als übergeordnete Instanz wählen.
3. Unten „Vorlage laden“, dann in der Tabelle je Datenpunkt die Variable eintragen. „Datenpunkte prüfen“ zeigt, was belegt ist.
4. Sollwerte (Typ 50) und Befehle (45, 46): die Ziel-Variable eintragen, in die geschrieben wird.

## Tests (ohne IPS)
```
php tests/run.php            # Protokoll, byte-genau, 109 Prüfungen
php tests/module_stub.php    # Symcon-Teil gegen einen IPS-Nachbau
php tests/interop_slave.php /dev/ttysNNN [Sekunden]   # Unterstation an einem Pseudo-Terminal, für lib60870-Master
```
Installation ohne GitHub: Ordner mit `.git` in das Modulverzeichnis des IPS-Servers kopieren.
