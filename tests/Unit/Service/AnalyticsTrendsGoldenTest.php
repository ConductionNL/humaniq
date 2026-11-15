<?php

/**
 * Golden test for AnalyticsService::getTrends().
 *
 * Runs every metric over every period for a whole administration, for org
 * units with and without children, for a unit that is too small and for an
 * unknown unit, against one corpus of sick leave, contracts, payroll runs,
 * payslips, timesheets, expenses, leave requests, employees and org
 * placements spread over two administrations and a year. The current month is
 * pinned to 2026-06 so the series do not move with the calendar. Every answer
 * is compared with tests/fixtures/golden/analytics-trends.json. Set
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
 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Humaniq\Service\AbsenceRateService;
use OCA\Humaniq\Service\AnalyticsCalculators;
use OCA\Humaniq\Service\AnalyticsService;
use OCA\Humaniq\Service\Percentile;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Service\TrendPeriods;
use OCA\Humaniq\Service\UnitTrends;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Golden cases for the trend series.
 *
 * No coverage metadata, like AnalyticsServiceTest: a trend runs the absence,
 * unit, timeline and percentile helpers too.
 */
class AnalyticsTrendsGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/golden/analytics-trends.json';

	/**
	 * The golden cases: every metric for every period and scope.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string|null, 4: int}>
	 */
	public static function cases(): array {
		$scopes = [
			'all' => ['ADM-001', null, 0],
			'blank unit' => ['ADM-001', '', 0],
			'team' => ['ADM-001', 'team', 0],
			'team min 2' => ['ADM-001', 'team', 2],
			'desk min 5' => ['ADM-001', 'desk', 5],
			'other' => ['ADM-001', 'other', 1],
			'unknown unit' => ['ADM-001', 'nowhere', 0],
			'second administration' => ['ADM-002', null, 0],
			'second administration team' => ['ADM-002', 'teamx', 0],
			'first administration asks for a second administration unit' => ['ADM-001', 'teamx', 0],
		];
		$cases = [];
		foreach (array_merge(AnalyticsService::ALLOWED_TREND_METRICS, ['nonsense']) as $metric) {
			foreach (['quarter', 'half-year', 'year', 'decade'] as $period) {
				foreach ($scopes as $name => [$admin, $unit, $minimum]) {
					$cases[$metric . ' | ' . $period . ' | ' . $name] = [$metric, $period, $admin, $unit, $minimum];
				}
			}
		}

		return $cases;

	}//end cases()

	/**
	 * Run one case and compare it with the golden file.
	 *
	 * @param string $metric The metric.
	 * @param string $period The period.
	 * @param string $admin The administration.
	 * @param string|null $unit The org unit, or null.
	 * @param int $minimum The small-unit threshold.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testGolden(string $metric, string $period, string $admin, ?string $unit, int $minimum): void {
		$case = $metric . ' | ' . $period . ' | ' . $admin . ' | ' . var_export($unit, true) . ' | ' . $minimum;
		$flags = (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
		try {
			$answer = $this->trends(metric: $metric, period: $period, scope: [$admin, $unit, $minimum]);
		} catch (InvalidArgumentException $e) {
			$answer = ['exception' => $e->getMessage()];
		}

		$actual = (string)json_encode($answer, $flags);
		$golden = (is_file(self::GOLDEN) === true ? json_decode((string)file_get_contents(self::GOLDEN), true) : []);
		if (getenv('RECORD_GOLDEN') === '1') {
			$golden[$case] = json_decode($actual, true);
			ksort($golden);
			$lines = [];
			foreach ($golden as $name => $recorded) {
				$lines[] = json_encode((string)$name) . ': ' . json_encode($recorded, $flags);
			}

			file_put_contents(self::GOLDEN, "{\n" . implode(",\n", $lines) . "\n}\n");
			$this->markTestSkipped('recorded');
		}

		$this->assertArrayHasKey($case, $golden);
		$this->assertSame(json_encode($golden[$case], $flags), $actual);

	}//end testGolden()

	/**
	 * Ask the service for one trend.
	 *
	 * @param string $metric The metric.
	 * @param string $period The period.
	 * @param array{0: string, 1: string|null, 2: int} $scope Administration, unit and threshold.
	 *
	 * @return array<string, mixed>
	 */
	private function trends(string $metric, string $period, array $scope): array {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn(new AnalyticsGoldenObjectService(self::corpus()));
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$settings->method('isOpenRegisterAvailable')->willReturn(true);

		$service = new AnalyticsService(
			$container,
			$settings,
			$this->createMock(LoggerInterface::class),
			new AnalyticsCalculators(new AbsenceRateService(), new Percentile(), new UnitTrends(), new PinnedTrendPeriods())
		);

		return $service->getTrends($metric, $period, $scope[0], ($scope[1] === null ? null : ['id' => $scope[1], 'minimumMembers' => $scope[2]]));

	}//end trends()

	/**
	 * The rows of every schema, over two administrations and a year.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private static function corpus(): array {
		$rows = [];
		foreach (['ADM-001' => '', 'ADM-002' => 'x'] as $admin => $tag) {
			$a = ['administrationId' => $admin];
			$rows['OrgUnit'][] = (['id' => 'team' . $tag, 'parentUnitId' => null] + $a);
			$rows['OrgUnit'][] = (['id' => 'desk' . $tag, 'parentUnitId' => 'team' . $tag] + $a);
			$rows['OrgUnit'][] = (['id' => 'other' . $tag, 'parentUnitId' => null] + $a);
			foreach (['a' => 'desk', 'b' => 'other', 'c' => 'team', 'd' => 'desk'] as $emp => $unit) {
				$rows['OrgAssignment'][] = (['employeeId' => $emp . $tag, 'orgUnitId' => $unit . $tag, 'startDate' => ($emp === 'd' ? '2026-05-10' : '2020-01-01'), 'endDate' => ($emp === 'c' ? '2026-04-15' : null)] + $a);
				$rows['EmploymentContract'][] = (['employeeId' => $emp . $tag, 'hoursPerWeek' => ($emp === 'b' ? 24.0 : 40.0), 'startDate' => '2020-01-01', 'endDate' => ($emp === 'c' ? '2026-04-30' : null)] + $a);
				$rows['Employee'][] = (['id' => $emp . $tag, 'startDate' => ($emp === 'd' ? '2026-05-10' : '2019-03-01'), 'endDate' => ($emp === 'c' ? '2026-04-30' : null)] + $a);
			}

			$rows['Employee'][] = (['id' => 'nostart' . $tag, 'startDate' => null] + $a);
			$rows['SickLeaveCase'][] = (['employeeId' => 'a' . $tag, 'firstSickDay' => '2026-05-04', 'status' => 'hersteld', 'recoveredDate' => '2026-05-15'] + $a);
			$rows['SickLeaveCase'][] = (['employeeId' => 'b' . $tag, 'firstSickDay' => '2026-06-01', 'status' => 'gemeld'] + $a);
			$rows['SickLeaveCase'][] = (['employeeId' => 'a' . $tag, 'firstSickDay' => '2025-09-10', 'status' => 'hersteld', 'recoveredDate' => '2025-11-20'] + $a);
			$rows['SickLeaveCase'][] = (['employeeId' => 'd' . $tag, 'firstSickDay' => '2026-03-02', 'status' => 'hersteld', 'recoveredDate' => '2026-03-03'] + $a);
			foreach (['2026-04', '2026-05', '2026-06', '2025-12'] as $i => $month) {
				$rows['PayrollRun'][] = (['id' => 'run-' . $month . $tag, 'period' => $month, 'status' => (['posted', 'paid', 'approved', 'draft'][$i]), 'totalGross' => (1000.0 * ($i + 2)), 'totalEmployerCharges' => (100.0 * ($i + 1))] + $a);
				foreach (['a' => 3000.0, 'b' => 2000.0, 'c' => 1500.0] as $emp => $gross) {
					$rows['Payslip'][] = (['employeeId' => $emp . $tag, 'payrollRunId' => 'run-' . $month . $tag, 'grossPay' => ($gross + $i)] + $a);
				}

				$rows['Timesheet'][] = (['period' => $month, 'hours' => (30.0 + $i), 'billable' => true, 'submittedAt' => $month . '-02T09:00:00+00:00', 'approvedAt' => $month . '-0' . (3 + $i) . 'T09:00:00+00:00'] + $a);
				$rows['Timesheet'][] = (['period' => $month, 'hours' => 10.0, 'billable' => false, 'approvedAt' => $month . '-05T09:00:00+00:00'] + $a);
				$rows['Expense'][] = (['submittedAt' => $month . '-01T08:00:00+00:00', 'approvedAt' => $month . '-2' . $i . 'T08:00:00+00:00'] + $a);
				$rows['LeaveRequest'][] = (['submittedAt' => $month . '-10T08:00:00+00:00', 'approvedAt' => 'not a date'] + $a);
			}
		}//end foreach

		return $rows;

	}//end corpus()

}//end class

/**
 * Trend periods with the current month pinned to 2026-06.
 */
