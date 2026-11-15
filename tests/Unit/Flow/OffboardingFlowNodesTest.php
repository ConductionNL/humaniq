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
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
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

		$out = $node->execute(new FlowNodeCall(items: [], config: [], context: []));

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

		$out = $node->execute(new FlowNodeCall(items: [], config: ['afterDays' => 90], context: []));

		self::assertSame('humaniq.anonymise-exit-interviews', $node->getId());
		self::assertSame(0, $out[0]['json']['anonymised']['count']);
	}//end testTheAnonymiseStepPassesTheDays()

	/**
	 * Each incoming item keeps its record and gains the outcome; an object
	 * item is read as an array. Both steps are admin-only, validate nothing
	 * and show the app icon.
	 *
	 * @return void
	 */
	public function testEveryItemCarriesTheOutcomeAndTheStepsAreAdminOnly(): void {
		$service = $this->createMock(AccessRevocationService::class);
		$service->expects(self::once())->method('revokeDue')->willReturn(['case-1', 'case-2']);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('imagePath')->with('humaniq', 'app-dark.svg')->willReturn('/icon.svg');
		$revoke = new RevokeAccessNode($this->l10n(), $urls, $service, '2026-09-29');
		$anonymise = new AnonymiseExitInterviewsNode($this->l10n(), $urls, $this->createMock(ExitInterviewService::class), '2026-09-29');

		$out = $revoke->execute(new FlowNodeCall(items: [['json' => ['keep' => 1], 'binary' => 'b'], (object)['json' => ['other' => 2]]], config: [], context: []));

		self::assertSame(
			[
				['json' => ['keep' => 1, 'revoked' => ['ids' => ['case-1', 'case-2'], 'count' => 2]], 'binary' => 'b'],
				['json' => ['other' => 2, 'revoked' => ['ids' => ['case-1', 'case-2'], 'count' => 2]]],
			],
			$out
		);
		foreach ([$revoke, $anonymise] as $node) {
			self::assertTrue($node->isAvailableForScope(IManager::SCOPE_ADMIN));
			self::assertFalse($node->isAvailableForScope(IManager::SCOPE_USER));
			$node->validateConfig(['anything' => true]);
			self::assertSame('/icon.svg', $node->getIcon());
		}
	}//end testEveryItemCarriesTheOutcomeAndTheStepsAreAdminOnly()

	/**
	 * Without a pinned day the step runs on today, stamped with the current time.
	 *
	 * @return void
	 */
	public function testWithoutAPinnedDayTheStepRunsNow(): void {
		$service = $this->createMock(ExitInterviewService::class);
		$seen = [];
		$service->expects(self::once())->method('anonymiseDue')->willReturnCallback(
			static function (string $today, string $now, int $afterDays) use (&$seen): array {
				$seen = [$today, $now, $afterDays];
				return ['x'];
			}
		);
		$node = new AnonymiseExitInterviewsNode($this->l10n(), $this->createMock(IURLGenerator::class), $service);

		$out = $node->execute(new FlowNodeCall(items: [], config: [], context: []));

		self::assertSame([['json' => ['anonymised' => ['ids' => ['x'], 'count' => 1]]]], $out);
		self::assertMatchesRegularExpression('/^\\d{4}-\\d{2}-\\d{2}$/', $seen[0]);
		self::assertStringStartsWith(substr($seen[0], 0, 10), $seen[1]);
		self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $seen[1]));
		self::assertSame(90, $seen[2]);
	}//end testWithoutAPinnedDayTheStepRunsNow()

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
			// The scheduled flow; Offboarding also carries the Uitdiensttreding task flow (platform-hr-workflows).
			$flows = array_values(array_filter(
				($fragment['components']['schemas'][$schema]['configuration']['x-openregister-flows'] ?? []),
				static fn (array $flow): bool => ($flow['trigger'] ?? '') === 'schedule'
			));
			self::assertCount(1, $flows, $schema);
			$flow = $flows[0];
			self::assertSame('schedule', $flow['trigger']);
			self::assertArrayNotHasKey('enabled', $flow, 'A declared flow arrives disabled.');
			self::assertSame(['openregister.trigger-schedule', $step, 'openregister.end'], array_column($flow['nodes'], 'type'));
			self::assertCount(2, $flow['edges']);
		}
	}//end testTheFlowsShipDisabled()

}//end class
