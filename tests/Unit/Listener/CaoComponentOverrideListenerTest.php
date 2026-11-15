<?php

/**
 * CaoComponentOverrideListener: a contract override below the agreement or without a reason is refused on save.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit
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
 * @spec openspec/specs/payroll-cao-components/spec.md#REQ-CCP-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Listener;

use OCA\Humaniq\Listener\CaoComponentOverrideListener;
use OCA\Humaniq\Service\EmploymentTermsResolver;
use OCA\Humaniq\Standards\CaoRegistry;
use OCA\Humaniq\Tests\Unit\Support\RegisterSchemaValidator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use PHPUnit\Framework\TestCase;

/**
 * The save of a contract with a wrong override stops with a message.
 */
class CaoComponentOverrideListenerTest extends TestCase {

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	protected function setUp(): void {
		CaoRegistry::reset();
	}//end setUp()

	/**
	 * An entity holding the contract.
	 *
	 * @param array<string, mixed> $data The contract.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('ct-1');
		$entity->setSchema('EmploymentContract');
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * A contract with the given override fields.
	 *
	 * @param array<string, mixed> $fields The fields.
	 *
	 * @return array<string, mixed>
	 */
	private static function contract(array $fields): array {
		return array_merge(['employeeId' => '00000000-0000-4000-8000-000000000000', 'type' => 'permanent', 'startDate' => '2026-01-01', 'cao' => 'cao-voorbeeld', 'caoComponents' => ['ploegentoeslag']], $fields);
	}//end contract()

	/**
	 * An override of 8% below the agreement's 10% is refused on update, and
	 * 15% without a reason is refused on create.
	 *
	 * @return void
	 */
	public function testAWrongOverrideIsRefused(): void {
		$listener = new CaoComponentOverrideListener(new EmploymentTermsResolver());

		$below = self::contract(['caoComponentOverrides' => ['ploegentoeslag' => ['pct' => 8]], 'caoComponentOverrideReason' => 'agreed']);
		$update = new ObjectUpdatingEvent($this->entity($below), $this->entity(self::contract([])));
		$listener->handle($update);
		$this->assertTrue($update->isPropagationStopped());
		$this->assertStringContainsString('below', (string)($update->getErrors()['message'] ?? ''));

		$create = new ObjectCreatingEvent($this->entity(self::contract(['caoComponentOverrides' => ['ploegentoeslag' => ['pct' => 15]]])));
		$listener->handle($create);
		$this->assertTrue($create->isPropagationStopped());
		$this->assertStringContainsString('reason', (string)($create->getErrors()['message'] ?? ''));
	}//end testAWrongOverrideIsRefused()

	/**
	 * A better override with a reason, and a contract without overrides, save;
	 * another event is ignored. The saved contract is valid against its schema.
	 *
	 * @return void
	 */
	public function testAValidContractSaves(): void {
		$listener = new CaoComponentOverrideListener(new EmploymentTermsResolver());
		$better = self::contract(['caoComponentOverrides' => ['ploegentoeslag' => ['pct' => 15]], 'caoComponentOverrideReason' => 'agreed at hiring']);
		$this->assertSame([], RegisterSchemaValidator::errors('EmploymentContract', $better));

		foreach ([$better, self::contract([])] as $contract) {
			$event = new ObjectCreatingEvent($this->entity($contract));
			$listener->handle($event);
			$this->assertFalse($event->isPropagationStopped());
		}

		$created = new ObjectCreatedEvent($this->entity(self::contract(['caoComponentOverrides' => ['ploegentoeslag' => ['pct' => 1]], 'caoComponentOverrideReason' => 'x'])));
		$listener->handle($created);
		$this->assertFalse($created->isPropagationStopped());
	}//end testAValidContractSaves()

}//end class
