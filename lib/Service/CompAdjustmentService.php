<?php

/**
 * Comp Adjustment Service
 *
 * The imperative effective-dating write for compensation review cycles
 * (comp-cycles design.md D5): lifecycle guards are read-only per
 * OpenRegister's contract, so `CompEffectiveDateGuard` alone cannot stamp the
 * employee's new salary — this service owns that write.
 *
 * For an approved CompAdjustment whose `effectiveDate` has arrived it:
 *
 * 1. Refuses non-approved or not-yet-due adjustments (the same predicate
 *    `CompEffectiveDateGuard` enforces — belt and braces, since a service
 *    write should never rely solely on a guard it does not control the
 *    invocation of).
 * 2. Validates `proposedSalary` sits within the target SalaryBand's
 *    `[minSalary, maxSalary]` (vacuous when `targetBandId` is null, the
 *    `comp-adjustment-within-band` rule).
 * 3. Writes `grossMonthlySalary` onto the **Employee** — verified: the
 *    payroll engine reads `Employee.grossMonthlySalary`, and
 *    `EmploymentContract` carries only `hourlyWage` (no monthly-salary
 *    field), so the Employee record is the only target that flows into pay.
 *    `Employee.grossMonthlySalary` is stored as a plain euro-denominated
 *    float (verified against lib/Settings/register.d/hr-objects.json and its
 *    seed data, e.g. `3800.00` — NOT integer cents), while CompAdjustment's
 *    own `currentSalary`/`proposedSalary` are integer cents (this change's
 *    convention). The write therefore converts cents -> euros with the same
 *    `cents / 100` idiom `PayrollRunService::euros()` and
 *    `NlCaoChecks::minimumloonSchaalSatisfied()` already use for this exact
 *    boundary — never a bare cents value dropped onto a euro field.
 * 4. Stamps `appliedAt` and drives the `effectuate` transition
 *    (approved -> effective) via the ordinary object write that carries the
 *    transition (the NoSelfApprovalGuard/PayrollRunService idiom) — no
 *    separate "transition" API exists in this codebase.
 *
 * Idempotent per adjustment: an already-`effective` adjustment is a no-op.
 * Never edits any other status and never touches a PayrollRun.
 *
 * @category Service
 * @package  OCA\Hrmq\Service
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
 * @spec openspec/changes/comp-cycles/specs/comp-cycles/spec.md#REQ-COMP-006
 * @spec openspec/changes/comp-cycles/specs/comp-cycles/spec.md#REQ-COMP-007
 */

declare(strict_types=1);

namespace OCA\Hrmq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Effectuates approved, due CompAdjustments: within-band validation, the
 * cents-to-euros Employee.grossMonthlySalary write, and driving the
 * effectuate transition.
 */
class CompAdjustmentService
{

    /**
     * Max objects loaded per type.
     *
     * @var int
     */
    private const LIMIT = 10000;


    /**
     * @param ContainerInterface $container       DI container for lazy ObjectService resolution.
     * @param SettingsService    $settingsService Register slug source.
     * @param LoggerInterface    $logger          Logger.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {

    }//end __construct()


    /**
     * Effectuate one CompAdjustment by id — the guarded endpoint's entry
     * point (design.md D6). The controller has already RBAC-resolved the
     * adjustment; this re-fetches it unscoped and applies the same
     * approved+due+within-band predicate before writing.
     *
     * @param string      $adjustmentId The CompAdjustment id.
     * @param string|null $asOf         ISO date to evaluate "due" against, or null for today.
     *
     * @return array<string, mixed> Outcome: {adjustmentId, status, message, employeeId, newGrossMonthlySalary}.
     *
     * @spec openspec/changes/comp-cycles/specs/comp-cycles/spec.md#REQ-COMP-006
     */
    public function effectuateOne(string $adjustmentId, ?string $asOf=null): array
    {
        $adjustmentId = trim($adjustmentId);
        if ($adjustmentId === '') {
            return $this->outcome('', 'failed', 'Geen adjustmentId opgegeven.');
        }

        $adjustment = $this->findById('CompAdjustment', $adjustmentId);
        if ($adjustment === null) {
            return $this->outcome($adjustmentId, 'failed', 'Aanpassing niet gevonden.');
        }

        return $this->effectuate($adjustment, $asOf, false);

    }//end effectuateOne()


