<?php

/**
 * Humaniq ManagerDeputies
 *
 * Who stands in for a manager, and when (self-service-approvals-inbox D2).
 * A ManagerDeputy record names a manager, a deputy and a period. During that
 * period, from and until both inclusive, the deputy's approvals inbox holds
 * the manager's waiting requests and the submit notifications reach the
 * deputy too. Nothing is stamped on the requests themselves: every reader
 * resolves the active deputy at the moment it reads.
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
 * @spec openspec/specs/approvals-inbox/spec.md#REQ-API-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Resolves active deputies and judges a deputy record before it is saved.
 *
 * @spec openspec/specs/approvals-inbox/spec.md#REQ-API-002
 */
class ManagerDeputies {

	public const SCHEMA = 'ManagerDeputy';

	public const SLUG = 'managerdeputy';

	/**
	 * Rows read once per request, since a notification or an inbox read asks
	 * several times.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private ?array $rows = null;

	/**
	 * The org chart's manager per employee, read once per request.
	 *
	 * @var array<string, string|null>
	 */
	private array $orgManagers = [];

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway Reads the deputy records.
	 * @param HumaniqRoles $roles Who counts as HR.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly HumaniqRoles $roles,
	) {

	}//end __construct()

	/**
	 * The deputies standing in for this manager on this day.
	 *
	 * @param string $managerUserId The manager's account.
	 * @param string $today The day, YYYY-MM-DD.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/approvals-inbox/spec.md#REQ-API-002
	 */
	public function activeDeputiesOf(string $managerUserId, string $today): array {
		$deputies = [];
		foreach ($this->activeOn(today: $today) as $row) {
			if (trim((string)($row['managerUserId'] ?? '')) === $managerUserId) {
				$deputies[] = trim((string)($row['deputyUserId'] ?? ''));
			}
		}

		return $this->distinct(uids: $deputies);
	}//end activeDeputiesOf()

	/**
	 * The managers this account stands in for on this day.
	 *
	 * @param string $deputyUserId The deputy's account.
	 * @param string $today The day, YYYY-MM-DD.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/approvals-inbox/spec.md#REQ-API-002
	 */
	public function managersCoveredBy(string $deputyUserId, string $today): array {
		$managers = [];
		foreach ($this->activeOn(today: $today) as $row) {
			if (trim((string)($row['deputyUserId'] ?? '')) === $deputyUserId) {
				$managers[] = trim((string)($row['managerUserId'] ?? ''));
			}
		}

		return $this->distinct(uids: $managers);
	}//end managersCoveredBy()

	/**
	 * The managers a waiting request belongs to: its stamped managerUserId,
	 * or, when nothing stamped one (a leave trade, an older claim), the
	 * employee's unique manager from the org chart on this day.
	 *
	 * @param array<string, mixed> $request The request.
	 * @param string $today The day, YYYY-MM-DD.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/approvals-inbox/spec.md#REQ-API-001
	 */
	public function managersOf(array $request, string $today): array {
		$stamped = trim((string)($request['managerUserId'] ?? ''));
		if ($stamped !== '') {
			return [$stamped];
		}

		$employeeId = trim((string)($request['employeeId'] ?? ''));
		if ($employeeId === '') {
			return [];
		}

		if (array_key_exists($employeeId, $this->orgManagers) === false) {
			$this->orgManagers[$employeeId] = $this->gateway->uniqueManagerUserIdFor($employeeId, $today);
		}

		$manager = $this->orgManagers[$employeeId];

		return ($manager === null || $manager === '') ? [] : [$manager];
	}//end managersOf()

	/**
	 * Everyone who may decide on a waiting request today: its managers and
	 * their active deputies, never the requester's own account.
	 *
	 * @param array<string, mixed> $request The request.
	 * @param string $today The day, YYYY-MM-DD.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/approvals-inbox/spec.md#REQ-API-002
	 */
	public function approversOf(array $request, string $today): array {
		$approvers = [];
		foreach ($this->managersOf(request: $request, today: $today) as $manager) {
			$approvers[] = $manager;
			foreach ($this->activeDeputiesOf(managerUserId: $manager, today: $today) as $deputy) {
				$approvers[] = $deputy;
			}
		}

		$own = trim((string)($request['userId'] ?? ''));

		return $this->distinct(uids: array_values(array_filter($approvers, static fn (string $uid): bool => $uid !== $own)));
	}//end approversOf()

	/**
	 * Why a deputy record may not be saved, or null when it may.
	 *
	 * @param array<string, mixed> $record The record as it would be saved.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/approvals-inbox/spec.md#REQ-API-002
	 */
	public function refusal(array $record): ?string {
		$manager = trim((string)($record['managerUserId'] ?? ''));
		$deputy = trim((string)($record['deputyUserId'] ?? ''));
		if ($manager === '' || $deputy === '') {
			return 'Name both the manager and the deputy.';
		}

		if ($manager === $deputy) {
			return 'A manager cannot be their own deputy.';
		}

		$from = $this->day(value: $record['from'] ?? null);
		$until = $this->day(value: $record['until'] ?? null);
		if ($from === null || $until === null) {
			return 'Give the first and the last day the deputy stands in.';
		}

		if ($until < $from) {
			return 'The last day comes before the first day.';
		}

		return null;
	}//end refusal()

	/**
	 * Why this account may not write this deputy record, or null when it may.
	 *
	 * A manager names their own deputy; HR may name one for any manager, for
	 * instance for a manager who is off sick. A write without a user (an
	 * import, a repair step) is the system's own.
	 *
	 * @param string $writer The account saving the record, '' for the system.
	 * @param array<string, mixed> $record The record as it would be saved.
	 * @param array<string, mixed> $stored The record as stored, [] on create.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/approvals-inbox/spec.md#REQ-API-002
	 */
	public function authorityRefusal(string $writer, array $record, array $stored): ?string {
		if ($writer === '' || $this->roles->isHr($writer) === true) {
			return null;
		}

		foreach ([$record, $stored] as $version) {
			$manager = trim((string)($version['managerUserId'] ?? ''));
			if ($version !== [] && $manager !== $writer) {
				return 'You can only name a deputy for yourself. HR can name one for another manager.';
			}
		}

		return null;
	}//end authorityRefusal()

	/**
	 * The records whose period holds this day.
	 *
	 * @param string $today The day, YYYY-MM-DD.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function activeOn(string $today): array {
		if ($this->rows === null) {
			$this->rows = $this->gateway->loadAll(self::SCHEMA);
		}

		return array_values(
			array_filter(
				$this->rows,
				function (array $row) use ($today): bool {
					$from = $this->day(value: $row['from'] ?? null);
					$until = $this->day(value: $row['until'] ?? null);
					return $from !== null && $until !== null && $from <= $today && $today <= $until;
				}
			)
		);
	}//end activeOn()

	/**
	 * The date part of a stored value, or null when it is not a date.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return string|null
	 */
	private function day(mixed $value): ?string {
		$text = substr(trim((string)($value ?? '')), 0, 10);
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) !== 1) {
			return null;
		}

		return $text;
	}//end day()

	/**
	 * Non-empty, each once.
	 *
	 * @param array<int, string> $uids The accounts.
	 *
	 * @return array<int, string>
	 */
	private function distinct(array $uids): array {
		return array_values(array_unique(array_filter($uids, static fn (string $uid): bool => $uid !== '')));
	}//end distinct()

}//end class
