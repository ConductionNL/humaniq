<?php

/**
 * TimeEstimateService tests
 *
 * Pins the four things that make an estimate useful rather than decorative: the
 * remainder is derived and moves with the entries, an overrun is negative, a
 * ceiling refuses only the role it belongs to, and a stopped timer is never
 * refused.
 *
 * Each group opens with the case that must NOT be refused, as its control.
 * Without it, "the booking past the ceiling was refused" cannot be told apart
 * from "every booking is refused".
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
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\TimeEstimateService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TimeEstimateService.
 */
class TimeEstimateServiceTest extends TestCase {

	/**
	 * The `<app>:<schema>` literal every fixture is booked against.
	 *
	 * @var string
	 */
	private const TYPE = 'dossiq:zaak';

	/**
	 * The host object every fixture is booked against.
	 *
	 * @var string
	 */
	private const REF = 'zaak-1';

	/**
	 * Service under test.
	 *
	 * @var TimeEstimateService
	 */
	private TimeEstimateService $service;

	/**
	 * Build the service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->service = new TimeEstimateService();
	}//end setUp()

	/**
	 * One estimate against the fixture object.
	 *
	 * @param float $hours What it is expected to take.
	 * @param string|null $role Which role, or null for the object as a whole.
	 * @param bool $enforced Whether the number is a ceiling.
	 * @param string $id The estimate's own id.
	 *
	 * @return array<string, mixed> The estimate.
	 */
	private function estimate(float $hours, ?string $role = null, bool $enforced = false, string $id = 'est-1'): array {
		return [
			'id' => $id,
			'domainObjectType' => self::TYPE,
			'domainObjectRef' => self::REF,
			'estimatedHours' => $hours,
			'role' => $role,
			'enforced' => $enforced,
		];
	}//end estimate()

	/**
	 * One booking against the fixture object.
	 *
	 * @param float $hours The booked hours.
	 * @param string|null $role Which role, or null.
	 * @param string $id The entry's own id.
	 * @param string $origin How the entry was created.
	 *
	 * @return array<string, mixed> The entry.
	 */
	private function entry(float $hours, ?string $role = null, string $id = 'entry-1', string $origin = 'manual'): array {
		return [
			'id' => $id,
			'domainObjectType' => self::TYPE,
			'domainObjectRef' => self::REF,
			'hours' => $hours,
			'role' => $role,
			'origin' => $origin,
		];
	}//end entry()

	/**
	 * Three numbers on an object that has an estimate: eight estimated, three
	 * spent, five remaining.
	 *
	 * @return void
	 */
	public function testEstimatedSpentAndRemainingAreAnswered(): void {
		$summary = $this->service->summary(
			estimates: [$this->estimate(8.0)],
			entries: [$this->entry(2.0, null, 'e-1'), $this->entry(1.0, null, 'e-2')],
			domainObjectType: self::TYPE,
			domainObjectRef: self::REF
		);

		$this->assertTrue($summary['hasEstimate']);
		$this->assertSame(8.0, $summary['estimatedHours']);
		$this->assertSame(3.0, $summary['spentHours']);
		$this->assertSame(5.0, $summary['remainingHours']);
	}//end testEstimatedSpentAndRemainingAreAnswered()

	/**
	 * Deleting an entry moves the remainder at once: the same call over a
	 * shorter list answers a larger remainder, with nothing in between.
	 *
	 * @return void
	 */
	public function testDeletingAnEntryMovesTheRemainderOnTheNextRead(): void {
		$entries = [$this->entry(4.0, null, 'e-1'), $this->entry(2.0, null, 'e-2')];

		$before = $this->service->summary(
			estimates: [$this->estimate(8.0)],
			entries: $entries,
			domainObjectType: self::TYPE,
			domainObjectRef: self::REF
		);

		array_pop($entries);

		$after = $this->service->summary(
			estimates: [$this->estimate(8.0)],
			entries: $entries,
			domainObjectType: self::TYPE,
			domainObjectRef: self::REF
		);

		$this->assertSame(2.0, $before['remainingHours']);
		$this->assertSame(4.0, $after['remainingHours'], 'No recomputation job stands between the two reads');
	}//end testDeletingAnEntryMovesTheRemainderOnTheNextRead()

