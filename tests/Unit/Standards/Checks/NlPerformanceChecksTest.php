<?php

/**
 * Unit tests for the NL performance dossiervorming check.
 *
 * Pins the single performance-reviews-mvp predicate
 * (nl-performance-dossiervorming): a `vastgesteld` PerformanceReview must
 * carry a non-null `rating` and non-empty `afspraken`; every other status
 * passes vacuously.
 *
 * @category Test
 * @package  OCA\Hrmq\Tests\Unit\Standards\Checks
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
 * @spec openspec/changes/performance-reviews-mvp/specs/performance-reviews/spec.md#REQ-PRV-003
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Standards\Checks;

use OCA\Hrmq\Standards\Checks\NlPerformanceChecks;
use PHPUnit\Framework\TestCase;

/**
 * Tests for NlPerformanceChecks.
 *
 * @spec openspec/changes/performance-reviews-mvp/specs/performance-reviews/spec.md#REQ-PRV-003
 */
class NlPerformanceChecksTest extends TestCase {

	/**
	 * The registered PerformanceReview predicates, keyed by rule id.
	 *
	 * @var array<string, callable>
	 */
	private array $checks;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->checks = NlPerformanceChecks::checks()['PerformanceReview'];

	}//end setUp()

	/**
	 * A minimal PerformanceReview fixture; each test overrides the fields it
	 * exercises.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function review(array $overrides = []): array {
		return array_merge(
			[
				'employeeId' => 'employee-visser',
				'cycleId' => 'review-cycle-2026',
				'status' => 'vastgesteld',
				'rating' => 'goed',
				'afspraken' => 'Volgt in Q3 de cursus Advanced Vue.',
			],
			$overrides
		);

	}//end review()

	// -- nl-performance-dossiervorming ----------------------------------------

	/**
	 * @return void
	 */
	public function testCompleteVastgesteldReviewSatisfied(): void {
		$review = $this->review();

		$this->assertTrue(($this->checks['nl-performance-dossiervorming'])($review));

	}//end testCompleteVastgesteldReviewSatisfied()

	/**
	 * @return void
	 */
	public function testVastgesteldWithoutRatingViolates(): void {
		$review = $this->review(['rating' => null]);

		$this->assertFalse(($this->checks['nl-performance-dossiervorming'])($review));

	}//end testVastgesteldWithoutRatingViolates()

	/**
	 * @return void
	 */
	public function testVastgesteldWithEmptyAfsprakenViolates(): void {
		$review = $this->review(['afspraken' => '']);

		$this->assertFalse(($this->checks['nl-performance-dossiervorming'])($review));

	}//end testVastgesteldWithEmptyAfsprakenViolates()

	/**
	 * @return void
	 */
	public function testVastgesteldWithWhitespaceOnlyAfsprakenViolates(): void {
		$review = $this->review(['afspraken' => '   ']);

		$this->assertFalse(($this->checks['nl-performance-dossiervorming'])($review));

	}//end testVastgesteldWithWhitespaceOnlyAfsprakenViolates()

	/**
	 * @return void
	 */
	public function testConceptWithoutRatingPassesVacuously(): void {
		$review = $this->review(['status' => 'concept', 'rating' => null, 'afspraken' => null]);

		$this->assertTrue(($this->checks['nl-performance-dossiervorming'])($review));

	}//end testConceptWithoutRatingPassesVacuously()

	/**
	 * @return void
	 */
	public function testIngediendWithoutRatingPassesVacuously(): void {
		$review = $this->review(['status' => 'ingediend', 'rating' => null, 'afspraken' => null]);

		$this->assertTrue(($this->checks['nl-performance-dossiervorming'])($review));

	}//end testIngediendWithoutRatingPassesVacuously()

	/**
	 * @return void
	 */
	public function testBesprokenWithoutRatingPassesVacuously(): void {
		$review = $this->review(['status' => 'besproken', 'rating' => null, 'afspraken' => null]);

		$this->assertTrue(($this->checks['nl-performance-dossiervorming'])($review));

	}//end testBesprokenWithoutRatingPassesVacuously()

}//end class
