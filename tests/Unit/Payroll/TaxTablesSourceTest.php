<?php

/**
 * Uploaded tables are a second home: consulted only for an id no bundled file owns.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Payroll
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
 * @spec openspec/specs/payroll-pack-and-table-updates/spec.md#REQ-PKU-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Payroll;

use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Payroll\TaxTables;
use OCA\Humaniq\Payroll\TaxTableSourceInterface;
use OCA\Humaniq\Service\TaxTableSetService;
use OCA\Humaniq\Tests\Unit\Support\FakeContainer;
use OCA\Humaniq\Tests\Unit\Support\PackFixtures;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * TaxTables::load() reads the bundled file first and the uploaded source second.
 */
class TaxTablesSourceTest extends TestCase {

	/**
	 * @return void
	 */
	protected function tearDown(): void {
		TaxTables::useSource(null);
		parent::tearDown();
	}//end tearDown()

	/**
	 * A source offering its own nl-2026 is never asked: the bundled file wins.
	 *
	 * @return void
	 */
	public function testABundledIdAlwaysLoadsFromDisk(): void {
		$tampered = PackFixtures::tables('nl-2026');
		$tampered['parameters']['zvw']['inhouding']['value'] = 99.0;
		$source = $this->source(['nl-2026' => $tampered]);
		TaxTables::useSource(static fn () => $source);

		self::assertSame(4.85, TaxTables::load('nl-2026')->zvw()['inhouding']);
		self::assertSame([], $source->asked, 'the source is not consulted for a bundled id');
		self::assertTrue(TaxTables::isBundled('nl-2026'));
		self::assertFalse(TaxTables::isBundled('nl-2027'));
	}//end testABundledIdAlwaysLoadsFromDisk()

	/**
	 * An id no bundled file owns loads from the uploaded source.
	 *
	 * @return void
	 */
	public function testAnUnknownIdLoadsFromTheSource(): void {
		$tables = PackFixtures::tables('nl-2027');
		$tables['parameters']['zvw']['inhouding']['value'] = 5.0;
		$source = $this->source(['nl-2027' => $tables]);
		TaxTables::useSource(static fn () => $source);

		$loaded = TaxTables::load('nl-2027');

		self::assertSame('nl-2027', $loaded->id());
		self::assertSame(5.0, $loaded->zvw()['inhouding']);
		self::assertSame(['nl-2027'], $source->asked);
	}//end testAnUnknownIdLoadsFromTheSource()

	/**
	 * Without a source, or when the source has nothing, an unknown id still fails.
	 *
	 * @return void
	 */
	public function testAnUnknownIdWithoutAnUploadStillFails(): void {
		TaxTables::useSource(fn () => $this->source([]));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/nl-2028/');
		TaxTables::load('nl-2028');
	}//end testAnUnknownIdWithoutAnUploadStillFails()

	/**
	 * An uploaded document missing a required group is refused on load too.
	 *
	 * @return void
	 */
	public function testAnUploadedDocumentMissingAGroupIsRefused(): void {
		$tables = PackFixtures::tables('nl-2027');
		unset($tables['parameters']['zvw']);
		TaxTables::useSource(fn () => $this->source(['nl-2027' => $tables]));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/zvw/');
		TaxTables::load('nl-2027');
	}//end testAnUploadedDocumentMissingAGroupIsRefused()

	/**
	 * The app installs the uploaded-tables service as the source at boot,
	 * resolved lazily from the container on first need.
	 *
	 * @return void
	 */
	public function testTheAppInstallsTheUploadedTablesAsTheSource(): void {
		$service = $this->createMock(TaxTableSetService::class);
		$service->expects(self::once())->method('activeTables')->with('nl-2027')->willReturn(PackFixtures::tables('nl-2027'));
		$app = (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();
		$method = new \ReflectionMethod(Application::class, 'registerTaxTableSource');
		$method->invoke($app, new FakeContainer([TaxTableSetService::class => $service]));

		self::assertSame('nl-2027', TaxTables::load('nl-2027')->id());
		$boot = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/AppInfo/Application.php');
		self::assertStringContainsString('$this->registerTaxTableSource($context->getAppContainer());', $boot);
	}//end testTheAppInstallsTheUploadedTablesAsTheSource()

	/**
	 * A source that records what it was asked.
	 *
	 * @param array<string, array<string, mixed>> $documents Tables documents by id.
	 *
	 * @return TaxTableSourceInterface&object{asked: array<int, string>}
	 */
	private function source(array $documents): TaxTableSourceInterface {
		return new class($documents) implements TaxTableSourceInterface {

			/**
			 * @var array<int, string>
			 */
			public array $asked = [];

			/**
			 * @param array<string, array<string, mixed>> $documents Tables documents by id.
			 */
			public function __construct(private readonly array $documents) {
			}//end __construct()

			/**
			 * {@inheritDoc}
			 */
			public function activeTables(string $id): ?array {
				$this->asked[] = $id;

				return ($this->documents[$id] ?? null);
			}//end activeTables()
		};
	}//end source()

}//end class
