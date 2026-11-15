<?php

/**
 * The yearly statement for a third-party payee: its variable contract and
 * the generation through filinq.
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
 * @spec openspec/specs/third-party-payments/spec.md#REQ-UBD-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Service\ThirdPartyStatementService;
use OCA\Humaniq\Tests\Unit\Support\FakeContainer;
use OCA\Humaniq\Tests\Unit\Support\FakeObjectStore;
use OCA\Humaniq\Tests\Unit\Support\FakeSchemaMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Task 2.4: the ubd-jaaropgaaf document type.
 */
class ThirdPartyStatementServiceTest extends TestCase {

	/**
	 * The in-memory register.
	 *
	 * @var FakeObjectStore
	 */
	private FakeObjectStore $store;

	/**
	 * The container the subject resolves filinq and the file service in.
	 *
	 * @var FakeContainer
	 */
	private FakeContainer $container;

	/**
	 * The subject.
	 *
	 * @var ThirdPartyStatementService
	 */
	private ThirdPartyStatementService $service;

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new FakeObjectStore();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$this->container = new FakeContainer([
			'OCA\OpenRegister\Service\ObjectService' => $this->store,
			'OCA\OpenRegister\Db\SchemaMapper' => new FakeSchemaMapper(),
		]);
		$gateway = new HoursRegisterGateway(container: $this->container, settingsService: $settings, orgResolution: new OrgResolutionService());
		$this->service = new ThirdPartyStatementService(gateway: $gateway, container: $this->container, settings: $settings, logger: new NullLogger());

