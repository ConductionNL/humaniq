<?php

/**
 * Tax Tables loader
 *
 * Loads one versioned tax-year parameter file (`lib/Standards/tables/{id}.json`,
 * the payroll-core-schema chain head's verified corpus) and exposes it as
 * strongly-typed, integer-CENTS getters for `PayrollCalculator` (design.md D1).
 * Every euro-denominated leaf in the JSON is converted to integer cents at load
 * time; percentage/ratio leaves (schijventarief `percentage`, AHK/ARK/OUK `a`
 * factors, Zvw/Awf/Aof/Wko/Whk rates, vakantiebijslag rate) are exposed
 * unconverted (as plain floats on the 0-100 percentage scale, or as dimensionless
 * ratios where the Rekenvoorschriften formula itself is a straight per-euro
 * factor) so `PayrollCalculator` multiplies them directly against cents-valued
 * operands (design.md D2 worked example verifies this holds byte-exact).
 *
 * Zero Nextcloud dependencies (design.md D1): file IO only, no container, no
 * clock.
 *
 * @category Payroll
 * @package  OCA\Hrmq\Payroll
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-001
 */

declare(strict_types=1);

namespace OCA\Hrmq\Payroll;

/**
 * Versioned NL tax-year parameter table, integer-cents-converted.
 */
final class TaxTables
{

    /**
     * Memoised list of available table ids (globbed once, design.md D7).
     *
     * @var array<int, string>|null
     */
    private static ?array $availableIdsCache = null;

    /**
     * The raw decoded JSON `parameters` object.
     *
     * @var array<string, mixed>
     */
    private readonly array $parameters;


    /**
     * @param string               $id         The table id (e.g. `nl-2026`).
     * @param array<string, mixed> $parameters The raw decoded `parameters` object.
     */
    private function __construct(
        private readonly string $id,
        array $parameters,
    ) {
        $this->parameters = $parameters;

    }//end __construct()


    /**
     * Load and shape-validate `lib/Standards/tables/{id}.json`.
     *
     * @param string $id The table id (e.g. `nl-2026`).
     *
     * @return self
     *
     * @throws \RuntimeException When the file is missing, unreadable, malformed, or missing required parameter groups.
     *
     * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-001
     */
    public static function load(string $id): self
    {
        $id = trim($id);
        if ($id === '' || preg_match('/^[a-zA-Z0-9_-]+$/', $id) !== 1) {
            throw new \RuntimeException('TaxTables: ongeldige tabel-id "'.$id.'".');
        }

        $path = self::tablesDir().'/'.$id.'.json';
        if (file_exists($path) === false) {
            throw new \RuntimeException('TaxTables: tabelbestand niet gevonden: '.$path);
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException('TaxTables: kon tabelbestand niet lezen: '.$path);
        }

        $decoded = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE || is_array($decoded) === false) {
            throw new \RuntimeException('TaxTables: kon tabelbestand niet parsen: '.$path.' ('.json_last_error_msg().')');
        }

        $parameters = ($decoded['parameters'] ?? null);
        if (is_array($parameters) === false) {
            throw new \RuntimeException('TaxTables: tabelbestand mist "parameters": '.$path);
        }

        foreach (['loonheffing', 'heffingskortingen', 'volksverzekeringen', 'aow', 'zvw', 'werknemersverzekeringen', 'vakantiebijslag'] as $group) {
            if (isset($parameters[$group]) === false) {
                throw new \RuntimeException('TaxTables: tabelbestand mist parametergroep "'.$group.'": '.$path);
            }
        }

