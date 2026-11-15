<?php

/**
 * Unit tests for the mapped-scale edge of the HR21 schaal check (NlHr21Checks).
 *
 * Complements tests/Unit/Standards/NlHr21ChecksTest.php, which drives the
 * `nl-hr21-schaal-consistentie` predicate through the real RuleEngine for the
 * resolvable, verified mapping. This suite pins the remaining scope rule of
 * REQ-HR21-003: a verified Normfunctie that carries no CAO scale has nothing
 * to be consistent with, so the contract is not flagged whatever scale it
 * records.
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
 * @spec openspec/specs/functiehuis-hr21/spec.md#REQ-HR21-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Standards\Checks;

use OCA\Humaniq\Standards\Checks\NlHr21Checks;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the mapped-scale edge of NlHr21Checks.
 *
 * @spec openspec/specs/functiehuis-hr21/spec.md#REQ-HR21-003
 */
class NlHr21SchaalMappingTest extends TestCase {

	/**
	 * The `hr21.normfunctiesById` context with one verified Normfunctie.
	 *
	 * @param mixed $caoSchaal The Normfunctie's mapped scale.
	 *
	 * @return array<string, mixed>
	 */
	private function context(mixed $caoSchaal): array {
		return ['hr21' => ['normfunctiesById' => ['HR21-002' => ['functiecode' => 'HR21-002', 'caoSchaal' => $caoSchaal, 'caoSchaalVerified' => true]]]];

	}//end context()

	/**
	 * A verified Normfunctie with a blank or absent scale has no mapping to
	 * check the contract's scale against: the contract passes, while the same
	 * contract against a mapped scale 10 is flagged.
	 *
	 * @return void
	 */
	public function testAVerifiedNormfunctieWithoutAScaleIsVacuous(): void {
		$check = NlHr21Checks::checks()['EmploymentContract']['nl-hr21-schaal-consistentie'];
		$contract = ['normfunctieId' => 'HR21-002', 'caoSchaal' => '8'];

		self::assertFalse($check($contract, $this->context('10')), 'Sanity: a mismatch against a mapped scale is a violation.');
		self::assertTrue($check($contract, $this->context('  ')));
		self::assertTrue($check($contract, $this->context(null)));
		self::assertTrue($check($contract, ['hr21' => ['normfunctiesById' => ['HR21-002' => ['caoSchaalVerified' => true]]]]));

	}//end testAVerifiedNormfunctieWithoutAScaleIsVacuous()

}//end class
