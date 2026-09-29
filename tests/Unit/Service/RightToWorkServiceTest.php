<?php

/**
 * Unit tests for the right-to-work rule (people-dossier-completeness D4).
 *
 * The rule runs on the real EEA table in lib/Standards/reference and on
 * machine-readable zones whose check digits were computed with the ICAO 9303
 * 7-3-1 weighting.
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
 * @spec openspec/changes/people-dossier-completeness/specs/dossier-completeness/spec.md#REQ-DCP-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\RightToWorkService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the right-to-work rule.
 *
 * @spec openspec/changes/people-dossier-completeness/specs/dossier-completeness/spec.md#REQ-DCP-003
 */
class RightToWorkServiceTest extends TestCase {

	private const PASSPORT_LINE_1 = 'P<NLDDE<BRUIJN<<WILLEKE<LISELOTTE<<<<<<<<<<<';

	private const PASSPORT_LINE_2 = 'SPECI20142NLD6503101F3103094<<<<<<<<<<<<<<06';

	private const PERMIT_LINE_1 = 'IRNLDXA12345679<<<<<<<<<<<<<<<';

	private const PERMIT_LINE_2 = '9001011M2905017SYR<<<<<<<<<<<2';

	private const PERMIT_LINE_3 = 'AL<HASSAN<<OMAR<<<<<<<<<<<<<<<';

	/**
	 * A Dutch passport valid for years passes.
	 *
	 * @return void
	 */
	public function testADutchPassportPasses(): void {
		$out = $this->service()->decide(
			[
				'startDate' => '2026-10-01',
				'documentType' => 'paspoort',
				'nationality' => 'NLD',
				'documentExpiry' => '2031-03-09',
			]
		);

		$this->assertSame('geslaagd', $out['result']);
		$this->assertSame('eer-onderdaan', $out['reasonCode']);
		$this->assertSame('handmatig', $out['method']);
	}//end testADutchPassportPasses()

	/**
	 * A passport that expires before the start date fails.
	 *
	 * @return void
	 */
	public function testAPassportExpiredAtTheStartDateFails(): void {
		$out = $this->service()->decide(
			[
				'startDate' => '2026-10-01',
				'documentType' => 'paspoort',
				'nationality' => 'NLD',
				'documentExpiry' => '2026-09-01',
			]
		);

		$this->assertSame('mislukt', $out['result']);
		$this->assertSame('document-verlopen', $out['reasonCode']);
	}//end testAPassportExpiredAtTheStartDateFails()

	/**
	 * No document at all fails.
	 *
	 * @return void
	 */
	public function testNoDocumentFails(): void {
		$out = $this->service()->decide(['startDate' => '2026-10-01', 'nationality' => 'NLD']);

		$this->assertSame('mislukt', $out['result']);
		$this->assertSame('geen-document', $out['reasonCode']);
	}//end testNoDocumentFails()

	/**
	 * A residence document whose endorsement allows work passes.
	 *
	 * @return void
	 */
	public function testAResidenceDocumentThatAllowsWorkPasses(): void {
		$out = $this->service()->decide(
			[
				'startDate' => '2026-10-01',
				'documentType' => 'verblijfsdocument',
				'nationality' => 'SYR',
				'documentExpiry' => '2029-05-01',
				'endorsement' => 'Arbeid vrij toegestaan. TWV niet vereist.',
			]
		);

		$this->assertSame('geslaagd', $out['result']);
		$this->assertSame('verblijf-arbeid-vrij', $out['reasonCode']);
	}//end testAResidenceDocumentThatAllowsWorkPasses()

	/**
	 * A residence document without a work endorsement and no work permit fails.
	 *
	 * @return void
	 */
	public function testAResidenceDocumentWithoutWorkEndorsementFails(): void {
		$out = $this->service()->decide(
			[
				'startDate' => '2026-10-01',
				'documentType' => 'verblijfsdocument',
				'nationality' => 'SYR',
				'documentExpiry' => '2029-05-01',
				'endorsement' => 'Arbeid niet toegestaan.',
			]
		);

		$this->assertSame('mislukt', $out['result']);
		$this->assertSame('geen-arbeidstoestemming', $out['reasonCode']);
		$this->assertStringStartsWith('No permission to work', $out['reason']);
	}//end testAResidenceDocumentWithoutWorkEndorsementFails()