    /**
     * Batch-effectuate every due, approved CompAdjustment in a
     * CompReviewCycle — the `occ hrmq:comp:effectuate --cycle` entry point
     * (design.md D5).
     *
     * @param string      $cycleId The CompReviewCycle id.
     * @param string|null $asOf    ISO date to evaluate "due" against, or null for today.
     * @param bool        $dryRun  When true, evaluates and reports without writing anything.
     *
     * @return array<int, array<string, mixed>> One outcome per CompAdjustment in the cycle.
     *
     * @spec openspec/changes/comp-cycles/specs/comp-cycles/spec.md#REQ-COMP-006
     */
    public function effectuateCycle(string $cycleId, ?string $asOf=null, bool $dryRun=false): array
    {
        $cycleId = trim($cycleId);
        if ($cycleId === '') {
            return [];
        }

        $outcomes = [];
        foreach ($this->loadAll('CompAdjustment') as $adjustment) {
            if ((string) ($adjustment['cycleId'] ?? '') !== $cycleId) {
                continue;
            }

            $outcomes[] = $this->effectuate($adjustment, $asOf, $dryRun);
        }

        return $outcomes;

    }//end effectuateCycle()


    /**
     * The shared effectuation core: approved+due+within-band predicate, then
     * (unless dry-run) the Employee write + appliedAt stamp + effectuate
     * transition. Idempotent: an already-effective adjustment is a no-op.
     *
     * @param array<string, mixed> $adjustment The CompAdjustment.
     * @param string|null          $asOf       ISO date to evaluate "due" against, or null for today.
     * @param bool                 $dryRun     When true, evaluates without writing.
     *
     * @return array<string, mixed> Outcome.
     *
     * @spec openspec/changes/comp-cycles/specs/comp-cycles/spec.md#REQ-COMP-006
     * @spec openspec/changes/comp-cycles/specs/comp-cycles/spec.md#REQ-COMP-007
     */
    private function effectuate(array $adjustment, ?string $asOf, bool $dryRun): array
    {
        $adjustmentId = $this->idOf($adjustment);
        $status       = (string) ($adjustment['status'] ?? '');

        if ($status === 'effective') {
            return $this->outcome($adjustmentId, 'already-effective', 'Aanpassing is al geëffectueerd; niets te doen (idempotent).');
        }

        if ($status !== 'approved') {
            return $this->outcome(
                $adjustmentId,
                'refused-not-approved',
                'Aanpassing heeft status "'.($status !== '' ? $status : 'onbekend').'" — alleen goedgekeurde aanpassingen kunnen worden geëffectueerd.'
            );
        }

        $effectiveDate = trim((string) ($adjustment['effectiveDate'] ?? ''));
        $dueTimestamp  = $effectiveDate === '' ? false : strtotime($effectiveDate);
        if ($dueTimestamp === false) {
            return $this->outcome($adjustmentId, 'refused-not-due', 'Aanpassing heeft geen (geldige) ingangsdatum; effectueren is geweigerd.');
        }

        $asOfTimestamp = ($asOf === null || trim($asOf) === '') ? strtotime('today') : strtotime($asOf);
        if ($asOfTimestamp === false) {
            $asOfTimestamp = strtotime('today');
        }

        if ($dueTimestamp > $asOfTimestamp) {
            return $this->outcome(
                $adjustmentId,
                'refused-not-due',
                'Ingangsdatum ('.$effectiveDate.') ligt nog in de toekomst; effectueren kan pas op of na deze datum.'
            );
        }

        $proposedSalaryCents = ($adjustment['proposedSalary'] ?? null);
        if (is_numeric($proposedSalaryCents) === false) {
            return $this->outcome($adjustmentId, 'refused-not-due', 'Aanpassing heeft geen geldig voorgesteld salaris.');
        }

        $proposedSalaryCents = (int) $proposedSalaryCents;

        $bandCheck = $this->withinBand($adjustment, $proposedSalaryCents);
        if ($bandCheck !== null) {
            return $this->outcome($adjustmentId, $bandCheck['status'], $bandCheck['message']);
        }

        $employeeId = trim((string) ($adjustment['employeeId'] ?? ''));
        if ($employeeId === '') {
            return $this->outcome($adjustmentId, 'refused-employee-unresolvable', 'Aanpassing verwijst niet naar een medewerker.');
        }

        $employee = $this->findById('Employee', $employeeId);
        if ($employee === null) {
            return $this->outcome($adjustmentId, 'refused-employee-unresolvable', 'De gekoppelde medewerker kon niet worden geladen.');
        }

        $newGrossMonthlySalary = $this->euros($proposedSalaryCents);

        if ($dryRun === true) {
            $outcome                          = $this->outcome($adjustmentId, 'would-apply', 'Zou grossMonthlySalary bijwerken naar '.$newGrossMonthlySalary.' en de aanpassing effectief maken (dry-run: niets geschreven).');
            $outcome['employeeId']            = $employeeId;
            $outcome['newGrossMonthlySalary'] = $newGrossMonthlySalary;
            return $outcome;
        }

        try {
            $employeeUpdate                     = $employee;
            $employeeUpdate['grossMonthlySalary'] = $newGrossMonthlySalary;
            unset($employeeUpdate['@self']);

            $this->objectService()->saveObject(
                object: $employeeUpdate,
                register: $this->register(),
                schema: 'Employee',
                uuid: $employeeId,
                _rbac: false,
                _multitenancy: false
            );
        } catch (\Throwable $e) {
            $this->logger->error('CompAdjustmentService: kon grossMonthlySalary niet bijwerken voor '.$employeeId.': '.$e->getMessage());
            return $this->outcome($adjustmentId, 'failed', 'Bijwerken van het brutomaandsalaris is mislukt: '.$e->getMessage());
        }

        try {
            $adjustmentUpdate               = $adjustment;
            $adjustmentUpdate['appliedAt']  = gmdate('Y-m-d\TH:i:s\Z');
            $adjustmentUpdate['status']     = 'effective';
            unset($adjustmentUpdate['@self']);

            $this->objectService()->saveObject(
                object: $adjustmentUpdate,
                register: $this->register(),
                schema: 'CompAdjustment',
                uuid: $adjustmentId,
                _rbac: false,
                _multitenancy: false
            );
        } catch (\Throwable $e) {
            $this->logger->error('CompAdjustmentService: kon aanpassing '.$adjustmentId.' niet effectief maken na het bijwerken van het salaris: '.$e->getMessage());
            return $this->outcome($adjustmentId, 'failed', 'Salaris is bijgewerkt, maar de aanpassing kon niet effectief worden gemaakt: '.$e->getMessage());
        }

        $outcome                          = $this->outcome($adjustmentId, 'applied', 'Brutomaandsalaris bijgewerkt naar '.$newGrossMonthlySalary.'; aanpassing is nu effectief.');
        $outcome['employeeId']            = $employeeId;
        $outcome['newGrossMonthlySalary'] = $newGrossMonthlySalary;

        return $outcome;

    }//end effectuate()


