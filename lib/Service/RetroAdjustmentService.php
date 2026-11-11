<?php

/**
 * Retro Adjustment Service
 *
 * Computes and settles terugwerkende kracht (TWK) corrections for a SEALED
 * prior-period payslip (design.md D1): the original, already-approved/posted/
 * paid Payslip and its PayrollRun are READ ONLY, never passed to
 * `saveObject()`/`deleteObject()`. `adjustFor()` resolves the stored original
 * Payslip for (originalPeriod, employeeId), recomputes an alternative result
 * with the corrected gross salary using the pure `PayrollCalculator` against
 * `TaxTables::load('nl-{originalYear}')` -- the ORIGINAL period's tax year,
 * never the current year (design.md D2, the same-tax-year MVP boundary) --
 * and stores only the cents-exact DIFFERENCE (recomputed - stored) on a new
 * `PayrollAdjustment` object, upserted idempotently keyed on `(originalPeriod,
 * employeeId, correctionRef)` (design.md D3).
 *
 * Adjustments apply only to originals whose PayrollRun is NOT `draft`
 * (design.md D5) -- a still-draft original is recomputed directly via the
 * existing `humaniq:payroll:run --recalculate` engine path; there is nothing for
 * this service to correct.
 *
 * The computed delta settles into the CURRENT open period, never the
 * historical one (design.md D4): `settlementPeriod` defaults to the most
 * recent open draft PayrollRun's period when not given explicitly, and only
 * once `--apply` flips `status: draft -> applied` does
 * `PayrollRunService.generate()` fold the delta into that period's payslip.
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
 * @spec openspec/specs/retro-adjustments/spec.md#REQ-RETRO-001
 * @spec openspec/specs/retro-adjustments/spec.md#REQ-RETRO-002
 * @spec openspec/specs/retro-adjustments/spec.md#REQ-RETRO-003
 * @spec openspec/specs/retro-adjustments/spec.md#REQ-RETRO-005
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCA\Humaniq\Payroll\AwfTariffResolver;
use OCA\Humaniq\Payroll\CalculationInput;
use OCA\Humaniq\Payroll\PayrollCalculator;
use OCA\Humaniq\Payroll\TaxTables;
use OCA\Humaniq\Support\ConvertsRowsToArrays;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use OCA\Humaniq\Support\ResolvesObjectService;
use OCA\Humaniq\Support\LoadsAllRows;
use OCA\Humaniq\Support\ChecksPeriodCoverage;
use OCA\Humaniq\Support\ReadsEmployeeKeys;

/**
 * Computes + settles TWK delta corrections against sealed payslips.
 *
 * @spec openspec/specs/retro-adjustments/spec.md#REQ-RETRO-001
 */
class RetroAdjustmentService {

	use ConvertsRowsToArrays;
	use ResolvesObjectService;
	use LoadsAllRows;
	use ChecksPeriodCoverage;
	use ReadsEmployeeKeys;

	/**
	 * Max objects loaded per type.
	 *
	 * @var int
	 */
	private const LIMIT = 10000;