	/**
	 * An endorsement that needs a work permit passes with a current one, fails with an expired one.
	 *
	 * @return void
	 */
	public function testAWorkPermitMustCoverTheStartDate(): void {
		$input = [
			'startDate' => '2026-10-01',
			'documentType' => 'verblijfsdocument',
			'nationality' => 'IND',
			'documentExpiry' => '2029-05-01',
			'endorsement' => 'Arbeid toegestaan mits TWV.',
			'twvValidUntil' => '2027-09-30',
		];

		$this->assertSame('twv-geldig', $this->service()->decide($input)['reasonCode']);

		$input['twvValidUntil'] = '2026-09-30';
		$this->assertSame('geen-arbeidstoestemming', $this->service()->decide($input)['reasonCode']);
	}//end testAWorkPermitMustCoverTheStartDate()

	/**
	 * A valid passport zone is read: nationality and expiry come from it.
	 *
	 * @return void
	 */
	public function testAValidPassportZoneIsReadAndPasses(): void {
		$out = $this->service()->decide(
			[
				'startDate' => '2026-10-01',
				'mrz' => self::PASSPORT_LINE_1 . "\n" . self::PASSPORT_LINE_2,
			]
		);

		$this->assertSame('geslaagd', $out['result']);
		$this->assertSame('extractie', $out['method']);
		$this->assertSame('NLD', $out['nationality']);
		$this->assertSame('2031-03-09', $out['documentExpiry']);
		$this->assertSame('paspoort', $out['documentType']);
	}//end testAValidPassportZoneIsReadAndPasses()

	/**
	 * A wrong check digit fails, naming the field.
	 *
	 * @return void
	 */
	public function testAWrongCheckDigitFails(): void {
		// Expiry check digit 4 replaced by 5.
		$tampered = substr_replace(self::PASSPORT_LINE_2, '5', 27, 1);
		$out = $this->service()->decide(
			[
				'startDate' => '2026-10-01',
				'mrz' => [self::PASSPORT_LINE_1, $tampered],
			]
		);

		$this->assertSame('mislukt', $out['result']);
		$this->assertSame('controlecijfer-onjuist', $out['reasonCode']);
		$this->assertStringContainsString('expiry', $out['reason']);
	}//end testAWrongCheckDigitFails()

	/**
	 * A residence permit card zone (three lines) is read; the endorsement still decides.
	 *
	 * @return void
	 */
	public function testAResidencePermitCardZoneIsRead(): void {
		$out = $this->service()->decide(
			[
				'startDate' => '2026-10-01',
				'mrz' => [self::PERMIT_LINE_1, self::PERMIT_LINE_2, self::PERMIT_LINE_3],
				'endorsement' => 'Arbeid niet toegestaan.',
			]
		);

		$this->assertSame('SYR', $out['nationality']);
		$this->assertSame('2029-05-01', $out['documentExpiry']);
		$this->assertSame('verblijfsdocument', $out['documentType']);
		$this->assertSame('mislukt', $out['result']);
		$this->assertSame('geen-arbeidstoestemming', $out['reasonCode']);
	}//end testAResidencePermitCardZoneIsRead()

	/**
	 * A German ID card writes D, which is an EEA nationality.
	 *
	 * @return void
	 */
	public function testTheGermanZoneAliasIsEea(): void {
		$out = $this->service()->decide(
			[
				'startDate' => '2026-10-01',
				'documentType' => 'identiteitskaart',
				'nationality' => 'D',
				'documentExpiry' => '2030-01-01',
			]
		);

		$this->assertSame('geslaagd', $out['result']);
		$this->assertSame('DEU', $out['nationality']);
	}//end testTheGermanZoneAliasIsEea()

	/**
	 * The service under test.
	 *
	 * @return RightToWorkService
	 */
	private function service(): RightToWorkService {
		return new RightToWorkService();
	}//end service()

}//end class
