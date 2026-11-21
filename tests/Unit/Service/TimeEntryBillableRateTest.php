<?php

/**
 * Unit tests for the billable rate of a booking (REQ-TEC-009).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Covers ProjectRateReader (the planninq project's rate), the rate stamp of
 * TimeEntryRateListener and the amount per project on the approved-time
 * event, each through its boundary: the app manager, OpenRegister's
 * ObjectService and the record store.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/matrix-rows-without-a-requirement/specs/time-entry-capture/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Kernel\Contracts\OpenRegisterAvailabilityInterface;
use OCA\Humaniq\Listener\TimeEntryRateListener;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\Humaniq\Service\ProjectRateReader;
use OCA\Humaniq\Service\RecordStoreInterface;
use OCA\Humaniq\Service\TimeEntryEventService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCP\App\IAppManager;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A billable booking takes the project's rate; the approved event carries hours times rate.
 *
 * @spec openspec/changes/matrix-rows-without-a-requirement/specs/time-entry-capture/spec.md#requirement-a-billable-booking-shall-carry-the-rate-it-is-billed-at-req-tec-009
 */
#[CoversClass(ProjectRateReader::class)]
#[CoversClass(TimeEntryRateListener::class)]
#[CoversClass(TimeEntryEventService::class)]
final class TimeEntryBillableRateTest extends TestCase {

	/**
	 * A rate reader over planninq projects.
	 *
	 * @param array<string, array<string, mixed>> $projects  Projects by id.
	 * @param bool                                $installed Whether planninq is installed.
	 *
	 * @return ProjectRateReader
	 */
	private function reader(array $projects, bool $installed = true): ProjectRateReader {
		$objects = new class($projects) {
			/**
			 * @param array<string, array<string, mixed>> $projects Projects by id.
			 */
			public function __construct(private array $projects) {
			}//end __construct()

			/**
			 * @param string            $id            The project.
			 * @param ObjectFindOptions $options       Register and schema.
			 * @param bool              $_rbac         Unused.
			 * @param bool              $_multitenancy Unused.
			 *
			 * @return object|null
			 */
			public function find(string $id, ObjectFindOptions $options, bool $_rbac = true, bool $_multitenancy = true): ?object {
				if ($options->register !== 'planninq' || $options->schema !== 'project') {
					throw new RuntimeException('wrong register');
				}

				if (isset($this->projects[$id]) === false) {
					throw new RuntimeException('not found');
				}

				$project = $this->projects[$id];

				return new class($project) {
					/**
					 * @param array<string, mixed> $project The project.
					 */
					public function __construct(private array $project) {
					}//end __construct()

					/**
					 * @return array<string, mixed>
					 */
					public function getObject(): array {
						return $this->project;
					}//end getObject()
				};
			}//end find()
		};

		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->with('planninq')->willReturn($installed);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);
		$openRegister = $this->createMock(OpenRegisterAvailabilityInterface::class);
		$openRegister->method('isAvailable')->willReturn(true);

