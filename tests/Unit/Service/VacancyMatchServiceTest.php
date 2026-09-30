<?php

/**
 * The vacancy match score: plain arithmetic over the stated requirements,
 * with every point explained.
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
 * @spec openspec/changes/hiring-candidate-assessment/specs/candidate-assessment/spec.md#REQ-CAS-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Service\VacancyMatchService;
use OCA\Humaniq\Tests\Unit\Support\FakeContainer;
use OCA\Humaniq\Tests\Unit\Support\FakeObjectStore;
use OCA\Humaniq\Tests\Unit\Support\FakeSchemaMapper;
use OCA\Humaniq\Tests\Unit\Support\RegisterSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * Scores applicants and employees against one vacancy.
 */
class VacancyMatchServiceTest extends TestCase {

	private const VACANCY = 'vac-1';

	/**
	 * The fake register.
	 *
	 * @var FakeObjectStore
	 */
	private FakeObjectStore $store;

	/**
	 * A vacancy with every kind of requirement, valid against the real schema.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new FakeObjectStore();
		$vacancy = [
			'title' => 'Payroll adviseur',
			'status' => 'gepubliceerd',
			'minEducationLevel' => 5,
			'minExperienceYears' => 4,
			'requiredCompetences' => ['loonheffing', 'excel-gevorderd'],
			'preferredCompetences' => ['afas'],
			'evaluationCriteria' => ['Payroll knowledge', 'Communication'],
		];
		self::assertSame([], RegisterSchemaValidator::errors('Vacancy', $vacancy));
		$this->store->seed('Vacancy', self::VACANCY, $vacancy);
	}//end setUp()

	/**
	 * An applicant who meets everything scores 100.
	 *
	 * @return void
	 */
	public function testAFullMatchScoresOneHundred(): void {
		$result = VacancyMatchService::score(
			requirements: $this->requirements(),
			educationLevel: 6,
			experienceYears: 5.0,
			competences: ['loonheffing', 'excel-gevorderd', 'afas']
		);

		self::assertSame(100, $result['total']);
		self::assertSame([], $result['missing']);
	}//end testAFullMatchScoresOneHundred()

	/**
	 * A missing required competence costs its share, and is named.
	 *
	 * @return void
	 */
	public function testAMissingRequiredCompetenceCostsItsShare(): void {
		$result = VacancyMatchService::score(
			requirements: $this->requirements(),
			educationLevel: 5,
			experienceYears: 2.0,
			competences: ['loonheffing']
		);

		self::assertSame(25.0, $result['points']['education']);
		self::assertSame(12.5, $result['points']['experience'], 'pro rata below the minimum');
		self::assertSame(20.0, $result['points']['required']);
		self::assertSame(0.0, $result['points']['preferred']);
		self::assertSame(58, $result['total']);
		self::assertContains('excel-gevorderd', $result['missing']);
		self::assertContains('afas', $result['missing']);
	}//end testAMissingRequiredCompetenceCostsItsShare()

	/**
	 * A colleague with one current and one expired competence: only the
	 * current one counts, and unknown education and experience score zero.
	 *
	 * @return void
	 */
	public function testAnEmployeeCountsOnlyCurrentCompetences(): void {
		$this->store->seed('Employee', 'emp-1', ['firstName' => 'Petra', 'lastName' => 'Jansen']);
		$this->store->seed('EmployeeCompetence', 'c-1', ['employeeId' => 'emp-1', 'competenceCode' => 'loonheffing', 'issuedOn' => '2025-01-01', 'validUntil' => '2027-01-01']);
		$this->store->seed('EmployeeCompetence', 'c-2', ['employeeId' => 'emp-1', 'competenceCode' => 'excel-gevorderd', 'issuedOn' => '2020-01-01', 'validUntil' => '2026-01-01']);

		$matches = $this->service()->matchesFor(vacancyId: self::VACANCY, today: '2026-09-30');

		self::assertCount(1, $matches);
		self::assertSame('employee', $matches[0]['kind']);
		self::assertSame('Petra Jansen', $matches[0]['name']);
		self::assertSame(20.0, $matches[0]['requiredPoints'], '20 of 40 competence points');
		self::assertStringContainsString('excel-gevorderd', $matches[0]['missing']);
		self::assertStringContainsString('education unknown', $matches[0]['missing']);
	}//end testAnEmployeeCountsOnlyCurrentCompetences()

	/**
	 * A rejected applicant without talent-pool consent is not proposed; one
	 * with consent and time left is, and the list is ranked.
	 *
	 * @return void
	 */
	public function testARejectedApplicantWithoutConsentIsNotProposed(): void {
		$base = ['vacancyId' => 'other-vacancy', 'email' => 'a@example.org', 'educationLevel' => 6, 'experienceYears' => 5, 'competenceCodes' => ['loonheffing']];
		$this->store->seed('job-application', 'a-1', $base + ['candidateName' => 'Niet in de pool', 'status' => 'afgewezen', 'talentPoolOptIn' => false]);
		$this->store->seed('job-application', 'a-2', $base + ['candidateName' => 'In de pool', 'status' => 'afgewezen', 'talentPoolOptIn' => true, 'retentionExpiryDate' => '2027-03-01']);
		$this->store->seed('job-application', 'a-3', $base + ['candidateName' => 'Pool verlopen', 'status' => 'afgewezen', 'talentPoolOptIn' => true, 'retentionExpiryDate' => '2026-09-01']);
		$this->store->seed('job-application', 'a-4', ['competenceCodes' => ['loonheffing', 'excel-gevorderd', 'afas']] + $base + ['candidateName' => 'Actief', 'status' => 'screening']);
		$this->store->seed('job-application', 'a-5', $base + ['candidateName' => 'Geen profiel', 'status' => 'nieuw', 'educationLevel' => null, 'experienceYears' => null, 'competenceCodes' => []]);
		foreach (['a-1', 'a-4'] as $id) {
			self::assertSame([], RegisterSchemaValidator::errors('job-application', $this->store->state->objects['job-application'][$id]));
		}

		$names = array_column($this->service()->matchesFor(vacancyId: self::VACANCY, today: '2026-09-30'), 'name');

		self::assertSame(['Actief', 'In de pool'], $names);
	}//end testARejectedApplicantWithoutConsentIsNotProposed()

	/**
	 * The requirements as the service reads them from the vacancy.
	 *
	 * @return array<string, mixed>
	 */
	private function requirements(): array {
		return VacancyMatchService::requirementsOf($this->store->state->objects['Vacancy'][self::VACANCY]);
	}//end requirements()

	/**
	 * The service on the fake register.
	 *
	 * @return VacancyMatchService
	 */
	private function service(): VacancyMatchService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		return new VacancyMatchService(
			gateway: new HoursRegisterGateway(
				container: new FakeContainer([
					'OCA\OpenRegister\Service\ObjectService' => $this->store,
					'OCA\OpenRegister\Db\SchemaMapper' => new FakeSchemaMapper(),
				]),
				settingsService: $settings,
				orgResolution: new OrgResolutionService()
			)
		);
	}//end service()

}//end class
