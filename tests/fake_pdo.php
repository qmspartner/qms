<?php

/**
 * Minimal, i-hukommelse-stub af PDO/PDOStatement til brug i tests/ — ingen rigtig
 * databaseforbindelse. Erstatter ikke en rigtig integrationstest (den slags kræver et
 * test-skema), men lader os teste at repository-funktionerne i includes/documents.php
 * bygger den SQL og de bind-parametre vi forventer, givet forskellige filtre/brugere.
 * FakeStatement husker den sidst forberedte SQL og de sidst bundne parametre, og
 * returnerer nogle på forhånd satte "rækker", så selve databasekørslen kan springes
 * over.
 */

class FakeStatement {
    public array $boundParams = [];

    public function __construct(public string $sql, public array $rows = []) {}

    public function execute(array $params = []): bool {
        $this->boundParams = $params;
        return true;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT): array {
        // quality_objective_all_period_ends() bruger PDO::FETCH_COLUMN til at få en flad
        // liste af værdier i stedet for associative rækker - understøt det her, så den
        // kan testes uden en rigtig database.
        if ($mode === PDO::FETCH_COLUMN) {
            return array_map(fn ($row) => reset($row), $this->rows);
        }
        return $this->rows;
    }

    public function fetch() {
        return $this->rows[0] ?? false;
    }
}

class FakePdo extends PDO {
    public ?FakeStatement $lastStatement = null;
    /** @var FakeStatement[] Alle forberedte statements, i kaldeorden — til funktioner der kører flere forespørgsler (fx audit_stats()). */
    public array $statements = [];
    public array $rowsToReturn = [];
    /** @var array[] Kø af rækkesæt, ét pr. prepare()-kald, i forventet kalderækkefølge. Tømmes fortløbende; falder tilbage til $rowsToReturn når køen er tom (bagudkompatibelt med funktioner der kun laver ét kald). */
    public array $rowsQueue = [];

    public function __construct() {
        // Ingen rigtig forbindelse — denne stub kører aldrig rigtig SQL.
    }

    public function prepare(string $query, array $options = []): FakeStatement|false {
        $rows = $this->rowsQueue ? array_shift($this->rowsQueue) : $this->rowsToReturn;
        $this->lastStatement = new FakeStatement($query, $rows);
        $this->statements[] = $this->lastStatement;
        return $this->lastStatement;
    }
}
