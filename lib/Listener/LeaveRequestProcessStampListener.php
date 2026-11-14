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

	use StampsProcessFields;

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
		$this->stampOrRefuse(
			event: $event,
			failure: 'humaniq: LeaveRequestProcessStampListener could not stamp a LeaveRequest write',
			refusal: 'De verlofaanvraag kon niet worden verwerkt; probeer het later opnieuw.'
		);
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

		$edge = $this->stampEdge(incoming: $incoming, stored: $stored);
		$decision = ($edge['decision'] ?? null);
		unset($edge['decision']);
		if ($decision !== null) {
			$modified['decisions'] = array_values([...$modified['decisions'], $this->decisionEntry(decision: $decision, incoming: $incoming)]);
		}

		return array_merge($modified, $edge);
	}//end stampUpdate()

	/**
	 * The stamps of a status change, with the decision to record under `decision`.
	 *
	 * @param array<string, mixed> $incoming The incoming payload.
	 * @param array<string, mixed> $stored   The stored request.
	 *
	 * @return array<string, mixed> Stamps to merge, and `decision` (`step`, `decision`) when a decision was made.
	 */
	private function stampEdge(array $incoming, array $stored): array {
		$from = strtolower(trim((string)($stored['status'] ?? '')));
		$to   = strtolower(trim((string)($incoming['status'] ?? '')));
		if ($from === $to) {
			return [];
		}

		return match ($to) {
			'submitted' => ['submittedAt' => $this->now(), 'approvedBy' => null, 'approvedAt' => null],
			'manager-approved' => ['decision' => ['step' => 'manager', 'decision' => 'approved']],
			'approved', 'rejected' => $this->verdict(from: $from, incoming: $incoming),
			default => [],
		};
	}//end stampEdge()

	/**
	 * The stamps of a final approval or rejection, by the manager or, after the manager, by HR.
	 *
	 * @param string               $from     The stored status.
	 * @param array<string, mixed> $incoming The incoming payload, its status `approved` or `rejected`.
	 *
	 * @return array<string, mixed>
	 */
	private function verdict(string $from, array $incoming): array {
		$to = strtolower(trim((string)($incoming['status'] ?? '')));
		$step = 'manager';
		if ($from === 'manager-approved') {
			$step = 'hr';
		}

		$stamps = [
			'approvedBy' => $this->userId(),
			'approvedAt' => $this->now(),
			'decision' => ['step' => $step, 'decision' => $to],
		];
		if ($to === 'rejected') {
			$stamps['rejectionReason'] = self::nullableTrim(value: ($incoming['rejectionReason'] ?? null));
		}

		return $stamps;
	}//end verdict()

	/**
	 * One entry of the decision history: the step and decision, by whom, when, and the remark.
	 *
	 * @param array{step: string, decision: string} $decision The step and the decision.
	 * @param array<string, mixed>                  $incoming The incoming payload.
	 *
	 * @return array<string, mixed>
	 */
	private function decisionEntry(array $decision, array $incoming): array {
		$remark = self::nullableTrim(value: ($incoming['decisionRemark'] ?? ($incoming['rejectionReason'] ?? null)));
		if ($decision['decision'] === 'approved') {
			$remark = self::nullableTrim(value: ($incoming['decisionRemark'] ?? null));
		}

		return [
			'step' => $decision['step'],
			'decision' => $decision['decision'],
			'by' => (string)($this->userId() ?? ''),
			'at' => $this->now(),
			'remark' => $remark,
		];
	}//end decisionEntry()

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

}//end class
