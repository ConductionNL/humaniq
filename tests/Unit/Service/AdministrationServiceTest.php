<?php

/**
 * Unit tests for AdministrationService.
 *
 * Pins the multi-administratie access-resolution + active-pointer contract
 * (design.md D2/D4/D7): an accountant's AdministrationAccess rows resolve to
 * their accessible administraties (enriched with the Administration catalog's
 * name), `hasAccess()` is the closed set the controller's no-admin-idor guard
 * checks against, and the active-administration pointer round-trips through
 * the (mocked) `IConfig` user-value store. Drives the service through a fake
 * ObjectService double (a fake collaborator, not a fake of the logic under
 * test) since the real OpenRegister ObjectService is a sibling-app dependency
 * not available in this standalone suite — the RuleAuditServiceTest /
 * PayrollControllerProformaTest precedent.
 *
 * @category Test
 * @package  OCA\Hrmq\Tests\Unit\Service
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
 * @spec openspec/changes/multi-administratie/specs/multi-administratie/spec.md#REQ-MULTI-002
 * @spec openspec/changes/multi-administratie/specs/multi-administratie/spec.md#REQ-MULTI-003
 * @spec openspec/changes/single-person-modes/specs/single-person-modes/spec.md#REQ-SPM-002
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Service;

use OCA\Hrmq\Service\AdministrationService;
use OCA\Hrmq\Service\SettingsService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for AdministrationService.
 *
 * @spec openspec/changes/multi-administratie/specs/multi-administratie/spec.md#REQ-MULTI-002
 * @spec openspec/changes/multi-administratie/specs/multi-administratie/spec.md#REQ-MULTI-003
 * @spec openspec/changes/single-person-modes/specs/single-person-modes/spec.md#REQ-SPM-002
 */
class AdministrationServiceTest extends TestCase {

	/**
	 * REQ-MULTI-002 "An accountant has access to multiple administraties"
	 * scenario: two AdministrationAccess rows for the caller resolve to both
	 * administraties, each enriched with its catalog name.
	 *
	 * @return void
	 */
	public function testAccountantResolvesBothAccessibleAdministraties(): void {
		$service = $this->buildService();

		$administrations = $service->accessibleAdministrations('admin');

		$this->assertCount(2, $administrations);
		$this->assertSame('ADM-001', $administrations[0]['administrationId']);
		$this->assertSame('Example: Conduction Demo B.V.', $administrations[0]['name']);
		$this->assertSame('accountant', $administrations[0]['role']);
		$this->assertSame('ADM-002', $administrations[1]['administrationId']);
		$this->assertSame('Example: Tweede Klant B.V.', $administrations[1]['name']);

	}//end testAccountantResolvesBothAccessibleAdministraties()

	/**
	 * REQ-SPM-002 response shape: every accessible-administratie entry carries
	 * its resolved `mode` — the standard default for a legacy/absent value
	 * (ADM-001), the set value for one that has it (ADM-002).
	 *
	 * @return void
	 */
	public function testAccessibleAdministrationsCarryResolvedMode(): void {
		$service = $this->buildService();

		$administrations = $service->accessibleAdministrations('admin');

		$this->assertSame('standard', $administrations[0]['mode']);
		$this->assertSame('dga_single_person', $administrations[1]['mode']);

	}//end testAccessibleAdministrationsCarryResolvedMode()

	/**
	 * REQ-SPM-002 "Switching administratie updates the stamped mode": the
	 * active administratie's `mode` resolves from the catalog (ADM-002 →
	 * dga_single_person).
	 *
	 * @return void
	 */
	public function testActiveAdministrationModeResolvesFromCatalog(): void {
		$store = ['admin' => ['active_administration_id' => 'ADM-002']];
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')
			->willReturnCallback(static function (string $userId, string $appName, string $key, string $default) use (&$store): string {
				return $store[$userId][$key] ?? $default;
			});

		$service = $this->buildService($config);

		$this->assertSame('dga_single_person', $service->getActiveAdministrationMode('admin'));

	}//end testActiveAdministrationModeResolvesFromCatalog()

	/**
	 * REQ-SPM-002 default: a caller with NO active administratie (never
	 * switched) resolves the standard mode — the no-regression default, so no
	 * visibleIf predicate hides a menu for them.
	 *
	 * @return void
	 */
	public function testActiveAdministrationModeDefaultsToStandardWhenUnresolved(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')
			->willReturnCallback(static fn (string $u, string $a, string $k, string $default): string => $default);

		$service = $this->buildService($config);

		$this->assertSame('standard', $service->getActiveAdministrationMode('admin'));

	}//end testActiveAdministrationModeDefaultsToStandardWhenUnresolved()

	/**
	 * An active administratie with no `mode` value set (ADM-001) resolves the
	 * standard default — a legacy administratie never hides a menu by accident.
	 *
	 * @return void
	 */
	public function testActiveAdministrationModeDefaultsToStandardForLegacyAdministratie(): void {
		$store = ['admin' => ['active_administration_id' => 'ADM-001']];
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')
			->willReturnCallback(static function (string $userId, string $appName, string $key, string $default) use (&$store): string {
				return $store[$userId][$key] ?? $default;
			});

		$service = $this->buildService($config);

		$this->assertSame('standard', $service->getActiveAdministrationMode('admin'));

	}//end testActiveAdministrationModeDefaultsToStandardForLegacyAdministratie()

