<?php

/**
 * Unit tests for RightToWorkGuard (people-dossier-completeness D5).
 *
 * The guard runs with the real RightToWorkService; only the register read is
 * a double, answering rows shaped like the RightToWorkCheck schema.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Lifecycle
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
 * @spec openspec/specs/dossier-completeness/spec.md#REQ-DCP-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Lifecycle;

use OCA\Humaniq\Lifecycle\RightToWorkGuard;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\RightToWorkService;
use OCA\Humaniq\Tests\Unit\Support\RegisterSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the right-to-work hard stop.
 *
 * @spec openspec/specs/dossier-completeness/spec.md#REQ-DCP-004
 */
class RightToWorkGuardTest extends TestCase {

	private const EMPLOYEE = '5b0c8f0e-2d1b-4c55-9d4e-1a2b3c4d5e6f';

	/**
	 * No check at all refuses.
	 *
	 * @return void
	 */
	public function testNoCheckRefuses(): void {
		$result = $this->guard([])->check($this->onboarding(), 'gereed_melden', 'hr');

		$this->assertFalse($result->isAllowed());
		$this->assertStringContainsString('right-to-work', (string)$result->getMessage());
	}//end testNoCheckRefuses()

	/**
	 * A failed check refuses with its reason.
	 *
	 * @return void
	 */
	public function testAFailedCheckRefusesWithItsReason(): void {
		$failed = $this->check('mislukt', 'SYR', 'Arbeid niet toegestaan.', '2026-09-20');
		$failed['reason'] = 'No permission to work: the residence document does not allow work.';
		$result = $this->guard([$failed])->check($this->onboarding(), 'gereed_melden', 'hr');

		$this->assertFalse($result->isAllowed());
		$this->assertStringContainsString('No permission to work', (string)$result->getMessage());
	}//end testAFailedCheckRefusesWithItsReason()

	/**
	 * A passing check dated before the start date allows both transitions.
	 *
	 * @return void
	 */
	public function testAPassingCheckBeforeTheStartAllows(): void {
		$guard = $this->guard([$this->check('geslaagd', 'NLD', null, '2026-09-20')]);

		$this->assertTrue($guard->check($this->onboarding(), 'gereed_melden', 'hr')->isAllowed());
		$this->assertTrue($guard->check($this->onboarding(), 'starten', 'hr')->isAllowed());
	}//end testAPassingCheckBeforeTheStartAllows()

	/**
	 * A passing check dated after the start date refuses.
	 *
	 * @return void
	 */
	public function testAPassingCheckAfterTheStartRefuses(): void {
		$result = $this->guard([$this->check('geslaagd', 'NLD', null, '2026-10-02')])->check($this->onboarding(), 'starten', 'hr');

		$this->assertFalse($result->isAllowed());
	}//end testAPassingCheckAfterTheStartRefuses()

	/**
	 * The newest check decides: a newer failure is not lifted by an older pass, a newer pass lifts an older failure.
	 *
	 * @return void
	 */
	public function testTheNewestCheckDecides(): void {
		$pass = $this->check('geslaagd', 'NLD', null, '2026-09-10');
		$fail = $this->check('mislukt', 'SYR', 'Arbeid niet toegestaan.', '2026-09-20');
		$this->assertFalse($this->guard([$pass, $fail])->check($this->onboarding(), 'gereed_melden', 'hr')->isAllowed());

		$newPass = $this->check('geslaagd', 'SYR', 'Arbeid vrij toegestaan.', '2026-09-25');
		$this->assertTrue($this->guard([$fail, $newPass])->check($this->onboarding(), 'gereed_melden', 'hr')->isAllowed());
	}//end testTheNewestCheckDecides()

	/**
	 * A check stored as passed whose own facts fail the rule refuses: a failure cannot be overridden by hand.
	 *
	 * @return void
	 */
	public function testAHandSetPassThatTheRuleRefusesIsRefused(): void {
		$forged = $this->check('geslaagd', 'SYR', 'Arbeid niet toegestaan.', '2026-09-20');
		$result = $this->guard([$forged])->check($this->onboarding(), 'gereed_melden', 'hr');

		$this->assertFalse($result->isAllowed());
	}//end testAHandSetPassThatTheRuleRefusesIsRefused()

	/**
	 * The check rows are payloads the register accepts.
	 *
	 * @return void
	 */
	public function testTheCheckRowIsAValidRegisterPayload(): void {
		$row = $this->check('geslaagd', 'NLD', null, '2026-09-20');
		unset($row['id']);

		$this->assertSame([], RegisterSchemaValidator::errors('RightToWorkCheck', array_filter($row, static fn ($v): bool => $v !== null)));
	}//end testTheCheckRowIsAValidRegisterPayload()

	/**
	 * The onboarding case, starting 2026-10-01.
	 *
	 * @return array<string, mixed>
	 */
	private function onboarding(): array {
		return ['id' => 'onb-1', 'employeeId' => self::EMPLOYEE, 'startDate' => '2026-10-01', 'status' => 'gegevens_gevalideerd'];
	}//end onboarding()

	/**
	 * One check row.
	 *
	 * @param string $result geslaagd or mislukt.
	 * @param string $nationality The nationality.
	 * @param string|null $endorsement The endorsement.
	 * @param string $checkedOn The check date.
	 *
	 * @return array<string, mixed>
	 */
	private function check(string $result, string $nationality, ?string $endorsement, string $checkedOn): array {
		return [
			'id' => 'rtw-' . $checkedOn,
			'employeeId' => self::EMPLOYEE,
			'documentType' => ($endorsement === null) ? 'paspoort' : 'verblijfsdocument',
			'nationality' => $nationality,
			'documentExpiry' => '2030-01-01',
			'endorsement' => $endorsement,
			'method' => 'handmatig',
			'result' => $result,
			'reason' => '',
			'checkedBy' => 'hr',
			'checkedOn' => $checkedOn,
		];
	}//end check()

	/**
	 * The guard over a register that answers these checks.
	 *
	 * @param array<int, array<string, mixed>> $checks The employee's checks.
	 *
	 * @return RightToWorkGuard
	 */
	private function guard(array $checks): RightToWorkGuard {
		$gateway = $this->createMock(HoursRegisterGateway::class);
		$gateway->method('findFiltered')->willReturnCallback(
			static fn (string $schema, array $filters): array => ($schema === 'RightToWorkCheck' && ($filters['employeeId'] ?? '') === self::EMPLOYEE) ? $checks : []
		);

		return new RightToWorkGuard($gateway, new RightToWorkService());
	}//end guard()

}//end class