class PinnedTrendPeriods extends TrendPeriods {

	/**
	 * The `YYYY-MM` keys of a period, ending in 2026-06.
	 *
	 * @param string $period One of PERIOD_MONTHS' keys.
	 *
	 * @return array<int, string>
	 *
	 * @throws InvalidArgumentException When the period is not recognised.
	 */
	public function keys(string $period): array {
		if (isset(self::PERIOD_MONTHS[$period]) === false) {
			throw new InvalidArgumentException('Invalid period');
		}

		$keys = [];
		for ($offset = (self::PERIOD_MONTHS[$period] - 1); $offset >= 0; $offset--) {
			$keys[] = (new DateTimeImmutable('2026-06-01'))->modify(sprintf('-%d months', $offset))->format('Y-m');
		}

		return $keys;

	}//end keys()

}//end class

/**
 * Canned rows per schema.
 */
class AnalyticsGoldenObjectService {

	/**
	 * The schema set last.
	 *
	 * @var string
	 */
	private string $schema = '';

	/**
	 * @param array<string, array<int, array<string, mixed>>> $rows Rows per schema.
	 */
	public function __construct(
		private readonly array $rows,
	) {

	}//end __construct()

	/**
	 * Select the register.
	 *
	 * @param string $register The register.
	 *
	 * @return self
	 */
	public function setRegister(string $register): self {
		return $this;

	}//end setRegister()

	/**
	 * Select the schema.
	 *
	 * @param string $schema The schema.
	 *
	 * @return self
	 */
	public function setSchema(string $schema): self {
		$this->schema = $schema;
		return $this;

	}//end setSchema()

	/**
	 * The rows of the selected schema.
	 *
	 * @param array<string, mixed> $options Query options.
	 * @param bool $_rbac RBAC flag.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function findAll(array $options = [], bool $_rbac = true): array {
		return ($this->rows[$this->schema] ?? []);

	}//end findAll()

}//end class
