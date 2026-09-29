<?php

/**
 * Humaniq ApprovalsInboxService
 *
 * One approvals inbox across processes (self-service-approvals-inbox D1).
 * Composes, on read, every submitted leave request, timesheet, expense claim
 * and leave trade that waits for the caller: requests whose manager is the
 * caller, and requests of a manager the caller stands in for today. It also
 * lists what the caller decided in the last 90 days, with each request's
 * timeline. Nothing is stored: the rows are the requests themselves, read
 * under the caller's own rights, so a request the caller may not read is
 * simply absent.
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
 * @spec openspec/specs/approvals-inbox/spec.md#REQ-API-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;

/**
 * Composes the open and decided views of the approvals inbox.
 *
 * @spec openspec/specs/approvals-inbox/spec.md#REQ-API-001
 */
class ApprovalsInboxService {

	/**
	 * How far back the decided view looks, in days.
	 */
	public const DECIDED_WINDOW_DAYS = 90;

	/**
	 * Per approvable schema: its kind, its detail route, and the fields that
	 * give its dates and its size.
	 *
	 * @var array<string, array{kind: string, route: string, from: string, to: string, size: string}>
	 */
	public const SOURCES = [
		'LeaveRequest' => ['kind' => 'leave', 'route' => 'LeaveRequestDetail', 'from' => 'startDate', 'to' => 'endDate', 'size' => 'hours'],
		'Timesheet' => ['kind' => 'hours', 'route' => 'TimesheetDetail', 'from' => 'period', 'to' => 'period', 'size' => 'hours'],
		'Expense' => ['kind' => 'expense', 'route' => 'ExpenseDetail', 'from' => 'expenseDate', 'to' => 'expenseDate', 'size' => 'amount'],
		'LeaveTransaction' => ['kind' => 'leave-trade', 'route' => 'LeaveTransactionDetail', 'from' => 'year', 'to' => 'year', 'size' => 'hours'],
	];

	/**
	 * Employee names by id, read once per request.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $names = null;

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway Reads the requests and employees.
	 * @param ManagerDeputies $deputies Managers and their deputies.
	 * @param RbacObjectReader $rbac What the caller may read.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly ManagerDeputies $deputies,
		private readonly RbacObjectReader $rbac,
	) {

	}//end __construct()

	/**
	 * Every request waiting for this account today, oldest first.
	 *
	 * @param string $uid The caller.
	 * @param string $today The day, YYYY-MM-DD.
	 * @param string|null $kind Only this kind (leave, hours, expense, leave-trade).
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/approvals-inbox/spec.md#REQ-API-001
	 * @spec openspec/specs/approvals-inbox/spec.md#REQ-API-002
	 */
	public function open(string $uid, string $today, ?string $kind = null): array {
		$rows = [];
		foreach ($this->sources(kind: $kind) as $schema => $source) {
			foreach ($this->gateway->findFiltered($schema, ['status' => 'submitted']) as $request) {
				$managers = $this->deputies->managersOf(request: $request, today: $today);
				if (in_array($uid, $this->deputies->approversOf(request: $request, today: $today), true) === false
					|| $this->readable(schema: $schema, request: $request) === false
				) {
					continue;
				}

				$row = $this->row(schema: $schema, source: $source, request: $request);
				$row['waitingDays'] = $this->daysBetween(from: $request['submittedAt'] ?? null, until: $today);
				$row['onBehalfOf'] = (in_array($uid, $managers, true) === true) ? null : implode(', ', $managers);
				$rows[] = $row;
			}
		}

		usort($rows, static fn (array $a, array $b): int => [($a['submittedAt'] ?? '9999'), $a['id']] <=> [($b['submittedAt'] ?? '9999'), $b['id']]);

		return $rows;
	}//end open()

	/**
	 * The requests this account decided in the last 90 days, newest first,
	 * each with its timeline.
	 *
	 * @param string $uid The caller.
	 * @param string $today The day, YYYY-MM-DD.
	 * @param string|null $kind Only this kind (leave, hours, expense, leave-trade).
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/approvals-inbox/spec.md#REQ-API-001
	 */
	public function decided(string $uid, string $today, ?string $kind = null): array {
		$since = (new DateTimeImmutable($today))->modify('-' . self::DECIDED_WINDOW_DAYS . ' days')->format('Y-m-d');
		$rows = [];
		foreach ($this->sources(kind: $kind) as $schema => $source) {
			foreach ($this->gateway->findFiltered($schema, ['approvedBy' => $uid]) as $request) {
				$decidedAt = substr((string)($request['approvedAt'] ?? ''), 0, 10);
				if ($decidedAt === '' || $decidedAt < $since || $this->readable(schema: $schema, request: $request) === false) {
					continue;
				}

				$rows[] = $this->withTimeline(row: $this->row(schema: $schema, source: $source, request: $request), request: $request);
			}
		}

		usort($rows, static fn (array $a, array $b): int => [(string)$b['decidedAt'], $b['id']] <=> [(string)$a['decidedAt'], $a['id']]);

		return $rows;
	}//end decided()

