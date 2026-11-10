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
 * @spec openspec/specs/leave-management/spec.md#REQ-LEAVE-POST-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;
use OCA\Humaniq\Support\ConvertsRowsToArrays;
use OCA\OpenRegister\Service\Object\ObjectFindTarget;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Recomputes `LeaveBalance.usedHours` from the approved LeaveRequests behind it.
 *
 * @spec openspec/specs/leave-management/spec.md#REQ-LEAVE-POST-001
 */
class LeaveBalanceProjectionService {

	use ConvertsRowsToArrays;

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
	 * @param LeaveTypeResolver $leaveTypes Resolves the request's administered leave type.
	 * @param WorkingCalendarReader|null $calendar openregister's working calendar, for the feestdagen a leave day does not cost.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly LeaveTypeResolver $leaveTypes = new LeaveTypeResolver(),
		private readonly ?WorkingCalendarReader $calendar = null,
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
	 * @param string|null $today The date lapses are measured on, `Y-m-d`; today when null.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/leave-management/spec.md#REQ-LEAVE-POST-001
	 * @spec openspec/specs/leave-expiry-and-carry-over/spec.md#Requirement:-Leave-taken-SHALL-draw-from-the-hours-that-lapse-first-(REQ-LEX-001)
	 */
	public function projectForRequest(array $request, ?string $today = null): void {
		$employeeId = trim((string)($request['employeeId'] ?? ''));
		$leaveType = trim((string)($request['leaveType'] ?? ''));
		if ($employeeId === '' || $leaveType === '') {
			return;
		}

		// leave-against-a-department-schedule REQ-LVM-T01: a type that draws no
		// balance posts nothing. Unpaid leave and most bijzonder verlof spend no
		// entitlement, and projecting them would move a number that should not
		// move -- silently, because a balance write reports nothing.
		if ($this->leaveTypes->drawsFromBalance($this->resolveLeaveType($request)) === false) {
			$this->logger->info(
				'humaniq: leave type ' . $leaveType . ' draws no balance, so nothing was projected for employee '
				. $employeeId . '.'
			);
			return;
		}

		$allRequests = $this->loadAll('LeaveRequest');
		$allBalances = $this->loadAll('LeaveBalance');
		$this->recompute(
			scope: new LeaveBalanceScope(employeeId: $employeeId, leaveType: $leaveType, type: $this->resolveLeaveType($request)),
			inputs: new LeaveProjectionInputs(
				requests: $allRequests,
				balances: $allBalances,
				today: ($today ?? date('Y-m-d')),
				workingTime: $this->workingTime(requests: $allRequests)
			)
		);

	}//end projectForRequest()

	/**
	 * Recompute every balance of every employee and leave type, and so apply
	 * the lapses due on `$today`. Run daily by LeaveAccrualJob.
	 *
	 * @param string $today The date lapses are measured on, `Y-m-d`.
	 *
	 * @return int The number of balances written.
	 *
	 * @spec openspec/specs/leave-expiry-and-carry-over/spec.md#Requirement:-Statutory-hours-SHALL-lapse-on-their-expiry-date-unless-HR-waives-it-(REQ-LEX-003)
	 */
	public function recomputeAll(string $today): int {
		$allBalances = $this->loadAll('LeaveBalance');
		$allRequests = $this->loadAll('LeaveRequest');
		// loadAll answers an empty list on a read failure, and no type means
		// "draws from the balance", as before types existed.
		$types = $this->loadAll('LeaveType');
		$inputs = new LeaveProjectionInputs(
			requests: $allRequests,
			balances: $allBalances,
			today: $today,
			workingTime: $this->workingTime(requests: $allRequests)
		);

		$groups = [];
		foreach ($allBalances as $balance) {
			$key = (string)($balance['employeeId'] ?? '') . '|' . (string)($balance['leaveType'] ?? '');
			$groups[$key] = [(string)($balance['employeeId'] ?? ''), (string)($balance['leaveType'] ?? '')];
		}

		$written = 0;
		foreach ($groups as [$employeeId, $leaveType]) {
			if ($employeeId === '' || $leaveType === '') {
				continue;
			}

			$type = $this->leaveTypes->resolve(request: ['leaveType' => $leaveType], types: $types);
			if ($this->leaveTypes->drawsFromBalance($type) === false) {
				continue;
			}

			$written += $this->recompute(
				scope: new LeaveBalanceScope(employeeId: $employeeId, leaveType: $leaveType, type: $type),
				inputs: $inputs
			);
		}

		return $written;
	}//end recomputeAll()

