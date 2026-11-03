<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The CAO components folded onto a payslip match the recorded golden: the
 * amounts and lines, the unresolved components, the payslip fields and the
 * warning logged for a refused override.
 *
 * @spec openspec/specs/payroll-cao-components/spec.md#REQ-CCP-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\CaoComponentCalculator;
use OCA\Humaniq\Service\CaoComponentPayService;
use OCA\Humaniq\Service\EmploymentTermsResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

require_once __DIR__ . '/../../fixtures/golden/cao-fold.input.php';

/**
 * Golden test for {@see CaoComponentPayService::foldFor()}.
 *
 * @covers \OCA\Humaniq\Service\CaoComponentPayService
 * @uses   \OCA\Humaniq\Service\CaoComponentCalculator
 * @uses   \OCA\Humaniq\Service\EmploymentTermsResolver
 * @uses   \OCA\Humaniq\Service\CaoComponentTerms
 * @uses   \OCA\Humaniq\Standards\CaoComponents
 */
class CaoComponentFoldGoldenTest extends TestCase {

	/**
	 * Every case folds to the recorded result.
	 *
	 * @return void
	 */
	public function testFoldsMatchTheGolden(): void {
		$golden = json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/golden/cao-fold.json'), true);
		$logger = new class extends AbstractLogger {

			/**
			 * @var list<array{0: mixed, 1: string}>
			 */
			public array $lines = [];

			/**
			 * {@inheritDoc}
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->lines[] = [$level, (string)$message];
			}//end log()

		};
		$service = new CaoComponentPayService(new EmploymentTermsResolver(), new CaoComponentCalculator(), $logger);
		self::assertSame(array_keys($golden), array_keys(caoFoldCases()));
		foreach (caoFoldCases() as $name => $case) {
			$logger->lines = [];
			$fold = $service->foldFor(contract: $case['contract'], basis: $case);
			self::assertSame(
				json_encode($golden[$name], JSON_PRESERVE_ZERO_FRACTION),
				json_encode(['fold' => $fold, 'fields' => $service->payslipFields(fold: $fold), 'log' => $logger->lines], JSON_PRESERVE_ZERO_FRACTION),
				$name
			);
		}
	}//end testFoldsMatchTheGolden()
}//end class
