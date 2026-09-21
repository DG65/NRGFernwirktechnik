# NRG-Stack Fernwirktechnik (Repo NRGFernwirktechnik)

Enthält die Module Fernwirk101 und Fernwirk104.

Symcon als kundeneigene Fernwirkstation gegenüber dem Netzbetreiber. Zwei Module in einer Bibliothek, gemeinsame Anwendungsschicht:

| Modul | Protokoll | Rolle | Verbindung |
|---|---|---|---|
| **Fernwirk101** | IEC 60870-5-101, unsymmetrisch | Unterstation (Slave), wird abgefragt | Serial Port (RS-485) |
| **Fernwirk104** | IEC 60870-5-104 | gesteuerte Station (Server) | Server Socket (TCP, Standard-Port 2404) |

**Stand 0.2.0:** Protokoll gegen einen unabhängigen Master (lib60870, 101 über Pseudo-Terminal, 104 im lokalen Netz) und gegen eigene Tests geprüft. **Nicht getestet:** Serial Port und Server Socket im echten IPS, Zeitverhalten dort, eine echte Gegenstelle, Abnahme durch den Netzbetreiber. Ob ein Netzbetreiber Symcon als Kunden-Fernwirkgerät akzeptiert, steht in keiner der vorliegenden Unterlagen – vorher klären (`docs/KLAERUNG.md`).

Hinweis zu 101/104: Beides sind nur Übertragungsarten (101 seriell, 104 über Ethernet/TCP), keine Zuordnung zu einem Zweck. Netzbetreiber setzen je nach Unternehmen 101 (E-Werk Netze) oder 104 (EWE NETZ, EWF) ein; Direktvermarkter nutzen teils ebenfalls 104 oder Modbus TCP.

Wichtig: Das ist die **Fernwirktechnik zum Netzbetreiber** (§ 9 EEG, § 13 EnWG, Redispatch). Die Schnittstelle zum **Direktvermarkter** (§ 10b EEG, z. B. Modbus TCP) ist ein eigener Kanal; Netzbetreiber verlangen die Trennung (E-Werk Netze V4.0, Kapitel 3).

## Was beide Module machen
- Meldungen (30, 31) und Messwerte (36, 13) aus Symcon-Variablen, spontan bei Änderung (Messwerte nach Schwelle, Zwangsaktualisierung einstellbar), mit Zeitmarke CP56Time2a (UTC oder Ortszeit einstellbar).
- Generalabfrage (Ursache „abgefragt“, Abschluss mit ACTTERM), optional mit Typen ohne Zeitmarke (1, 3, 13).
- Befehle (45, 46, mit Zeitmarke 58, 59) und Sollwerte (50, mit Zeitmarke 63) mit positiver oder negativer Quittung (Bereichsprüfung, Ort-Betrieb).
- Rückmeldepunkte für Sollwerte und Befehle: exakt der empfangene Wert wird sofort gemeldet.
- Sollwerte werden als Modulvariable gespeichert (überleben Neustart); optional Ausfallwert nach Ausfall der Zentralstation.
- Flatterunterdrückung (mehr als 0,5 Hz: Ungültig-Kennung, 30 s Stillsetzung), Zwischenstellung 10 s und Störstellung 1 s unterdrückt.
- Prüfbefehle (104, 107) und Uhrzeitsynchronisation (103) werden quittiert (die Symcon-Uhr wird nicht gestellt).

## Fernwirk101 (RS-485)
Unsymmetrisch (FT1.2), Klasse-1-/Klasse-2-Abruf, Wiederholung bei gleichem FCB. Vorlage „E-Werk Netze V4.0“ (91 Datenpunkte, Stand 11.02.2026). Adresslängen, Zeitmarken, Faktor und Punkte einstellbar.
1. Serial Port anlegen: 19200 Baud, 8 Datenbits, gerade Parität, 1 Stoppbit.
2. Instanz „Fernwirk101“ anlegen, den Serial Port als übergeordnete Instanz wählen.
3. „Vorlage laden“, in der Tabelle je Datenpunkt die Variable eintragen, „Datenpunkte prüfen“.

