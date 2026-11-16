<?php

/**
 * ContractBindings: every Kernel contract is aliased once to the class implementing it.
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
 * @spec exclude Composition root wiring, no functional requirement.
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\ContractBindings;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Humaniq\Service\ContractBindings
 */
final class ContractBindingsTest extends TestCase {

	/**
	 * The aliases ContractBindings registers, as [interface, implementation] pairs.
	 *
	 * @return list<array{0: string, 1: string}>
	 */
	private function recordAliases(): array {
		$aliases = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerServiceAlias')->willReturnCallback(
			static function (string $alias, string $target) use (&$aliases): void {
				$aliases[] = [$alias, $target];
			}
		);

		(new ContractBindings())->register($context);

		return $aliases;
	}

	public function testEveryContractIsBoundExactlyOnce(): void {
		$aliases = $this->recordAliases();
		$names = array_column($aliases, 0);

		self::assertCount(97, $aliases);
		self::assertSame($names, array_values(array_unique($names)));
	}

	public function testEveryContractIsBoundToTheClassImplementingIt(): void {
		foreach ($this->recordAliases() as [$contract, $implementation]) {
			self::assertTrue(interface_exists($contract), $contract . ' is not an interface');
			self::assertStringStartsWith('OCA\\Humaniq\\Kernel\\Contracts\\', $contract);
			self::assertContains($contract, class_implements($implementation), $implementation . ' does not implement ' . $contract);
			self::assertSame((new \ReflectionClass($implementation))->getShortName() . 'Interface', (new \ReflectionClass($contract))->getShortName());
		}
	}

	public function testEveryKernelContractIsBound(): void {
		$bound = array_column($this->recordAliases(), 0);
		$files = glob(dirname(__DIR__, 3) . '/lib/Kernel/Contracts/*Interface.php');

		self::assertNotEmpty($files);
		foreach ($files as $file) {
			self::assertContains('OCA\\Humaniq\\Kernel\\Contracts\\' . basename($file, '.php'), $bound);
		}
	}
}