	/**
	 * An overrun reads as a negative remainder rather than as zero, so the
	 * overrun is visible instead of clipped.
	 *
	 * @return void
	 */
	public function testAnOverrunIsNegativeAndNotClipped(): void {
		$summary = $this->service->summary(
			estimates: [$this->estimate(4.0)],
			entries: [$this->entry(6.0)],
			domainObjectType: self::TYPE,
			domainObjectRef: self::REF
		);

		$this->assertSame(-2.0, $summary['remainingHours']);
	}//end testAnOverrunIsNegativeAndNotClipped()

	/**
	 * No estimate is said, not answered as zero: the spent total still comes
	 * back and the remainder does not exist.
	 *
	 * @return void
	 */
	public function testAnObjectWithNoEstimateHasNoRemainder(): void {
		$summary = $this->service->summary(
			estimates: [],
			entries: [$this->entry(2.0)],
			domainObjectType: self::TYPE,
			domainObjectRef: self::REF
		);

		$this->assertFalse($summary['hasEstimate']);
		$this->assertSame(2.0, $summary['spentHours'], 'Spent still answers');
		$this->assertNull($summary['remainingHours'], 'A remaining of zero would say the estimate is used up');
		$this->assertNull($summary['estimatedHours']);
	}//end testAnObjectWithNoEstimateHasNoRemainder()

	/**
	 * A case estimated per role answers per role, and each role's spent counts
	 * only its own bookings.
	 *
	 * @return void
	 */
	public function testTheSummaryAnswersPerRole(): void {
		$summary = $this->service->summary(
			estimates: [
				$this->estimate(6.0, 'juridisch', false, 'est-jur'),
				$this->estimate(2.0, 'vakafdeling', false, 'est-vak'),
			],
			entries: [
				$this->entry(5.0, 'juridisch', 'e-1'),
				$this->entry(0.5, 'vakafdeling', 'e-2'),
			],
			domainObjectType: self::TYPE,
			domainObjectRef: self::REF
		);

		$this->assertSame(8.0, $summary['estimatedHours'], 'The total is the sum of the roles');
		$this->assertSame(5.5, $summary['spentHours']);
		$this->assertSame(['juridisch', 'vakafdeling'], array_column($summary['roles'], 'role'));
		$this->assertSame(1.0, $summary['roles'][0]['remainingHours']);
		$this->assertSame(1.5, $summary['roles'][1]['remainingHours']);
	}//end testTheSummaryAnswersPerRole()

	/**
	 * Another object's entries and estimates are not counted against this one.
	 *
	 * @return void
	 */
	public function testAnotherObjectsHoursAreNotCounted(): void {
		$summary = $this->service->summary(
			estimates: [$this->estimate(8.0)],
			entries: [
				$this->entry(3.0),
				['id' => 'other', 'domainObjectType' => self::TYPE, 'domainObjectRef' => 'zaak-2', 'hours' => 40.0],
			],
			domainObjectType: self::TYPE,
			domainObjectRef: self::REF
		);

		$this->assertSame(3.0, $summary['spentHours']);
	}//end testAnotherObjectsHoursAreNotCounted()

	/**
	 * A second estimate for the same object and role is refused, and one for a
	 * different role is not.
	 *
	 * @return void
	 */
	public function testOneRoleOneEstimate(): void {
		$existing = [$this->estimate(6.0, 'juridisch', false, 'est-jur')];

		$otherRole = $this->service->refusalForEstimate(
			estimate: $this->estimate(2.0, 'vakafdeling', false, 'est-new'),
			existing: $existing
		);
		$sameRole = $this->service->refusalForEstimate(
			estimate: $this->estimate(4.0, 'juridisch', false, 'est-new'),
			existing: $existing
		);
		$edit = $this->service->refusalForEstimate(
			estimate: $this->estimate(7.0, 'juridisch', false, 'est-jur'),
			existing: $existing
		);

		$this->assertNull($otherRole, 'The control: a second role is an ordinary second estimate');
		$this->assertNotNull($sameRole);
		$this->assertStringContainsString('juridisch', (string)$sameRole);
		$this->assertNull($edit, 'Correcting an estimate is not duplicating it');
	}//end testOneRoleOneEstimate()

