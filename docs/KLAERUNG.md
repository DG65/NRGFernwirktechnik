# Klärungsstand: Netztrennung, Zeitmarken, Abnahme

Stand 21.09.2026. Belegt ist nur, was in den vorliegenden Dokumenten steht (Quelle jeweils genannt). Alles andere ist als **offen** gekennzeichnet und ist mit dem Netzbetreiber zu klären. Nichts hiervon ersetzt eine schriftliche Auskunft des jeweiligen Netzbetreibers.

Quellen:
- **EWK 4.0** = E-Werk Netze GmbH & Co. KG, „Fernwirktechnische Anbindung von Erzeugungsanlagen“, Version 4.0, Stand 11.02.2026 (IEC 101, RS-485).
- **EWE 4.2** = EWE NETZ GmbH, „Anforderung zur fernwirktechnischen Anbindung von Erzeugungsanlagen und Speichern mit Pinst ≥ 100 kW“, Version 4.2, 01.01.2026 (IEC 104).
- **EWE KL** = EWE NETZ, „IEC 60870-5-104 Kompatibilitätsliste Fernwirkgateway“, Stand 27.04.2019.
- **EWF 1.7** = Energie Waldeck-Frankenberg GmbH, „Fernwirktechnische Anbindung von Kundenanlagen …“, Version 1.7, gültig ab 01.10.2025 (die Fußzeile des PDF nennt noch „Version 1.6“).

## 1. „Kundenseitige Fernwirkhardware nicht zugleich mit WAN/Internet verbunden“ (EWE 4.2, Kapitel 7.3)

**Wortlaut sinngemäß (EWE 4.2, 7.3):** Für den Zeitraum der Verbindung mit dem Fernwirkgateway verpflichtet sich der Anschlussnehmer und alle von ihm beauftragten Personen, nicht gleichzeitig **über die gleiche Hardware** mit einem anderen Weitverkehrsnetz (WAN), zum Beispiel Internet, verbunden zu sein. Außerdem: Vorgaben des Netzbetreibers zur Fernwirkschnittstelle sind jederzeit einzuhalten; Mitschneiden und Speichern von Daten des Fernwirknetzes (Datensniffer) ist zu unterlassen. Kapitel 7.2 verlangt Geheimhaltung der Adressdaten; 7.4: bei Zuwiderhandlung darf der Netzbetreiber die zur Verfügung gestellte Hardware sofort entziehen.

**Was das für Symcon bedeutet (Auslegung, nicht bestätigt):**
- Läuft Fernwirk104 auf einem Symcon-Rechner, der ebenfalls Internet hat (Symcon Connect, Updates, Fernzugriff, Cloud-Module), ist es „die gleiche Hardware“ mit WAN-Verbindung. Der Wortlaut spricht dagegen.
- Denkbar wäre ein Symcon-Rechner, der **nur** mit dem Fernwirkgateway verbunden ist (eigene Netzwerkschnittstelle, nicht geroutet, keine Bridge, kein Internet). Das trennt allerdings Symcon vom Rest der Anlagensteuerung, wenn diese über das Netz läuft. Ob die Kopplung zweier Netzwerkkarten am selben Rechner als „nicht zugleich verbunden“ gilt, entscheidet EWE NETZ, nicht das Modul.
- Praktischer Weg: ein **eigenes, kleines Symcon-System** (oder ein Gerät mit Symcon) nur für die Fernwirkstrecke, das über eine getrennte Schnittstelle Werte mit der Hauptanlage austauscht. Auch das ist mit EWE NETZ zu klären.
- Das Modul erzwingt nichts davon; es kann die Netzwerklage nicht prüfen. Der Formulartext und die Dokumentation weisen darauf hin.