	/**
	 * A user with no AdministrationAccess rows resolves an empty accessible
	 * set.
	 *
	 * @return void
	 */
	public function testUserWithoutAccessRowsResolvesEmpty(): void {
		$service = $this->buildService();

		$this->assertSame([], $service->accessibleAdministrations('nobody'));

	}//end testUserWithoutAccessRowsResolvesEmpty()

	/**
	 * REQ-MULTI-003 "Activating an accessible administratie" precondition:
	 * `hasAccess()` is true for a row the caller has, false otherwise —
	 * the exact set `AdministrationController::setActive` guards on.
	 *
	 * @return void
	 */
	public function testHasAccessReflectsMembershipRowsOnly(): void {
		$service = $this->buildService();

		$this->assertTrue($service->hasAccess('admin', 'ADM-001'));
		$this->assertTrue($service->hasAccess('admin', 'ADM-002'));
		$this->assertFalse($service->hasAccess('admin', 'ADM-099'));
		$this->assertFalse($service->hasAccess('nobody', 'ADM-001'));
		$this->assertFalse($service->hasAccess('admin', ''));

	}//end testHasAccessReflectsMembershipRowsOnly()

	/**
	 * The active-administration pointer round-trips through the per-user
	 * `IConfig` value (design.md D4): unset resolves to null, then a set
	 * value is read back exactly.
	 *
	 * @return void
	 */
	public function testActiveAdministrationRoundTripsThroughUserConfig(): void {
		$store = [];

		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')
			->willReturnCallback(static function (string $userId, string $appName, string $key, string $default) use (&$store): string {
				return $store[$userId][$key] ?? $default;
			});
		$config->method('setUserValue')
			->willReturnCallback(static function (string $userId, string $appName, string $key, string $value) use (&$store): void {
				$store[$userId][$key] = $value;
			});

		$service = $this->buildService($config);

		$this->assertNull($service->getActiveAdministrationId('admin'));

		$service->setActiveAdministrationId('admin', 'ADM-002');

		$this->assertSame('ADM-002', $service->getActiveAdministrationId('admin'));

	}//end testActiveAdministrationRoundTripsThroughUserConfig()

	/**
	 * REQ-MULTI-004 `context()` combines the active pointer + the accessible
	 * set into the one payload the switcher/topbar indicator consumes.
	 *
	 * @return void
	 */
	public function testContextCombinesActivePointerAndAccessibleSet(): void {
		$store = ['admin' => ['active_administration_id' => 'ADM-002']];
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')
			->willReturnCallback(static function (string $userId, string $appName, string $key, string $default) use (&$store): string {
				return $store[$userId][$key] ?? $default;
			});

		$service = $this->buildService($config);

		$context = $service->context('admin');

		$this->assertSame('ADM-002', $context['activeAdministrationId']);
		$this->assertCount(2, $context['administrations']);

	}//end testContextCombinesActivePointerAndAccessibleSet()

	/**
	 * Build an `AdministrationService` with a fake container-resolved
	 * ObjectService pre-loaded with two Administration rows and three
	 * AdministrationAccess rows (admin -> ADM-001/ADM-002 accountant,
	 * hr-devries -> ADM-001 hr) — the hr-seed.json seed shape.
	 *
	 * @param IConfig|null $config Optional IConfig mock; a default no-op mock is used when omitted.
	 *
	 * @return AdministrationService
	 */
	private function buildService(?IConfig $config = null): AdministrationService {
		$administrations = [
			// ADM-001 has no `mode` value set — proves the standard default
			// resolves for a legacy/pre-change administratie (REQ-SPM-001).
			['administrationId' => 'ADM-001', 'name' => 'Example: Conduction Demo B.V.'],
			['administrationId' => 'ADM-002', 'name' => 'Example: Tweede Klant B.V.', 'mode' => 'dga_single_person'],
		];

		$access = [
			['userId' => 'admin', 'administrationId' => 'ADM-001', 'role' => 'accountant'],
			['userId' => 'admin', 'administrationId' => 'ADM-002', 'role' => 'accountant'],
			['userId' => 'hr-devries', 'administrationId' => 'ADM-001', 'role' => 'hr'],
		];

		$objectService = new class($administrations, $access) {

			/**
			 * @param array<int, array<string, mixed>> $administrations Canned Administration rows.
			 * @param array<int, array<string, mixed>> $access Canned AdministrationAccess rows.
			 */
			public function __construct(
				private readonly array $administrations,
				private readonly array $access,
			) {
			}

			/**
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @param string $register Ignored; chainable.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				return $this;
			}

			/**
			 * @param string $schema The schema to load on the next findAll().
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}

			/**
			 * @param array<string, mixed> $options Ignored.
			 *
			 * @return array<int, mixed>
			 */
			public function findAll(array $options): array {
				return match ($this->schema) {
					'Administration' => $this->administrations,
					'AdministrationAccess' => $this->access,
					default => [],
				};

			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($objectService);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('hrmq');

		$config ??= $this->createMock(IConfig::class);
		$logger = $this->createMock(LoggerInterface::class);

		return new AdministrationService($container, $config, $settings, $logger);
	}//end buildService()

}//end class
