<?php

/**
 * Payroll Run Service
 *
 * Turns the pure `PayrollCalculator` into draft payroll runs (design.md D4/D5):
 * creates at most one `PayrollRun` per (period, administrationId) — the
 * netpay/glpost probe-before-create idempotency pattern — generates one
 * Payslip per active NL employee whose contract covers the period (upsert
 * keyed on `(payrollRunId, employeeId)`, orphaned engine payslips of that run
 * deleted, payslips with a different or null `payrollRunId` never touched),
 * rolls up cents-exact totals, and stamps `engineVersion` (the tax-year table
 * id) + `calculatedAt`.
 *
 * Recalculation is allowed only while the run is `draft` (design.md D4):
 * approved/posted/paid runs are downstream truth consumed by glpost/netpay
 * and are refused. The service never writes any `status` value other than
 * creating the initial `draft` — approval remains a human act on the
 * existing enum, and write-time guard wiring stays owned by the active
 * `humaniq-rule-compliance-enforcement` change. GL/clearing fields
 * (glExpensePosted/glLiabilityPosted/withholdings*) are never touched.
 *
 * Employees the engine cannot compute honestly are SKIPPED with a per-employee
 * reason in the outcome — never computed wrong, never silently dropped
 * (design.md D4): no covering contract, no `grossMonthlySalary` (the hourly x
 * Timesheet path is a named fast-follow), missing BSN/ID-verification (the
 * anoniementarief 52% path is a named fast-follow), or no NL tax-table colour.
 *
 * Writes go through OpenRegister's ObjectService (design.md D5): verified
 * against the openregister checkout that `allowCreate: false` is a UI-only
 * object-list affordance with zero server-side enforcement, so this
 * service-side write is legitimate and generation remains the only Payslip
 * create path (the manifest keeps every Payslip surface create-less).
 *
 * sick-pay-calc (design.md D4): before building the `CalculationInput`, an
 * open (gemeld) `SickLeaveCase` covering the period is looked up per
 * employee; when present, the pure `SickPayCalculator` computes the
 * doorbetaald loon and its `payableGrossCents` replaces the full salary as
 * the gross fed to `PayrollCalculator`, and the Payslip is additionally
 * stamped with the sick-pay fields (`sickLeaveCaseId`, `doorbetaaldLoon`,
 * `wachtdagDeduction`, `sickPayReferenceWage`, `sickPayPercentage`,
 * `sickPayMinimumWageFloor`, `sickPayYearOne`). No open case -> the
 * full-salary path and the Payslip shape are byte-identical to before.
 *
 * retro-adjustments (design.md D4): after computing each employee's payslip,
 * every `applied` `PayrollAdjustment` whose `settlementPeriod` equals this
 * run's period is summed (by `deltaNet`, cents-exact) into that employee's
 * `retroAdjustment` component and folded into `nettoPay` -- a nabetaling or
 * terugvordering line that lands in the CURRENT run only. `draft`
 * adjustments and adjustments settling a different period never surface
 * here; the sealed historical payslip each adjustment was diffed against is
 * never read or written by this service. No open/applied adjustment for an
 * employee -> `retroAdjustment` stays null and `nettoPay` is unchanged
 * (byte-identical to before this change). The cost side of the same payout
 * (`deltaGross`, `deltaLoonheffing`, the employer charge deltas) is added to
 * the run totals, so the GL journal books what net pays out (humaniq#514).
 *
 * leave-buy-sell (design.md D6, humaniq#513): every `settled`
 * `LeaveTransaction` whose `settlementPeriod` equals this run's period is
 * summed (by `settledAmount`, cents-exact, signed: sold = taxable wage
 * added, bought = wage given up) into the GROSS fed to `PayrollCalculator`,
 * before the engine runs. Loonheffing is withheld on it and `nettoPay`
 * follows from the engine; the amount is never added onto net after tax.
 * The payslip names it in its `leaveBuySell` component. The engine is never
 * invoked to (re)compute `settledAmount` itself --
 * `LeaveBuySellSettlementService` already computed and stored it. No
 * settled transaction for an employee/period -> `leaveBuySell` stays null
 * and the gross is unchanged (byte-identical to before this change).
 *
 * loonbeslag (design.md D2/D3/D4): after folding retro-adjustments --
 * against the fully-folded `nettoPay` (engine net, which already carries
 * any leave buy/sell, + retroAdjustment), never an intermediate figure -- the one
 * `actief` Loonbeslag covering an employee's period (resolved via the same
 * id/slug/employeeNumber key convention as `coveringContract()`/
 * `openSickCaseFor()`, deterministic earliest-`effectiveFrom` tie-break when
 * more than one match) contributes a floor-clamped deduction: `deduction =
 * min(orderedAmount, max(0, nettoPaySoFar - beslagvrijeVoet))`, folded into
 * `nettoPay` as the FOURTH and final current-run post-tax component
 * (`Payslip.loonbeslag`/`loonbeslagId`). `PayrollCalculator` is never invoked
 * for this figure -- entirely post-tax arithmetic here. No active Loonbeslag
 * for an employee/period -> `loonbeslag`/`loonbeslagId` stay null and
 * `nettoPay` is unchanged (byte-identical to before this change). Idempotent
 * per (loonbeslagId, period): recalculating a draft run re-derives the same
 * deduction from scratch every time -- no accumulator anywhere on
 * `Loonbeslag`.
 *
 * fleet-bijtelling (design.md D3/D4; hrmq-asset-fleet-merge): UNLIKE the
 * three post-tax folds above, this is a genuine engine-INPUT change.
 * Immediately after the sick-pay substitution
 * (`$grossMonthlySalaryCents = $sickResult->payableGrossCents`) and
 * immediately before `CalculationInput` is constructed, the employee's open
 * `AssetAssignment` covering the period whose referenced `Asset.category` is
 * `vehicle` (resolved via the same id/slug/employeeNumber key convention as
 * `coveringContract()`/`openSickCaseFor()`, first match wins -- no overlap
 * guard in the MVP) contributes `monthlyBijtelling = max(0,
 * round(base_cents / 12) - employeeContributionCents)`, where `base` is the
 * referenced `Asset`'s listPrice times the applicable `nl-{year}.json`
 * `bijtellingPrivegebruikAuto` percentage (a two-tier blend for
 * `evReducedCapped`), ADDED to `grossMonthlySalaryCents` -- so
 * `PayrollCalculator` receives a larger `tvl` and is otherwise never touched.
 * `Payslip.bijtelling`/`assetAssignmentId` record the amount and the
 * assignment it came from. No covering vehicle AssetAssignment -> both stay
 * null and the gross is unchanged (byte-identical to before this change).
 * `Vehicle`/`CarAssignment` (hr-fleet.json) retired into `Asset`/
 * `AssetAssignment` -- the fiscal facts and holding period are the same
 * fields under new English names, not a new concept.
 *
 * dga-payroll-mode (design.md D3): `CalculationInput.verzekeringsplichtig`
 * is derived from `!($employee['isDga'] ?? false)` right at construction --
 * `false` for a DGA (director-major-shareholder), zeroing
 * `PayrollCalculator`'s Awf/Aof/Wko/Whk while every other component stays
 * computed exactly as for a regular employee (loonheffing/Zvw/nettoPay
 * unaffected -- werknemersverzekeringen never reduced net in this engine).
 * `Payslip.isDga` stamps a denormalized copy of `Employee.isDga` alongside
 * the other computed fields, so downstream consumers (glpost/UPA/
 * loonaangifte) see WHY werknemersverzekeringen reads zero instead of
 * assuming an engine bug. No `isDga` on the Employee -> `verzekeringsplichtig`
 * defaults `true` and the payslip is byte-identical to before this change.
 *
 * audit-trail-payroll (design.md D1, fixing hrmq#98): the resolved
 * `CalculationInput` fed to `PayrollCalculator::calculate()` was previously
 * discarded once `calculate()` returned -- `engineVersion`/`calculatedAt`
 * pinned WHICH code+parameters produced a run, but nothing pinned WHICH
 * resolved values (gross salary, `taxTableColor`,
 * `loonheffingskortingToegepast`, Awf/Aof tariffs, Whk percentage, ...) fed
 * it, so a later edit to the underlying `Employee`/`EmploymentContract`
 * could make a sealed payslip unreproducible. `$input->toCanonicalJson()` is
 * now stamped onto `Payslip.engineInputSnapshot` in the SAME write as the
 * rest of the payload (REQ-AUDP-001) -- `occ humaniq:payroll:reproduce` reloads
 * this snapshot (never live Employee/Contract state) to recompute and
 * compare a sealed payslip byte-for-byte (REQ-AUDP-002).
 *
 * @category Service
 * @package  OCA\Humaniq\Service
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
 * @spec openspec/specs/sick-pay-calc/spec.md#REQ-SICK-005
 * @spec openspec/specs/payroll-core-engine/spec.md#REQ-PCE-003
 * @spec openspec/specs/payroll-core-engine/spec.md#REQ-PCE-004
 * @spec openspec/specs/payroll-core-engine/spec.md#REQ-PCE-005
 * @spec openspec/specs/retro-adjustments/spec.md#REQ-RETRO-004
 * @spec openspec/specs/leave-buy-sell/spec.md#REQ-BUYSELL-005
 * @spec openspec/specs/loonbeslag/spec.md#REQ-BESLAG-002
 * @spec openspec/specs/loonbeslag/spec.md#REQ-BESLAG-004
 * @spec openspec/specs/loonbeslag/spec.md#REQ-BESLAG-005
 * @spec openspec/specs/fleet-bijtelling/spec.md#REQ-FLEET-003
 * @spec openspec/specs/fleet-bijtelling/spec.md#REQ-FLEET-003
 * @spec openspec/specs/dga-payroll-mode/spec.md#REQ-DGA-001
 * @spec openspec/specs/audit-trail-payroll/spec.md#REQ-AUDP-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;
use OCA\Humaniq\Payroll\CalculationInput;
use OCA\Humaniq\Payroll\CalculationResult;
use OCA\Humaniq\Payroll\PackRepository;
use OCA\Humaniq\Payroll\PayrollCalculator;
use OCA\Humaniq\Payroll\SickPayCalculator;
use OCA\Humaniq\Payroll\SickPayInput;
use OCA\Humaniq\Payroll\SickPayResult;
use OCA\Humaniq\Payroll\TaxTables;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Generates draft payroll runs + engine payslips from the pure calculator.
 */
class PayrollRunService {

	/**
	 * The default administrationId when the occ command omits
	 * `--administration` — matches the seed convention (`hr-seed.json` /
	 * NlPayrollChecks::seedObjects()).
	 *
	 * @var string
	 */
	private const DEFAULT_ADMINISTRATION = 'ADM-001';

	/**
	 * Max objects loaded per type.
	 *
	 * @var int
	 */
	private const LIMIT = 10000;

	/**
	 * The full-time hours-per-week basis the sick-pay WML floor's part-time
	 * factor is scaled against (design.md D3 — the same 36/36 full-time
	 * convention already used elsewhere in the seed data).
	 *
	 * @var float
	 */
	private const FULLTIME_HOURS_PER_WEEK = 36.0;

	/**
	 * The first 52 weeks of a SickLeaveCase's firstSickDay, past which the
	 * year-1 WML floor no longer applies (design.md D3, the
	 * nl-loondoorbetaling-floor rule's maxWeeks/2 boundary).
	 *
	 * @var int
	 */
	private const YEAR_ONE_WEEKS = 52;

	/**
	 * The retro fold of an employee with no applied adjustment this period:
	 * nothing paid, nothing booked (humaniq#514).
	 *
	 * @var array{net: int, gross: int, loonheffing: int, employerCharges: int}
	 */
	private const NO_RETRO_ADJUSTMENT = [
		'net' => 0,
		'gross' => 0,
		'loonheffing' => 0,
		'employerCharges' => 0,
	];

