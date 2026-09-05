<?php

/**
 * Humaniq LeaveBalanceProjectionService.
 *
 * Projects approved LeaveRequests onto `LeaveBalance.usedHours`, the field that
 * had no writer at all before this service existed: `LeaveAccrualJob` seeds it
 * to `0.0` when it creates a balance and never touches it again, and
 * `LeaveBuySellSettlementService` writes `bovenwettelijkHours` and only
 * `bovenwettelijkHours`. `remainingHours` is a declarative
 * `x-openregister-calculations` field over
 * `entitledHours + bovenwettelijkHours - usedHours`, so a permanently zero
 * `usedHours` showed every employee their full entitlement forever and left
 * `nl-verlof-saldo-niet-negatief`, `nl-verlof-vervaltermijn` and the offboarding
 * payout check unable to fire.
 *
 * Recompute, never increment. The sum of the approved requests IS the answer, so
 * running this twice lands on the same number, a replayed or missed event cannot
 * make the balance drift, and every balance that is wrong today corrects itself
 * the first time any request touching it changes.
 *
 * The arithmetic lives in {@see LeaveHoursCalculator}, which is pure and takes
 * plain arrays, so the whole decision surface is unit testable without
 * OpenRegister. This class carries only the reads and writes around it.
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
 * @spec openspec/changes/leave-approval-posts-to-the-balance/specs/leave-management/spec.md#REQ-LEAVE-POST-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Recomputes `LeaveBalance.usedHours` from the approved LeaveRequests behind it.
 */
class LeaveBalanceProjectionService {

	/**
	 * Max objects loaded per type when scanning for the requests behind a balance.
	 *
	 * Mirrors LeaveBuySellSettlementService::LIMIT: the manifest filter grammar
	 * cannot express the employee/year/leaveType triple, so the match happens here.
	 *
	 * @var int
	 */
	private const LIMIT = 10000;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param SettingsService $settingsService Register slug source.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Recompute every balance one changed LeaveRequest can affect.
	 *
	 * A request is projected onto its own employee/leaveType for each calendar
	 * year its range touches, so a request spanning New Year restates both
	 * years. Never creates a balance: when none matches, this logs at info and
	 * writes nothing, leaving auto provisioning to the named follow-up
	 * `leave-balance-auto-provision`.
	 *
	 * @param array<string, mixed> $request The changed LeaveRequest row.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/leave-approval-posts-to-the-balance/specs/leave-management/spec.md#REQ-LEAVE-POST-001
	 */
	public function projectForRequest(array $request): void {
		$employeeId = trim((string)($request['employeeId'] ?? ''));
		$leaveType = trim((string)($request['leaveType'] ?? ''));
		if ($employeeId === '' || $leaveType === '') {
			return;
		}

		$startYear = (int)substr((string)($request['startDate'] ?? ''), 0, 4);
		$endYear = (int)substr((string)($request['endDate'] ?? ''), 0, 4);
		if ($startYear === 0) {
			return;
		}

		if ($endYear < $startYear) {
			$endYear = $startYear;
		}

		$allRequests = $this->loadAll('LeaveRequest');
		$allBalances = $this->loadAll('LeaveBalance');

		for ($year = $startYear; $year <= $endYear; $year++) {
			$balance = $this->matchBalance($allBalances, $employeeId, $year, $leaveType);
			if ($balance === null) {
				$this->logger->info(
					sprintf(
						'humaniq: no LeaveBalance for employee %s, year %d, type %s, so nothing was projected.',
						$employeeId,
						$year,
						$leaveType
					)
				);
				continue;
			}

			$contractHours = null;
			if (($balance['contractHoursPerWeek'] ?? null) !== null) {
				$contractHours = (float)$balance['contractHoursPerWeek'];
			}

			$projection = LeaveHoursCalculator::usedHoursFor(
				$allRequests,
				$employeeId,
				$year,
				$leaveType,
				$contractHours
			);
			if ($projection['underivable'] !== []) {
				$this->logger->warning(
					sprintf(
						'humaniq: %d leave request(s) carry no hours and no contract hours per week, so they counted as zero against employee %s year %d type %s: %s',
						count($projection['underivable']),
						$employeeId,
						$year,
						$leaveType,
						implode(', ', $projection['underivable'])
					)
				);
			}

			$this->writeUsedHours($balance, $projection['usedHours']);
		}//end for

	}//end projectForRequest()

