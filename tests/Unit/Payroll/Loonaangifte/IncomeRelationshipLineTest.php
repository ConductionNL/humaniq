<?php

/**
 * IncomeRelationshipLineTest: the codes and amounts of one income relationship and the findings that stop it (Gegevensspecificaties 2026, filings-wage-tax-message D5).
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
 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Payroll\Loonaangifte;

use OCA\Humaniq\Payroll\CalculationInput;
use OCA\Humaniq\Payroll\CalculationResult;
use OCA\Humaniq\Payroll\Loonaangifte\IncomeRelationshipLine;
use OCA\Humaniq\Payroll\Loonaangifte\LoonaangifteMessage;
use OCA\Humaniq\Payroll\Loonaangifte\LoonaangifteMessageBuilder;
use OCA\Humaniq\Payroll\PayrollCalculator;
use OCA\Humaniq\Payroll\TaxTables;
use PHPUnit\Framework\TestCase;

/**
 * One income relationship, line by line.
 */
class IncomeRelationshipLineTest extends TestCase {

	private const JUNE = ['2026-06-01', '2026-06-30'];

	/**
	 * A DGA not insured for the employee insurances: code 17, no relationship
	 * kind or contract indicators, no SV wage or premium bases, insurances N.
	 *
	 * @return void
	 */
	public function testADgaIsReportedOnCode17(): void {
		$snapshot = $this->snapshot(['verzekeringsplichtig' => false]);
		$line = IncomeRelationshipLine::make($this->employee(['isDga' => true]), $this->contract(), $this->slip($snapshot), $this->calc($snapshot), self::JUNE);
		$period = $line['tree']['Inkomstenperiode'][0];

		self::assertSame(['17', null, null, 'N', 'N', 'N'], [$period['SrtIV'], $period['CdAard'], $period['IndArbovOnbepTd'], $period['IndWAO'], $period['IndWW'], $period['IndZW']]);
		self::assertSame([0, 0, 0, 0], [$line['cents']['LnSV'], $line['cents']['PrlnAofAnwLg'], $line['cents']['PrlnWhkAnw'], $line['cents']['PrlnAwfAnwLg']]);
		self::assertSame(380000, $line['cents']['LnLbPh']);
		self::assertSame([], $line['findings']);
	}//end testADgaIsReportedOnCode17()

	/**
	 * Contract kinds: BBL 83, agency 11, a civil servant under the Ambtenarenwet
	 * 11/18 without contract indicators and with a Ufo warning; on-call J.
	 *
	 * @return void
	 */
	public function testTheRelationshipKinds(): void {
		$snapshot = $this->snapshot();
		$kind = fn (array $employee, array $contract): array => IncomeRelationshipLine::make($this->employee($employee), $this->contract($contract), $this->slip($snapshot), $this->calc($snapshot), self::JUNE);

		self::assertSame('83', $kind([], ['type' => 'bbl'])['tree']['Inkomstenperiode'][0]['CdAard']);
		self::assertSame('11', $kind([], ['type' => 'agency'])['tree']['Inkomstenperiode'][0]['CdAard']);
		$oncall = $kind([], ['type' => 'oproep', 'hoursPerWeek' => 0])['tree'];
		self::assertSame(['J', '0'], [$oncall['Inkomstenperiode'][0]['IndOprov'], $oncall['Werknemersgegevens']['AantCtrcturenPWk']]);

		$civil = $kind(['publicSectorRegime' => 'ambtenarenwet'], []);
		$period = $civil['tree']['Inkomstenperiode'][0];
		self::assertSame(['11', '18', null], [$period['SrtIV'], $period['CdAard'], $period['IndSchriftArbov']]);
		self::assertSame(['public-sector-ufo', 'warning'], [$civil['findings'][0]['kind'], $civil['findings'][0]['severity']]);
	}//end testTheRelationshipKinds()

