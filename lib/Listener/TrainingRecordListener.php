<?php

/**
 * Humaniq TrainingRecordListener
 *
 * Listens on OpenRegister's object events for `TrainingRecord`
 * (talent-training-and-lms D1 and D2).
 *
 * - Before a record is saved as `gevolgd`: the completion date defaults to
 *   the planned day (or today), the validity to the completion date plus
 *   `validityMonths`, and the administration to the employee's. A group
 *   course is planned with its validity in months, so registering attendance
 *   from the index fills in the date the certificate runs to.
 * - After a record is saved as `gevolgd`: TrainingCompetenceWriter grants or
 *   extends the competence it names.
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
 * @spec openspec/specs/training-and-lms-sync/spec.md#REQ-TRN-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\TrainingCompetenceWriter;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Stamps an attended training and grants its competence.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/training-and-lms-sync/spec.md#REQ-TRN-001
 */
class TrainingRecordListener implements IEventListener {

	/**
	 * Lower-cased slug of the training schema.
	 *
	 * @var string
	 */
	public const TRAINING_SLUG = 'trainingrecord';

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway     $gateway Resolves slugs.
	 * @param TrainingCompetenceWriter $writer  Stamps the record and grants the competence.
	 * @param LoggerInterface          $logger  Logger.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly TrainingCompetenceWriter $writer,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Stamp before the save, grant after it.
	 *
	 * @param Event $event The object event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/training-and-lms-sync/spec.md#REQ-TRN-001
	 */
	public function handle(Event $event): void {
		$entity = $this->entityOf($event);
		if ($entity === null || strtolower($this->gateway->resolveSchemaSlug((string)$entity->getSchema())) !== self::TRAINING_SLUG) {
			return;
		}

		$record = ($entity->getObject() ?? []);
		try {
			if ($event instanceof ObjectCreatingEvent || $event instanceof ObjectUpdatingEvent) {
				$stamps = $this->writer->stamps($record);
				if ($stamps !== []) {
					$event->setModifiedData($stamps);
				}

				return;
			}

			$this->writer->apply($record);
		} catch (\Throwable $e) {
			$this->logger->warning('humaniq: TrainingRecordListener could not process a training record', ['exception' => $e->getMessage()]);
		}
	}//end handle()

	/**
	 * The entity an event is about, or null for any other event.
	 *
	 * @param Event $event The event.
	 *
	 * @return object|null
	 */
	private function entityOf(Event $event): ?object {
		if ($event instanceof ObjectCreatingEvent || $event instanceof ObjectCreatedEvent) {
			return $event->getObject();
		}

		if ($event instanceof ObjectUpdatingEvent || $event instanceof ObjectUpdatedEvent) {
			return $event->getNewObject();
		}

		return null;
	}//end entityOf()

}//end class
