<?php

/**
 * Unit tests for exit interviews: the case stamp, the fill-in, the reason
 * counts and the 90-day anonymisation, over the real gateway on a fake store.
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
 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Listener\ExitInterviewListener;
use OCA\Humaniq\Service\ExitInterviewService;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Tests\Unit\Support\FakeContainer;
use OCA\Humaniq\Tests\Unit\Support\FakeObjectStore;
use OCA\Humaniq\Tests\Unit\Support\FakeSchemaMapper;
use OCA\Humaniq\Tests\Unit\Support\RegisterSchemaValidator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The exit interview rules of design D1.
 */
class ExitInterviewServiceTest extends TestCase {

	private const EMP = '6b1f7c2e-3d4a-4e5b-8c6d-7e8f9a0b1c2d';
	private const CASE = '7c2f8d3e-4e5b-4f6c-9d7e-8f9a0b1c2d3e';
	private const UNIT = '8d3a9e4f-5f6c-4a7d-8e8f-9a0b1c2d3e4f';

	private FakeObjectStore $store;

	protected function setUp(): void {
		parent::setUp();
		$this->store = new FakeObjectStore();
		$this->store->seed('Employee', self::EMP, ['firstName' => 'Noa', 'lastName' => 'Visser', 'administrationId' => 'ADM-001']);
		$this->store->seed('Offboarding', self::CASE, ['employeeId' => self::EMP, 'lastWorkingDay' => '2026-10-31', 'reason' => 'opzegging-werknemer', 'status' => 'afronding_gepland', 'exitGesprekDone' => null, 'notes' => 'keep me']);
		$this->store->seed('OrgAssignment', 'asg-1', ['employeeId' => self::EMP, 'orgUnitId' => self::UNIT, 'startDate' => '2024-01-01']);
	}//end setUp()

	/**
	 * Recording an interview fills in the leaver and department, and stamps
	 * the case's exit interview date with the whole case saved.
	 *
	 * @return void
	 */
	public function testRecordingAnInterviewStampsTheCase(): void {
		$interview = ['offboardingId' => self::CASE, 'heldOn' => '2026-09-30', 'mainReason' => 'leidinggevende', 'wouldRecommend' => 4];
		$creating = new ObjectCreatingEvent($this->entity($interview));
		$this->listener()->handle($creating);

		$stamps = $creating->getModifiedData();
		self::assertSame(['employeeId' => self::EMP, 'administrationId' => 'ADM-001', 'orgUnitId' => self::UNIT], $stamps);
		self::assertSame([], RegisterSchemaValidator::errors('ExitInterview', array_merge($interview, $stamps)));

		$this->listener()->handle(new ObjectCreatedEvent($this->entity(array_merge($interview, $stamps))));
		$save = end($this->store->state->saves);
		self::assertSame('Offboarding', $save['schema']);
		self::assertSame('2026-09-30', $save['payload']['exitGesprekDone']);
		self::assertSame('keep me', $save['payload']['notes'], 'OpenRegister replaces the object, so the whole case is saved.');
		self::assertSame([], RegisterSchemaValidator::errors('Offboarding', $this->withoutId($save['payload'])));
	}//end testRecordingAnInterviewStampsTheCase()

	/**
	 * Six interviews in the last twelve months, four naming the manager: the
	 * count says 4; one from two years ago does not count.
	 *
	 * @return void
	 */
	public function testTheReasonsAreCountedOverTwelveMonths(): void {
		foreach (['leidinggevende', 'leidinggevende', 'salaris', 'leidinggevende', 'werkdruk', 'leidinggevende'] as $at => $reason) {
			$this->store->seed('ExitInterview', 'ei-' . $at, ['heldOn' => sprintf('2026-%02d-10', ($at + 2)), 'mainReason' => $reason]);
		}

		$this->store->seed('ExitInterview', 'ei-old', ['heldOn' => '2024-06-01', 'mainReason' => 'leidinggevende']);

		$counts = $this->service()->reasonCounts(today: '2026-09-30', months: 12);

		self::assertSame(6, $counts['total']);
		self::assertSame('2025-09-30', $counts['from']);
		self::assertSame(['reason' => 'leidinggevende', 'label' => 'Manager', 'count' => 4], $counts['reasons'][0]);
		self::assertSame(['salaris', 'werkdruk'], array_column(array_slice($counts['reasons'], 1), 'reason'));
	}//end testTheReasonsAreCountedOverTwelveMonths()

