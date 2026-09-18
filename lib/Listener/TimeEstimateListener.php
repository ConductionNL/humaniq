<?php

/**
 * Time Estimate Listener
 *
 * Pre-save listener on OpenRegister's `ObjectCreatingEvent` /
 * `ObjectUpdatingEvent` for two schemas:
 *
 * - a `TimeEstimate` whose object and role already carry one is refused
 *   (REQ-HL-EST-001), because two expected numbers for one role give two
 *   remainders and nothing looks wrong;
 * - a `TimeEntry` that would carry an ENFORCED estimate past its ceiling is
 *   refused, naming the estimate, the ceiling and the hours left
 *   (REQ-HL-EST-004).
 *
 * WHY IN THE WRITE PATH
 * ---------------------
 * The leaf books through OpenRegister's object API and so does the consuming
 * app's own screen. The spec asks for the refusal to read the same wherever the
 * booking is made, and the only place both callers pass through is the write.
 *
 * WHAT IS DELIBERATELY NOT REFUSED
 * --------------------------------
 * A stopped timer. Time already worked is a fact, and a register that refuses a
 * fact reports a smaller number than the truth; the overrun shows up as a
 * negative remainder instead. {@see TimeEstimateService::refusalForEntry()}
 * makes that decision, and this listener only carries it.
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
 * @spec openspec/specs/hours-leaf/spec.md#REQ-HL-EST-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\TimeEstimateService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Refuses a duplicate estimate and a booking past an enforced ceiling.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/hours-leaf/spec.md#REQ-HL-EST-001
 */
class TimeEstimateListener implements IEventListener {

	/**
	 * Lower-cased slug of the estimate schema.
	 *
	 * @var string
	 */
	public const TIMEESTIMATE_SLUG = 'timeestimate';

	/**
	 * Lower-cased slug of the booking schema.
	 *
	 * @var string
	 */
	public const TIMEENTRY_SLUG = 'timeentry';

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway The shared register plumbing.
	 * @param TimeEstimateService $estimates The rules themselves.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly TimeEstimateService $estimates,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Handle a pre-save event for a TimeEstimate or TimeEntry write.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/hours-leaf/spec.md#REQ-HL-EST-004
	 */
	public function handle(Event $event): void {
		$incoming = $this->incoming($event);
		if ($incoming === null) {
			return;
		}

		[$payload, $slug] = $incoming;

		try {
			$refusal = null;
			if ($slug === self::TIMEESTIMATE_SLUG) {
				$refusal = $this->estimates->refusalForEstimate(
					estimate: $payload,
					existing: $this->gateway->loadAll('TimeEstimate')
				);
			}

			if ($slug === self::TIMEENTRY_SLUG) {
				$refusal = $this->estimates->refusalForEntry(
					entry: $payload,
					estimates: $this->gateway->loadAll('TimeEstimate'),
					entries: $this->gateway->findFiltered(
						'TimeEntry',
						['domainObjectRef' => trim((string)($payload['domainObjectRef'] ?? ''))]
					)
				);
			}
		} catch (\Throwable $e) {
			// An unreadable estimate must not stop people booking hours they
			// have worked: a ceiling is a plan, and losing a booking costs more
			// than letting one past an unread plan. The miss is logged so it is
			// not silent.
			$this->logger->warning(
				'humaniq: TimeEstimateListener could not check a ' . $slug . ' write',
				['exception' => $e->getMessage()]
			);
			return;
		}//end try

		if ($refusal !== null) {
			$this->refuse($event, $refusal);
		}
	}//end handle()

	/**
	 * The payload and schema slug of a write this listener guards, or null.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return array{0: array<string, mixed>, 1: string}|null The payload and the slug.
	 *
	 * @spec openspec/specs/hours-leaf/spec.md#REQ-HL-EST-001
	 */
	private function incoming(Event $event): ?array {
		$entity = null;
		$isUpdate = false;
		if ($event instanceof \OCA\OpenRegister\Event\ObjectCreatingEvent) {
			$entity = $event->getObject();
		}

		if ($event instanceof \OCA\OpenRegister\Event\ObjectUpdatingEvent) {
			$entity = $event->getNewObject();
			$isUpdate = true;
		}

		if ($entity === null) {
			return null;
		}

		$slug = strtolower($this->gateway->resolveSchemaSlug((string)$entity->getSchema()));
		if (in_array($slug, [self::TIMEESTIMATE_SLUG, self::TIMEENTRY_SLUG], true) === false) {
			return null;
		}

		$payload = ($entity->getObject() ?? []);
		if ($isUpdate === true) {
			$uuid = trim((string)$entity->getUuid());
			if ($uuid !== '') {
				// Carried so a row being corrected is not read as its own
				// duplicate, nor counted twice against its own ceiling.
				$payload['id'] = $uuid;
			}
		}

		return [$payload, $slug];
	}//end incoming()

	/**
	 * Stop the write and carry the reason back to the caller.
	 *
	 * @param object $event The dispatched event.
	 * @param string $message The refusal.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/hours-leaf/spec.md#REQ-HL-EST-004
	 */
	private function refuse(object $event, string $message): void {
		if (method_exists($event, 'setErrors') === false || method_exists($event, 'stopPropagation') === false) {
			return;
		}

		$event->setErrors(['message' => $message]);
		$event->stopPropagation();
	}//end refuse()
}//end class
