<?php

/**
 * SeriesLatestTest
 *
 * Pins the refusal this class exists for: the Dashboard's KPI tiles read the
 * MOST RECENT bucket of a trend series, never the most recent one that
 * happens to hold a number.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests
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

use OCA\Humaniq\Service\SeriesLatest;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Humaniq\Service\SeriesLatest
 */
class SeriesLatestTest extends TestCase {

	/**
	 * The headline is the last bucket, and the delta leg the one before it.
	 *
	 * @return void
	 */
	public function testNamesTheMostRecentBucketAndTheOneBeforeIt(): void {
		$latest = (new SeriesLatest())->fromSeries([
			['date' => '2026-06', 'value' => 1.0],
			['date' => '2026-07', 'value' => 2.0],
			['date' => '2026-08', 'value' => 3.0],
		]);

		$this->assertSame('2026-08', $latest['date']);
		$this->assertSame(3.0, $latest['value']);
		$this->assertSame(2.0, $latest['previous']);
	}//end testNamesTheMostRecentBucketAndTheOneBeforeIt()

	/**
	 * The null-not-zero contract, carried into the KPI tile. Every series in
	 * AnalyticsService emits null for "no measurement ran in this period"
	 * precisely so it cannot be mistaken for a good reading; reaching back to
	 * the last populated month would reinstate that confusion, and invisibly,
	 * since a tile has nowhere to say which month it is showing.
	 *
	 * @return void
	 */
	public function testDoesNotReachBackPastAnEmptyMostRecentPeriod(): void {
		$latest = (new SeriesLatest())->fromSeries([
			['date' => '2026-07', 'value' => 3500.0],
			['date' => '2026-08', 'value' => null],
		]);

		$this->assertSame('2026-08', $latest['date']);
		$this->assertNull($latest['value']);
		$this->assertSame(3500.0, $latest['previous']);
	}//end testDoesNotReachBackPastAnEmptyMostRecentPeriod()

	/**
	 * `approval-lead-time` carries its headline under `median` (it also emits
	 * a `p90`), so the key is read off the series rather than assumed — the
	 * metric would otherwise report null forever.
	 *
	 * @return void
	 */
	public function testReadsTheMedianKeyWhenTheSeriesUsesOne(): void {
		$latest = (new SeriesLatest())->fromSeries([
			['date' => '2026-07', 'median' => 2.0, 'p90' => 9.0],
			['date' => '2026-08', 'median' => 4.0, 'p90' => 20.0],
		]);

		$this->assertSame(4.0, $latest['value']);
		$this->assertSame(2.0, $latest['previous']);
	}//end testReadsTheMedianKeyWhenTheSeriesUsesOne()

	/**
	 * A one-bucket series has no previous leg; the tile then renders its
	 * value with no delta rather than a delta against nothing.
	 *
	 * @return void
	 */
	public function testASingleBucketHasNoPreviousLeg(): void {
		$latest = (new SeriesLatest())->fromSeries([['date' => '2026-08', 'value' => 3.0]]);

		$this->assertSame(3.0, $latest['value']);
		$this->assertNull($latest['previous']);
	}//end testASingleBucketHasNoPreviousLeg()

	/**
	 * An empty series yields nulls rather than tripping on an absent index.
	 *
	 * @return void
	 */
	public function testAnEmptySeriesYieldsNulls(): void {
		$latest = (new SeriesLatest())->fromSeries([]);

		$this->assertNull($latest['date']);
		$this->assertNull($latest['value']);
		$this->assertNull($latest['previous']);
	}//end testAnEmptySeriesYieldsNulls()

}//end class
