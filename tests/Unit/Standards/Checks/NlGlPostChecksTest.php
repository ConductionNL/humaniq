<?php

/**
 * Unit tests for the payroll GL-post idempotency check (NlGlPostChecks).
 *
 * Pins the `nl-glpost-idempotent-per-run` predicate: failed/skipped attempts
 * are always compliant, an active (pending/posted) record with more than one
 * active sibling for the same run violates, and a posted record additionally
 * requires journalEntryId + postedAt plus a cents-balanced lines snapshot.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Standards\Checks
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
 * @spec openspec/changes/payroll-glpost-shillinq/specs/payroll-glpost-shillinq/spec.md#REQ-PGP-007
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Standards\Checks;

use OCA\Humaniq\Standards\Checks\NlGlPostChecks;
use PHPUnit\Framework\TestCase;

/**
 * Tests for NlGlPostChecks.
 *
 * @spec openspec/changes/payroll-glpost-shillinq/specs/payroll-glpost-shillinq/spec.md#REQ-PGP-007
 */
class NlGlPostChecksTest extends TestCase {

	/**
	 * The registered PayrollGLPost predicates, keyed by rule id.
	 *
	 * @var array<string, callable>
	 */
	private array $checks;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->checks = NlGlPostChecks::checks()['PayrollGLPost'];

	}//end setUp()

	/**
	 * A minimal PayrollGLPost fixture; each test overrides the fields it exercises.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function glPost(array $overrides = []): array {
		return array_merge(
			[
				'payrollRunId' => 'run-1',
				'period' => '2026-05',
				'status' => 'posted',
				'journalEntryId' => 'je-1',
				'journalNumber' => 'HRMQ-LOON-2026-05-ADM-001',
				'postedAt' => '2026-06-01T09:00:00Z',
				'lines' => [
					['accountNumber' => '4001', 'side' => 'debit', 'amount' => 3800.00],
					['accountNumber' => '4002', 'side' => 'debit', 'amount' => 649.80],
					['accountNumber' => '1701', 'side' => 'credit', 'amount' => 1102.00],
					['accountNumber' => '1702', 'side' => 'credit', 'amount' => 3347.80],
				],
			],
			$overrides
		);

	}//end glPost()

	/**
	 * A `context['glpost']['activeCountByRun']` fixture.
	 *
	 * @param array<string, int> $counts Per-run active counts.
	 *
	 * @return array<string, mixed>
	 */
	private function context(array $counts = []): array {
		return ['glpost' => ['activeCountByRun' => $counts]];
	}//end context()

	/**
	 * @return void
	 */
	public function testCompliantPostedRecordSatisfiesTheRule(): void {
		$glPost = $this->glPost();
		$context = $this->context(['run-1' => 1]);

		$this->assertTrue(($this->checks['nl-glpost-idempotent-per-run'])($glPost, $context));

	}//end testCompliantPostedRecordSatisfiesTheRule()

	/**
	 * @return void
	 */
	public function testDuplicateActiveRecordsForTheSameRunViolate(): void {
		$glPost = $this->glPost();
		$context = $this->context(['run-1' => 2]);

		$this->assertFalse(($this->checks['nl-glpost-idempotent-per-run'])($glPost, $context));

	}//end testDuplicateActiveRecordsForTheSameRunViolate()

	/**
	 * @return void
	 */
	public function testPendingRecordIsCompliantWhenItIsTheOnlyActiveOne(): void {
		$glPost = $this->glPost(['status' => 'pending', 'journalEntryId' => null, 'postedAt' => null]);
		$context = $this->context(['run-1' => 1]);

		$this->assertTrue(($this->checks['nl-glpost-idempotent-per-run'])($glPost, $context));

	}//end testPendingRecordIsCompliantWhenItIsTheOnlyActiveOne()

	/**
	 * @return void
	 */
	public function testFailedAttemptIsAlwaysCompliantRegardlessOfActiveCount(): void {
		$glPost = $this->glPost(['status' => 'failed', 'journalEntryId' => null, 'postedAt' => null, 'lines' => []]);
		$context = $this->context(['run-1' => 3]);

		$this->assertTrue(($this->checks['nl-glpost-idempotent-per-run'])($glPost, $context));

	}//end testFailedAttemptIsAlwaysCompliantRegardlessOfActiveCount()

	/**
	 * @return void
	 */
	public function testSkippedNoShillinqAttemptIsAlwaysCompliant(): void {
		$glPost = $this->glPost(['status' => 'skipped-no-shillinq', 'journalEntryId' => null, 'postedAt' => null, 'lines' => []]);
		$context = $this->context(['run-1' => 3]);

		$this->assertTrue(($this->checks['nl-glpost-idempotent-per-run'])($glPost, $context));

	}//end testSkippedNoShillinqAttemptIsAlwaysCompliant()

	/**
	 * @return void
	 */
	public function testPostedRecordViolatesWhenJournalEntryIdMissing(): void {
		$glPost = $this->glPost(['journalEntryId' => null]);
		$context = $this->context(['run-1' => 1]);

		$this->assertFalse(($this->checks['nl-glpost-idempotent-per-run'])($glPost, $context));

	}//end testPostedRecordViolatesWhenJournalEntryIdMissing()

	/**
	 * @return void
	 */
	public function testPostedRecordViolatesWhenPostedAtMissing(): void {
		$glPost = $this->glPost(['postedAt' => null]);
		$context = $this->context(['run-1' => 1]);

		$this->assertFalse(($this->checks['nl-glpost-idempotent-per-run'])($glPost, $context));

	}//end testPostedRecordViolatesWhenPostedAtMissing()

	/**
	 * @return void
	 */
	public function testPostedRecordViolatesWhenLinesDoNotBalance(): void {
		$glPost = $this->glPost(
			[
				'lines' => [
					['accountNumber' => '4001', 'side' => 'debit', 'amount' => 3800.00],
					['accountNumber' => '1701', 'side' => 'credit', 'amount' => 1102.00],
				],
			]
		);
		$context = $this->context(['run-1' => 1]);

		$this->assertFalse(($this->checks['nl-glpost-idempotent-per-run'])($glPost, $context));

	}//end testPostedRecordViolatesWhenLinesDoNotBalance()

	/**
	 * @return void
	 */
	public function testPostedRecordViolatesWhenLinesEmpty(): void {
		$glPost = $this->glPost(['lines' => []]);
		$context = $this->context(['run-1' => 1]);

		$this->assertFalse(($this->checks['nl-glpost-idempotent-per-run'])($glPost, $context));

	}//end testPostedRecordViolatesWhenLinesEmpty()

	/**
	 * @return void
	 */
	public function testMissingContextDefaultsToZeroActiveCount(): void {
		$glPost = $this->glPost();

		$this->assertTrue(($this->checks['nl-glpost-idempotent-per-run'])($glPost, []));

	}//end testMissingContextDefaultsToZeroActiveCount()

}//end class
