# Changelog

## 0.2.0
- Doku und Hilfe für Laien überarbeitet: „Dokumentation & Hilfe“ beginnt jetzt mit einer Schritt-für-Schritt-Anleitung und einem Glossar der wichtigsten Begriffe (Unterstation/Zentralstation, Meldung/Messwert/Befehl/Sollwert, Adresse); neue Hilfe-Knöpfe zur Adressierung (Linkadresse/Common-Adresse bzw. Port/Common-Adresse), zu t0–t3/k/w und zu Schwelle/Zwangsaktualisierung/Ausfallwert/Ort-Betrieb; die einleitenden Texte der Fachpanels erklären jetzt, dass die Werte vom Netzbetreiber vorgegeben sind, nicht frei wählbar.
- Erster Test im echten IPS: IEC104 gegen lib60870 im LAN, 26 von 27 Prüfungen bestanden (der eine ist erwartetes Verhalten bei kurzer Testdauer), dazu ein Langzeit-Ruhetest (4,5 Minuten, 11 eigene TESTFR-Zyklen des Servers, kein Abbruch). Details `docs/PRUEFPLAN-IPS.md`.
- `tests/interop/cs104_master`: neuer Ruhemodus (`LONGRUN=<Sekunden>`), ein Uebernahme-Test (`TAKEOVER=1`) und ein Lasttest (`LOAD=<Anzahl>`, viele Sollwerte in rascher Folge plus parallele Generalabfragen), alle drei ohne dass am IPS etwas eingestellt werden muss; alle drei gegen die echte IEC104-Instanz gelaufen (200 Sollwerte in 1 s, 7/7 Pruefungen).
- „Datenpunkte prüfen“ (und bei IEC104 „Verbindungen anzeigen“) sind jetzt aufklappbare Panels im Formular statt schmaler `echo(...)`-Dialogfenster (dessen Breite Symcon fest vorgibt, ein Modul kann sie nicht setzen); Bericht ist live, „Aktualisieren“-Knopf baut das Formular neu.
- Fix (beim ersten IPS-Test gefunden): Die 104-Vorlage setzte Eigenschaften, die es im IEC104-Modul nicht gibt (Adress-/Ursachenlängen); die Vorlagenauswahl stand auf dem ersten Eintrag statt auf der gespeicherten Vorlage. Der Stub-Test lehnt unbekannte Eigenschaften jetzt ab.
- Module heißen jetzt **IEC101** und **IEC104** (Funktionspräfixe `IEC101_`/`IEC104_`, Ordner entsprechend); die GUIDs sind unverändert. Mindestversion Symcon 9.0.
- Bibliothek heißt jetzt „NRG-Stack Fernwirktechnik“, Repo `NRGFernwirktechnik`; `LICENSE` (PolyForm Noncommercial 1.0.0) ergänzt.
- **Neu: Modul IEC104** – Symcon als gesteuerte Station (Server) nach IEC 60870-5-104 über den Symcon-Server-Socket. Eigene Verbindungsschicht `libs/FW104_Link.php`: APCI mit I-, S- und U-Rahmen, STARTDT/STOPDT/TESTFR, Sende- und Empfangsfolgezähler, Fenster k und w, Timer t0 bis t3 einstellbar, spontanes Senden, Übernahme durch eine neue Verbindung. Anwendungsschicht (`FW101_Station`) wird mit IEC101 geteilt.
- Vorlagen `libs/FW104_Presets.php` nur aus den Unterlagen: EWE NETZ V4.2 (Anhang A, VDE-AR-N 4110, und Anhang B, VDE-AR-N 4105, je Energieart, steuerbare Ressource X einstellbar, Schwellen aus Pinst/PAV) und EWF V1.7 (Anhang D, Tabelle D.1); Verbindungsparameter aus der EWE-Kompatibilitätsliste. Offenes ist in der Vorlage gekennzeichnet.
- Anwendungsschicht: Generalabfrage optional mit Typen ohne Zeitmarke (1, 3, 13); Prüfbefehle 104/107 werden bestätigt; Rückmeldung eines Doppelbefehls (46) auf eine Doppelmeldung (31) und eines Einzelbefehls (45) auf eine Doppelmeldung wird richtig abgebildet (vorher 0/1 statt 1/2).
- Formulare beider Module nach SUITE.md: „Wozu dieses Modul?“, „Neu in Version“, „Dokumentation & Hilfe“, Hilfe-Knöpfe an erklärungsbedürftigen Feldern, Lizenz-Panel, geteiltes Ausblenden. Der Feedback-Hinweis fehlt, bis es einen Forum-Thread gibt.
- Gemeinsame Bausteine in `libs/` (`FW_IpsHost`, `FW_FormPanels`), damit die Klassen nur einmal geladen werden.
- Dokumentation: `docs/KLAERUNG.md` (Netztrennung/WAN, Zeitmarken, Abnahme), `docs/PRUEFPLAN-IPS.md`.
- Tests: `tests/run104.php` (104-Schicht, Zeit simuliert), `tests/module_stub104.php`, `tests/interop104.php` mit `tests/interop/cs104_master.c` (lib60870-Client, 27 Prüfungen, im lokalen Netz gegen die PHP-Unterstation gelaufen).
- Nicht getestet: echter Serial Port bzw. Server Socket im IPS, Zeitverhalten dort, echte Gegenstelle, Abnahme durch den Netzbetreiber. Der 101-Interop-Test gegen lib60870 wurde nach den Änderungen an der gemeinsamen Anwendungsschicht nicht wiederholt (109 Protokollprüfungen laufen).

## 0.1.0
- Erste Fassung: FT1.2 unsymmetrisch (Slave), ASDU-Typen 30/31/36/13 (Monitor), 45/46/50 (Steuerung), Generalabfrage, Vorlage E-Werk Netze V4.0.
- Geprüft mit eigenem Test-Master (109 Prüfungen) und mit lib60870 (CS101 unbalanced) über ein Pseudo-Terminal; nicht an echter Hardware.
