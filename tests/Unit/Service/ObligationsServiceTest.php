<?php

/**
 * Unit tests for ObligationsService.
 *
 * Pins the Obligations merge (REQ-DSI-008: due-and-done exclusion,
 * cross-source sort) and its best-effort rule badge (REQ-DSI-009), scoped to
 * exactly the three obligation schemas — never a full-corpus
 * `RuleAuditService::audit()`-shaped walk.
 *
 * Drives the service through a fake ObjectService double (a fake
 * collaborator, not a fake of the logic under test) — the
 * AdministrationServiceTest / RuleAuditServiceTest precedent. The
 * mandatory-violation badge goes through the REAL `RuleAuditService` (built
 * with the same fake ObjectService, never called since
 * `mandatoryViolationIds()` needs no register access) so the badge
 * assertion exercises the real `RuleEngine::evaluate()` corpus, not a mock
 * standing in for it.
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
 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md#REQ-DSI-008
 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md#REQ-DSI-009
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Humaniq\Service\ObligationsService;
use OCA\Humaniq\Service\RuleAuditService;
use OCA\Humaniq\Service\SettingsService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for ObligationsService.
 *
 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md
 */
class ObligationsServiceTest extends TestCase {

	/**
	 * REQ-DSI-008 scenario: a due-and-done WVP milestone (`planVanAanpakDue`
	 * set, `planVanAanpakDone` set) does not appear.
	 *
	 * @return void
	 */
	public function testDueAndDoneMilestoneIsExcluded(): void {
		$today = new DateTimeImmutable('today');
		$service = $this->buildService([
			'SickLeaveCase' => [
				[
					'employeeId' => 'emp-1',
					'administrationId' => 'ADM-001',
					'planVanAanpakDue' => $today->modify('+5 days')->format('Y-m-d'),
					'planVanAanpakDone' => $today->format('Y-m-d'),
				],
			],
		]);

		$obligations = $service->getObligations('ADM-001');

		$this->assertSame([], $obligations);
	}//end testDueAndDoneMilestoneIsExcluded()

	/**
	 * REQ-DSI-008 scenario: rows from all three sources (a due-and-not-done
	 * WVP milestone, an expiring contract, an expiring BHV certificate) sort
	 * together by `dueDate` ascending, regardless of source type.
	 *
	 * @return void
	 */
	public function testRowsFromAllThreeSourcesSortTogetherByDueDate(): void {
		$today = new DateTimeImmutable('today');
		$service = $this->buildService([
			'SickLeaveCase' => [
				[
					'employeeId' => 'emp-sick',
					'administrationId' => 'ADM-001',
					'planVanAanpakDue' => $today->modify('+30 days')->format('Y-m-d'),
					'planVanAanpakDone' => null,
				],
			],
			'EmploymentContract' => [
				[
					'employeeId' => 'emp-contract',
					'administrationId' => 'ADM-001',
					'type' => 'temporary',
					'endDate' => $today->modify('+10 days')->format('Y-m-d'),
				],
			],
			'BhvCertificering' => [
				[
					'employeeId' => 'emp-bhv',
					'administrationId' => 'ADM-001',
					'certificaatGeldigTot' => $today->modify('+50 days')->format('Y-m-d'),
				],
			],
		]);

		$obligations = $service->getObligations('ADM-001');

		$this->assertCount(3, $obligations);
		$this->assertSame('EmploymentContract', $obligations[0]['type']);
		$this->assertSame('SickLeaveCase', $obligations[1]['type']);
		$this->assertSame('BhvCertificering', $obligations[2]['type']);
	}//end testRowsFromAllThreeSourcesSortTogetherByDueDate()

	/**
	 * A row from a DIFFERENT administration never contributes — the
	 * tenant-isolation half of REQ-DSI-005, exercised at the service layer.
	 *
	 * @return void
	 */
	public function testRowsFromAnotherAdministrationAreExcluded(): void {
		$today = new DateTimeImmutable('today');
		$service = $this->buildService([
			'EmploymentContract' => [
				[
					'employeeId' => 'emp-other',
					'administrationId' => 'ADM-OTHER',
					'type' => 'temporary',
					'endDate' => $today->modify('+10 days')->format('Y-m-d'),
				],
			],
		]);

		$this->assertSame([], $service->getObligations('ADM-001'));
	}//end testRowsFromAnotherAdministrationAreExcluded()

	/**
	 * REQ-DSI-009 scenario: an EmploymentContract row the endpoint already
	 * returns for REQ-DSI-008, whose data trips the mandatory
	 * `nl-aanzegtermijn-bewaking` check, carries a violation badge naming it.
	 *
	 * @return void
	 */
	public function testMandatoryViolationOnAnAlreadyReturnedRowIsBadged(): void {
		$today = new DateTimeImmutable('today');
		// A 7-month fixed-term contract whose aanzeg deadline (endDate - 1
		// month) has already passed, with no aanzegdOn recorded — trips
		// `nl-aanzegtermijn-bewaking` (mandatory) while staying inside the
		// Obligations 60-day expiry window.
		$endDate = $today->modify('+20 days');
		$startDate = $endDate->modify('-7 months');

		$service = $this->buildService([
			'EmploymentContract' => [
				[
					'employeeId' => 'emp-1',
					'administrationId' => 'ADM-001',
					'type' => 'temporary',
					'startDate' => $startDate->format('Y-m-d'),
					'endDate' => $endDate->format('Y-m-d'),
					'aanzegdOn' => null,
				],
			],
		]);

		$obligations = $service->getObligations('ADM-001');

		$this->assertCount(1, $obligations);
		$this->assertContains('nl-aanzegtermijn-bewaking', $obligations[0]['violations']);
	}//end testMandatoryViolationOnAnAlreadyReturnedRowIsBadged()

