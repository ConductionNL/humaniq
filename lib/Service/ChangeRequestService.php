<?php

/**
 * Humaniq ChangeRequestService
 *
 * The imperative half of an EmployeeChangeRequest
 * (people-record-change-approval D1, D2 and D4):
 *
 * - On create it places the request: the employee (the requester's own
 *   record when none is named), the proposed values (the `changes` object
 *   plus the flat address and bank fields of the self-service form), only
 *   fields the rule for its kind covers, the current values, the approver
 *   role from the rule, and the accounts the guard and the @me pages need.
 *   A kind with no approver is approved at once when the requester asks for
 *   their own record; for someone else's record it waits for HR.
 * - On a decision it records who decided and when, and refuses a rejection
 *   without a reason.
 * - Once approved it writes the values to the employee as one internal write,
 *   unless the record changed since the request was made.
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
 * @spec openspec/specs/employee-change-approval/spec.md#REQ-ECR-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserSession;

/**
 * Places, decides and applies employee change requests.
 *
 * @spec openspec/specs/employee-change-approval/spec.md#REQ-ECR-001
 */
class ChangeRequestService {

	/**
	 * The flat fields of the self-service form, merged into `changes`.
	 *
	 * @var list<string>
	 */
	public const FORM_FIELDS = ['straat', 'huisnummer', 'postcode', 'woonplaats', 'land', 'iban', 'tenaamstelling'];

