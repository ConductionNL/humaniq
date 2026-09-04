<?php

/**
 * ADR-083 guard contract
 *
 * Twenty-nine classes reach OpenRegister through a private `objectService()`
 * that establishes availability first. Every one of those guards had its
 * refusing branch executed by NOTHING: the whole suite stubs
 * `isOpenRegisterAvailable()` to true, so the `throw` existed, satisfied
 * gate-66, and was never once shown to fire.
 *
 * That is the shape of a promise nobody checks. The guard's entire purpose is
 * the message an admin sees on an instance without OpenRegister — if it threw
 * the wrong type, named the wrong app, or (after a careless edit) fell through
 * to `$this->container->get(...)` and produced the container explosion it
 * exists to prevent, the suite would still have been green.
 *
 * So this test drives every guarded class through the ONE state the rest of
 * the suite never puts it in. It resolves each class's SettingsService
 * dependency by TYPE rather than by property name, because the fleet spells
 * that property both `$settingsService` and `$settings`, and a test that
 * hard-codes one name silently skips the classes using the other — which is
 * the same invisible hole in a different place.
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
 * @spec exclude architectural contract test — asserts ADR-083 rule 1 holds across every guarded class; no single spec requirement owns it
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit;

use OCA\Humaniq\Service\SettingsService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

/**
 * Every guarded OpenRegister reach refuses, by name, when the app is absent.
 */
class OpenRegisterGuardContractTest extends TestCase {

	/**
	 * Every class whose own `objectService()` carries the availability guard.
	 *
	 * Deliberately an explicit list rather than a directory scan: a scan that
	 * finds nothing passes, and "0 classes checked" is indistinguishable from
	 * "0 classes broken". The count is asserted separately below, so removing
	 * a guard without removing its entry here fails loudly.
	 *
	 * @return array<string, array{0: class-string}>
	 */
	public static function guardedClasses(): array {
		$classes = [
			'OCA\Humaniq\BackgroundJob\LeaveAccrualJob',
			'OCA\Humaniq\Command\AvgDsrRectifyCommand',
			'OCA\Humaniq\Controller\AvgDsrController',
			'OCA\Humaniq\Controller\CompController',
			'OCA\Humaniq\Controller\DocumentController',
			'OCA\Humaniq\Controller\EmployerCostRateController',
			'OCA\Humaniq\Controller\LeaveController',
			'OCA\Humaniq\Controller\LoonbeslagController',
			'OCA\Humaniq\Controller\PayrollController',
			'OCA\Humaniq\Controller\RosterController',
			'OCA\Humaniq\Service\AdministrationService',
			'OCA\Humaniq\Service\AnalyticsService',
			'OCA\Humaniq\Service\AvgDsrRequestStore',
			'OCA\Humaniq\Service\AvgDsrService',
			'OCA\Humaniq\Service\CompAdjustmentService',
			'OCA\Humaniq\Service\HrDocumentService',
			'OCA\Humaniq\Service\InterviewRepository',
			'OCA\Humaniq\Service\JurisdictionPackService',
			'OCA\Humaniq\Service\LeaveBalanceProjectionService',
			'OCA\Humaniq\Service\LeaveBuySellSettlementService',
			'OCA\Humaniq\Service\LeaveCalendarService',
			'OCA\Humaniq\Service\ObligationsService',
			'OCA\Humaniq\Service\OfferApplicationRepository',
			'OCA\Humaniq\Service\PayrollAuditVerificationService',
			'OCA\Humaniq\Service\PayrollGLPostService',
			'OCA\Humaniq\Service\PayrollMutationService',
			'OCA\Humaniq\Service\PayrollNetPayService',
			'OCA\Humaniq\Service\PayrollReproduceService',
			'OCA\Humaniq\Service\PayrollRunService',
			'OCA\Humaniq\Service\ReceiptExtractionRepository',
			'OCA\Humaniq\Service\RetroAdjustmentService',
			'OCA\Humaniq\Service\WkrService',
		];

		$out = [];
		foreach ($classes as $fqcn) {
			$out[$fqcn] = [$fqcn];
		}

		return $out;
	}//end guardedClasses()

	/**
	 * The guarded reach refuses, and says which app to install.
	 *
	 * @param class-string $fqcn The guarded class.
	 *
	 * @return void
	 */
	#[DataProvider('guardedClasses')]
	public function testRefusesWhenOpenRegisterIsAbsent(string $fqcn): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn(false);

		$reflection = new ReflectionClass($fqcn);

		// Constructed WITHOUT the constructor on purpose. These classes take
		// between three and thirteen collaborators; building all of them would
		// make this test about the container rather than about the guard, and
		// the guard reads exactly one of them.
		$instance = $reflection->newInstanceWithoutConstructor();

		$injected = false;
		foreach ($reflection->getProperties() as $property) {
			$type = $property->getType();
			if (($type instanceof ReflectionNamedType) === false || $type->getName() !== SettingsService::class) {
				continue;
			}

			$property->setValue($instance, $settings);
			$injected = true;
		}

		// AN UNINJECTED DEPENDENCY WOULD THROW Error, NOT RuntimeException,
		// and expectException() alone cannot tell those apart at a glance. Say
		// so explicitly, so a class that renames or re-types the property fails
		// with the reason rather than with a confusing type mismatch.
		$this->assertTrue($injected, $fqcn . ' has no SettingsService-typed property, so the guard could not be driven.');

		$method = $reflection->getMethod('objectService');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister');
		$method->invoke($instance);
	}//end testRefusesWhenOpenRegisterIsAbsent()

	/**
	 * The list above still matches what is in `lib/`.
	 *
	 * A data provider that has silently lost entries still passes every case
	 * it does provide. This is the positive control: it counts the guards that
	 * actually exist and refuses to let the two numbers drift apart.
	 *
	 * @return void
	 */
	public function testEveryGuardInLibIsListed(): void {
		$root = dirname(__DIR__, 2) . '/lib';
		$found = [];

		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
		foreach ($files as $file) {
			if ($file->isFile() === false || $file->getExtension() !== 'php') {
				continue;
			}

			$source = (string)file_get_contents($file->getPathname());
			$start = strpos($source, 'private function objectService()');
			if ($start === false) {
				continue;
			}

			$end = strpos($source, '//end objectService()', $start);
			$body = substr($source, $start, (($end === false) ? null : ($end - $start)));
			if (str_contains($body, 'isOpenRegisterAvailable() === false') === true) {
				$found[] = $file->getPathname();
			}
		}

		$this->assertCount(
			count(self::guardedClasses()),
			$found,
			"lib/ holds a different number of SettingsService-guarded objectService() methods than this test lists.\nFound:\n" . implode("\n", $found)
		);
	}//end testEveryGuardInLibIsListed()

}//end class