    /**
     * The comp-adjustment-within-band predicate, evaluated inline (belt and
     * braces alongside the corpus CheckProvider, design.md D7): vacuous when
     * `targetBandId` is null; refused when the band cannot be resolved or the
     * proposed salary sits outside `[minSalary, maxSalary]`.
     *
     * @param array<string, mixed> $adjustment          The CompAdjustment.
     * @param int                   $proposedSalaryCents The proposed salary, in integer cents.
     *
     * @return array{status: string, message: string}|null Null when within band (or vacuous); an outcome fragment otherwise.
     *
     * @spec openspec/changes/comp-cycles/specs/comp-cycles/spec.md#REQ-COMP-007
     */
    private function withinBand(array $adjustment, int $proposedSalaryCents): ?array
    {
        $targetBandId = trim((string) ($adjustment['targetBandId'] ?? ''));
        if ($targetBandId === '') {
            // No band targeted -- vacuous (the payScalesVerified advisory-until-confirmed precedent).
            return null;
        }

        $band = $this->findById('SalaryBand', $targetBandId);
        if ($band === null) {
            return [
                'status'  => 'refused-band-unresolvable',
                'message' => 'De gekoppelde salarisschaal kon niet worden geladen; effectueren is geweigerd.',
            ];
        }

        $minSalary = ($band['minSalary'] ?? null);
        $maxSalary = ($band['maxSalary'] ?? null);
        if (is_numeric($minSalary) === false || is_numeric($maxSalary) === false) {
            return [
                'status'  => 'refused-band-unresolvable',
                'message' => 'De gekoppelde salarisschaal heeft geen geldig min/max-bereik; effectueren is geweigerd.',
            ];
        }

        if ($proposedSalaryCents < (int) $minSalary || $proposedSalaryCents > (int) $maxSalary) {
            return [
                'status'  => 'refused-out-of-band',
                'message' => 'Het voorgestelde salaris valt buiten de schaal ('.((int) $minSalary).'-'.((int) $maxSalary).' cent); effectueren is geweigerd.',
            ];
        }

        return null;

    }//end withinBand()


