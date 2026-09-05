<?php

/**
 * Unit tests for {@see \OCA\Humaniq\Repair\MigrateSchemaSlug}.
 *
 * @category  Tests
 * @package   OCA\Humaniq\Tests\Unit\Repair
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Repair;

use OCA\Humaniq\Repair\MigrateRegisterSlugDecisions;
use OCA\Humaniq\Repair\MigrateSchemaSlug;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @covers \OCA\Humaniq\Repair\MigrateSchemaSlug
 *
 * @spec exclude No canonical spec covers the schema-slug namespacing; it is a
 *  migration for a fleet-wide slug collision, not a product requirement.
 */
final class MigrateSchemaSlugTest extends TestCase {

	/**
	 * A connection whose one SELECT returns the given slugs.
	 *
	 * @param array<int, string>             $slugs   Slugs this app's schemas carry.
	 * @param array<int, array<int, string>> $written Captured UPDATE params.
	 *
	 * @return IDBConnection&\PHPUnit\Framework\MockObject\MockObject The double.
	 */
	private function db(array $slugs, array &$written = []) {
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn(
			array_map(static fn (string $s): array => ['slug' => $s], $slugs)
		);

		$db = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willReturn($result);
		$db->method('executeStatement')->willReturnCallback(
			function (string $sql, array $params) use (&$written): int {
				$written[] = $params;
				return 1;
			}
		);

		return $db;

	}//end db()

	/**
	 * Build the step over a database double.
	 *
	 * @param IDBConnection        $db     The connection.
	 * @param LoggerInterface|null $logger An explicit logger, for the refusal case.
	 *
	 * @return MigrateSchemaSlug The step under test.
	 */
	private function step($db, ?LoggerInterface $logger = null): MigrateSchemaSlug {
		return new MigrateSchemaSlug(
			$db,
			($logger ?? $this->createMock(LoggerInterface::class)),
			new MigrateRegisterSlugDecisions()
		);

	}//end step()

	/**
	 * The colliding slug is renamed, and the UPDATE is scoped to this app.
	 *
	 * The scope is the point: a slug is global per organisation, so an UPDATE
	 * that matched on slug alone would rename filinq's `generatedDocument` too,
	 * which is the very row this exists to stop answering for.
	 *
	 * @return void
	 */
	public function testItRenamesTheCollidingSlugScopedToThisApp(): void {
		$written = [];
		$db = $this->db(['Employee', 'GeneratedDocument'], $written);

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')
			->with(self::stringContains('1 schema slug(s) renamed, 0 refused'));

		$this->step($db)->run($output);

		self::assertSame(
			[['HrGeneratedDocument', 'humaniq', 'GeneratedDocument']],
			$written,
			'the UPDATE must carry the application id as well as the slug'
		);

	}//end testItRenamesTheCollidingSlugScopedToThisApp()

	/**
	 * An install that already carries the new slug is left alone, so a second
	 * `occ maintenance:repair` is a no-op rather than a second rename.
	 *
	 * @return void
	 */
	public function testASecondRunDoesNothing(): void {
		$written = [];
		$db = $this->db(['Employee', 'HrGeneratedDocument'], $written);

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')
			->with(self::stringContains('0 schema slug(s) renamed, 0 refused'));

		$this->step($db)->run($output);

		self::assertSame([], $written);

	}//end testASecondRunDoesNothing()

	/**
	 * With BOTH slugs present it refuses rather than merging.
	 *
	 * Two rows sharing (application, slug) means the lower id silently wins
	 * every lookup and the other's objects become unreachable. Choosing between
	 * them is a decision about data, not a migration.
	 *
	 * @return void
	 */
	public function testItRefusesWhenBothSlugsExist(): void {
		$written = [];
		$db = $this->db(['GeneratedDocument', 'HrGeneratedDocument'], $written);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')
			->with(self::stringContains('already exists'));

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')
			->with(self::stringContains('0 schema slug(s) renamed, 1 refused'));

		$this->step($db, $logger)->run($output);

		self::assertSame([], $written, 'it must not write when it refuses');

	}//end testItRefusesWhenBothSlugsExist()

	/**
	 * An unreadable schemas table does nothing, rather than treating "I could
	 * not tell" as "there is nothing to rename".
	 *
	 * @return void
	 */
	public function testAFailedReadDoesNothing(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willThrowException(
			$this->createMock(\OCP\DB\Exception::class)
		);
		$db->expects(self::never())->method('executeStatement');

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')
			->with(self::stringContains('could not read schemas'));

		$this->step($db)->run($output);

	}//end testAFailedReadDoesNothing()

	/**
	 * The map names the slug the descriptors and the services agree on.
	 *
	 * A rename lives in four places at once — two register descriptors, the
	 * service constant and this map — and three of them agreeing is what a
	 * silent no-op looks like.
	 *
	 * @return void
	 */
	public function testTheMapMatchesTheDescriptorsAndTheService(): void {
		$root = dirname(__DIR__, 3);

		self::assertSame(
			['GeneratedDocument' => 'HrGeneratedDocument'],
			MigrateSchemaSlug::SLUG_MAP
		);

		foreach (
			[
				'/lib/Settings/humaniq_mock_register.json',
				'/lib/Settings/register.d/hr-documents.json',
			] as $descriptor
		) {
			$schemas = json_decode(
				(string)file_get_contents($root.$descriptor),
				true
			)['components']['schemas'];

			self::assertArrayHasKey('HrGeneratedDocument', $schemas, $descriptor);
			self::assertArrayNotHasKey('GeneratedDocument', $schemas, $descriptor);
			self::assertSame(
				'HrGeneratedDocument',
				$schemas['HrGeneratedDocument']['slug'],
				$descriptor.' must carry the slug, not only the key'
			);
		}

		$register = json_decode(
			(string)file_get_contents($root.'/lib/Settings/humaniq_register.json'),
			true
		);
		$listed = $register['components']['registers']['humaniq']['schemas'] ?? [];
		self::assertNotEmpty($listed, 'the register must list its schemas at all');
		self::assertContains('HrGeneratedDocument', $listed);
		self::assertNotContains('GeneratedDocument', $listed);

	}//end testTheMapMatchesTheDescriptorsAndTheService()

}//end class
