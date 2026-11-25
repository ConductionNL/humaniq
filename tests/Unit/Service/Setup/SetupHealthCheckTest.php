<?php

/**
 * Unit tests for SetupHealthCheck (setup-wizard-six-steps, REQ-FTS-011).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/setup-wizard-six-steps/specs/first-time-setup/spec.md#scenario-a-failed-health-check-is-shown-as-failed
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service\Setup;

use OCA\Humaniq\Payroll\TaxTables;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Service\Setup\SetupHealthCheck;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * The Klaar step's health check.
 */
#[CoversClass(SetupHealthCheck::class)]
#[UsesClass(TaxTables::class)]
class SetupHealthCheckTest extends TestCase {

	/**
	 * A check over a register with the given schemas, a last cron run and OpenRegister on or off.
	 *
	 * @param array<int,mixed>|null $schemas   The register's schemas; null makes the lookup throw.
	 * @param integer               $lastCron  The epoch of the last background job run.
	 * @param boolean               $available Whether OpenRegister is on.
	 * @param array<int,mixed>      $asked     Collects the arguments `find` was called with.
	 *
	 * @return SetupHealthCheck
	 */
	private function check(?array $schemas, int $lastCron, bool $available = true, array &$asked = []): SetupHealthCheck {
		$mapper = new class ($schemas, $asked) {

			/**
			 * @param array<int,mixed>|null $schemas The schemas.
			 * @param array<int,mixed>      $asked   The find arguments.
			 */
			public function __construct(private ?array $schemas, public array &$asked) {
			}

			/**
			 * @param string  $id            Register slug.
			 * @param boolean $_rbac         RBAC flag.
			 * @param boolean $_multitenancy Multitenancy flag.
			 *
			 * @return object
			 */
			public function find(string $id, bool $_rbac = true, bool $_multitenancy = true): object {
				$this->asked = [$id, $_rbac, $_multitenancy];
				if ($this->schemas === null) {
					throw new RuntimeException('no register');
				}

				$schemas = $this->schemas;
				return new class ($schemas) {

					/**
					 * @param array<int,mixed> $schemas The schemas.
					 */
					public function __construct(private array $schemas) {
					}

					/**
					 * @return array<int,mixed>
					 */
					public function getSchemas(): array {
						return $this->schemas;
					}
				};
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Db\RegisterMapper')->willReturn($mapper);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn($available);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->with('core', 'lastcron', 0)->willReturn($lastCron);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_800_000_000);

		return new SetupHealthCheck($container, $settings, $appConfig, $time);
	}

	/**
	 * An IL10N that fills the template in English, as Nextcloud does without a catalogue entry.
	 *
	 * @param array<string,string> $catalogue Translations by template.
	 *
	 * @return IL10N
	 */
	private function l10n(array $catalogue = []): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, $params = []): string => vsprintf($catalogue[$text] ?? $text, (array) $params));

