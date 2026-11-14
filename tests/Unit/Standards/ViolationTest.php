<?php

/**
 * Violation test
 *
 * The value object every compliance check reports through. Its serialised
 * shape is what reaches the API and the audit record, so the four field names
 * are part of the contract: renaming one would silently empty a column in
 * every consumer.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Standards
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

namespace OCA\Humaniq\Tests\Unit\Standards;

use OCA\Humaniq\Standards\Violation;
use PHPUnit\Framework\TestCase;

/**
 * The violated-rule value object.
 *
 * @covers \OCA\Humaniq\Standards\Violation
 */
class ViolationTest extends TestCase {

	/**
	 * The four constructor arguments are readable as-is, unmodified.
	 *
	 * @return void
	 */
	public function testTheFourFieldsAreReadableAsGiven(): void {
		$violation = $this->violation();

		$this->assertSame('nl-loonaangifte-termijn', $violation->ruleId);
		$this->assertSame('mandatory', $violation->severity);
		$this->assertSame('WTD 2003/88/EC art. 6', $violation->source);
		$this->assertSame('The weekly working time may not exceed 48 hours.', $violation->statement);

	}//end testTheFourFieldsAreReadableAsGiven()

	/**
	 * The serialised shape carries exactly the four contract keys, in order,
	 * with the values unchanged — this is what the API and the audit record
	 * read.
	 *
	 * @return void
	 */
	public function testTheSerialisedShapeCarriesExactlyTheFourContractKeys(): void {
		$this->assertSame(
			[
				'ruleId'    => 'nl-loonaangifte-termijn',
				'severity'  => 'mandatory',
				'source'    => 'WTD 2003/88/EC art. 6',
				'statement' => 'The weekly working time may not exceed 48 hours.',
			],
			$this->violation()->jsonSerialize()
		);

	}//end testTheSerialisedShapeCarriesExactlyTheFourContractKeys()

	/**
	 * `json_encode` goes through that same shape, so a violation embedded in a
	 * response body carries the same keys as one serialised directly.
	 *
	 * @return void
	 */
	public function testJsonEncodeGoesThroughTheSameShape(): void {
		$encoded = json_encode($this->violation());

		$this->assertIsString($encoded);
		$this->assertSame($this->violation()->jsonSerialize(), json_decode($encoded, true));

	}//end testJsonEncodeGoesThroughTheSameShape()

	/**
	 * A violation under test.
	 *
	 * @return Violation
	 */
	private function violation(): Violation {
		return new Violation(
			'nl-loonaangifte-termijn',
			'mandatory',
			'WTD 2003/88/EC art. 6',
			'The weekly working time may not exceed 48 hours.'
		);

	}//end violation()

}//end class
