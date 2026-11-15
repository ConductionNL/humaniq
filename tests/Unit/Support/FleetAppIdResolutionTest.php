<?php

/**
 * FleetAppId — the id half of the resolver.
 *
 * `FleetAppIdTest` guards the namespace half (which class names are tried and
 * that no consumer binds a retired one directly). This covers the other half:
 * which app ID the instance actually has, and the paths built from it. Both
 * halves fail the same silent way — an unresolved id degrades to "the app is
 * not installed", with nothing logged and nothing red — so the resolution
 * order and the tolerance for an app manager that cannot answer are asserted
 * here rather than assumed.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Support;

use OCA\Humaniq\Support\FleetAppId;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * App-id resolution, enablement and path building.
 *
 * @covers \OCA\Humaniq\Support\FleetAppId
 */
class FleetAppIdResolutionTest extends TestCase {

	/**
	 * The newest candidate wins when both the new and the retired id are
	 * installed, because the candidate list is ordered newest first.
	 *
	 * @return void
	 */
	public function testTheNewestInstalledCandidateWins(): void {
		$manager = $this->manager(['integriq' => true, 'openconnector' => true]);

		$this->assertSame('integriq', FleetAppId::resolve(appManager: $manager, canonical: 'integriq'));

	}//end testTheNewestInstalledCandidateWins()

	/**
	 * An instance still running the pre-rename release resolves to the retired
	 * id — dropping it from the search would take that instance dark.
	 *
	 * @return void
	 */
	public function testARetiredIdStillResolvesWhenItIsTheInstalledOne(): void {
		$manager = $this->manager(['integriq' => false, 'openconnector' => true]);

		$this->assertSame('openconnector', FleetAppId::resolve(appManager: $manager, canonical: 'integriq'));
		$this->assertTrue(FleetAppId::isInstalled(appManager: $manager, canonical: 'integriq'));

	}//end testARetiredIdStillResolvesWhenItIsTheInstalledOne()

	/**
	 * With no candidate installed the resolution is null and the app counts as
	 * absent.
	 *
	 * @return void
	 */
	public function testNoInstalledCandidateResolvesToNull(): void {
		$manager = $this->manager(['integriq' => false, 'openconnector' => false]);

		$this->assertNull(FleetAppId::resolve(appManager: $manager, canonical: 'integriq'));
		$this->assertFalse(FleetAppId::isInstalled(appManager: $manager, canonical: 'integriq'));

	}//end testNoInstalledCandidateResolvesToNull()

	/**
	 * An app outside the rename map is looked up under its own name only.
	 *
	 * @return void
	 */
	public function testAnAppOutsideTheRenameMapIsLookedUpUnderItsOwnName(): void {
		$manager = $this->manager(['openregister' => true]);

		$this->assertSame('openregister', FleetAppId::resolve(appManager: $manager, canonical: 'openregister'));

	}//end testAnAppOutsideTheRenameMapIsLookedUpUnderItsOwnName()

	/**
	 * An app manager that cannot answer for one candidate must not abort the
	 * search: the next candidate still resolves.
	 *
	 * @return void
	 */
	public function testAThrowingCandidateDoesNotAbortTheSearch(): void {
		$manager = $this->createMock(IAppManager::class);
		$manager->method('isInstalled')->willReturnCallback(
			static function (string $id): bool {
				if ($id === 'integriq') {
					throw new RuntimeException('app manager cannot answer');
				}

				return $id === 'openconnector';
			}
		);

		$this->assertSame('openconnector', FleetAppId::resolve(appManager: $manager, canonical: 'integriq'));

	}//end testAThrowingCandidateDoesNotAbortTheSearch()

	/**
	 * A manager that cannot answer for ANY candidate yields null rather than
	 * an exception reaching the caller.
	 *
	 * @return void
	 */
	public function testAManagerThatNeverAnswersYieldsNull(): void {
		$manager = $this->createMock(IAppManager::class);
		$manager->method('isInstalled')->willThrowException(new RuntimeException('app manager cannot answer'));

		$this->assertNull(FleetAppId::resolve(appManager: $manager, canonical: 'integriq'));

	}//end testAManagerThatNeverAnswersYieldsNull()

	/**
	 * The enablement check runs against the id the instance really has, not
	 * against a canonical name it never registered.
	 *
	 * @return void
	 */
	public function testEnablementIsCheckedAgainstTheInstalledId(): void {
		$manager = $this->manager(['integriq' => false, 'openconnector' => true]);
		$manager->expects($this->once())->method('isEnabledForUser')
			->with('openconnector')
			->willReturn(true);

		$this->assertTrue(FleetAppId::isEnabledForUser(appManager: $manager, canonical: 'integriq'));

	}//end testEnablementIsCheckedAgainstTheInstalledId()

	/**
	 * An app that is not installed is not enabled, and the manager is never
	 * asked about an id that does not exist.
	 *
	 * @return void
	 */
	public function testAnAbsentAppIsNotEnabled(): void {
		$manager = $this->manager(['integriq' => false, 'openconnector' => false]);
		$manager->expects($this->never())->method('isEnabledForUser');

		$this->assertFalse(FleetAppId::isEnabledForUser(appManager: $manager, canonical: 'integriq'));

	}//end testAnAbsentAppIsNotEnabled()

	/**
	 * An enablement check that throws reads as not enabled, never as an error
	 * reaching the caller.
	 *
	 * @return void
	 */
	public function testAThrowingEnablementCheckReadsAsNotEnabled(): void {
		$manager = $this->manager(['integriq' => true]);
		$manager->method('isEnabledForUser')->willThrowException(new RuntimeException('no user session'));

		$this->assertFalse(FleetAppId::isEnabledForUser(appManager: $manager, canonical: 'integriq'));

	}//end testAThrowingEnablementCheckReadsAsNotEnabled()

