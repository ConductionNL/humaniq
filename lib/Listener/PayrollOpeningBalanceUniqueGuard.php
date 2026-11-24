<?php

/**
 * Payroll Opening Balance Unique Guard
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pre-save listener on OpenRegister's `ObjectCreatingEvent` /
 * `ObjectUpdatingEvent` for `PayrollOpeningBalance` writes. It refuses a
 * second opening balance for the same employee, administration and year: the
 * annual statement adds the balance to humaniq's payslips, so two balances
 * would count the previous package's months twice and nothing would look
 * wrong. The pages are declarative and the balances arrive by mass import, so
 * the rule sits on the write itself. It fails closed: a balance that could not
 * be checked is refused.
 *
 * @category Listener
 * @package  OCA\Humaniq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/payroll-period-edge-cases/spec.md#REQ-PPE-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * One opening balance per employee, administration and year.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/payroll-period-edge-cases/spec.md#REQ-PPE-003
 */
class PayrollOpeningBalanceUniqueGuard implements IEventListener {

	public const SCHEMA_SLUG = 'payrollopeningbalance';

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway Reads the stored balances and resolves schema ids.
	 * @param LoggerInterface      $logger  Logs a balance that could not be checked.
	 *
	 * @spec openspec/specs/payroll-period-edge-cases/spec.md#REQ-PPE-003
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Refuse the write when another balance has the same key.
	 *
	 * @param Event $event The pre-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/payroll-period-edge-cases/spec.md#REQ-PPE-003
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) PreSaveEvents::entityOf and ::refuse are the shared reader and refusal every pre-save guard uses.
	 */
	public function handle(Event $event): void {
		$entity = PreSaveEvents::entityOf($event);
		if ($entity === null || $this->isBalance(entity: $entity) === false) {
			return;
		}

		$payload = ($entity->getObject() ?? []);

		try {
			$duplicate = $this->duplicateOf(payload: $payload, uuid: $this->ownId(event: $event, entity: $entity));
		} catch (\Throwable $e) {
			$this->logger->warning('humaniq: PayrollOpeningBalanceUniqueGuard could not check an opening balance', ['exception' => $e->getMessage()]);
			PreSaveEvents::refuse($event, 'Het beginsaldo kon niet worden gecontroleerd; probeer het later opnieuw.');
			return;
		}

		if ($duplicate === true) {
			PreSaveEvents::refuse($event, sprintf('Deze medewerker heeft al een beginsaldo voor administratie %s in %d.', (string)$payload['administrationId'], (int)$payload['year']));
		}
	}//end handle()

	/**
	 * The written balance's own id on an update, null on a create.
	 *
	 * @param Event  $event  The pre-save event.
	 * @param object $entity The written balance.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/payroll-period-edge-cases/spec.md#REQ-PPE-003
	 */
	private function ownId(Event $event, object $entity): ?string {
		if ($event instanceof ObjectUpdatingEvent) {
			return (string)$entity->getUuid();
		}

		return null;
	}//end ownId()

	/**
	 * Whether a stored balance other than this one has the same employee,
	 * administration and year.
	 *
	 * @param array<string, mixed> $payload The written balance.
	 * @param string|null          $uuid    Its id on an update.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/payroll-period-edge-cases/spec.md#REQ-PPE-003
	 */
	private function duplicateOf(array $payload, ?string $uuid): bool {
		$employeeId = trim((string)($payload['employeeId'] ?? ''));
		$administrationId = trim((string)($payload['administrationId'] ?? ''));
		$year = ($payload['year'] ?? null);
		if ($employeeId === '' || $administrationId === '' || is_numeric($year) === false) {
			// The schema requires all three; a balance missing one has no key to repeat.
			return false;
		}

		foreach ($this->gateway->findFiltered('PayrollOpeningBalance', ['employeeId' => $employeeId]) as $row) {
			if ($uuid !== null && trim((string)($row['id'] ?? '')) === $uuid) {
				continue;
			}

			if (trim((string)($row['administrationId'] ?? '')) === $administrationId && (int)($row['year'] ?? 0) === (int)$year) {
				return true;
			}
		}

		return false;
	}//end duplicateOf()

	/**
	 * Whether the entity is a PayrollOpeningBalance.
	 *
	 * @param object $entity The OpenRegister object.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/payroll-period-edge-cases/spec.md#REQ-PPE-003
	 */
	private function isBalance(object $entity): bool {
		return strtolower($this->gateway->resolveSchemaSlug((string)$entity->getSchema())) === self::SCHEMA_SLUG;
	}//end isBalance()
}//end class