	/**
	 * Recompute every balance of one employee and leave type: which bucket
	 * each approved request drew from, and what has lapsed by `$today`.
	 *
	 * Never creates a balance: hours in a year with no balance and no earlier
	 * bucket to draw from are logged and left unplaced.
	 *
	 * @param LeaveBalanceScope $scope The employee, the leave type and the administered type.
	 * @param LeaveProjectionInputs $inputs Every request and balance, the measuring date and the working time.
	 *
	 * @return int The number of balances written.
	 *
	 * @spec openspec/specs/leave-expiry-and-carry-over/spec.md#Requirement:-Leave-taken-SHALL-draw-from-the-hours-that-lapse-first-(REQ-LEX-001)
	 */
	private function recompute(LeaveBalanceScope $scope, LeaveProjectionInputs $inputs): int {
		$own = $inputs->withBalances($this->balancesOf(scope: $scope, balances: $inputs->balances));
		if ($own->balances === []) {
			$this->logger->info(
				sprintf('humaniq: no LeaveBalance for employee %s, type %s, so nothing was projected.', $scope->employeeId, $scope->leaveType)
			);
			return 0;
		}

		$allocation = (new LeaveAllocationCalculator())->allocate(
			balances: $own->balances,
			uses: $this->usesOf(scope: $scope, inputs: $own),
			leaveType: $scope->type,
			today: $own->today
		);

		return $this->writeAllocations(balances: $own->balances, allocation: $allocation);
	}//end recompute()

	/**
	 * The balances of one employee and leave type.
	 *
	 * @param LeaveBalanceScope $scope The employee and leave type.
	 * @param array<int, array<string, mixed>> $balances Every LeaveBalance.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function balancesOf(LeaveBalanceScope $scope, array $balances): array {
		$own = [];
		foreach ($balances as $balance) {
			if ((string)($balance['employeeId'] ?? '') === $scope->employeeId && (string)($balance['leaveType'] ?? '') === $scope->leaveType) {
				$own[] = $balance;
			}
		}

		return $own;
	}//end balancesOf()

	/**
	 * Write each balance's recomputed figures, and count the writes.
	 *
	 * @param array<int, array<string, mixed>> $balances The employee's balances of this type.
	 * @param array<string, array<string, mixed>> $allocation The figures per balance id.
	 *
	 * @return int The number of balances written.
	 */
	private function writeAllocations(array $balances, array $allocation): int {
		$written = 0;
		foreach ($balances as $balance) {
			$id = trim((string)($balance['id'] ?? ($balance['@self']['id'] ?? '')));
			if (isset($allocation[$id]) === true && $this->writeAllocation(balance: $balance, figures: $allocation[$id]) === true) {
				$written++;
			}
		}

		return $written;
	}//end writeAllocations()

	/**
	 * The hours taken per request per year, naming the requests whose hours
	 * cannot be derived.
	 *
	 * @param LeaveBalanceScope $scope The employee and leave type.
	 * @param LeaveProjectionInputs $inputs Every request, the employee's balances of this type and the working time.
	 *
	 * @return array<int, array{date: string, year: int, hours: float}>
	 */
	private function usesOf(LeaveBalanceScope $scope, LeaveProjectionInputs $inputs): array {
		$found = (new LeaveAllocationCalculator())->usesFrom(
			requests: $inputs->requests,
			balances: $inputs->balances,
			employeeId: $scope->employeeId,
			leaveType: $scope->leaveType,
			workingTime: $inputs->workingTime
		);
		if ($found['underivable'] !== []) {
			$this->logger->warning(
				sprintf(
					'humaniq: %d leave request(s) carry no hours and no contract hours per week, so they counted as zero against employee %s type %s: %s',
					count($found['underivable']),
					$scope->employeeId,
					$scope->leaveType,
					implode(', ', $found['underivable'])
				)
			);
		}

		return $found['uses'];
	}//end usesOf()