	/**
	 * A route is built under the REGISTERED id, since Nextcloud mounts routes
	 * there — the canonical name would 404 on half the fleet.
	 *
	 * @return void
	 */
	public function testAPathIsBuiltUnderTheRegisteredId(): void {
		$manager = $this->manager(['integriq' => false, 'openconnector' => true]);

		$this->assertSame(
			'/apps/openconnector',
			FleetAppId::appPath(appManager: $manager, canonical: 'integriq')
		);
		$this->assertSame(
			'/apps/openconnector/api/sources',
			FleetAppId::appPath(appManager: $manager, canonical: 'integriq', suffix: 'api/sources')
		);

	}//end testAPathIsBuiltUnderTheRegisteredId()

	/**
	 * A suffix written with a leading slash does not produce a double slash.
	 *
	 * @return void
	 */
	public function testALeadingSlashOnTheSuffixIsNormalised(): void {
		$manager = $this->manager(['integriq' => true]);

		$this->assertSame(
			'/apps/integriq/api/sources',
			FleetAppId::appPath(appManager: $manager, canonical: 'integriq', suffix: '/api/sources')
		);

	}//end testALeadingSlashOnTheSuffixIsNormalised()

	/**
	 * With the app absent there is no path to build, so callers get null
	 * rather than a URL that would 404.
	 *
	 * @return void
	 */
	public function testAnAbsentAppHasNoPath(): void {
		$manager = $this->manager(['integriq' => false, 'openconnector' => false]);

		$this->assertNull(FleetAppId::appPath(appManager: $manager, canonical: 'integriq', suffix: 'api/sources'));

	}//end testAnAbsentAppHasNoPath()

	/**
	 * A service is fetched under whichever namespace the other app ships: the
	 * newest name is tried first, and a container that refuses it falls
	 * through to the retired name instead of concluding the app is absent.
	 *
	 * @return void
	 */
	public function testAServiceIsFetchedUnderWhicheverNamespaceResolves(): void {
		$service = new \stdClass();

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($service): object {
				if ($id === 'OCA\Integriq\Service\SourceService') {
					throw new RuntimeException('not registered');
				}

				return $service;
			}
		);

		$this->assertSame(
			$service,
			FleetAppId::getService(container: $container, canonical: 'integriq', relative: 'Service\SourceService')
		);

	}//end testAServiceIsFetchedUnderWhicheverNamespaceResolves()

	/**
	 * When no candidate is registered the lookup is null — the caller's
	 * graceful-degradation branch, not an exception.
	 *
	 * @return void
	 */
	public function testAServiceNoCandidateRegistersIsNull(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('not registered'));

		$this->assertNull(
			FleetAppId::getService(container: $container, canonical: 'integriq', relative: 'Service\SourceService')
		);

	}//end testAServiceNoCandidateRegistersIsNull()

	/**
	 * A container answering with a non-object is not a service: the lookup
	 * keeps searching and ends up null rather than handing back a scalar.
	 *
	 * @return void
	 */
	public function testANonObjectFromTheContainerIsNotAService(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn('not a service');

		$this->assertNull(
			FleetAppId::getService(container: $container, canonical: 'integriq', relative: 'Service\SourceService')
		);

	}//end testANonObjectFromTheContainerIsNotAService()

	/**
	 * An unknown canonical app has no namespaces, so nothing is even asked of
	 * the container.
	 *
	 * @return void
	 */
	public function testAnUnknownAppAsksTheContainerNothing(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');

		$this->assertNull(
			FleetAppId::getService(container: $container, canonical: 'nosuchapp', relative: 'Service\Thing')
		);

	}//end testAnUnknownAppAsksTheContainerNothing()

	/**
	 * `resolveClass()` answers with the newest namespace that actually exists,
	 * so a consumer on an instance carrying both names binds the current one.
	 *
	 * @return void
	 */
	public function testResolveClassPrefersTheNewestExistingNamespace(): void {
		if (class_exists('OCA\Integriq\Service\ResolveProbe') === false) {
			eval('namespace OCA\Integriq\Service; class ResolveProbe {}');
		}

		if (class_exists('OCA\OpenConnector\Service\ResolveProbe') === false) {
			eval('namespace OCA\OpenConnector\Service; class ResolveProbe {}');
		}

		$this->assertSame(
			'OCA\Integriq\Service\ResolveProbe',
			FleetAppId::resolveClass(canonical: 'integriq', relative: 'Service\ResolveProbe')
		);

	}//end testResolveClassPrefersTheNewestExistingNamespace()

	/**
	 * A class that exists only under the retired namespace still resolves —
	 * the case that took thirteen fleet integrations dark when it did not.
	 *
	 * @return void
	 */
	public function testResolveClassStillFindsARetiredNamespace(): void {
		if (class_exists('OCA\OpenConnector\Service\LegacyOnlyProbe') === false) {
			eval('namespace OCA\OpenConnector\Service; class LegacyOnlyProbe {}');
		}

		$this->assertSame(
			'OCA\OpenConnector\Service\LegacyOnlyProbe',
			FleetAppId::resolveClass(canonical: 'integriq', relative: 'Service\LegacyOnlyProbe')
		);

	}//end testResolveClassStillFindsARetiredNamespace()

	/**
	 * An app manager reporting the given ids as installed.
	 *
	 * @param array<string, bool> $installed Installed state per app id.
	 *
	 * @return IAppManager&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function manager(array $installed): IAppManager {
		$manager = $this->createMock(IAppManager::class);
		$manager->method('isInstalled')->willReturnCallback(
			static function (string $id) use ($installed): bool {
				return ($installed[$id] ?? false);
			}
		);

		return $manager;
	}//end manager()

}//end class
