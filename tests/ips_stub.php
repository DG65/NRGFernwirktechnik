<?php

declare(strict_types=1);

// Minimaler IPS-Nachbau fuer die Modul-Stub-Tests (IEC101 und IEC104).
// Kein Ersatz fuer einen Test im echten IPS.

date_default_timezone_set('Europe/Berlin');
const KR_READY = 10103;
const IPS_KERNELMESSAGE = 10100;
const VM_UPDATE = 10603;
const KL_ERROR = 4;
const KL_NOTIFY = 1;
const IS_ACTIVE = 102;

$GLOBALS['ips'] = ['vars' => [], 'ident' => [], 'props' => [], 'buf' => [], 'sent' => [], 'log' => [], 'next' => 5000, 'actions' => []];

function IPS_VariableExists(int $id): bool { return isset($GLOBALS['ips']['vars'][$id]); }
function GetValue(int $id): mixed { return $GLOBALS['ips']['vars'][$id]['v']; }
function SetValueInteger(int $id, int $v): void { $GLOBALS['ips']['vars'][$id]['v'] = $v; $GLOBALS['ips']['vars'][$id]['u'] = time(); }
function SetValueFloat(int $id, float $v): void { $GLOBALS['ips']['vars'][$id]['v'] = $v; $GLOBALS['ips']['vars'][$id]['u'] = time(); }
function RequestAction(int $id, mixed $v): void { $GLOBALS['ips']['actions'][] = [$id, $v]; $GLOBALS['ips']['vars'][$id]['v'] = $v; }
function IPS_GetVariable(int $id): array { return ['VariableType' => $GLOBALS['ips']['vars'][$id]['t'], 'VariableUpdated' => $GLOBALS['ips']['vars'][$id]['u'] ?? 0]; }
function IPS_GetKernelRunlevel(): int { return KR_READY; }
function IPS_SemaphoreEnter(string $n, int $t): bool { return true; }
function IPS_SemaphoreLeave(string $n): bool { return true; }
function IPS_GetInstance(int $id): array { return ['ConnectionID' => $GLOBALS['ips']['parent'] ?? 7000, 'InstanceStatus' => 102]; }
function IPS_GetProperty(int $id, string $n): mixed { return ['Port' => 2404, 'Open' => true][$n] ?? null; }
function IPS_GetInstanceListByModuleID(string $g): array { return $GLOBALS['ips']['siblings'][$g] ?? [$GLOBALS['ips']['self'] ?? 1234]; }
function IEC101_GetDismissState(int $id): array { return ['purposeIntroGone' => false, 'forumHintGone' => false, 'seenNews' => '']; }
function IEC104_GetDismissState(int $id): array { return ['purposeIntroGone' => false, 'forumHintGone' => false, 'seenNews' => '']; }
function IPS_GetName(int $id): string { return 'Serial Port'; }
function IPS_GetConfiguration(int $id): string { return json_encode(['BaudRate' => '19200', 'DataBits' => '8', 'Parity' => 'Even', 'StopBits' => '1']); }
function IPS_SetProperty(int $id, string $n, mixed $v): void { $GLOBALS['ips']['props'][$n] = $v; }
function IPS_ApplyChanges(int $id): void { $GLOBALS['ips']['module']->ApplyChanges(); }

class IPSModule
{
    public int $InstanceID = 1234;
    private array $regProps = [];
    public function Create() {}
    public function ApplyChanges() {}
    private function reg($n, $d) { $this->regProps[$n] = $d; }
    protected function RegisterPropertyString($n, $d) { $this->reg($n, $d); }
    protected function RegisterPropertyInteger($n, $d) { $this->reg($n, $d); }
    protected function RegisterPropertyBoolean($n, $d) { $this->reg($n, $d); }
    protected function RegisterPropertyFloat($n, $d) { $this->reg($n, $d); }
    private function prop($n) { return $GLOBALS['ips']['props'][$n] ?? $this->regProps[$n]; }
    protected function ReadPropertyString($n) { return (string) $this->prop($n); }
    protected function ReadPropertyInteger($n) { return (int) $this->prop($n); }
    protected function ReadPropertyBoolean($n) { return (bool) $this->prop($n); }
    protected function ReadPropertyFloat($n) { return (float) $this->prop($n); }
    protected function RegisterTimer($n, $i, $s) {}
    protected function SetTimerInterval($n, $i) {}
    protected function RegisterMessage($s, $m) { $GLOBALS['ips']['msgs'][$s][] = $m; }
    protected function UnregisterMessage($s, $m) {}
    protected function GetMessageList() { return $GLOBALS['ips']['msgs'] ?? []; }
    protected function SetStatus($s) { $GLOBALS['ips']['status'] = $s; }
    protected function SetBuffer($n, $v) { $GLOBALS['ips']['buf'][$n] = $v; }
    protected function GetBuffer($n) { return $GLOBALS['ips']['buf'][$n] ?? ''; }
    protected function SendDebug($a, $b, $c) {}
    private array $attrs = [];
    protected function RegisterAttributeBoolean($n, $d) { $this->attrs[$n] ??= $d; }
    protected function RegisterAttributeString($n, $d) { $this->attrs[$n] ??= $d; }
    protected function ReadAttributeBoolean($n) { return (bool) $this->attrs[$n]; }
    protected function ReadAttributeString($n) { return (string) $this->attrs[$n]; }
    protected function WriteAttributeBoolean($n, $v) { $this->attrs[$n] = $v; }
    protected function WriteAttributeString($n, $v) { $this->attrs[$n] = $v; }
    protected function UpdateFormField($n, $p, $v) { $GLOBALS['ips']['formUpdates'][] = [$n, $p, $v]; }
    protected function GetValue($i) { return $GLOBALS['ips']['vars'][$GLOBALS['ips']['ident'][$i]]['v']; }
    protected function LogMessage($m, $k) { $GLOBALS['ips']['log'][] = $m; }
    protected function ReloadForm() {}
    protected function SendDataToParent($j) { $GLOBALS['ips']['sent'][] = $j; }
    private function regVar($i, $t, $init) {
        if (!isset($GLOBALS['ips']['ident'][$i])) {
            $id = $GLOBALS['ips']['next']++;
            $GLOBALS['ips']['ident'][$i] = $id;
            $GLOBALS['ips']['vars'][$id] = ['v' => $init, 't' => $t];
        }
    }
    protected function RegisterVariableInteger($i, $n, $p = '', $pos = 0) { $this->regVar($i, 1, 0); }
    protected function RegisterVariableBoolean($i, $n, $p = '', $pos = 0) { $this->regVar($i, 0, false); }
    protected function RegisterVariableFloat($i, $n, $p = '', $pos = 0) { $this->regVar($i, 2, 0.0); }
    protected function GetIDForIdent($i) { return $GLOBALS['ips']['ident'][$i] ?? false; }
    protected function SetValue($i, $v) { $id = $GLOBALS['ips']['ident'][$i]; $GLOBALS['ips']['vars'][$id]['v'] = $v; $GLOBALS['ips']['vars'][$id]['u'] = time(); }
}

