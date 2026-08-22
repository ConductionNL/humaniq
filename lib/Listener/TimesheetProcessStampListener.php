<?php

/**
 * Humaniq TimesheetProcessStampListener
 *
 * Pre-save listener on OpenRegister's `ObjectCreatingEvent` /
 * `ObjectUpdatingEvent` for the `Timesheet` schema (hours-process-redesign
 * Decision 4). It makes the process fields (`submittedAt`, `approvedBy`,
 * `approvedAt`, `rejectionReason`), the server-recomputed aggregates and the
 * identity caches INERT to client input, and stamps the process fields on
 * the lifecycle edges of the carrying write:
 *
 * - CREATE: force `status: "draft"`, null every process field regardless of
 *   input, stamp `userId`/`managerUserId`/`administrationId` (Decision 5).
 * - UPDATE: restore the stored values of every process field and (for
 *   non-internal writes) every aggregate; re-derive the identity caches from
 *   the employee/org chain (shared OrgResolutionService, so stamp and audit
 *   cannot disagree); then stamp the status edge found in the now-sanitised
 *   write — submit stamps `submittedAt` and clears the approval fields,
 *   approve/reject stamp `approvedBy` (acting session uid) + `approvedAt`
 *   (reject additionally accepts the incoming `rejectionReason`, the single
 *   allowlisted client-supplied process value), reopen clears all four.
 *
 * Because stamping happens INSIDE the same write that flips `status`, the
 * post-save `ObjectUpdatedEvent` that `TimesheetApprovalListener` consumes
 * carries real provenance — the CloudEvent gains correct `approvedBy` /
 * `approvedAt` with zero change to `TimeEntryEventService` (Decision 7).
 * The mutate-and-persist channel (`setModifiedData()`) is proven by
 * OpenRegisterPreSaveMutationContractTest (task V1, must-fail control).
 *
 * humaniq's own internal writes (aggregate recompute, migration) carry the
 * request-scoped {@see InternalWriteMarker}: under it the aggregates pass
 * through untouched, but process-field inertness still applies — an internal
 * writer may maintain totals, never fabricate approvals.
 *
 * All register plumbing lives in {@see HoursRegisterGateway} (which clones
 * ObjectService per use); this class carries only the decisions.
 *
 * @category Listener
 * @package  OCA\Humaniq\Listener
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
 * @spec openspec/changes/humaniq-hours-process-redesign/specs/humaniq-timesheet-approval/spec.md#Requirement:-Process-fields-are-server-stamped-and-inert-to-client-input
 * @spec openspec/changes/humaniq-hours-process-redesign/specs/mss-team-scope/spec.md#Requirement:-The-approval-carrying-schemas-SHALL-gain-an-optional-denormalized-managerUserId-scoping-property-(REQ-MSS-001)
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Pre-save inertness + lifecycle stamping for Timesheet writes.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/humaniq-hours-process-redesign/specs/humaniq-timesheet-approval/spec.md#Requirement:-Process-fields-are-server-stamped-and-inert-to-client-input
 */
class TimesheetProcessStampListener implements IEventListener {

	/**
	 * Lower-cased slug of the schema this listener stamps.
	 *
	 * @var string
	 */
	public const TIMESHEET_SLUG = 'timesheet';

	/**
	 * The lifecycle-stamped process fields (inert to client input).
	 *
	 * @var array<int, string>
	 */
	public const PROCESS_FIELDS = ['submittedAt', 'approvedBy', 'approvedAt', 'rejectionReason'];

	/**
	 * The server-recomputed aggregates (inert outside internal writes).
	 *
	 * @var array<int, string>
	 */
	public const AGGREGATE_FIELDS = ['hours', 'entryCount', 'projectId', 'costCenter', 'billable'];

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway The shared register plumbing + org-chain lookups.
	 * @param IUserSession $userSession The user session (acting uid for edge stamps).
	 * @param InternalWriteMarker $marker The request-scoped internal-writer marker.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly IUserSession $userSession,
		private readonly InternalWriteMarker $marker,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Handle a pre-save event for a Timesheet write.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/humaniq-hours-process-redesign/specs/humaniq-timesheet-approval/spec.md#Requirement:-Process-fields-are-server-stamped-and-inert-to-client-input
	 */
	public function handle(Event $event): void {
		try {
			if ($event instanceof \OCA\OpenRegister\Event\ObjectCreatingEvent) {
				$entity = $event->getObject();
				if ($this->isTimesheet($entity) === true) {
					$event->setModifiedData($this->stampCreate($entity->getObject() ?? []));
				}

				return;
			}

			if (($event instanceof \OCA\OpenRegister\Event\ObjectUpdatingEvent) === false) {
				return;
			}

			$entity = $event->getNewObject();
			if ($this->isTimesheet($entity) === false) {
				return;
			}

			$stored = $event->getOldObject()?->getObject();
			if (is_array($stored) === false) {
				$stored = $this->gateway->findObjectData((string)$entity->getUuid(), 'Timesheet');
			}

			if ($stored === null) {
				// Without the stored values the inertness guarantee cannot
				// be established — refuse rather than let a possibly
				// tampered write through (fail closed).
				$this->refuse($event, 'De urenstaat kon niet worden geladen; de wijziging is niet opgeslagen.');
				return;
			}

			$event->setModifiedData($this->stampUpdate(($entity->getObject() ?? []), $stored));
		} catch (\Throwable $e) {
			$this->logger->warning(
				'humaniq: TimesheetProcessStampListener could not stamp a Timesheet write',
				['exception' => $e->getMessage()]
			);
			$this->refuse($event, 'De urenstaat kon niet worden verwerkt; probeer het later opnieuw.');
		}//end try

	}//end handle()

