<?php

/**
 * Every call site's behaviour when this instance carries no humaniq register.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The fix for ConductionNL/openregister#3579 gave six call sites a resolved
 * register slug and, with it, a `null` to handle. `null` is an answer, not an
 * error, and the DIRECTION each site takes on it is the whole point of the
 * change — so each direction is asserted here rather than described in a
 * docblock.
 *
 * The three sites covered here all fail LOUD, and for two different reasons.
 * `RuleAuditService` reads: an empty list from it is summed into a compliance
 * report saying every rule passes, which is a worse lie than an exception.
 * `RuleTestDataSeeder` and `AssetDialectMigrationService` WRITE, and
 * OpenRegister's import path creates a register rather than refusing one, so a
 * slug nothing answers to does not fail there — it forks the data and reports
 * success.
 *
 * The two lifecycle guards fail CLOSED instead, and are covered beside their
 * own tests; `RosterCheckService` reports its absence in the report shape, and
 * `RosterCheckCommand` surfaces that, both covered below.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Command\RosterCheckCommand;
use OCA\Humaniq\Service\AssetDialectMigrationService;
use OCA\Humaniq\Service\RosterCheckService;
use OCA\Humaniq\Service\RuleAuditService;
use OCA\Humaniq\Service\RuleTestDataEmployeeIndex;
use OCA\Humaniq\Service\RuleTestDataSeeder;
use OCA\Humaniq\Tests\Unit\Support\FakeSlugResolver;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Behaviour of every resolved call site on an instance with no humaniq register.
 *
 * @spec exclude Cross-cutting failure-direction coverage for the register-slug
 *  resolution; each subject carries its own feature anchors.
 */
class AbsentRegisterFailureDirectionTest extends TestCase {

	/**
	 * A container that answers every lookup with a resolver reporting an
	 * absent register.
	 *
	 * The ObjectService is deliberately NOT wired: reaching it would mean the
	 * subject read past the absence, which is the defect. A test that supplied
	 * one could not tell "refused to read" from "read and found nothing".
	 *
	 * @return ContainerInterface
	 */
	private function containerWithAbsentRegister(): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn(new FakeSlugResolver([]));

		return $container;
	}//end containerWithAbsentRegister()

	/**
	 * App config with no `register` value stored, so resolution is reached.
	 *
	 * @return IAppConfig
	 */
	private function unsetAppConfig(): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');

		return $appConfig;
	}//end unsetAppConfig()

	/**
	 * RuleAuditService RAISES rather than auditing nothing and calling it clean.
	 *
	 * `loadAll()` already degrades a failed read to an empty list and logs, and
	 * that is right for one schema being unavailable. It is wrong for the
	 * register itself: twenty `loadAll()` calls each returning `[]` produce a
	 * report with no violations, which is what full compliance looks like.
	 *
	 * @return void
	 */
	public function testRuleAuditServiceRaisesRatherThanReportingAnUnreadEstateAsCompliant(): void {
		$service = new RuleAuditService(
			$this->containerWithAbsentRegister(),
			$this->unsetAppConfig(),
			$this->createMock(LoggerInterface::class)
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/humaniq-register is niet gevonden/');

		$service->audit();
	}//end testRuleAuditServiceRaisesRatherThanReportingAnUnreadEstateAsCompliant()

	/**
	 * RuleTestDataSeeder RAISES rather than seeding into a register that is
	 * not there.
	 *
	 * @return void
	 */
	public function testTheSeederRaisesRatherThanWritingIntoAnAbsentRegister(): void {
		$seeder = new RuleTestDataSeeder(
			$this->containerWithAbsentRegister(),
			$this->unsetAppConfig(),
			$this->createMock(IUserManager::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(LoggerInterface::class),
			new RuleTestDataEmployeeIndex($this->createMock(LoggerInterface::class))
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/humaniq-register is niet gevonden/');

		$seeder->seed();
	}//end testTheSeederRaisesRatherThanWritingIntoAnAbsentRegister()

	/**
	 * AssetDialectMigrationService RAISES rather than reporting a migration
	 * that moved nothing as a completed one.
	 *
	 * This one writes with `_rbac: false` and `_multitenancy: false`, so a
	 * wrong slug points a privileged migration at a register that is not there.
	 *
	 * @return void
	 */
	public function testTheAssetMigrationRaisesRatherThanMigratingNothing(): void {
		$service = new AssetDialectMigrationService(
			$this->containerWithAbsentRegister(),
			$this->unsetAppConfig(),
			$this->createMock(LoggerInterface::class)
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/humaniq-register is niet gevonden/');

		$service->migrate();
	}//end testTheAssetMigrationRaisesRatherThanMigratingNothing()

	/**
	 * `occ humaniq:roster:check` prints the reason and exits 1.
	 *
	 * The report shape carries the distinction; this asserts the operator
	 * actually SEES it. Without the branch the command prints "rosters
	 * gecontroleerd: 0" and an operator reads a compliant estate off a register
	 * that is not on the instance.
	 *
	 * @return void
	 */
	public function testTheRosterCommandSaysNothingWasCheckedRatherThanPrintingZeros(): void {
		$service = new RosterCheckService(
			$this->containerWithAbsentRegister(),
			$this->unsetAppConfig(),
			$this->createMock(LoggerInterface::class)
		);

		$output = new BufferedOutput();
		$code = (new RosterCheckCommand($service))->run(new ArrayInput(['--roster' => 'roster-1']), $output);
		$printed = $output->fetch();

		$this->assertSame(1, $code, 'An unanswerable check must not exit 0.');
		$this->assertStringContainsString('humaniq-register is niet gevonden', $printed);
		$this->assertStringContainsString('Er is NIETS gecontroleerd', $printed);
		$this->assertStringNotContainsString(
			'rosters gecontroleerd',
			$printed,
			'The zero counts must not be printed at all; they are what reads as a clean result.'
		);
	}//end testTheRosterCommandSaysNothingWasCheckedRatherThanPrintingZeros()

	/**
	 * A resolvable register leaves the command's ordinary output intact.
	 *
	 * Without this the branch above could swallow every run and both the
	 * exit code and the message would still be asserted correctly.
	 *
	 * @return void
	 */
	public function testTheRosterCommandStillPrintsCountsWhenTheRegisterResolves(): void {
		$objectService = new class {

			/**
			 * @param string $register Register slug (unused by the fake).
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			/**
			 * @param string $schema Schema name (unused by the fake).
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				return $this;
			}//end setSchema()

			/**
			 * @param array<string, mixed> $options Query options (unused by the fake).
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $options = []): array {
				return [];
			}//end findAll()

		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objectService) {
				if ($id === 'OCA\OpenRegister\Service\ObjectService') {
					return $objectService;
				}

				return new FakeSlugResolver(['humaniq']);
			}
		);

		$output = new BufferedOutput();
		$service = new RosterCheckService($container, $this->unsetAppConfig(), $this->createMock(LoggerInterface::class));
		$code = (new RosterCheckCommand($service))->run(new ArrayInput(['--roster' => 'roster-1']), $output);
		$printed = $output->fetch();

		$this->assertStringContainsString('rosters gecontroleerd', $printed);
		$this->assertStringNotContainsString('Er is NIETS gecontroleerd', $printed);
		$this->assertSame(1, $code, 'No roster matched, which the command already reports as 1.');
	}//end testTheRosterCommandStillPrintsCountsWhenTheRegisterResolves()

}//end class