    /**
     * Convert integer cents to a euro float rounded to 2 decimals — the unit
     * `Employee.grossMonthlySalary` actually stores (verified: a plain number
     * field, e.g. seeded as `3800.00`, NOT integer cents), mirroring
     * `PayrollRunService::euros()`.
     *
     * @param int $cents The cents amount.
     *
     * @return float
     */
    private function euros(int $cents): float
    {
        return round(($cents / 100), 2);

    }//end euros()


    /**
     * Find one object by id, or null when it cannot be loaded/does not exist.
     *
     * @param string $schema The schema name.
     * @param string $id     The object id.
     *
     * @return array<string, mixed>|null
     */
    private function findById(string $schema, string $id): ?array
    {
        try {
            $row = $this->objectService()->find(id: $id, register: $this->register(), schema: $schema);
        } catch (\Throwable $e) {
            $this->logger->info('CompAdjustmentService: kon '.$schema.' '.$id.' niet laden: '.$e->getMessage());
            return null;
        }

        if ($row === null) {
            return null;
        }

        return $this->toArray($row);

    }//end findById()


    /**
     * Load all objects of a schema (capped), as plain arrays.
     *
     * @param string $schema The schema name.
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadAll(string $schema): array
    {
        try {
            $rows = $this->objectService()->setRegister($this->register())->setSchema($schema)->findAll(['limit' => self::LIMIT]);
        } catch (\Throwable $e) {
            $this->logger->warning('CompAdjustmentService: kon '.$schema.' niet laden: '.$e->getMessage());
            return [];
        }

        $out = [];
        foreach ((is_array($rows) === true ? $rows : []) as $row) {
            $out[] = $this->toArray($row);
        }

        return $out;

    }//end loadAll()


    /**
     * Build the base outcome array.
     *
     * @param string $adjustmentId The CompAdjustment id ('' when unknown).
     * @param string $status       Outcome status.
     * @param string $message      Human-readable outcome message.
     *
     * @return array<string, mixed>
     */
    private function outcome(string $adjustmentId, string $status, string $message): array
    {
        return [
            'adjustmentId' => ($adjustmentId === '' ? null : $adjustmentId),
            'status'       => $status,
            'message'      => $message,
        ];

    }//end outcome()


    /**
     * Normalise an ObjectService row (entity or array) to an array.
     *
     * @param mixed $row The row.
     *
     * @return array<string, mixed>
     */
    private function toArray(mixed $row): array
    {
        if (is_array($row) === true) {
            return $row;
        }

        if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
            return (array) $row->jsonSerialize();
        }

        return [];

    }//end toArray()


    /**
     * The object id of a row, falling back to `@self.id`.
     *
     * @param array<string, mixed> $row The row.
     *
     * @return string
     */
    private function idOf(array $row): string
    {
        return (string) ($row['id'] ?? $row['@self']['id'] ?? '');

    }//end idOf()


    /**
     * @return mixed The OpenRegister ObjectService.
     */
    private function objectService(): mixed
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');

    }//end objectService()


    /**
     * @return string The configured hrmq register slug.
     */
    private function register(): string
    {
        return $this->settingsService->getRegisterSlug();

    }//end register()


}//end class
