<?php

/**
 * CorrectionDiffTest
 *
 * Edge paths of the difference between the last stand sent and the
 * period's current income relationships: unreadable XML, a relationship
 * keyed by personnel number, a sent correction applied over the stand,
 * and an added, a changed, an unchanged and a withdrawn relationship.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Payroll\Loonaangifte
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
 * @spec openspec/changes/filings-correction-message/specs/loonaangifte-correction/spec.md#REQ-LHC-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Payroll\Loonaangifte;

use OCA\Humaniq\Payroll\Loonaangifte\CorrectionDiff;
use PHPUnit\Framework\TestCase;

/**
 * Edge paths of CorrectionDiff.
 *
 * @spec openspec/changes/filings-correction-message/specs/loonaangifte-correction/spec.md#REQ-LHC-002
 */
class CorrectionDiffTest extends TestCase {

	/**
	 * An income relationship tree as IncomeRelationshipLine makes it.
	 *
	 * @param string $numIv  The relationship number.
	 * @param string $bsn    The BSN, or '' without one.
	 * @param string $persNr The personnel number.
	 * @param string $wage   The taxable wage (LnLbPh).
	 *
	 * @return array<string, mixed>
	 */
	private function tree(string $numIv, string $bsn, string $persNr, string $wage): array {
		$tree = ['NumIV' => $numIv, 'DatAanv' => '2026-01-01', 'PersNr' => $persNr];
		if ($bsn !== '') {
			$tree['NatuurlijkPersoon'] = ['SofiNr' => $bsn];
		}

		$tree['Inkomstenperiode'] = [['DatAanvTv' => '2026-03-01', 'SrtIV' => '15']];
		$tree['Werknemersgegevens'] = ['LnLbPh' => $wage, 'LnSV' => $wage];
		return $tree;
	}//end tree()

	/**
	 * Unreadable or empty XML yields no stand, so the caller blocks.
	 *
	 * @return void
	 */
	public function testUnreadableXmlYieldsNoRelationships(): void {
		self::assertSame([], CorrectionDiff::fromXml(''));
		self::assertSame([], CorrectionDiff::fromXml('<not-closed>'));
	}//end testUnreadableXmlYieldsNoRelationships()

	/**
	 * A sent message is read back per relationship; one without a BSN is
	 * keyed by its personnel number, and the first income period counts.
	 *
	 * @return void
	 */
	public function testASentMessageIsReadBackPerRelationship(): void {
		$xml = '<?xml version="1.0"?><Loonaangifte xmlns="urn:x"><AdministratieveEenheid><TijdvakAangifte><VolledigeAangifte>'
			. '<InkomstenverhoudingInitieel><NumIV>1</NumIV><DatAanv>2026-01-01</DatAanv><PersNr>P1</PersNr><NatuurlijkPersoon><SofiNr>123456782</SofiNr></NatuurlijkPersoon>'
			. '<Inkomstenperiode><DatAanvTv>2026-03-01</DatAanvTv><SrtIV>15</SrtIV></Inkomstenperiode><Inkomstenperiode><SrtIV>99</SrtIV></Inkomstenperiode>'
			. '<Werknemersgegevens><LnLbPh>3800</LnLbPh><LnSV>3800</LnSV></Werknemersgegevens></InkomstenverhoudingInitieel>'
			. '<InkomstenverhoudingInitieel><NumIV>2</NumIV><PersNr>P2</PersNr><Werknemersgegevens><LnLbPh>100</LnLbPh></Werknemersgegevens></InkomstenverhoudingInitieel>'
			. '</VolledigeAangifte></TijdvakAangifte></AdministratieveEenheid></Loonaangifte>';

		$stand = CorrectionDiff::fromXml($xml);

		self::assertSame(['B123456782#1', 'PP2#2'], array_keys($stand));
		self::assertSame('15', $stand['B123456782#1']['values']['Inkomstenperiode/SrtIV']);
		self::assertSame('3800', $stand['B123456782#1']['values']['LnLbPh']);
		self::assertSame('2026-01-01', $stand['B123456782#1']['values']['DatAanv']);
		self::assertSame('100', $stand['PP2#2']['values']['LnLbPh']);
	}//end testASentMessageIsReadBackPerRelationship()

