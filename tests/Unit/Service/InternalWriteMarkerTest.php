<?php

/**
 * InternalWriteMarker unit tests
 *
 * The request-scoped internal-writer marker (hours-process-redesign
 * Decisions 3/4): set only inside runInternal(), reset even when the write
 * throws, and nesting-safe (an inner internal write cannot clear the outer
 * one's marker early).
 *
 * @category Test
 * @package  OCA\Hrmq\Tests\Unit\Service
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
 * @spec openspec/changes/hrmq-hours-process-redesign/specs/time-entry-capture/spec.md#Requirement:-Entries-of-a-submitted-or-approved-timesheet-are-immutable-(REQ-TEC-005)
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Service;

use OCA\Hrmq\Service\InternalWriteMarker;
use PHPUnit\Framework\TestCase;

/**
 * Scope, exception-safety and nesting of the marker.
 */
class InternalWriteMarkerTest extends TestCase {

	/**
	 * Inside runInternal() the marker is set; outside it is not; the
	 * callable's return value passes through.
	 *
	 * @return void
	 */
	public function testMarkerIsScopedToRunInternal(): void {
		$marker = new InternalWriteMarker();
		$this->assertFalse($marker->isInternal());

		$result = $marker->runInternal(function () use ($marker): string {
			$this->assertTrue($marker->isInternal());

			return 'done';
		});

		$this->assertSame('done', $result);
		$this->assertFalse($marker->isInternal());
	}//end testMarkerIsScopedToRunInternal()

	/**
	 * A throwing write resets the marker — a leaked marker would silently
	 * exempt a later CLIENT write in the same request.
	 *
	 * @return void
	 */
	public function testMarkerResetsWhenTheWriteThrows(): void {
		$marker = new InternalWriteMarker();

		try {
			$marker->runInternal(static function (): void {
				throw new \RuntimeException('boom');
			});
			$this->fail('The exception must propagate.');
		} catch (\RuntimeException $e) {
			$this->assertSame('boom', $e->getMessage());
		}

		$this->assertFalse($marker->isInternal(), 'The marker leaked past the exception.');
	}//end testMarkerResetsWhenTheWriteThrows()

	/**
	 * Nested internal writes: the inner exit must not clear the outer scope.
	 *
	 * @return void
	 */
	public function testNestedInternalWritesKeepTheOuterScope(): void {
		$marker = new InternalWriteMarker();

		$marker->runInternal(function () use ($marker): void {
			$marker->runInternal(static function (): void {
				// Inner internal write.
			});
			$this->assertTrue($marker->isInternal(), 'The inner exit cleared the outer marker.');
		});

		$this->assertFalse($marker->isInternal());
	}//end testNestedInternalWritesKeepTheOuterScope()

}//end class