	/**
	 * The sources to read, narrowed to one kind when asked.
	 *
	 * @param string|null $kind The kind, or null for all.
	 *
	 * @return array<string, array{kind: string, route: string, from: string, to: string, size: string}>
	 */
	private function sources(?string $kind): array {
		if ($kind === null || $kind === '') {
			return self::SOURCES;
		}

		return array_filter(self::SOURCES, static fn (array $source): bool => $source['kind'] === $kind);
	}//end sources()

	/**
	 * Whether the caller may read this request under their own rights.
	 *
	 * @param string $schema The schema.
	 * @param array<string, mixed> $request The request.
	 *
	 * @return bool
	 */
	private function readable(string $schema, array $request): bool {
		$id = (string)($request['id'] ?? '');

		return $id !== '' && $this->rbac->findOrNull(id: $id, schema: $schema) !== null;
	}//end readable()

	/**
	 * The inbox row for one request.
	 *
	 * @param string $schema The schema.
	 * @param array{kind: string, route: string, from: string, to: string, size: string} $source Its source entry.
	 * @param array<string, mixed> $request The request.
	 *
	 * @return array<string, mixed>
	 */
	private function row(string $schema, array $source, array $request): array {
		$employeeId = (string)($request['employeeId'] ?? '');

		return [
			'id' => (string)($request['id'] ?? ''),
			'schema' => $schema,
			'kind' => $source['kind'],
			'route' => $source['route'],
			'employeeId' => $employeeId,
			'employee' => ($this->names()[$employeeId] ?? $employeeId),
			'from' => $this->text(value: $request[$source['from']] ?? null),
			'to' => $this->text(value: $request[$source['to']] ?? null),
			'size' => ($request[$source['size']] ?? null),
			'status' => (string)($request['status'] ?? ''),
			'submittedAt' => $this->text(value: $request['submittedAt'] ?? null),
			'decidedAt' => $this->text(value: $request['approvedAt'] ?? null),
			'decidedBy' => $this->text(value: $request['approvedBy'] ?? null),
			'rejectionReason' => $this->text(value: $request['rejectionReason'] ?? null),
		];
	}//end row()

	/**
	 * Add the request's timeline: submitted, then the verdict with who gave it and why.
	 *
	 * @param array<string, mixed> $row The row.
	 * @param array<string, mixed> $request The request.
	 *
	 * @return array<string, mixed>
	 */
	private function withTimeline(array $row, array $request): array {
		$verdict = ((string)($request['status'] ?? '') === 'rejected') ? 'rejected' : 'approved';
		$timeline = [];
		if ($row['submittedAt'] !== null) {
			$timeline[] = ['event' => 'submitted', 'at' => $row['submittedAt'], 'by' => $this->text(value: $request['userId'] ?? null), 'reason' => null];
		}

		$timeline[] = ['event' => $verdict, 'at' => $row['decidedAt'], 'by' => $row['decidedBy'], 'reason' => ($verdict === 'rejected' ? $row['rejectionReason'] : null)];
		$row['verdict'] = $verdict;
		$row['timeline'] = $timeline;

		return $row;
	}//end withTimeline()

	/**
	 * Employee display names by id.
	 *
	 * @return array<string, string>
	 */
	private function names(): array {
		if ($this->names === null) {
			$this->names = [];
			foreach ($this->gateway->loadAll('Employee') as $employee) {
				$name = trim((string)($employee['firstName'] ?? '') . ' ' . (string)($employee['lastName'] ?? ''));
				$this->names[(string)($employee['id'] ?? '')] = $name;
			}
		}

		return $this->names;
	}//end names()

	/**
	 * Whole days from a stored moment to a day, or null without a moment.
	 *
	 * @param mixed $from The stored moment.
	 * @param string $until The day, YYYY-MM-DD.
	 *
	 * @return int|null
	 */
	private function daysBetween(mixed $from, string $until): ?int {
		$day = substr(trim((string)($from ?? '')), 0, 10);
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
			return null;
		}

		return max(0, (int)(new DateTimeImmutable($day))->diff(new DateTimeImmutable($until))->format('%r%a'));
	}//end daysBetween()

	/**
	 * A stored scalar as text, or null when empty.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null
	 */
	private function text(mixed $value): ?string {
		if ($value === null || is_array($value) === true) {
			return null;
		}

		$text = trim((string)$value);

		return $text === '' ? null : $text;
	}//end text()

}//end class