	/**
	 * A sent correction replaces a relationship and withdraws another, by
	 * BSN or by personnel number.
	 *
	 * @return void
	 */
	public function testASentCorrectionIsAppliedOverTheStand(): void {
		$stand = CorrectionDiff::apply([], ['InkomstenverhoudingInitieel' => [$this->tree('1', '123456782', 'P1', '3800'), $this->tree('2', '', 'P2', '100'), $this->tree('3', '111222333', 'P3', '50')]]);
		$stand = CorrectionDiff::apply(
			$stand,
			[
				'InkomstenverhoudingInitieel' => [$this->tree('1', '123456782', 'P1', '3900')],
				'InkomstenverhoudingIntrekking' => [['NumIV' => '2', 'PersNr' => 'P2'], ['NumIV' => '3', 'SofiNr' => '111222333']],
			]
		);

		self::assertSame(['B123456782#1'], array_keys($stand));
		self::assertSame('3900', $stand['B123456782#1']['values']['LnLbPh']);
		self::assertSame($stand, CorrectionDiff::apply($stand, []));
	}//end testASentCorrectionIsAppliedOverTheStand()

	/**
	 * Compared with the stand: an unchanged relationship is left out, a
	 * changed one names old and new, a new one is added, and one no longer
	 * reported is withdrawn by BSN or, without one, by personnel number.
	 *
	 * @return void
	 */
	public function testAddedChangedUnchangedAndWithdrawn(): void {
		$received = CorrectionDiff::apply([], ['InkomstenverhoudingInitieel' => [$this->tree('1', '123456782', 'P1', '3800'), $this->tree('1', '111222333', 'P2', '2400'), $this->tree('1', '', 'P9', '10'), $this->tree('2', '999999990', 'P8', '20')]]);
		$current = [
			['employeeId' => 'emp-1', 'tree' => $this->tree('1', '123456782', 'P1', '3800')],
			['employeeId' => 'emp-2', 'tree' => $this->tree('1', '111222333', 'P2', '2500')],
			['employeeId' => 'emp-3', 'tree' => $this->tree('1', '', 'P3', '700')],
		];

		$diff = CorrectionDiff::compare($received, $current);

		self::assertSame(['changed', 'added', 'withdrawn', 'withdrawn'], array_column($diff['lines'], 'kind'));
		self::assertSame(['old' => '2400', 'new' => '2500'], $diff['lines'][0]['changes']['LnLbPh']);
		self::assertSame('emp-3', $diff['lines'][1]['employeeId']);
		self::assertSame(['P2', 'P3'], array_column($diff['initial'], 'PersNr'));
		self::assertSame([['NumIV' => '1', 'PersNr' => 'P9'], ['NumIV' => '2', 'SofiNr' => '999999990']], $diff['withdrawn']);
		self::assertSame(['', '999999990'], [$diff['lines'][2]['bsn'], $diff['lines'][3]['bsn']]);
	}//end testAddedChangedUnchangedAndWithdrawn()

	/**
	 * Nothing changed, nothing to correct.
	 *
	 * @return void
	 */
	public function testNoDifferenceIsEmpty(): void {
		$tree = $this->tree('1', '123456782', 'P1', '3800');
		$received = CorrectionDiff::apply([], ['InkomstenverhoudingInitieel' => [$tree]]);

		self::assertSame(['lines' => [], 'initial' => [], 'withdrawn' => []], CorrectionDiff::compare($received, [['employeeId' => 'emp-1', 'tree' => $tree]]));
	}//end testNoDifferenceIsEmpty()

}//end class
