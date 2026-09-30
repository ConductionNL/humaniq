<?php

/**
 * The candidate, new-hire and former-employee audiences on humaniq's portal
 * contribution, checked against the real register schemas.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Portal
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
 * @spec openspec/specs/portal-audiences/spec.md#REQ-PAU-001
 * @spec openspec/specs/portal-audiences/spec.md#REQ-PAU-002
 * @spec openspec/specs/portal-audiences/spec.md#REQ-PAU-003
 * @spec openspec/specs/portal-audiences/spec.md#REQ-PAU-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Portal;

use OCA\Humaniq\Portal\PortalContributionProvider;
use OCA\Humaniq\Tests\Unit\Support\RegisterSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * The three portal audiences for recruiting, preboarding and leavers.
 */
class PortalAudiencesTest extends TestCase {

	/**
	 * Six audiences, and still null for anything else.
	 *
	 * @return void
	 */
	public function testTheSixAudiences(): void {
		$provider = new PortalContributionProvider();

		self::assertSame(['external-employee', 'client', 'manager', 'candidate', 'new-hire', 'former-employee'], $provider->getAudiences());
		self::assertNull($provider->getContribution(['audience' => 'visitor']));
	}//end testTheSixAudiences()

	/**
	 * A visitor sees only published vacancies and applies with a strict whitelist.
	 *
	 * @return void
	 */
	public function testTheCareersSurfaceIsAnonymousAndNarrow(): void {
		$manifest = $this->manifest('candidate');

		self::assertCount(1, $manifest['collections']);
		$vacancies = $manifest['collections'][0];
		self::assertSame('openVacancies', $vacancies['id']);
		self::assertSame('Vacancy', $vacancies['schema']);
		self::assertTrue($vacancies['anonymous']);
		self::assertSame('low', $vacancies['minTrust'], 'portaliq drops the anonymous flag from any entry above low trust');
		self::assertSame(['status' => 'gepubliceerd'], $vacancies['filter']);
		self::assertSame(['title', 'description', 'department', 'closingDate', 'questions'], $vacancies['fields']);
		$this->assertFieldsExist('Vacancy', $vacancies['fields']);

		self::assertCount(1, $manifest['actions']);
		$apply = $manifest['actions'][0];
		self::assertSame('applyToVacancy', $apply['id']);
		self::assertSame('create', $apply['type']);
		self::assertSame('job-application', $apply['schema']);
		self::assertTrue($apply['anonymous']);
		self::assertSame(['vacancyId', 'candidateName', 'email', 'phone', 'motivation', 'talentPoolOptIn', 'answers'], $apply['fields']);
		$this->assertFieldsExist('job-application', $apply['fields']);
		foreach (['status', 'rejectedDate', 'retentionExpiryDate', 'administrationId', 'offerLetterFileId', 'offerSigningRequestId', 'offerSigningStatus'] as $never) {
			self::assertNotContains($never, $apply['fields']);
		}
	}//end testTheCareersSurfaceIsAnonymousAndNarrow()

	/**
	 * What the apply action lets a candidate send is a valid application.
	 *
	 * @return void
	 */
	public function testAPortalApplicationWithAnswersIsValid(): void {
		$payload = [
			'vacancyId' => '5d1d6c1e-0000-4000-8000-000000000001',
			'candidateName' => 'Sanne de Wit',
			'email' => 'sanne@example.org',
			'phone' => '0612345678',
			'motivation' => 'Ik werk graag aan de balie.',
			'talentPoolOptIn' => false,
			'answers' => ['rijbewijs' => 'ja', 'beschikbaarheid' => 'Per 1 november'],
			'status' => 'nieuw',
		];

		self::assertSame([], RegisterSchemaValidator::errors('job-application', $payload));
	}//end testAPortalApplicationWithAnswersIsValid()

	/**
	 * Questions on a vacancy take the fields[] shape the form builder writes.
	 *
	 * @return void
	 */
	public function testAVacancyCarriesItsQuestions(): void {
		$vacancy = [
			'title' => 'Baliemedewerker Burgerzaken',
			'status' => 'gepubliceerd',
			'questions' => [
				['key' => 'rijbewijs', 'type' => 'enum', 'label' => 'Heeft u een rijbewijs B?', 'required' => true, 'options' => ['ja', 'nee']],
				['key' => 'beschikbaarheid', 'type' => 'textarea', 'label' => 'Per wanneer bent u beschikbaar?', 'required' => false],
			],
		];

		self::assertSame([], RegisterSchemaValidator::errors('Vacancy', $vacancy));
		$vacancy['questions'][0]['key'] = '';
		self::assertNotSame([], RegisterSchemaValidator::errors('Vacancy', $vacancy), 'a question without a key cannot hold an answer');
	}//end testAVacancyCarriesItsQuestions()

