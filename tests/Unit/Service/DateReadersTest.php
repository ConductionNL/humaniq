<?php

/**
 * Characterisation tests for the lenient date readers several services carry.
 *
 * Pins three readings over the same inputs: a full parse of any non-empty
 * string; the first ten characters of a trimmed non-empty string; and the
 * leading Y-m-d of a string that starts with one.
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

use OCA\Humaniq\Service\ContractChainService;
use OCA\Humaniq\Service\EmployeeTimeline;
use OCA\Humaniq\Service\LeaveCoverageService;
use OCA\Humaniq\Service\LeaveSubmissionConditionService;
use OCA\Humaniq\Service\ObligationsService;
use OCA\Humaniq\Service\TransitionPaymentCalculator;
use OCA\Humaniq\Service\TrendPeriods;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Every copy of a date reader reads the same inputs the same way.
 */
class DateReadersTest extends TestCase {

	/**
	 * The inputs, each with what the three readings make of it (Y-m-d H:i:s, or null).
	 *
	 * @var list<array{0: mixed, 1: string|null, 2: string|null, 3: string|null}>
	 */
	private const CASES = [
		['2026-03-01', '2026-03-01 00:00:00', '2026-03-01 00:00:00', '2026-03-01 00:00:00'],
		[' 2026-03-01 ', '2026-03-01 00:00:00', '2026-03-01 00:00:00', null],
		['2026-03-01T10:30:00', '2026-03-01 10:30:00', '2026-03-01 00:00:00', '2026-03-01 00:00:00'],
		['2026-02-30', '2026-03-02 00:00:00', '2026-03-02 00:00:00', '2026-03-02 00:00:00'],
		['1 March 2026', '2026-03-01 00:00:00', '2020-03-01 00:00:00', null],
		['morgen', null, null, null],
		['', null, null, null],
		['   ', null, null, null],
	];

	/**
	 * The services and their copy of each reading.
	 *
	 * @return array<string, array{0: class-string, 1: string, 2: int}>
	 */
	public static function readers(): array {
		return [
			'trend periods parse' => [TrendPeriods::class, 'parseDate', 1],
			'employee timeline parse' => [EmployeeTimeline::class, 'date', 1],
			'obligations parse' => [ObligationsService::class, 'parseDate', 1],
			'leave coverage date part' => [LeaveCoverageService::class, 'date', 2],
			'leave conditions date part' => [LeaveSubmissionConditionService::class, 'date', 2],
			'contract chain leading date' => [ContractChainService::class, 'date', 3],
			'transition payment leading date' => [TransitionPaymentCalculator::class, 'date', 3],
		];

	}//end readers()

	/**
	 * @param class-string $class   The service.
	 * @param string       $method  Its reader.
	 * @param int          $reading Which column of CASES it follows.
	 *
	 * @return void
	 *
	 * @dataProvider readers
	 */
	public function testReader(string $class, string $method, int $reading): void {
		foreach (self::CASES as $case) {
			$this->assertSame($case[$reading], $this->read($class, $method, $case[0])?->format('Y-m-d H:i:s'), $class.'::'.$method.'('.var_export($case[0], true).')');
		}

		if ($reading !== 3) {
			$this->assertNull($this->read($class, $method, null));
			$this->assertNull($this->read($class, $method, 20260301));
		}

	}//end testReader()

	/**
	 * Read one value with a service's private (or public) reader.
	 *
	 * @param class-string $class  The service.
	 * @param string       $method Its reader.
	 * @param mixed        $value  The value.
	 *
	 * @return \DateTimeImmutable|null
	 */
	private function read(string $class, string $method, mixed $value): ?\DateTimeImmutable {
		$instance = (new ReflectionClass($class))->newInstanceWithoutConstructor();

		return (new ReflectionMethod($instance, $method))->invoke($instance, $value);

	}//end read()
}//end class
