<?php

/**
 * SurveyOpenGuardTest
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Lifecycle
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
 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Lifecycle;

use OCA\Humaniq\Lifecycle\SurveyOpenGuard;
use OCA\Humaniq\Service\InternalWriteMarker;
use PHPUnit\Framework\TestCase;

/**
 * A survey opens only inside SurveyService's own write.
 */
class SurveyOpenGuardTest extends TestCase {

	/**
	 * Outside humaniq's write the transition is refused; inside it passes.
	 *
	 * @return void
	 */
	public function testOnlyOpenSurveyOpensASurvey(): void {
		$marker = new InternalWriteMarker();
		$guard = new SurveyOpenGuard($marker);
		$survey = ['status' => 'concept', 'title' => 'Pulse'];

		$denied = $guard->check($survey, 'openen', 'hr-user');
		self::assertFalse($denied->isAllowed());
		self::assertStringContainsString('Open survey', (string)$denied->getMessage());

		$allowed = $marker->runInternal(static fn () => $guard->check($survey, 'openen', 'hr-user'));
		self::assertTrue($allowed->isAllowed());
	}//end testOnlyOpenSurveyOpensASurvey()

}//end class
