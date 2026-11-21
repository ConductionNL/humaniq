<?php

/**
 * Final Leave Payout Service
 *
 * Pays a leaver's open leave balance on the payslip of the period their
 * employment ends in (REQ-OFC-005, BW 7:641): the open hours (entitled plus
 * extra-statutory minus used, at least zero) at the hourly wage of the last
 * contract, as one payslip line, and then marks the leaver's offboarding case
 * `verlofsaldoUitbetaald: true`.
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
 * @spec openspec/specs/offboarding-completion/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCA\Humaniq\Support\ConvertsRowsToArrays;
use OCA\Humaniq\Support\LoadsAllRows;
use OCA\Humaniq\Support\ReadsEmployeeKeys;
use OCA\Humaniq\Support\ResolvesObjectService;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The open leave balance of a leaver as pay, and the offboarding stamp.
 *
 * @spec openspec/specs/offboarding-completion/spec.md
 */
final class FinalLeavePayoutService {

	use ConvertsRowsToArrays;
	use LoadsAllRows;
	use ReadsEmployeeKeys;
	use ResolvesObjectService;

	/**
	 * Page size for the row reads.
	 */
	private const LIMIT = 10000;

	/**
	 * Weeks per month, for an hourly wage derived from a monthly salary.
	 */
	private const WEEKS_PER_MONTH = (52 / 12);

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container       Resolves OpenRegister's services.
	 * @param SettingsService    $settingsService The register slug and OpenRegister availability.
	 * @param LoggerInterface    $logger          Logger.
	 *
	 * @spec openspec/specs/offboarding-completion/spec.md
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Every LeaveBalance, read once per run, by the employee reference it
	 * carries (an id, slug or employee number).
	 *
	 * @return array<string, list<array<string, mixed>>>
	 *
	 * @spec openspec/specs/offboarding-completion/spec.md#requirement-the-open-leave-balance-shall-be-paid-on-the-leavers-final-payslip-req-ofc-005
	 */
	public function balancesByEmployeeKey(): array {
		$out = [];
		foreach ($this->loadAll('LeaveBalance') as $balance) {
			$key = trim((string)($balance['employeeId'] ?? ''));
			if ($key !== '') {
				$out[$key][] = $balance;
			}
		}

		return $out;
	}//end balancesByEmployeeKey()

	/**
	 * The payout of a leaver whose employment ends in this period, or null
	 * when the employee does not leave in it or has no open hours.
	 *
	 * @param array<string, mixed>                          $employee   The Employee.
	 * @param array<string, mixed>|null                     $contract   The last contract, covering the period.
	 * @param string                                        $period     Wage period, `YYYY-MM`.
	 * @param array<string, list<array<string, mixed>>>     $balances   The LeaveBalances by employee key.
	 *
	 * @return array{hours: float, cents: int}|null
	 *
	 * @spec openspec/specs/offboarding-completion/spec.md#requirement-the-open-leave-balance-shall-be-paid-on-the-leavers-final-payslip-req-ofc-005
	 */
	public function payoutFor(array $employee, ?array $contract, string $period, array $balances): ?array {
		$endDate = trim((string)($employee['endDate'] ?? ''));
		if ($endDate === '' || substr($endDate, 0, 7) !== $period) {
			return null;
		}

		$hours = $this->openHours(employee: $employee, year: substr($endDate, 0, 4), balances: $balances);
		if ($hours <= 0.0) {
			return null;
		}

		$hourlyCents = $this->hourlyWageCents(employee: $employee, contract: $contract);
		if ($hourlyCents <= 0) {
			return null;
		}

		return ['hours' => $hours, 'cents' => (int)round($hours * $hourlyCents)];
	}//end payoutFor()

	/**
	 * The payslip field naming the payout as one line, or nothing when there
	 * is none, so an ordinary payslip keeps its shape.
	 *
	 * @param array{hours: float, cents: int}|null $payout The payout.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/offboarding-completion/spec.md#requirement-the-open-leave-balance-shall-be-paid-on-the-leavers-final-payslip-req-ofc-005
	 */
	public function payslipFields(?array $payout): array {
		if ($payout === null) {
			return [];
		}

		return ['leavePayout' => ['hours' => $payout['hours'], 'amount' => round(($payout['cents'] / 100), 2)]];
	}//end payslipFields()

