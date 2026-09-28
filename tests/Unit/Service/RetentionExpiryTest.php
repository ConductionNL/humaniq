<?php

/**
 * Unit tests for retention expiry (compliance-retention-expiry).
 *
 * Pins the other end of the hrmq#99 retention clock: once a humaniq
 * statutory floor has passed, the hold humaniq placed is released and the
 * record is marked with the appraisal and date OpenRegister's destruction
 * check reads, while every hold humaniq did not place stays, and nothing
 * happens at all while the admin switch is off.
 *
 * The OpenRegister doubles carry the real method names and the real
 * semantics of `RetentionService::placeLegalHold()`, `releaseLegalHold()`
 * (one hold slot, released holds move to `history`) and
 * `hasActiveLegalHold()`, copied from openregister development 910471dc,
 * since OpenRegister is a sibling app not loaded in this standalone suite.
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
 * @spec openspec/specs/personnel-retention-expiry/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Humaniq\BackgroundJob\RetentionExpiryJob;
use OCA\Humaniq\Service\PayrollRetentionGuardService;
use OCA\Humaniq\Service\RetentionExpiryService;
use OCA\Humaniq\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for retention expiry.
 *
 * @spec openspec/specs/personnel-retention-expiry/spec.md
 */
class RetentionExpiryTest extends TestCase {

	/**
	 * The day every test runs on.
	 *
	 * @var string
	 */
	private const TODAY = '2026-09-28';

	/**
	 * A payslip of March 2012 carries the real humaniq floor hold (to
	 * 2019-12-31); on 2026-09-28 the hold is released and the payslip is
	 * marked `vernietigen` with that floor date.
	 *
	 * @return void
	 */
	public function testALapsedHumaniqFloorHoldIsReleasedAndMarkedForDestruction(): void {
		[$guard, $retention, $mapper, $expiry] = $this->guard();
		$payslip = $this->entity(['period' => '2012-03'], []);
		$guard->placeStatutoryFloorHold($payslip, 'Payslip', 'period', 7, PayrollRetentionGuardService::AWR_LAW_REFERENCE);

		$result = $expiry->releaseLapsedFloorHold($payslip, 'Payslip', new DateTimeImmutable(self::TODAY), true);

		$this->assertTrue($result['released']);
		$this->assertSame('2019-12-31', $result['floor']);
		$block = $payslip->getRetention();
		$this->assertFalse($block['legalHold']['active']);
		$this->assertCount(1, $block['legalHold']['history']);
		$this->assertSame('vernietigen', $block['archiefnominatie']);
		$this->assertSame('2019-12-31', $block['archiefactiedatum']);
		$this->assertCount(1, $retention->releaseCalls);

	}//end testALapsedHumaniqFloorHoldIsReleasedAndMarkedForDestruction()

	/**
	 * A floor that has not passed keeps its hold and gets no mark.
	 *
	 * @return void
	 */
	public function testAFloorHoldThatHasNotLapsedIsKept(): void {
		[$guard, $retention, , $expiry] = $this->guard();
		$payslip = $this->entity(['period' => '2024-03'], []);
		$guard->placeStatutoryFloorHold($payslip, 'Payslip', 'period', 7, PayrollRetentionGuardService::AWR_LAW_REFERENCE);

		$result = $expiry->releaseLapsedFloorHold($payslip, 'Payslip', new DateTimeImmutable(self::TODAY), true);

		$this->assertFalse($result['eligible']);
		$this->assertTrue($payslip->getRetention()['legalHold']['active']);
		$this->assertArrayNotHasKey('archiefnominatie', $payslip->getRetention());
		$this->assertSame([], $retention->releaseCalls);

	}//end testAFloorHoldThatHasNotLapsedIsKept()

	/**
	 * A hold an HR officer placed for a dispute replaced humaniq's floor
	 * hold (one hold slot); it stays and the payslip is not marked.
	 *
	 * @return void
	 */
	public function testAHoldAPersonPlacedStaysAndThePayslipIsNotMarked(): void {
		[$guard, $retention, , $expiry] = $this->guard();
		$payslip = $this->entity(['period' => '2012-03'], []);
		$retention->placeLegalHold($payslip, 'Geschil met werknemer, dossier 2026-14');

		$result = $expiry->releaseLapsedFloorHold($payslip, 'Payslip', new DateTimeImmutable(self::TODAY), true);

		$this->assertFalse($result['eligible']);
		$this->assertTrue($payslip->getRetention()['legalHold']['active']);
		$this->assertArrayNotHasKey('archiefnominatie', $payslip->getRetention());
		$this->assertSame([], $retention->releaseCalls);

	}//end testAHoldAPersonPlacedStaysAndThePayslipIsNotMarked()

