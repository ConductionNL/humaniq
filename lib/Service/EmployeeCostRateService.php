<?php

/**
 * Employee Cost Rate Service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @package  OCA\Humaniq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

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
		$costing = new ContractCosting();
		$costing->employee = $employee;
		$costing->contract = $contract;
		$costing->period = $period;

		$wage = ($this->resolveOverride(employee: $employee) ?? $this->deriveFromContract(costing: $costing));
		if ($wage === null) {
			return null;
		}

		$additions = $this->checkedAdditions(
			wage: $wage,
			raw: array_merge((array)($employee['hourlyCostAdditions'] ?? []), $extraAdditions)
		);

		return $this->rateOf(wage: $wage, additions: $additions);

	}//end resolve()

	/**
	 * The additions resolved against the wage base, refused when they do not
	 * fit it.
	 *
	 * @param array{centsPerHour: int, source: string, basis: string, blendsOvertime: bool} $wage The wage base.
	 * @param array<int, array<string, mixed>>                                             $raw  The stored and caller-supplied additions.
	 *
	 * @return array<int, array{key: string, centsPerHour: int, source: string, basis: string}>
	 *
	 * @throws InvalidArgumentException When an addition carries no basis or does not fit the base.
	 */
	private function checkedAdditions(array $wage, array $raw): array {
		$additions = $this->additions->normalise(raw: $raw, wageCents: $wage['centsPerHour']);

		$this->additions->assertCompatible(
			additions: $additions,
			wageBaseBlendsOvertime: $wage['blendsOvertime']
		);

		return $additions;
	}//end checkedAdditions()

	/**
	 * The cost rate: the wage base plus the additions.
	 *
	 * @param array{centsPerHour: int, source: string, basis: string, blendsOvertime: bool}   $wage      The wage base.
	 * @param array<int, array{key: string, centsPerHour: int, source: string, basis: string}> $additions The checked additions.
	 *
	 * @return array<string, mixed>
	 */
	private function rateOf(array $wage, array $additions): array {
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
	}//end rateOf()

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

		// A hand-set base is whatever the administrator meant it to be; it
		// carries no payslip's overtime blending.
		return [
			'centsPerHour' => $cents,
			'source' => self::SOURCE_OVERRIDE,
			'basis' => self::overrideReason(employee: $employee),
			'blendsOvertime' => false,
		];

	}//end resolveOverride()

	/**
	 * Why the override exists, refusing one that does not say.
	 *
	 * @param array<string, mixed> $employee The Employee object as an array.
	 *
	 * @return string
	 *
	 * @throws InvalidArgumentException When no reason is recorded.
	 */
	private static function overrideReason(array $employee): string {
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

		return $reason;
	}//end overrideReason()

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
	 * @param ContractCosting $costing The employee, contract and period.
	 *
	 * @return array{centsPerHour: int, source: string, basis: string, blendsOvertime: bool}|null Null when the contract cannot yield a rate.
	 */
	private function deriveFromContract(ContractCosting $costing): ?array {
		$monthlyHours = self::monthlyHoursOf(costing: $costing);
		if ($monthlyHours === null) {
			return null;
		}

		$breakdown = $this->proformaBreakdown(costing: $costing, monthlyHours: $monthlyHours);
		if ($breakdown === null) {
			return null;
		}

		$loadedCents = $this->loadedCents(breakdown: $breakdown);
		if ($loadedCents <= 0) {
			return null;
		}

		return self::contractWage(costing: $costing, centsPerHour: (int)round($loadedCents / $monthlyHours));

	}//end deriveFromContract()

	/**
	 * The hours behind one contracted month; null without a contract, a
	 * period or contracted hours.
	 *
	 * @param ContractCosting $costing The employee, contract and period.
	 *
	 * @return float|null
	 */
	private static function monthlyHoursOf(ContractCosting $costing): ?float {
		if ($costing->contract === null || $costing->period === '') {
			return null;
		}

		$hoursPerWeek = self::hoursPerWeekOf(costing: $costing);
		if ($hoursPerWeek <= 0.0) {
			return null;
		}

		return ($hoursPerWeek * self::WEEKS_PER_YEAR / self::MONTHS_PER_YEAR);
	}//end monthlyHoursOf()

	/**
	 * The contract's weekly hours.
	 *
	 * @param ContractCosting $costing The employee, contract and period.
	 *
	 * @return float
	 */
	private static function hoursPerWeekOf(ContractCosting $costing): float {
		return (float)($costing->contract['hoursPerWeek'] ?? 0);
	}//end hoursPerWeekOf()

	/**
	 * The proforma breakdown of one plain month at the contract's salary;
	 * null without a salary or when the calculator cannot price it.
	 *
	 * @param ContractCosting $costing      The employee, contract and period.
	 * @param float           $monthlyHours Hours behind one contracted month.
	 *
	 * @return array<string, mixed>|null
	 */
	private function proformaBreakdown(ContractCosting $costing, float $monthlyHours): ?array {
		$grossMonthly = $this->resolveGrossMonthly(costing: $costing, monthlyHours: $monthlyHours);
		if ($grossMonthly === null || $grossMonthly <= 0.0) {
			return null;
		}

		try {
			return $this->proforma->simulate(self::proformaParams(costing: $costing, grossMonthly: $grossMonthly));
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
	}//end proformaBreakdown()

	/**
	 * The proforma's input for one plain month.
	 *
	 * `bijzonder: 0` and `parttime: 1.0` deliberately: the contract's own
	 * hours ARE the denominator, and a one-off payment is not part of what an
	 * hour costs. This is what keeps the base free of the period noise a real
	 * payslip carries.
	 *
	 * @param ContractCosting $costing      The employee, contract and period.
	 * @param float           $grossMonthly The gross monthly salary.
	 *
	 * @return array<string, mixed>
	 */
	private static function proformaParams(ContractCosting $costing, float $grossMonthly): array {
		return [
			'gross' => $grossMonthly,
			'table' => ($costing->employee['taxTableColor'] ?? 'wit'),
			'dateOfBirth' => ($costing->employee['dateOfBirth'] ?? null),
			'period' => $costing->period,
			'parttime' => 1.0,
			'bijzonder' => 0.0,
			'aof' => ($costing->contract['aofTariff'] ?? null),
		];
	}//end proformaParams()

	/**
	 * What the month costs the employer in cents: gross plus the employer charges.
	 *
	 * @param array<string, mixed> $breakdown The proforma breakdown.
	 *
	 * @return int
	 */
	private function loadedCents(array $breakdown): int {
		return ($this->toCents(value: ($breakdown['grossPay'] ?? null)) ?? 0)
			+ ($this->toCents(value: ($breakdown['werknemersverzekeringen'] ?? null)) ?? 0)
			+ ($this->toCents(value: ($breakdown['zvw'] ?? null)) ?? 0);
	}//end loadedCents()

	/**
	 * The contract-derived wage base.
	 *
	 * @param ContractCosting $costing      The employee, contract and period.
	 * @param int             $centsPerHour The loaded cost per hour.
	 *
	 * @return array{centsPerHour: int, source: string, basis: string, blendsOvertime: bool}
	 */
	private static function contractWage(ContractCosting $costing, int $centsPerHour): array {
		return [
			'centsPerHour' => $centsPerHour,
			'source' => self::SOURCE_CONTRACT,
			'basis' => 'proforma over the contract, ' . $costing->period . ', '
				. rtrim(rtrim(number_format(self::hoursPerWeekOf(costing: $costing), 2, '.', ''), '0'), '.') . 'h/week',
			// A proforma over the contract contains no overtime, no one-offs
			// and no sick pay, so an overtime addition on top is legitimate.
			'blendsOvertime' => false,
		];
	}//end contractWage()

	/**
	 * Resolve the gross monthly salary the proforma should price.
	 *
	 * @param ContractCosting $costing      The employee, contract and period.
	 * @param float           $monthlyHours Hours behind one contracted month.
	 *
	 * @return float|null Null when neither a salary nor an hourly wage is recorded.
	 */
	private function resolveGrossMonthly(ContractCosting $costing, float $monthlyHours): ?float {
		$salary = self::positiveAmount(value: ($costing->employee['grossMonthlySalary'] ?? null));
		if ($salary !== null) {
			return $salary;
		}

		$hourly = self::positiveAmount(value: ($costing->contract['hourlyWage'] ?? null));
		if ($hourly !== null) {
			return ($hourly * $monthlyHours);
		}

		return null;
	}//end resolveGrossMonthly()

	/**
	 * A recorded amount above zero, as a float; null when absent, not numeric
	 * or not positive.
	 *
	 * @param mixed $value Raw field value.
	 *
	 * @return float|null
	 */
	private static function positiveAmount(mixed $value): ?float {
		if ($value !== null && $value !== '' && is_numeric($value) === true && (float)$value > 0.0) {
			return (float)$value;
		}

		return null;
	}//end positiveAmount()

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
