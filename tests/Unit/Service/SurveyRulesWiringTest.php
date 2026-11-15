<?php

/**
 * Survey rules wiring test
 *
 * The survey audience resolves placements with the OrgResolutionService the
 * SurveyRules bundle carries, not a fresh one.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Service
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

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\Humaniq\Service\SurveyAnswers;
use OCA\Humaniq\Service\SurveyResults;
use OCA\Humaniq\Service\SurveyRules;
use OCA\Humaniq\Service\SurveyService;
use OCA\Humaniq\Service\UnitMembership;
use PHPUnit\Framework\TestCase;

/**
 * The survey audience uses the placement rule from the bundle.
 */
class SurveyRulesWiringTest extends TestCase {

	/**
	 * The audience resolves placements with the very OrgResolutionService instance the bundle carries.
	 *
	 * @return void
	 */
	public function testTheAudienceUsesTheBundledInstance(): void {
		$org = new OrgResolutionService();
		$rules = new SurveyRules(orgResolution: $org, results: new SurveyResults(new SurveyAnswers()), answers: new SurveyAnswers());
		$svc = new SurveyService(gateway: $this->createMock(HoursRegisterGateway::class), membership: new UnitMembership(), marker: new InternalWriteMarker(), rules: $rules);
		$aud = (new \ReflectionProperty(SurveyService::class, 'audience'))->getValue($svc);
		$this->assertSame($org, (new \ReflectionProperty($aud, 'orgResolution'))->getValue($aud));
	}
}