	/**
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param SettingsService $settingsService Register slug.
	 * @param EmployerPremiumSettings $premiums The employer's Aof tariff and Whk percentage.
	 * @param PayrollCalculator $calculator The pure gross-to-net calculator (reused, never modified).
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SettingsService $settingsService,
		private readonly EmployerPremiumSettings $premiums,
		private readonly PayrollCalculator $calculator,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Compute (and, with `$apply`, settle) a TWK correction -- the occ
	 * `humaniq:payroll:adjust` entry point (design.md D3/D4/D5).
	 *
	 * @param RetroCorrection $correction The period, employee and reference, and the corrected gross (reused from an existing adjustment when null).
	 * @param string|null $correctionType Free-text correction classification.
	 * @param string|null $settlementPeriod The period to settle into, or null to auto-resolve the open draft run's period.
	 * @param bool $apply Whether to flip `status: draft -> applied` and stamp `settlementPayrollRunId`.
	 *
	 * @return array<string, mixed> Outcome: {adjustmentId, originalPeriod, employeeId, correctionRef, status, message, idempotent, delta, engineVersion, settlementPeriod, settlementLine}.
	 *
	 * @spec openspec/specs/retro-adjustments/spec.md#REQ-RETRO-001
	 * @spec openspec/specs/retro-adjustments/spec.md#REQ-RETRO-002
	 * @spec openspec/specs/retro-adjustments/spec.md#REQ-RETRO-003
	 * @spec openspec/specs/retro-adjustments/spec.md#REQ-RETRO-005
	 */
	public function adjustFor(
		RetroCorrection $correction,
		?string $correctionType = null,
		?string $settlementPeriod = null,
		bool $apply = false,
	): array {
		$correction = $correction->trimmed();

		$refusal = $this->correctionRefusal(correction: $correction);
		if ($refusal !== null) {
			return $this->outcome('', $correction, 'failed', $refusal);
		}

		// Probe-before-create idempotency (design.md D3): the SAME
		// (originalPeriod, employeeId, correctionRef) always resolves to the
		// SAME PayrollAdjustment.
		$existing = $this->findAdjustment(correction: $correction);
		$correction = $correction->withGross($this->grossToUse(correction: $correction, existing: $existing));
		$gross = $correction->correctedGrossMonthlySalary;
		if ($gross === null || $gross <= 0.0) {
			return $this->outcome($this->idOf($existing ?? []), $correction, 'corrected-gross-required', 'Een gecorrigeerd bruto maandsalaris (--gross) is verplicht bij de eerste berekening.');
		}

		$delta = $this->computeDelta($correction->originalPeriod, $correction->employeeId, $gross);
		if ($delta['status'] !== 'ok') {
			return $this->outcome($this->idOf($existing ?? []), $correction, $delta['status'], $delta['message']);
		}

		$resolvedSettlementPeriod = $this->resolveSettlementPeriod($settlementPeriod);
		if ($resolvedSettlementPeriod === null) {
			return $this->outcome($this->idOf($existing ?? []), $correction, 'settlement-period-required', 'Kon geen concept-loonrun vinden om de correctie in te verwerken -- geef --settlement-period expliciet op.');
		}

		return $this->settle(
			correction: $correction,
			computed: [
				'existing' => $existing,
				'delta' => $delta,
				'settlementPeriod' => $resolvedSettlementPeriod,
				'settlementLine' => ($delta['deltaEuros']['net'] < 0.0) ? 'terugvordering' : 'nabetaling',
				'correctionType' => $correctionType ?? ($existing['correctionType'] ?? null),
				'apply' => $apply,
			]
		);
	}//end adjustFor()