	/**
	 * The request schema.
	 *
	 * @var string
	 */
	private const SCHEMA = 'EmployeeChangeRequest';

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway     Reads and writes humaniq's register.
	 * @param ChangeApprovalRules  $rules       The rule per kind of change.
	 * @param InternalWriteMarker  $marker      Marks the apply write as humaniq's own.
	 * @param IUserSession         $userSession The signed-in user.
	 * @param ITimeFactory         $time        Now.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly ChangeApprovalRules $rules,
		private readonly InternalWriteMarker $marker,
		private readonly IUserSession $userSession,
		private readonly ITimeFactory $time,
	) {

	}//end __construct()

	/**
	 * The stamps for a new request, or the reason it is refused.
	 *
	 * @param array<string, mixed> $request The request as it will be saved.
	 *
	 * @return array{stamps: array<string, mixed>, error: string|null}
	 *
	 * @spec openspec/specs/employee-change-approval/spec.md#REQ-ECR-003
	 */
	public function prepare(array $request): array {
		$uid = $this->currentUid();
		$employee = $this->employeeFor($request, $uid);
		if ($employee === null) {
			return $this->refusal('Er is geen medewerker gevonden voor dit wijzigingsverzoek.');
		}

		$kind = (string)($request['changeKind'] ?? '');
		$rule = ($this->rules->forAdministration($this->text($employee['administrationId'] ?? null))[$kind] ?? null);
		if ($rule === null) {
			return $this->refusal('Er is geen goedkeuringsregel voor dit soort wijziging.');
		}

		$changes = $this->proposedChanges($request);
		$outside = array_diff(array_keys($changes), $rule['fields']);
		if ($changes === [] || $outside !== []) {
			return $this->refusal($changes === [] ? 'Dit wijzigingsverzoek bevat geen wijziging.' : 'Deze velden horen niet bij dit soort wijziging: ' . implode(', ', $outside) . '.');
		}

		$previous = [];
		foreach (array_keys($changes) as $field) {
			$previous[$field] = ($employee[$field] ?? null);
		}

		$employeeId = (string)$employee['id'];
		$ownRecord = $uid !== '' && $uid === $this->text($employee['nextcloudUserId'] ?? null);
		$role = $rule['approverRole'];
		if ($role === ChangeApprovalRules::NO_APPROVER && $ownRecord === false) {
			// Only the employee's own request applies without a second person.
			$role = 'hr';
		}

		$stamps = [
			'employeeId' => $employeeId,
			'changes' => $changes,
			'previousValues' => $previous,
			'approverRole' => $role,
			'requestedBy' => $uid === '' ? null : $uid,
			'userId' => $this->text($employee['nextcloudUserId'] ?? null),
			'managerUserId' => $this->gateway->uniqueManagerUserIdFor($employeeId, $this->now()->format('Y-m-d')),
			'administrationId' => $this->text($employee['administrationId'] ?? null),
			'status' => (($request['status'] ?? null) === 'concept') ? 'concept' : 'ingediend',
		];
		if ($role === ChangeApprovalRules::NO_APPROVER && $stamps['status'] === 'ingediend') {
			$stamps['status'] = 'goedgekeurd';
			$stamps['decidedAt'] = $this->now()->format(DATE_ATOM);
		}

		return ['stamps' => $stamps, 'error' => null];
	}//end prepare()

	/**
	 * The stamps for a decision, or the reason it is refused.
	 *
	 * @param array<string, mixed> $old The request before.
	 * @param array<string, mixed> $new The request as it will be saved.
	 *
	 * @return array{stamps: array<string, mixed>, error: string|null}
	 *
	 * @spec openspec/specs/employee-change-approval/spec.md#REQ-ECR-001
	 */
	public function decide(array $old, array $new): array {
		$status = (string)($new['status'] ?? '');
		if ($status === (string)($old['status'] ?? '') || in_array($status, ['goedgekeurd', 'afgewezen'], true) === false) {
			return ['stamps' => [], 'error' => null];
		}

		if ($status === 'afgewezen' && $this->text($new['rejectionReason'] ?? null) === null) {
			return $this->refusal('Geef een reden op om dit wijzigingsverzoek af te wijzen.');
		}

		$uid = $this->currentUid();

		return ['stamps' => ['decidedBy' => $uid === '' ? null : $uid, 'decidedAt' => $this->now()->format(DATE_ATOM)], 'error' => null];
	}//end decide()

	/**
	 * Write an approved request's values to the employee, once.
	 *
	 * @param string               $requestId The request.
	 * @param array<string, mixed> $request   The request as saved.
	 *
	 * @return bool Whether the values were written.
	 *
	 * @spec openspec/specs/employee-change-approval/spec.md#REQ-ECR-001
	 */
	public function apply(string $requestId, array $request): bool {
		if (($request['status'] ?? null) !== 'goedgekeurd' || $this->text($request['appliedAt'] ?? null) !== null || $this->text($request['applyError'] ?? null) !== null) {
			return false;
		}

		$employeeId = (string)($request['employeeId'] ?? '');
		$employee = $this->gateway->findObjectData($employeeId, 'Employee');
		$changes = (is_array($request['changes'] ?? null) === true ? $request['changes'] : []);
		$previous = (is_array($request['previousValues'] ?? null) === true ? $request['previousValues'] : []);
		$stale = [];
		foreach (array_keys($changes) as $field) {
			if ($employee === null || $this->same($employee[$field] ?? null, $previous[$field] ?? null) === false) {
				$stale[] = (string)$field;
			}
		}

		$record = $this->withoutIdentity($request);
		if ($stale !== []) {
			$record['applyError'] = 'Niet doorgevoerd: ' . implode(', ', $stale) . ' is gewijzigd nadat het verzoek werd gedaan. Doe een nieuw verzoek.';
			$this->marker->runInternal(fn () => $this->gateway->save($record, self::SCHEMA, $requestId));
			return false;
		}

		$record['appliedAt'] = $this->now()->format(DATE_ATOM);
		$this->marker->runInternal(
			function () use ($employee, $changes, $employeeId, $record, $requestId): void {
				$this->gateway->save(array_merge($this->withoutIdentity((array)$employee), $changes), 'Employee', $employeeId);
				$this->gateway->save($record, self::SCHEMA, $requestId);
			}
		);

		return true;
	}//end apply()

	/**
	 * The employee a request is for: the named one, or the requester's own.
	 *
	 * @param array<string, mixed> $request The request.
	 * @param string               $uid     The requester.
	 *
	 * @return array<string, mixed>|null
	 */
	private function employeeFor(array $request, string $uid): ?array {
		$employeeId = $this->text($request['employeeId'] ?? null);
		if ($employeeId !== null) {
			$employee = $this->gateway->findObjectData($employeeId, 'Employee');
			return $employee === null ? null : array_merge($employee, ['id' => $employeeId]);
		}

		return $this->gateway->findEmployeeByUserId($uid);
	}//end employeeFor()

	/**
	 * The `changes` object plus the filled-in form fields.
	 *
	 * @param array<string, mixed> $request The request.
	 *
	 * @return array<string, mixed>
	 */
	private function proposedChanges(array $request): array {
		$changes = (is_array($request['changes'] ?? null) === true ? $request['changes'] : []);
		foreach (self::FORM_FIELDS as $field) {
			if ($this->text($request[$field] ?? null) !== null) {
				$changes[$field] = trim((string)$request[$field]);
			}
		}

		return $changes;
	}//end proposedChanges()

	/**
	 * Whether two stored values are the same value.
	 *
	 * @param mixed $left  One value.
	 * @param mixed $right The other.
	 *
	 * @return bool
	 */
	private function same(mixed $left, mixed $right): bool {
		if (is_numeric($left) === true && is_numeric($right) === true) {
			return (float)$left === (float)$right;
		}

		return $this->text($left) === $this->text($right);
	}//end same()

	/**
	 * A row without its id and metadata, for a save by id.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return array<string, mixed>
	 */
	private function withoutIdentity(array $row): array {
		unset($row['id'], $row['@self']);

		return $row;
	}//end withoutIdentity()

	/**
	 * A refusal.
	 *
	 * @param string $message Why.
	 *
	 * @return array{stamps: array<string, mixed>, error: string}
	 */
	private function refusal(string $message): array {
		return ['stamps' => [], 'error' => $message];
	}//end refusal()

	/**
	 * The signed-in user's uid, or ''.
	 *
	 * @return string
	 */
	private function currentUid(): string {
		return (string)$this->userSession->getUser()?->getUID();
	}//end currentUid()

	/**
	 * Now.
	 *
	 * @return \DateTimeInterface
	 */
	private function now(): \DateTimeInterface {
		return $this->time->getDateTime();
	}//end now()

	/**
	 * A trimmed non-empty string, or null.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string|null
	 */
	private function text(mixed $value): ?string {
		if (is_scalar($value) === false) {
			return null;
		}

		$trimmed = trim((string)$value);

		return $trimmed === '' ? null : $trimmed;
	}//end text()

}//end class
