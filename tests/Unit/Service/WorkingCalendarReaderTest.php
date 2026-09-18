<?php

/**
 * WorkingCalendarReader tests
 *
 * Pins the one property that keeps a missing working calendar from reading as
 * a working week: every way of failing to reach openregister answers `null`
 * dates and `resolved` false, never an empty list, and every one of them is
 * logged.
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
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
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
	 * A container answering one object for the working calendar service.
	 *
	 * @param mixed $service The object to answer with, or null to throw.
	 *
	 * @return ContainerInterface The container double.
	 */
	private function containerAnswering(mixed $service): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		if ($service === null) {
			$container->method('get')->willThrowException(
				new RuntimeException('Could not resolve OCA\OpenRegister\Service\WorkingCalendarService')
			);

			return $container;
		}

		$container->method('get')->willReturn($service);

		return $container;
	}//end containerAnswering()

	/**
	 * The control: a working calendar that answers dates is read, and the
	 * dates come back normalised to ten characters with duplicates dropped.
	 *
	 * @return void
	 */
	public function testAResolvedCalendarAnswersItsDates(): void {
		$calendar = new class {

			/**
			 * The dates openregister marks non-working.
			 *
			 * @param string $from First day.
			 * @param string $to Last day.
			 *
			 * @return array<int, string> The dates.
			 */
			public function nonWorkingDates(string $from, string $to): array {
				return ['2026-05-25', '2026-05-25T00:00:00+02:00', '2026-05-05', '', 7];
			}
		};

		$reader = new WorkingCalendarReader(
			container: $this->containerAnswering(service: $calendar),
			logger: $this->createMock(LoggerInterface::class)
		);

		$answer = $reader->nonWorkingDates(from: $this->from, to: $this->to);

		$this->assertTrue($answer['resolved']);
		$this->assertSame(['2026-05-25', '2026-05-05'], $answer['dates']);
		$this->assertNull($answer['reason']);
	}//end testAResolvedCalendarAnswersItsDates()

	/**
	 * A calendar that cannot be resolved answers null dates, never an empty
	 * list, and logs the degradation.
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
	 * A resolved service that has lost the method degrades the same way, which
	 * is what a rename on openregister's side looks like from here.
	 *
	 * @return void
	 */
	public function testAServiceWithoutTheMethodDegrades(): void {
		$reader = new WorkingCalendarReader(
			container: $this->containerAnswering(service: new \stdClass()),
			logger: $this->createMock(LoggerInterface::class)
		);

		$answer = $reader->nonWorkingDates(from: $this->from, to: $this->to);

		$this->assertFalse($answer['resolved']);
		$this->assertNull($answer['dates']);
		$this->assertSame('method-missing', $answer['reason']);
	}//end testAServiceWithoutTheMethodDegrades()

	/**
	 * A call that throws degrades rather than propagating: an instance whose
	 * calendar is broken still gets a pattern-only answer.
	 *
	 * @return void
	 */
	public function testACallThatThrowsDegrades(): void {
		$calendar = new class {

			/**
			 * Always fails.
			 *
			 * @param string $from First day.
			 * @param string $to Last day.
			 *
			 * @return array<int, string> Never returns.
			 */
			public function nonWorkingDates(string $from, string $to): array {
				throw new RuntimeException('register unavailable');
			}
		};

		$reader = new WorkingCalendarReader(
			container: $this->containerAnswering(service: $calendar),
			logger: $this->createMock(LoggerInterface::class)
		);

		$answer = $reader->nonWorkingDates(from: $this->from, to: $this->to);

		$this->assertFalse($answer['resolved']);
		$this->assertSame('call-failed', $answer['reason']);
	}//end testACallThatThrowsDegrades()

	/**
	 * A calendar answering something other than a list is not read as one.
	 *
	 * @return void
	 */
	public function testANonListAnswerDegrades(): void {
		$calendar = new class {

			/**
			 * Answers the wrong shape.
			 *
			 * @param string $from First day.
			 * @param string $to Last day.
			 *
			 * @return string The wrong shape.
			 */
			public function nonWorkingDates(string $from, string $to): string {
				return '2026-05-25';
			}
		};

		$reader = new WorkingCalendarReader(
			container: $this->containerAnswering(service: $calendar),
			logger: $this->createMock(LoggerInterface::class)
		);

		$answer = $reader->nonWorkingDates(from: $this->from, to: $this->to);

		$this->assertFalse($answer['resolved']);
		$this->assertSame('unreadable-answer', $answer['reason']);
	}//end testANonListAnswerDegrades()

	/**
	 * A calendar that was read and holds nothing answers an EMPTY list, which
	 * is a different answer from not having been read.
	 *
	 * @return void
	 */
	public function testAnEmptyCalendarIsNotTheSameAsAnUnreadOne(): void {
		$calendar = new class {

			/**
			 * No feestdagen in the range.
			 *
			 * @param string $from First day.
			 * @param string $to Last day.
			 *
			 * @return array<int, string> Empty.
			 */
			public function nonWorkingDates(string $from, string $to): array {
				return [];
			}
		};

		$reader = new WorkingCalendarReader(
			container: $this->containerAnswering(service: $calendar),
			logger: $this->createMock(LoggerInterface::class)
		);

		$answer = $reader->nonWorkingDates(from: $this->from, to: $this->to);

		$this->assertTrue($answer['resolved']);
		$this->assertSame([], $answer['dates']);
	}//end testAnEmptyCalendarIsNotTheSameAsAnUnreadOne()
}//end class
