<?php

/**
 * TimeActivityCatalog unit tests
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
 * @spec openspec/changes/time-non-project-activities/specs/time-activities/spec.md#requirement-non-project-work-shall-be-bookable-as-an-activity-req-tac-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Listener\HoursWriteRefusedException;
use OCA\Humaniq\Service\EntryKind;
use OCA\Humaniq\Service\RecordStoreInterface;
use OCA\Humaniq\Service\TimeActivityCatalog;
use PHPUnit\Framework\TestCase;

/**
 * The activity kinds and how an entry is classified.
 */
class TimeActivityCatalogTest extends TestCase {

	/**
	 * Every activity with a code and a known kind is listed; the rest are not.
	 *
	 * @return void
	 */
	public function testKindsByCodeSkipsActivitiesWithoutACodeOrAKnownKind(): void {
		$store = $this->createMock(RecordStoreInterface::class);
		$store->expects(self::once())->method('loadAll')->with('TimeActivity')->willReturn([
			['code' => 'opleiding', 'kind' => 'non-project'],
			['code' => ' verlof ', 'kind' => 'absence'],
			['code' => 'project', 'kind' => 'project'],
			['code' => '', 'kind' => 'absence'],
			['code' => 'feest', 'kind' => 'party'],
		]);

		self::assertSame(
			['opleiding' => 'non-project', 'verlof' => 'absence', 'project' => 'project'],
			(new TimeActivityCatalog($store))->kindsByCode()
		);
	}//end testKindsByCodeSkipsActivitiesWithoutACodeOrAKnownKind()

	/**
	 * One activity's kind is looked up by its code.
	 *
	 * @return void
	 */
	public function testKindOf(): void {
		$store = $this->createMock(RecordStoreInterface::class);
		$store->method('findFiltered')->willReturnCallback(
			static fn (string $schema, array $filters): array => match ($filters['code']) {
				'ziekte' => [['code' => 'ziekte', 'kind' => 'absence']],
				'oud' => [['code' => 'oud', 'kind' => 'retired']],
				default => [],
			}
		);
		$catalog = new TimeActivityCatalog($store);

		self::assertSame('absence', $catalog->kindOf(' ziekte '));
		self::assertNull($catalog->kindOf('oud'), 'an unknown kind is no kind');
		self::assertNull($catalog->kindOf('onbekend'));
		self::assertNull($catalog->kindOf(''));
	}//end testKindOf()

	/**
	 * An entry counts as its activity's kind, else by whether it names a project.
	 *
	 * @return void
	 */
	public function testAnEntryCountsAsItsActivityElseByItsProject(): void {
		$kinds = ['opleiding' => 'non-project', 'project' => 'project'];

		self::assertSame('non-project', (new EntryKind($kinds))->kindOf(['activityCode' => 'opleiding', 'projectId' => 'P-1']));
		self::assertSame('project', (new EntryKind($kinds))->kindOf(['activityCode' => 'project']));
		self::assertSame('project', (new EntryKind($kinds))->kindOf(['projectId' => 'P-1']));
		self::assertSame('project', (new EntryKind($kinds))->kindOf(['activityCode' => 'weg', 'projectId' => 'P-1']), 'an unknown activity falls back to the project');
		self::assertSame('non-project', (new EntryKind($kinds))->kindOf(['projectId' => '  ']));
	}//end testAnEntryCountsAsItsActivityElseByItsProject()

	/**
	 * A booking on an absence activity is refused; any other booking passes.
	 *
	 * @return void
	 */
	public function testOnlyAbsenceActivitiesAreRefused(): void {
		$store = $this->createMock(RecordStoreInterface::class);
		$store->method('findFiltered')->willReturnCallback(
			static fn (string $schema, array $filters): array => [['code' => $filters['code'], 'kind' => ($filters['code'] === 'verlof' ? 'absence' : 'non-project')]]
		);
		$catalog = new TimeActivityCatalog($store);

		$catalog->assertBookable(['activityCode' => 'opleiding']);
		$catalog->assertBookable(['projectId' => 'P-1']);

		$this->expectException(HoursWriteRefusedException::class);
		$this->expectExceptionMessage('verlofaanvraag of ziekmelding');
		$catalog->assertBookable(['activityCode' => 'verlof']);
	}//end testOnlyAbsenceActivitiesAreRefused()

}//end class
