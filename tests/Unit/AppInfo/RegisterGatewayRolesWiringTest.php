<?php

/**
 * The register gateway is wired as both of its role interfaces.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\AppInfo
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

namespace OCA\Humaniq\Tests\Unit\AppInfo;

use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\OrgLookupInterface;
use OCA\Humaniq\Service\RecordStoreInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Asserts the wiring from the caller, Application, and the split of the
 * gateway's public methods over the two roles.
 */
class RegisterGatewayRolesWiringTest extends TestCase {

	/**
	 * Both role interfaces resolve to the gateway.
	 *
	 * @return void
	 */
	public function testBothRolesAreAliasesOfTheGateway(): void {
		$aliases = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerServiceAlias')->willReturnCallback(
			static function (string $alias, string $target) use (&$aliases): void {
				$aliases[$alias] = $target;
			}
		);

		$app = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();
		$app->register($context);

		self::assertSame(HoursRegisterGateway::class, ($aliases[RecordStoreInterface::class] ?? null));
		self::assertSame(HoursRegisterGateway::class, ($aliases[OrgLookupInterface::class] ?? null));
	}//end testBothRolesAreAliasesOfTheGateway()

	/**
	 * Every public method of the gateway belongs to exactly one role.
	 *
	 * @return void
	 */
	public function testEveryGatewayMethodBelongsToOneRole(): void {
		$store = $this->methodsOf(RecordStoreInterface::class);
		$org = $this->methodsOf(OrgLookupInterface::class);

		self::assertSame(['delete', 'findFiltered', 'findObjectData', 'loadAll', 'save'], $store);
		self::assertSame(['findEmployeeByUserId', 'resolveSchemaSlug', 'uniqueCostCenterFor', 'uniqueManagerUserIdFor'], $org);

		$public = array_diff($this->methodsOf(HoursRegisterGateway::class), ['__construct']);
		$roles = array_merge($store, $org);
		sort($roles);
		self::assertSame($roles, array_values($public));
	}//end testEveryGatewayMethodBelongsToOneRole()

	/**
	 * The public method names a class or interface declares, sorted.
	 *
	 * @param class-string $type The class or interface.
	 *
	 * @return list<string>
	 */
	private function methodsOf(string $type): array {
		$names = array_map(static fn (ReflectionMethod $m): string => $m->getName(), (new ReflectionClass($type))->getMethods(ReflectionMethod::IS_PUBLIC));
		sort($names);
		return $names;
	}//end methodsOf()

}//end class
