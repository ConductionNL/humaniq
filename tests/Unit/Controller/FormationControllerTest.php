<?php

/**
 * Unit tests for the formation occupancy read of FormationController.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Controller
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
 * @spec openspec/specs/formation-positions/spec.md#REQ-FRM-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Controller\FormationController;
use OCA\Humaniq\Service\AbsencePeriod;
use OCA\Humaniq\Service\FormationAbsenceSources;
use OCA\Humaniq\Service\FormationOccupancyService;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\RbacObjectReader;
use OCA\Humaniq\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Which places are counted for whom, and what the answer carries.
 */
class FormationControllerTest extends TestCase {

	/**
	 * What the occupancy service was asked, in order.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $asked = [];

	/**
	 * The schemas loaded, in order.
	 *
	 * @var list<string>
	 */
	private array $loaded = [];

	/**
	 * The controller; the caller may read the ids listed.
	 *
	 * @param list<string> $readable The ids the caller's RBAC returns.
	 *
	 * @return FormationController
	 */
	private function controller(array $readable): FormationController {
		$gateway = $this->createMock(HoursRegisterGateway::class);
		$gateway->method('findFiltered')->willReturnCallback(function (string $schema, array $filters): array {
			$this->loaded[] = $schema . ':' . (string)($filters['orgUnitId'] ?? '');
			return [['id' => 'fp-1', 'status' => 'actief'], ['id' => 'fp-2'], ['id' => 'fp-3', 'status' => 'opgeheven']];
		});
		$gateway->method('loadAll')->willReturnCallback(function (string $schema): array {
			$this->loaded[] = $schema;
			return [['schema' => $schema]];
		});
		$rbac = $this->createMock(RbacObjectReader::class);
		$rbac->method('findOrNull')->willReturnCallback(static fn (string $id): ?array => (in_array($id, $readable, true) === true ? ['id' => $id, 'read' => true] : null));
		$occupancy = $this->createMock(FormationOccupancyService::class);
		$occupancy->method('occupancy')->willReturnCallback(function (array $places, array $contracts, FormationAbsenceSources $absences, AbsencePeriod $period): array {
			$this->asked[] = ['places' => array_column($places, 'id'), 'contracts' => $contracts, 'leave' => $absences->leaveRequests, 'sick' => $absences->sickCases, 'types' => $absences->netLeaveTypes, 'weeks' => $absences->longTermSickWeeks, 'period' => [$period->start->format('Y-m-d'), $period->end->format('Y-m-d')]];
			return ['fte' => 1.5];
		});
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getFormationLongTermSickWeeks')->willReturn(6);
		$settings->method('getFormationNetFteLeaveTypes')->willReturn(['ouderschapsverlof']);

		return new FormationController($this->createMock(IRequest::class), $gateway, $occupancy, $rbac, $settings);
	}//end controller()

	/**
	 * A bad window is a 400, a unit or place the caller cannot read a 404; a
	 * readable unit counts its places that are not retired.
	 *
	 * @return void
	 */
	public function testTheOccupancyAnswers(): void {
		$bad = $this->controller(['unit-1'])->occupancy(orgUnitId: 'unit-1', from: '2026-06-02', to: '2026-06-01');
		self::assertSame([Http::STATUS_BAD_REQUEST, ['error' => 'from en to moeten geldige datums zijn, met to op of na from.']], [$bad->getStatus(), $bad->getData()]);
		foreach ([['unit-1', ''], ['', ''], ['', 'fp-9']] as [$unit, $place]) {
			$missing = $this->controller(['fp-1'])->occupancy(orgUnitId: $unit, formatieplaatsId: $place, from: '2026-06-01');
			self::assertSame([Http::STATUS_NOT_FOUND, ['error' => 'Eenheid of formatieplaats niet gevonden.']], [$missing->getStatus(), $missing->getData()]);
		}

		self::assertSame([], $this->asked);

		$unit = $this->controller(['unit-1'])->occupancy(orgUnitId: ' unit-1 ', from: '2026-06-01', to: '2026-06-30');
		self::assertSame(['fte' => 1.5, 'from' => '2026-06-01', 'to' => '2026-06-30', 'longTermSickWeeks' => 6], $unit->getData());
		self::assertSame(['places' => ['fp-1', 'fp-2'], 'contracts' => [['schema' => 'EmploymentContract']], 'leave' => [['schema' => 'LeaveRequest']], 'sick' => [['schema' => 'SickLeaveCase']], 'types' => ['ouderschapsverlof'], 'weeks' => 6, 'period' => ['2026-06-01', '2026-06-30']], $this->asked[0]);
		self::assertSame(['Formatieplaats:unit-1', 'EmploymentContract', 'LeaveRequest', 'SickLeaveCase'], $this->loaded);

		$place = $this->controller(['fp-1'])->occupancy(formatieplaatsId: 'fp-1', from: '2026-06-01');
		self::assertSame(['fte' => 1.5, 'from' => '2026-06-01', 'to' => '2026-06-01', 'longTermSickWeeks' => 6], $place->getData());
		self::assertSame(['fp-1'], $this->asked[1]['places']);
	}//end testTheOccupancyAnswers()
}//end class