	/**
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param SettingsService $settingsService Register slug + employer-level payroll config.
	 * @param PayrollCalculator $calculator The pure gross-to-net calculator.
	 * @param SickPayCalculator $sickPayCalculator The pure loondoorbetaling-bij-ziekte calculator (sick-pay-calc).
	 * @param PayrollRetentionGuardService $retentionGuard Places the AWR art. 52 lid 4 statutory-retention legal hold on every sealed Payslip (hrmq#99 regression fix -- see `savePayslip()`).
	 * @param LoggerInterface $logger Logger.
	 * @param PackRepository $packs The jurisdiction-pack resolver (jurisdiction-packs design.md D7).
	 * @param HoursPayService|null $hoursPay Approved hours and overtime as pay (time-hours-and-overtime-to-payroll); null runs without it.
	 * @param WorkingCalendarReader|null $calendar openregister's working calendar, for the feestdag overtime category.
	 * @param PayrollExpenseFoldService|null $expenses Approved claims and recurring allowances (payroll-expenses-and-allowances); null runs without them.
	 * @param PayrollRunCheckService|null $runCheck The run check that runs after every calculation (payroll-run-checks D2); null runs without it.
	 * @param CaoComponentPayService|null $caoComponents The CAO components a contract names (payroll-cao-components D3); null runs without them.
	 * @param CostAllocationService|null $costAllocation Splits each payslip's wage costs over cost centres and projects (payroll-cost-allocation D3); null runs without it.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Each optional fold (hours, claims and allowances) and the run check is its own collaborator, so a run without one stays byte-identical and a test names which fold ran.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SettingsService $settingsService,
		private readonly PayrollCalculator $calculator,
		private readonly SickPayCalculator $sickPayCalculator,
		private readonly PayrollRetentionGuardService $retentionGuard,
		private readonly LoggerInterface $logger,
		private readonly PackRepository $packs = new PackRepository(),
		private readonly ?HoursPayService $hoursPay = null,
		private readonly ?WorkingCalendarReader $calendar = null,
		private readonly ?PayrollExpenseFoldService $expenses = null,
		private readonly ?PayrollRunCheckService $runCheck = null,
		private readonly ?CaoComponentPayService $caoComponents = null,
		private readonly ?CostAllocationService $costAllocation = null,
	) {

	}//end __construct()

	/**
	 * Create or recalculate the draft PayrollRun for (period,
	 * administrationId) — the occ `humaniq:payroll:run` entry point
	 * (design.md D4).
	 *
	 * @param string $period Wage period, `YYYY-MM`.
	 * @param string|null $administrationId The administration, or null for the seed-convention default.
	 * @param bool $recalculate Whether an existing draft run may be regenerated.
	 *
	 * @return array<string, mixed> Outcome: {runId, period, administrationId, status, message, computed, skipped, totals}.
	 *
	 * @spec openspec/specs/payroll-core-engine/spec.md#REQ-PCE-003
	 * @spec openspec/specs/payroll-core-engine/spec.md#REQ-PCE-004
	 */
	public function runFor(string $period, ?string $administrationId = null, bool $recalculate = false): array {
		$period = trim($period);
		$administrationId = trim((string)($administrationId ?? ''));
		if ($administrationId === '') {
			$administrationId = self::DEFAULT_ADMINISTRATION;
		}

		if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
			return $this->outcome('', $period, $administrationId, 'failed', 'Ongeldige periode "' . $period . '" (verwacht JJJJ-MM).');
		}

		$outsourced = $this->bureauRefusal(runId: '', period: $period, administrationId: $administrationId);
		if ($outsourced !== null) {
			return $outsourced;
		}

		$existing = $this->findRun($period, $administrationId);

		if ($existing !== null) {
			$status = (string)($existing['status'] ?? '');
			if ($status !== 'draft') {
				return $this->outcome(
					$this->idOf($existing),
					$period,
					$administrationId,
					'refused-not-draft',
					'Loonrun heeft status "' . $status . '" — alleen concept-runs kunnen (her)berekend worden (goedgekeurde runs zijn geboekte waarheid).'
				);
			}

			if ($recalculate === false) {
				return $this->outcome(
					$this->idOf($existing),
					$period,
					$administrationId,
					'exists',
					'Concept-loonrun bestaat al voor deze periode; gebruik --recalculate om opnieuw te berekenen (idempotente no-op).'
				);
			}

			return $this->generate($existing);
		}//end if

		try {
			$created = $this->toArray(
				$this->objectService()->saveObject(
					object: [
						'period' => $period,
						'administrationId' => $administrationId,
						'jurisdiction' => 'NL',
						'status' => 'draft',
					],
					register: $this->register(),
					schema: 'PayrollRun',
					_rbac: false,
					_multitenancy: false
				)
			);
		} catch (\Throwable $e) {
			$this->logger->error('PayrollRunService: kon PayrollRun niet aanmaken: ' . $e->getMessage());
			return $this->outcome('', $period, $administrationId, 'failed', 'Aanmaken van de loonrun is mislukt: ' . $e->getMessage());
		}