	/**
	 * Everyone's working patterns and non-working times, and the dates
	 * openregister's working calendar marks non-working over the span of the
	 * requests, read once per projection. An unread calendar is logged once,
	 * and every cost then says `pattern-only` (leave-hours-from-the-working-pattern D3).
	 *
	 * @param array<int, array<string, mixed>> $requests The LeaveRequests in scope.
	 *
	 * @return array{patterns: array<int, array<string, mixed>>, nonWorkingTimes: array<int, array<string, mixed>>, nonWorkingDates: array<int, string>|null}
	 *
	 * @spec openspec/specs/leave-hours-from-pattern/spec.md#REQ-LHP-001
	 */
	private function workingTime(array $requests): array {
		$first = null;
		$last = null;
		foreach ($requests as $request) {
			$start = substr((string)($request['startDate'] ?? ''), 0, 10);
			$end = substr((string)($request['endDate'] ?? $start), 0, 10);
			if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) !== 1) {
				continue;
			}

			$first = min($first ?? $start, $start);
			$last = max($last ?? $end, $end, $start);
		}

		$dates = null;
		if ($this->calendar !== null && $first !== null) {
			$answer = $this->calendar->nonWorkingDates(new DateTimeImmutable($first), new DateTimeImmutable((string)$last));
			$dates = $answer['dates'];
			if ($dates === null) {
				$this->logger->notice(
					'humaniq: the working calendar could not be read (' . (string)($answer['reason'] ?? 'unknown')
					. '), so leave days were costed from the working pattern alone.'
				);
			}
		}

		return [
			'patterns' => $this->loadAll('WorkingPattern'),
			'nonWorkingTimes' => $this->loadAll('NonWorkingTime'),
			'nonWorkingDates' => $dates,
		];
	}//end workingTime()

	/**
	 * The administered `LeaveType` a request means, or null when none is
	 * administered or the list cannot be read.
	 *
	 * A read failure resolves to null, which {@see LeaveTypeResolver::drawsFromBalance()}
	 * treats as drawing from the balance: that is what every instance did before
	 * types existed, so a register hiccup cannot silently stop counting holiday.
	 *
	 * @param array<string, mixed> $request The LeaveRequest row.
	 *
	 * @return array<string, mixed>|null The type, or null.
	 *
	 * @spec openspec/specs/leave-management/spec.md#REQ-LVM-T01
	 */
	private function resolveLeaveType(array $request): ?array {
		try {
			$types = $this->loadAll('LeaveType');
		} catch (\Throwable $e) {
			$this->logger->info(
				'humaniq: the leave types could not be read, so the balance projection ran as before: ' . $e->getMessage()
			);
			return null;
		}

		return $this->leaveTypes->resolve(request: $request, types: $types);
	}//end resolveLeaveType()

	/**
	 * Write the recomputed allocation and lapse onto a balance, skipping an unchanged one.
	 *
	 * @param array<string, mixed> $balance The resolved LeaveBalance row.
	 * @param array<string, mixed> $figures usedStatutoryHours, usedBovenwettelijkHours, usedHours, expiredHours, bovenwettelijkExpiryDate.
	 *
	 * @return bool Whether a write was issued.
	 */
	private function writeAllocation(array $balance, array $figures): bool {
		$changed = false;
		foreach ($figures as $field => $value) {
			$current = $balance[$field] ?? null;
			if (is_float($value) === true) {
				$current = round((float)($current ?? 0), 2);
			}

			if ($current !== $value) {
				$changed = true;
			}
		}

		if ($changed === false) {
			// Idempotent: an unchanged projection issues no write, so a replayed
			// event cannot churn the object store or its audit trail.
			return false;
		}

		$payload = array_merge($balance, $figures);
		unset($payload['@self']);

		$uuid = trim((string)($balance['id'] ?? ($balance['@self']['id'] ?? '')));

		try {
			$this->objectService()->saveObject(
				object: $payload,
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: $this->register(), schema: 'LeaveBalance'),
					uuid: ($uuid === '' ? null : $uuid)
				),
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'humaniq: could not write the allocation onto LeaveBalance ' . $uuid . ': ' . $e->getMessage()
			);
			return false;
		}

		return true;
	}//end writeAllocation()

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
