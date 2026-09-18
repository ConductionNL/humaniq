<?php

/**
 * Working Pattern Overlap Listener
 *
 * Pre-save listener on OpenRegister's `ObjectCreatingEvent` /
 * `ObjectUpdatingEvent` for `WorkingPattern` writes. It refuses a pattern
 * whose period overlaps one this employee already has.
 *
 * WHY THIS IS A WRITE-TIME REFUSAL AND NOT A READ-TIME PREFERENCE
 * ---------------------------------------------------------------
 * {@see \OCA\Humaniq\Service\WorkingHoursService} resolves the pattern in
 * force by taking the first that covers the date. With two overlapping
 * patterns that is whichever the object store happened to return first, so the
 * contracted hours of a person change with the ordering of a query and nothing
 * anywhere looks wrong. A refused write is visible. A silently chosen answer
 * is not, which is the whole reason the spec makes this a refusal
 * (REQ-WHP-001).
 *
 * The check runs on the write itself rather than in a service the UI calls,
 * because the pages are declarative: a pattern is created straight through
 * OpenRegister's object API, and a rule that only a controller enforces is a
 * rule the actual write path never sees.
 *
 * FAIL CLOSED
 * -----------
 * A payload whose employee cannot be read, or an overlap scan that throws, is
 * refused rather than allowed. An accepted pattern that should not exist is
 * repaired by hand; a refused one is retried.
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
 * @spec openspec/specs/working-hours-per-person/spec.md#REQ-WHP-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\OverlappingWorkingPatternException;
use OCA\Humaniq\Service\WorkingHoursService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Refuses a WorkingPattern write that would give one employee two answers for
 * the same day.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/working-hours-per-person/spec.md#REQ-WHP-001
 */
class WorkingPatternOverlapListener implements IEventListener {

	/**
	 * Lower-cased slug of the schema this listener guards.
	 *
	 * @var string
	 */
	public const WORKINGPATTERN_SLUG = 'workingpattern';

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway The shared register plumbing.
	 * @param WorkingHoursService $workingHours The overlap rule itself.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly WorkingHoursService $workingHours,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Handle a pre-save event for a WorkingPattern write.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/working-hours-per-person/spec.md#REQ-WHP-001
	 */
	public function handle(Event $event): void {
		$incoming = $this->incoming($event);
		if ($incoming === null) {
			return;
		}

		[$payload, $uuid] = $incoming;

		try {
			$this->guard(payload: $payload, uuid: $uuid);
		} catch (OverlappingWorkingPatternException $e) {
			$this->refuse($event, $e->getMessage());
		} catch (\Throwable $e) {
			// Fail closed: an unchecked pattern is a second answer waiting to
			// happen, and nothing downstream would report it.
			$this->logger->warning(
				'humaniq: WorkingPatternOverlapListener could not check a WorkingPattern write',
				['exception' => $e->getMessage()]
			);
			$this->refuse(
				$event,
				'Het werkpatroon kon niet worden gecontroleerd op overlap; probeer het later opnieuw.'
			);
		}//end try

	}//end handle()

	/**
	 * The payload and uuid of a WorkingPattern write, or null for any other
	 * event or schema.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return array{0: array<string, mixed>, 1: string|null}|null The payload and the uuid being updated.
	 *
	 * @spec openspec/specs/working-hours-per-person/spec.md#REQ-WHP-001
	 */
	private function incoming(Event $event): ?array {
		if ($event instanceof \OCA\OpenRegister\Event\ObjectCreatingEvent) {
			$entity = $event->getObject();
			if ($this->isWorkingPattern($entity) === false) {
				return null;
			}

			return [($entity->getObject() ?? []), null];
		}

		if ($event instanceof \OCA\OpenRegister\Event\ObjectUpdatingEvent) {
			$entity = $event->getNewObject();
			if ($this->isWorkingPattern($entity) === false) {
				return null;
			}

			return [($entity->getObject() ?? []), (string)$entity->getUuid()];
		}

		return null;
	}//end incoming()

	/**
	 * Refuse the write when the incoming pattern overlaps a stored one.
	 *
	 * @param array<string, mixed> $payload The incoming WorkingPattern payload.
	 * @param string|null $uuid The uuid being updated, so a pattern does not overlap itself.
	 *
	 * @return void
	 *
	 * @throws OverlappingWorkingPatternException When it overlaps.
	 *
	 * @spec openspec/specs/working-hours-per-person/spec.md#REQ-WHP-001
	 */
	private function guard(array $payload, ?string $uuid): void {
		$employeeId = trim((string)($payload['employeeId'] ?? ''));
		if ($employeeId === '' || trim((string)($payload['validFrom'] ?? '')) === '') {
			// The schema refuses both before the write reaches the store; an
			// entry missing either cannot overlap anything.
			return;
		}

		$stored = $this->gateway->findFiltered('WorkingPattern', ['employeeId' => $employeeId]);

		$candidates = [];
		foreach ($stored as $row) {
			$rowId = trim((string)($row['id'] ?? ''));
			if ($uuid !== null && $rowId === $uuid) {
				// The row being edited is not its own overlap.
				continue;
			}

			$candidates[] = $row;
		}

		$candidates[] = $payload;

		$this->workingHours->assertPatternsDoNotOverlap(patterns: $candidates);
	}//end guard()

	/**
	 * Whether the entity is a WorkingPattern (slug gate, defence in depth
	 * under the subscription-level filter).
	 *
	 * @param object $entity The ObjectEntity.
	 *
	 * @return bool True for the WorkingPattern schema.
	 */
	private function isWorkingPattern(object $entity): bool {
		return (strtolower($this->gateway->resolveSchemaSlug((string)$entity->getSchema())) === self::WORKINGPATTERN_SLUG);
	}//end isWorkingPattern()

	/**
	 * Stop the write and carry the reason back to the caller.
	 *
	 * @param object $event The dispatched event.
	 * @param string $message The refusal, in the language of the person who sees it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/working-hours-per-person/spec.md#REQ-WHP-001
	 */
	private function refuse(object $event, string $message): void {
		if (method_exists($event, 'setErrors') === false || method_exists($event, 'stopPropagation') === false) {
			return;
		}

		$event->setErrors(['message' => $message]);
		$event->stopPropagation();
	}//end refuse()
}//end class
