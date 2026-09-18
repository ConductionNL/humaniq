<?php

/**
 * RegisterAgendaLeafListener tests
 *
 * Pins the one thing a leaf cannot be checked for at runtime: that its two
 * halves agree. The server descriptor and `src/integrations/registerAgendaLeaf.js`
 * are bound by a shared id alone, so a rename on one side is an orphan
 * registration on both rather than an error on either. Nothing fails, the
 * surface simply never appears.
 *
 * So this test reads the JS half as text and compares the five values that have
 * to match: the id, the label source string, the icon, the reference type and
 * the surface list.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Listener
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

namespace OCA\Humaniq\Tests\Unit\Listener;

use OCA\Humaniq\Listener\RegisterAgendaLeafListener;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the agenda leaf's server half.
 */
class RegisterAgendaLeafListenerTest extends TestCase {

	/**
	 * The client half, as text.
	 *
	 * @var string
	 */
	private string $clientHalf;

	/**
	 * Read the client half.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$path = (__DIR__ . '/../../../src/integrations/registerAgendaLeaf.js');
		$this->assertFileExists($path, 'The client half must exist, or the leaf is catalogued and dark');
		$this->clientHalf = (string)file_get_contents($path);
	}//end setUp()

	/**
	 * The id, label, icon and reference type match, value for value.
	 *
	 * @return void
	 */
	public function testBothHalvesDeclareTheSameLeaf(): void {
		$this->assertStringContainsString(
			"'" . RegisterAgendaLeafListener::LEAF_ID . "'",
			$this->clientHalf,
			'A leaf id that differs between the halves registers two orphans'
		);
		$this->assertStringContainsString(
			"'" . RegisterAgendaLeafListener::LABEL_SOURCE . "'",
			$this->clientHalf
		);
		$this->assertStringContainsString(
			"icon: '" . RegisterAgendaLeafListener::ICON . "'",
			$this->clientHalf
		);
		$this->assertStringContainsString(
			"referenceType: '" . RegisterAgendaLeafListener::REFERENCE_TYPE . "'",
			$this->clientHalf
		);
		$this->assertStringContainsString(
			"renderMode: 'mount'",
			$this->clientHalf,
			'A render mode that differs blanks the surface under a Vue 2.7 host'
		);
	}//end testBothHalvesDeclareTheSameLeaf()

	/**
	 * The surface lists match, in the same order.
	 *
	 * @return void
	 */
	public function testBothHalvesDeclareTheSameSurfaces(): void {
		$expected = ("['" . implode("', '", RegisterAgendaLeafListener::SURFACES) . "']");

		$this->assertStringContainsString(
			$expected,
			$this->clientHalf,
			'A half that declares its surfaces by omission is how two halves drift apart unnoticed'
		);
	}//end testBothHalvesDeclareTheSameSurfaces()

	/**
	 * The client half is shipped in the leaves bundle, not only on humaniq's
	 * own pages.
	 *
	 * A leaf registered only from `main.js` renders on humaniq's own pages,
	 * where nobody needs it, and is absent from every consuming page, where the
	 * whole point is. That is exactly how `humaniq-hours` was dark for as long
	 * as it shipped.
	 *
	 * @return void
	 */
	public function testTheClientHalfIsInTheLeavesBundle(): void {
		$leaves = (string)file_get_contents(__DIR__ . '/../../../src/leaves.js');

		$this->assertStringContainsString('registerAgendaLeaf', $leaves);
	}//end testTheClientHalfIsInTheLeavesBundle()
}//end class