		return new ProjectRateReader($apps, $container, $openRegister, $this->createMock(LoggerInterface::class));
	}//end reader()

	/**
	 * The listener over a reader.
	 *
	 * @param ProjectRateReader $reader The rates.
	 *
	 * @return TimeEntryRateListener
	 */
	private function listener(ProjectRateReader $reader): TimeEntryRateListener {
		return new TimeEntryRateListener($this->createMock(HoursRegisterGateway::class), $reader, $this->createMock(InternalWriteMarker::class));
	}//end listener()

	/**
	 * Scenario: a billable booking takes the project's rate of 95 euros, as 9500 cents.
	 *
	 * @return void
	 */
	public function testABillableBookingTakesTheProjectsRate(): void {
		$listener = $this->listener($this->reader(['p-95' => ['hourlyRate' => 95], 'p-free' => ['hourlyRate' => 0]]));

		self::assertSame(['hourlyRate' => 9500], $listener->rateStamp(['billable' => true, 'projectId' => 'p-95', 'hours' => 3]));
		self::assertNull($listener->rateStamp(['billable' => true, 'projectId' => 'p-free']), 'A project without a rate stamps none.');
		self::assertNull($listener->rateStamp(['billable' => true, 'projectId' => 'p-missing']));
		self::assertNull($listener->rateStamp(['billable' => true, 'projectId' => 'p-95', 'hourlyRate' => 12000]), 'A rate already on the booking stands.');
	}//end testABillableBookingTakesTheProjectsRate()

	/**
	 * On a create the rate merges into what the stamp listener already set, never replacing it.
	 *
	 * @return void
	 */
	public function testTheListenerMergesIntoTheEarlierStamps(): void {
		$gateway = $this->createMock(HoursRegisterGateway::class);
		$gateway->method('resolveSchemaSlug')->willReturnCallback(static fn (string $schema): string => $schema);
		$listener = new TimeEntryRateListener($gateway, $this->reader(['p-95' => ['hourlyRate' => 95]]), $this->createMock(InternalWriteMarker::class));

		$entity = new ObjectEntity();
		$entity->setSchema('TimeEntry');
		$entity->setObject(['billable' => true, 'projectId' => 'p-95', 'hours' => 3]);
		$event = new ObjectCreatingEvent($entity);
		$event->setModifiedData(['userId' => 'sanne', 'timesheetId' => 'ts-1']);

		$listener->handle($event);

		self::assertSame(['userId' => 'sanne', 'timesheetId' => 'ts-1', 'hourlyRate' => 9500], $event->getModifiedData());

		$other = new ObjectEntity();
		$other->setSchema('Timesheet');
		$other->setObject(['billable' => true, 'projectId' => 'p-95']);
		$untouched = new ObjectCreatingEvent($other);
		$listener->handle($untouched);
		self::assertSame([], $untouched->getModifiedData());
	}//end testTheListenerMergesIntoTheEarlierStamps()

	/**
	 * Scenario: a non-billable booking has no rate.
	 *
	 * @return void
	 */
	public function testANonBillableBookingHasNoRate(): void {
		$listener = $this->listener($this->reader(['p-95' => ['hourlyRate' => 95]]));

		self::assertNull($listener->rateStamp(['billable' => false, 'projectId' => 'p-95']));
		self::assertSame(['hourlyRate' => null], $listener->rateStamp(['billable' => false, 'projectId' => 'p-95', 'hourlyRate' => 9500]));
	}//end testANonBillableBookingHasNoRate()

	/**
	 * Without planninq there is no project, so no rate.
	 *
	 * @return void
	 */
	public function testWithoutPlanninqThereIsNoRate(): void {
		self::assertNull($this->reader(['p-95' => ['hourlyRate' => 95]], false)->rateCents('p-95'));
		self::assertNull($this->reader(['p-95' => ['hourlyRate' => 95]])->rateCents(''));
	}//end testWithoutPlanninqThereIsNoRate()

	/**
	 * Scenario: once approved, the event carries 3 hours and 285 euros for that project.
	 *
	 * @return void
	 */
	public function testTheApprovedEventCarriesHoursAndAmountPerProject(): void {
		$store = $this->createMock(RecordStoreInterface::class);
		$store->method('findFiltered')->with('TimeEntry', ['timesheetId' => 'ts-1'])->willReturn(
			[
				['projectId' => 'p-95', 'billable' => true, 'hours' => 2.0, 'hourlyRate' => 9500],
				['projectId' => 'p-95', 'billable' => true, 'hours' => 1.0, 'hourlyRate' => 9500],
				['projectId' => 'p-95', 'billable' => false, 'hours' => 4.0],
				['projectId' => 'p-norate', 'billable' => true, 'hours' => 1.5],
				['projectId' => '', 'billable' => true, 'hours' => 8.0, 'hourlyRate' => 100],
			]
		);

		$service = new TimeEntryEventService($this->createMock(ContainerInterface::class), $this->createMock(IEventDispatcher::class), $this->createMock(LoggerInterface::class), $store);
		$event = $service->buildApprovedEvent(['id' => 'ts-1', 'status' => 'approved', 'approvedAt' => '2026-10-31T12:00:00Z']);

		self::assertSame(
			[['projectId' => 'p-95', 'hours' => 3.0, 'amount' => 285.0], ['projectId' => 'p-norate', 'hours' => 1.5, 'amount' => 0.0]],
			$event['data']['projects']
		);

		$without = new TimeEntryEventService($this->createMock(ContainerInterface::class), $this->createMock(IEventDispatcher::class), $this->createMock(LoggerInterface::class));
		self::assertArrayNotHasKey('projects', $without->buildApprovedEvent(['id' => 'ts-1'])['data'], 'Without a store the envelope keeps its earlier shape.');
	}//end testTheApprovedEventCarriesHoursAndAmountPerProject()
}//end class
