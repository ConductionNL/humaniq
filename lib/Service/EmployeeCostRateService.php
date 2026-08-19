<?php

/**
 * Employee Cost Rate Service
 *
 * Resolves what one hour of an employee's time COSTS THE EMPLOYER — the
 * number a domain app multiplies by logged hours to get a cost allocation
 * (ADR-081 decision 4).
 *
 * This is not a wage. `EmploymentContract.hourlyWage` is the employee's gross
 * hourly wage; the employer additionally carries the werknemersverzekeringen
 * (Awf, Aof, Wko, Whk) and the Zvw werkgeversheffing. Costing case work at the
 * gross wage understates the real cost by the whole employer burden, which is
 * exactly the mistake this service exists to prevent — so `hourlyWage` is
 * never used as a rate.
 *
 * The rate is a SUM, never a branch:
 *
 *     hourlyCost = wageCost + Σ additions
 *
 * `wageCost` comes from one of two sources, in priority order:
 *
 * 1. **Override** — `Employee.hourlyCostRateOverrideCents`, when set. Used
 *    verbatim. An override with no reason is refused rather than silently
 *    accepted: this number reaches a statutory IV3 submission through
 *    Shillinq, and an unexplained hand-entered rate is unauditable.
 * 2. **Derived from the CONTRACT** — a proforma payroll calculation over the
 *    contract's own terms, via {@see ProformaPayslipService}: what this
 *    employment costs per hour in a plain month, employer charges included.
 *
 * **Why the contract and not an actual payslip.** A payslip is a period
 * artefact and legitimately differs month to month for reasons that have
 * nothing to do with the cost of an hour: sickness and doorbetaald loon, a
 * bonus or bijzondere beloning, retro-adjustments, leave buy/sell, loonbeslag,
 * bijtelling, a 13th month. Costing case work off whichever payslip happened
 * to be last makes the same hour cost different amounts depending on when it
 * was logged, and makes the figure irreproducible after the fact. The contract
 * is stable, reproducible and is what the employment actually commits to.
 *
 * That choice also removes a correctness trap rather than guarding it. A
 * payslip divides TOTAL pay by TOTAL hours, so an overtime premium paid in
 * that period is already averaged into every hour of the rate — and adding an
 * overtime addition on top would charge it twice. A proforma over the contract
 * contains no overtime, no one-offs and no sick pay, so the base is clean by
 * construction and an overtime addition is legitimate.
 *
 * `additions` are the per-hour employer costs beyond the wage — overhead,
 * equipment, workplace, overtime. Each is named, amounted and justified, so a
 * cost figure can be explained line by line. They arrive either stored on the
 * Employee (an administrator filled them in) or passed in by a caller that
 * computes them — Shillinq derives the overhead and equipment ones from the
 * ledger those pools live in.
 *
 * Returns null rather than guessing when there is nothing to derive from. A
 * null rate is a visible "cannot cost this yet" state for the caller to
 * surface; a zero or a fabricated default would book a case as free.
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
 */

declare(strict_types=1);

namespace OCA\Hrmq\Service;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves an employee's employer cost per hour, in integer cents.
 */
class EmployeeCostRateService {

	/**
	 * Weeks in a year, for turning contracted hours-per-week into the hours
	 * behind one proforma month. 52 whole weeks, not 52.18 — a payroll month
	 * is an administrative twelfth of a year, not an astronomical one.
	 *
	 * @var float
	 */
	private const WEEKS_PER_YEAR = 52.0;

	/**
	 * Months in a year.
	 *
	 * @var int
	 */
	private const MONTHS_PER_YEAR = 12;

	/**
	 * Rate came from a hand-set override.
	 *
	 * @var string
	 */
	public const SOURCE_OVERRIDE = 'override';

	/**
	 * Rate was derived from a proforma calculation over the contract.
	 *
	 * @var string
	 */
	public const SOURCE_CONTRACT = 'contract-proforma';

	/**
	 * The addition key reserved for the overtime uplift. Named as a constant
	 * because it is the one key with a correctness rule attached: it must not
	 * be combined with a wage base that already blends overtime.
	 *
	 * @var string
	 */
	public const ADDITION_OVERTIME = 'overtime';

	/**
	 * @param ProformaPayslipService $proforma Stateless proforma calculator — persists nothing.
	 * @param HourlyCostAdditions $additions Validates and totals the per-hour additions.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ProformaPayslipService $proforma,
		private readonly HourlyCostAdditions $additions,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Refuse an overtime addition on a wage base that already blends overtime.
	 *
	 * Delegates to {@see HourlyCostAdditions::assertCompatible()}; kept on the
	 * service because the composition rule belongs to whoever composes a rate,
	 * and the bridge composes them too — a precondition only one caller can
	 * reach is a precondition that eventually gets bypassed.
	 *
	 * @param array<int, array<string, mixed>> $additions Normalised additions.
	 * @param bool $wageBaseBlendsOvertime Whether the base already includes overtime pay.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When both are true.
	 */
	public function assertAdditionsCompatible(array $additions, bool $wageBaseBlendsOvertime): void {
		$this->additions->assertCompatible(
			additions: $additions,
			wageBaseBlendsOvertime: $wageBaseBlendsOvertime
		);

	}//end assertAdditionsCompatible()