	/**
	 * An appraisal already on the record wins over humaniq's mark.
	 *
	 * @return void
	 */
	public function testAnAppraisalAlreadyRecordedWins(): void {
		[$guard, , , $expiry] = $this->guard();
		$payslip = $this->entity(['period' => '2012-03'], ['archiefnominatie' => 'blijvend_bewaren', 'archiefactiedatum' => '2040-01-01']);
		$guard->placeStatutoryFloorHold($payslip, 'Payslip', 'period', 7, PayrollRetentionGuardService::AWR_LAW_REFERENCE);

		$expiry->releaseLapsedFloorHold($payslip, 'Payslip', new DateTimeImmutable(self::TODAY), true);

		$this->assertSame('blijvend_bewaren', $payslip->getRetention()['archiefnominatie']);
		$this->assertSame('2040-01-01', $payslip->getRetention()['archiefactiedatum']);

	}//end testAnAppraisalAlreadyRecordedWins()

	/**
	 * An employee who left on 2017-05-31 is marked with 2024-12-31.
	 *
	 * @return void
	 */
	public function testAnEmployeeWhoLeftIn2017IsMarkedWithTheEndOf2024(): void {
		[, , $mapper, $expiry] = $this->guard();
		$employee = $this->entity(['endDate' => '2017-05-31'], []);

		$result = $expiry->markEndedEmployee($employee, new DateTimeImmutable(self::TODAY), true);

		$this->assertTrue($result['marked']);
		$this->assertSame('vernietigen', $employee->getRetention()['archiefnominatie']);
		$this->assertSame('2024-12-31', $employee->getRetention()['archiefactiedatum']);
		$this->assertCount(1, $mapper->saved);

	}//end testAnEmployeeWhoLeftIn2017IsMarkedWithTheEndOf2024()

	/**
	 * Still employed, recently ended, or under a hold: not marked.
	 *
	 * @return void
	 */
	public function testAnEmployeeStillInsideTheFloorOrUnderAHoldIsNotMarked(): void {
		[$guard, $retention, $mapper, $expiry] = $this->guard();
		$today = new DateTimeImmutable(self::TODAY);
		$employed = $this->entity(['startDate' => '2010-01-01'], []);
		$recent = $this->entity(['endDate' => '2020-06-30'], []);
		$held = $this->entity(['endDate' => '2015-06-30'], []);
		$retention->placeLegalHold($held, 'Procedure bij de rechtbank');

		$this->assertFalse($expiry->markEndedEmployee($employed, $today, true)['eligible']);
		$this->assertFalse($expiry->markEndedEmployee($recent, $today, true)['eligible']);
		$this->assertFalse($expiry->markEndedEmployee($held, $today, true)['eligible']);
		$this->assertSame([], $mapper->saved);

	}//end testAnEmployeeStillInsideTheFloorOrUnderAHoldIsNotMarked()

	/**
	 * With the switch off the walk changes nothing and reports what it
	 * would release and mark.
	 *
	 * @return void
	 */
	public function testNothingChangesWhileTheSwitchIsOff(): void {
		[$service, $retention, $mapper, $payslip, $employee] = $this->walk(enabled: false);

		$summary = $service->run(new DateTimeImmutable(self::TODAY));

		$this->assertFalse($summary['enabled']);
		$this->assertSame([], $retention->releaseCalls);
		$this->assertSame([], $mapper->saved);
		$this->assertTrue($payslip->getRetention()['legalHold']['active']);
		$this->assertArrayNotHasKey('archiefnominatie', $employee->getRetention());
		$this->assertSame(1, $summary['wouldRelease']);
		$this->assertSame(1, $summary['wouldMark']);

	}//end testNothingChangesWhileTheSwitchIsOff()