	/**
	 * Write the recomputed usage onto a balance, skipping an unchanged value.
	 *
	 * @param array<string, mixed> $balance The resolved LeaveBalance row.
	 * @param float $usedHours The recomputed usage.
	 *
	 * @return void
	 */
	private function writeUsedHours(array $balance, float $usedHours): void {
		$current = round((float)($balance['usedHours'] ?? 0), 2);
		if ($current === $usedHours) {
			// Idempotent: an unchanged projection issues no write, so a replayed
			// event cannot churn the object store or its audit trail.
			return;
		}

		$payload = $balance;
		$payload['usedHours'] = $usedHours;
		unset($payload['@self']);

		$uuid = trim((string)($balance['id'] ?? ($balance['@self']['id'] ?? '')));

		try {
			$this->objectService()->saveObject(
				object: $payload,
				register: $this->register(),
				schema: 'LeaveBalance',
				uuid: ($uuid === '' ? null : $uuid),
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'humaniq: could not write usedHours onto LeaveBalance ' . $uuid . ': ' . $e->getMessage()
			);
		}

	}//end writeUsedHours()

	/**
	 * Find the balance for one employee, year and leave type.
	 *
	 * @param array<int, array<string, mixed>> $balances Every LeaveBalance in scope.
	 * @param string $employeeId The employee.
	 * @param int $year The calendar year.
	 * @param string $leaveType The leave type.
	 *
	 * @return array<string, mixed>|null The matching balance, or null when there is none.
	 */
	private function matchBalance(array $balances, string $employeeId, int $year, string $leaveType): ?array {
		foreach ($balances as $balance) {
			if ((string)($balance['employeeId'] ?? '') === $employeeId
				&& (int)($balance['year'] ?? 0) === $year
				&& (string)($balance['leaveType'] ?? '') === $leaveType
			) {
				return $balance;
			}
		}

		return null;

	}//end matchBalance()

	/**
	 * Load every row of one schema.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<int, array<string, mixed>> The rows, empty when unreadable.
	 */
	private function loadAll(string $schema): array {
		try {
			$rows = $this->objectService()
				->setRegister($this->register())
				->setSchema($schema)
				->findAll(['limit' => self::LIMIT]);
		} catch (\Throwable $e) {
			$this->logger->warning('humaniq: could not load ' . $schema . ': ' . $e->getMessage());
			return [];
		}

		$out = [];
		foreach ((is_array($rows) === true ? $rows : []) as $row) {
			$out[] = $this->toArray($row);
		}

		return $out;

	}//end loadAll()

	/**
	 * Normalise an object-store row to a plain array.
	 *
	 * @param mixed $row The row as the object store returned it.
	 *
	 * @return array<string, mixed> The row as an array, empty when unusable.
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
	 * The configured register slug.
	 *
	 * @return string The register slug.
	 */
	private function register(): string {
		return $this->settingsService->getRegisterSlug();

	}//end register()

	/**
	 * Resolve OpenRegister's ObjectService, explaining itself when absent.
	 *
	 * @return mixed The ObjectService.
	 *
	 * @throws RuntimeException When OpenRegister is not installed.
	 */
	private function objectService(): mixed {
		// ADR-083: establish availability before reaching, so an instance
		// without OpenRegister is told which app to install rather than handed
		// a container exception naming a class the admin has never heard of.
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			throw new RuntimeException(
				'humaniq requires the OpenRegister app, which is not installed on this instance.'
			);
		}

		return $this->container->get('OCA\OpenRegister\Service\ObjectService');

	}//end objectService()

}//end class
