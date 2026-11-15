<?php

/**
 * Golden test: every class that takes the register gateway still accepts it.
 *
 * Scans lib/ for constructor parameters typed as HoursRegisterGateway or one
 * of its role interfaces, checks the gateway satisfies each type, and
 * compares the list of those parameters with
 * tests/fixtures/golden/hours-register-gateway-collaborators.json. Set
 * RECORD_GOLDEN=1 to rewrite that file.
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
 * @spec openspec/specs/mss-team-scope/spec.md#Requirement:-The-approval-carrying-schemas-SHALL-gain-an-optional-denormalized-managerUserId-scoping-property-(REQ-MSS-001)
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\HoursRegisterGateway;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * The gateway's collaborators, by constructor parameter.
 *
 * @spec openspec/specs/mss-team-scope/spec.md#Requirement:-The-approval-carrying-schemas-SHALL-gain-an-optional-denormalized-managerUserId-scoping-property-(REQ-MSS-001)
 */
class HoursRegisterGatewayCollaboratorsTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/golden/hours-register-gateway-collaborators.json';

	private const TYPES = [
		'OCA\Humaniq\Service\HoursRegisterGateway',
		'OCA\Humaniq\Service\RecordStoreInterface',
		'OCA\Humaniq\Service\OrgLookupInterface',
	];

	/**
	 * The same constructor parameters take the gateway, and it satisfies
	 * every one of their types.
	 *
	 * @return void
	 */
	public function testEveryCollaboratorStillAcceptsTheGateway(): void {
		$found = [];
		foreach ($this->gatewayParameters() as [$class, $param, $type]) {
			self::assertTrue(is_a(HoursRegisterGateway::class, $type, true), $class . '::$' . $param . ' is typed ' . $type);
			$found[] = $class . '::$' . $param;
		}

		sort($found);
		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($found, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n");
			$this->markTestSkipped('recorded');
		}

		self::assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), $found);

	}//end testEveryCollaboratorStillAcceptsTheGateway()

	/**
	 * Every constructor parameter in lib/ typed as the gateway or a role.
	 *
	 * @return list<array{0: string, 1: string, 2: string}> Class, parameter and type.
	 */
	private function gatewayParameters(): array {
		$lib = dirname(__DIR__, 3) . '/lib';
		$found = [];
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($lib, \FilesystemIterator::SKIP_DOTS));
		foreach ($files as $file) {
			$class = $this->classIn((string)$file);
			if ($class === null || class_exists($class) === false) {
				continue;
			}

			$constructor = (new ReflectionClass($class))->getConstructor();
			foreach (($constructor?->getParameters() ?? []) as $param) {
				$type = $param->getType();
				if ($type instanceof ReflectionNamedType && in_array($type->getName(), self::TYPES, true) === true) {
					$found[] = [$class, $param->getName(), $type->getName()];
				}
			}
		}

		return $found;

	}//end gatewayParameters()

	/**
	 * The class a PHP file declares when it names the gateway or a role, or null.
	 *
	 * @param string $path The file.
	 *
	 * @return string|null The fully qualified class name.
	 */
	private function classIn(string $path): ?string {
		if (str_ends_with($path, '.php') === false) {
			return null;
		}

		$source = (string)file_get_contents($path);
		if (preg_match('/HoursRegisterGateway|RecordStoreInterface|OrgLookupInterface/', $source) !== 1
			|| preg_match('/^namespace\s+([^;]+);/m', $source, $ns) !== 1
			|| preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/m', $source, $name) !== 1
		) {
			return null;
		}

		return $ns[1] . '\\' . $name[1];

	}//end classIn()

}//end class