		return $this->generate($created);
	}//end runFor()

	/**
	 * Recalculate one existing run in place — the guarded endpoint's entry
	 * point (design.md D6). The controller has already RBAC-resolved the run;
	 * this re-fetches it unscoped and applies the draft-only guard.
	 *
	 * @param string $runId The PayrollRun id.
	 *
	 * @return array<string, mixed> Outcome (see runFor()).
	 *
	 * @spec openspec/specs/payroll-core-engine/spec.md#REQ-PCE-004
	 */
	public function recalculateRun(string $runId): array {
		$run = null;
		foreach ($this->loadAll('PayrollRun') as $candidate) {
			if ($this->idOf($candidate) === $runId) {
				$run = $candidate;
				break;
			}
		}

		if ($run === null) {
			return $this->outcome($runId, '', '', 'failed', 'Loonrun niet gevonden.');
		}

		$outsourced = $this->bureauRefusal(runId: $runId, period: (string)($run['period'] ?? ''), administrationId: (string)($run['administrationId'] ?? ''));
		if ($outsourced !== null) {
			return $outsourced;
		}

		$status = (string)($run['status'] ?? '');
		if ($status !== 'draft') {
			return $this->outcome(
				$runId,
				(string)($run['period'] ?? ''),
				(string)($run['administrationId'] ?? ''),
				'refused-not-draft',
				'Loonrun heeft status "' . $status . '" — alleen concept-runs kunnen herberekend worden.'
			);
		}

		return $this->generate($run);
	}//end recalculateRun()

	/**
	 * Generate (or regenerate) the payslips + totals for a draft run
	 * (design.md D4): per-employee calculate, upsert keyed
	 * (payrollRunId, employeeId), orphan cleanup, cents-exact roll-up,
	 * engineVersion/calculatedAt stamps. Never writes `status`. An open
	 * (gemeld) SickLeaveCase covering the period substitutes the doorbetaald
	 * loon for the full salary before the gross-to-net calculation
	 * (sick-pay-calc design.md D4); absent a case the path is unchanged.
	 *
	 * @param array<string, mixed> $run The draft PayrollRun.
	 *
	 * @return array<string, mixed> Outcome (see runFor()).
	 *
	 * @spec openspec/specs/sick-pay-calc/spec.md#REQ-SICK-005
	 * @spec openspec/specs/payroll-core-engine/spec.md#REQ-PCE-003
	 * @spec openspec/specs/payroll-core-engine/spec.md#REQ-PCE-005
	 * @spec openspec/specs/leave-buy-sell/spec.md#REQ-BUYSELL-005
	 * @spec openspec/specs/loonbeslag/spec.md#REQ-BESLAG-002
	 * @spec openspec/specs/loonbeslag/spec.md#REQ-BESLAG-004
	 * @spec openspec/specs/fleet-bijtelling/spec.md#REQ-FLEET-003
	 * @spec openspec/specs/dga-payroll-mode/spec.md#REQ-DGA-001
	 * @spec openspec/specs/dga-payroll-mode/spec.md#REQ-DGA-002
	 * @spec openspec/specs/audit-trail-payroll/spec.md#REQ-AUDP-001
	 * @spec openspec/specs/payroll-expenses-and-allowances/spec.md#REQ-PEA-002
	 */
	private function generate(array $run): array {
		$runId = $this->idOf($run);
		$period = (string)($run['period'] ?? '');
		$administrationId = (string)($run['administrationId'] ?? '');

		// jurisdiction-packs design.md D7: the country is no longer hardcoded
		// in the resolver (this was `'nl-'.substr($period, 0, 4)`). The pack is
		// a lookup on (run.jurisdiction, year-of(period)) against the packs'
		// own DECLARED jurisdiction/taxYear fields, and the pack in turn
		// declares which tables corpus its @table.* refs resolve against — so
		// no code path parses a country out of an id or a period any more.
		$jurisdiction = strtoupper(trim((string)($run['jurisdiction'] ?? '')));
		if ($jurisdiction === '') {
			$jurisdiction = 'NL';
		}

		try {
			$pack = $this->packs->resolve($jurisdiction, $period);
			$tables = TaxTables::load($pack->tablesId());
		} catch (\Throwable $e) {
			return $this->outcome($runId, $period, $administrationId, 'failed', 'Geen jurisdictiepack of belastingtabellen voor ' . $jurisdiction . ' ' . $period . ': ' . $e->getMessage());
		}

		$aofTariff = $this->settingsService->getPayrollAofTariff();
		$whkPercentage = $this->settingsService->getPayrollWhkPercentage($tables->werknemersverzekeringen()['whkDefault']);

		$contractsByEmployeeKey = $this->contractsByEmployeeKey();
		$sickCasesByEmployeeKey = $this->openSickCasesByEmployeeKey();
		$existingByEmployeeId = $this->enginePayslipsByEmployeeId($runId);
		$retroAdjustmentsByEmployeeId = $this->appliedRetroAdjustmentsByEmployeeId($period);
		$leaveBuySellByEmployeeId = $this->settledLeaveTransactionsByEmployeeId($period);
		$loonbeslagenByEmployeeKey = $this->activeLoonbeslagenByEmployeeKey();
		$assetAssignmentsByEmployeeKey = $this->openAssetAssignmentsByEmployeeKey();
		$vehicleAssetsById = $this->vehicleAssetsById();
		$hours = $this->hoursInputs(period: $period);
		$paidTimesheets = [];
		$expenseInputs = $this->expenses?->inputs(norm: $this->expenses->normFrom($tables));
		$paidClaimIds = [];
		$wkrRows = [];
		$allocatable = [];

		$computed = [];
		$skipped = [];
		$totals = [
			'gross' => 0,
			'loonheffing' => 0,
			'employerCharges' => 0,
			'withholdings' => 0,
			'net' => 0,
			'reimbursements' => 0,
		];

		foreach ($this->loadAll('Employee') as $employee) {
			if ($this->coversPeriod((string)($employee['startDate'] ?? ''), (string)($employee['endDate'] ?? ''), $period) === false) {
				// Not employed in this period — not selected, not reported.
				continue;
			}

			$employeeId = $this->idOf($employee);
			$employeeLabel = $this->employeeLabel($employee);

			$contract = $this->coveringContract($employee, $contractsByEmployeeKey, $period);
			if ($contract === null) {
				$skipped[] = ['employee' => $employeeLabel, 'employeeId' => $employeeId, 'reason' => 'no-contract (geen contract dat de periode dekt)'];
				continue;
			}

			$taxTableColor = trim((string)($employee['taxTableColor'] ?? ''));
			if (in_array($taxTableColor, ['wit', 'groen'], true) === false) {
				$skipped[] = ['employee' => $employeeLabel, 'employeeId' => $employeeId, 'reason' => 'non-nl (geen NL tabelkleur wit/groen op het werknemersrecord)'];
				continue;
			}

			// time-hours-and-overtime-to-payroll D1/D2: approved hours and
			// overtime this run pays. An employee without a monthly salary is
			// paid the hours at the contract's hourly wage instead of skipped.
			$hoursPay = $this->hoursPayFor(employee: $employee, contract: $contract, period: $period, runId: $runId, hours: $hours);
			$grossMonthly = ($employee['grossMonthlySalary'] ?? null);
			$salaried = (is_numeric($grossMonthly) === true && ((float)$grossMonthly) > 0.0);
			if ($salaried === false) {
				$hourlySkip = $this->hourlySkipReason(contract: $contract, hoursPay: $hoursPay);
				if ($hourlySkip !== null) {
					$skipped[] = ['employee' => $employeeLabel, 'employeeId' => $employeeId, 'reason' => $hourlySkip];
					continue;
				}
			}

			if (trim((string)($employee['bsn'] ?? '')) === '' || ($employee['identityDocumentVerified'] ?? false) !== true) {
				// Anoniementarief precondition: never compute a knowingly-wrong
				// slip — the 52% flat path is a named fast-follow (design.md D2).
				$skipped[] = ['employee' => $employeeLabel, 'employeeId' => $employeeId, 'reason' => 'anoniementarief-precondition (BSN/ID-verificatie ontbreekt; 52%-tarief: fast-follow)'];
				continue;
			}

			$grossMonthlySalaryCents = ($salaried === true ? (int)round(((float)$grossMonthly) * 100) : (int)($hoursPay['hourlyCents'] ?? 0));
			$regularWageCents = $grossMonthlySalaryCents;

			// sick-pay-calc (design.md D4): an open (gemeld) SickLeaveCase
			// covering the period substitutes the doorbetaald loon for the
			// full salary as the gross fed into PayrollCalculator. No open
			// case -> the full-salary path below is completely unchanged.
			$sickCase = $this->openSickCaseFor($employee, $sickCasesByEmployeeKey, $period);
			$sickResult = null;
			if ($sickCase !== null) {
				$sickInput = $this->sickPayInputFor($sickCase, $contract, $grossMonthlySalaryCents, $period);
				$sickResult = $this->sickPayCalculator->compute($sickInput, $tables);

				$grossMonthlySalaryCents = $sickResult->payableGrossCents;
			}

			// time-hours-and-overtime-to-payroll D1: overtime is wage worked in
			// the period, so it enters the gross the engine taxes.
			$grossMonthlySalaryCents += (int)($hoursPay['overtimeCents'] ?? 0);

			// fleet-bijtelling (design.md D3/D4; hrmq-asset-fleet-merge): a
			// genuine engine-INPUT change -- unlike sick-pay-calc's
			// substitution above (which still lands here as the "gross fed
			// to the calculator"), this ADDS to that gross rather than
			// replacing it. An open AssetAssignment on a category: vehicle
			// Asset, covering the period, contributes its monthly bijtelling;
			// PayrollCalculator is never touched -- it only ever sees the
			// (possibly larger) tvl. No covering assignment -> $bijtellingCents
			// stays 0 and the gross is unchanged.
			$assetAssignment = $this->openVehicleAssignmentFor($employee, $assetAssignmentsByEmployeeKey, $vehicleAssetsById, $period);
			$bijtellingCents = 0;
			if ($assetAssignment !== null) {
				$vehicleAsset = ($vehicleAssetsById[(string)($assetAssignment['assetId'] ?? '')] ?? null);
				$bijtellingCents = $this->bijtellingCentsFor($vehicleAsset, $assetAssignment, $tables);

				$grossMonthlySalaryCents += $bijtellingCents;
			}

			// leave-buy-sell (humaniq#513): a settled LeaveTransaction is WAGE,
			// so it is settled BEFORE tax. Every settled transaction whose
			// settlementPeriod equals THIS period enters the gross fed to the
			// calculator: a sell adds taxable wage, a buy takes it off. The
			// engine then withholds loonheffing on it and the net follows from
			// the engine, never from adding a gross amount onto net after tax.
			// No settled transaction -> leaveBuySellCents is 0 and the gross is
			// unchanged.
			$leaveBuySellCents = ($leaveBuySellByEmployeeId[$employeeId] ?? 0);
			$grossMonthlySalaryCents += $leaveBuySellCents;

			// payroll-cao-components D3: the allowances and premiums the
			// collective agreement prescribes are wage, added before the
			// calculator. A contract naming none folds nothing.
			$caoFold = $this->caoComponents?->foldFor(contract: $contract, regularWageCents: $regularWageCents, hoursPay: $hoursPay, entries: $hours['entries'], nonWorkingDates: $hours['nonWorkingDates'], period: $period);
			$grossMonthlySalaryCents += (int)($caoFold['totalCents'] ?? 0);

			// payroll-expenses-and-allowances D2/D4: the taxed part of every
			// allowance is wage and enters the gross before the calculator;
			// approved payroll-route claims and the untaxed allowance parts
			// are added to net after the leave fold, before the garnishment.
			$expenseFold = $this->expenses?->foldFor(inputs: $expenseInputs, employeeId: $employeeId, period: $period, runId: $runId);
			$grossMonthlySalaryCents += (int)($expenseFold['taxedCents'] ?? 0);
			$expenseNetCents = ((int)($expenseFold['claimCents'] ?? 0) + (int)($expenseFold['untaxedCents'] ?? 0));

			// 30-procent-regeling (design.md D2/D7): a granted 30%-ruling feeds
			// its applied rate into the engine, which reduces the TAXABLE base
			// (pack `belastbaarLoon` binding) while leaving the net-fold's gross
			// untouched -- so nettoPay RISES. Not granted -> rate 0.0 -> the
			// pack's cappedRate exemption degrades to zero (byte-identical path).
			$thirtyPercentRulingRate = ((($employee['thirtyPercentRulingGranted'] ?? false) === true)
				? (float)($employee['thirtyPercentRulingRate'] ?? 0.0)
				: 0.0);

			$input = new CalculationInput(
				grossMonthlySalaryCents: $grossMonthlySalaryCents,
				taxTableColor: $taxTableColor,
				loonheffingskortingToegepast: (($employee['loonheffingskortingToegepast'] ?? true) === true),
				dateOfBirth: (($employee['dateOfBirth'] ?? null) !== null ? (string)$employee['dateOfBirth'] : null),
				period: $period,
				awfTariff: $this->awfTariffFor($contract),
				aofTariff: $aofTariff,
				whkPercentage: $whkPercentage,
				verzekeringsplichtig: (($employee['isDga'] ?? false) !== true),
				jurisdiction: $jurisdiction,
				thirtyPercentRulingRate: $thirtyPercentRulingRate
			);

			$result = $this->calculator->calculate($input, $tables);

			// 30-procent-regeling (design.md D5): independently re-derive the
			// exemption amount (the same cappedRate formula, in PHP -- the
			// bijtelling D3 precedent) over the SAME tvl the engine saw, to
			// stamp the Payslip. nl-30-regeling-aftoppingsgrens-bedrag is the
			// drift detector if this ever disagrees with the pack binding.
			$thirtyPercentExemptionCents = $this->thirtyPercentExemptionCentsFor($employee, $grossMonthlySalaryCents, $tables);

			// retro-adjustments (design.md D4): fold every APPLIED
			// PayrollAdjustment settling into THIS period for this employee
			// into the payslip's retroAdjustment component + nettoPay. No
			// applied adjustment -> retroAdjustmentCents is 0 and the payload
			// stays byte-identical to before this change.
			$retroAdjustment = ($retroAdjustmentsByEmployeeId[$employeeId] ?? self::NO_RETRO_ADJUSTMENT);
			$retroAdjustmentCents = $retroAdjustment['net'];

			// loonbeslag (design.md D3): computed against the FULLY-folded
			// nettoPay-so-far (engine net, which already carries any settled
			// leave buy/sell, + retroAdjustment) -- the final fold, so the
			// beslagvrije voet protects the employee's actual take-home this
			// period, never an intermediate figure a same-period
			// nabetaling/leave-payout would still inflate past.
			$nettoPaySoFarCents = ($result->nettoPayCents + $retroAdjustmentCents + $expenseNetCents);
			$loonbeslag = $this->activeLoonbeslagFor($employee, $loonbeslagenByEmployeeKey, $period);
			$loonbeslagDeductionCents = ($loonbeslag !== null) ? $this->loonbeslagDeductionCents($loonbeslag, $nettoPaySoFarCents) : 0;

			$payload = $this->payslipPayload($runId, $employee, $contract, $period, $result);
			$payload = array_merge($payload, $this->sickPayFields($sickCase, $sickResult));
			$payload = array_merge($payload, $this->bijtellingFields($assetAssignment, $bijtellingCents));
			$payload = array_merge($payload, $this->thirtyPercentRulingFields($thirtyPercentExemptionCents));
			$payload = array_merge($payload, $this->retroAdjustmentFields($retroAdjustmentCents, $result->nettoPayCents));
			$payload = array_merge($payload, $this->leaveBuySellFields($leaveBuySellCents));
			$payload = array_merge($payload, $this->loonbeslagFields($loonbeslag, $loonbeslagDeductionCents, $nettoPaySoFarCents));
			$payload = array_merge($payload, $this->hoursPayFields(hoursPay: $hoursPay, salaried: $salaried));
			$payload = array_merge($payload, ($this->caoComponents?->payslipFields(fold: $caoFold) ?? []));
			$payload = array_merge($payload, $this->expenseFields(fold: $expenseFold, netCents: ($nettoPaySoFarCents - $loonbeslagDeductionCents)));
			$paidClaimIds = array_merge($paidClaimIds, (array)($expenseFold['claimIds'] ?? []));
			$wkrRows = array_merge($wkrRows, (array)($expenseFold['wkr'] ?? []));
			foreach ((array)($hoursPay['timesheetIds'] ?? []) as $timesheetId) {
				$paidTimesheets[$timesheetId] = $this->creditFor(hoursPay: $hoursPay, timesheetId: $timesheetId);
			}

			// audit-trail-payroll (REQ-AUDP-001): stamp the exact resolved
			// CalculationInput alongside the engine output, in the SAME write
			// -- the input that produced this payslip is never re-derivable
			// from Employee/EmploymentContract state once either is edited
			// later, so it is persisted here, once, at generation time.
			$payload['engineInputSnapshot'] = $input->toArray();

			try {
				$existingPayslip = ($existingByEmployeeId[$employeeId] ?? null);
				$saved = $this->savePayslip($payload, $existingPayslip);
				unset($existingByEmployeeId[$employeeId]);
			} catch (\Throwable $e) {
				$this->logger->error('PayrollRunService: kon loonstrook niet opslaan voor ' . $employeeLabel . ': ' . $e->getMessage());
				return $this->outcome($runId, $period, $administrationId, 'failed', 'Opslaan van de loonstrook voor ' . $employeeLabel . ' is mislukt: ' . $e->getMessage());
			}

			$computed[] = ['employee' => $employeeLabel, 'payslipId' => $this->idOf($saved)];
			$allocatable[] = ['payslipId' => $this->idOf($saved), 'employeeId' => $employeeId, 'grossCents' => ($result->grossPayCents + $retroAdjustment['gross']), 'chargesCents' => ($result->employerChargesCents + $retroAdjustment['employerCharges'])];

			// humaniq#514: the retro nabetaling/terugvordering is paid out in
			// net, so its gross, its wage tax and its employer charges belong
			// on the cost side too. Without them totalNet holds money
			// totalGross does not, the GL journal books no cost for it, and a
			// large enough delta makes the run unpostable. A leave buy/sell
			// already sits inside the engine gross since humaniq#513.
			$totals['gross'] += ($result->grossPayCents + $retroAdjustment['gross']);
			$totals['loonheffing'] += ($result->loonheffingCents + $retroAdjustment['loonheffing']);
			$totals['employerCharges'] += ($result->employerChargesCents + $retroAdjustment['employerCharges']);
			$totals['withholdings'] += ($result->loonheffingCents + $retroAdjustment['loonheffing']);
			$totals['net'] += ($nettoPaySoFarCents - $loonbeslagDeductionCents);
			$totals['reimbursements'] += $expenseNetCents;
		}//end foreach

		// Orphan cleanup (design.md D4): engine payslips of THIS run whose
		// employee no longer computes are deleted; payslips with a different
		// or null payrollRunId are never touched (they were never selected).
		foreach ($existingByEmployeeId as $orphan) {
			$orphanId = $this->idOf($orphan);
			try {
				$this->objectService()->deleteObject(
					uuid: $orphanId,
					register: $this->register(),
					schema: 'Payslip',
					_rbac: false,
					_multitenancy: false
				);
			} catch (\Throwable $e) {
				$this->logger->warning('PayrollRunService: kon verweesde loonstrook ' . $orphanId . ' niet verwijderen: ' . $e->getMessage());
			}
		}

		// time-hours-and-overtime-to-payroll D2: each paid timesheet names
		// this run, so no later run pays it again; a timesheet this run paid
		// before but no longer pays (reopened) is unstamped.
		if ($this->hoursPay !== null) {
			$this->hoursPay->stamp(timesheets: $hours['timesheets'], paid: $paidTimesheets, runId: $runId, period: $period);
		}

		// payroll-expenses-and-allowances D2/D5: each paid claim names this
		// run, and every untaxed allowance payment has its one WKR row.
		if ($this->expenses !== null && $expenseInputs !== null) {
			$this->expenses->stampClaims(expenses: $expenseInputs['expenses'], paidIds: $paidClaimIds, runId: $runId, period: $period);
			$this->expenses->writeWkr(inputs: $expenseInputs, rows: $wkrRows, administrationId: $administrationId);
		}

		// Roll-up + stamps (design.md D4): totals cents-exact, calculatedAt =
		// now. Status and GL/clearing fields are deliberately NOT written.
		// engineVersion is now `{packId}@{packVersion}` (jurisdiction-packs
		// design.md D7) — strictly more information than the bare tables id it
		// stamped before, since it names the CHAIN as well as the parameters.
		$runUpdate = array_merge(
			$run,
			[
				'totalGross' => $this->euros($totals['gross']),
				'totalLoonheffing' => $this->euros($totals['loonheffing']),
				'totalEmployerCharges' => $this->euros($totals['employerCharges']),
				'totalWithholdings' => $this->euros($totals['withholdings']),
				'totalNet' => $this->euros($totals['net']),
				'totalReimbursements' => $this->euros($totals['reimbursements']),
				'engineVersion' => $pack->engineVersion(),
				'calculatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
			]
		);
		unset($runUpdate['@self']);

		try {
			$this->objectService()->saveObject(
				object: $runUpdate,
				register: $this->register(),
				schema: 'PayrollRun',
				uuid: ($runId === '' ? null : $runId),
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			$this->logger->error('PayrollRunService: kon PayrollRun-totalen niet opslaan: ' . $e->getMessage());
			return $this->outcome($runId, $period, $administrationId, 'failed', 'Opslaan van de loonrun-totalen is mislukt: ' . $e->getMessage());
		}

		$outcome = $this->outcome($runId, $period, $administrationId, 'calculated', sprintf('%d loonstro(o)k(en) berekend, %d overgeslagen.', count($computed), count($skipped)));
		$outcome['computed'] = $computed;
		$outcome['skipped'] = $skipped;
		$outcome['check'] = $this->runTheCheck(runId: $runId, skipped: $skipped);
		$outcome['allocationLines'] = $this->allocateCosts(runId: $runId, period: $period, administrationId: $administrationId, payslips: $allocatable);
		$outcome['totals'] = [
			'totalGross' => $this->euros($totals['gross']),
			'totalLoonheffing' => $this->euros($totals['loonheffing']),
			'totalEmployerCharges' => $this->euros($totals['employerCharges']),
			'totalWithholdings' => $this->euros($totals['withholdings']),
			'totalNet' => $this->euros($totals['net']),
		];

		return $outcome;
	}//end generate()

	/**
	 * The timesheets, entries, runs and calendar the hours fold reads, once
	 * per run (time-hours-and-overtime-to-payroll D2, D4).
	 *
	 * @param string $period The run's period.
	 *
	 * @return array{timesheets: list<array<string, mixed>>, entries: list<array<string, mixed>>, runsById: array<string, array<string, mixed>>, nonWorkingDates: list<string>|null}
	 *
	 * @spec openspec/specs/time-hours-and-overtime-to-payroll/spec.md#REQ-HTP-001
	 */
	private function hoursInputs(string $period): array {
		if ($this->hoursPay === null) {
			return ['timesheets' => [], 'entries' => [], 'runsById' => [], 'nonWorkingDates' => null];
		}

		$runsById = [];
		foreach ($this->loadAll('PayrollRun') as $run) {
			$runsById[$this->idOf($run)] = $run;
		}

		$dates = null;
		if ($this->calendar !== null) {
			$from = new \DateTimeImmutable(((int)substr($period, 0, 4) - 1) . '-01-01');
			$dates = $this->calendar->nonWorkingDates($from, (new \DateTimeImmutable($period . '-01'))->modify('last day of this month'))['dates'];
		}

		return [
			'timesheets' => array_values($this->loadAll('Timesheet')),
			'entries' => array_values($this->loadAll('TimeEntry')),
			'runsById' => $runsById,
			'nonWorkingDates' => $dates,
		];
	}//end hoursInputs()

	/**
	 * What the approved hours and overtime come to for one employee, or null
	 * when this run pays no timesheet of theirs.
	 *
	 * @param array<string, mixed> $employee The employee.
	 * @param array<string, mixed> $contract The covering contract.
	 * @param string               $period   The run's period.
	 * @param string               $runId    The run.
	 * @param array<string, mixed> $hours    The inputs from hoursInputs().
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/time-hours-and-overtime-to-payroll/spec.md#REQ-HTP-001
	 */
	private function hoursPayFor(array $employee, array $contract, string $period, string $runId, array $hours): ?array {
		if ($this->hoursPay === null) {
			return null;
		}

		$toPay = $this->hoursPay->timesheetsToPay(
			timesheets: $hours['timesheets'],
			employeeId: $this->idOf($employee),
			period: $period,
			runId: $runId,
			runsById: $hours['runsById']
		);
		if ($toPay === []) {
			return null;
		}

		return $this->hoursPay->payFor(employee: $employee, contract: $contract, timesheets: $toPay, entries: $hours['entries'], nonWorkingDates: $hours['nonWorkingDates']);
	}//end hoursPayFor()

	/**
	 * Why an employee without a monthly salary is not paid, or null when the
	 * approved hours pay them.
	 *
	 * @param array<string, mixed>      $contract The covering contract.
	 * @param array<string, mixed>|null $hoursPay The hours fold, or null.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/time-hours-and-overtime-to-payroll/spec.md#REQ-HTP-001
	 */
	private function hourlySkipReason(array $contract, ?array $hoursPay): ?string {
		if ($this->hoursPay === null) {
			return 'no-monthly-salary (hourly path: fast-follow)';
		}

		$wage = ($contract['hourlyWage'] ?? null);
		if (is_numeric($wage) === false || (float)$wage <= 0.0) {
			return 'no-salary-and-no-hourly-wage';
		}

		return ($hoursPay === null ? 'no-approved-hours' : null);
	}//end hourlySkipReason()

	/**
	 * Run the check after a successful calculation; a failing check is
	 * logged and never fails the run (payroll-run-checks D2).
	 *
	 * @param string                     $runId   The run.
	 * @param list<array<string, mixed>> $skipped The skipped employees.
	 *
	 * @return array<string, int>|null The counts, or null without a check.
	 *
	 * @spec openspec/specs/payroll-run-checks/spec.md#REQ-PRK-001
	 */
	private function runTheCheck(string $runId, array $skipped): ?array {
		if ($this->runCheck === null || $runId === '') {
			return null;
		}

		try {
			return $this->runCheck->check($runId, $skipped);
		} catch (\Throwable $e) {
			$this->logger->error('PayrollRunService: the check of run ' . $runId . ' failed: ' . $e->getMessage());
			return null;
		}
	}//end runTheCheck()

	/**
	 * The refusal for an administration whose payroll an outside bureau
	 * processes, or null when humaniq's engine runs it
	 * (payroll-external-bureau-handoff D1). Two sources of payslips for one
	 * period would disagree, and every sum downstream would double.
	 *
	 * @param string $runId            The run, or '' before one exists.
	 * @param string $period           The period.
	 * @param string $administrationId The administration.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/payroll-external-bureau-handoff/spec.md#REQ-PXB-001
	 */
	private function bureauRefusal(string $runId, string $period, string $administrationId): ?array {
		foreach ($this->loadAll('hrAdministration') as $administration) {
			if ((string)($administration['administrationId'] ?? '') !== $administrationId
				|| (string)($administration['payrollProcessing'] ?? 'engine') !== 'external-bureau'
			) {
				continue;
			}

			$bureau = trim((string)($administration['payrollBureauName'] ?? ''));
			return $this->outcome(
				$runId,
				$period,
				$administrationId,
				'refused-external-bureau',
				'De salarisverwerking van deze administratie ligt bij een extern bureau' . ($bureau === '' ? '' : ' (' . $bureau . ')') . '; humaniq berekent hier geen loonrun. Stuur de mutaties via een overdracht.'
			);
		}

		return null;
	}//end bureauRefusal()

	/**
	 * Split the run's wage costs over cost centres and projects; a failing
	 * allocation is logged and never fails the run, and the journal then
	 * books the totals without codes (payroll-cost-allocation D3, D4).
	 *
	 * @param string                     $runId            The run.
	 * @param string                     $period           The run's period.
	 * @param string                     $administrationId The run's administration.
	 * @param list<array<string, mixed>> $payslips         Per saved payslip: id, employee, gross and charges in cents.
	 *
	 * @return int|null The lines written, or null without the allocation.
	 *
	 * @spec openspec/specs/payroll-cost-allocation/spec.md#REQ-PCA-001
	 */
	private function allocateCosts(string $runId, string $period, string $administrationId, array $payslips): ?int {
		if ($this->costAllocation === null) {
			return null;
		}

		try {
			return $this->costAllocation->allocateRun(runId: $runId, period: $period, administrationId: $administrationId, payslips: $payslips);
		} catch (\Throwable $e) {
			$this->logger->error('PayrollRunService: the cost allocation of run ' . $runId . ' failed: ' . $e->getMessage());
			return null;
		}
	}//end allocateCosts()

	/**
	 * The payslip fields of the claims-and-allowances fold; the net pay is
	 * restated only when the fold added to it (payroll-expenses-and-allowances D2).
	 *
	 * @param array<string, mixed>|null $fold     The fold, or null without the service.
	 * @param int                       $netCents The final net pay, in cents.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/payroll-expenses-and-allowances/spec.md#REQ-PEA-001
	 */
	private function expenseFields(?array $fold, int $netCents): array {
		if ($fold === null || $this->expenses === null) {
			return [];
		}

		$fields = $this->expenses->payslipFields($fold);
		if (((int)$fold['claimCents'] + (int)$fold['untaxedCents']) > 0) {
			$fields['nettoPay'] = $this->euros($netCents);
		}

		return $fields;
	}//end expenseFields()

	/**
	 * The payslip fields that say what hours and overtime were paid; none
	 * when no timesheet was paid, so such a payslip stays as it was.
	 *
	 * @param array<string, mixed>|null $hoursPay The hours fold, or null.
	 * @param bool                      $salaried Whether the employee has a monthly salary.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/time-hours-and-overtime-to-payroll/spec.md#REQ-HTP-003
	 */
	private function hoursPayFields(?array $hoursPay, bool $salaried): array {
		if ($hoursPay === null) {
			return [];
		}

		$fields = [
			'hoursPaid' => ($salaried === true ? null : (float)$hoursPay['hoursPaid']),
			'hourlyPay' => ($salaried === true ? null : round($hoursPay['hourlyCents'] / 100, 2)),
			'overtimeHours' => (float)$hoursPay['overtimeHours'],
			'overtimePay' => round($hoursPay['overtimeCents'] / 100, 2),
			'overtimeSurchargeUnresolved' => (bool)$hoursPay['surchargeUnresolved'],
			'timesheetIds' => $hoursPay['timesheetIds'],
		];
		if ($salaried === false) {
			$fields['hoursWorked'] = (float)$hoursPay['hoursPaid'];
		}

		return $fields;
	}//end hoursPayFields()

	/**
	 * The hours one paid timesheet credits as time off (0 when none).
	 *
	 * @param array<string, mixed> $hoursPay    The hours fold.
	 * @param string               $timesheetId The timesheet.
	 *
	 * @return float
	 */
	private function creditFor(array $hoursPay, string $timesheetId): float {
		foreach ((array)($hoursPay['timeCredits'] ?? []) as $credit) {
			if ($credit['timesheetId'] === $timesheetId) {
				return (float)$credit['hours'];
			}
		}

		return 0.0;
	}//end creditFor()

	/**
	 * Build the Payslip payload for one computed employee (design.md D4
	 * "payslip stamping"): the D2 components, `payrollRunId`, `userId` from
	 * the employee's `nextcloudUserId` (the mijn-hr convention), the
	 * loonstrook-content booleans (the record carries the BW 7:626 facts —
	 * rendering stays payslip-pdf-docudesk's concern), WKR fields 0 (no WKR
	 * administration in the engine MVP), `anoniementariefApplied` false
	 * (precondition employees are skipped, never computed at 52%). `isDga`
	 * is a denormalized copy of `Employee.isDga` (dga-payroll-mode), so a
	 * payslip records WHY werknemersverzekeringen reads zero.
	 *
	 * @param string $runId The PayrollRun id.
	 * @param array<string, mixed> $employee The Employee.
	 * @param array<string, mixed> $contract The covering EmploymentContract.
	 * @param string $period Wage period (YYYY-MM).
	 * @param CalculationResult $result The calculator output.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/payroll-core-engine/spec.md#REQ-PCE-005
	 * @spec openspec/specs/dga-payroll-mode/spec.md#REQ-DGA-001
	 */
	private function payslipPayload(string $runId, array $employee, array $contract, string $period, CalculationResult $result): array {
		$nextcloudUserId = trim((string)($employee['nextcloudUserId'] ?? ''));

		$payload = [
			'employeeId' => $this->idOf($employee),
			'userId' => ($nextcloudUserId === '' ? null : $nextcloudUserId),
			'payrollRunId' => $runId,
			'period' => $period,
			'jurisdiction' => 'NL',
			'currency' => 'EUR',
			'grossPay' => $this->euros($result->grossPayCents),
			'loonheffing' => $this->euros($result->loonheffingCents),
			'arbeidskorting' => $this->euros($result->arbeidskortingCents),
			'volksverzekeringen' => $this->euros($result->volksverzekeringenCents),
			'werknemersverzekeringen' => $this->euros($result->werknemersverzekeringenCents),
			'isDga' => (($employee['isDga'] ?? false) === true),
			'zvw' => $this->euros($result->zvwCents),
			'zvwMode' => $result->zvwMode,
			'zvwRate' => $result->zvwRate,
			'anoniementariefApplied' => false,
			'appliedTaxRate' => $result->appliedTaxRate,
			'nettoPay' => $this->euros($result->nettoPayCents),
			'vakantiegeldReserved' => $this->euros($result->vakantiegeldReservedCents),
			'vakantiegeldRate' => $result->vakantiegeldRate,
			'wkrUsed' => 0.0,
			'wkrVrijeRuimteRemaining' => 0.0,
			'wkrExcess' => 0.0,
			'pensionContribution' => 0.0,
			'statementProvided' => true,
			'showsGrossWage' => true,
			'showsDeductionBasis' => true,
			'showsMinimumWage' => true,
			'showsEmployerEmployeeIds' => true,
		];

		$hoursPerWeek = ($contract['hoursPerWeek'] ?? null);
		if (is_numeric($hoursPerWeek) === true && ((float)$hoursPerWeek) > 0.0) {
			// Contracted monthly hours (52 weeks / 12 months) — feeds the
			// effective-hourly-rate minimum-wage checks.
			$payload['hoursWorked'] = round((((float)$hoursPerWeek) * 52 / 12), 2);
		}

		return $payload;
	}//end payslipPayload()

	/**
	 * Upsert one engine Payslip: update the existing (payrollRunId,
	 * employeeId)-keyed payslip in place, or create a new one. Every seal
	 * (create or recalculate) places the AWR art. 52 lid 4 statutory-
	 * retention legal hold (hrmq#99 regression fix: a plain NL Payslip has
	 * neither a populated `retainedUntil` nor an OpenRegister-computed
	 * `archiefactiedatum` for `PayrollRetentionGuardService::syncLegalHold()`
	 * to read, so without this call it was left fully erasable by the
	 * guarded DSAR erase -- see `PayrollRetentionGuardService`'s class
	 * docblock REGRESSION note). Idempotent: a recalculated payslip that
	 * already carries the hold is left untouched.
	 *
	 * @param array<string, mixed> $payload The new payslip payload.
	 * @param array<string, mixed>|null $existing The existing engine payslip for this (run, employee), if any.
	 *
	 * @return array<string, mixed> The saved object.
	 *
	 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-005
	 */
	private function savePayslip(array $payload, ?array $existing): array {
		$uuid = null;
		if ($existing !== null) {
			$existingId = $this->idOf($existing);
			$uuid = ($existingId === '' ? null : $existingId);
		}

		$saved = $this->objectService()->saveObject(
			object: $payload,
			register: $this->register(),
			schema: 'Payslip',
			uuid: $uuid,
			_rbac: false,
			_multitenancy: false
		);

		$this->retentionGuard->placeStatutoryFloorHold(
			$saved,
			'Payslip',
			'period',
			PayrollRetentionGuardService::AWR_RETENTION_YEARS,
			PayrollRetentionGuardService::AWR_LAW_REFERENCE
		);

		// The AWR floor above is the NL case: a plain NL Payslip carries no
		// ceiling of its own, so the floor is DERIVED. A non-NL payslip is the
		// other half of the same concern — FR/US/DE payslips arrive with
		// `retainedUntil` already populated by EuUsPayrollChecks, and a schema
		// carrying an `archive` config gets OpenRegister's own computed
		// `retention.archiefactiedatum`. syncLegalHold() reads whichever of
		// those already exists and syncs the hold to it; it derives nothing.
		//
		// It was previously called from NOWHERE in production — only from its
		// unit tests. So an already-known, still-open ceiling on a non-NL
		// payslip placed no legal hold at all, and the guarded DSAR erase
		// would delete it. Both calls are idempotent and each is a no-op in
		// the other's case, so running both on every seal is correct rather
		// than merely harmless: whichever ceiling exists wins, and neither
		// overwrites a hold that is already active.
		$this->retentionGuard->syncLegalHold($saved, 'Payslip');

		return $this->toArray($saved);
	}//end savePayslip()

	/**
	 * The existing PayrollRun for (period, administrationId), or null —
	 * the idempotency probe (design.md D4).
	 *
	 * @param string $period Wage period (YYYY-MM).
	 * @param string $administrationId The administration.
	 *
	 * @return array<string, mixed>|null
	 */
	private function findRun(string $period, string $administrationId): ?array {
		foreach ($this->loadAll('PayrollRun') as $run) {
			if ((string)($run['period'] ?? '') === $period
				&& (string)($run['administrationId'] ?? '') === $administrationId
			) {
				return $run;
			}
		}

		return null;
	}//end findRun()

	/**
	 * This run's existing ENGINE payslips (payrollRunId === $runId), keyed by
	 * employeeId — the upsert/orphan-cleanup index. Payslips with a different
	 * or null payrollRunId are never included (design.md D4).
	 *
	 * @param string $runId The PayrollRun id.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function enginePayslipsByEmployeeId(string $runId): array {
		$out = [];
		if ($runId === '') {
			return $out;
		}

		foreach ($this->loadAll('Payslip') as $payslip) {
			if ((string)($payslip['payrollRunId'] ?? '') !== $runId) {
				continue;
			}

			$employeeId = (string)($payslip['employeeId'] ?? '');
			if ($employeeId !== '') {
				$out[$employeeId] = $payslip;
			}
		}

		return $out;
	}//end enginePayslipsByEmployeeId()

	/**
	 * All EmploymentContracts, indexed by every employee-reference key
	 * (the netpay three-way convention: contracts may reference the employee
	 * by object id, slug, or employeeNumber). Each key maps to the LIST of
	 * that employee's contracts.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function contractsByEmployeeKey(): array {
		$out = [];
		foreach ($this->loadAll('EmploymentContract') as $contract) {
			$key = trim((string)($contract['employeeId'] ?? ''));
			if ($key !== '') {
				$out[$key][] = $contract;
			}
		}

		return $out;
	}//end contractsByEmployeeKey()

	/**
	 * The employee's contract covering the period, resolved via the id/slug/
	 * employeeNumber keys, or null when none covers it.
	 *
	 * @param array<string, mixed> $employee The Employee.
	 * @param array<string, array<int, array<string, mixed>>> $contractsByEmployeeKey The contract index.
	 * @param string $period Wage period (YYYY-MM).
	 *
	 * @return array<string, mixed>|null
	 */
	private function coveringContract(array $employee, array $contractsByEmployeeKey, string $period): ?array {
		$keys = array_filter(
			[
				$this->idOf($employee),
				(string)($employee['@self']['slug'] ?? ''),
				trim((string)($employee['employeeNumber'] ?? '')),
			],
			static fn (string $key): bool => $key !== ''
		);

		foreach ($keys as $key) {
			foreach (($contractsByEmployeeKey[$key] ?? []) as $contract) {
				if ($this->coversPeriod((string)($contract['startDate'] ?? ''), (string)($contract['endDate'] ?? ''), $period) === true) {
					return $contract;
				}
			}
		}

		return null;
	}//end coveringContract()

	/**
	 * All open (gemeld) SickLeaveCases, indexed by every employee-reference
	 * key (sick-pay-calc design.md D4, the same id/slug/employeeNumber
	 * convention as `contractsByEmployeeKey()`). Each key maps to the LIST of
	 * that employee's open cases.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 *
	 * @spec openspec/specs/sick-pay-calc/spec.md#REQ-SICK-005
	 */
	private function openSickCasesByEmployeeKey(): array {
		$out = [];
		foreach ($this->loadAll('SickLeaveCase') as $case) {
			if ((string)($case['status'] ?? 'gemeld') !== 'gemeld') {
				continue;
			}

			$key = trim((string)($case['employeeId'] ?? ''));
			if ($key !== '') {
				$out[$key][] = $case;
			}
		}

		return $out;
	}//end openSickCasesByEmployeeKey()

	/**
	 * The employee's open SickLeaveCase covering the period — firstSickDay
	 * on or before the period's last day (sick-pay-calc design.md D4) —
	 * resolved via the id/slug/employeeNumber keys, or null when none
	 * applies (the full-salary path stays unchanged).
	 *
	 * @param array<string, mixed> $employee The Employee.
	 * @param array<string, array<int, array<string, mixed>>> $sickCasesByEmployeeKey The open-case index.
	 * @param string $period Wage period (YYYY-MM).
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/sick-pay-calc/spec.md#REQ-SICK-005
	 */
	private function openSickCaseFor(array $employee, array $sickCasesByEmployeeKey, string $period): ?array {
		$keys = array_filter(
			[
				$this->idOf($employee),
				(string)($employee['@self']['slug'] ?? ''),
				trim((string)($employee['employeeNumber'] ?? '')),
			],
			static fn (string $key): bool => $key !== ''
		);

		foreach ($keys as $key) {
			foreach (($sickCasesByEmployeeKey[$key] ?? []) as $case) {
				// A still-open case has no end date; it "covers" the period
				// once its firstSickDay is on or before the period's last day.
				if ($this->coversPeriod((string)($case['firstSickDay'] ?? ''), '', $period) === true) {
					return $case;
				}
			}
		}

		return null;
	}//end openSickCaseFor()

	/**
	 * Build the SickPayInput for one open case + covering contract
	 * (sick-pay-calc design.md D2/D3/D4): reference = the employee's full
	 * grossMonthlySalary (pre-substitution), aangepastLoon from the case,
	 * percentage from the case, yearOne/firstSickDayInPeriod derived from
	 * firstSickDay vs the period, wachtdag from the case, contract hours for
	 * the WML floor's part-time factor.
	 *
	 * @param array<string, mixed> $case The open SickLeaveCase.
	 * @param array<string, mixed> $contract The covering EmploymentContract.
	 * @param int $grossMonthlySalaryCents The employee's full grossMonthlySalary, in cents (the reference wage).
	 * @param string $period Wage period (YYYY-MM).
	 *
	 * @return SickPayInput
	 *
	 * @spec openspec/specs/sick-pay-calc/spec.md#REQ-SICK-005
	 */
	private function sickPayInputFor(array $case, array $contract, int $grossMonthlySalaryCents, string $period): SickPayInput {
		$firstSickDay = trim((string)($case['firstSickDay'] ?? ''));
		$aangepastLoon = ($case['aangepastLoon'] ?? null);
		$hoursPerWeek = ($contract['hoursPerWeek'] ?? null);

		return new SickPayInput(
			referenceWageCents: $grossMonthlySalaryCents,
			aangepastLoonCents: (is_numeric($aangepastLoon) === true ? (int)round(((float)$aangepastLoon) * 100) : 0),
			loondoorbetalingPercentage: (float)($case['loondoorbetalingPercentage'] ?? 70),
			yearOne: $this->isYearOne($firstSickDay, $period),
			wachtdag: (($case['wachtdag'] ?? false) === true),
			firstSickDayInPeriod: $this->coversPeriod($firstSickDay, $firstSickDay, $period),
			contractHoursPerWeek: (is_numeric($hoursPerWeek) === true ? (float)$hoursPerWeek : self::FULLTIME_HOURS_PER_WEEK),
			fulltimeHoursPerWeek: self::FULLTIME_HOURS_PER_WEEK
		);

	}//end sickPayInputFor()

	/**
	 * Whether the run period falls within the first 52 weeks of firstSickDay
	 * (sick-pay-calc design.md D3) — weeks elapsed between firstSickDay and
	 * the period's first day, floored. An unparseable firstSickDay
	 * defensively yields false (no WML floor rather than a guessed one).
	 *
	 * @param string $firstSickDay ISO-8601 firstSickDay.
	 * @param string $period Wage period (YYYY-MM).
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/sick-pay-calc/spec.md#REQ-SICK-002
	 */
	private function isYearOne(string $firstSickDay, string $period): bool {
		try {
			$periodStart = new DateTimeImmutable($period . '-01');
			$day = new DateTimeImmutable($firstSickDay);
		} catch (\Throwable $e) {
			return false;
		}

		if ($periodStart < $day) {
			// The employee fell sick within (or after) this very period.
			return true;
		}

		$weeksElapsed = intdiv($periodStart->diff($day)->days, 7);

		return $weeksElapsed < self::YEAR_ONE_WEEKS;
	}//end isYearOne()

	/**
	 * The sick-pay Payslip fields to merge onto the payload (sick-pay-calc
	 * design.md D4): all null when no open case applies (a normal payslip
	 * stays byte-identical to the pre-change shape).
	 *
	 * @param array<string, mixed>|null $case The open SickLeaveCase, or null.
	 * @param SickPayResult|null $result The calculator output, or null.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/sick-pay-calc/spec.md#REQ-SICK-005
	 */
	private function sickPayFields(?array $case, ?SickPayResult $result): array {
		if ($case === null || $result === null) {
			return [
				'sickLeaveCaseId' => null,
				'doorbetaaldLoon' => null,
				'wachtdagDeduction' => null,
				'sickPayReferenceWage' => null,
				'sickPayPercentage' => null,
				'sickPayMinimumWageFloor' => null,
				'sickPayYearOne' => null,
			];
		}

		return [
			'sickLeaveCaseId' => $this->idOf($case),
			'doorbetaaldLoon' => $this->euros($result->doorbetaaldLoonCents),
			'wachtdagDeduction' => $this->euros($result->wachtdagDeductionCents),
			'sickPayReferenceWage' => $this->euros($result->referenceWageCents),
			'sickPayPercentage' => $result->appliedPercentage,
			'sickPayMinimumWageFloor' => $this->euros($result->minimumWageFloorCents),
			'sickPayYearOne' => $result->yearOne,
		];

	}//end sickPayFields()

	/**
	 * All open (no `returnedOn`, or `returnedOn` in the future -- resolved
	 * per-period by `coversPeriod()`) AssetAssignments, indexed by every
	 * employee-reference key (fleet-bijtelling, the
	 * `contractsByEmployeeKey()`/`openSickCasesByEmployeeKey()` precedent).
	 * Loads every AssetAssignment regardless of the referenced Asset's
	 * category (laptops/phones/etc. included) -- the category filter is
	 * applied by `openVehicleAssignmentFor()`, not here. Each key maps to the
	 * LIST of that employee's AssetAssignments; period coverage itself is
	 * resolved by `openVehicleAssignmentFor()`. Degrades gracefully to an
	 * empty map when the AssetAssignment schema does not exist yet in the
	 * register.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 *
	 * @spec openspec/specs/fleet-bijtelling/spec.md#REQ-FLEET-003
	 */
	private function openAssetAssignmentsByEmployeeKey(): array {
		$out = [];
		foreach ($this->loadAll('AssetAssignment') as $assignment) {
			$key = trim((string)($assignment['employeeId'] ?? ''));
			if ($key !== '') {
				$out[$key][] = $assignment;
			}
		}

		return $out;
	}//end openAssetAssignmentsByEmployeeKey()

	/**
	 * The employee's AssetAssignment covering the period whose referenced
	 * Asset resolves in `$vehicleAssetsById` (i.e. `category: vehicle` --
	 * design.md REQ-FLEET-003: a laptop/phone/etc. AssetAssignment must never
	 * contribute a bijtelling fold), resolved via the id/slug/employeeNumber
	 * keys (the `coveringContract()`/`openSickCaseFor()` precedent) -- first
	 * match wins; no overlap guard in the MVP (design.md Non-goals). Null
	 * when none covers it -- the bijtelling fold stays a no-op.
	 *
	 * @param array<string, mixed> $employee The Employee.
	 * @param array<string, array<int, array<string, mixed>>> $assetAssignmentsByEmployeeKey The open-assignment index (every category).
	 * @param array<string, array<string, mixed>> $vehicleAssetsById The category: vehicle Asset index -- membership is the category filter.
	 * @param string $period Wage period (YYYY-MM).
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/fleet-bijtelling/spec.md#REQ-FLEET-003
	 */
	private function openVehicleAssignmentFor(array $employee, array $assetAssignmentsByEmployeeKey, array $vehicleAssetsById, string $period): ?array {
		$keys = array_filter(
			[
				$this->idOf($employee),
				(string)($employee['@self']['slug'] ?? ''),
				trim((string)($employee['employeeNumber'] ?? '')),
			],
			static fn (string $key): bool => $key !== ''
		);

		foreach ($keys as $key) {
			foreach (($assetAssignmentsByEmployeeKey[$key] ?? []) as $assignment) {
				if ($this->coversPeriod((string)($assignment['issuedOn'] ?? ''), (string)($assignment['returnedOn'] ?? ''), $period) === false) {
					continue;
				}

				if (isset($vehicleAssetsById[(string)($assignment['assetId'] ?? '')]) === true) {
					return $assignment;
				}
			}
		}

		return null;
	}//end openVehicleAssignmentFor()

	/**
	 * Every Asset whose `category` is `vehicle`, keyed by id (the
	 * `enginePayslipsByEmployeeId()`-adjacent simple by-id index precedent;
	 * hrmq-asset-fleet-merge, renamed from `vehiclesById()` which loaded the
	 * now-retired `Vehicle` schema). Degrades gracefully to an empty map when
	 * the Asset schema does not exist yet in the register.
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/specs/fleet-bijtelling/spec.md#REQ-FLEET-003
	 */
	private function vehicleAssetsById(): array {
		$out = [];
		foreach ($this->loadAll('Asset') as $asset) {
			if ((string)($asset['category'] ?? '') !== 'vehicle') {
				continue;
			}

			$id = $this->idOf($asset);
			if ($id !== '') {
				$out[$id] = $asset;
			}
		}

		return $out;
	}//end vehicleAssetsById()

	/**
	 * The monthly bijtelling for one covering AssetAssignment + its
	 * referenced (category: vehicle) Asset (design.md D3; hrmq-asset-fleet-merge):
	 * `base = listPrice x standardPercent/100` for
	 * `companyCarTaxCategory: standard`, or the two-tier blend
	 * `min(listPrice, evReducedCataloguswaardeCap) x evReducedPercent/100 +
	 * max(0, listPrice - evReducedCataloguswaardeCap) x standardPercent/100`
	 * for `evReducedCapped`; `monthlyBijtellingCents = max(0,
	 * round(base_cents / 12) - employeeContributionCents)` -- floored at
	 * zero, never negative. A dangling/missing Asset reference, or a resolved
	 * Asset whose `listPrice` is not numeric (the non-vehicle-category case:
	 * REQ-AST-001's conditional-required rule guarantees a vehicle Asset
	 * missing this would already be flagged, and every non-vehicle Asset
	 * simply never carries it), defensively yields 0 (never a guessed
	 * figure).
	 *
	 * @param array<string, mixed>|null $asset The referenced (category: vehicle) Asset, or null when dangling.
	 * @param array<string, mixed> $assignment The covering AssetAssignment.
	 * @param TaxTables $tables The tax-year parameter set.
	 *
	 * @return int The monthly bijtelling, in cents (0 when there is no Asset, no numeric listPrice, or no headroom above employeeContribution).
	 *
	 * @spec openspec/specs/fleet-bijtelling/spec.md#REQ-FLEET-003
	 */
	private function bijtellingCentsFor(?array $asset, array $assignment, TaxTables $tables): int {
		if ($asset === null) {
			return 0;
		}

		$listPrice = ($asset['listPrice'] ?? null);
		if (is_numeric($listPrice) === false) {
			return 0;
		}

		$listPriceCents = (int)round(((float)$listPrice) * 100);
		$params = $tables->bijtellingPrivegebruikAuto();

		// Default: the flat standard-percentage bijtelling. The capped-EV
		// category below overrides it; both branches are pure arithmetic, so
		// initialising here is equivalent to the former if/else.
		$baseCents = (($listPriceCents * $params['standardPercent']) / 100);

		if ((string)($asset['companyCarTaxCategory'] ?? '') === 'evReducedCapped') {
			$cap = $params['evReducedCataloguswaardeCapCents'];
			$baseCents = ((min($listPriceCents, $cap) * $params['evReducedPercent']) / 100);
			$baseCents += ((max(0, ($listPriceCents - $cap)) * $params['standardPercent']) / 100);
		}

		$employeeContribution = ($assignment['employeeContribution'] ?? 0);
		$employeeContributionCents = is_numeric($employeeContribution) === true ? (int)round(((float)$employeeContribution) * 100) : 0;

		return max(0, ((int)round($baseCents / 12)) - $employeeContributionCents);
	}//end bijtellingCentsFor()

	/**
	 * The bijtelling + assetAssignmentId Payslip fields to merge onto the
	 * payload (design.md D3; hrmq-asset-fleet-merge): both null when no
	 * covering vehicle AssetAssignment exists -- a normal payslip stays
	 * byte-identical to the pre-change shape. `grossPay` already carries the
	 * bijtelling (it was folded into `grossMonthlySalaryCents` before the
	 * calculator ran, design.md D3) -- this only records the amount and its
	 * source, it does not fold anything further into `nettoPay`.
	 *
	 * @param array<string, mixed>|null $assetAssignment The covering AssetAssignment, or null.
	 * @param int $bijtellingCents The computed monthly bijtelling, in cents.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/fleet-bijtelling/spec.md#REQ-FLEET-003
	 */
	private function bijtellingFields(?array $assetAssignment, int $bijtellingCents): array {
		if ($assetAssignment === null) {
			return ['bijtelling' => null, 'assetAssignmentId' => null];
		}

		return [
			'bijtelling' => $this->euros($bijtellingCents),
			'assetAssignmentId' => $this->idOf($assetAssignment),
		];

	}//end bijtellingFields()

	/**
	 * Re-derive the 30%-ruling tax-free exemption for an employee over the
	 * gross fed to the engine (30-procent-regeling design.md D2/D5, the
	 * `bijtellingCentsFor()` precedent): `min(gross, WNT-aftoppingsgrens.maand)
	 * x thirtyPercentRulingRate / 100`, rounded to the cent -- the SAME
	 * cappedRate formula the pack's `thirtyPercentExemption` binding evaluates,
	 * computed here in PHP over the SAME tvl the calculator saw so the two are
	 * consistent by construction. Returns null when no ruling is granted or the
	 * applied rate is not a positive number, so the Payslip records null rather
	 * than €0,00 for a non-ruling employee.
	 *
	 * @param array<string, mixed> $employee The Employee.
	 * @param int $grossCents The gross fed to the engine (`tvl`), in cents -- post bijtelling/sick-pay substitution.
	 * @param TaxTables $tables The tax-year parameter set.
	 *
	 * @return int|null The exemption in cents, or null when not applicable.
	 *
	 * @spec openspec/specs/30-procent-regeling/spec.md#REQ-30P-003
	 */
	private function thirtyPercentExemptionCentsFor(array $employee, int $grossCents, TaxTables $tables): ?int {
		if (($employee['thirtyPercentRulingGranted'] ?? false) !== true) {
			return null;
		}

		$rate = ($employee['thirtyPercentRulingRate'] ?? null);
		if (is_numeric($rate) === false || ((float)$rate) <= 0.0) {
			return null;
		}

		$capCents = $tables->dertigProcentRegeling()['aftoppingsgrensMaandCents'];

		return (int)round((min($grossCents, $capCents) * ((float)$rate)) / 100);
	}//end thirtyPercentExemptionCentsFor()

	/**
	 * The `thirtyPercentRulingExemption` Payslip field to merge onto the
	 * payload (30-procent-regeling design.md D5): null when no ruling was
	 * applied -- a normal payslip stays byte-identical to the pre-change shape
	 * (the `bijtellingFields()` precedent). `grossPay` already carries the full
	 * (unreduced) gross; this only records the tax-free allowance amount, it
	 * does not fold anything into `nettoPay`.
	 *
	 * @param int|null $exemptionCents The re-derived exemption, in cents, or null when not applicable.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/30-procent-regeling/spec.md#REQ-30P-003
	 */
	private function thirtyPercentRulingFields(?int $exemptionCents): array {
		return [
			'thirtyPercentRulingExemption' => ($exemptionCents === null ? null : $this->euros($exemptionCents)),
		];

	}//end thirtyPercentRulingFields()

	/**
	 * Every employee's summed retro deltas (cents) across `applied`
	 * PayrollAdjustments whose `settlementPeriod` equals this run's period
	 * (retro-adjustments design.md D4) -- the current-run-only folding index.
	 * `draft` adjustments and adjustments settling a different period are
	 * excluded (a draft adjustment affects no run). Degrades gracefully to an
	 * empty map when the PayrollAdjustment schema does not exist yet in the
	 * register (the two changes may land in either order).
	 *
	 * Per employee: `net` (summed `deltaNet`, folded into nettoPay) and the
	 * cost side of the same payout (humaniq#514): `gross` (`deltaGross`),
	 * `loonheffing` (`deltaLoonheffing`) and `employerCharges` (`deltaZvw` +
	 * `deltaWerknemersverzekeringen`). An adjustment without a numeric
	 * `deltaGross` books `deltaNet + deltaLoonheffing` as its gross, so a net
	 * payout never reaches the run without a matching cost.
	 *
	 * @param string $period Wage period (YYYY-MM).
	 *
	 * @return array<string, array{net: int, gross: int, loonheffing: int, employerCharges: int}>
	 *
	 * @spec openspec/specs/retro-adjustments/spec.md#REQ-RETRO-004
	 */
	private function appliedRetroAdjustmentsByEmployeeId(string $period): array {
		$out = [];
		foreach ($this->loadAll('PayrollAdjustment') as $adjustment) {
			if ((string)($adjustment['status'] ?? '') !== 'applied') {
				continue;
			}

			if ((string)($adjustment['settlementPeriod'] ?? '') !== $period) {
				continue;
			}

			$employeeId = trim((string)($adjustment['employeeId'] ?? ''));
			if ($employeeId === '') {
				continue;
			}

			$netCents = $this->centsOrZero($adjustment['deltaNet'] ?? null);
			$loonheffingCents = $this->centsOrZero($adjustment['deltaLoonheffing'] ?? null);
			$grossCents = ($netCents + $loonheffingCents);
			if (is_numeric($adjustment['deltaGross'] ?? null) === true) {
				$grossCents = $this->centsOrZero($adjustment['deltaGross']);
			}

			$chargesCents = ($this->centsOrZero($adjustment['deltaZvw'] ?? null) + $this->centsOrZero($adjustment['deltaWerknemersverzekeringen'] ?? null));

			$current = ($out[$employeeId] ?? self::NO_RETRO_ADJUSTMENT);
			$out[$employeeId] = [
				'net' => ($current['net'] + $netCents),
				'gross' => ($current['gross'] + $grossCents),
				'loonheffing' => ($current['loonheffing'] + $loonheffingCents),
				'employerCharges' => ($current['employerCharges'] + $chargesCents),
			];
		}//end foreach

		return $out;
	}//end appliedRetroAdjustmentsByEmployeeId()

	/**
	 * A stored euro amount as integer cents; anything non-numeric is 0.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return int
	 */
	private function centsOrZero(mixed $value): int {
		if (is_numeric($value) === false) {
			return 0;
		}

		return (int)round(((float)$value) * 100);
	}//end centsOrZero()

	/**
	 * The retroAdjustment + (adjusted) nettoPay Payslip fields to merge onto
	 * the payload (retro-adjustments design.md D4): null/unchanged when no
	 * applied adjustment settles this period for this employee -- a normal
	 * payslip stays byte-identical to the pre-change shape.
	 *
	 * @param int $retroAdjustmentCents The summed applied delta for this employee/period, in cents.
	 * @param int $nettoPayCents The engine-computed nettoPay before folding the adjustment, in cents.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/retro-adjustments/spec.md#REQ-RETRO-004
	 */
	private function retroAdjustmentFields(int $retroAdjustmentCents, int $nettoPayCents): array {
		if ($retroAdjustmentCents === 0) {
			return ['retroAdjustment' => null];
		}

		return [
			'retroAdjustment' => $this->euros($retroAdjustmentCents),
			'nettoPay' => $this->euros($nettoPayCents + $retroAdjustmentCents),
		];

	}//end retroAdjustmentFields()

	/**
	 * Every employee's summed signed settled LeaveTransaction amount (cents)
	 * whose settlementPeriod equals this run's period (leave-buy-sell
	 * design.md D6) -- the current-run-only folding index. A `sell`
	 * contributes a POSITIVE amount (taxable wage added to the gross); a
	 * `buy` contributes a NEGATIVE amount (taken off the gross). Draft/
	 * submitted/approved/rejected transactions and transactions settling a
	 * different period are excluded. Degrades gracefully to an empty map
	 * when the LeaveTransaction schema does not exist yet in the register
	 * (the retro-adjustments cross-change-ordering precedent).
	 *
	 * @param string $period Wage period (YYYY-MM).
	 *
	 * @return array<string, int>
	 *
	 * @spec openspec/specs/leave-buy-sell/spec.md#REQ-BUYSELL-005
	 */
	private function settledLeaveTransactionsByEmployeeId(string $period): array {
		$out = [];
		foreach ($this->loadAll('LeaveTransaction') as $transaction) {
			if ((string)($transaction['status'] ?? '') !== 'settled') {
				continue;
			}

			if ((string)($transaction['settlementPeriod'] ?? '') !== $period) {
				continue;
			}

			$employeeId = trim((string)($transaction['employeeId'] ?? ''));
			if ($employeeId === '') {
				continue;
			}

			$settledAmount = ($transaction['settledAmount'] ?? 0);
			$cents = is_numeric($settledAmount) === true ? (int)round(((float)$settledAmount) * 100) : 0;
			$sign = ((string)($transaction['transactionType'] ?? '')) === 'sell' ? 1 : -1;

			$out[$employeeId] = (($out[$employeeId] ?? 0) + ($sign * $cents));
		}

		return $out;
	}//end settledLeaveTransactionsByEmployeeId()

	/**
	 * The leaveBuySell Payslip field to merge onto the payload (leave-buy-sell
	 * design.md D6, humaniq#513): null when no settled transaction settles
	 * into this period for this employee, so a normal payslip stays
	 * byte-identical to the pre-change shape. The amount itself already
	 * entered the calculator's gross, so `grossPay`, `loonheffing` and
	 * `nettoPay` carry it; this field only names the component. It never
	 * touches `nettoPay`. `settledAmount` is a fact
	 * `LeaveBuySellSettlementService` already computed and stored; this
	 * merely reads it.
	 *
	 * @param int $leaveBuySellCents The summed settled amount for this employee/period, in cents.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/leave-buy-sell/spec.md#REQ-BUYSELL-005
	 */
	private function leaveBuySellFields(int $leaveBuySellCents): array {
		if ($leaveBuySellCents === 0) {
			return ['leaveBuySell' => null];
		}

		return ['leaveBuySell' => $this->euros($leaveBuySellCents)];

	}//end leaveBuySellFields()

	/**
	 * Every `actief` Loonbeslag, indexed by every employee-reference key
	 * (loonbeslag design.md D4, the `contractsByEmployeeKey()`/
	 * `openSickCasesByEmployeeKey()` precedent: a Loonbeslag's `employeeId`
	 * may be a literal id, slug, or employeeNumber string). Each key maps to
	 * the LIST of that employee's `actief` Loonbeslag records; period
	 * coverage and the earliest-`effectiveFrom` tie-break are resolved by
	 * `activeLoonbeslagFor()`, not here.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 *
	 * @spec openspec/specs/loonbeslag/spec.md#REQ-BESLAG-005
	 */
	private function activeLoonbeslagenByEmployeeKey(): array {
		$out = [];
		foreach ($this->loadAll('Loonbeslag') as $loonbeslag) {
			if ((string)($loonbeslag['status'] ?? '') !== 'actief') {
				continue;
			}

			$key = trim((string)($loonbeslag['employeeId'] ?? ''));
			if ($key !== '') {
				$out[$key][] = $loonbeslag;
			}
		}

		return $out;
	}//end activeLoonbeslagenByEmployeeKey()

	/**
	 * The employee's one `actief` Loonbeslag covering the period, resolved
	 * via the id/slug/employeeNumber keys (the `coveringContract()`/
	 * `openSickCaseFor()` precedent), or null when none covers it. When more
	 * than one match resolves across the keys (design.md D4's MVP-scope
	 * exception, machine-checked separately by `nl-loonbeslag-single-active`),
	 * the earliest `effectiveFrom` wins deterministically -- never a silent
	 * drop, never a double deduction.
	 *
	 * @param array<string, mixed> $employee The Employee.
	 * @param array<string, array<int, array<string, mixed>>> $loonbeslagenByEmployeeKey The active-Loonbeslag index.
	 * @param string $period Wage period (YYYY-MM).
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/loonbeslag/spec.md#REQ-BESLAG-005
	 */
	private function activeLoonbeslagFor(array $employee, array $loonbeslagenByEmployeeKey, string $period): ?array {
		$keys = array_filter(
			[
				$this->idOf($employee),
				(string)($employee['@self']['slug'] ?? ''),
				trim((string)($employee['employeeNumber'] ?? '')),
			],
			static fn (string $key): bool => $key !== ''
		);

		$matches = [];
		$seenIds = [];
		foreach ($keys as $key) {
			foreach (($loonbeslagenByEmployeeKey[$key] ?? []) as $loonbeslag) {
				if ($this->coversPeriod((string)($loonbeslag['effectiveFrom'] ?? ''), (string)($loonbeslag['effectiveTo'] ?? ''), $period) === false) {
					continue;
				}

				$id = $this->idOf($loonbeslag);
				if ($id !== '' && isset($seenIds[$id]) === true) {
					// Already matched via a different key (e.g. both id AND
					// employeeNumber resolved the same record) -- never
					// double-count the same Loonbeslag.
					continue;
				}

				if ($id !== '') {
					$seenIds[$id] = true;
				}

				$matches[] = $loonbeslag;
			}
		}

		if ($matches === []) {
			return null;
		}

		usort(
			$matches,
			static function (array $a, array $b): int {
				$aFrom = strtotime((string)($a['effectiveFrom'] ?? ''));
				$bFrom = strtotime((string)($b['effectiveFrom'] ?? ''));
				return (($aFrom === false ? PHP_INT_MAX : $aFrom) <=> ($bFrom === false ? PHP_INT_MAX : $bFrom));
			}
		);

		return $matches[0];
	}//end activeLoonbeslagFor()

	/**
	 * The floor-clamped garnishment deduction (design.md D2, REQ-BESLAG-002):
	 * `min(orderedAmount, max(0, nettoPaySoFar - beslagvrijeVoet))`, cents-
	 * exact. This is the hard rule by construction -- `max(0, ...)` clamps a
	 * would-be-negative headroom to zero (nothing deducted once the employee
	 * has no headroom above the voet from other components) and
	 * `min(orderedAmount, ...)` never deducts more than the order specifies
	 * even when headroom exceeds it, so the result can never push `nettoPay`
	 * below `beslagvrijeVoet`.
	 *
	 * @param array<string, mixed> $loonbeslag The covering `actief` Loonbeslag.
	 * @param int $nettoPaySoFarCents nettoPay after retroAdjustment is folded (the engine net already carries any leaveBuySell), in cents.
	 *
	 * @return int The deduction, in cents (0 when there is no headroom).
	 *
	 * @spec openspec/specs/loonbeslag/spec.md#REQ-BESLAG-002
	 */
	private function loonbeslagDeductionCents(array $loonbeslag, int $nettoPaySoFarCents): int {
		$orderedAmount = ($loonbeslag['orderedAmount'] ?? 0);
		$orderedCents = is_numeric($orderedAmount) === true ? (int)round(((float)$orderedAmount) * 100) : 0;

		$beslagvrijeVoet = ($loonbeslag['beslagvrijeVoet'] ?? 0);
		$voetCents = is_numeric($beslagvrijeVoet) === true ? (int)round(((float)$beslagvrijeVoet) * 100) : 0;

		return min($orderedCents, max(0, ($nettoPaySoFarCents - $voetCents)));
	}//end loonbeslagDeductionCents()

	/**
	 * The loonbeslag + (adjusted) nettoPay Payslip fields to merge onto the
	 * payload (design.md D2/D4): both `loonbeslag`/`loonbeslagId` null when no
	 * `actief` Loonbeslag covers this period -- a normal payslip stays
	 * byte-identical to the pre-change shape. When a Loonbeslag covers the
	 * period but the deduction is zero (no headroom), `loonbeslagId` is still
	 * stamped (the floor check resolves it -- trivially satisfied since
	 * `nettoPay` already sits at or below the voet from other components) but
	 * `loonbeslag`/`nettoPay` are left unchanged, matching the
	 * present-but-zero-is-null convention `retroAdjustment`/`leaveBuySell`
	 * already use.
	 *
	 * @param array<string, mixed>|null $loonbeslag The covering `actief` Loonbeslag, or null.
	 * @param int $deductionCents The floor-clamped deduction, in cents.
	 * @param int $nettoPaySoFarCents nettoPay after retroAdjustment is folded (the engine net already carries any leaveBuySell), in cents.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/loonbeslag/spec.md#REQ-BESLAG-002
	 * @spec openspec/specs/loonbeslag/spec.md#REQ-BESLAG-004
	 */
	private function loonbeslagFields(?array $loonbeslag, int $deductionCents, int $nettoPaySoFarCents): array {
		if ($loonbeslag === null) {
			return ['loonbeslag' => null, 'loonbeslagId' => null];
		}

		if ($deductionCents === 0) {
			return ['loonbeslag' => null, 'loonbeslagId' => $this->idOf($loonbeslag)];
		}

		return [
			'loonbeslag' => $this->euros($deductionCents),
			'loonbeslagId' => $this->idOf($loonbeslag),
			'nettoPay' => $this->euros($nettoPaySoFarCents - $deductionCents),
		];

	}//end loonbeslagFields()

	/**
	 * Whether a start/end date range covers the wage period: startDate on or
	 * before the period's last day AND endDate null/blank or on/after the
	 * period's first day.
	 *
	 * @param string $startDate ISO start date.
	 * @param string $endDate ISO end date, or ''/null-ish while open.
	 * @param string $period Wage period (YYYY-MM).
	 *
	 * @return bool
	 */
	private function coversPeriod(string $startDate, string $endDate, string $period): bool {
		try {
			$periodStart = new DateTimeImmutable($period . '-01');
		} catch (\Throwable $e) {
			return false;
		}

		$periodEnd = $periodStart->modify('last day of this month');

		$start = strtotime(trim($startDate));
		if ($start === false || $start > $periodEnd->getTimestamp()) {
			return false;
		}

		$endDate = trim($endDate);
		if ($endDate === '') {
			return true;
		}

		$end = strtotime($endDate);
		return $end === false || $end >= $periodStart->getTimestamp();
	}//end coversPeriod()

	/**
	 * The contract's Awf tariff for the calculator (`low`/`high`), falling
	 * back to the Wab-derived expectation (permanent + written -> low, else
	 * high) when the field is absent.
	 *
	 * @param array<string, mixed> $contract The covering EmploymentContract.
	 *
	 * @return string `low` or `high`.
	 */
	private function awfTariffFor(array $contract): string {
		$tariff = trim((string)($contract['awfTariff'] ?? ''));
		if (in_array($tariff, ['low', 'high'], true) === true) {
			return $tariff;
		}

		$permanent = ((string)($contract['type'] ?? '') === 'permanent');
		$written = (($contract['writtenContract'] ?? false) === true);
		return ($permanent === true && $written === true) ? 'low' : 'high';
	}//end awfTariffFor()

	/**
	 * A human label for an Employee in outcome reporting.
	 *
	 * @param array<string, mixed> $employee The Employee.
	 *
	 * @return string
	 */
	private function employeeLabel(array $employee): string {
		$name = trim(trim((string)($employee['firstName'] ?? '')) . ' ' . trim((string)($employee['lastName'] ?? '')));
		if ($name !== '') {
			return $name;
		}

		$number = trim((string)($employee['employeeNumber'] ?? ''));
		if ($number !== '') {
			return $number;
		}

		$id = $this->idOf($employee);
		return $id === '' ? 'onbekend' : $id;
	}//end employeeLabel()

	/**
	 * Build the base outcome array.
	 *
	 * @param string $runId The PayrollRun id ('' when unknown).
	 * @param string $period The wage period.
	 * @param string $administrationId The administration.
	 * @param string $status Outcome status (calculated/exists/refused-not-draft/failed).
	 * @param string $message Human-readable outcome message.
	 *
	 * @return array<string, mixed>
	 */
	private function outcome(string $runId, string $period, string $administrationId, string $status, string $message): array {
		return [
			'runId' => ($runId === '' ? null : $runId),
			'period' => $period,
			'administrationId' => $administrationId,
			'status' => $status,
			'message' => $message,
			'computed' => [],
			'skipped' => [],
			'totals' => null,
		];

	}//end outcome()

	/**
	 * Convert integer cents to a euro float rounded to 2 decimals (the
	 * register's number fields are euro-denominated).
	 *
	 * @param int $cents The cents amount.
	 *
	 * @return float
	 */
	private function euros(int $cents): float {
		return round(($cents / 100), 2);
	}//end euros()

	/**
	 * Load all objects of a schema (capped), as plain arrays.
	 *
	 * @param string $schema The schema name.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function loadAll(string $schema): array {
		try {
			$rows = $this->objectService()->setRegister($this->register())->setSchema($schema)->findAll(['limit' => self::LIMIT]);
		} catch (\Throwable $e) {
			$this->logger->warning('PayrollRunService: kon ' . $schema . ' niet laden: ' . $e->getMessage());
			return [];
		}

		$out = [];
		foreach ((is_array($rows) === true ? $rows : []) as $row) {
			$out[] = $this->toArray($row);
		}

		return $out;
	}//end loadAll()

	/**
	 * Normalise an ObjectService row (entity or array) to an array.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed>
	 */
	private function toArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			return (array)$row->jsonSerialize();
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
	private function idOf(array $row): string {
		return (string)($row['id'] ?? $row['@self']['id'] ?? '');
	}//end idOf()

	/**
	 * @return mixed The OpenRegister ObjectService.
	 */
	private function objectService(): mixed {
		// ADR-083: establish availability before reaching. Unguarded, an
		// instance without OpenRegister gets a container exception naming a
		// class the admin has never heard of; guarded, it is told which app to
		// install — which is rule 3's promise that the app still explains
		// itself.
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			throw new RuntimeException(
				'humaniq requires the OpenRegister app, which is not installed on this instance.'
			);
		}

		return $this->container->get('OCA\OpenRegister\Service\ObjectService');
	}//end objectService()

	/**
	 * @return string The configured humaniq register slug.
	 */
	private function register(): string {
		return $this->settingsService->getRegisterSlug();
	}//end register()

}//end class
