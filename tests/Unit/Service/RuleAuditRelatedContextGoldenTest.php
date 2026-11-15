<?php

/**
 * Golden test for the cross-type `related` context of RuleAuditService.
 *
 * Feeds one row set per schema through the private buildRelatedContext() and
 * compares the indexes it builds, and the order in which it reads the
 * schemas, with tests/fixtures/golden/rule-audit-related-context.json.
 * Set RECORD_GOLDEN=1 to rewrite that file.
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
 * @spec openspec/specs/mss-team-scope/spec.md#REQ-MSS-005
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\RuleAuditService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Golden test for RuleAuditService::buildRelatedContext().
 *
 * @covers \OCA\Humaniq\Service\RuleAuditService
 * @uses   \OCA\Humaniq\Service\RelatedContextIndexer
 * @uses   \OCA\Humaniq\Support\RegisterSlugLookup
 * @uses   \OCA\Humaniq\Support\KeepsReadableRows
 * @uses   \OCA\Humaniq\Support\ResolvesObjectServiceByClass
 *
 * @spec openspec/specs/mss-team-scope/spec.md#REQ-MSS-005
 */
class RuleAuditRelatedContextGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/golden/rule-audit-related-context.json';

	/**
	 * One row set per schema, covering every branch of every index.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function rows(): array {
		return [
			'PayrollRun' => [
				['id' => 'run-1', 'period' => '2026-01', 'status' => 'approved', 'administrationId' => 'ADM-1'],
				['@self' => ['id' => 'run-2'], 'period' => '2026-02', 'status' => 'posted'],
				['period' => '2026-03', 'status' => 'paid'],
				['id' => 'run-4', 'period' => '2026-04', 'status' => 'draft'],
				['id' => 'run-5', 'period' => '', 'status' => 'approved'],
				['id' => 6, 'period' => '2026-01', 'status' => 'paid'],
			],
			'PensionFiling' => [
				['period' => '2026-01', 'fund' => 'abp', 'administrationId' => 'ADM-1'],
				['period' => '2026-02', 'fund' => 'pfzw', 'administrationId' => 'ADM-1'],
				['period' => '2026-03', 'fund' => 'abp'],
				['period' => '', 'fund' => 'abp', 'administrationId' => 'ADM-2'],
				['period' => '2026-04', 'fund' => 'abp', 'administrationId' => 'ADM-2'],
			],
			'hrAdministration' => [
				['administrationId' => 'ADM-1', 'abpAansluitingsplichtig' => true],
				['administrationId' => 'ADM-2'],
				['abpAansluitingsplichtig' => true],
				['administrationId' => 'ADM-3', 'abpAansluitingsplichtig' => 0],
			],
			'Employee' => [
				[
					'id' => 'emp-1',
					'loonheffingenVerklaringOnFile' => 1,
					'startDate' => '2026-01-01',
					'endDate' => '2026-12-31',
					'nextcloudUserId' => 'alice',
					'administrationId' => 'ADM-1',
					'wntTopfunctionaris' => true,
					'wntUitzonderingReden' => 'overgangsrecht',
				],
				['@self' => ['id' => 'emp-2'], 'wntUitzonderingReden' => 7],
				['startDate' => '2026-05-01'],
				['id' => 'emp-3', 'wntUitzonderingReden' => null],
			],
			'EmploymentContract' => [
				['employeeId' => 'emp-1', 'type' => 'fixed', 'startDate' => '2026-01-01', 'endDate' => '2026-06-30'],
				['employeeId' => 'emp-1', 'type' => 'open', 'startDate' => '2026-07-01'],
				['type' => 'fixed'],
				['employeeId' => 'emp-2'],
			],
			'OrgUnit' => [
				['id' => 'unit-1', 'parentUnitId' => 'unit-0', 'active' => false, 'managerId' => 'emp-1', 'administrationId' => 'ADM-1'],
				['@self' => ['id' => 'unit-2']],
				['parentUnitId' => 'unit-1'],
			],
			'OrgAssignment' => [
				['employeeId' => ' emp-1 ', 'orgUnitId' => 'unit-1', 'endDate' => '2026-12-31'],
				['employeeId' => 'emp-1', 'orgUnitId' => 'unit-2'],
				['employeeId' => '   ', 'orgUnitId' => 'unit-1'],
				['employeeId' => 'emp-2'],
			],
			'LeaveBalance' => [
				['employeeId' => 'emp-1', 'leaveType' => 'wettelijk', 'year' => '2026', 'entitledHours' => '160', 'bovenwettelijkHours' => 40, 'usedHours' => '12.5'],
				['employeeId' => 'emp-1', 'leaveType' => 'bovenwettelijk'],
				['leaveType' => 'wettelijk'],
			],
			'Asset' => [
				['id' => 'asset-1', 'status' => 'issued', 'active' => false, 'category' => 'vehicle', 'listPrice' => 42000, 'fuelType' => 'electric', 'companyCarTaxCategory' => 'B'],
				['@self' => ['id' => 'asset-2']],
				['status' => 'issued'],
			],
			'AssetAssignment' => [
				['id' => 'aa-1', 'assetId' => 'asset-1', 'employeeId' => 'emp-1', 'issuedOn' => '2026-01-01', 'returnedOn' => '2026-06-01', 'employeeContribution' => 25.5],
				['@self' => ['id' => 'aa-2'], 'assetId' => 'asset-2'],
				['assetId' => 'asset-1'],
			],
			'Offboarding' => [
				['employeeId' => 'emp-1', 'lastWorkingDay' => '2026-03-31', 'status' => 'open'],
				['employeeId' => 'emp-1', 'lastWorkingDay' => '2026-05-31'],
				['employeeId' => 'emp-1', 'lastWorkingDay' => '2026-04-30'],
				['employeeId' => 'emp-1', 'lastWorkingDay' => '2026-09-30', 'status' => 'geannuleerd'],
				['employeeId' => ' emp-2 ', 'lastWorkingDay' => ' 2026-02-28 '],
				['employeeId' => 'emp-3', 'lastWorkingDay' => 'not a date'],
				['employeeId' => 'emp-3', 'lastWorkingDay' => '   '],
				['employeeId' => '', 'lastWorkingDay' => '2026-01-31'],
				['lastWorkingDay' => '2026-01-31'],
			],
		];
	}//end rows()

	/**
	 * The related context and the schema reads match the recording.
	 *
	 * @return void
	 */
	public function testTheRelatedContextMatchesTheRecording(): void {
		$reads = new \ArrayObject();
		$objectService = new class($this->rows(), $reads) {

			/**
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Rows keyed by schema name.
			 * @param \ArrayObject<int, string>                        $reads        Schemas read, in order.
			 */
			public function __construct(
				private readonly array $rowsBySchema,
				private readonly \ArrayObject $reads,
			) {

			}//end __construct()

			/**
			 * @param string $register Register slug.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				$this->reads->append('register:' . $register);
				return $this;
			}//end setRegister()

			/**
			 * @param string $schema Schema name.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * @param array<string, mixed> $config Query options.
			 * @param bool                 $_rbac  Whether to apply RBAC.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config = [], bool $_rbac = true): array {
				$this->reads->append($this->schema . ':' . json_encode($config) . ':' . var_export($_rbac, true));
				return $this->rowsBySchema[$this->schema] ?? [];
			}//end findAll()

		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => $id === 'OCA\OpenRegister\Service\ObjectService' ? $objectService : new \stdClass()
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('humaniq');
		$logger = $this->createMock(LoggerInterface::class);

		$service = new RuleAuditService($container, $appConfig, $logger);
		$method = new ReflectionMethod(RuleAuditService::class, 'buildRelatedContext');
		$context = $method->invoke($service);

		$actual = json_encode(
			['context' => $context, 'reads' => $reads->getArrayCopy()],
			JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES
		) . "\n";

		if (getenv('RECORD_GOLDEN') === '1') {
			if (is_dir(dirname(self::GOLDEN)) === false) {
				mkdir(dirname(self::GOLDEN), 0777, true);
			}

			file_put_contents(self::GOLDEN, $actual);
		}

		$this->assertSame(file_get_contents(self::GOLDEN), $actual);
	}//end testTheRelatedContextMatchesTheRecording()

}//end class