	/**
	 * Withheld Zvw (M), the green table, a four-week wage period, paid hours
	 * rounding half up, a contract wage from the hourly wage, and a retro
	 * correction as a warning.
	 *
	 * @return void
	 */
	public function testCodesAndAmountsFromThePayslip(): void {
		$snapshot = $this->snapshot(['taxTableColor' => 'groen']);
		$slip = array_merge($this->slip($snapshot), ['zvwMode' => 'inhouding', 'period' => '2026-P06', 'hoursPaid' => 151.5, 'retroAdjustment' => -12.5]);
		$line = IncomeRelationshipLine::make($this->employee(['grossMonthlySalary' => null]), $this->contract(['hourlyWage' => 20, 'hoursPerWeek' => 36.5]), $slip, $this->calc($snapshot), self::JUNE);
		$tree = $line['tree'];

		self::assertSame(['M', '024'], [$tree['Inkomstenperiode'][0]['CdZvw'], $tree['Inkomstenperiode'][0]['LbTab']]);
		self::assertSame([(int)round((float)$slip['zvw'] * 100), 0], [$line['cents']['BijdrZvw'], $line['cents']['WghZvw']]);
		self::assertSame(['152', '3163.33', '36.5'], [$tree['Werknemersgegevens']['AantVerlU'], $tree['Werknemersgegevens']['Ctrctln'], $tree['Werknemersgegevens']['AantCtrcturenPWk']]);
		self::assertSame(['retro-adjustment-elsewhere', 'warning'], [$line['findings'][0]['kind'], $line['findings'][0]['severity']]);
	}//end testCodesAndAmountsFromThePayslip()

	/**
	 * The person: an invalid BSN, a BSN without name or birth date, a missing
	 * start date and an incomplete address (left out).
	 *
	 * @return void
	 */
	public function testThePersonFindings(): void {
		$snapshot = $this->snapshot();
		$employee = ['bsn' => '123456789', 'lastName' => '', 'dateOfBirth' => '', 'startDate' => '', 'postcode' => '0123 ab'];
		$line = IncomeRelationshipLine::make($this->employee($employee), $this->contract(), $this->slip($snapshot), $this->calc($snapshot), self::JUNE);
		$elements = array_column($line['findings'], 'element');
		sort($elements);

		self::assertSame(['DatAanv', 'Gebdat', 'SignNm', 'SofiNr'], $elements);
		self::assertNull($line['tree']['NatuurlijkPersoon']['AdresBinnenland']);
		self::assertSame('2026-06-01', $line['tree']['DatAanv']);
	}//end testThePersonFindings()

	/**
	 * A payslip without engine input cannot be reproduced; an end date after
	 * the period is not sent; an end reason outside the code list is a finding.
	 *
	 * @return void
	 */
	public function testReproductionAndEndDates(): void {
		$snapshot = $this->snapshot();
		$line = IncomeRelationshipLine::make($this->employee(), $this->contract(), $this->slip([]), null, self::JUNE);
		self::assertSame('payslip-not-reproducible', $line['findings'][0]['kind']);

		$later = IncomeRelationshipLine::make($this->employee(['endDate' => '2026-07-31']), $this->contract(), $this->slip($snapshot), $this->calc($snapshot), self::JUNE);
		self::assertSame([null, null], [$later['tree']['DatEind'], $later['tree']['CdRdnEindArbov']]);

		$unknown = IncomeRelationshipLine::make($this->employee(['endDate' => '2026-06-15', 'endReason' => '77']), $this->contract(), $this->slip($snapshot), $this->calc($snapshot), self::JUNE);
		self::assertSame('CdRdnEindArbov', $unknown['findings'][0]['element']);
	}//end testReproductionAndEndDates()