**Bei EWK 4.0 (101, RS-485)** steht diese WAN-Klausel im vorliegenden Dokument nicht. Dort steht in Kapitel 3 „Netzsicherheitsmanagement“: die Steuerungs- und Übertragungstechnik darf **nur zur Anbindung des Netzbetreibers und nicht als Zugriffspunkt für Dritte, z. B. Direktvermarkter**, zur Verfügung stehen. Und Kapitel 2: das Kunden-Fernwirkgerät ist nach Inbetriebnahme Teil der kritischen Infrastruktur, sollte in einem zutrittsbeschränkten Bereich stehen, der Kunde ist für Datenschutz und Wartung selbst verantwortlich und soll Patches und Updates einspielen.

**Folgerung für die Direktvermarktung (wichtig, offen):** Läuft auf demselben Symcon-System bereits der Modbus-TCP-Server als Zugang für den Direktvermarkter (blue'Log-Emulation), dann ist genau diese Technik ein Zugriffspunkt für Dritte. Nach EWK 4.0 Kapitel 3 dürfte die Fernwirktechnik zum Netzbetreiber dann **nicht** auf demselben System laufen, oder zumindest ist das ausdrücklich mit dem Netzbetreiber zu klären. Das ist eine Einschätzung aus dem Wortlaut, keine Auskunft des Netzbetreibers.

**Offen (Frage an den Netzbetreiber):** Darf die Fernwirkstrecke auf einem Symcon-System laufen, das eine zweite, getrennte Netzwerkschnittstelle mit Internet hat? Darf dasselbe System die Direktvermarkter-Schnittstelle bedienen?

## 2. Zeitmarken: UTC oder Ortszeit?

**Belegt:**
- EWE KL (Uhrzeitsynchronisation): Bit SU (Sommerzeit) wird benutzt; die von der Zentralstation gesendete Uhrzeit **entspricht der Lokalzeit (örtliche Uhrzeit)**. Das gilt für den Uhrzeitsynchronisationsbefehl (Typ 103), nicht ausdrücklich für die Zeitmarken der Meldungen.
- EWF 1.7: Zeitsynchronisation bevorzugt per NTP, optional per 104-Zeittelegramm. Zur Zeitzone der Zeitmarken steht nichts im Dokument.
- EWK 4.0 und EWE 4.2: zu Zeitmarken steht in den Dokumenten nichts zu UTC oder Ortszeit (Volltextsuche nach UTC, Ortszeit, Lokalzeit, Sommerzeit, Zeitmarke, Uhrzeit: keine Treffer in EWK 4.0; in EWE 4.2 keine Treffer).
- Zeitmarken (CP56Time2a) mit Meldungen und Messwerten sind vorgeschrieben (Typen 30, 31, 36).

**Umsetzung im Modul:** einstellbar („UTC“ oder „Ortszeit mit Sommerzeitbit“). Vorgabe im 101-Modul: UTC. Die EWE-Vorlage im 104-Modul stellt „Ortszeit“ ein, **als Annahme** aus der Lokalzeit der Zentralstation, nicht als Angabe von EWE NETZ.

**Offen:** von jedem Netzbetreiber schriftlich bestätigen lassen. Praktischer Test bei der Inbetriebnahme: eine Meldung auslösen und beim Netzbetreiber die angezeigte Zeit ansehen; ein Versatz von einer oder zwei Stunden verrät die falsche Einstellung. Auf den ausgelieferten Rechner achten: „Ortszeit“ nimmt die PHP-Zeitzone des IPS.

## 3. Abnahme durch den Netzbetreiber

**Belegt:**
- EWK 4.0 (101): Vor der Inbetriebnahme des Kommunikationsmoduls muss ein **Inbetriebnahmeprotokoll der Kunden-Fernwirktechnik** vorliegen; die Spalte „Kunde“ der Datenpunktliste dient als Prüfprotokoll des Kunden, die Spalte „VNB“ als Prüfprotokoll des Netzbetreibers. Der Netzbetreiber trägt IBN-Datum und die Ergebnisse von **Datenpunktprüfung, Wirkleistungstest und Blindleistungstest** ein. Der Inbetriebnahmetermin des Kommunikationsmoduls ist **mindestens acht Wochen im Voraus** abzustimmen; der Kunde muss vor Ort sein. Die Inbetriebnahme des Kommunikationsmoduls (als Erklärung zum Netzsicherheitsmanagement) ist Voraussetzung für die Inbetriebnahme der Übergabestation.
- EWE 4.2 (104), Kapitel 5: Inbetriebnahme der Geräte des Netzbetreibers durch den Netzbetreiber; Termin im Voraus abstimmen. Geprüft werden (1) der Befehl „AUS mit Netztrennung“ bei angeschaltetem Schaltgerät und (2) alle Meldungen und Messwerte nach Prozessdatenpunktliste sowie Schaltbefehle und Sollwerte, jeweils als Quelle-Senke-Test über die gesamte Wirkungskette. Voraussetzung: Fachkräfte mit Kenntnis der Fernwirkanbindung vor Ort, alle Anlagen betriebsbereit, Kabel und Signalleitung am Einbauort. Verhält sich die 104-Schnittstelle nicht wie beschrieben, oder fehlen Voraussetzungen, kann die Inbetriebnahme abgebrochen werden; eine Stunde Nachbesserung wird eingeräumt, danach neuer Termin, Mehrkosten trägt der Anschlussnehmer.
- EWF 1.7: Zugelassene Technik: die VPN-Router-/Fernwirkgeräte SAE FW-5-GATE (4G und DSL); das VPN-Tunnelende wird von EWF im Router eingerichtet und liegt in einem abschließbaren Gehäuse mit exklusivem Zugriff der EWF. Bei Anlagen über 1.000 kW, Bezugs-, Lade- und Mischanlagen erfolgt die Prozessanbindung über bauseitige Fernwirkgeräte oder Protokollumsetzer, die per IEC 104 an den VPN-Router angeschlossen werden (dafür kommt Fernwirk104 in Frage).

**Nicht belegt / offen:**
- Ob ein Netzbetreiber Symcon (oder überhaupt PC-basierte Software) als „Fernwirkgerät“ des Kunden akzeptiert, steht in keinem der Dokumente. EWF nennt eine Liste zugelassener Technik für den VPN-Router, für das dahinterliegende Kundengerät nicht. **Vor dem Einsatz beim Netzbetreiber schriftlich klären.**
- Ob für Software eine Konformitäts- oder Typprüfung verlangt wird: nicht in den Dokumenten.
- Diese Bibliothek ersetzt keine Abnahme. Sie ist mit einem unabhängigen Master (lib60870) im lokalen Netz bzw. über ein Pseudo-Terminal getestet, nicht mit einer echten Gegenstelle.

## 4. Netztrennung / „AUS mit Netztrennung“ / Sofort-AUS

- EWE 4.2 (6.2): Der Befehl wird **nicht** über die 104-Schnittstelle ausgetauscht, sondern als **Binärkontakt** (Schließer, 24 V DC ±10 %, max. 0,5 A, Dauerausgabe) am Gateway. Notwendig bei Anlagen nach VDE-AR-N 4110 mit NAP im MS-/NS-Netz und gesteuerter Erzeugung Pinst über 2.000 kW. Anmerkung 7: der Ausgang ist als **direkte Wirkverbindung** zur Schalteinrichtung auszuführen und darf **nicht über SPS oder sonstige Automatisierungseinrichtungen** geführt werden (Ausnahme: das Schutzgerät des Entkupplungsschutzes). Symcon darf ihn also nicht vermitteln.
- EWF 1.7: Der Sofort-AUS-Befehl wird grundsätzlich über physische Schnittstellen am Gateway realisiert und wirkt auf die Schalter des Entkupplungsschutzes. In der Punkteliste steht zusätzlich ein Befehl 45 (Sofort-AUS) und eine Meldung „Kuppelschalter Aus“; die Vorlage führt beides, aber das Modul schaltet nichts hart. Wer den Sofort-AUS über die Punkteliste verwendet, sollte das mit EWF vorher abstimmen.
- Das Modul setzt „AUS mit Netztrennung“ nicht um und bietet dafür keinen Datenpunkt an.
