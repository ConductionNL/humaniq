<?php

/**
 * Unit tests for where the WNT norm check (NlWntChecks) reads its norm from.
 *
 * Complements tests/Unit/Standards/NlWntChecksTest.php, which pins the
 * predicate against the bundled nl-2026 table. This suite pins the design.md
 * D1 degradation: the norm is read from the LATEST available tax-year table's
 * `dertigProcentRegeling.aftoppingsgrens.jaar` leaf, and when no table is
 * available, the latest one cannot be loaded, or its annual figure is not a
 * positive amount, the check passes vacuously instead of inventing a norm.
 * The table set is varied through TaxTables' own seams: the memoised id list
 * and the uploaded-tables source (a boundary to OpenRegister).
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Standards\Checks
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
 * @spec openspec/specs/wnt-disclosure/spec.md#REQ-WNT-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Standards\Checks;

use OCA\Humaniq\Payroll\TaxTables;
use OCA\Humaniq\Payroll\TaxTableSourceInterface;
use OCA\Humaniq\Standards\Checks\NlWntChecks;
use OCA\Humaniq\Tests\Unit\Support\PackFixtures;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Tests for the WNT norm source of NlWntChecks.
 *
 * @spec openspec/specs/wnt-disclosure/spec.md#REQ-WNT-003
 */
class NlWntChecksNormSourceTest extends TestCase {

	/**
	 * A table id that has no bundled file, so only the uploaded source can serve it.
	 *
	 * @var string
	 */
	private const UPLOADED_ID = 'nl-2999';

	/**
	 * The predicate under test.
	 *
	 * @var callable
	 */
	private $check;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->check = NlWntChecks::checks()['WntDisclosure']['nl-wnt-norm-overschrijding'];

	}//end setUp()

	/**
	 * Restore the bundled table set.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		TaxTables::useSource(null);
		TaxTables::resetAvailableIdsCache();

	}//end tearDown()

	/**
	 * Make TaxTables report exactly these table ids as available.
	 *
	 * @param array<int, string> $ids The ids.
	 *
	 * @return void
	 */
	private function availableIds(array $ids): void {
		$cache = new ReflectionProperty(TaxTables::class, 'availableIdsCache');
		$cache->setValue(null, $ids);

	}//end availableIds()

	/**
	 * Serve UPLOADED_ID from an uploaded-tables source whose WNT annual norm is `$jaar` euro.
	 *
	 * @param mixed $jaar The `dertigProcentRegeling.aftoppingsgrens.value.jaar` leaf.
	 *
	 * @return void
	 */
	private function uploadedNorm(mixed $jaar): void {
		$document = PackFixtures::tables(self::UPLOADED_ID);
		$document['parameters']['dertigProcentRegeling']['aftoppingsgrens']['value']['jaar'] = $jaar;

		$source = new class($document) implements TaxTableSourceInterface {

			/**
			 * @param array<string, mixed> $document The one table document served.
			 */
			public function __construct(private readonly array $document) {
			}//end __construct()

			/**
			 * @param string $id The table id.
			 *
			 * @return array<string, mixed>|null
			 */
			public function activeTables(string $id): ?array {
				return $id === $this->document['id'] ? $this->document : null;
			}//end activeTables()

		};
		TaxTables::useSource(static fn (): TaxTableSourceInterface => $source);

	}//end uploadedNorm()

	/**
	 * A topfunctionaris disclosure without exemption.
	 *
	 * @param int $totalCompensation The annual compensation, in euro.
	 *
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
	 */
	private function disclosureAndContext(int $totalCompensation): array {
		return [
			['employeeId' => 'emp-topf', 'year' => '2026', 'totalCompensation' => $totalCompensation],
			[
				'related' => [
					'Employee' => [
						'byId' => ['emp-topf' => ['id' => 'emp-topf', 'wntTopfunctionaris' => true, 'wntUitzonderingReden' => null]],
					],
				],
			],
		];

	}//end disclosureAndContext()

	/**
	 * With no tax-year table available the norm is unknown: even a
	 * compensation of a million euro passes vacuously.
	 *
	 * @return void
	 */
	public function testNoAvailableTableMakesTheCheckVacuous(): void {
		[$disclosure, $context] = $this->disclosureAndContext(1000000);
		self::assertFalse(($this->check)($disclosure, $context), 'Sanity: against the bundled table this is a violation.');

		$this->availableIds([]);
		self::assertTrue(($this->check)($disclosure, $context));

	}//end testNoAvailableTableMakesTheCheckVacuous()

	/**
	 * When the latest available table id cannot be loaded (no bundled file,
	 * no uploaded source) the norm is unknown and the check passes vacuously.
	 *
	 * @return void
	 */
	public function testAnUnloadableLatestTableMakesTheCheckVacuous(): void {
		[$disclosure, $context] = $this->disclosureAndContext(1000000);

		$this->availableIds(['nl-2026', self::UPLOADED_ID]);
		self::assertTrue(($this->check)($disclosure, $context));

	}//end testAnUnloadableLatestTableMakesTheCheckVacuous()

	/**
	 * The norm is read from the LATEST table, here an uploaded one: its own
	 * annual figure is the boundary.
	 *
	 * @return void
	 */
	public function testTheNormIsReadFromTheLatestAvailableTable(): void {
		$this->availableIds(['nl-2026', self::UPLOADED_ID]);
		$this->uploadedNorm(100000);

		[$atNorm, $context] = $this->disclosureAndContext(100000);
		[$overNorm] = $this->disclosureAndContext(100001);
		self::assertTrue(($this->check)($atNorm, $context));
		self::assertFalse(($this->check)($overNorm, $context));

	}//end testTheNormIsReadFromTheLatestAvailableTable()

	/**
	 * A latest table whose annual figure is zero or negative carries no
	 * usable norm: the check passes vacuously rather than flag everyone.
	 *
	 * @return void
	 */
	public function testANonPositiveAnnualNormMakesTheCheckVacuous(): void {
		[$disclosure, $context] = $this->disclosureAndContext(1000000);
		$this->availableIds(['nl-2026', self::UPLOADED_ID]);

		$this->uploadedNorm(0);
		self::assertTrue(($this->check)($disclosure, $context));

		$this->uploadedNorm(-262000);
		self::assertTrue(($this->check)($disclosure, $context));

	}//end testANonPositiveAnnualNormMakesTheCheckVacuous()

}//end class
