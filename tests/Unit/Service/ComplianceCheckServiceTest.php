<?php

/**
 * Unit tests for ComplianceCheckService.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/hrm-rule-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\ComplianceCheckService;
use OCA\Humaniq\Service\RecordStoreInterface;
use OCA\Humaniq\Service\RuleAuditService;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Coverage beside the findings, and only the caller's readable records (REQ-RULE-010).
 *
 * @spec openspec/specs/hrm-rule-engine/spec.md#requirement-hr-shall-see-the-audits-violations-and-coverage-on-a-page-req-rule-010
 */
#[CoversClass(ComplianceCheckService::class)]
final class ComplianceCheckServiceTest extends TestCase {

	/**
	 * A violation row as the audit gives it.
	 *
	 * @param string $schema   The schema.
	 * @param string $id       The record.
	 * @param string $ruleId   The rule.
	 * @param string $severity The severity.
	 *
	 * @return array<string, string>
	 */
	private static function violation(string $schema, string $id, string $ruleId, string $severity): array {
		return ['schema' => $schema, 'objectId' => $id, 'ruleId' => $ruleId, 'severity' => $severity, 'source' => 'CAO', 'statement' => 'Statement of ' . $ruleId];
	}//end violation()

	/**
	 * A service over the given audit findings and the ids the caller may read per schema.
	 *
	 * @param list<array<string, string>> $violations The audit's violations.
	 * @param array<string, list<string>> $readable   Readable ids per schema; a schema mapped to null throws.
	 *
	 * @return ComplianceCheckService
	 */
	private function service(array $violations, array $readable): ComplianceCheckService {
		$audit = $this->createMock(RuleAuditService::class);
		$audit->expects(self::once())->method('findings')->with(['jurisdiction' => 'NL'])->willReturn(
			[
				'report' => ['catalogueVersion' => '2026.3', 'corpusTotal' => 120, 'machineCheckable' => 90, 'enforceableRules' => 72, 'coveragePct' => 80.0],
				'violations' => $violations,
			]
		);

		$store = $this->createMock(RecordStoreInterface::class);
		$store->method('findFiltered')->willReturnCallback(
			static function (string $schema, array $filters, bool $asCaller) use ($readable): array {
				self::assertSame([], $filters);
				self::assertTrue($asCaller, 'Readability is decided by a caller-scoped read.');
				if (array_key_exists($schema, $readable) === true && $readable[$schema] === null) {
					throw new RuntimeException('read refused');
				}

				return array_map(static fn (string $id): array => ['id' => $id], ($readable[$schema] ?? []));
			}
		);

		return new ComplianceCheckService($audit, $store);
	}//end service()

	/**
	 * Scenario: HR finds a contract below the CAO minimum, and coverage is shown beside it.
	 *
	 * @return void
	 */
	public function testHrSeesTheMandatoryContractViolationAndTheCoverage(): void {
		$result = $this->service(
			[
				self::violation('Employee', 'emp-1', 'nl-dossier-compleet', 'recommended'),
				self::violation('EmploymentContract', 'ct-1', 'nl-cao-minimumloon-schaal', 'mandatory'),
			],
			['EmploymentContract' => ['ct-1'], 'Employee' => ['emp-1']]
		)->run();

		self::assertSame(
			['id' => 'ct-1', 'schema' => 'EmploymentContract', 'ruleId' => 'nl-cao-minimumloon-schaal', 'severity' => 'mandatory', 'statement' => 'Statement of nl-cao-minimumloon-schaal', 'source' => 'CAO'],
			$result['violations'][0],
			'Mandatory violations come first and name the record they open.'
		);
		self::assertSame(['catalogueVersion' => '2026.3', 'corpusTotal' => 120, 'catalogued' => 90, 'enforced' => 72, 'notEnforced' => 18, 'coveragePct' => 80.0], $result['coverage']);
		self::assertSame(['total' => 2, 'mandatory' => 1, 'conditional' => 0, 'recommended' => 1], $result['counts']);
	}//end testHrSeesTheMandatoryContractViolationAndTheCoverage()

	/**
	 * Scenario: a manager sees only their own people; a failed read hides that schema.
	 *
	 * @return void
	 */
	public function testAManagerSeesOnlyRecordsTheyMayRead(): void {
		$result = $this->service(
			[
				self::violation('EmploymentContract', 'ct-own', 'nl-cao-minimumloon-schaal', 'mandatory'),
				self::violation('EmploymentContract', 'ct-other', 'nl-cao-minimumloon-schaal', 'mandatory'),
				self::violation('Payslip', 'ps-1', 'nl-netpay-iban-present', 'mandatory'),
				self::violation('Employee', '', 'nl-dossier-compleet', 'recommended'),
			],
			['EmploymentContract' => ['ct-own'], 'Payslip' => null]
		)->run();

		self::assertSame(['ct-own'], array_column($result['violations'], 'id'));
		self::assertSame(1, $result['counts']['total'], 'Counts are of the listed violations only.');
	}//end testAManagerSeesOnlyRecordsTheyMayRead()

	/**
	 * The page's tiles and table share one audit run a minute; each caller is still filtered.
	 *
	 * @return void
	 */
	public function testTheAuditRunsOnceForTheTilesAndTheTable(): void {
		$stored = [];
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(static function (string $key) use (&$stored): mixed {
			return ($stored[$key] ?? null);
		});
		$cache->method('set')->willReturnCallback(static function (string $key, mixed $value, int $ttl) use (&$stored): bool {
			self::assertSame(60, $ttl);
			$stored[$key] = $value;
			return true;
		});
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(true);
		$factory->method('createDistributed')->with('humaniq-compliance-check')->willReturn($cache);

		$audit = $this->createMock(RuleAuditService::class);
		$audit->expects(self::once())->method('findings')->willReturn(['report' => [], 'violations' => [self::violation('Payslip', 'ps-1', 'nl-netpay-iban-present', 'mandatory')]]);
		$store = $this->createMock(RecordStoreInterface::class);
		$store->method('findFiltered')->willReturnOnConsecutiveCalls([['id' => 'ps-1']], []);

		$service = new ComplianceCheckService($audit, $store, $factory);

		self::assertSame(1, $service->run()['counts']['total']);
		self::assertSame(0, $service->run()['counts']['total'], 'A cached audit is still filtered for the caller.');
	}//end testTheAuditRunsOnceForTheTilesAndTheTable()
}//end class
