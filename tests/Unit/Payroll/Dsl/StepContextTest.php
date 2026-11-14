<?php

/**
 * Unit tests for StepContext — the forward-only store of one pack run.
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
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Payroll\Dsl;

use OCA\Humaniq\Payroll\Dsl\DslException;
use OCA\Humaniq\Payroll\Dsl\StepContext;
use OCA\Humaniq\Payroll\TaxTables;
use PHPUnit\Framework\TestCase;

/**
 * Reads, writes and the refusal of every undeclared name.
 *
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-002
 */
class StepContextTest extends TestCase {

	/**
	 * What was supplied or stored reads back unchanged.
	 *
	 * @return void
	 */
	public function testSuppliedAndStoredValuesReadBack(): void {
		$tables = TaxTables::load('nl-2026');
		$ctx = new StepContext(['gross' => 100000, 'dob' => null], $tables, '2026-02', ['jurisdiction' => 'ZZ']);

		$ctx->setBinding('aow', false);
		$ctx->setStep('tax', 1234.5);
		$ctx->setProvenance(['path' => 'zvw.rate', 'source' => 'S', 'verified' => true, 'placeholder' => false]);

		$this->assertSame(100000, $ctx->input('gross'));
		$this->assertNull($ctx->input('dob'), 'a declared null input is present, not unknown');
		$this->assertSame($tables, $ctx->tables());
		$this->assertSame('2026-02', $ctx->period());
		$this->assertSame('ZZ', $ctx->meta('jurisdiction'));
		$this->assertFalse($ctx->binding('aow'));
		$this->assertSame(1234.5, $ctx->step('tax'));
		$this->assertSame(['aow' => false], $ctx->allBindings());
		$this->assertSame(['tax' => 1234.5], $ctx->allSteps());
		$this->assertSame(['zvw.rate'], array_keys($ctx->allProvenance()));

	}//end testSuppliedAndStoredValuesReadBack()

	/**
	 * An undeclared input is refused with the reference spelled out.
	 *
	 * @return void
	 */
	public function testAnUnknownInputIsRejected(): void {
		$this->expectException(DslException::class);
		$this->expectExceptionMessage('Pack: onbekende invoer "@input.hours".');

		$this->context()->input('hours');

	}//end testAnUnknownInputIsRejected()

	/**
	 * An undeclared pack field is refused.
	 *
	 * @return void
	 */
	public function testAnUnknownPackFieldIsRejected(): void {
		$this->expectException(DslException::class);
		$this->expectExceptionMessage('Pack: onbekend pack-veld "@pack.author".');

		$this->context()->meta('author');

	}//end testAnUnknownPackFieldIsRejected()

	/**
	 * A binding read before it was set is a forward reference.
	 *
	 * @return void
	 */
	public function testAForwardBindingReferenceIsRejected(): void {
		$this->expectException(DslException::class);
		$this->expectExceptionMessage('Pack: verwijzing naar niet-eerder-gedeclareerde binding "@binding.aow".');

		$this->context()->binding('aow');

	}//end testAForwardBindingReferenceIsRejected()

	/**
	 * An empty context.
	 *
	 * @return StepContext
	 */
	private function context(): StepContext {
		return new StepContext([], TaxTables::load('nl-2026'), '2026-02', []);

	}//end context()

}//end class