	/**
	 * With the switch on the walk releases the lapsed payslip hold and
	 * marks the ended employee.
	 *
	 * @return void
	 */
	public function testTheWalkReleasesAndMarksWhenSwitchedOn(): void {
		[$service, $retention, , $payslip, $employee] = $this->walk(enabled: true);

		$summary = $service->run(new DateTimeImmutable(self::TODAY));

		$this->assertTrue($summary['enabled']);
		$this->assertSame(1, $summary['released']);
		$this->assertSame(1, $summary['marked']);
		$this->assertCount(1, $retention->releaseCalls);
		$this->assertSame('vernietigen', $payslip->getRetention()['archiefnominatie']);
		$this->assertSame('vernietigen', $employee->getRetention()['archiefnominatie']);

	}//end testTheWalkReleasesAndMarksWhenSwitchedOn()

	/**
	 * The daily job is registered and hands the run to the service.
	 *
	 * @return void
	 */
	public function testTheDailyJobIsRegisteredAndRunsTheWalk(): void {
		$infoXml = (string)file_get_contents(__DIR__ . '/../../../appinfo/info.xml');
		$this->assertStringContainsString('<job>OCA\Humaniq\BackgroundJob\RetentionExpiryJob</job>', $infoXml);

		$service = $this->createMock(RetentionExpiryService::class);
		$service->expects($this->once())->method('run')->willReturn(
			['enabled' => false, 'released' => 0, 'marked' => 0, 'wouldRelease' => 0, 'wouldMark' => 0]
		);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(strtotime(self::TODAY));

		$job = new RetentionExpiryJob($time, $service, $this->createMock(LoggerInterface::class));
		$job->runExpiry();

	}//end testTheDailyJobIsRegisteredAndRunsTheWalk()

	/**
	 * Build a walk over one lapsed payslip and one long-ended employee.
	 *
	 * @param bool $enabled The admin switch.
	 *
	 * @return array{0: RetentionExpiryService, 1: object, 2: object, 3: object, 4: object}
	 */
	private function walk(bool $enabled): array {
		$retention = $this->retentionService();
		$mapper = $this->mapper();
		$logger = $this->createMock(LoggerInterface::class);
		$guard = new PayrollRetentionGuardService($this->container($retention, $mapper, null), $logger);
		$payslip = $this->entity(['period' => '2012-03'], []);
		$guard->placeStatutoryFloorHold($payslip, 'Payslip', 'period', 7, PayrollRetentionGuardService::AWR_LAW_REFERENCE);
		$mapper->saved = [];
		$employee = $this->entity(['endDate' => '2017-05-31'], []);

		$objects = new class(['Payslip' => [$payslip], 'Employee' => [$employee]]) {
			/**
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @param array<string, array<int, object>> $rows Entities per schema.
			 */
			public function __construct(private readonly array $rows) {

			}//end __construct()

			/**
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * @param string $schema Schema slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * @param array<string, mixed> $config        Query config.
			 * @param bool                 $_rbac         RBAC flag.
			 * @param bool                 $_multitenancy Multitenancy flag.
			 *
			 * @return array<int, object>
			 */
			public function findAll(array $config=[], bool $_rbac=true, bool $_multitenancy=true): array {
				return ($this->rows[$this->schema] ?? []);
			}//end findAll()
		};

		$service = new RetentionExpiryService($this->container($retention, $mapper, $objects), $this->settings($enabled), $logger);

		return [$service, $retention, $mapper, $payslip, $employee];

	}//end walk()

	/**
	 * Build the guard service (to place real humaniq holds) and the expiry
	 * service over OpenRegister doubles with real semantics.
	 *
	 * @return array{0: PayrollRetentionGuardService, 1: object, 2: object, 3: RetentionExpiryService}
	 */
	private function guard(): array {
		$retention = $this->retentionService();
		$mapper = $this->mapper();
		$container = $this->container($retention, $mapper, null);
		$logger = $this->createMock(LoggerInterface::class);

		return [
			new PayrollRetentionGuardService($container, $logger),
			$retention,
			$mapper,
			new RetentionExpiryService($container, $this->settings(true), $logger),
		];

	}//end guard()

	/**
	 * Settings with OpenRegister present and the switch as given.
	 *
	 * @param bool $enabled The admin switch.
	 *
	 * @return SettingsService
	 */
	private function settings(bool $enabled): SettingsService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn(true);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$settings->method('isRetentionExpiryEnabled')->willReturn($enabled);