	/**
	 * A booking past a capped estimate is refused, with the ceiling and the
	 * hours left named in the refusal.
	 *
	 * @return void
	 */
	public function testABookingPastAnEnforcedCeilingIsRefusedWithTheNumbers(): void {
		$estimates = [$this->estimate(4.0, null, true)];
		$entries = [$this->entry(3.0, null, 'e-1')];

		$fits = $this->service->refusalForEntry(
			entry: $this->entry(1.0, null, 'e-new'),
			estimates: $estimates,
			entries: $entries
		);
		$past = $this->service->refusalForEntry(
			entry: $this->entry(2.0, null, 'e-new'),
			estimates: $estimates,
			entries: $entries
		);

		$this->assertNull($fits, 'The control: a booking that fits exactly is accepted');
		$this->assertNotNull($past);
		$this->assertStringContainsString('4 uur', (string)$past, 'The refusal names the ceiling');
		$this->assertStringContainsString('1 uur over', (string)$past, 'and what is left');
	}//end testABookingPastAnEnforcedCeilingIsRefusedWithTheNumbers()

	/**
	 * An estimate that is not enforced refuses nothing, whatever the total
	 * becomes.
	 *
	 * @return void
	 */
	public function testAnUnenforcedEstimateRefusesNothing(): void {
		$refusal = $this->service->refusalForEntry(
			entry: $this->entry(40.0, null, 'e-new'),
			estimates: [$this->estimate(4.0)],
			entries: [$this->entry(3.0, null, 'e-1')]
		);

		$this->assertNull($refusal);
	}//end testAnUnenforcedEstimateRefusesNothing()

	/**
	 * A ceiling on one role does not block another.
	 *
	 * @return void
	 */
	public function testACeilingOnOneRoleDoesNotBlockAnother(): void {
		$estimates = [
			$this->estimate(4.0, 'juridisch', true, 'est-jur'),
			$this->estimate(2.0, 'vakafdeling', false, 'est-vak'),
		];
		$entries = [$this->entry(4.0, 'juridisch', 'e-1')];

		$blockedRole = $this->service->refusalForEntry(
			entry: $this->entry(1.0, 'juridisch', 'e-new'),
			estimates: $estimates,
			entries: $entries
		);
		$otherRole = $this->service->refusalForEntry(
			entry: $this->entry(1.0, 'vakafdeling', 'e-new'),
			estimates: $estimates,
			entries: $entries
		);

		$this->assertNotNull($blockedRole, 'The juridisch estimate is used up');
		$this->assertNull($otherRole, 'and the vakafdeling booking is somebody else’s work');
	}//end testACeilingOnOneRoleDoesNotBlockAnother()

	/**
	 * A timer stopped over the ceiling records the truth: the entry is not
	 * refused and the remainder goes negative.
	 *
	 * @return void
	 */
	public function testAStoppedTimerLandsPastTheCeiling(): void {
		$estimates = [$this->estimate(4.0, null, true)];

		$refusal = $this->service->refusalForEntry(
			entry: $this->entry(5.0, null, 'e-timer', TimeEstimateService::ORIGIN_TIMER),
			estimates: $estimates,
			entries: []
		);

		$summary = $this->service->summary(
			estimates: $estimates,
			entries: [$this->entry(5.0, null, 'e-timer', TimeEstimateService::ORIGIN_TIMER)],
			domainObjectType: self::TYPE,
			domainObjectRef: self::REF
		);

		$this->assertNull($refusal, 'Time already worked is a fact');
		$this->assertSame(-1.0, $summary['remainingHours'], 'and the overrun is visible rather than discarded');
	}//end testAStoppedTimerLandsPastTheCeiling()

	/**
	 * Correcting an entry does not count that entry against its own ceiling.
	 *
	 * @return void
	 */
	public function testAnEntryBeingCorrectedDoesNotCountAgainstItself(): void {
		$refusal = $this->service->refusalForEntry(
			entry: $this->entry(4.0, null, 'e-1'),
			estimates: [$this->estimate(4.0, null, true)],
			entries: [$this->entry(3.0, null, 'e-1')]
		);

		$this->assertNull($refusal, 'Raising a three-hour entry to four fills the estimate exactly');
	}//end testAnEntryBeingCorrectedDoesNotCountAgainstItself()

	/**
	 * An entry booked against no object meets no ceiling at all.
	 *
	 * @return void
	 */
	public function testAnEntryWithNoHostObjectMeetsNoCeiling(): void {
		$refusal = $this->service->refusalForEntry(
			entry: ['hours' => 40.0, 'origin' => 'manual'],
			estimates: [$this->estimate(4.0, null, true)],
			entries: []
		);

		$this->assertNull($refusal);
	}//end testAnEntryWithNoHostObjectMeetsNoCeiling()
}//end class
