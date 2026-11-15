<?php

/**
 * Unit tests for PackRunResult — the read-only outcome of a pack run.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Payroll\Dsl
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
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-003
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-006
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Payroll\Dsl;

use OCA\Humaniq\Payroll\Dsl\DslException;
use OCA\Humaniq\Payroll\Dsl\PackRunResult;
use PHPUnit\Framework\TestCase;

/**
 * Lookups by id, their refusals, and the unverified-provenance filter.
 *
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-006
 */
class PackRunResultTest extends TestCase {

	/**
	 * Steps and bindings are read back by id; cents() truncates a float step.
	 *
	 * @return void
	 */
	public function testStepsAndBindingsAreReadBackById(): void {
		$result = $this->runResult();

		$this->assertSame(1234.9, $result->step('tax'));
		$this->assertSame(1234, $result->cents('tax'));
		$this->assertTrue($result->binding('aow'));
		$this->assertSame(['tax' => 1234.9, 'levy' => 500], $result->allSteps());

	}//end testStepsAndBindingsAreReadBackById()

	/**
	 * An undeclared step is refused rather than read as zero.
	 *
	 * @return void
	 */
	public function testAnUndeclaredStepIsRejected(): void {
		$this->expectException(DslException::class);
		$this->expectExceptionMessage('Pack: het pack declareert geen stap "bonus".');

		$this->runResult()->cents('bonus');

	}//end testAnUndeclaredStepIsRejected()

	/**
	 * An undeclared binding is refused rather than read as null.
	 *
	 * @return void
	 */
	public function testAnUndeclaredBindingIsRejected(): void {
		$this->expectException(DslException::class);
		$this->expectExceptionMessage('Pack: het pack declareert geen binding "schijvenSet".');

		$this->runResult()->binding('schijvenSet');

	}//end testAnUndeclaredBindingIsRejected()

	/**
	 * Only leaves that are unverified OR placeholders are flagged.
	 *
	 * @return void
	 */
	public function testOnlyUnverifiedOrPlaceholderProvenanceIsFlagged(): void {
		$result = $this->runResult();

		$this->assertCount(3, $result->provenance());
		$this->assertSame(
			['a.unverified', 'a.placeholder'],
			array_column($result->unverifiedProvenance(), 'path')
		);

	}//end testOnlyUnverifiedOrPlaceholderProvenanceIsFlagged()

	/**
	 * A result with two steps, one binding and three provenance leaves.
	 *
	 * @return PackRunResult
	 */
	private function runResult(): PackRunResult {
		$leaf = static function (string $path, bool $verified, bool $placeholder): array {
			return [
				'path' => $path,
				'source' => 'S',
				'verified' => $verified,
				'placeholder' => $placeholder,
			];
		};

		return new PackRunResult(
			['tax' => 1234.9, 'levy' => 500],
			['aow' => true],
			100000,
			98765,
			500,
			[
				'a.ok' => $leaf('a.ok', true, false),
				'a.unverified' => $leaf('a.unverified', false, false),
				'a.placeholder' => $leaf('a.placeholder', true, true),
			]
		);

	}//end runResult()

}//end class
