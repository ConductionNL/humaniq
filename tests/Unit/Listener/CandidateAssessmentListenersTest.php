<?php

/**
 * The server-side stamps and checks behind candidate evaluations and
 * employee referrals.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Listener
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
 * @spec openspec/changes/hiring-candidate-assessment/specs/candidate-assessment/spec.md#REQ-CAS-001
 * @spec openspec/changes/hiring-candidate-assessment/specs/candidate-assessment/spec.md#REQ-CAS-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Listener;

use OCA\Humaniq\Listener\CandidateEvaluationStampListener;
use OCA\Humaniq\Listener\ReferralListener;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\Humaniq\Service\ReferralService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Tests\Unit\Support\FakeContainer;
use OCA\Humaniq\Tests\Unit\Support\FakeObjectStore;
use OCA\Humaniq\Tests\Unit\Support\FakeSchemaMapper;
use OCA\Humaniq\Tests\Unit\Support\RegisterSchemaValidator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Evaluator stamp, referral checks, the application a referral creates and
 * the status it follows.
 */
class CandidateAssessmentListenersTest extends TestCase {

	/**
	 * The fake register.
	 *
	 * @var FakeObjectStore
	 */
	private FakeObjectStore $store;

	/**
	 * Seed a published and a closed vacancy.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new FakeObjectStore();
		$this->store->seed('Vacancy', 'vac-open', ['title' => 'Payroll adviseur', 'status' => 'gepubliceerd', 'administrationId' => 'ADM-001']);
		$this->store->seed('Vacancy', 'vac-closed', ['title' => 'Controller', 'status' => 'gesloten', 'administrationId' => 'ADM-001']);
	}//end setUp()

	/**
	 * The evaluator is whoever is signed in, whatever the form sent.
	 *
	 * @return void
	 */
	public function testAnEvaluationIsStampedWithTheSignedInUser(): void {
		$evaluation = ['applicationId' => '5d1d6c1e-0000-4000-8000-000000000001', 'evaluatorUserId' => 'someone.else', 'recommendation' => 'ja', 'scores' => [['criterion' => 'Payroll knowledge', 'score' => 4, 'note' => 'Kent de loonheffing goed.']]];
		$event = new ObjectCreatingEvent($this->entity('CandidateEvaluation', $evaluation));

		$this->evaluationListener('manager.one')->handle($event);

		$stamped = $event->getModifiedData();
		self::assertSame('manager.one', $stamped['evaluatorUserId']);
		self::assertArrayHasKey('submittedAt', $stamped);
		self::assertSame([], RegisterSchemaValidator::errors('CandidateEvaluation', array_merge($evaluation, $stamped)));
	}//end testAnEvaluationIsStampedWithTheSignedInUser()

	/**
	 * An edit cannot move an evaluation to another evaluator.
	 *
	 * @return void
	 */
	public function testAnEditKeepsTheEvaluator(): void {
		$old = $this->entity('CandidateEvaluation', ['applicationId' => 'a-1', 'evaluatorUserId' => 'manager.one', 'scores' => []]);
		$new = $this->entity('CandidateEvaluation', ['applicationId' => 'a-1', 'evaluatorUserId' => 'hr.user', 'scores' => []]);
		$event = new ObjectUpdatingEvent($new, $old);

		$this->evaluationListener('hr.user')->handle($event);

		self::assertSame('manager.one', $event->getModifiedData()['evaluatorUserId']);
	}//end testAnEditKeepsTheEvaluator()

	/**
	 * A score outside 1 to 5 is refused by the schema.
	 *
	 * @return void
	 */
	public function testAScoreIsOneToFive(): void {
		$evaluation = ['applicationId' => '5d1d6c1e-0000-4000-8000-000000000001', 'scores' => [['criterion' => 'Communication', 'score' => 6]]];
		self::assertNotSame([], RegisterSchemaValidator::errors('CandidateEvaluation', $evaluation));
		$fragment = RegisterSchemaValidator::schema('CandidateEvaluation');
		self::assertSame('CASCADE', $fragment['properties']['applicationId']['onDelete'], 'evaluations go with their application');
	}//end testAScoreIsOneToFive()

	/**
	 * A referral without the candidate's agreement is refused, and says why.
	 *
	 * @return void
	 */
	public function testAReferralWithoutConsentIsRefused(): void {
		$event = new ObjectCreatingEvent($this->entity('Referral', $this->referral(['candidateConsented' => false])));

		$this->referralListener('employee.one')->handle($event);

		self::assertStringContainsString('agree', (string)($event->getErrors()['message'] ?? ''));
		self::assertTrue($event->isPropagationStopped());
	}//end testAReferralWithoutConsentIsRefused()

	/**
	 * A closed vacancy takes no referrals.
	 *
	 * @return void
	 */
	public function testAClosedVacancyTakesNoReferral(): void {
		$event = new ObjectCreatingEvent($this->entity('Referral', $this->referral(['vacancyId' => 'vac-closed'])));

		$this->referralListener('employee.one')->handle($event);

		self::assertStringContainsString('published', (string)($event->getErrors()['message'] ?? ''));
	}//end testAClosedVacancyTakesNoReferral()