		return $settings;

	}//end settings()

	/**
	 * A container answering OpenRegister's RetentionService, MagicMapper and ObjectService.
	 *
	 * @param object      $retention The RetentionService double.
	 * @param object      $mapper    The MagicMapper double.
	 * @param object|null $objects   The ObjectService double, if any.
	 *
	 * @return ContainerInterface
	 */
	private function container(object $retention, object $mapper, ?object $objects): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($retention, $mapper, $objects) {
				$map = [
					'OCA\OpenRegister\Service\RetentionService' => $retention,
					'OCA\OpenRegister\Db\MagicMapper' => $mapper,
					'OCA\OpenRegister\Service\ObjectService' => $objects,
				];
				if (($map[$id] ?? null) === null) {
					throw new \RuntimeException('Unexpected container->get(' . $id . ')');
				}

				return $map[$id];
			}
		);

		return $container;

	}//end container()

	/**
	 * OpenRegister MagicMapper double: `update()` records every write.
	 *
	 * @return object
	 */
	private function mapper(): object {
		return new class {
			/**
			 * @var array<int, object>
			 */
			public array $saved = [];

			/**
			 * @param object $object The entity to persist.
			 *
			 * @return object
			 */
			public function update(object $object): object {
				$this->saved[] = $object;
				return $object;
			}//end update()
		};

	}//end mapper()

	/**
	 * An ObjectEntity double with the real getObject/getRetention/setRetention contract.
	 *
	 * @param array<string, mixed> $data      Payload.
	 * @param array<string, mixed> $retention Retention block.
	 *
	 * @return object
	 */
	private function entity(array $data, array $retention): object {
		return new class($data, $retention) {
			/**
			 * @param array<string, mixed> $data      Payload.
			 * @param array<string, mixed> $retention Retention block.
			 */
			public function __construct(private readonly array $data, private array $retention) {

			}//end __construct()

			/**
			 * @return array<string, mixed>
			 */
			public function getObject(): array {
				return $this->data;
			}//end getObject()

			/**
			 * @return array<string, mixed>
			 */
			public function getRetention(): array {
				return $this->retention;
			}//end getRetention()

			/**
			 * @param array<string, mixed> $retention Retention block.
			 *
			 * @return void
			 */
			public function setRetention(array $retention): void {
				$this->retention = $retention;
			}//end setRetention()
		};

	}//end entity()

	/**
	 * OpenRegister RetentionService double: the legal-hold methods with the
	 * semantics of openregister development 910471dc.
	 *
	 * @return object
	 */
	private function retentionService(): object {
		return new class {
			/**
			 * @var array<int, string>
			 */
			public array $releaseCalls = [];

			/**
			 * @param object $object The object.
			 * @param string $reason The hold reason.
			 *
			 * @return object
			 */
			public function placeLegalHold(object $object, string $reason): object {
				$retention = $object->getRetention();
				$retention['legalHold'] = [
					'active' => true,
					'reason' => $reason,
					'placedBy' => 'system',
					'placedDate' => '2013-01-01T00:00:00+00:00',
					'history' => ($retention['legalHold']['history'] ?? []),
				];
				$object->setRetention($retention);
				return $object;
			}//end placeLegalHold()

			/**
			 * @param object $object The object.
			 * @param string $reason The release reason.
			 *
			 * @return object
			 */
			public function releaseLegalHold(object $object, string $reason): object {
				$retention = $object->getRetention();
				$legalHold = ($retention['legalHold'] ?? null);
				if ($legalHold === null || ($legalHold['active'] ?? false) === false) {
					return $object;
				}

				$this->releaseCalls[] = $reason;
				$history = ($legalHold['history'] ?? []);
				$history[] = ['reason' => ($legalHold['reason'] ?? ''), 'releaseReason' => $reason];
				$retention['legalHold'] = ['active' => false, 'history' => $history];
				$object->setRetention($retention);
				return $object;
			}//end releaseLegalHold()

			/**
			 * @param object $object The object.
			 *
			 * @return bool
			 */
			public function hasActiveLegalHold(object $object): bool {
				return (($object->getRetention()['legalHold']['active'] ?? false) === true);
			}//end hasActiveLegalHold()
		};

	}//end retentionService()

}//end class
