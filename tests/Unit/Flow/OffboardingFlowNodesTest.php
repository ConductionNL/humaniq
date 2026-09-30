<?php

/**
 * The offboarding flow steps and the two flows that ship disabled.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Flow
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
 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Flow;

use OCA\Humaniq\Flow\AnonymiseExitInterviewsNode;
use OCA\Humaniq\Flow\HumaniqFlowNodeListener;
use OCA\Humaniq\Flow\RevokeAccessNode;
use OCA\Humaniq\Service\AccessRevocationService;
use OCA\Humaniq\Service\ExitInterviewService;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Each step runs its service once per run and passes the outcome on.
 */
class OffboardingFlowNodesTest extends TestCase {

	/**
	 * Translations that echo.
	 *
	 * @return IL10N
	 */
	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		return $l10n;
	}//end l10n()

	/**
	 * The revoke step calls the service once for the day and carries the ids.
	 *
	 * @return void
	 */
	public function testTheRevokeStepRunsTheDueCasesOnce(): void {
		$service = $this->createMock(AccessRevocationService::class);
		$service->expects(self::once())->method('revokeDue')->with('2026-09-29', '2026-09-29T00:00:00+00:00')->willReturn(['case-1']);
		$node = new RevokeAccessNode($this->l10n(), $this->createMock(IURLGenerator::class), $service, '2026-09-29');

		$out = $node->execute([], [], []);

		self::assertSame('humaniq.revoke-access', $node->getId());
		self::assertSame(['ids' => ['case-1'], 'count' => 1], $out[0]['json']['revoked']);
	}//end testTheRevokeStepRunsTheDueCasesOnce()

	/**
	 * The anonymise step passes its day count on.
	 *
	 * @return void
	 */
	public function testTheAnonymiseStepPassesTheDays(): void {
		$service = $this->createMock(ExitInterviewService::class);
		$service->expects(self::once())->method('anonymiseDue')->with('2026-09-29', '2026-09-29T00:00:00+00:00', 90)->willReturn([]);
		$node = new AnonymiseExitInterviewsNode($this->l10n(), $this->createMock(IURLGenerator::class), $service, '2026-09-29');

		$out = $node->execute([], ['afterDays' => 90], []);

		self::assertSame('humaniq.anonymise-exit-interviews', $node->getId());
		self::assertSame(0, $out[0]['json']['anonymised']['count']);
	}//end testTheAnonymiseStepPassesTheDays()

	/**
	 * Both steps are registered with OpenRegister's flow engine.
	 *
	 * @return void
	 */
	public function testBothStepsAreRegistered(): void {
		self::assertContains(RevokeAccessNode::class, HumaniqFlowNodeListener::nodeClasses());
		self::assertContains(AnonymiseExitInterviewsNode::class, HumaniqFlowNodeListener::nodeClasses());
	}//end testBothStepsAreRegistered()

	/**
	 * The two shipped flows run on a schedule, call the humaniq step, and
	 * arrive disabled.
	 *
	 * @return void
	 */
	public function testTheFlowsShipDisabled(): void {
		$fragment = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/register.d/hr-onboarding.json'), true);
		$expected = ['Offboarding' => 'humaniq.revoke-access', 'ExitInterview' => 'humaniq.anonymise-exit-interviews'];
		foreach ($expected as $schema => $step) {
			$flows = ($fragment['components']['schemas'][$schema]['configuration']['x-openregister-flows'] ?? []);
			self::assertCount(1, $flows, $schema);
			$flow = $flows[0];
			self::assertSame('schedule', $flow['trigger']);
			self::assertArrayNotHasKey('enabled', $flow, 'A declared flow arrives disabled.');
			self::assertSame(['openregister.trigger-schedule', $step, 'openregister.end'], array_column($flow['nodes'], 'type'));
			self::assertCount(2, $flow['edges']);
		}
	}//end testTheFlowsShipDisabled()

}//end class
