<?php

/**
 * The yearly report of payments to third parties (UBD, formerly IB47):
 * one melding per payee with the year's total, in the Belastingdienst's
 * delivery format, validated against its XSD.
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
 * @spec openspec/specs/third-party-payments/spec.md#REQ-UBD-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use DOMDocument;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Service\ThirdPartyReportService;
use OCA\Humaniq\Tests\Unit\Support\FakeContainer;
use OCA\Humaniq\Tests\Unit\Support\FakeObjectStore;
use OCA\Humaniq\Tests\Unit\Support\FakeSchemaMapper;
use OCA\Humaniq\Tests\Unit\Support\RegisterSchemaValidator;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Design D2 (the report) over the real gateway and the real XSD.
 */
class ThirdPartyReportServiceTest extends TestCase {

	/**
	 * The in-memory register.
	 *
	 * @var FakeObjectStore
	 */
	private FakeObjectStore $store;

	/**
	 * The subject.
	 *
	 * @var ThirdPartyReportService
	 */
	private ThirdPartyReportService $service;

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new FakeObjectStore();
		$this->service = $this->serviceWithRelNr('SWO12345');

		$this->store->seed('hrAdministration', 'adm-1', ['administrationId' => 'ADM-001', 'name' => 'Conduction Demo B.V.', 'loonheffingennummer' => '123456789L01', 'postalAddress' => 'Lauriergracht 14h, 1016 RL Amsterdam']);
		$this->store->seed('ThirdPartyPayee', 'payee-lecturer', ['initials' => 'J.P.', 'prefix' => 'van', 'lastName' => 'Dijk', 'bsn' => '111222333', 'dateOfBirth' => '1971-05-14', 'street' => 'Kerkstraat', 'houseNumber' => '12', 'postcode' => '3511AB', 'city' => 'Utrecht', 'country' => 'NL', 'administrationId' => 'ADM-001']);
		$this->store->seed('ThirdPartyPayee', 'payee-member', ['initials' => 'A.', 'lastName' => 'Bos', 'bsn' => '123456782', 'dateOfBirth' => '1960-01-30', 'street' => 'Dorpsweg', 'houseNumber' => '3', 'houseNumberAddition' => 'A', 'postcode' => '1234AB', 'city' => 'Ede', 'country' => 'NL', 'administrationId' => 'ADM-001']);
		$this->store->seed('ThirdPartyPayment', 'pay-1', ['payeeId' => 'payee-lecturer', 'paidOn' => '2026-03-10', 'amount' => 450.00, 'expenseAllowance' => 32.40, 'administrationId' => 'ADM-001']);
		$this->store->seed('ThirdPartyPayment', 'pay-2', ['payeeId' => 'payee-lecturer', 'paidOn' => '2026-06-02', 'amount' => 450.00, 'administrationId' => 'ADM-001']);
		$this->store->seed('ThirdPartyPayment', 'pay-3', ['payeeId' => 'payee-member', 'paidOn' => '2026-04-15', 'amount' => 275.75, 'expenseAllowance' => 18.90, 'administrationId' => 'ADM-001']);
		// Not in the report: another year, another administration.
		$this->store->seed('ThirdPartyPayment', 'pay-2025', ['payeeId' => 'payee-member', 'paidOn' => '2025-11-20', 'amount' => 100.00, 'administrationId' => 'ADM-001']);
		$this->store->seed('ThirdPartyPayment', 'pay-other', ['payeeId' => 'payee-member', 'paidOn' => '2026-05-01', 'amount' => 999.00, 'administrationId' => 'ADM-002']);
	}//end setUp()

	/**
	 * Two guest lecturers paid three times: the report carries two
	 * meldingen with each payee's total including expenses, rounded down
	 * to whole euros, dated on the last payment, and validates against the
	 * Belastingdienst's XSD and the report's own schema.
	 *
	 * @return void
	 */
	public function testTwoPayeesThreePaymentsMakeTwoLines(): void {
		$outcome = $this->service->assemble(administrationId: 'ADM-001', year: 2026, userId: 'payroll-1');

		self::assertSame('assembled', $outcome['status']);
		self::assertSame([2, 1226, 0], [$outcome['lineCount'], $outcome['totalAmount'], $outcome['blockingFindings']]);

		$report = $this->rowsOf('ThirdPartyReport')[0];
		self::assertSame(['ADM-001', 2026, 'concept', '2027-01-31', 'payroll-1'], [$report['administrationId'], $report['year'], $report['status'], $report['deadline'], $report['assembledBy']]);
		self::assertMatchesRegularExpression('/^UBD_123456789L01_[A-Za-z0-9]{1,32}\.xml$/', $report['messageFileName']);

		$xml = new DOMDocument();
		$xml->loadXML($report['messageXml']);
		self::assertTrue($xml->schemaValidateSource((string)file_get_contents(dirname(__DIR__, 3) . '/lib/Standards/ubd/UBD_1.0_V1.20211028.xsd')));

		$ns = 'http://xml.belastingdienst.nl/schemas/UBD/DELIVERY/1.0';
		self::assertSame('SWO12345', $xml->getElementsByTagNameNS($ns, 'relNr')->item(0)->textContent);
		self::assertSame('123456789L01', $xml->getElementsByTagNameNS($ns, 'LHnrBron')->item(0)->textContent);
		$amounts = [];
		foreach ($xml->getElementsByTagNameNS($ns, 'opvoerofwijziging') as $melding) {
			$amounts[$melding->getElementsByTagNameNS($ns, 'achternaam')->item(0)->textContent] = [
				$melding->getElementsByTagNameNS($ns, 'bedrag')->item(0)->textContent,
				$melding->getElementsByTagNameNS($ns, 'uitbetalingsDatum')->item(0)->textContent,
				$melding->getElementsByTagNameNS($ns, 'bSN')->item(0)->textContent,
			];
		}

		// 450 + 32.40 + 450 = 932.40 -> 932; 275.75 + 18.90 = 294.65 -> 294.
		self::assertSame(['Dijk' => ['932', '2026-06-02', '111222333'], 'Bos' => ['294', '2026-04-15', '123456782']], $amounts);

		unset($report['id']);
		self::assertSame([], RegisterSchemaValidator::errors('ThirdPartyReport', $report));
	}//end testTwoPayeesThreePaymentsMakeTwoLines()

	/**
	 * Golden file: the seeded year's message, with the two moment-bound
	 * values (aanmaakmoment, LeveringsID) normalised, is byte for byte the
	 * reviewed fixture.
	 *
	 * @return void
	 */
	public function testTheMessageMatchesTheGoldenFile(): void {
		$this->service->assemble(administrationId: 'ADM-001', year: 2026, userId: 'payroll-1');
		$message = (string)$this->rowsOf('ThirdPartyReport')[0]['messageXml'];
		$message = preg_replace('#<aanmaakmoment>[^<]+</aanmaakmoment>#', '<aanmaakmoment>2027-01-05T10:00:00</aanmaakmoment>', $message);
		$message = preg_replace('#<LeveringsID>[^<]+</LeveringsID>#', '<LeveringsID>UBD2026X</LeveringsID>', (string)$message);

		self::assertStringEqualsFile(dirname(__DIR__, 2) . '/fixtures/ubd/ubd-2026-adm-001.xml', (string)$message);
	}//end testTheMessageMatchesTheGoldenFile()

	/**
	 * A payee with payments and no BSN refuses with a finding: no message,
	 * one blocking finding naming the payee.
	 *
	 * @return void
	 */
	public function testAPayeeWithoutABsnIsABlockingFinding(): void {
		$payee = $this->store->find('payee-member', schema: 'ThirdPartyPayee')->getObject();
		unset($payee['bsn']);
		$this->store->seed('ThirdPartyPayee', 'payee-member', $payee);

		$outcome = $this->service->assemble(administrationId: 'ADM-001', year: 2026, userId: 'payroll-1');

		self::assertSame(1, $outcome['blockingFindings']);
		$report = $this->rowsOf('ThirdPartyReport')[0];
		self::assertArrayNotHasKey('messageXml', $report);
		self::assertSame([['kind' => 'payee-without-bsn', 'severity' => 'blocking', 'payeeId' => 'payee-member']], array_map(static fn (array $f): array => ['kind' => $f['kind'], 'severity' => $f['severity'], 'payeeId' => $f['payeeId']], $report['findings']));
		unset($report['id']);
		self::assertSame([], RegisterSchemaValidator::errors('ThirdPartyReport', $report));
	}//end testAPayeeWithoutABsnIsABlockingFinding()

	/**
	 * The administration's own identification blocks the report: no
	 * payroll tax number in the format, no postal address.
	 *
	 * @return void
	 */
	public function testAnAdministrationWithoutTaxNumberOrAddressBlocks(): void {
		$this->store->seed('hrAdministration', 'adm-1', ['administrationId' => 'ADM-001', 'name' => 'Conduction Demo B.V.', 'loonheffingennummer' => '12345']);

		$outcome = $this->service->assemble(administrationId: 'ADM-001', year: 2026, userId: 'payroll-1');

		self::assertSame(2, $outcome['blockingFindings']);
		self::assertEqualsCanonicalizing(['administration-without-tax-number', 'administration-without-address'], array_column($this->rowsOf('ThirdPartyReport')[0]['findings'], 'kind'));
	}//end testAnAdministrationWithoutTaxNumberOrAddressBlocks()

	/**
	 * Without the software relation number the message still validates,
	 * with a warning that the Belastingdienst expects one.
	 *
	 * @return void
	 */
	public function testAMissingSoftwareRelationNumberIsAWarning(): void {
		$outcome = $this->serviceWithRelNr('')->assemble(administrationId: 'ADM-001', year: 2026, userId: 'payroll-1');

		self::assertSame([0, 1], [$outcome['blockingFindings'], $outcome['warningFindings']]);
		self::assertSame('software-relation-number-missing', $this->rowsOf('ThirdPartyReport')[0]['findings'][0]['kind']);
	}//end testAMissingSoftwareRelationNumberIsAWarning()

	/**
	 * Assembling a concept report again replaces it (same report, same
	 * message ids); a report that is ready or sent is refused until it is
	 * reopened.
	 *
	 * @return void
	 */
	public function testAReassemblyReplacesAConceptAndRefusesOnceReady(): void {
		$first = $this->service->assemble(administrationId: 'ADM-001', year: 2026, userId: 'payroll-1');
		$firstIds = $this->meldingIds();
		$second = $this->service->assemble(administrationId: 'ADM-001', year: 2026, userId: 'payroll-2');

		self::assertSame($first['reportId'], $second['reportId']);
		self::assertCount(1, $this->rowsOf('ThirdPartyReport'));
		self::assertSame($firstIds, $this->meldingIds());

		$report = $this->rowsOf('ThirdPartyReport')[0];
		$this->store->seed('ThirdPartyReport', $report['id'], array_merge($report, ['status' => 'klaargezet']));
		$third = $this->service->assemble(administrationId: 'ADM-001', year: 2026, userId: 'payroll-1');

		self::assertSame('refused-not-concept', $third['status']);
	}//end testAReassemblyReplacesAConceptAndRefusesOnceReady()

	/**
	 * A year without payments has nothing to report; a payee whose total
	 * rounds down to zero is left out (the format's amount is at least 1).
	 *
	 * @return void
	 */
	public function testAnEmptyYearAndASubEuroTotal(): void {
		self::assertSame('nothing-to-report', $this->service->assemble(administrationId: 'ADM-001', year: 2024, userId: 'payroll-1')['status']);

		$this->store->seed('ThirdPartyPayment', 'pay-cents', ['payeeId' => 'payee-member', 'paidOn' => '2025-02-01', 'amount' => 0.60, 'administrationId' => 'ADM-001']);
		$this->store->seed('ThirdPartyPayment', 'pay-2025', ['payeeId' => 'payee-lecturer', 'paidOn' => '2025-11-20', 'amount' => 100.00, 'administrationId' => 'ADM-001']);
		$outcome = $this->service->assemble(administrationId: 'ADM-001', year: 2025, userId: 'payroll-1');

		self::assertSame([1, 100], [$outcome['lineCount'], $outcome['totalAmount']]);
	}//end testAnEmptyYearAndASubEuroTotal()

	/**
	 * Initials longer than six characters are cut to five plus a hyphen
	 * (Deel 2, 4.5.4).
	 *
	 * @return void
	 */
	public function testLongInitialsAreCut(): void {
		self::assertSame('J.P.M-', ThirdPartyReportService::initials('J.P.M.A.'));
		self::assertSame('J.P.', ThirdPartyReportService::initials(' J.P. '));
	}//end testLongInitialsAreCut()

	/**
	 * A subject whose software relation number setting is the given value.
	 *
	 * @param string $relNr The ubd_relnr app config value.
	 *
	 * @return ThirdPartyReportService
	 */
	private function serviceWithRelNr(string $relNr): ThirdPartyReportService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$gateway = new HoursRegisterGateway(
			container: new FakeContainer([
				'OCA\OpenRegister\Service\ObjectService' => $this->store,
				'OCA\OpenRegister\Db\SchemaMapper' => new FakeSchemaMapper(),
			]),
			settingsService: $settings,
			orgResolution: new OrgResolutionService()
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($relNr);

		return new ThirdPartyReportService(gateway: $gateway, appConfig: $appConfig, logger: new NullLogger());
	}//end serviceWithRelNr()

	/**
	 * The meldingsIDs of the stored message.
	 *
	 * @return list<string>
	 */
	private function meldingIds(): array {
		preg_match_all('#<meldingsID>([^<]+)</meldingsID>#', (string)$this->rowsOf('ThirdPartyReport')[0]['messageXml'], $m);
		return $m[1];
	}//end meldingIds()

	/**
	 * The rows of a schema.
	 *
	 * @param string $schema The schema.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function rowsOf(string $schema): array {
		$this->store->setSchema($schema);
		return $this->store->findAll();
	}//end rowsOf()

}//end class