	/**
	 * Whether the entity is a Timesheet (slug gate, defence in depth under
	 * the subscription-level filter).
	 *
	 * @param object $entity The ObjectEntity.
	 *
	 * @return bool True for the Timesheet schema.
	 */
	private function isTimesheet(object $entity): bool {
		return strtolower($this->gateway->resolveSchemaSlug((string)$entity->getSchema())) === self::TIMESHEET_SLUG;
	}//end isTimesheet()

	/**
	 * CREATE: force draft, null every process field, stamp identity caches.
	 *
	 * @param array<string, mixed> $incoming The incoming payload.
	 *
	 * @return array<string, mixed> The modified-data map.
	 *
	 * @spec openspec/changes/humaniq-hours-process-redesign/specs/humaniq-timesheet-approval/spec.md#Requirement:-Process-fields-are-server-stamped-and-inert-to-client-input
	 */
	private function stampCreate(array $incoming): array {
		$modified = ['status' => 'draft'];
		foreach (self::PROCESS_FIELDS as $field) {
			$modified[$field] = null;
		}

		return array_merge(
			$modified,
			$this->deriveIdentityCaches(
				employeeId: trim((string)($incoming['employeeId'] ?? '')),
				fallback: []
			)
		);
	}//end stampCreate()

	/**
	 * UPDATE: restore inert fields, re-derive caches, stamp the status edge.
	 *
	 * @param array<string, mixed> $incoming The incoming payload.
	 * @param array<string, mixed> $stored The stored payload.
	 *
	 * @return array<string, mixed> The modified-data map.
	 *
	 * @spec openspec/changes/humaniq-hours-process-redesign/specs/humaniq-timesheet-approval/spec.md#Requirement:-Process-fields-are-server-stamped-and-inert-to-client-input
	 */
	private function stampUpdate(array $incoming, array $stored): array {
		$modified = [];

		// Inertness: client input to process fields is discarded — the stored
		// values persist, whatever surface the write came from.
		foreach (self::PROCESS_FIELDS as $field) {
			$modified[$field] = ($stored[$field] ?? null);
		}

		// Aggregates: inert to clients; humaniq's own aggregation/migration
		// writes (marker) carry them through untouched.
		if ($this->marker->isInternal() === false) {
			foreach (self::AGGREGATE_FIELDS as $field) {
				$modified[$field] = ($stored[$field] ?? null);
			}
		}

		// Identity caches: re-derived from the employee/org chain on every
		// write (REQ-MSS-001 / REQ-MHS-002) — the chain wins, client input is
		// inert; on a derivation failure the stored values are kept.
		$employeeId = trim((string)($incoming['employeeId'] ?? ''));
		if ($employeeId === '') {
			$employeeId = trim((string)($stored['employeeId'] ?? ''));
		}

		$modified = array_merge($modified, $this->deriveIdentityCaches(employeeId: $employeeId, fallback: $stored));

		return array_merge($modified, $this->stampEdge($incoming, $stored));
	}//end stampUpdate()

	/**
	 * The lifecycle-edge stamps for the (sanitised) write, keyed by field.
	 *
	 * @param array<string, mixed> $incoming The incoming payload.
	 * @param array<string, mixed> $stored The stored payload.
	 *
	 * @return array<string, mixed> Stamps for the detected edge (possibly empty).
	 *
	 * @spec openspec/changes/humaniq-hours-process-redesign/specs/humaniq-timesheet-approval/spec.md#Requirement:-Process-fields-are-server-stamped-and-inert-to-client-input
	 */
	private function stampEdge(array $incoming, array $stored): array {
		$from = strtolower(trim((string)($stored['status'] ?? '')));
		$to = strtolower(trim((string)($incoming['status'] ?? '')));

		// Same-status writes and unknown pairs fall through to the empty
		// default — only genuine lifecycle edges stamp.
		switch ($from . '>' . $to) {
			case 'draft>submitted':
			case 'rejected>submitted':
			case '>submitted':
				return $this->submitStamps();

			case 'submitted>approved':
			case 'submitted>rejected':
				return $this->verdictStamps($to, $incoming);

			case 'approved>draft':
				return $this->clearedProcessFields();

			default:
				return [];
		}
	}//end stampEdge()