	/**
	 * Resolve the employer cost per hour for one employee.
	 *
	 * The caller supplies the already-loaded Employee and its active
	 * EmploymentContract, so this cannot silently pick a different contract
	 * than the caller believes it is using.
	 *
	 * @param array<string, mixed> $employee The Employee object as an array.
	 * @param array<string, mixed>|null $contract The active EmploymentContract as an array.
	 * @param string $period Costing period as `YYYY-MM`, for the tax year the proforma runs against.
	 * @param array<int, array<string, mixed>> $extraAdditions Additions supplied by a caller that computes them — Shillinq's ledger-derived overhead/equipment. Merged with the employee's own stored additions.
	 *
	 * @return array{
	 *     totalCentsPerHour: int, wageCostCents: int, wageSource: string, wageBasis: string,
	 *     wageBaseBlendsOvertime: bool, additions: array<int, array{key: string, centsPerHour: int, source: string, basis: string}>
	 * }|null Null when no wage base can be established — additions alone are
	 *        never a cost rate, because an hour with overhead but no wage is
	 *        not an hour anyone worked.
	 *
	 * @throws InvalidArgumentException When an override amount is set without a reason, an addition
	 *                                  carries no basis, or an overtime addition is applied to an
	 *                                  overtime-blended base.
	 */
	public function resolve(
		array $employee,
		?array $contract = null,
		string $period = '',
		array $extraAdditions = [],
	): ?array {
		$wage = $this->resolveOverride(employee: $employee);
		if ($wage === null) {
			$wage = $this->deriveFromContract(employee: $employee, contract: $contract, period: $period);
		}

		if ($wage === null) {
			return null;
		}

		$additions = $this->additions->normalise(
			raw: array_merge(
				(array)($employee['hourlyCostAdditions'] ?? []),
				$extraAdditions
			),
			wageCents: $wage['centsPerHour']
		);

		$this->additions->assertCompatible(
			additions: $additions,
			wageBaseBlendsOvertime: $wage['blendsOvertime']
		);

		// Percentages already resolved against the WAGE BASE inside normalise(),
		// never against a running total: compounding one addition onto another
		// would make the result depend on the order they are listed in.
		$total = ($wage['centsPerHour'] + $this->additions->total(additions: $additions));

		return [
			'totalCentsPerHour' => $total,
			'wageCostCents' => $wage['centsPerHour'],
			'wageSource' => $wage['source'],
			'wageBasis' => $wage['basis'],
			'wageBaseBlendsOvertime' => $wage['blendsOvertime'],
			'additions' => $additions,
		];

	}//end resolve()

	/**
	 * Read the hand-set override, refusing an unexplained one.
	 *
	 * @param array<string, mixed> $employee The Employee object as an array.
	 *
	 * @return array{centsPerHour: int, source: string, basis: string, blendsOvertime: bool}|null Null when no override is set.
	 *
	 * @throws InvalidArgumentException When an amount is set without a reason, or is negative.
	 */
	private function resolveOverride(array $employee): ?array {
		$cents = ($employee['hourlyCostRateOverrideCents'] ?? null);
		if ($cents === null || $cents === '') {
			return null;
		}

		$cents = (int)$cents;
		if ($cents < 0) {
			throw new InvalidArgumentException('hourlyCostRateOverrideCents must not be negative, got: ' . $cents);
		}

		$reason = trim((string)($employee['hourlyCostRateOverrideReason'] ?? ''));
		if ($reason === '') {
			// Refused rather than ignored: silently falling back to the derived
			// rate would hide that someone deliberately set a number, and
			// silently using it would put an unauditable figure into an IV3
			// submission.
			throw new InvalidArgumentException(
				'hourlyCostRateOverrideCents is set without hourlyCostRateOverrideReason; '
				. 'an override that reaches a statutory submission must say why it exists'
			);
		}

		// A hand-set base is whatever the administrator meant it to be; it
		// carries no payslip's overtime blending.
		return [
			'centsPerHour' => $cents,
			'source' => self::SOURCE_OVERRIDE,
			'basis' => $reason,
			'blendsOvertime' => false,
		];

	}//end resolveOverride()