	/**
	 * A row that trips no mandatory check carries an empty (not missing)
	 * `violations` array — a vacuous pass, never fabricated.
	 *
	 * @return void
	 */
	public function testRowWithNoViolationCarriesAnEmptyViolationsArray(): void {
		$today = new DateTimeImmutable('today');
		$service = $this->buildService([
			'BhvCertificering' => [
				[
					'employeeId' => 'emp-bhv',
					'administrationId' => 'ADM-001',
					'certificaatGeldigTot' => $today->modify('+50 days')->format('Y-m-d'),
				],
			],
		]);

		$obligations = $service->getObligations('ADM-001');

		$this->assertCount(1, $obligations);
		$this->assertSame([], $obligations[0]['violations']);
	}//end testRowWithNoViolationCarriesAnEmptyViolationsArray()

	/**
	 * REQ-DSI-009 scenario: the endpoint's object-loading is limited to the
	 * three obligation schemas — no full-corpus walk. Asserts the SET of
	 * schemas the (fake) ObjectService was asked for, which is the
	 * "objects loaded" instrument this task exists to satisfy, not merely
	 * that the test passes.
	 *
	 * @return void
	 */
	public function testObligationsOnlyLoadsTheThreeObligationSchemas(): void {
		$objectService = $this->fakeObjectService([]);
		$service = $this->buildServiceWithObjectService($objectService);

		$service->getObligations('ADM-001');

		$this->assertSame(
			['SickLeaveCase', 'EmploymentContract', 'BhvCertificering'],
			array_values(array_unique($objectService->schemasQueried))
		);
	}//end testObligationsOnlyLoadsTheThreeObligationSchemas()

	/**
	 * Rows are capped at the OBLIGATIONS_LIMIT (10), nearest-due-date-first.
	 *
	 * @return void
	 */
	public function testRowsAreCappedAtTen(): void {
		$today = new DateTimeImmutable('today');
		$contracts = [];
		for ($i = 1; $i <= 15; $i++) {
			$contracts[] = [
				'employeeId' => 'emp-' . $i,
				'administrationId' => 'ADM-001',
				'type' => 'temporary',
				'endDate' => $today->modify('+' . $i . ' days')->format('Y-m-d'),
			];
		}

		$service = $this->buildService(['EmploymentContract' => $contracts]);

		$obligations = $service->getObligations('ADM-001');

		$this->assertCount(10, $obligations);
		$this->assertSame('emp-1', $obligations[0]['employeeId']);
	}//end testRowsAreCappedAtTen()

	/**
	 * Build an `ObligationsService` backed by a fresh fake ObjectService
	 * pre-loaded with `$rowsBySchema`, and a REAL `RuleAuditService`
	 * (backed by the same fake) for the mandatory-violation badge.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Canned rows, keyed by schema name.
	 *
	 * @return ObligationsService
	 */
	private function buildService(array $rowsBySchema): ObligationsService {
		return $this->buildServiceWithObjectService($this->fakeObjectService($rowsBySchema));
	}//end buildService()

	/**
	 * Build an `ObligationsService` around an already-built fake
	 * ObjectService (so a test can inspect the fake afterwards — e.g. which
	 * schemas were queried).
	 *
	 * @param object $objectService The fake ObjectService.
	 *
	 * @return ObligationsService
	 */
	private function buildServiceWithObjectService(object $objectService): ObligationsService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($objectService);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$settings->method('isOpenRegisterAvailable')->willReturn(true);

		$logger = $this->createMock(LoggerInterface::class);

		// mandatoryViolationIds() calls RuleEngine::evaluate() directly and
		// never touches the register, so a bare mocked IAppConfig is enough
		// to satisfy RuleAuditService's constructor.
		$appConfig = $this->createMock(IAppConfig::class);
		$ruleAuditService = new RuleAuditService($container, $appConfig, $logger);

		return new ObligationsService($container, $settings, $ruleAuditService, $logger);
	}//end buildServiceWithObjectService()

	/**
	 * A fake OpenRegister ObjectService returning canned rows per schema and
	 * recording every schema it was asked for — the AdministrationServiceTest
	 * precedent, extended with a query log for REQ-DSI-009's
	 * no-full-corpus-walk assertion.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Canned rows, keyed by schema name.
	 *
	 * @return object
	 */
	private function fakeObjectService(array $rowsBySchema): object {
		return new class($rowsBySchema) {
			/**
			 * @var array<int, string>
			 */
			public array $schemasQueried = [];

			/**
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Canned rows, keyed by schema name.
			 */
			public function __construct(
				private readonly array $rowsBySchema,
			) {
			}

			/**
			 * @param string $register Ignored; chainable.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				return $this;
			}

			/**
			 * @param string $schema The schema to load on the next findAll().
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->schema = $schema;
				$this->schemasQueried[] = $schema;
				return $this;
			}

			/**
			 * @param array<string, mixed> $options Ignored.
			 *
			 * @return array<int, mixed>
			 */
			public function findAll(array $options): array {
				return ($this->rowsBySchema[$this->schema] ?? []);
			}
		};
	}//end fakeObjectService()

}//end class
