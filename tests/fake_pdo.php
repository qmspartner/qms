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

    /**
     * Tilføjet sammen med includes/action_items.php, som bruger fetchColumn() til
     * eksistens-tjek (action_item_has_open_for_case()) og enkelt-værdi-opslag
     * (næste position i action_item_add()) — samme mønster som flere steder i
     * includes/functions.php allerede bruger mod den rigtige PDO. Henter værdien af den
     * første kolonne i første række; false hvis der ingen rækker er, ligesom den
     * rigtige PDOStatement::fetchColumn().
     */
    public function fetchColumn() {
        if (!$this->rows) {
            return false;
        }
        $first = reset($this->rows[0]);
        return $first;
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