	/**
	 * Why a correction cannot be computed, or null when it can.
	 *
	 * @param RetroCorrection $correction The trimmed correction.
	 *
	 * @return string|null The refusal message.
	 */
	private function correctionRefusal(RetroCorrection $correction): ?string {
		if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $correction->originalPeriod) !== 1) {
			return 'Ongeldige originele periode "' . $correction->originalPeriod . '" (verwacht JJJJ-MM).';
		}

		if ($correction->employeeId === '' || $correction->correctionRef === '') {
			return 'employeeId en correctionRef zijn verplicht.';
		}

		return null;
	}//end correctionRefusal()

	/**
	 * The corrected gross to compute on: the requested one, else the one the
	 * existing adjustment stored.
	 *
	 * @param RetroCorrection $correction The correction.
	 * @param array<string, mixed>|null $existing The PayrollAdjustment of the same key, or null.
	 *
	 * @return float|null
	 */
	private function grossToUse(RetroCorrection $correction, ?array $existing): ?float {
		$gross = $correction->correctedGrossMonthlySalary;
		if ($gross === null && $existing !== null && is_numeric($existing['correctedGrossMonthlySalary'] ?? null) === true) {
			$gross = (float)$existing['correctedGrossMonthlySalary'];
		}

		return $gross;
	}//end grossToUse()

	/**
	 * Store a computed correction, applied or as a draft, and report it.
	 *
	 * @param RetroCorrection $correction The trimmed correction with the gross it was computed on.
	 * @param array{existing: array<string, mixed>|null, delta: array<string, mixed>, settlementPeriod: string, settlementLine: string, correctionType: string|null, apply: bool} $computed The computed correction.
	 *
	 * @return array<string, mixed> Outcome (see adjustFor()).
	 *
	 * @spec openspec/specs/retro-adjustments/spec.md#REQ-RETRO-005
	 */
	private function settle(RetroCorrection $correction, array $computed): array {
		$existing = $computed['existing'];
		$apply = $computed['apply'];
		$payload = $this->payload(correction: $correction, computed: $computed);
		if ($apply === true) {
			$payload['status'] = 'applied';
			$payload['settlementPayrollRunId'] = $this->resolveRunForPeriod($computed['settlementPeriod'], (string)($computed['delta']['administrationId'] ?? ''));
		}

		try {
			$saved = $this->toArray(
				$this->objectService()->saveObject(
					object: $payload,
					register: $this->register(),
					schema: 'PayrollAdjustment',
					uuid: ($existing !== null ? ($this->idOf($existing) === '' ? null : $this->idOf($existing)) : null),
					_rbac: false,
					_multitenancy: false
				)
			);
		} catch (\Throwable $e) {
			$this->logger->error('RetroAdjustmentService: kon PayrollAdjustment niet opslaan: ' . $e->getMessage());
			return $this->outcome($this->idOf($existing ?? []), $correction, 'failed', 'Opslaan van de correctie is mislukt: ' . $e->getMessage());
		}

		$status = ($apply === true) ? 'applied' : 'computed';

		$outcome = $this->outcome($this->idOf($saved), $correction, $status, ($apply === true ? 'Correctie berekend en toegepast.' : 'Correctie berekend.'));
		$outcome['idempotent'] = ($existing !== null);
		$outcome['delta'] = $computed['delta']['deltaEuros'];
		$outcome['engineVersion'] = $computed['delta']['engineVersion'];
		$outcome['settlementPeriod'] = $computed['settlementPeriod'];
		$outcome['settlementLine'] = $computed['settlementLine'];

		return $outcome;
	}//end settle()

	/**
	 * The PayrollAdjustment object of a computed correction, as a draft.
	 *
	 * @param RetroCorrection $correction The trimmed correction with the gross it was computed on.
	 * @param array{existing: array<string, mixed>|null, delta: array<string, mixed>, settlementPeriod: string, settlementLine: string, correctionType: string|null, apply: bool} $computed The computed correction.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/retro-adjustments/spec.md#REQ-RETRO-002
	 */
	private function payload(RetroCorrection $correction, array $computed): array {
		$existing = $computed['existing'];
		$delta = $computed['delta'];
		$deltaEuros = $delta['deltaEuros'];

		return [
			'employeeId' => $correction->employeeId,
			'originalPayrollRunId' => $delta['originalPayrollRunId'],
			'originalPayslipId' => $delta['originalPayslipId'],
			'originalPeriod' => $correction->originalPeriod,
			'correctionType' => $computed['correctionType'],
			'correctedGrossMonthlySalary' => $correction->correctedGrossMonthlySalary,
			'correctionRef' => $correction->correctionRef,
			'deltaGross' => $deltaEuros['gross'],
			'deltaLoonheffing' => $deltaEuros['loonheffing'],
			'deltaNet' => $deltaEuros['net'],
			'deltaWerknemersverzekeringen' => $deltaEuros['werknemersverzekeringen'],
			'deltaZvw' => $deltaEuros['zvw'],
			'deltaVolksverzekeringen' => $deltaEuros['volksverzekeringen'],
			'deltaVakantiegeldReserved' => $deltaEuros['vakantiegeldReserved'],
			'engineVersion' => $delta['engineVersion'],
			'settlementPeriod' => $computed['settlementPeriod'],
			'settlementLine' => $computed['settlementLine'],
			'status' => ($existing['status'] ?? 'draft'),
			'settlementPayrollRunId' => ($existing['settlementPayrollRunId'] ?? null),
			'calculatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
		];
	}//end payload()

	/**
	 * Recompute one existing PayrollAdjustment in place -- the guarded
	 * endpoint's "Herrekenen" entry point (design.md D8). Refuses when the
	 * adjustment is already `applied` (design.md D5/REQ-RETRO-007) -- the
	 * controller has already RBAC-resolved the adjustment; this re-fetches it
	 * unscoped, applies the applied-guard, and delegates to `adjustFor()`
	 * with its own stored fields (so the SAME object is updated, never
	 * duplicated).
	 *
	 * @param string $adjustmentId The PayrollAdjustment id.
	 *
	 * @return array<string, mixed> Outcome (see adjustFor()).
	 *
	 * @spec openspec/specs/retro-adjustments/spec.md#REQ-RETRO-007
	 */
	public function recomputeAdjustment(string $adjustmentId): array {
		$adjustment = $this->findAdjustmentById($adjustmentId);
		if ($adjustment === null) {
			return $this->outcome($adjustmentId, new RetroCorrection('', '', ''), 'failed', 'Correctie niet gevonden.');
		}

		$correctedGross = ($adjustment['correctedGrossMonthlySalary'] ?? null);
		$correction = new RetroCorrection(
			originalPeriod: (string)($adjustment['originalPeriod'] ?? ''),
			employeeId: (string)($adjustment['employeeId'] ?? ''),
			correctionRef: (string)($adjustment['correctionRef'] ?? ''),
			correctedGrossMonthlySalary: (is_numeric($correctedGross) === true ? (float)$correctedGross : null)
		);
		if ((string)($adjustment['status'] ?? '') === 'applied') {
			return $this->outcome($adjustmentId, $correction, 'refused-applied', 'Correctie is al toegepast -- herrekenen is niet meer mogelijk.');
		}

		return $this->adjustFor(
			$correction,
			(($adjustment['correctionType'] ?? null) !== null ? (string)$adjustment['correctionType'] : null),
			(string)($adjustment['settlementPeriod'] ?? ''),
			false
		);

	}//end recomputeAdjustment()

	/**
	 * The delta-computation core, shared by `adjustFor()` and
	 * `recomputeAdjustment()` (design.md D1/D2): resolves the sealed original
	 * Payslip and its PayrollRun (READ ONLY -- never written), refuses a
	 * still-draft original (D5), recomputes with the corrected gross against
	 * the ORIGINAL period's tax year (D2), and diffs recomputed - stored into
	 * cents-exact euro deltas.
	 *
	 * @param string $originalPeriod Wage period being corrected, `YYYY-MM`.
	 * @param string $employeeId The Employee id.
	 * @param float $correctedGrossMonthlySalary Corrected gross monthly salary (euro).
	 *
	 * @return array<string, mixed> {status, message, deltaEuros, engineVersion, originalPayrollRunId, originalPayslipId, administrationId}.
	 *
	 * @spec openspec/specs/retro-adjustments/spec.md#REQ-RETRO-001
	 * @spec openspec/specs/retro-adjustments/spec.md#REQ-RETRO-002
	 * @spec openspec/specs/retro-adjustments/spec.md#REQ-RETRO-005
	 */
	private function computeDelta(string $originalPeriod, string $employeeId, float $correctedGrossMonthlySalary): array {
		$payslip = $this->resolveOriginalPayslip($originalPeriod, $employeeId);
		if ($payslip === null) {
			return ['status' => 'failed', 'message' => 'Geen gesealde loonstrook gevonden voor deze medewerker/periode.'];
		}

		$runId = trim((string)($payslip['payrollRunId'] ?? ''));
		$run = ($runId === '' ? null : $this->findRunById($runId));
		if ($run === null) {
			return ['status' => 'failed', 'message' => 'De loonrun van de originele loonstrook kon niet worden gevonden.'];
		}

		$status = (string)($run['status'] ?? '');
		if ($status === 'draft') {
			return ['status' => 'refused-original-draft', 'message' => 'De originele loonrun heeft nog status "draft" -- herbereken deze direct via humaniq:payroll:run --recalculate.'];
		}

		$tableId = 'nl-' . substr($originalPeriod, 0, 4);
		try {
			$tables = TaxTables::load($tableId);
		} catch (\Throwable $e) {
			return [
				'status' => 'historical-tables-missing',
				'message' => 'historical-tables-missing: herrekenen van ' . $originalPeriod . ' vereist ' . $tableId . '.json -- same-tax-year MVP, multi-jaar is een vervolgwijziging (retro-multi-year-tables).',
			];
		}

		$employee = $this->findEmployeeById($employeeId);
		if ($employee === null) {
			return ['status' => 'failed', 'message' => 'Medewerker niet gevonden.'];
		}

		$taxTableColor = trim((string)($employee['taxTableColor'] ?? ''));
		if (in_array($taxTableColor, ['wit', 'groen'], true) === false) {
			return ['status' => 'failed', 'message' => 'Medewerker heeft geen geldige NL tabelkleur (wit/groen).'];
		}

		$contract = $this->coveringContract($employee, $originalPeriod);
		if ($contract === null) {
			return ['status' => 'failed', 'message' => 'Geen contract gevonden dat de originele periode dekt.'];
		}

		$aofTariff = $this->premiums->getPayrollAofTariff();
		$whkPercentage = $this->premiums->getPayrollWhkPercentage($tables->werknemersverzekeringen()['whkDefault']);

		$input = CalculationInput::fromDecoded([
			'grossMonthlySalaryCents' => (int)round($correctedGrossMonthlySalary * 100),
			'taxTableColor' => $taxTableColor,
			'loonheffingskortingToegepast' => (($employee['loonheffingskortingToegepast'] ?? true) === true),
			'dateOfBirth' => (($employee['dateOfBirth'] ?? null) !== null ? (string)$employee['dateOfBirth'] : null),
			'period' => $originalPeriod,
			'awfTariff' => $this->awfTariffFor($contract, $employee, $payslip, $originalPeriod),
			'aofTariff' => $aofTariff,
			'whkPercentage' => $whkPercentage,
		]);

		$result = $this->calculator->calculate($input, $tables);

		$storedCents = [
			'gross' => $this->centsOf($payslip, 'grossPay'),
			'loonheffing' => $this->centsOf($payslip, 'loonheffing'),
			'net' => $this->centsOf($payslip, 'nettoPay'),
			'werknemersverzekeringen' => $this->centsOf($payslip, 'werknemersverzekeringen'),
			'zvw' => $this->centsOf($payslip, 'zvw'),
			'volksverzekeringen' => $this->centsOf($payslip, 'volksverzekeringen'),
			'vakantiegeldReserved' => $this->centsOf($payslip, 'vakantiegeldReserved'),
		];

		$deltaCents = [
			'gross' => ($result->grossPayCents - $storedCents['gross']),
			'loonheffing' => ($result->loonheffingCents - $storedCents['loonheffing']),
			'net' => ($result->nettoPayCents - $storedCents['net']),
			'werknemersverzekeringen' => ($result->werknemersverzekeringenCents - $storedCents['werknemersverzekeringen']),
			'zvw' => ($result->zvwCents - $storedCents['zvw']),
			'volksverzekeringen' => ($result->volksverzekeringenCents - $storedCents['volksverzekeringen']),
			'vakantiegeldReserved' => ($result->vakantiegeldReservedCents - $storedCents['vakantiegeldReserved']),
		];

		$deltaEuros = [];
		foreach ($deltaCents as $key => $cents) {
			$deltaEuros[$key] = $this->euros($cents);
		}

		return [
			'status' => 'ok',
			'message' => '',
			'deltaEuros' => $deltaEuros,
			'engineVersion' => $tables->id(),
			'originalPayrollRunId' => $runId,
			'originalPayslipId' => $this->idOf($payslip),
			'administrationId' => (string)($run['administrationId'] ?? ''),
		];

	}//end computeDelta()

	/**
	 * The stored, sealed engine Payslip for (originalPeriod, employeeId) --
	 * READ ONLY (design.md D1). Only engine-produced payslips (non-null
	 * `payrollRunId`) are resolved -- hand-entered payslips carry no
	 * traceable run to check for sealing.
	 *
	 * @param string $originalPeriod Wage period, `YYYY-MM`.
	 * @param string $employeeId The Employee id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function resolveOriginalPayslip(string $originalPeriod, string $employeeId): ?array {
		foreach ($this->loadAll('Payslip') as $payslip) {
			if ((string)($payslip['period'] ?? '') !== $originalPeriod) {
				continue;
			}

			if ((string)($payslip['employeeId'] ?? '') !== $employeeId) {
				continue;
			}

			if (trim((string)($payslip['payrollRunId'] ?? '')) === '') {
				continue;
			}

			return $payslip;
		}

		return null;
	}//end resolveOriginalPayslip()

	/**
	 * The PayrollRun by id, unscoped (design.md D5's draft-status probe).
	 *
	 * @param string $runId The PayrollRun id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function findRunById(string $runId): ?array {
		foreach ($this->loadAll('PayrollRun') as $run) {
			if ($this->idOf($run) === $runId) {
				return $run;
			}
		}

		return null;
	}//end findRunById()

	/**
	 * The Employee by id.
	 *
	 * @param string $employeeId The Employee id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function findEmployeeById(string $employeeId): ?array {
		foreach ($this->loadAll('Employee') as $employee) {
			if ($this->idOf($employee) === $employeeId) {
				return $employee;
			}
		}

		return null;
	}//end findEmployeeById()

	/**
	 * The employee's contract covering the original period (the
	 * PayrollRunService id/slug/employeeNumber resolution precedent).
	 *
	 * @param array<string, mixed> $employee The Employee.
	 * @param string $originalPeriod Wage period, `YYYY-MM`.
	 *
	 * @return array<string, mixed>|null
	 */
	private function coveringContract(array $employee, string $originalPeriod): ?array {
		$keys = $this->employeeKeys($employee);

		foreach ($this->loadAll('EmploymentContract') as $contract) {
			$key = trim((string)($contract['employeeId'] ?? ''));
			if (in_array($key, $keys, true) === false) {
				continue;
			}

			if ($this->coversPeriod((string)($contract['startDate'] ?? ''), (string)($contract['endDate'] ?? ''), $originalPeriod) === true) {
				return $contract;
			}
		}

		return null;
	}//end coveringContract()

	/**
	 * The Awf tariff of the corrected period: what the run charged when the
	 * original payslip says so, otherwise the shared resolution (contract,
	 * BBL, young part-timer with the payslip's paid hours) the run uses
	 * (filings-premium-differentiation D1).
	 *
	 * @param array<string, mixed> $contract       The covering EmploymentContract.
	 * @param array<string, mixed> $employee       The Employee.
	 * @param array<string, mixed> $payslip        The sealed original payslip.
	 * @param string               $originalPeriod The corrected period.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/awf-premium-review/spec.md#REQ-AWF-101
	 */
	private function awfTariffFor(array $contract, array $employee, array $payslip, string $originalPeriod): string {
		$stamped = (string)($payslip['awfTariff'] ?? '');
		if (in_array($stamped, ['low', 'high'], true) === true) {
			return $stamped;
		}

		$paidHours = (is_numeric($payslip['hoursWorked'] ?? null) === true ? ((float)$payslip['hoursWorked'] + (float)($payslip['overtimeHours'] ?? 0.0)) : null);
		$dateOfBirth = (($employee['dateOfBirth'] ?? null) !== null ? (string)$employee['dateOfBirth'] : null);
		return AwfTariffResolver::resolve($contract, $dateOfBirth, $originalPeriod, $paidHours)['tariff'];
	}//end awfTariffFor()

	/**
	 * The existing PayrollAdjustment for (originalPeriod, employeeId,
	 * correctionRef), or null -- the idempotency probe (design.md D3).
	 *
	 * @param RetroCorrection $correction The period, employee and reference.
	 *
	 * @return array<string, mixed>|null
	 */
	private function findAdjustment(RetroCorrection $correction): ?array {
		foreach ($this->loadAll('PayrollAdjustment') as $adjustment) {
			if ((string)($adjustment['originalPeriod'] ?? '') === $correction->originalPeriod
				&& (string)($adjustment['employeeId'] ?? '') === $correction->employeeId
				&& (string)($adjustment['correctionRef'] ?? '') === $correction->correctionRef
			) {
				return $adjustment;
			}
		}

		return null;
	}//end findAdjustment()

	/**
	 * The PayrollAdjustment by id, unscoped -- `recomputeAdjustment()`'s
	 * entry resolve (the `PayrollRunService::recalculateRun()` precedent).
	 *
	 * @param string $adjustmentId The PayrollAdjustment id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function findAdjustmentById(string $adjustmentId): ?array {
		foreach ($this->loadAll('PayrollAdjustment') as $adjustment) {
			if ($this->idOf($adjustment) === $adjustmentId) {
				return $adjustment;
			}
		}

		return null;
	}//end findAdjustmentById()

	/**
	 * Resolve the settlement period: the given value when non-blank, else
	 * the most recent open draft PayrollRun's period (design.md D4's
	 * "current open period" default). Null when neither resolves.
	 *
	 * @param string|null $given The caller-supplied settlement period, or null.
	 *
	 * @return string|null
	 */
	private function resolveSettlementPeriod(?string $given): ?string {
		$given = trim((string)$given);
		if ($given !== '') {
			return $given;
		}

		$latest = null;
		foreach ($this->loadAll('PayrollRun') as $run) {
			if ((string)($run['status'] ?? '') !== 'draft') {
				continue;
			}

			$period = (string)($run['period'] ?? '');
			if ($period === '') {
				continue;
			}

			if ($latest === null || $period > $latest) {
				$latest = $period;
			}
		}

		return $latest;
	}//end resolveSettlementPeriod()

	/**
	 * The PayrollRun of a given (period, administrationId), any status --
	 * used only to stamp `settlementPayrollRunId` when applying (design.md
	 * D4). Not required to exist.
	 *
	 * @param string $period Wage period, `YYYY-MM`.
	 * @param string $administrationId The administration.
	 *
	 * @return string|null
	 */
	private function resolveRunForPeriod(string $period, string $administrationId): ?string {
		foreach ($this->loadAll('PayrollRun') as $run) {
			if ((string)($run['period'] ?? '') === $period
				&& (string)($run['administrationId'] ?? '') === $administrationId
			) {
				return ($this->idOf($run) === '' ? null : $this->idOf($run));
			}
		}

		return null;
	}//end resolveRunForPeriod()

	/**
	 * Build the base outcome array.
	 *
	 * @param string $adjustmentId The PayrollAdjustment id ('' when unknown).
	 * @param RetroCorrection $correction The period, employee and reference.
	 * @param string $status Outcome status.
	 * @param string $message Human-readable outcome message.
	 *
	 * @return array<string, mixed>
	 */
	private function outcome(string $adjustmentId, RetroCorrection $correction, string $status, string $message): array {
		return [
			'adjustmentId' => ($adjustmentId === '' ? null : $adjustmentId),
			'originalPeriod' => $correction->originalPeriod,
			'employeeId' => $correction->employeeId,
			'correctionRef' => $correction->correctionRef,
			'status' => $status,
			'message' => $message,
			'idempotent' => false,
			'delta' => null,
			'engineVersion' => null,
			'settlementPeriod' => null,
			'settlementLine' => null,
		];

	}//end outcome()

	/**
	 * A field's value converted to integer cents (round-half-away-from-zero),
	 * defensively 0 for a missing/non-numeric value.
	 *
	 * @param array<string, mixed> $o Object.
	 * @param string $key Field.
	 *
	 * @return int
	 */
	private function centsOf(array $o, string $key): int {
		$value = ($o[$key] ?? null);
		return is_numeric($value) === true ? (int)round(((float)$value) * 100) : 0;
	}//end centsOf()

	/**
	 * Convert integer cents to a euro float rounded to 2 decimals.
	 *
	 * @param int $cents The cents amount.
	 *
	 * @return float
	 */
	private function euros(int $cents): float {
		return round(($cents / 100), 2);
	}//end euros()

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
	 * @return string The configured humaniq register slug.
	 */
	private function register(): string {
		return $this->settingsService->getRegisterSlug();
	}//end register()
}//end class
