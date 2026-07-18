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
     * The WML (Wet minimumloon) parameters: `referentiemaandloon`, the
     * verified full-time statutory reference monthly wage, cents-converted
     * (sick-pay-calc design.md D3 — the year-1 loondoorbetaling floor is
     * scaled from this single verified table value, never a hard-coded
     * number).
     *
     * @return array{referentiemaandloonCents: int}
     *
     * @spec openspec/specs/sick-pay-calc/spec.md#REQ-SICK-002
     */
    public function wml(): array
    {
        return [
            'referentiemaandloonCents' => self::euroToCents((float) $this->leaf(['wml', 'referentiemaandloon', 'value'])),
        ];

    }//end wml()


    /**
     * The gebruikelijkloonregeling 2026 norm (dga-payroll-mode design.md D4):
     * the minimum annual customary salary a DGA (director-major-shareholder)
     * must be paid (Wet LB 1964 art. 12a), cents-converted. Not read by
     * `PayrollCalculator` — this exists solely for `NlDgaChecks` and any
     * future gebruikelijkloon-facing UI, the `wml()`/`referentiemaandloonCents`
     * precedent (consumed only by `SickPayCalculator`, never
     * `PayrollCalculator`).
     *
     * @return array{jaarnormCents: int}
     *
     * @spec openspec/specs/dga-payroll-mode/spec.md#REQ-DGA-003
     */
    public function gebruikelijkloon(): array
    {
        return [
            'jaarnormCents' => self::euroToCents((float) $this->leaf(['gebruikelijkloon', 'jaarnorm', 'value'])),
        ];

    }//end gebruikelijkloon()


    /**
     * The 30%-ruling (expatregeling, Wet LB 1964 art. 31a) rate/cap/norm
     * parameters from the `dertigProcentRegeling` table group
     * (30-procent-regeling design.md D3): `percent` stays on the percentage
     * scale (0-100) -- the vakantiebijslagRate()/zvw() precedent --
     * `aftoppingsgrensMaandCents`/`salarisnormAlgemeenCents`/
     * `salarisnormMasterOnder30Cents` are cents-converted since they are
     * compared directly against wage amounts in cents, and `maxDurationMonths`
     * is a plain month count. Not read by `PayrollCalculator` (the pack reads
     * the raw corpus via `@table.*` refs); this exists for
     * `PayrollRunService`'s exemption re-derivation and the `NlPayrollChecks`
     * 30%-ruling corpus rules -- the `bijtellingPrivegebruikAuto()` precedent.
     * `aftoppingsgrensJaarCents` is the SAME WNT-norm leaf's ANNUAL figure
     * (`aftoppingsgrens.jaar`, EUR 262.000/jaar in 2026), read by `NlWntChecks`
     * for the `nl-wnt-norm-overschrijding` check -- the single, shared home for
     * the WNT-norm datum, never re-declared in a second corpus file
     * (wnt-disclosure design.md D1).
     *
     * @return array{percent: float, maxDurationMonths: int, aftoppingsgrensMaandCents: int, aftoppingsgrensJaarCents: int, salarisnormAlgemeenCents: int, salarisnormMasterOnder30Cents: int}
     *
     * @spec openspec/changes/30-procent-regeling/specs/30-procent-regeling/spec.md#REQ-30P-001
     * @spec openspec/changes/wnt-disclosure/specs/wnt-disclosure/spec.md#REQ-WNT-003
     */
    public function dertigProcentRegeling(): array
    {
        return [
            'percent'                       => (float) $this->leaf(['dertigProcentRegeling', 'percent', 'value']),
            'maxDurationMonths'             => (int) $this->leaf(['dertigProcentRegeling', 'maxDurationMonths', 'value']),
            'aftoppingsgrensMaandCents'     => self::euroToCents((float) $this->leaf(['dertigProcentRegeling', 'aftoppingsgrens', 'value', 'maand'])),
            'aftoppingsgrensJaarCents'      => self::euroToCents((float) $this->leaf(['dertigProcentRegeling', 'aftoppingsgrens', 'value', 'jaar'])),
            'salarisnormAlgemeenCents'      => self::euroToCents((float) $this->leaf(['dertigProcentRegeling', 'salarisnormAlgemeen', 'value'])),
            'salarisnormMasterOnder30Cents' => self::euroToCents((float) $this->leaf(['dertigProcentRegeling', 'salarisnormMasterOnder30', 'value'])),
        ];

    }//end dertigProcentRegeling()


    /**
     * The werkkostenregeling (WKR) vrije-ruimte tranche + eindheffing
     * parameters from the `wkr` table group (wkr-administration design.md
     * D2/D4): `tranche1Percent`/`tranche2Percent`/`eindheffingPercent` stay on
     * the percentage scale (0-100), `tranche1GrensCents` is cents-converted —
     * the vakantiebijslagRate()/zvw() precedent.
     *
     * @return array{tranche1Percent: float, tranche1GrensCents: int, tranche2Percent: float, eindheffingPercent: float}
     *
     * @spec openspec/changes/wkr-administration/specs/wkr-administration/spec.md#REQ-WKR-002
     */
    public function wkr(): array
    {
        return [
            'tranche1Percent'    => (float) $this->leaf(['wkr', 'vrijeRuimteTranche1Percent', 'value']),
            'tranche1GrensCents' => self::euroToCents((float) $this->leaf(['wkr', 'vrijeRuimteTranche1Grens', 'value'])),
            'tranche2Percent'    => (float) $this->leaf(['wkr', 'vrijeRuimteTranche2Percent', 'value']),
            'eindheffingPercent' => (float) $this->leaf(['wkr', 'eindheffingPercent', 'value']),
        ];

    }//end wkr()


    /**
     * The bijtelling privegebruik auto rate/cap parameters (fleet-bijtelling
     * design.md D2): `standardPercent`/`evReducedPercent` stay on the
     * percentage scale (0-100) -- the vakantiebijslagRate()/zvw() precedent --
     * `evReducedCataloguswaardeCapCents` is cents-converted since it is
     * compared directly against a Vehicle's cataloguswaarde in cents.
     *
     * @return array{standardPercent: float, evReducedPercent: float, evReducedCataloguswaardeCapCents: int}
     *
     * @spec openspec/changes/fleet-bijtelling/specs/fleet-bijtelling/spec.md#REQ-FLEET-002
     */
    public function bijtellingPrivegebruikAuto(): array
    {
        return [
            'standardPercent'                  => (float) $this->leaf(['bijtellingPrivegebruikAuto', 'standardPercent', 'value']),
            'evReducedPercent'                 => (float) $this->leaf(['bijtellingPrivegebruikAuto', 'evReducedPercent', 'value']),
            'evReducedCataloguswaardeCapCents'  => self::euroToCents((float) $this->leaf(['bijtellingPrivegebruikAuto', 'evReducedCataloguswaardeCap', 'value'])),
        ];

    }//end bijtellingPrivegebruikAuto()


    /**
     * Convert a euro amount to integer cents using this class's own rule
     * (jurisdiction-packs design.md D4: the euro-to-cents conversion stays
     * exactly where it is today rather than being reimplemented in the
     * interpreter). Used by the DSL's `bracket` op, whose declared rows carry
     * euro-denominated `tot`/`a`/`c` fields.
     *
     * @param float $euro The euro amount.
     *
     * @return int
     *
     * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-002
     */
    public function toCents(float $euro): int
    {
        return self::euroToCents($euro);

    }//end toCents()


    /**
     * Resolve a jurisdiction-pack `@table.*` reference path against this
     * table's `parameters` object (jurisdiction-packs design.md D4: the pack
     * REFERENCES the verified corpus, it never copies it).
     *
     * Walking rule: each path segment descends one level; whenever the node
     * reached is a `{value, source, verified}` provenance leaf, it is
     * unwrapped to its `value` and any remaining segments continue into that
     * value. This handles every shape the corpus uses uniformly --
     * `loonheffing.Lv` (scalar leaf), `loonheffing.tijdvakFactoren.maand`
     * (leaf holding a map), `loonheffing.schijven.belowAow` (a group of
     * leaves), and `heffingskortingen.ahkm1.belowAow` (leaf holding a column
     * map).
     *
     * `$toCents` applies this class's own euro-to-cents conversion, so the
     * conversion stays exactly where it is today (design.md D4) rather than
     * being reimplemented in the interpreter. The corpus carries no unit
     * marker on its leaves (`Lv: 54` is euro, `zvw.werkgeversheffing: 6.1` is
     * a percentage), so the unit is DECLARED by the referencing pack via the
     * `:cents` ref suffix -- unit knowledge lives in config, not in the
     * interpreter.
     *
     * @param array<int, string> $segments The `@table.` path segments (already index-resolved).
     * @param bool               $toCents  Whether to convert the resolved euro value to integer cents.
     *
     * @return array{value: mixed, provenance: array{path: string, source: string, verified: bool, placeholder: bool}|null}
     *
     * @throws \RuntimeException When any path segment is missing.
     *
     * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-001
     * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-006
     */
    public function resolveLeaf(array $segments, bool $toCents=false): array
    {
        $node       = $this->parameters;
        $walked     = [];
        $provenance = null;

        foreach ($segments as $segment) {
            $unwrapped = $this->unwrapLeaf($node, $walked);
            if ($unwrapped !== null) {
                $node       = $unwrapped['value'];
                $provenance = $unwrapped['provenance'];
            }

            $walked[] = $segment;
            if (is_array($node) === false || array_key_exists($segment, $node) === false) {
                throw new \RuntimeException('TaxTables ('.$this->id.'): ontbrekende parameter "'.implode('.', $walked).'".');
            }

            $node = $node[$segment];
        }

        $unwrapped = $this->unwrapLeaf($node, $walked);
        if ($unwrapped !== null) {
            $node       = $unwrapped['value'];
            $provenance = $unwrapped['provenance'];
        }

        if ($toCents === true) {
            if (is_int($node) === false && is_float($node) === false) {
                throw new \RuntimeException('TaxTables ('.$this->id.'): parameter "'.implode('.', $walked).'" is geen bedrag en kan niet naar centen worden omgezet.');
            }

            $node = self::euroToCents((float) $node);
        }

        return [
            'value'      => $node,
            'provenance' => $provenance,
        ];

    }//end resolveLeaf()


    /**
     * Unwrap a `{value, source, verified}` provenance leaf, returning its
     * `value` plus the leaf's provenance, or null when the node is not a
     * provenance leaf.
     *
     * @param mixed              $node   The current node.
     * @param array<int, string> $walked The path walked so far (for the provenance stamp).
     *
     * @return array{value: mixed, provenance: array{path: string, source: string, verified: bool, placeholder: bool}}|null
     */
    private function unwrapLeaf(mixed $node, array $walked): ?array
    {
        if (is_array($node) === false || array_key_exists('value', $node) === false) {
            return null;
        }

        return [
            'value'      => $node['value'],
            'provenance' => [
                'path'        => implode('.', $walked),
                'source'      => (string) ($node['source'] ?? ''),
                'verified'    => (bool) ($node['verified'] ?? false),
                'placeholder' => (bool) ($node['placeholder'] ?? false),
            ],
        ];

    }//end unwrapLeaf()


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