	/**
	 * An interview held 120 days ago loses its person link and free text and
	 * keeps the rest; one held 30 days ago and one already anonymised stay.
	 *
	 * @return void
	 */
	public function testAnInterviewOlderThanNinetyDaysIsAnonymised(): void {
		$full = ['offboardingId' => self::CASE, 'employeeId' => self::EMP, 'orgUnitId' => self::UNIT, 'conductedBy' => 'hr-demo', 'mainReason' => 'loopbaan', 'wouldRecommend' => 7, 'wouldReturn' => true, 'whatWorked' => 'De collega\'s', 'whatToImprove' => 'Doorgroei', 'administrationId' => 'ADM-001'];
		$this->store->seed('ExitInterview', 'ei-old', array_merge($full, ['heldOn' => '2026-06-02']));
		$this->store->seed('ExitInterview', 'ei-new', array_merge($full, ['heldOn' => '2026-08-31']));
		$this->store->seed('ExitInterview', 'ei-done', ['heldOn' => '2025-01-01', 'mainReason' => 'salaris', 'anonymisedAt' => '2025-04-02T02:30:00+00:00']);

		$done = $this->service()->anonymiseDue(today: '2026-09-30', now: '2026-09-30T02:30:00+00:00', afterDays: 90);

		self::assertSame(['ei-old'], $done);
		self::assertCount(1, $this->store->state->saves);
		$payload = $this->store->state->saves[0]['payload'];
		foreach (['employeeId', 'offboardingId', 'conductedBy', 'whatWorked', 'whatToImprove'] as $field) {
			self::assertNull($payload[$field], $field . ' is cleared.');
		}

		self::assertSame('loopbaan', $payload['mainReason']);
		self::assertSame(7, $payload['wouldRecommend']);
		self::assertTrue($payload['wouldReturn']);
		self::assertSame(self::UNIT, $payload['orgUnitId']);
		self::assertSame('2026-06-02', $payload['heldOn']);
		self::assertSame('2026-09-30T02:30:00+00:00', $payload['anonymisedAt']);
		self::assertSame([], RegisterSchemaValidator::errors('ExitInterview', $this->withoutId($payload)));
	}//end testAnInterviewOlderThanNinetyDaysIsAnonymised()

	/**
	 * The payload without the id the fake store adds.
	 *
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return array<string, mixed>
	 */
	private function withoutId(array $payload): array {
		unset($payload['id']);
		return $payload;
	}//end withoutId()

	/**
	 * An ExitInterview entity.
	 *
	 * @param array<string, mixed> $data The data.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('ei-new-1');
		$entity->setSchema('ExitInterview');
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * The service over the real gateway.
	 *
	 * @return ExitInterviewService
	 */
	private function service(): ExitInterviewService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		return new ExitInterviewService(
			gateway: new HoursRegisterGateway(
				container: new FakeContainer([
					'OCA\OpenRegister\Service\ObjectService' => $this->store,
					'OCA\OpenRegister\Db\SchemaMapper' => new FakeSchemaMapper(),
				]),
				settingsService: $settings,
				orgResolution: new OrgResolutionService()
			),
			marker: new InternalWriteMarker(),
			l10n: $l10n
		);
	}//end service()

	/**
	 * The listener.
	 *
	 * @return ExitInterviewListener
	 */
	private function listener(): ExitInterviewListener {
		$service = $this->service();
		return new ExitInterviewListener(interviews: $service, gateway: $this->gatewayOf($service), logger: new NullLogger());
	}//end listener()

	/**
	 * A gateway on the same store (resolves the schema slug).
	 *
	 * @param ExitInterviewService $service Unused; the store is shared.
	 *
	 * @return HoursRegisterGateway
	 */
	private function gatewayOf(ExitInterviewService $service): HoursRegisterGateway {
		unset($service);
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
	}//end gatewayOf()

}//end class