		return $l10n;
	}

	public function testAHealthyInstallPassesAndNamesWhatItFound(): void {
		$asked = [];
		$report = $this->check(array_fill(0, 54, 1), 1_800_000_000 - 300, true, $asked)->check($this->l10n());

		$this->assertTrue($report['passed']);
		$this->assertSame(54, $report['schemas']);
		$this->assertTrue($report['backgroundJobs']);
		$this->assertSame(2026, $report['payrollTablesYear']);
		$this->assertSame([], $report['failures']);
		$this->assertSame('Check passed: register imported with 54 schemas, background jobs running, payroll tables 2026 loaded.', $report['message']);
		$this->assertSame(['humaniq', false, false], $asked);
		$this->assertTrue($report['ok']);
		$this->assertSame(
			[
				['id' => 'register', 'ok' => true, 'label' => 'register imported with 54 schemas'],
				['id' => 'background-jobs', 'ok' => true, 'label' => 'background jobs running'],
				['id' => 'payroll-tables', 'ok' => true, 'label' => 'payroll tables 2026 loaded'],
			],
			$report['checks']
		);
	}

	/**
	 * The rows the wizard's health line names carry the failing wording for what failed.
	 *
	 * @spec openspec/changes/setup-wizard-six-steps/tasks.md#task-5.2
	 *
	 * @return void
	 */
	public function testEachCheckRowSaysWhatItFound(): void {
		$report = SetupHealthCheck::report(schemas: 12, backgroundJobs: false, payrollTablesYear: null);

		$this->assertFalse($report['ok']);
		$this->assertSame([true, false, false], array_column($report['checks'], 'ok'));
		$this->assertSame(
			['register imported with 12 schemas', 'background jobs are not running', 'no payroll tables are loaded'],
			array_column($report['checks'], 'label')
		);
	}

	/**
	 * A Dutch reader reads the board's wording.
	 *
	 * @spec openspec/changes/setup-wizard-six-steps/tasks.md#task-5.2
	 *
	 * @return void
	 */
	public function testTheCheckRowsAreTranslated(): void {
		$catalogue = [
			'register imported with %1$s schemas' => 'register geïmporteerd met %1$s schema\'s',
			'background jobs running'             => 'achtergrondtaken draaien',
			'payroll tables %1$s loaded'          => 'loonpakket %1$s geladen',
		];
		$asked  = [];
		$report = $this->check(array_fill(0, 54, 1), 1_800_000_000 - 300, true, $asked)->check($this->l10n($catalogue));

		$this->assertSame(
			['register geïmporteerd met 54 schema\'s', 'achtergrondtaken draaien', 'loonpakket 2026 geladen'],
			array_column($report['checks'], 'label')
		);
	}

	public function testAFailedImportIsReportedAsFailed(): void {
		$report = $this->check(null, 1_800_000_000 - 300)->check($this->l10n());

		$this->assertFalse($report['passed']);
		$this->assertSame(0, $report['schemas']);
		$this->assertSame(['the register was not imported'], $report['failures']);
		$this->assertSame('Check failed: the register was not imported.', $report['message']);
		$this->assertStringNotContainsString('passed', $report['message']);
	}

	public function testAnEmptyRegisterCountsAsAFailedImport(): void {
		$report = $this->check([], 1_800_000_000)->check($this->l10n());

		$this->assertFalse($report['passed']);
		$this->assertContains('the register was not imported', $report['failures']);
	}

	public function testWithoutOpenRegisterTheImportFailsAndNoLookupIsMade(): void {
		$asked = [];
		$report = $this->check([1, 2], 1_800_000_000, false, $asked)->check($this->l10n());

		$this->assertFalse($report['passed']);
		$this->assertSame(0, $report['schemas']);
		$this->assertSame([], $asked);
	}

	public function testStaleBackgroundJobsFailTheCheck(): void {
		$report = $this->check([1], 1_800_000_000 - SetupHealthCheck::CRON_MAX_AGE - 1)->check($this->l10n());

		$this->assertFalse($report['passed']);
		$this->assertFalse($report['backgroundJobs']);
		$this->assertSame(['background jobs are not running'], $report['failures']);
	}

	public function testBackgroundJobsJustWithinTheHourStillRun(): void {
		$report = $this->check([1], 1_800_000_000 - SetupHealthCheck::CRON_MAX_AGE)->check($this->l10n());

		$this->assertTrue($report['backgroundJobs']);
	}

	public function testBackgroundJobsThatNeverRanFailTheCheck(): void {
		$report = $this->check([1], 0)->check($this->l10n());

		$this->assertFalse($report['backgroundJobs']);
	}

	public function testEveryFailureIsNamedInOneLine(): void {
		$report = SetupHealthCheck::report(schemas: 0, backgroundJobs: false, payrollTablesYear: null);

		$this->assertFalse($report['passed']);
		$this->assertSame('Check failed: the register was not imported, background jobs are not running, no payroll tables are loaded.', $report['message']);
	}

	public function testLatestYearPicksTheNewestTableAndIgnoresIdsWithoutAYear(): void {
		$this->assertSame(2027, SetupHealthCheck::latestYear(ids: ['nl-2026', 'nl-2027-07', 'custom', 'nl-2025']));
		$this->assertNull(SetupHealthCheck::latestYear(ids: ['custom', 'nl-12345']));
		$this->assertNull(SetupHealthCheck::latestYear(ids: []));
	}

	/**
	 * A register whose schemas are not a list counts as a failed import.
	 *
	 * @return void
	 */
	public function testARegisterWithoutASchemaListCountsAsAFailedImport(): void {
		$register = new class {

			/**
			 * @return null
			 */
			public function getSchemas() {
				return null;
			}
		};
		$mapper = new class ($register) {

			/**
			 * @param object $register The register.
			 */
			public function __construct(private object $register) {
			}

			/**
			 * @param string  $id            Register slug.
			 * @param boolean $_rbac         RBAC flag.
			 * @param boolean $_multitenancy Multitenancy flag.
			 *
			 * @return object
			 */
			public function find(string $id, bool $_rbac = true, bool $_multitenancy = true): object {
				return $this->register;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Db\RegisterMapper')->willReturn($mapper);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn(true);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturn(1_800_000_000);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_800_000_000);

		$report = (new SetupHealthCheck($container, $settings, $appConfig, $time))->check($this->l10n());

		$this->assertFalse($report['passed']);
		$this->assertSame(0, $report['schemas']);
		$this->assertSame(['the register was not imported'], $report['failures']);
	}//end testARegisterWithoutASchemaListCountsAsAFailedImport()
}
