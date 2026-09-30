<?php

/**
 * Humaniq CandidateEvaluationStampListener
 *
 * The evaluator of a candidate evaluation is whoever is signed in when it is
 * created, never what the form sent, and an edit cannot move it to someone
 * else (hiring-candidate-assessment D1, the TimeEntryStampListener pattern:
 * the renderer has no create-form token defaults).
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
 * @spec openspec/changes/hiring-candidate-assessment/specs/candidate-assessment/spec.md#REQ-CAS-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;

/**
 * Stamps the evaluator and the moment on a candidate evaluation.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/hiring-candidate-assessment/specs/candidate-assessment/spec.md#REQ-CAS-001
 */
class CandidateEvaluationStampListener implements IEventListener {

	/**
	 * Lower-cased slug of the evaluation schema.
	 */
	public const SLUG = 'candidateevaluation';

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway     Resolves the schema slug.
	 * @param IUserSession         $userSession The signed-in user.
	 *
	 * @spec openspec/changes/hiring-candidate-assessment/specs/candidate-assessment/spec.md#REQ-CAS-001
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * Stamp on create; keep the evaluator on an edit.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/hiring-candidate-assessment/specs/candidate-assessment/spec.md#REQ-CAS-001
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent) {
			if ($this->isEvaluation(entity: $event->getObject()) === false) {
				return;
			}

			$event->setModifiedData(
				[
					'evaluatorUserId' => (string)($this->userSession->getUser()?->getUID() ?? ''),
					'submittedAt' => gmdate('c'),
				]
			);
			return;
		}

		if ($event instanceof ObjectUpdatingEvent && $this->isEvaluation(entity: $event->getNewObject()) === true) {
			$old = ($event->getOldObject()?->getObject() ?? []);
			$event->setModifiedData(['evaluatorUserId' => (string)($old['evaluatorUserId'] ?? '')]);
		}
	}//end handle()

	/**
	 * Whether the entity is a candidate evaluation.
	 *
	 * @param object $entity The object entity.
	 *
	 * @return boolean
	 */
	private function isEvaluation(object $entity): bool {
		return strtolower($this->gateway->resolveSchemaSlug((string)$entity->getSchema())) === self::SLUG;
	}//end isEvaluation()

}//end class