	/**
	 * A referral is stamped with its referrer and creates the application
	 * HR works with, at nieuw, with source referral.
	 *
	 * @return void
	 */
	public function testAReferralCreatesTheApplication(): void {
		$referral = $this->referral(['referrerUserId' => 'forged']);
		$event = new ObjectCreatingEvent($this->entity('Referral', $referral));
		$this->referralListener('employee.one')->handle($event);
		self::assertSame([], $event->getErrors());
		$stamped = array_merge($referral, $event->getModifiedData());
		self::assertSame('employee.one', $stamped['referrerUserId']);
		self::assertSame('Payroll adviseur', $stamped['vacancyTitle']);
		self::assertSame([], RegisterSchemaValidator::errors('Referral', $stamped));

		$this->store->seed('Referral', 'ref-1', $stamped);
		$this->referralListener('employee.one')->handle(new ObjectCreatedEvent($this->entity('Referral', $stamped + ['id' => 'ref-1'], 'ref-1')));

		$applications = array_values($this->store->state->objects['job-application'] ?? []);
		self::assertCount(1, $applications);
		$application = $applications[0];
		self::assertSame('nieuw', $application['status']);
		self::assertSame('referral', $application['source']);
		self::assertSame('employee.one', $application['referredByUserId']);
		self::assertSame('Ahmed Yilmaz', $application['candidateName']);
		$payload = $application;
		unset($payload['id']);
		self::assertSame([], RegisterSchemaValidator::errors('job-application', $payload));
		self::assertSame($application['id'], $this->store->state->objects['Referral']['ref-1']['applicationId']);
	}//end testAReferralCreatesTheApplication()

	/**
	 * The referral follows the status HR gives the application.
	 *
	 * @return void
	 */
	public function testTheReferralFollowsTheApplicationStatus(): void {
		$this->store->seed('Referral', 'ref-1', $this->referral(['referrerUserId' => 'employee.one', 'applicationId' => 'app-1', 'status' => 'nieuw']));
		$old = $this->entity('job-application', ['status' => 'nieuw', 'referredByUserId' => 'employee.one'], 'app-1');
		$new = $this->entity('job-application', ['status' => 'gesprek', 'referredByUserId' => 'employee.one'], 'app-1');

		$this->referralListener('hr.user')->handle(new ObjectUpdatedEvent($new, $old));

		self::assertSame('gesprek', $this->store->state->objects['Referral']['ref-1']['status']);
	}//end testTheReferralFollowsTheApplicationStatus()

	/**
	 * A referral payload.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 *
	 * @return array<string, mixed>
	 */
	private function referral(array $overrides=[]): array {
		return array_merge(
			[
				'vacancyId' => 'vac-open',
				'candidateName' => 'Ahmed Yilmaz',
				'email' => 'ahmed@example.org',
				'phone' => '0612345678',
				'motivation' => 'Oud-collega, sterk in loonheffing.',
				'candidateConsented' => true,
			],
			$overrides
		);
	}//end referral()

	/**
	 * An entity of one schema.
	 *
	 * @param string               $schema The schema slug.
	 * @param array<string, mixed> $data   The payload.
	 * @param string               $uuid   The uuid.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $schema, array $data, string $uuid='new-1'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setSchema($schema);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * A session for one user.
	 *
	 * @param string $uid The user id.
	 *
	 * @return IUserSession
	 */
	private function session(string $uid): IUserSession {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		return $session;
	}//end session()

	/**
	 * The gateway on the fake register.
	 *
	 * @return HoursRegisterGateway
	 */
	private function gateway(): HoursRegisterGateway {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		return new HoursRegisterGateway(
			container: new FakeContainer([
				'OCA\OpenRegister\Service\ObjectService' => $this->store,
				'OCA\OpenRegister\Db\SchemaMapper' => new FakeSchemaMapper(),
			]),
			settingsService: $settings,
			orgResolution: new OrgResolutionService()
		);
	}//end gateway()

	/**
	 * The evaluation listener.
	 *
	 * @param string $uid The signed-in user.
	 *
	 * @return CandidateEvaluationStampListener
	 */
	private function evaluationListener(string $uid): CandidateEvaluationStampListener {
		return new CandidateEvaluationStampListener(gateway: $this->gateway(), userSession: $this->session($uid));
	}//end evaluationListener()

	/**
	 * The referral listener.
	 *
	 * @param string $uid The signed-in user.
	 *
	 * @return ReferralListener
	 */
	private function referralListener(string $uid): ReferralListener {
		$gateway = $this->gateway();
		return new ReferralListener(
			referrals: new ReferralService(gateway: $gateway),
			gateway: $gateway,
			userSession: $this->session($uid),
			marker: new InternalWriteMarker(),
			logger: new NullLogger()
		);
	}//end referralListener()

}//end class
