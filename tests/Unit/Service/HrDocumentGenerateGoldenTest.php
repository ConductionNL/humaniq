<?php

/**
 * Golden test for HrDocumentService's document generation.
 *
 * Runs generate(), generateLoonstrook(), generateJaaropgaaf() and
 * generateUwvNotification() over fixed register rows and recording docudesk,
 * template, file and retention doubles. Between them the cases take every
 * exit of the shared generation pipeline: the blank employee, an already
 * generated and a stale pending document (keyed per contract, employee,
 * payslip, jaaropgaaf and sickness case), docudesk missing, every template
 * selection error, a render that throws or returns nothing, a file store
 * that throws or returns no path, and success. Every outcome, every saved
 * object, every collaborator call and every log line is compared with
 * tests/fixtures/golden/hr-document-generate.json. Set RECORD_GOLDEN=1 to
 * rewrite that file.
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
 * @spec openspec/specs/payslip-pdf-docudesk/spec.md#REQ-PPD-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\HrDocumentService;
use OCA\Humaniq\Service\PayrollRetentionGuardService;
use OCA\Humaniq\Service\SettingsService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Golden cases for HrDocumentService's generate entry points.
 *
 * @spec openspec/specs/payslip-pdf-docudesk/spec.md#REQ-PPD-002
 */
class HrDocumentGenerateGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/golden/hr-document-generate.json';

	/**
	 * Every collaborator call of the current case, in call order.
	 *
	 * @var list<mixed>
	 */
	public array $calls = [];

	/**
	 * The golden cases.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function cases(): array {
		$names = [
			'letters',
			'loonstrook',
			'jaaropgaaf',
			'uwv notification',
			'docudesk not installed',
			'docudesk services missing',
			'template selection',
			'render and store failures',
			'backlog refusals',
			'saves without an id',
		];
		return array_combine($names, array_map(static fn (string $n): array => [$n], $names));

	}//end cases()

	/**
	 * Run one case and compare it with the golden file.
	 *
	 * @param string $case The case name.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testGolden(string $case): void {
		$actual = json_encode($this->observe($case), (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
		$actual = (string)preg_replace('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z/', '(now)', (string)$actual);
		$actual = str_replace(gmdate('Y-m-d'), '(today)', $actual);
		$golden = (is_file(self::GOLDEN) === true ? json_decode((string)file_get_contents(self::GOLDEN), true) : []);

		if (getenv('RECORD_GOLDEN') === '1') {
			$golden[$case] = json_decode($actual, true);
			ksort($golden);
			$lines = [];
			foreach ($golden as $name => $recorded) {
				$lines[] = json_encode((string)$name) . ': ' . json_encode($recorded, (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
			}

			file_put_contents(self::GOLDEN, "{\n" . implode(",\n", $lines) . "\n}\n");
			$this->markTestSkipped('recorded');
		}

		$this->assertArrayHasKey($case, $golden);
		$this->assertSame(json_encode($golden[$case], (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)), $actual);

	}//end testGolden()

	/**
	 * Run one case: every entry-point call with its outcome, then the calls.
	 *
	 * @param string $case The case name.
	 *
	 * @return array<string, mixed>
	 */
	private function observe(string $case): array {
		$config = ['installed' => true, 'services' => true, 'configured' => ['arbeidsovereenkomst' => 't-letter', 'loonstrook' => 't-slip', 'jaaropgaaf' => 't-year', 'uwv-melding-42-weken' => 't-uwv', 'werkgeversverklaring' => 't-letter']];
		$service = null;
		$steps = [];
		switch ($case) {
			case 'letters':
				$steps = [
					['generate', 'emp-1', 'contract-1', 'arbeidsovereenkomst', 'hr-admin'],
					['generate', ' emp-1 ', '  ', 'werkgeversverklaring', null],
					['generate', '  ', 'contract-1', 'arbeidsovereenkomst', 'hr-admin'],
					['generate', 'emp-2', ' contract-done ', 'arbeidsovereenkomst', '  '],
					['generate', 'emp-2', 'contract-stale', 'arbeidsovereenkomst', 'hr-admin'],
					['generate', 'emp-2', null, 'werkgeversverklaring', 'hr-admin'],
					['generate', 'emp-3', null, 'arbeidsovereenkomst', null],
					['generate', 'emp-1', 'contract-1', 'getuigschrift', null],
					['generate', 'emp-2', null, 'getuigschrift', null],
				];
				break;
			case 'loonstrook':
				$steps = [
					['generateLoonstrook', 'payslip-1', 'hr-admin'],
					['generateLoonstrook', ' payslip-held ', null],
					['generateLoonstrook', '  ', null],
					['generateLoonstrook', 'payslip-missing', null],
					['generateLoonstrook', 'payslip-orphan', null],
					['generateLoonstrook', 'payslip-done', null],
				];
				break;
			case 'jaaropgaaf':
				$steps = [
					['generateJaaropgaaf', 'emp-1', 2026, 'hr-admin'],
					['generateJaaropgaaf', ' ', 2026, null],
					['generateJaaropgaaf', 'emp-9', 2026, null],
					['generateJaaropgaaf', 'emp-1', 2026, null],
				];
				break;
			case 'uwv notification':
				$steps = [
					['generateUwvNotification', 'case-open', 'hr-admin'],
					['generateUwvNotification', 'case-recovered', null],
					['generateUwvNotification', 'case-missing', null],
					['generateUwvNotification', 'case-done', null],
					['generateUwvNotification', 'case-nocontract', null],
				];
				break;
			case 'docudesk not installed':
				$config['installed'] = false;
				$steps = [['generate', 'emp-1', 'contract-1', 'arbeidsovereenkomst', null], ['generateLoonstrook', 'payslip-1', null]];
				break;
			case 'docudesk services missing':
				$config['services'] = false;
				$steps = [['generate', 'emp-1', 'contract-1', 'arbeidsovereenkomst', null]];
				break;
			case 'template selection':
				$config['configured'] = ['werkgeversverklaring' => '  t-trimmed  '];
				$steps = [
					['generate', 'emp-1', 'contract-1', 'werkgeversverklaring', null],
					['generate', 'emp-1', 'contract-1', 'arbeidsovereenkomst', null],
					['generate', 'emp-1', 'contract-1', 'getuigschrift', null],
					['generate', 'emp-1', 'contract-1', 'ontslagbrief', null],
					['generate', 'emp-1', 'contract-1', 'leeg', null],
					['generate', 'emp-1', 'contract-1', 'self-id', null],
				];
				break;
			case 'render and store failures':
				$config['configured'] = ['render-throws' => 't-throw', 'render-empty' => 't-empty', 'store-throws' => 't-ok', 'no-path' => 't-ok'];
				$steps = [
					['generate', 'emp-1', 'contract-1', 'render-throws', null],
					['generate', 'emp-1', 'contract-1', 'render-empty', null],
					['generate', 'emp-1', 'contract-1', 'store-throws', null],
					['generate', 'emp-4', null, 'no-path', null],
				];
				break;
			case 'saves without an id':
				$config['idless'] = ['Jaaropgaaf', 'HrGeneratedDocument'];
				$steps = [
					['generateJaaropgaaf', 'emp-1', 2026, null],
					['generate', 'emp-1', 'contract-1', 'arbeidsovereenkomst', null],
				];
				break;
			case 'backlog refusals':
				$steps = [
					['generateBacklog', 'arbeidsovereenkomst', null, '2026-05', null],
					['generateBacklog', 'loonstrook', null, null, '2026'],
					['generateBacklog', 'jaaropgaaf', null, null, null],
					['generateBacklog', 'werkgeversverklaring', null, null, null],
					['generateBacklog', 'werkgeversverklaring', 'emp-1', null, null],
					['generateBacklog', 'jaaropgaaf', 'emp-9', null, '2026'],
				];
				break;
		}//end switch

		$built = $this->service($config, ($case === 'template selection'));
		$service = $built[0];
		$runs = [];
		foreach ($steps as $step) {
			$method = array_shift($step);
			$this->calls[] = ['>>' => $method, 'args' => $step];
			$runs[] = $service->$method(...$step);
		}

		return ['runs' => $runs, 'calls' => $this->calls, 'rows' => $built[1]->rows];

	}//end observe()

	/**
	 * The register rows by schema.
	 *
	 * @return array<string, list<array<string, mixed>>>
	 */
	private function rows(): array {
		return [
			'Employee' => [
				['id' => 'emp-1', 'employeeNumber' => 'EMP-0001', 'lastName' => 'Jansen', 'bsn' => '123456782'],
				['id' => 'emp-2', 'employeeNumber' => '', 'lastName' => 'de Boer'],
				['@self' => ['id' => 'emp-3'], 'employeeNumber' => 'EMP-0003'],
			],
			'EmploymentContract' => [
				['id' => 'contract-1', 'employeeId' => 'emp-1', 'type' => 'permanent', 'writtenContract' => true, 'startDate' => '2020-01-01'],
				['id' => 'contract-done', 'employeeId' => 'emp-2', 'type' => 'permanent', 'writtenContract' => true, 'startDate' => '2021-01-01'],
				['id' => 'contract-stale', 'employeeId' => 'emp-2', 'type' => 'temporary', 'startDate' => '2025-01-01', 'endDate' => '2025-12-31'],
			],
			'Payslip' => [
				['id' => 'payslip-1', 'employeeId' => 'emp-1', 'period' => '2026-05', 'grossPay' => 3800.0, 'loonheffing' => 1102.0, 'nettoPay' => 2698.0],
				['id' => 'payslip-held', 'employeeId' => 'emp-1', 'period' => '2026-06', 'grossPay' => 3800.0, 'loonheffing' => 1102.0, 'nettoPay' => 2698.0],
				['id' => 'payslip-orphan', 'employeeId' => ' ', 'period' => '2026-05'],
				['id' => 'payslip-done', 'employeeId' => 'emp-2', 'period' => '2026-05', 'grossPay' => 1000.0],
				['id' => 'payslip-old', 'employeeId' => 'emp-1', 'period' => '2025-12', 'grossPay' => 999.0],
			],
			'HrGeneratedDocument' => [
				['id' => 'gd-done', 'documentType' => 'arbeidsovereenkomst', 'employeeId' => 'emp-2', 'contractId' => 'contract-done', 'status' => 'generated'],
				['id' => 'gd-stale', 'documentType' => 'arbeidsovereenkomst', 'employeeId' => 'emp-2', 'contractId' => 'contract-stale', 'status' => 'pending', '@self' => ['id' => 'gd-stale']],
				['id' => 'gd-employee', 'documentType' => 'werkgeversverklaring', 'employeeId' => 'emp-2', 'contractId' => '', 'status' => 'generated'],
				['id' => 'gd-contract-letter', 'documentType' => 'getuigschrift', 'employeeId' => 'emp-2', 'contractId' => 'contract-done', 'status' => 'generated'],
				['id' => 'gd-old-failed', 'documentType' => 'arbeidsovereenkomst', 'employeeId' => 'emp-3', 'contractId' => '', 'status' => 'failed'],
				['id' => 'gd-slip', 'documentType' => 'loonstrook', 'employeeId' => 'emp-2', 'payslipId' => 'payslip-done', 'status' => 'generated'],
				['id' => 'gd-uwv', 'documentType' => 'uwv-melding-42-weken', 'employeeId' => 'emp-2', 'sickLeaveCaseId' => 'case-nocontract', 'status' => 'pending'],
			],
			'SickLeaveCase' => [
				['id' => 'case-open', 'employeeId' => 'emp-1', 'firstSickDay' => '2026-01-05', 'status' => 'gemeld', 'administrationId' => 'ADM-1'],
				['id' => 'case-recovered', 'employeeId' => 'emp-1', 'firstSickDay' => '2026-01-05', 'status' => 'hersteld'],
				['id' => 'case-done', 'employeeId' => 'emp-1', 'firstSickDay' => '2026-01-05', 'status' => 'gemeld', 'uwv42WeekMeldingDone' => '2026-10-01'],
				['id' => 'case-nocontract', 'employeeId' => 'emp-2', 'firstSickDay' => '2019-01-05', 'status' => 'gemeld'],
			],
			'hrAdministration' => [
				['administrationId' => 'ADM-1', 'name' => 'Voorbeeld B.V.', 'loonheffingennummer' => '123456789L01'],
			],
			'Jaaropgaaf' => [],
		];

	}//end rows()

	/**
	 * Build the service and its object store over the case's configuration.
	 *
	 * @param array<string, mixed> $config Docudesk presence and configured templates.
	 * @param bool $discovery Whether the template namespace holds the discovery fixtures.
	 *
	 * @return array{0: HrDocumentService, 1: object}
	 */
	private function service(array $config, bool $discovery): array {
		$test = $this;
		$objects = new class($this->rows(), $test) {
			/**
			 * @var string
			 */
			private string $schema = '';

			/**
			 * @var int
			 */
			private int $nextId = 1;

			/**
			 * Schemas whose saved object comes back without an id.
			 *
			 * @var list<string>
			 */
			public array $idless = [];

			/**
			 * @param array<string, list<array<string, mixed>>> $rows Rows by schema.
			 * @param HrDocumentGenerateGoldenTest $test The recording test.
			 */
			public function __construct(public array $rows, private readonly HrDocumentGenerateGoldenTest $test) {
			}//end __construct()

			/**
			 * @param string $register The register.
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			/**
			 * @param string $schema The schema.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * @param array<string, mixed> $options The query options.
			 *
			 * @return list<array<string, mixed>>
			 */
			public function findAll(array $options = []): array {
				$this->test->calls[] = ['findAll' => $this->schema, 'options' => $options];
				return ($this->rows[$this->schema] ?? []);
			}//end findAll()

			/**
			 * @param string $id The id.
			 * @param string|null $register The register.
			 * @param string|null $schema The schema.
			 * @param bool $_rbac RBAC flag.
			 * @param bool $_multitenancy Multitenancy flag.
			 *
			 * @return object|null
			 */
			public function find(string $id, ?string $register = null, ?string $schema = null, bool $_rbac = true, bool $_multitenancy = true): ?object {
				$this->test->calls[] = ['find' => [$id, $register, $schema, $_rbac, $_multitenancy]];
				foreach (($this->rows[$schema] ?? []) as $row) {
					if ((string)($row['id'] ?? '') === $id) {
						return (object)['entity' => $id];
					}
				}

				return null;
			}//end find()

			/**
			 * @param array<string, mixed> $object The object.
			 * @param string|null $register The register.
			 * @param string|null $schema The schema.
			 * @param string|null $uuid The id when updating.
			 * @param bool $_rbac RBAC flag.
			 * @param bool $_multitenancy Multitenancy flag.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, ?string $register = null, ?string $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->test->calls[] = ['saveObject' => [$object, $register, $schema, $uuid, $_rbac, $_multitenancy]];
				$id = ($uuid ?? ('new-' . $schema . '-' . $this->nextId++));
				$saved = array_merge($object, ['id' => $id]);
				$rows = ($this->rows[$schema] ?? []);
				$found = false;
				foreach ($rows as $i => $row) {
					if ((string)($row['id'] ?? '') === $id) {
						$rows[$i] = $saved;
						$found = true;
					}
				}

				if ($found === false) {
					$rows[] = $saved;
				}

				$this->rows[$schema] = $rows;
				if (in_array($schema, $this->idless, true) === true) {
					unset($saved['id']);
				}

				return $saved;
			}//end saveObject()
		};
		$objects->idless = ($config['idless'] ?? []);

		$documents = new class($test) {
			/**
			 * @param HrDocumentGenerateGoldenTest $test The recording test.
			 */
			public function __construct(private readonly HrDocumentGenerateGoldenTest $test) {
			}//end __construct()

			/**
			 * @param string $templateId The template.
			 * @param array<int, mixed> $dataRefs The data refs.
			 * @param array<string, mixed> $options The options.
			 *
			 * @return array<string, mixed>
			 */
			public function generateDocument(string $templateId, array $dataRefs, array $options): array {
				$this->test->calls[] = ['generateDocument' => [$templateId, $dataRefs, $options]];
				if ($templateId === 't-throw') {
					throw new \RuntimeException('renderer down');
				}

				return ($templateId === 't-empty') ? ['content' => ''] : ['content' => '%PDF ' . $templateId];
			}//end generateDocument()
		};

		$templates = new class($test, $discovery) {
			/**
			 * @param HrDocumentGenerateGoldenTest $test The recording test.
			 * @param bool $discovery Whether to answer with the discovery fixtures.
			 */
			public function __construct(private readonly HrDocumentGenerateGoldenTest $test, private readonly bool $discovery) {
			}//end __construct()

			/**
			 * @param string $namespace The namespace.
			 *
			 * @return list<mixed>
			 */
			public function getTemplatesByNamespace(string $namespace): array {
				$this->test->calls[] = ['getTemplatesByNamespace' => $namespace];
				if ($this->discovery === false) {
					throw new \RuntimeException('no namespace');
				}

				return [
					['id' => 't-1', 'category' => 'arbeidsovereenkomst', 'name' => 'Contract'],
					['id' => 't-2', 'category' => 'getuigschrift', 'name' => 'Getuigschrift A'],
					['@self' => ['id' => 't-3'], 'category' => 'getuigschrift'],
					['category' => 'getuigschrift'],
					['category' => 'leeg', 'name' => 'Leeg'],
					['@self' => ['id' => 't-self'], 'category' => 'self-id'],
				];
			}//end getTemplatesByNamespace()
		};

		$files = new class($test) {
			/**
			 * @param HrDocumentGenerateGoldenTest $test The recording test.
			 */
			public function __construct(private readonly HrDocumentGenerateGoldenTest $test) {
			}//end __construct()

			/**
			 * @param mixed $objectEntity The object id.
			 * @param string $fileName The file name.
			 * @param string $content The content.
			 *
			 * @return object
			 */
			public function addFile(mixed $objectEntity, string $fileName, string $content): object {
				$this->test->calls[] = ['addFile' => [$objectEntity, $fileName, $content]];
				if (str_starts_with($fileName, 'store-throws') === true) {
					throw new \RuntimeException('disk full');
				}

				if (str_starts_with($fileName, 'no-path') === true) {
					return (object)[];
				}

				return new class($fileName) {
					/**
					 * @param string $name The file name.
					 */
					public function __construct(private readonly string $name) {
					}//end __construct()

					/**
					 * @return string
					 */
					public function getPath(): string {
						return '/humaniq/' . $this->name;
					}//end getPath()
				};
			}//end addFile()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $class) use ($objects, $documents, $templates, $files, $config) {
				$map = ['OCA\OpenRegister\Service\ObjectService' => $objects, 'OCA\OpenRegister\Service\FileService' => $files];
				if ($config['services'] === true) {
					$map['OCA\DocuDesk\Service\DocumentService'] = $documents;
					$map['OCA\DocuDesk\Service\TemplateService'] = $templates;
				}

				if (isset($map[$class]) === false) {
					throw new \RuntimeException('no ' . $class);
				}

				return $map[$class];
			}
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(
			function (string $app) use ($config): bool {
				$this->calls[] = ['isInstalled' => $app];
				return $config['installed'];
			}
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$settings->method('isOpenRegisterAvailable')->willReturn(true);
		$settings->method('getDocumentsTemplateId')->willReturnCallback(static fn (string $type): string => ($config['configured'][$type] ?? ''));
		$settings->method('getDocumentsEmployerBlock')->willReturn(['name' => 'Voorbeeld Werkgever B.V.']);

		$guard = $this->createMock(PayrollRetentionGuardService::class);
		$guard->method('isUnderActiveRetention')->willReturnCallback(
			function (mixed $entity): bool {
				$this->calls[] = ['isUnderActiveRetention' => $entity];
				return (($entity->entity ?? '') === 'payslip-held');
			}
		);
		$guard->method('inheritLegalHold')->willReturnCallback(
			function (mixed $entity, string $schema, string $source): bool {
				$this->calls[] = ['inheritLegalHold' => [$entity, $schema, $source]];
				return true;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->calls[] = ['log' => [$level, $message]];
				}
			);
		}

		return [new HrDocumentService($container, $appManager, $settings, $guard, $logger), $objects];

	}//end service()

}//end class