	/**
	 * Derive the loaded cost per hour from a proforma calculation over the
	 * contract's own terms.
	 *
	 * The monthly salary lives on the Employee (`grossMonthlySalary`) — the
	 * field the payroll engine reads — while the hours and the Awf tariff live
	 * on the EmploymentContract. Where no monthly salary is recorded the
	 * contract's gross `hourlyWage` reconstitutes one, so an hourly-paid
	 * employment still costs out.
	 *
	 * @param array<string, mixed> $employee The Employee object as an array.
	 * @param array<string, mixed>|null $contract The active EmploymentContract as an array.
	 * @param string $period Costing period as `YYYY-MM`.
	 *
	 * @return array{centsPerHour: int, source: string, basis: string, blendsOvertime: bool}|null Null when the contract cannot yield a rate.
	 */
	private function deriveFromContract(array $employee, ?array $contract, string $period): ?array {
		if ($contract === null || $period === '') {
			return null;
		}

		$hoursPerWeek = (float)($contract['hoursPerWeek'] ?? 0);
		if ($hoursPerWeek <= 0.0) {
			return null;
		}

		$monthlyHours = ($hoursPerWeek * self::WEEKS_PER_YEAR / self::MONTHS_PER_YEAR);

		$grossMonthly = $this->resolveGrossMonthly(
			employee: $employee,
			contract: $contract,
			monthlyHours: $monthlyHours
		);
		if ($grossMonthly === null || $grossMonthly <= 0.0) {
			return null;
		}

		try {
			// `bijzonder: 0` and `parttime: 1.0` deliberately: the contract's
			// own hours ARE the denominator, and a one-off payment is not part
			// of what an hour costs. This is what keeps the base free of the
			// period noise a real payslip carries.
			$breakdown = $this->proforma->simulate(
				[
					'gross' => $grossMonthly,
					'table' => ($employee['taxTableColor'] ?? 'wit'),
					'dateOfBirth' => ($employee['dateOfBirth'] ?? null),
					'period' => $period,
					'parttime' => 1.0,
					'bijzonder' => 0.0,
					'aof' => ($contract['aofTariff'] ?? null),
				]
			);
		} catch (Throwable $e) {
			// A contract the calculator cannot price is a visible "no rate",
			// not a zero — the caller surfaces it rather than booking the work
			// as free.
			$this->logger->warning(
				'[EmployeeCostRateService] proforma calculation failed; no cost rate derived',
				['error' => $e->getMessage()]
			);
			return null;
		}//end try

		$loadedCents = ($this->toCents(value: ($breakdown['grossPay'] ?? null)) ?? 0)
			+ ($this->toCents(value: ($breakdown['werknemersverzekeringen'] ?? null)) ?? 0)
			+ ($this->toCents(value: ($breakdown['zvw'] ?? null)) ?? 0);

		if ($loadedCents <= 0) {
			return null;
		}

		return [
			'centsPerHour' => (int)round($loadedCents / $monthlyHours),
			'source' => self::SOURCE_CONTRACT,
			'basis' => 'proforma over the contract, ' . $period . ', '
				. rtrim(rtrim(number_format($hoursPerWeek, 2, '.', ''), '0'), '.') . 'h/week',
			// A proforma over the contract contains no overtime, no one-offs
			// and no sick pay, so an overtime addition on top is legitimate.
			'blendsOvertime' => false,
		];

	}//end deriveFromContract()

	/**
	 * Resolve the gross monthly salary the proforma should price.
	 *
	 * @param array<string, mixed> $employee The Employee object as an array.
	 * @param array<string, mixed> $contract The EmploymentContract as an array.
	 * @param float $monthlyHours Hours behind one contracted month.
	 *
	 * @return float|null Null when neither a salary nor an hourly wage is recorded.
	 */
	private function resolveGrossMonthly(array $employee, array $contract, float $monthlyHours): ?float {
		$salary = ($employee['grossMonthlySalary'] ?? null);
		if ($salary !== null && $salary !== '' && is_numeric($salary) === true && (float)$salary > 0.0) {
			return (float)$salary;
		}

		$hourly = ($contract['hourlyWage'] ?? null);
		if ($hourly !== null && $hourly !== '' && is_numeric($hourly) === true && (float)$hourly > 0.0) {
			return ((float)$hourly * $monthlyHours);
		}

		return null;
	}//end resolveGrossMonthly()

	/**
	 * Convert a euro amount to integer cents.
	 *
	 * The proforma breakdown is euro decimals, while a cost rate is carried in
	 * cents so that rate x hours cannot accumulate IEEE-754 drift across a
	 * quarter's allocations.
	 *
	 * @param mixed $value Raw field value.
	 *
	 * @return int|null Null when the value is absent or not numeric.
	 */
	private function toCents(mixed $value): ?int {
		if ($value === null || $value === '' || is_numeric($value) === false) {
			return null;
		}

		return (int)round(((float)$value) * 100);
	}//end toCents()
}//end class
