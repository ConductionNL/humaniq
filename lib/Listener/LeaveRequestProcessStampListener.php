<?php

/**
 * Humaniq Leave Request Process Stamp Listener
 *
 * Pre-save stamping of a leave request's process fields, mirroring
 * TimesheetProcessStampListener. On create the request starts as a draft with
 * empty process fields. On update the stored `submittedAt`, `approvedBy`,
 * `approvedAt` and `decisions` are restored against client input, and the
 * lifecycle edge in the write is stamped from the session user and the clock:
 *
 * | edge                               | stamps                                                   |
 * |------------------------------------|----------------------------------------------------------|
 * | to `submitted`                     | `submittedAt`; clears `approvedBy` and `approvedAt`      |
 * | `submitted` to `manager-approved`  | appends the manager's decision                           |
 * | to `approved`                      | `approvedBy`, `approvedAt`; appends the step's decision  |
 * | to `rejected`                      | `approvedBy`, `approvedAt`, `rejectionReason`; appends   |
 *
 * `decisions` is never cleared, so a resubmitted request keeps its history.
 * The only client values accepted are the decision remark (`decisionRemark`,
 * not stored on its own) and `rejectionReason`.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/leave-approval-steps-and-history/specs/leave-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Stamps who decided a leave request, and when, inside the carrying write.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/leave-approval-steps-and-history/specs/leave-management/spec.md
 */
class LeaveRequestProcessStampListener implements IEventListener {

	public const LEAVE_REQUEST_SLUG = 'leaverequest';

	/**
	 * Fields only the server writes.
	 */
	public const PROCESS_FIELDS = ['submittedAt', 'approvedBy', 'approvedAt', 'rejectionReason', 'decisions'];

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway     Resolves schema slugs and reads the stored request.
	 * @param IUserSession         $userSession The deciding user.
	 * @param LoggerInterface      $logger      PSR logger.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle an ObjectCreatingEvent or ObjectUpdatingEvent.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/leave-approval-steps-and-history/specs/leave-management/spec.md
	 */
	public function handle(Event $event): void {
		try {
			if ($event instanceof \OCA\OpenRegister\Event\ObjectCreatingEvent) {
				$this->stampCreating(event: $event);
				return;
			}

			if ($event instanceof \OCA\OpenRegister\Event\ObjectUpdatingEvent) {
				$this->stampUpdating(event: $event);
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				'humaniq: LeaveRequestProcessStampListener could not stamp a LeaveRequest write',
				['exception' => $e->getMessage()]
			);
			$this->refuse(event: $event, message: 'De verlofaanvraag kon niet worden verwerkt; probeer het later opnieuw.');
		}//end try
	}//end handle()

	/**
	 * A new request starts as a draft with empty process fields.
	 *
	 * @param \OCA\OpenRegister\Event\ObjectCreatingEvent $event The event.
	 *
	 * @return void
	 */
	private function stampCreating(\OCA\OpenRegister\Event\ObjectCreatingEvent $event): void {
		$entity = $event->getObject();
		if ($this->isLeaveRequest(entity: $entity) === false) {
			return;
		}

		$modified = ['status' => 'draft', 'decisions' => []];
		foreach (['submittedAt', 'approvedBy', 'approvedAt', 'rejectionReason'] as $field) {
			$modified[$field] = null;
		}

		$event->setModifiedData($modified);
	}//end stampCreating()

	/**
	 * Restore the stored process fields and stamp the edge.
	 *
	 * @param \OCA\OpenRegister\Event\ObjectUpdatingEvent $event The event.
	 *
	 * @return void
	 */
	private function stampUpdating(\OCA\OpenRegister\Event\ObjectUpdatingEvent $event): void {
		$entity = $event->getNewObject();
		if ($this->isLeaveRequest(entity: $entity) === false) {
			return;
		}

		$stored = $event->getOldObject()?->getObject();
		if (is_array($stored) === false) {
			$stored = $this->gateway->findObjectData((string)$entity->getUuid(), 'LeaveRequest');
		}

		if ($stored === null) {
			// Without the stored values the history cannot be kept: refuse
			// rather than let a possibly tampered write through.
			$this->refuse(event: $event, message: 'De verlofaanvraag kon niet worden geladen; de wijziging is niet opgeslagen.');
			return;
		}

		$event->setModifiedData($this->stampUpdate(incoming: ($entity->getObject() ?? []), stored: $stored));
	}//end stampUpdating()

