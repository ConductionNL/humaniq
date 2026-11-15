<?php

/**
 * WorkingCalendarReader tests
 *
 * Pins the one property that keeps a missing working calendar from reading as
 * a working week: every way of failing to reach openregister answers `null`
 * dates and `resolved` false, never an empty list, and every one of them is
 * logged. And pins the typed reach itself: the container is asked for
 * openregister's Flow\Timer\WorkingCalendarService by class, and the
 * resolved calendar's per-year `Y-m-d` keys are narrowed to the range.
 *
 * The resolved case is asserted first, as the control. Without it, "the
 * degradation was taken" cannot be told apart from "the reader cannot read
 * anything at all".
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
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Humaniq\Service\WorkingCalendarReader;
use OCA\OpenRegister\Exception\FlowTimerValidationException;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for WorkingCalendarReader.
 */
class WorkingCalendarReaderTest extends TestCase {

	/**
	 * First day of the range every test asks about.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $from;

	/**
	 * Last day of the range every test asks about.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $to;

	/**
	 * Fix the range.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->from = new DateTimeImmutable('2026-05-01');
		$this->to = new DateTimeImmutable('2026-05-31');
	}//end setUp()

	/**
	 * A container that answers the service only when asked for openregister's
	 * Flow\Timer\WorkingCalendarService by class, and otherwise reports the
	 * name unknown, the way Nextcloud's container does.
	 *
	 * @param mixed $service The object to answer with, or null for "not found".
	 *
	 * @return ContainerInterface The container double.
	 */
	private function containerAnswering(mixed $service): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($service): mixed {
				if ($service === null || $id !== WorkingCalendarService::class) {
					throw new class('Could not resolve ' . $id) extends RuntimeException implements NotFoundExceptionInterface {
					};
				}

				return $service;
			}
		);

		return $container;
	}//end containerAnswering()

	/**
	 * A working calendar service resolving a calendar with these dates.
	 *
	 * @param array<int, array<string, string>> $byYear Year => [`Y-m-d` => name].
	 *
	 * @return WorkingCalendarService The service double.
	 */
	private function serviceWith(array $byYear): WorkingCalendarService {
		$service = $this->createMock(WorkingCalendarService::class);
		$service->expects($this->once())
			->method('resolve')
			->with(null, null)
			->willReturn(WorkingCalendar::fromArray(definition: ['nonWorkingDates' => $byYear]));

		return $service;
	}//end serviceWith()

	/**
	 * The control: the default calendar is resolved through the typed
	 * service and the dates inside the range come back, ascending.
	 *
	 * @return void
	 */
	public function testAResolvedCalendarAnswersItsDates(): void {
		$service = $this->serviceWith(
			byYear: [
				2026 => [
					'2026-04-27' => 'Koningsdag',
					'2026-05-25' => 'Tweede pinksterdag',
					'2026-05-05' => 'Bevrijdingsdag',
					'2026-12-25' => 'Eerste kerstdag',
				],
			]
		);

		$reader = new WorkingCalendarReader(
			container: $this->containerAnswering(service: $service),
			logger: $this->createMock(LoggerInterface::class)
		);

		$answer = $reader->nonWorkingDates(from: $this->from, to: $this->to);

		$this->assertTrue($answer['resolved']);
		$this->assertSame(['2026-05-05', '2026-05-25'], $answer['dates']);
		$this->assertNull($answer['reason']);
	}//end testAResolvedCalendarAnswersItsDates()

	/**
	 * A range over a year boundary asks the calendar for both years.
	 *
	 * @return void
	 */
	public function testARangeOverNewYearReadsBothYears(): void {
		$service = $this->serviceWith(
			byYear: [
				2026 => ['2026-12-25' => 'Eerste kerstdag', '2026-12-26' => 'Tweede kerstdag'],
				2027 => ['2027-01-01' => 'Nieuwjaarsdag', '2027-04-27' => 'Koningsdag'],
			]
		);

		$reader = new WorkingCalendarReader(
			container: $this->containerAnswering(service: $service),
			logger: $this->createMock(LoggerInterface::class)
		);

		$answer = $reader->nonWorkingDates(from: new DateTimeImmutable('2026-12-26'), to: new DateTimeImmutable('2027-01-05'));

		$this->assertTrue($answer['resolved']);
		$this->assertSame(['2026-12-26', '2027-01-01'], $answer['dates']);
	}//end testARangeOverNewYearReadsBothYears()

	/**
	 * A calendar service the container cannot give answers null dates, never
	 * an empty list, and logs the degradation.
	 *
	 * @return void
	 */
	public function testAnUnresolvableCalendarAnswersNullAndLogsIt(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info');

		$reader = new WorkingCalendarReader(
			container: $this->containerAnswering(service: null),
			logger: $logger
		);

		$answer = $reader->nonWorkingDates(from: $this->from, to: $this->to);

		$this->assertFalse($answer['resolved']);
		$this->assertNull($answer['dates'], 'An unread calendar must not look like a calendar with no feestdagen');
		$this->assertSame('no-working-calendar-service', $answer['reason']);
	}//end testAnUnresolvableCalendarAnswersNullAndLogsIt()

	/**
	 * A container answering something other than the calendar service
	 * degrades the same way.
	 *
	 * @return void
	 */
	public function testAForeignServiceDegrades(): void {
		$reader = new WorkingCalendarReader(
			container: $this->containerAnswering(service: new \stdClass()),
			logger: $this->createMock(LoggerInterface::class)
		);

		$answer = $reader->nonWorkingDates(from: $this->from, to: $this->to);

		$this->assertFalse($answer['resolved']);
		$this->assertNull($answer['dates']);
		$this->assertSame('no-working-calendar-service', $answer['reason']);
	}//end testAForeignServiceDegrades()

	/**
	 * A calendar that does not resolve (no default seeded) degrades rather
	 * than propagating: the instance still gets a pattern-only answer.
	 *
	 * @return void
	 */
	public function testACalendarThatDoesNotResolveDegrades(): void {
		$service = $this->createMock(WorkingCalendarService::class);
		$service->method('resolve')->willThrowException(new FlowTimerValidationException("Working calendar 'default' does not exist"));

		$reader = new WorkingCalendarReader(
			container: $this->containerAnswering(service: $service),
			logger: $this->createMock(LoggerInterface::class)
		);

		$answer = $reader->nonWorkingDates(from: $this->from, to: $this->to);

		$this->assertFalse($answer['resolved']);
		$this->assertNull($answer['dates']);
		$this->assertSame('call-failed', $answer['reason']);
	}//end testACalendarThatDoesNotResolveDegrades()

	/**
	 * A calendar that was read and holds nothing in the range answers an
	 * EMPTY list, which is a different answer from not having been read.
	 *
	 * @return void
	 */
	public function testAnEmptyCalendarIsNotTheSameAsAnUnreadOne(): void {
		$service = $this->serviceWith(byYear: [2026 => ['2026-12-25' => 'Eerste kerstdag']]);

		$reader = new WorkingCalendarReader(
			container: $this->containerAnswering(service: $service),
			logger: $this->createMock(LoggerInterface::class)
		);

		$answer = $reader->nonWorkingDates(from: $this->from, to: $this->to);

		$this->assertTrue($answer['resolved']);
		$this->assertSame([], $answer['dates']);
	}//end testAnEmptyCalendarIsNotTheSameAsAnUnreadOne()
}//end class
