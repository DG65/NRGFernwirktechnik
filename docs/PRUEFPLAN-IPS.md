# Prüfplan im echten IPS (noch nicht durchgeführt)

**Stand 21.09.2026: nichts davon ist gemessen.** Es gab in der Entwicklungssitzung keinen Zugriff auf einen IPS-Server oder eine Gegenstelle. Diese Liste ist für den Test an der echten Anlage. Ergebnisse bitte eintragen.

## Installation (ohne GitHub)
Ordner der Bibliothek **mit `.git`** nach `C:\ProgramData\Symcon\modules\IEC101` kopieren (Windows-IPS), im Modul-Store/„Module“ neu laden. Prüfen: es erscheinen die Module „IEC101“ und „IEC104“, und keine Fehlermeldung „Cannot redeclare class“ (die Host-Klasse `FW_IpsHost` und das Trait `FW_FormPanels` liegen nur in `libs/`).

## IEC101 (RS-485, IEC 101)
| # | Prüfung | Erwartung | Ergebnis |
|---|---|---|---|
| 1 | Instanz anlegen, Serial Port als Parent (19200, 8, gerade Parität, 1) | Instanz aktiv (102) bzw. 104 ohne Punkte; Statuszeile nennt den Serial Port | |
| 2 | Die Reihenfolge der Panels | Wozu / Neu / Doku / Status / Fachpanels / Lizenz | |
| 3 | „Vorlage laden“ | 91 Punkte, Variablen bleiben beim erneuten Laden | |
| 4 | Byte-Werte ≥ 0x80 über den Serial Port (z. B. Prüfsumme, Adresse) | Antworten kommen unverändert an. Der Test-Nachbau nutzt die UTF-8-Hülle; das echte Verhalten des Serial Ports ist damit nicht belegt | |
| 5 | Antwortzeit auf Abrufe der Zentralstation | im Rahmen der Vorgaben des Netzbetreibers (Timeout der Zentralstation erfragen) | |
| 6 | RS-485-Richtungsumschaltung des Adapters | Antwort geht ohne Kollision raus (Adapter mit automatischer Umschaltung) | |
| 7 | Modul-Neustart, Kernel-Neustart | Sollwerte werden aus der Modulvariable wiederhergestellt, Warteschlange leer, Init-Meldung | |
| 8 | Sollwert, Befehl, Generalabfrage mit einer echten oder einer Test-Zentralstation | wie im Protokolltest | |

## IEC104 (Ethernet, IEC 104)
| # | Prüfung | Erwartung | Ergebnis |
|---|---|---|---|
| 1 | Server Socket als Parent (Port 2404) | Statuszeile grün, Port stimmt | |
| 2 | Verbindung mit lib60870-Client (`tests/interop/cs104_master`) im LAN | 27 Prüfungen, 0 Fehler wie im lokalen Interop-Lauf gegen den PHP-Testserver; **im IPS erst zu messen** | |
| 3 | Server Socket liefert `Type` 1/2 (verbunden/getrennt) und `ClientIP`/`ClientPort` wie erwartet | Debug-Fenster der Instanz zeigt RX/TX je Client | |
| 4 | Bytes ≥ 0x80 im Buffer des Server Sockets (APCI-Steuerfeld, Float-Werte) | Antworten byte-genau (Debug-Ausgabe mit Hex vergleichen) | |
| 5 | Zeitverhalten: t1/t2/t3 im 1-s-Takt des Timers | keine Abbrüche im Normalbetrieb, `GetConnectionReport` ohne Fehler | |
| 6 | Zwei Verbindungen (Neuaufbau der Zentralstation) | zweite STARTDT übernimmt, alte bekommt keine Daten | |
| 7 | Bei Last (Generalabfrage 100+ Punkte, viele Änderungen) | keine Semaphore-Timeouts im Debug (Sperre) | |
| 8 | Zustand im Puffer (`state`) wächst nicht unbegrenzt | Größe der Instanz im Rahmen (Warteschlange max. 1000) | |

## Vor der Abnahme beim Netzbetreiber
1. Schriftlich klären: Symcon als Kunden-Fernwirkgerät zulässig? (siehe `docs/KLAERUNG.md`)
2. Zeitmarken UTC oder Ortszeit bestätigen lassen.
3. Trennung zu Direktvermarkter-Schnittstelle und Internetzugang der Hardware klären.
4. Inbetriebnahmeprotokoll (Kunden-Spalte der Datenpunktliste) ausfüllen, Termin mindestens acht Wochen vorher (E-Werk Netze).
