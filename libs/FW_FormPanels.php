<?php

declare(strict_types=1);

// Formular-Bausteine nach SUITE.md ("Einheitliche Formular-Optik"), gemeinsam fuer IEC101 und IEC104:
//   0 "Wozu dieses Modul?" (einmalig wegklickbar), 1 "Neu in Version" (pro Version wegklickbar),
//   2 "Dokumentation & Hilfe" (eingeklappt, mit Versionsnummer), ... Fachpanels ...,
//   4 Feedback-Hinweis (einmalig wegklickbar), 5 "Ueber dieses Modul" (Lizenz, nicht wegklickbar).
// Ausblenden wird ueber alle Instanzen desselben Moduls geteilt (Uebernahme-Schritt propagiert nie weiter).
//
// Die verwendende Klasse liefert: Konstanten PREFIX und MODULE_GUID sowie die Methoden
// formTexts() und licenseUrl(); ausserdem RegisterAttributeBoolean/-String in Create():
// PurposeIntroGone, ForumHintGone, SeenNews (siehe registerDismissAttributes()).

trait FW_FormPanels
{
    /**
     * @return array{purpose:string[],news:string[],newsVersion:string,doc:string[],feedbackUrl:string}
     */
    abstract protected function formTexts(): array;

    abstract protected function licenseUrl(): string;

    private function registerDismissAttributes(): void
    {
        $this->RegisterAttributeBoolean('PurposeIntroGone', false);
        $this->RegisterAttributeBoolean('ForumHintGone', false);
        $this->RegisterAttributeString('SeenNews', '');
    }

    private function fwLabels(array $texts): array
    {
        return array_map(static fn (string $t): array => ['type' => 'Label', 'caption' => $t], $texts);
    }

