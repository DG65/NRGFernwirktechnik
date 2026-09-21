<?php

declare(strict_types=1);

require_once __DIR__ . '/FW101_Station.php';

// Anbindung der Anwendungsschicht an Symcon (Variablen lesen, Aktionen ausfuehren, Werte speichern).
// Gemeinsam fuer IEC101 und IEC104; hier und nicht in module.php, damit die Klasse nur einmal
// geladen wird (zwei Module einer Bibliothek laufen im selben PHP-Prozess).

final class FW_IpsHost implements FW101_Host
{
    /** @param array<string,callable> $cb */
    public function __construct(private array $cb)
    {
    }

    public function value(int $varId): mixed
    {
        return IPS_VariableExists($varId) ? GetValue($varId) : null;
    }

    public function action(int $varId, mixed $value): bool
    {
        if (!IPS_VariableExists($varId)) {
            return false;
        }
        try {
            RequestAction($varId, $value);
            return true;
        } catch (Throwable $e) {
            ($this->cb['log'])('RequestAction #' . $varId . ' fehlgeschlagen: ' . $e->getMessage());
            return false;
        }
    }

    public function varType(int $varId): int
    {
        return IPS_VariableExists($varId) ? (int) IPS_GetVariable($varId)['VariableType'] : 2;
    }

    public function store(string $key, mixed $value): void
    {
        ($this->cb['store'])($key, $value);
    }

    public function load(string $key): mixed
    {
        return ($this->cb['load'])($key);
    }

    public function localMode(): bool
    {
        return ($this->cb['local'])();
    }

    public function log(string $message): void
    {
        ($this->cb['log'])($message);
    }

    public function now(): float
    {
        return microtime(true);
    }
}