	/**
	 * Mark the offboarding case of every paid leaver `verlofsaldoUitbetaald`.
	 * A case already marked is left alone; a failed write is logged and does
	 * not fail the run, whose payslip is the figure of record.
	 *
	 * @param list<array<string, mixed>> $employees The employees whose payout this run paid.
	 *
	 * @return int The number of cases marked.
	 *
	 * @spec openspec/specs/offboarding-completion/spec.md#requirement-the-open-leave-balance-shall-be-paid-on-the-leavers-final-payslip-req-ofc-005
	 */
	public function markPaid(array $employees): int {
		if ($employees === []) {
			return 0;
		}

		$keys = [];
		foreach ($employees as $employee) {
			foreach ($this->employeeKeys($employee) as $key) {
				$keys[$key] = true;
			}
		}

		$marked = 0;
		foreach ($this->loadAll('Offboarding') as $case) {
			if (isset($keys[trim((string)($case['employeeId'] ?? ''))]) === false || ($case['verlofsaldoUitbetaald'] ?? false) === true) {
				continue;
			}

			$marked += $this->stamp(case: $case);
		}

		return $marked;
	}//end markPaid()

	/**
	 * Save one case with `verlofsaldoUitbetaald: true`.
	 *
	 * @param array<string, mixed> $case The Offboarding case.
	 *
	 * @return int 1 when saved, 0 when the write failed.
	 */
	private function stamp(array $case): int {
		$uuid = $this->idOf($case);
		$payload = $case;
		unset($payload['@self']);
		$payload['verlofsaldoUitbetaald'] = true;

		try {
			$this->objectService()->saveObject(
				object: $payload,
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: $this->register(), schema: 'Offboarding'),
					uuid: ($uuid === '' ? null : $uuid)
				),
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			$this->logger->warning('FinalLeavePayoutService: kon verlofsaldoUitbetaald niet zetten: ' . $e->getMessage());
			return 0;
		}

		return 1;
	}//end stamp()

	/**
	 * The open hours of the end year: entitled plus extra-statutory minus
	 * used, summed over the employee's balances of that year, at least zero.
	 *
	 * @param array<string, mixed>                      $employee The Employee.
	 * @param string                                    $year     The year the employment ends.
	 * @param array<string, list<array<string, mixed>>> $balances The LeaveBalances by employee key.
	 *
	 * @return float
	 */
	private function openHours(array $employee, string $year, array $balances): float {
		$open = 0.0;
		$seen = [];
		foreach ($this->employeeKeys($employee) as $key) {
			foreach (($balances[$key] ?? []) as $balance) {
				$id = $this->idOf($balance);
				if (($id !== '' && isset($seen[$id]) === true) || (string)($balance['year'] ?? '') !== $year) {
					continue;
				}

				$seen[$id] = true;
				$open += ((float)($balance['entitledHours'] ?? 0) + (float)($balance['bovenwettelijkHours'] ?? 0) - (float)($balance['usedHours'] ?? 0));
			}
		}

		return round(max(0.0, $open), 2);
	}//end openHours()

	/**
	 * The hourly wage of the last contract in cents: its `hourlyWage`, else
	 * the monthly salary over the contract's hours per month.
	 *
	 * @param array<string, mixed>      $employee The Employee.
	 * @param array<string, mixed>|null $contract The last contract.
	 *
	 * @return int
	 */
	private function hourlyWageCents(array $employee, ?array $contract): int {
		$hourly = ($contract['hourlyWage'] ?? null);
		if (is_numeric($hourly) === true && (float)$hourly > 0.0) {
			return (int)round(((float)$hourly) * 100);
		}

		$monthly = ($employee['grossMonthlySalary'] ?? null);
		$perWeek = ($contract['hoursPerWeek'] ?? null);
		if (is_numeric($monthly) === false || is_numeric($perWeek) === false || (float)$perWeek <= 0.0) {
			return 0;
		}

		return (int)round(((float)$monthly * 100) / ((float)$perWeek * self::WEEKS_PER_MONTH));
	}//end hourlyWageCents()

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