    private function purposeIntro(): ?array
    {
        if ((bool) $this->ReadAttributeBoolean('PurposeIntroGone')) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'PurposeIntroPanel', 'expanded' => true,
            'caption' => '👋  Wozu dieses Modul?',
            'items' => array_merge($this->fwLabels($this->formTexts()['purpose']), [
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => self::PREFIX . '_AckPurposeIntro($id);'],
            ]),
        ];
    }

    public function AckPurposeIntro(): void
    {
        $this->WriteAttributeBoolean('PurposeIntroGone', true);
        $this->UpdateFormField('PurposeIntroPanel', 'visible', false);
        $this->propagateDismiss('PurposeIntro');
    }

    private function newsBanner(): ?array
    {
        $t = $this->formTexts();
        if ((string) $this->ReadAttributeString('SeenNews') === $t['newsVersion']) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'expanded' => true,
            'caption' => '🆕  Neu in Version ' . $t['newsVersion'],
            'items' => array_merge($this->fwLabels($t['news']), [
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => self::PREFIX . '_AckNews($id);'],
            ]),
        ];
    }

    public function AckNews(): void
    {
        $v = $this->formTexts()['newsVersion'];
        $this->WriteAttributeString('SeenNews', $v);
        $this->UpdateFormField('NewsPanel', 'visible', false);
        $this->propagateDismiss('News', $v);
    }

    private function docPanel(): array
    {
        $lib = json_decode((string) @file_get_contents(__DIR__ . '/../library.json'), true);
        $version = is_array($lib) ? (string) ($lib['version'] ?? '?') : '?';
        $name = is_array($lib) ? (string) ($lib['name'] ?? '') : '';
        return [
            'type' => 'ExpansionPanel', 'caption' => '📖  Dokumentation & Hilfe', 'expanded' => false,
            'items' => array_merge([['type' => 'Label', 'caption' => 'Version ' . $version . ($name !== '' ? ' (Bibliothek ' . $name . ')' : '')]], $this->fwLabels($this->formTexts()['doc'])),
        ];
    }

    /** Feedback-Hinweis; solange es keinen Forum-Thread gibt (URL leer), wird er nicht gezeigt. */
    private function forumHint(): ?array
    {
        $url = $this->formTexts()['feedbackUrl'];
        if ($url === '' || (bool) $this->ReadAttributeBoolean('ForumHintGone')) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'ForumHintPanel', 'expanded' => true,
            'caption' => '💬  Feedback im Symcon-Forum',
            'items' => [
                ['type' => 'Label', 'caption' => 'Rückmeldungen, Fragen und Fehlermeldungen zu diesem Modul sind ausdrücklich willkommen – am liebsten im Thread im Symcon-Forum.'],
                ['type' => 'Button', 'caption' => 'Zum Forums-Thread', 'onClick' => "echo '" . $url . "';", 'link' => true],
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => self::PREFIX . '_AckForumHint($id);'],
            ],
        ];
    }

    public function AckForumHint(): void
    {
        $this->WriteAttributeBoolean('ForumHintGone', true);
        $this->UpdateFormField('ForumHintPanel', 'visible', false);
        $this->propagateDismiss('ForumHint');
    }

    /** Wortlaut verbundweit identisch (SUITE.md, "Variante A"). */
    private function licenseHint(): array
    {
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '🧡  Über dieses Modul',
            'items' => [
                ['type' => 'Label', 'caption' => 'Entstanden aus echter Begeisterung für die eigene Anlage — und ein paar durchgetippten Abenden. Trotzdem: Software-Hobby hin oder her, das hier ist geistiges Eigentum und echte Arbeit steckt drin.'],
                ['type' => 'Label', 'caption' => 'Lizenz: PolyForm Noncommercial 1.0.0 — privat und nicht-kommerziell frei nutzbar, für den gewerblichen Einsatz braucht es eine gesonderte Lizenz vom Rechteinhaber.'],
                ['type' => 'Button', 'caption' => 'Lizenztext ansehen', 'onClick' => "echo '" . $this->licenseUrl() . "';", 'link' => true],
                ['type' => 'Label', 'caption' => 'Gewerbliche Nutzung oder Fragen zur Lizenz? Einfach melden: dietmar@gureth.eu'],
                ['type' => 'Label', 'caption' => 'Gefällt dir das Modul und du möchtest trotzdem etwas dalassen? Über eine kleine Spende freue ich mich — völlig freiwillig, keine Gegenleistung nötig.'],
                ['type' => 'Button', 'caption' => '☕  Spenden via PayPal', 'onClick' => "echo 'https://paypal.me/DietmarGureth';", 'link' => true],
            ],
        ];
    }

    /** Hilfe-Knopf zu einem Feld: Caption = die volle Frage (SUITE.md), Popup mit Erklaerung. */
    private function helpButton(string $question, array $lines, int $width = 420): array
    {
        return [
            'type' => 'PopupButton', 'caption' => $question, 'width' => $width,
            'popup' => ['caption' => rtrim($question, '?') . '?', 'items' => $this->fwLabels($lines)],
        ];
    }

    /**
     * Reihenfolge: Zweck, Neu, Doku, Statuszeile, Fachpanels, Feedback, Lizenz.
     * @param array $elements Statuszeile und Fachpanels aus form.json
     */
    private function assembleForm(array $elements): array
    {
        $head = array_values(array_filter([$this->purposeIntro(), $this->newsBanner(), $this->docPanel()]));
        $tail = array_values(array_filter([$this->forumHint(), $this->licenseHint()]));
        return array_merge($head, $elements, $tail);
    }

    private function propagateDismiss(string $what, string $value = ''): void
    {
        $fn = self::PREFIX . '_AdoptDismissState';
        foreach (IPS_GetInstanceListByModuleID(self::MODULE_GUID) as $sibling) {
            if ($sibling === $this->InstanceID) {
                continue;
            }
            try {
                $fn($sibling, $what, $value);
            } catch (\Throwable $e) {
                // eine Instanz mitten im Neuladen darf das Ausblenden nicht mitreissen
            }
        }
    }

    /** Reiner Uebernahme-Schritt fuer eine Geschwister-Instanz; reicht selbst nie weiter. */
    public function AdoptDismissState(string $what, string $value): void
    {
        switch ($what) {
            case 'PurposeIntro':
                $this->WriteAttributeBoolean('PurposeIntroGone', true);
                $this->UpdateFormField('PurposeIntroPanel', 'visible', false);
                break;
            case 'ForumHint':
                $this->WriteAttributeBoolean('ForumHintGone', true);
                $this->UpdateFormField('ForumHintPanel', 'visible', false);
                break;
            case 'News':
                $this->WriteAttributeString('SeenNews', $value);
                $this->UpdateFormField('NewsPanel', 'visible', false);
                break;
        }
    }

    /** Ausblende-Stand dieser Instanz, damit neu angelegte Instanzen ihn uebernehmen koennen. */
    public function GetDismissState(): array
    {
        return [
            'purposeIntroGone' => (bool) $this->ReadAttributeBoolean('PurposeIntroGone'),
            'forumHintGone'    => (bool) $this->ReadAttributeBoolean('ForumHintGone'),
            'seenNews'         => (string) $this->ReadAttributeString('SeenNews'),
        ];
    }

    /** Neue Instanz uebernimmt den Stand einer bestehenden; zieht nur vor, ueberschreibt nichts Weiteres. */
    private function adoptDismissFromSibling(): void
    {
        $news = $this->formTexts()['newsVersion'];
        if ((bool) $this->ReadAttributeBoolean('PurposeIntroGone') && (bool) $this->ReadAttributeBoolean('ForumHintGone')
            && (string) $this->ReadAttributeString('SeenNews') === $news) {
            return;
        }
        $fn = self::PREFIX . '_GetDismissState';
        foreach (IPS_GetInstanceListByModuleID(self::MODULE_GUID) as $sibling) {
            if ($sibling === $this->InstanceID) {
                continue;
            }
            try {
                $s = $fn($sibling);
            } catch (\Throwable $e) {
                continue;
            }
            if (!is_array($s)) {
                continue;
            }
            if (!empty($s['purposeIntroGone'])) {
                $this->WriteAttributeBoolean('PurposeIntroGone', true);
            }
            if (!empty($s['forumHintGone'])) {
                $this->WriteAttributeBoolean('ForumHintGone', true);
            }
            if (($s['seenNews'] ?? '') === $news) {
                $this->WriteAttributeString('SeenNews', $news);
            }
            return;
        }
    }
}