	/**
	 * A new hire reads their own records and updates only three fields, at substantial trust.
	 *
	 * @return void
	 */
	public function testPreboardingIsScopedAndNarrow(): void {
		$manifest = $this->manifest('new-hire');

		$byId = array_column($manifest['collections'], null, 'id');
		self::assertSame(['myEmployeeRecord', 'myOnboarding'], array_keys($byId));
		self::assertSame(['Employee', 'id', 'employeeId'], [$byId['myEmployeeRecord']['schema'], $byId['myEmployeeRecord']['scopeField'], $byId['myEmployeeRecord']['scopeClaim']]);
		self::assertSame(['Onboarding', 'employeeId', 'employeeId'], [$byId['myOnboarding']['schema'], $byId['myOnboarding']['scopeField'], $byId['myOnboarding']['scopeClaim']]);
		self::assertTrue($byId['myOnboarding']['filesUpload'], 'the papers are uploaded onto the onboarding case');
		$this->assertFieldsExist('Onboarding', $byId['myOnboarding']['fields']);

		self::assertCount(1, $manifest['actions']);
		$update = $manifest['actions'][0];
		self::assertSame(['updateMyDetails', 'update', 'Employee'], [$update['id'], $update['type'], $update['schema']]);
		self::assertSame(['iban', 'tenaamstelling', 'bsn'], $update['fields']);
		self::assertSame('substantial', $update['minTrust']);
		self::assertSame(['id', 'employeeId'], [$update['scopeField'], $update['scopeClaim']]);
		$this->assertFieldsExist('Employee', $update['fields']);
		$this->assertNothingAnonymous($manifest);
	}//end testPreboardingIsScopedAndNarrow()

	/**
	 * A leaver reads their own payslips, annual statements and letters, and writes nothing.
	 *
	 * @return void
	 */
	public function testALeaverReadsTheirOwnPaperworkOnly(): void {
		$manifest = $this->manifest('former-employee');

		$byId = array_column($manifest['collections'], null, 'id');
		self::assertSame(['payslips', 'annualStatements', 'myDocuments'], array_keys($byId));
		self::assertSame(['Payslip', 'Jaaropgaaf', 'HrGeneratedDocument'], array_column($manifest['collections'], 'schema'));
		foreach ($manifest['collections'] as $collection) {
			self::assertSame(['employeeId', 'employeeId'], [$collection['scopeField'], $collection['scopeClaim']]);
		}

		self::assertSame(['status' => 'generated'], $byId['myDocuments']['filter']);
		self::assertSame([], $manifest['actions']);
		$this->assertNothingAnonymous($manifest);
	}//end testALeaverReadsTheirOwnPaperworkOnly()

	/**
	 * The schema descriptions no longer say there is no career page.
	 *
	 * @return void
	 */
	public function testTheSchemasStopSayingThereIsNoCareerPage(): void {
		self::assertStringNotContainsString('no public career page', RegisterSchemaValidator::schema('Vacancy')['description']);
		$lifecycle = RegisterSchemaValidator::schema('job-application')['configuration']['x-openregister-lifecycle'];
		self::assertStringNotContainsString('absent a public career page', $lifecycle['transitions']['afwijzen']['description']);
	}//end testTheSchemasStopSayingThereIsNoCareerPage()

	/**
	 * The manifest for an audience.
	 *
	 * @param string $audience The audience.
	 *
	 * @return array<string, mixed>
	 */
	private function manifest(string $audience): array {
		$manifest = (new PortalContributionProvider())->getContribution(['audience' => $audience]);
		self::assertIsArray($manifest);

		return $manifest;
	}//end manifest()

	/**
	 * Every named field is a property of the real schema.
	 *
	 * @param string $schema The schema key.
	 * @param array<int, string> $fields The fields.
	 *
	 * @return void
	 */
	private function assertFieldsExist(string $schema, array $fields): void {
		// A schema can be declared across fragments (Employee is extended by
		// hr-cost-rate.json), so the properties are merged over all of them.
		$properties = [];
		foreach (glob(dirname(__DIR__, 3) . '/lib/Settings/register.d/*.json') as $file) {
			$fragment = json_decode((string)file_get_contents($file), true);
			$properties = array_merge($properties, array_keys((array)($fragment['components']['schemas'][$schema]['properties'] ?? [])));
		}

		self::assertSame([], array_values(array_diff($fields, $properties)), $schema . ' lacks a field the portal names');
	}//end assertFieldsExist()

	/**
	 * No collection or action of a signed-in audience is anonymous.
	 *
	 * @param array<string, mixed> $manifest The manifest.
	 *
	 * @return void
	 */
	private function assertNothingAnonymous(array $manifest): void {
		foreach (array_merge($manifest['collections'], $manifest['actions']) as $entry) {
			self::assertArrayNotHasKey('anonymous', $entry);
		}
	}//end assertNothingAnonymous()

}//end class