		$this->store->seed('hrAdministration', 'adm-1', ['administrationId' => 'ADM-001', 'name' => 'Conduction Demo B.V.', 'loonheffingennummer' => '123456789L01', 'postalAddress' => 'Lauriergracht 14h, 1016 RL Amsterdam']);
		$this->store->seed('ThirdPartyPayee', 'payee-lecturer', ['initials' => 'J.P.', 'prefix' => 'van', 'lastName' => 'Dijk', 'bsn' => '111222333', 'dateOfBirth' => '1971-05-14', 'street' => 'Kerkstraat', 'houseNumber' => '12', 'postcode' => '3511AB', 'city' => 'Utrecht', 'country' => 'NL', 'activity' => 'Gastlessen', 'administrationId' => 'ADM-001']);
		$this->store->seed('ThirdPartyPayment', 'pay-2', ['payeeId' => 'payee-lecturer', 'paidOn' => '2026-06-02', 'amount' => 450.00, 'description' => 'Juni', 'administrationId' => 'ADM-001']);
		$this->store->seed('ThirdPartyPayment', 'pay-1', ['payeeId' => 'payee-lecturer', 'paidOn' => '2026-03-10', 'amount' => 450.00, 'expenseAllowance' => 32.40, 'description' => 'Maart', 'administrationId' => 'ADM-001']);
		$this->store->seed('ThirdPartyPayment', 'pay-2025', ['payeeId' => 'payee-lecturer', 'paidOn' => '2025-11-20', 'amount' => 100.00, 'administrationId' => 'ADM-001']);
	}//end setUp()

	/**
	 * The variable contract: payee, administration, the year's payments in
	 * date order, the total paid and the total reported (whole euros).
	 *
	 * @return void
	 */
	public function testTheVariableContract(): void {
		$vars = $this->service->variables(payeeId: 'payee-lecturer', year: 2026);

		self::assertSame(['ubd-jaaropgaaf', 2026], [$vars['documentType'], $vars['year']]);
		self::assertSame('J.P. van Dijk', $vars['payee']['name']);
		self::assertSame(['Kerkstraat 12', '3511AB Utrecht'], $vars['payee']['addressLines']);
		self::assertSame(['111222333', '1971-05-14', 'Gastlessen'], [$vars['payee']['bsn'], $vars['payee']['dateOfBirth'], $vars['payee']['activity']]);
		self::assertSame(['Conduction Demo B.V.', '123456789L01', 'Lauriergracht 14h, 1016 RL Amsterdam'], [$vars['administration']['name'], $vars['administration']['loonheffingennummer'], $vars['administration']['postalAddress']]);
		self::assertSame(['2026-03-10', '2026-06-02'], array_column($vars['payments'], 'paidOn'));
		self::assertSame([482.40, 450.00], array_column($vars['payments'], 'total'));
		self::assertSame([932.40, 932], [$vars['totalPaid'], $vars['totalReported']]);

		self::assertNull($this->service->variables(payeeId: 'nobody', year: 2026));
	}//end testTheVariableContract()

	/**
	 * Golden: the whole variable set, key for key; and a payee without prefix, with a house-number addition and no known administration.
	 *
	 * @return void
	 */
	public function testTheWholeVariableSet(): void {
		self::assertSame(
			[
				'documentType' => 'ubd-jaaropgaaf',
				'year' => 2026,
				'payee' => [
					'name' => 'J.P. van Dijk',
					'addressLines' => ['Kerkstraat 12', '3511AB Utrecht'],
					'lastName' => 'Dijk',
					'bsn' => '111222333',
					'dateOfBirth' => '1971-05-14',
					'activity' => 'Gastlessen',
				],
				'administration' => [
					'administrationId' => 'ADM-001',
					'name' => 'Conduction Demo B.V.',
					'loonheffingennummer' => '123456789L01',
					'postalAddress' => 'Lauriergracht 14h, 1016 RL Amsterdam',
				],
				'payments' => [
					['paidOn' => '2026-03-10', 'description' => 'Maart', 'amount' => 450.0, 'expenseAllowance' => 32.4, 'total' => 482.4],
					['paidOn' => '2026-06-02', 'description' => 'Juni', 'amount' => 450.0, 'expenseAllowance' => 0.0, 'total' => 450.0],
				],
				'totalPaid' => 932.4,
				'totalReported' => 932,
			],
			$this->service->variables(payeeId: 'payee-lecturer', year: 2026)
		);

		$this->store->seed('ThirdPartyPayee', 'payee-plain', ['initials' => 'A.', 'lastName' => 'Smit', 'street' => 'Dorpsweg', 'houseNumber' => '3', 'houseNumberAddition' => 'b', 'administrationId' => 'ADM-404']);
		$this->store->seed('ThirdPartyPayment', 'pay-plain', ['payeeId' => 'payee-plain', 'paidOn' => '2026-01-31', 'amount' => '99.995']);
		$plain = $this->service->variables(payeeId: 'payee-plain', year: 2026);

		self::assertSame(['name' => 'A. Smit', 'addressLines' => ['Dorpsweg 3b', ''], 'lastName' => 'Smit', 'bsn' => '', 'dateOfBirth' => '', 'activity' => ''], $plain['payee']);
		self::assertSame(['administrationId' => 'ADM-404', 'name' => '', 'loonheffingennummer' => '', 'postalAddress' => ''], $plain['administration']);
		self::assertSame([['paidOn' => '2026-01-31', 'description' => '', 'amount' => 100.0, 'expenseAllowance' => 0.0, 'total' => 100.0]], $plain['payments']);
		self::assertSame([100.0, 100], [$plain['totalPaid'], $plain['totalReported']]);
		self::assertSame([], $this->service->variables(payeeId: 'payee-plain', year: 2025)['payments']);
	}//end testTheWholeVariableSet()

	/**
	 * Without filinq the statement is skipped and says so; with one
	 * template it is generated with the variables and stored on the payee;
	 * with two templates it fails closed; a year without payments has
	 * nothing to state.
	 *
	 * @return void
	 */
	public function testGenerationThroughFilinq(): void {
		self::assertSame('skipped-no-filinq', $this->service->generate(payeeId: 'payee-lecturer', year: 2026, userId: 'payroll-1')['status']);

		$templates = new class {

			/**
			 * The templates filinq holds.
			 *
			 * @var list<array<string, mixed>>
			 */
			public array $rows = [['id' => 'tpl-1', 'name' => 'UBD jaaropgaaf', 'category' => 'ubd-jaaropgaaf'], ['id' => 'tpl-2', 'name' => 'Loonstrook', 'category' => 'loonstrook']];

			/**
			 * @param string $namespace The namespace.
			 *
			 * @return list<array<string, mixed>>
			 */
			public function getTemplatesByNamespace(string $namespace): array {
				return $namespace === 'hrmq' ? $this->rows : [];
			}//end getTemplatesByNamespace()

		};
		$documents = new class {

			/**
			 * The last call.
			 *
			 * @var array<string, mixed>
			 */
			public array $call = [];

			/**
			 * @param string               $templateId The template.
			 * @param array<int, mixed>    $dataRefs   The objects.
			 * @param array<string, mixed> $options    The options.
			 *
			 * @return array<string, string>
			 */
			public function generateDocument(string $templateId, array $dataRefs, array $options): array {
				$this->call = ['templateId' => $templateId, 'dataRefs' => $dataRefs, 'options' => $options];
				return ['content' => '%PDF-1.7 statement'];
			}//end generateDocument()

		};
		$files = new class {

			/**
			 * The stored files.
			 *
			 * @var list<array<string, string>>
			 */
			public array $added = [];

			/**
			 * @param string $objectId The object.
			 * @param string $fileName The file name.
			 * @param string $content  The content.
			 *
			 * @return null
			 */
			public function addFile(string $objectId, string $fileName, string $content): mixed {
				$this->added[] = ['objectId' => $objectId, 'fileName' => $fileName, 'content' => $content];
				return null;
			}//end addFile()

		};
		$this->container->set('OCA\Filinq\Service\TemplateService', $templates);
		$this->container->set('OCA\Filinq\Service\DocumentService', $documents);
		$this->container->set('OCA\OpenRegister\Service\FileService', $files);

		$outcome = $this->service->generate(payeeId: 'payee-lecturer', year: 2026, userId: 'payroll-1');

		self::assertSame('generated', $outcome['status']);
		self::assertSame('tpl-1', $documents->call['templateId']);
		self::assertSame([['register' => 'humaniq', 'schema' => 'ThirdPartyPayee', 'id' => 'payee-lecturer']], $documents->call['dataRefs']);
		self::assertSame(932, $documents->call['options']['adHocData']['statement']['totalReported']);
		self::assertSame([['objectId' => 'payee-lecturer', 'fileName' => 'ubd-jaaropgaaf-2026-dijk.pdf', 'content' => '%PDF-1.7 statement']], $files->added);

		self::assertSame('nothing-to-state', $this->service->generate(payeeId: 'payee-lecturer', year: 2024, userId: 'payroll-1')['status']);

		$templates->rows[] = ['id' => 'tpl-3', 'name' => 'UBD jaaropgaaf (oud)', 'category' => 'ubd-jaaropgaaf'];
		self::assertSame('failed', $this->service->generate(payeeId: 'payee-lecturer', year: 2026, userId: 'payroll-1')['status']);
	}//end testGenerationThroughFilinq()

	/**
	 * Generating for a report makes one statement per payee with payments
	 * in the report's administration and year, and reports each outcome.
	 *
	 * @return void
	 */
	public function testGenerationForAReport(): void {
		$this->store->seed('ThirdPartyPayee', 'payee-member', ['initials' => 'A.', 'lastName' => 'Bos', 'administrationId' => 'ADM-001']);
		$this->store->seed('ThirdPartyPayment', 'pay-3', ['payeeId' => 'payee-member', 'paidOn' => '2026-04-15', 'amount' => 275.75, 'administrationId' => 'ADM-001']);
		$this->store->seed('ThirdPartyPayment', 'pay-other', ['payeeId' => 'payee-other', 'paidOn' => '2026-05-01', 'amount' => 999.00, 'administrationId' => 'ADM-002']);

		$outcome = $this->service->generateForReport(report: ['id' => 'r-1', 'administrationId' => 'ADM-001', 'year' => 2026], userId: 'payroll-1');

		self::assertSame(0, $outcome['generated']);
		self::assertSame(['payee-lecturer', 'payee-member'], array_column($outcome['results'], 'payeeId'));
		self::assertSame(['skipped-no-filinq', 'skipped-no-filinq'], array_column($outcome['results'], 'status'));
	}//end testGenerationForAReport()

}//end class
