<?php

/**
 * Unit tests for CaoRegistry::annualNormHours().
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

use OCA\Humaniq\Standards\CaoRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The annual norm is read only from a verified, non-placeholder workingTime leaf.
 *
 * @spec openspec/changes/education-staff-deployment-and-count/specs/education-staff-deployment/spec.md#scenario-an-unverified-cao-figure-is-not-used
 */
class CaoAnnualNormTest extends TestCase {

	/**
	 * Start every test from the shipped corpus.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		CaoRegistry::reset();
	}//end setUp()

	/**
	 * Today's CAO PO file marks the normjaartaak unverified, so it answers null; an unknown CAO answers null.
	 *
	 * @return void
	 */
	public function testTodaysCaoPoAnswersNull(): void {
		$leaf = CaoRegistry::get('cao-onderwijs-po')['workingTime'];
		$this->assertSame(1659, $leaf['value']['normjaartaakUrenPerJaar'], 'the figure is in the file');
		$this->assertFalse($leaf['verified']);

		$this->assertNull(CaoRegistry::annualNormHours('cao-onderwijs-po'));
		$this->assertNull(CaoRegistry::annualNormHours('cao-onderwijs-vo'));
		$this->assertNull(CaoRegistry::annualNormHours('cao-does-not-exist'));
	}//end testTodaysCaoPoAnswersNull()

	/**
	 * A verified fixture of the same leaf answers 1659.
	 *
	 * @return void
	 */
	public function testAVerifiedFixtureAnswers1659(): void {
		$leaf = CaoRegistry::get('cao-onderwijs-po')['workingTime'];
		$leaf['verified'] = true;
		$leaf['placeholder'] = false;

		$this->assertSame(1659.0, CaoRegistry::annualNormHoursOf($leaf));
	}//end testAVerifiedFixtureAnswers1659()

	/**
	 * A verified leaf that is still a placeholder, has no annual norm, or a non-positive one answers null.
	 *
	 * @return void
	 */
	public function testUnusableLeavesAnswerNull(): void {
		$verified = ['value' => ['normjaartaakUrenPerJaar' => 1659], 'source' => 'x', 'verified' => true];

		$this->assertNull(CaoRegistry::annualNormHoursOf(array_merge($verified, ['placeholder' => true])));
		$this->assertNull(CaoRegistry::annualNormHoursOf(['value' => ['fulltimeHoursPerWeek' => 40], 'source' => 'x', 'verified' => true]));
		$this->assertNull(CaoRegistry::annualNormHoursOf(['value' => ['normjaartaakUrenPerJaar' => 0], 'source' => 'x', 'verified' => true]));
		$this->assertNull(CaoRegistry::annualNormHoursOf(['value' => ['normjaartaakUrenPerJaar' => 'veel'], 'source' => 'x', 'verified' => true]));
		$this->assertNull(CaoRegistry::annualNormHoursOf(['value' => 1659, 'source' => 'x', 'verified' => true]));
		$this->assertNull(CaoRegistry::annualNormHoursOf(null));
		$this->assertSame(1659.0, CaoRegistry::annualNormHoursOf($verified));
	}//end testUnusableLeavesAnswerNull()
}//end class
