<?php

/**
 * Unit tests for TrainingCompetenceWriter.
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
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use DateTime;
use OCA\Humaniq\Service\RecordStoreInterface;
use OCA\Humaniq\Service\TrainingCompetenceWriter;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * The stamps on a training record and the competence it grants.
 */
class TrainingCompetenceWriterTest extends TestCase {

	/**
	 * The whole outcome of the stamps and of granting the competence: the
	 * stamps, the result and every write.
	 *
	 * @return void
	 */
	public function testTheWholeStampAndGrantOutcome(): void {
		$done = ['status' => 'gevolgd', 'employeeId' => 'emp-1', 'competenceCode' => 'VCA', 'title' => 'VCA basis', 'completedOn' => '2026-03-01', 'validUntil' => '2031-03-01'];
		$records = [
			'grant-new'          => [$done, []],
			'grant-new-bare'     => [['status' => 'gevolgd', 'employeeId' => ' emp-1 ', 'competenceCode' => ' BHV ', 'plannedOn' => '2026-02-01'], []],
			'not-done'           => [array_merge($done, ['status' => 'gepland']), []],
			'no-employee'        => [array_merge($done, ['employeeId' => ' ']), []],
			'no-code'            => [array_merge($done, ['competenceCode' => null]), []],
			'extend'             => [$done, [['id' => 'c-1', '@self' => [], 'employeeId' => 'emp-1', 'competenceCode' => 'VCA', 'validUntil' => '2029-01-01']]],
			'shorter'            => [$done, [['id' => 'c-1', 'validUntil' => '2032-01-01']]],
			'held-forever'       => [$done, [['id' => 'c-1', 'validUntil' => null]]],
			'open-ended-grant'   => [array_merge($done, ['validUntil' => '']), [['id' => 'c-1', 'validUntil' => '2029-01-01']]],
			'best-of-two'        => [$done, [['id' => 'c-1', 'validUntil' => '2027-01-01'], ['id' => 'c-2', 'validUntil' => '2030-01-01']]],
			'best-is-forever'    => [$done, [['id' => 'c-1', 'validUntil' => '2027-01-01'], ['id' => 'c-2', 'validUntil' => '']]],
		];
		$stampsFor = [
			'done-stamped'   => $done,
			'done-no-date'   => ['status' => 'gevolgd', 'plannedOn' => '2026-02-01', 'validityMonths' => 24],
			'done-today'     => ['status' => 'gevolgd', 'validityMonths' => '6'],
			'done-no-months' => ['status' => 'gevolgd', 'completedOn' => '2026-03-01', 'validityMonths' => 0],
			'done-has-until' => ['status' => 'gevolgd', 'completedOn' => '2026-03-01', 'validityMonths' => 12, 'validUntil' => '2026-12-31'],
			'planned'        => ['status' => 'gepland', 'employeeId' => 'emp-1', 'validityMonths' => 12],
			'admin-given'    => ['status' => 'gepland', 'employeeId' => 'emp-1', 'administrationId' => 'ADM-9'],
			'admin-unknown'  => ['status' => 'gepland', 'employeeId' => 'emp-gone'],
		];
		$got = [];
		foreach ($records as $name => [$record, $held]) {
			$writes = [];
			$got['apply'][$name] = ['result' => $this->writer(held: $held, writes: $writes)->apply($record), 'writes' => $writes];
		}

		foreach ($stampsFor as $name => $record) {
			$writes = [];
			$got['stamps'][$name] = $this->writer(held: [], writes: $writes)->stamps($record);
		}

		self::assertSame((string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/golden/training-competence.json'), json_encode($got, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n");
	}//end testTheWholeStampAndGrantOutcome()

	/**
	 * The writer over the competences held and a record of the writes.
	 *
	 * @param array<int, array<string, mixed>> $held   The competences held.
	 * @param array<int, array<string, mixed>> $writes The writes.
	 *
	 * @return TrainingCompetenceWriter
	 */
	private function writer(array $held, array &$writes): TrainingCompetenceWriter {
		$store = $this->createMock(RecordStoreInterface::class);
		$store->method('findFiltered')->willReturnCallback(
			static function (string $schema, array $filters) use ($held, &$writes): array {
				$writes[] = ['read' => $schema, 'filters' => $filters];
				return $held;
			}
		);
		$store->method('findObjectData')->willReturnCallback(static fn (string $uuid): ?array => ($uuid === 'emp-1' ? ['administrationId' => 'ADM-001'] : null));
		$store->method('save')->willReturnCallback(
			static function (array $payload, string $schema, ?string $uuid=null) use (&$writes): object {
				$writes[] = ['schema' => $schema, 'uuid' => $uuid, 'payload' => $payload];
				$entity = new ObjectEntity();
				$entity->setUuid('new-competence');
				return $entity;
			}
		);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn (): DateTime => new DateTime('2026-09-29T10:00:00+00:00'));

		return new TrainingCompetenceWriter(gateway: $store, time: $time);
	}//end writer()

}//end class