	/**
	 * The elfproef of GS p34/p66, and the collective rounding of GS p38: the
	 * unrounded employee amounts are summed, then cut to whole euros.
	 *
	 * @return void
	 */
	public function testTheElfproefAndTheCollectiveRounding(): void {
		self::assertTrue(IncomeRelationshipLine::elfproef('123456782'));
		self::assertFalse(IncomeRelationshipLine::elfproef('123456789'));
		self::assertFalse(IncomeRelationshipLine::elfproef('000000000'));
		self::assertFalse(IncomeRelationshipLine::elfproef('12345678'));

		$collective = LoonaangifteMessageBuilder::collective([['cents' => ['LnLbPh' => 167887, 'IngLbPh' => 50050]], ['cents' => ['LnLbPh' => 124595, 'IngLbPh' => 50050]]]);
		// GS p38 example: 1678.87 + 1245.95 = 2924.82, reported as 2924.
		self::assertSame([2924, 1001, 1001, 1001], [$collective['TotLnLbPh'], $collective['IngLbPh'], $collective['TotTeBet'], $collective['TotGen']]);
	}//end testTheElfproefAndTheCollectiveRounding()

	/**
	 * A message that is not XML, or that breaks the XSD, reports errors.
	 *
	 * @return void
	 */
	public function testValidationErrors(): void {
		$xsd = dirname(__DIR__, 4) . '/lib/Standards/loonaangifte/Loonaangifte2026v2.0.xsd';
		self::assertSame(['Het bericht is geen geldige XML.'], LoonaangifteMessage::errors('', $xsd));
		self::assertNotSame([], LoonaangifteMessage::errors(LoonaangifteMessage::render('http://xml.belastingdienst.nl/schemas/Loonaangifte/2026/01', '2.0', ['Bericht' => ['IdBer' => 'x']]), $xsd));
	}//end testValidationErrors()

	/**
	 * An employee.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed>
	 */
	private function employee(array $overrides = []): array {
		return array_merge(['id' => 'emp-1', 'firstName' => 'Anna', 'lastName' => 'Jansen', 'bsn' => '123456782', 'dateOfBirth' => '1990-04-12', 'startDate' => '2024-02-01', 'grossMonthlySalary' => 3800], $overrides);
	}//end employee()

	/**
	 * A contract.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed>
	 */
	private function contract(array $overrides = []): array {
		return array_merge(['type' => 'permanent', 'writtenContract' => true, 'hoursPerWeek' => 36], $overrides);
	}//end contract()

	/**
	 * Engine input.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed>
	 */
	private function snapshot(array $overrides = []): array {
		$input = (new CalculationInput(grossMonthlySalaryCents: 380000, taxTableColor: 'wit', loonheffingskortingToegepast: true, dateOfBirth: '1990-04-12', period: '2026-06', awfTariff: 'low', aofTariff: 'laag', whkPercentage: 1.52))->toArray();
		return array_merge($input, $overrides);
	}//end snapshot()

	/**
	 * The engine's result for an input.
	 *
	 * @param array<string, mixed> $snapshot The input.
	 *
	 * @return CalculationResult
	 */
	private function calc(array $snapshot): CalculationResult {
		return (new PayrollCalculator())->calculate(CalculationInput::fromDecoded($snapshot), TaxTables::load('nl-2026'));
	}//end calc()

	/**
	 * A payslip as the run writes it.
	 *
	 * @param array<string, mixed> $snapshot The engine input, or empty.
	 *
	 * @return array<string, mixed>
	 */
	private function slip(array $snapshot): array {
		$result = ($snapshot === [] ? null : $this->calc($snapshot));
		return [
			'period' => '2026-06',
			'grossPay' => 3800.0,
			'loonheffing' => ($result?->loonheffingCents ?? 0) / 100,
			'arbeidskorting' => ($result?->arbeidskortingCents ?? 0) / 100,
			'zvw' => ($result?->zvwCents ?? 0) / 100,
			'zvwMode' => 'werkgeversheffing',
			'vakantiegeldReserved' => 304.0,
			'awfTariff' => 'low',
			'engineInputSnapshot' => $snapshot,
		];
	}//end slip()

}//end class