        return new self((string) ($decoded['id'] ?? $id), $parameters);

    }//end load()


    /**
     * The table id (the run's `engineVersion` stamp).
     *
     * @return string
     *
     * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-001
     */
    public function id(): string
    {
        return $this->id;

    }//end id()


    /**
     * All table ids present under `lib/Standards/tables/` (globbed once,
     * memoised — design.md D7, so `NlEngineChecks` performs no per-object IO).
     *
     * @return array<int, string>
     *
     * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-007
     */
    public static function availableIds(): array
    {
        if (self::$availableIdsCache !== null) {
            return self::$availableIdsCache;
        }

        $ids = [];
        foreach ((glob(self::tablesDir().'/*.json') ?: []) as $file) {
            $ids[] = basename($file, '.json');
        }

        self::$availableIdsCache = $ids;
        return $ids;

    }//end availableIds()


    /**
     * Reset the memoised available-ids list (test hook).
     *
     * @return void
     *
     * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-007
     */
    public static function resetAvailableIdsCache(): void
    {
        self::$availableIdsCache = null;

    }//end resetAvailableIdsCache()


    /**
     * `Lv` — the tabelloon rounding step, in cents (`54` euro).
     *
     * @return int
     *
     * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-001
     */
    public function lv(): int
    {
        return self::euroToCents((float) $this->leaf(['loonheffing', 'Lv', 'value']));

    }//end lv()


    /**
     * `Lmax` — the tabelloon ceiling above which the "systematiek 1" linear
     * extension applies (design.md D2 step 3), in cents.
     *
     * @return int
     *
     * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-001
     */
    public function lmax(): int
    {
        return self::euroToCents((float) $this->leaf(['loonheffing', 'Lmax', 'value']));

    }//end lmax()


    /**
     * The tijdvak factor `F` for a given tijdvak (default `maand` = 12).
     *
     * @param string $tijdvak One of kwartaal/maand/vierweken/week/dag.
     *
     * @return int
     *
     * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-001
     */
    public function tijdvakFactor(string $tijdvak='maand'): int
    {
        $factoren = (array) $this->leaf(['loonheffing', 'tijdvakFactoren', 'value']);
        return (int) ($factoren[$tijdvak] ?? 12);

    }//end tijdvakFactor()


    /**
     * The schijventarief bracket rows for one schijven-set (`belowAow`,
     * `aowBorn1946OrLater`, `aowBorn1945OrEarlier`), cents-converted.
     *
     * @param string $set The schijven-set key.
     *
     * @return array<int, array{tot: int|null, percentage: float, a: int, c: int}>
     *
     * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-001
     */
    public function schijven(string $set): array
    {
        $rows = (array) $this->leaf(['loonheffing', 'schijven', $set, 'value']);

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'tot'        => $row['tot'] === null ? null : self::euroToCents((float) $row['tot']),
                'percentage' => (float) $row['percentage'],
                'a'          => self::euroToCents((float) $row['a']),
                'c'          => self::euroToCents((float) $row['c']),
            ];
        }

        return $out;

    }//end schijven()


    /**
     * The AHK (algemene heffingskorting) parameters for the below-AOW or
     * AOW-age column, cents-converted (`a1` stays a dimensionless ratio).
     *
     * @param bool $aow True for the AOW-age column, false for below-AOW.
     *
     * @return array{m1: int, g1: int, g2: int, a1: float}
     *
     * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-001
     */
    public function ahk(bool $aow): array
    {
        $col = $aow === true ? 'aowAge' : 'belowAow';

        return [
            'm1' => self::euroToCents((float) $this->leaf(['heffingskortingen', 'ahkm1', 'value', $col])),
            'g1' => self::euroToCents((float) $this->leaf(['heffingskortingen', 'ahkg1', 'value', $col])),
            'g2' => self::euroToCents((float) $this->leaf(['heffingskortingen', 'ahkg2', 'value', $col])),
            'a1' => (float) $this->leaf(['heffingskortingen', 'ahka1', 'value', $col]),
        ];

    }//end ahk()


    /**
     * The ARK (arbeidskorting) parameters for the below-AOW or AOW-age column,
     * cents-converted (`o1..o3`/`a1` stay dimensionless ratios).
     *
     * @param bool $aow True for the AOW-age column, false for below-AOW.
     *
     * @return array{o1: float, o2: float, o3: float, a1: float, g1: int, g2: int, g3: int, g4: int, m1: int, m2: int, m3: int}
     *
     * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-001
     */
    public function ark(bool $aow): array
    {
        $col = $aow === true ? 'aowAge' : 'belowAow';

        return [
            'o1' => (float) $this->leaf(['heffingskortingen', 'arko1', 'value', $col]),
            'o2' => (float) $this->leaf(['heffingskortingen', 'arko2', 'value', $col]),
            'o3' => (float) $this->leaf(['heffingskortingen', 'arko3', 'value', $col]),
            'a1' => (float) $this->leaf(['heffingskortingen', 'arka1', 'value', $col]),
            'g1' => self::euroToCents((float) $this->leaf(['heffingskortingen', 'arkg1', 'value', $col])),
            'g2' => self::euroToCents((float) $this->leaf(['heffingskortingen', 'arkg2', 'value', $col])),
            'g3' => self::euroToCents((float) $this->leaf(['heffingskortingen', 'arkg3', 'value', $col])),
            'g4' => self::euroToCents((float) $this->leaf(['heffingskortingen', 'arkg4', 'value', $col])),
            'm1' => self::euroToCents((float) $this->leaf(['heffingskortingen', 'arkm1', 'value', $col])),
            'm2' => self::euroToCents((float) $this->leaf(['heffingskortingen', 'arkm2', 'value', $col])),
            'm3' => self::euroToCents((float) $this->leaf(['heffingskortingen', 'arkm3', 'value', $col])),
        ];

    }//end ark()


    /**
     * The OUK (ouderenkorting) parameters — AOW-age only (`belowAow` is null
     * in the tables, n.v.t.), cents-converted (`a1` stays a ratio).
     *
     * @return array{m1: int, g1: int, g2: int, a1: float}
     *
     * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-002
     */
    public function ouk(): array
    {
        return [
            'm1' => self::euroToCents((float) $this->leaf(['heffingskortingen', 'oukm1', 'value', 'aowAge'])),
            'g1' => self::euroToCents((float) $this->leaf(['heffingskortingen', 'oukg1', 'value', 'aowAge'])),
            'g2' => self::euroToCents((float) $this->leaf(['heffingskortingen', 'oukg2', 'value', 'aowAge'])),
            'a1' => (float) $this->leaf(['heffingskortingen', 'ouka1', 'value', 'aowAge']),
        ];

    }//end ouk()


    /**
     * The informative volksverzekeringen split rates (percentage scale,
     * design.md D2 step 7).
     *
     * @return array{aow: float, anw: float, wlz: float}
     *
     * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-001
     */
    public function volksverzekeringenRates(): array
    {
        return [
            'aow' => (float) $this->leaf(['volksverzekeringen', 'aow', 'value']),
            'anw' => (float) $this->leaf(['volksverzekeringen', 'anw', 'value']),
            'wlz' => (float) $this->leaf(['volksverzekeringen', 'wlz', 'value']),
        ];

    }//end volksverzekeringenRates()


    /**
     * The statutory AOW-leeftijd in whole years (`67`).
     *
     * @return int
     *
     * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-002
     */
    public function aowLeeftijdJaren(): int
    {
        return (int) $this->leaf(['aow', 'leeftijdJaren', 'value']);

    }//end aowLeeftijdJaren()


    /**
     * Zvw rates (percentage scale) plus the monthly maximum bijdrageloon in
     * cents (design.md D2 step 8 — the same monthly cap as the
     * werknemersverzekeringen maximumpremieloon, since both share the same
     * annual maximum in `nl-2026.json`).
     *
     * @return array{werkgeversheffing: float, inhouding: float, maximumBijdrageloonMaand: int}
     *
     * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-001
     */
    public function zvw(): array
    {
        return [
            'werkgeversheffing'        => (float) $this->leaf(['zvw', 'werkgeversheffing', 'value']),
            'inhouding'                => (float) $this->leaf(['zvw', 'inhouding', 'value']),
            'maximumBijdrageloonMaand' => self::euroToCents((float) $this->leaf(['werknemersverzekeringen', 'maximumpremieloon', 'value', 'maand'])),
        ];

    }//end zvw()


    /**
     * Werknemersverzekeringen rates (percentage scale) plus the monthly
     * maximum premieloon in cents (design.md D2 step 9).
     *
     * @return array{maximumPremieloonMaand: int, awfLaag: float, awfHoog: float, aofLaag: float, aofHoog: float, wkoOpslag: float, whkDefault: float}
     *
     * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-001
     */
    public function werknemersverzekeringen(): array
    {
        return [
            'maximumPremieloonMaand' => self::euroToCents((float) $this->leaf(['werknemersverzekeringen', 'maximumpremieloon', 'value', 'maand'])),
            'awfLaag'                => (float) $this->leaf(['werknemersverzekeringen', 'awf', 'value', 'laag']),
            'awfHoog'                => (float) $this->leaf(['werknemersverzekeringen', 'awf', 'value', 'hoog']),
            'aofLaag'                => (float) $this->leaf(['werknemersverzekeringen', 'aof', 'value', 'laag']),
            'aofHoog'                => (float) $this->leaf(['werknemersverzekeringen', 'aof', 'value', 'hoog']),
            'wkoOpslag'              => (float) $this->leaf(['werknemersverzekeringen', 'wkoOpslag', 'value']),
            'whkDefault'             => (float) $this->leaf(['werknemersverzekeringen', 'whk', 'value']),
        ];

    }//end werknemersverzekeringen()


    /**
     * The vakantiebijslag minimum rate (percentage scale, `8.0`).
     *
     * @return float
     *
     * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-001
     */
    public function vakantiebijslagRate(): float
    {
        return (float) $this->leaf(['vakantiebijslag', 'minRatePercent', 'value']);

    }//end vakantiebijslagRate()


    /**
     * Read a nested leaf from `parameters` by path, throwing when absent —
     * a malformed/incomplete table file must never silently compute a wrong
     * amount.
     *
     * @param array<int, string> $path The nested key path.
     *
     * @return mixed
     *
     * @throws \RuntimeException When any path segment is missing.
     */
    private function leaf(array $path): mixed
    {
        $node = $this->parameters;
        $walked = [];
        foreach ($path as $segment) {
            $walked[] = $segment;
            if (is_array($node) === false || array_key_exists($segment, $node) === false) {
                throw new \RuntimeException('TaxTables ('.$this->id.'): ontbrekende parameter "'.implode('.', $walked).'".');
            }

            $node = $node[$segment];
        }

        return $node;

    }//end leaf()


    /**
     * Convert a euro amount to integer cents (round-half-away-from-zero).
     *
     * @param float $euro The euro amount.
     *
     * @return int
     */
    private static function euroToCents(float $euro): int
    {
        return (int) round($euro * 100);

    }//end euroToCents()


    /**
     * The absolute path to `lib/Standards/tables/`.
     *
     * @return string
     */
    private static function tablesDir(): string
    {
        return __DIR__.'/../Standards/tables';

    }//end tablesDir()


}//end class
