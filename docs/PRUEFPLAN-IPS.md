# Prüfplan im echten IPS (noch nicht durchgeführt)

**Stand 22.09.2026:** IEC104 gegen einen lib60870-Client im LAN erstmals im echten IPS gemessen (siehe Zeile 2 der Tabelle unten). IEC101 (Serial Port) weiterhin ungemessen. Es gab in der Entwicklungssitzung keinen Zugriff auf einen IPS-Server oder eine Gegenstelle. Diese Liste ist für den Test an der echten Anlage. Ergebnisse bitte eintragen.

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
| 1 | Server Socket als Parent (Port 2404); Linux: `ss -ltnp \| grep 2404`, ggf. `sudo ufw allow 2404/tcp` | Statuszeile grün, Port stimmt, vom zweiten Rechner erreichbar | ✅ 22.09.2026, Ubuntu-Server 192.168.2.7, Port erreichbar vom Mac |
| 2 | Verbindung mit lib60870-Client (`tests/interop/cs104_master`) im LAN (Mac gegen den echten Server Socket, EWE-PV-Vorlage, 7 Datenpunkte belegt) | 27 Prüfungen, 0 Fehler | ✅ 22.09.2026, **26 von 27** (der eine „Fehler“ ist erwartet: t3=20 s in der Instanz, Testlauf nur 9 s, daher kein eigenes TESTFR in der Zeit – kein Mangel). STARTDT, Generalabfrage (1 Einzel-, 1 Doppelmeldung, 5 Messwerte, alle Werte inkl. Faktor −1 korrekt), Sollwert mit Rückmeldung und Bereichsprüfung (60 % angenommen, 120 % abgelehnt), Einzelbefehl mit ACTCON/ACTTERM, spontane Messwertänderung mit korrekter Zeitmarke (Minute, Sommerzeitbit), STOPDT/STARTDT und erneute Generalabfrage – alles bestanden |
| 3 | Server Socket liefert `Type` 1/2 (verbunden/getrennt) und `ClientIP`/`ClientPort` wie erwartet | Debug-Fenster der Instanz zeigt RX/TX je Client | (im Test #2 mitbestätigt: Antworten kamen an den richtigen Client zurück, aber Debug-Fenster nicht einzeln kontrolliert) |
| 4 | Bytes ≥ 0x80 im Buffer des Server Sockets (APCI-Steuerfeld, Float-Werte) | Antworten byte-genau | ✅ mit Test #2: Float-Werte (Faktor −1, Zeitmarken) kamen korrekt an, also sind Bytes ≥ 0x80 unverändert durchgelaufen |
| 5 | Zeitverhalten: t1/t2/t3 im 1-s-Takt des Timers | keine Abbrüche im Normalbetrieb | ✅ mit Test #2 im Kurzlauf (9 s); ein Langzeittest (t3-Testrahmen, mehrere Minuten Ruhe) steht noch aus |
| 6 | Zwei Verbindungen (Neuaufbau der Zentralstation) | zweite STARTDT übernimmt, alte bekommt keine Daten | offen |
| 7 | Bei Last (Generalabfrage 100+ Punkte, viele Änderungen) | keine Semaphore-Timeouts im Debug (Sperre) | offen (Test #2 lief mit 7 Punkten) |
| 8 | Zustand im Puffer (`state`) wächst nicht unbegrenzt | Größe der Instanz im Rahmen (Warteschlange max. 1000) | offen |

## Vor der Abnahme beim Netzbetreiber
1. Schriftlich klären: Symcon als Kunden-Fernwirkgerät zulässig? (siehe `docs/KLAERUNG.md`)
2. Zeitmarken UTC oder Ortszeit bestätigen lassen.
3. Trennung zu Direktvermarkter-Schnittstelle und Internetzugang der Hardware klären.
4. Inbetriebnahmeprotokoll (Kunden-Spalte der Datenpunktliste) ausfüllen, Termin mindestens acht Wochen vorher (E-Werk Netze).
