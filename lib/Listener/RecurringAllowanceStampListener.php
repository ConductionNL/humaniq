<?php

/**
 * Humaniq RecurringAllowanceStampListener
 *
 * A new recurring allowance names who drafted it and the employee's account
 * and administration, so the separation-of-duties guard on `activate` refuses
 * both the drafter and the employee (payroll-expenses-and-allowances D3). An
 * update keeps the original drafter.
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
 * @spec openspec/specs/payroll-expenses-and-allowances/spec.md#REQ-PEA-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCA\Humaniq\Service\RecordStoreInterface;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Stamps the drafter, the employee's account and the administration.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/payroll-expenses-and-allowances/spec.md#REQ-PEA-002
 */
class RecurringAllowanceStampListener implements IEventListener {

	/**
	 * Lower-cased slug of the allowance schema.
	 */
	public const SLUG = 'recurringallowance';

	/**
	 * Constructor.
	 *
	 * @param RecordStoreInterface $gateway     Reads the employee.
	 * @param IUserSession         $userSession The signed-in user.
	 * @param LoggerInterface      $logger      The logger.
	 *
	 * @spec openspec/specs/payroll-expenses-and-allowances/spec.md#REQ-PEA-002
	 */
	public function __construct(
		private readonly RecordStoreInterface $gateway,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Stamp a new allowance; keep the drafter on an update.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/payroll-expenses-and-allowances/spec.md#REQ-PEA-002
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent) {
			$this->stampNew($event);
			return;
		}

		if ($event instanceof ObjectUpdatingEvent) {
			$this->stampChange($event);
		}
	}//end handle()

	/**
	 * A new allowance carries its drafter and its employee's account and
	 * administration.
	 *
	 * @param ObjectCreatingEvent $event The pre-save event.
	 *
	 * @return void
	 */
	private function stampNew(ObjectCreatingEvent $event): void {
		$allowance = ($event->getObject()->getObject() ?? []);
		$stamp = ['proposedBy' => (string)($this->userSession->getUser()?->getUID() ?? '')];
		$event->setModifiedData(array_merge($stamp, $this->employeeStamp(allowance: $allowance)));
	}//end stampNew()

	/**
	 * A changed allowance keeps its drafter and is restamped from its
	 * employee; only the fields that differ are written.
	 *
	 * @param ObjectUpdatingEvent $event The pre-save event.
	 *
	 * @return void
	 */
	private function stampChange(ObjectUpdatingEvent $event): void {
		$allowance = ($event->getNewObject()->getObject() ?? []);
		$old = ($event->getOldObject()?->getObject() ?? []);
		$stamp = array_merge($this->employeeStamp(allowance: $allowance), $this->drafterStamp(allowance: $allowance, old: $old));
		$changed = array_diff_assoc($stamp, array_intersect_key($allowance, $stamp));
		if ($changed !== []) {
			$event->setModifiedData($changed);
		}
	}//end stampChange()

	/**
	 * The stored drafter, when the write would change it.
	 *
	 * @param array<string, mixed> $allowance The incoming allowance.
	 * @param array<string, mixed> $old       The stored allowance.
	 *
	 * @return array<string, string>
	 */
	private function drafterStamp(array $allowance, array $old): array {
		$drafter = (string)($old['proposedBy'] ?? '');
		if ($drafter === '' || $drafter === (string)($allowance['proposedBy'] ?? '')) {
			return [];
		}

		return ['proposedBy' => $drafter];
	}//end drafterStamp()

	/**
	 * The employee's account and administration, or nothing when the
	 * employee cannot be read.
	 *
	 * @param array<string, mixed> $allowance The allowance.
	 *
	 * @return array<string, string>
	 */
	private function employeeStamp(array $allowance): array {
		$employeeId = (string)($allowance['employeeId'] ?? '');
		if ($employeeId === '') {
			return [];
		}

		$employee = $this->employee(employeeId: $employeeId);
		$stamp = [];
		foreach (['userId' => 'nextcloudUserId', 'administrationId' => 'administrationId'] as $field => $source) {
			$value = (string)($employee[$source] ?? '');
			if ($value !== '') {
				$stamp[$field] = $value;
			}
		}

		return $stamp;
	}//end employeeStamp()

	/**
	 * The allowance's employee; empty when it cannot be read, which is
	 * logged.
	 *
	 * @param string $employeeId The employee.
	 *
	 * @return array<string, mixed>
	 */
	private function employee(string $employeeId): array {
		try {
			return ($this->gateway->findObjectData($employeeId, 'Employee') ?? []);
		} catch (\Throwable $e) {
			$this->logger->warning('humaniq: the employee of a recurring allowance could not be read', ['exception' => $e->getMessage()]);
			return [];
		}
	}//end employee()

}//end class