	/**
	 * The process fields as they must be stored after this write.
	 *
	 * @param array<string, mixed> $incoming The written request.
	 * @param array<string, mixed> $stored   The request as stored.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/leave-approval-steps-and-history/specs/leave-management/spec.md
	 */
	public function stampUpdate(array $incoming, array $stored): array {
		$modified = [];
		foreach (self::PROCESS_FIELDS as $field) {
			$modified[$field] = ($stored[$field] ?? null);
		}

		if (is_array($modified['decisions']) === false) {
			$modified['decisions'] = [];
		}

		return array_merge($modified, $this->stampEdge(incoming: $incoming, stored: $stored, decisions: $modified['decisions']));
	}//end stampUpdate()

	/**
	 * The stamps of the lifecycle edge in the write, or none.
	 *
	 * @param array<string, mixed>   $incoming  The written request.
	 * @param array<string, mixed>   $stored    The request as stored.
	 * @param array<int, mixed>      $decisions The stored decisions.
	 *
	 * @return array<string, mixed>
	 */
	private function stampEdge(array $incoming, array $stored, array $decisions): array {
		$from = strtolower(trim((string)($stored['status'] ?? '')));
		$to   = strtolower(trim((string)($incoming['status'] ?? '')));
		if ($from === $to) {
			return [];
		}

		return match ($to) {
			'submitted' => ['submittedAt' => $this->now(), 'approvedBy' => null, 'approvedAt' => null],
			'manager-approved' => ['decisions' => $this->withDecision(decisions: $decisions, step: 'manager', decision: 'approved', incoming: $incoming)],
			'approved', 'rejected' => $this->verdict(from: $from, to: $to, incoming: $incoming, decisions: $decisions),
			default => [],
		};
	}//end stampEdge()

	/**
	 * The stamps of a final decision.
	 *
	 * @param string               $from      The stored status.
	 * @param string               $to        approved or rejected.
	 * @param array<string, mixed> $incoming  The written request.
	 * @param array<int, mixed>    $decisions The stored decisions.
	 *
	 * @return array<string, mixed>
	 */
	private function verdict(string $from, string $to, array $incoming, array $decisions): array {
		$step = 'manager';
		if ($from === 'manager-approved') {
			$step = 'hr';
		}

		$stamps = [
			'approvedBy' => $this->userId(),
			'approvedAt' => $this->now(),
			'decisions' => $this->withDecision(decisions: $decisions, step: $step, decision: $to, incoming: $incoming),
		];
		if ($to === 'rejected') {
			$stamps['rejectionReason'] = self::nullableTrim(value: ($incoming['rejectionReason'] ?? null));
		}

		return $stamps;
	}//end verdict()

	/**
	 * The decisions with this one appended.
	 *
	 * @param array<int, mixed>    $decisions The stored decisions.
	 * @param string               $step      manager or hr.
	 * @param string               $decision  approved or rejected.
	 * @param array<string, mixed> $incoming  The written request, for the remark.
	 *
	 * @return array<int, mixed>
	 */
	private function withDecision(array $decisions, string $step, string $decision, array $incoming): array {
		$remark = self::nullableTrim(value: ($incoming['decisionRemark'] ?? ($incoming['rejectionReason'] ?? null)));
		if ($decision === 'approved') {
			$remark = self::nullableTrim(value: ($incoming['decisionRemark'] ?? null));
		}

		$decisions[] = [
			'step' => $step,
			'decision' => $decision,
			'by' => (string)($this->userId() ?? ''),
			'at' => $this->now(),
			'remark' => $remark,
		];

		return array_values($decisions);
	}//end withDecision()

	/**
	 * Whether the written object is a LeaveRequest.
	 *
	 * @param object $entity The written entity.
	 *
	 * @return bool
	 */
	private function isLeaveRequest(object $entity): bool {
		return strtolower($this->gateway->resolveSchemaSlug((string)$entity->getSchema())) === self::LEAVE_REQUEST_SLUG;
	}//end isLeaveRequest()

	/**
	 * The session user's id, or null.
	 *
	 * @return string|null
	 */
	private function userId(): ?string {
		return self::nullableTrim(value: $this->userSession->getUser()?->getUID());
	}//end userId()

	/**
	 * Now, as an ISO 8601 UTC timestamp.
	 *
	 * @return string
	 */
	private function now(): string {
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
	}//end now()

	/**
	 * Refuse the write with a message.
	 *
	 * @param object $event   The event.
	 * @param string $message The message.
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
	 * A trimmed string, or null when empty.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null
	 */
	private static function nullableTrim(mixed $value): ?string {
		$trimmed = trim((string)($value ?? ''));
		if ($trimmed === '') {
			return null;
		}

		return $trimmed;
	}//end nullableTrim()
}//end class