	/**
	 * The submit-edge stamps: a fresh submission timestamp, approval fields
	 * cleared (a re-submission clears the previous verdict).
	 *
	 * @return array<string, mixed> The stamps.
	 */
	private function submitStamps(): array {
		$stamps = $this->clearedProcessFields();
		$stamps['submittedAt'] = $this->now();

		return $stamps;
	}//end submitStamps()

	/**
	 * The approve/reject-edge stamps: the ACTING session user and a
	 * timestamp; on reject additionally the incoming `rejectionReason` — the
	 * single allowlisted client-supplied process value, accepted ONLY on
	 * this edge (Decision 4 / the D1 transition-input contract).
	 *
	 * @param string $verdict 'approved' or 'rejected'.
	 * @param array<string, mixed> $incoming The incoming payload.
	 *
	 * @return array<string, mixed> The stamps.
	 */
	private function verdictStamps(string $verdict, array $incoming): array {
		$stamps = [
			'approvedBy' => $this->nullableTrim($this->userSession->getUser()?->getUID()),
			'approvedAt' => $this->now(),
		];
		if ($verdict === 'rejected') {
			$stamps['rejectionReason'] = $this->nullableTrim($incoming['rejectionReason'] ?? null);
		}

		return $stamps;
	}//end verdictStamps()

	/**
	 * All four process fields cleared (the reopen edge; the base of the
	 * submit edge).
	 *
	 * @return array<string, null> The cleared fields.
	 */
	private function clearedProcessFields(): array {
		return [
			'submittedAt' => null,
			'approvedBy' => null,
			'approvedAt' => null,
			'rejectionReason' => null,
		];
	}//end clearedProcessFields()

	/**
	 * Derive `userId` / `administrationId` (from the Employee) and
	 * `managerUserId` (from the shared org chain, unique-or-null). On any
	 * lookup failure the fallback values are kept — a transient outage must
	 * not wipe valid caches.
	 *
	 * @param string $employeeId The employee id ('' derives nothing).
	 * @param array<string, mixed> $fallback The values to keep on failure (stored payload, or [] on create).
	 *
	 * @return array<string, mixed> The three cache values.
	 *
	 * @spec openspec/changes/humaniq-hours-process-redesign/specs/mss-team-scope/spec.md#Requirement:-The-approval-carrying-schemas-SHALL-gain-an-optional-denormalized-managerUserId-scoping-property-(REQ-MSS-001)
	 */
	private function deriveIdentityCaches(string $employeeId, array $fallback): array {
		$kept = [
			'userId' => ($fallback['userId'] ?? null),
			'managerUserId' => ($fallback['managerUserId'] ?? null),
			'administrationId' => ($fallback['administrationId'] ?? null),
		];

		if ($employeeId === '') {
			return $kept;
		}

		try {
			$employee = $this->gateway->findObjectData($employeeId, 'Employee');
			if ($employee === null) {
				// Unresolvable Employee: an infra failure and a dangling id
				// are indistinguishable here — keep the stored caches rather
				// than wiping possibly-valid values on a flake; the migration
				// summary and the consistency audits surface true danglers.
				return $kept;
			}

			return [
				'userId' => $this->nullableTrim($employee['nextcloudUserId'] ?? null),
				'managerUserId' => $this->gateway->uniqueManagerUserIdFor($employeeId, gmdate('Y-m-d')),
				'administrationId' => $this->nullableTrim($employee['administrationId'] ?? null),
			];
		} catch (\Throwable $e) {
			$this->logger->info(
				'humaniq: identity-cache derivation failed; keeping stored values',
				['exception' => $e->getMessage()]
			);

			return $kept;
		}//end try

	}//end deriveIdentityCaches()

	/**
	 * Current UTC timestamp in ISO 8601 (the TimeEntryEventService format).
	 *
	 * @return string The timestamp.
	 */
	private function now(): string {
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
	}//end now()

	/**
	 * Refuse the write with a structured, user-facing error.
	 *
	 * @param object $event The pre-save event.
	 * @param string $message The Dutch user-facing message.
	 *
	 * @return void
	 */
	private function refuse(object $event, string $message): void {
		if (method_exists($event, 'setErrors') === true) {
			$event->setErrors(['message' => $message]);
		}

		if (method_exists($event, 'stopPropagation') === true) {
			$event->stopPropagation();
		}
	}//end refuse()

	/**
	 * Trim to a non-empty string or null.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string|null The trimmed value, or null.
	 */
	private function nullableTrim(mixed $value): ?string {
		$trimmed = trim((string)($value ?? ''));

		return $trimmed === '' ? null : $trimmed;
	}//end nullableTrim()

}//end class
