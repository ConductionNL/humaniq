<?php

/**
 * ShippedFlowAdoptionTest
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Service
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
 * @spec openspec/specs/payroll-run-pay-date-and-schedule/spec.md#REQ-PRD-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Kernel\Contracts\OpenRegisterAvailabilityInterface;
use OCA\Humaniq\Service\ShippedFlowAdoption;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * A shipped flow is adopted once it is enabled.
 *
 * @covers \OCA\Humaniq\Service\ShippedFlowAdoption
 */
class ShippedFlowAdoptionTest extends TestCase {

	/**
	 * The flow matching app, name and trigger schema answers by its enabled flag.
	 *
	 * @return void
	 */
	public function testTheMatchingFlowAnswersByItsEnabledFlag(): void {
		$flows = [
			$this->flow(name: 'Loonrun', schema: 'Timesheet', enabled: true),
			$this->flow(name: 'Loonrun', schema: 'PayrollRun', enabled: true),
		];
		self::assertTrue($this->adoption(available: true, flows: $flows)->isAdopted('humaniq', 'Loonrun', 'PayrollRun'));

		$disabled = [$this->flow(name: 'Loonrun', schema: 'PayrollRun', enabled: false)];
		self::assertFalse($this->adoption(available: true, flows: $disabled)->isAdopted('humaniq', 'Loonrun', 'PayrollRun'));
	}//end testTheMatchingFlowAnswersByItsEnabledFlag()

	/**
	 * A flow never imported is not adopted.
	 *
	 * @return void
	 */
	public function testAFlowNeverImportedIsNotAdopted(): void {
		$flows = [$this->flow(name: 'Onboarding', schema: 'Employee', enabled: true)];

		self::assertFalse($this->adoption(available: true, flows: $flows)->isAdopted('humaniq', 'Loonrun', 'PayrollRun'));
	}//end testAFlowNeverImportedIsNotAdopted()

	/**
	 * Without OpenRegister nothing is adopted and the flow store is not asked.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNothingIsAdopted(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects(self::never())->method('get');
		$availability = $this->createMock(OpenRegisterAvailabilityInterface::class);
		$availability->method('isAvailable')->willReturn(false);

		self::assertFalse((new ShippedFlowAdoption($availability, $container))->isAdopted('humaniq', 'Loonrun', 'PayrollRun'));
	}//end testWithoutOpenRegisterNothingIsAdopted()

	/**
	 * A stored flow double.
	 *
	 * @param string $name    The flow's name.
	 * @param string $schema  Its trigger schema.
	 * @param bool   $enabled Whether it is enabled.
	 *
	 * @return object
	 */
	private function flow(string $name, string $schema, bool $enabled): object {
		return new class($name, $schema, $enabled) {

			/**
			 * @param string $name    The flow's name.
			 * @param string $schema  Its trigger schema.
			 * @param bool   $enabled Whether it is enabled.
			 */
			public function __construct(
				private readonly string $name,
				private readonly string $schema,
				private readonly bool $enabled,
			) {
			}

			/**
			 * @return string
			 */
			public function getName(): string {
				return $this->name;
			}

			/**
			 * @return string
			 */
			public function getTriggerSchema(): string {
				return $this->schema;
			}

			/**
			 * @return bool
			 */
			public function getEnabled(): bool {
				return $this->enabled;
			}
		};
	}//end flow()

	/**
	 * The adoption lookup over a flow store holding these flows.
	 *
	 * @param bool         $available Whether OpenRegister is installed.
	 * @param list<object> $flows     The stored flows of the app.
	 *
	 * @return ShippedFlowAdoption
	 */
	private function adoption(bool $available, array $flows): ShippedFlowAdoption {
		$mapper = new class($flows) {

			/**
			 * @param list<object> $flows The stored flows.
			 */
			public function __construct(private readonly array $flows) {
			}

			/**
			 * @param string $app   The app.
			 * @param int    $limit The page size.
			 *
			 * @return list<object>
			 */
			public function findAllFlows(string $app, int $limit): array {
				return $app === 'humaniq' ? $this->flows : [];
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Db\FlowMapper')->willReturn($mapper);
		$availability = $this->createMock(OpenRegisterAvailabilityInterface::class);
		$availability->method('isAvailable')->willReturn($available);

		return new ShippedFlowAdoption($availability, $container);
	}//end adoption()
}//end class
