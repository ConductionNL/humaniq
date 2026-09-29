<?php

/**
 * Unit tests for ManagerDeputies (self-service-approvals-inbox D2): who stands in for whom, and which deputy records are refused. Real gateway over the in-memory store; records checked against the ManagerDeputy fragment.
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
 * @spec openspec/specs/approvals-inbox/spec.md#REQ-API-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Tests\Unit\Support\ApprovalsFixture;
use OCA\Humaniq\Tests\Unit\Support\RegisterSchemaValidator;
use PHPUnit\Framework\TestCase;

class ManagerDeputiesTest extends TestCase {

	use ApprovalsFixture;

	protected function setUp(): void {
		parent::setUp();
		$this->seedTeam();
	}//end setUp()

	public function testADeputyStandsInOnlyDuringTheirPeriod(): void {
		$deputies = $this->deputies();

		self::assertSame([], $deputies->activeDeputiesOf('mila', '2026-07-13'));
		self::assertSame(['dirk'], $deputies->activeDeputiesOf('mila', '2026-07-14'));
		self::assertSame(['dirk'], $deputies->activeDeputiesOf('mila', '2026-08-01'));
		self::assertSame([], $deputies->activeDeputiesOf('mila', '2026-08-02'));
		self::assertSame(['mila'], $deputies->managersCoveredBy('dirk', '2026-07-20'));
		self::assertSame([], $deputies->managersCoveredBy('dirk', '2026-08-02'));
	}//end testADeputyStandsInOnlyDuringTheirPeriod()

	public function testAManagerCannotBeTheirOwnDeputy(): void {
		self::assertSame('A manager cannot be their own deputy.', $this->deputies()->refusal(['managerUserId' => 'mila', 'deputyUserId' => 'mila', 'from' => '2026-07-14', 'until' => '2026-08-01']));
	}//end testAManagerCannotBeTheirOwnDeputy()

	public function testALastDayBeforeTheFirstIsRefused(): void {
		self::assertSame('The last day comes before the first day.', $this->deputies()->refusal(['managerUserId' => 'mila', 'deputyUserId' => 'dirk', 'from' => '2026-08-01', 'until' => '2026-07-14']));
	}//end testALastDayBeforeTheFirstIsRefused()

	public function testAValidRecordPassesAndFitsTheSchema(): void {
		$record = ['managerUserId' => 'mila', 'deputyUserId' => 'dirk', 'from' => '2026-07-14', 'until' => '2026-08-01', 'note' => 'Zomervakantie'];

		self::assertNull($this->deputies()->refusal($record));
		self::assertSame([], RegisterSchemaValidator::errors('ManagerDeputy', $record));
	}//end testAValidRecordPassesAndFitsTheSchema()

	public function testOnlyTheManagerOrHrMayNameADeputy(): void {
		$record = ['managerUserId' => 'mila', 'deputyUserId' => 'dirk', 'from' => '2026-07-14', 'until' => '2026-08-01'];
		$deputies = $this->deputies();

		self::assertNull($deputies->authorityRefusal('mila', $record, []));
		self::assertNull($deputies->authorityRefusal('hr', $record, []));
		self::assertNull($deputies->authorityRefusal('', $record, []));
		self::assertNotNull($deputies->authorityRefusal('dirk', $record, []));
		self::assertNotNull($deputies->authorityRefusal('dirk', array_merge($record, ['managerUserId' => 'dirk']), $record));
	}//end testOnlyTheManagerOrHrMayNameADeputy()

	public function testTheApproversAreTheManagerAndTheActiveDeputyNeverTheRequester(): void {
		$deputies = $this->deputies();
		$stamped = ['employeeId' => 'emp-sam', 'userId' => 'sam', 'managerUserId' => 'mila'];
		$unstamped = ['employeeId' => 'emp-noor'];

		self::assertSame(['mila', 'dirk'], $deputies->approversOf($stamped, '2026-07-20'));
		self::assertSame(['mila'], $deputies->approversOf($stamped, '2026-08-02'));
		self::assertSame(['mila', 'dirk'], $deputies->approversOf($unstamped, '2026-07-20'));
		self::assertSame(['dirk'], $deputies->approversOf(['employeeId' => 'emp-mila', 'userId' => 'mila', 'managerUserId' => 'mila'], '2026-07-20'));
	}//end testTheApproversAreTheManagerAndTheActiveDeputyNeverTheRequester()

}//end class