## Fernwirk104 (Ethernet)
Symcon wartet; das Fernwirkgateway des Netzbetreibers baut die Verbindung auf. APCI mit I-/S-/U-Rahmen, STARTDT/STOPDT/TESTFR, Sende-/Empfangsfolgezähler, k/w, t0–t3 einstellbar. Eine aktive Verbindung; eine neue STARTDT übernimmt. Nach einem Verbindungsabbruch gehen nicht quittierte Meldungen verloren, die Generalabfrage holt den Stand.

Vorlagen (nur aus den Unterlagen, Offenes ist gekennzeichnet):
- **EWE NETZ V4.2** (01.01.2026), Anhang A (VDE-AR-N 4110) und B (VDE-AR-N 4105), je Energieart (Wind, PV, Biogas, konventionell/Speicher, KWK), steuerbare Ressource X einstellbar, Schwellen aus Pinst/PAV; Verbindungsparameter aus der Kompatibilitätsliste (Port 2404, Common-Adresse 1, t0 30, t1 15, t2 10, t3 20, k 12, w 8).
- **EWF V1.7** (gültig ab 01.10.2025), Anhang D Tabelle D.1, Anlagennummer einstellbar; t0 30, t1 250, t2 240, t3 255. Port, Common-Adresse, k/w, Schwellen und Zeitzone nennt das Dokument nicht.

Einrichtung: Server Socket anlegen (Port 2404), Instanz „Fernwirk104“ mit dem Server Socket als übergeordnete Instanz, Vorlage laden, Variablen eintragen.

## Was es nicht macht
- Kein symmetrischer Betrieb (101), keine Dateiübertragung, keine Zählwerte, keine Zeitsynchronisation der Symcon-Uhr.
- „AUS mit Netztrennung“ / Sofort-AUS: ein hart verdrahteter Binärkontakt am Gateway, nie über 104 oder Symcon (EWE 4.2, 6.2).
- Entprellung im Millisekundenbereich (10 ms): im Symcon-Kernel nicht möglich.
- Es ersetzt keine Abnahme durch den Netzbetreiber (Inbetriebnahmeprotokoll, Wirk-/Blindleistungstest).
- Sicherheit: EWE 4.2, 7.3 verlangt, dass die kundenseitige Fernwirkhardware während der Verbindung nicht zugleich mit einem WAN/Internet verbunden ist. Ein Symcon-Rechner mit Internetzugang erfüllt das nicht ohne Weiteres (`docs/KLAERUNG.md`).

## Tests (ohne IPS)
```
php tests/run.php            # 101: Protokoll, byte-genau, 109 Prüfungen
php tests/run104.php         # 104: APCI, Timer (simulierte Zeit), Vorlagen
php tests/module_stub.php    # Symcon-Teil 101 gegen einen IPS-Nachbau
php tests/module_stub104.php # Symcon-Teil 104 gegen einen IPS-Nachbau
```
Interoperabilität mit lib60870 (GPL, nur zum Testen, nicht Teil der Module):
```
tests/interop/build.sh <Pfad zum lib60870-Klon>     # auf Apple Silicon in lib60870-C/make/target_system.mk "-arch i386" -> "-arch arm64"
php tests/interop104.php 24041 50 &                 # Unterstation am lokalen Port
tests/interop/cs104_master 127.0.0.1 24041          # lib60870-CS104-Client, CLIENT_T3=5 für die andere TESTFR-Richtung
php tests/interop_slave.php /dev/ttysNNN [Sekunden] # 101: Unterstation an einem Pseudo-Terminal für einen lib60870-101-Master
```

## Installation ohne GitHub
Ordner mit `.git` in das Modulverzeichnis des IPS-Servers kopieren (Windows: `C:\ProgramData\Symcon\modules\`). Prüfplan: `docs/PRUEFPLAN-IPS.md`.

## Offen (Entscheidung bei Dietmar)
Repo `DG65/NRGFernwirktechnik` anlegen und pushen (noch nicht geschehen; die URLs zeigen schon darauf), Forum-Thread (Feedback-Hinweis ist bis dahin ausgeblendet). Lizenz: PolyForm Noncommercial 1.0.0 (`LICENSE`).
