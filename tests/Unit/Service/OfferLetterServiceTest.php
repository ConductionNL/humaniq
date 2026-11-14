<?php

/**
 * Unit tests for OfferLetterService.
 *
 * Pins the docudesk template selection (configured id first, then namespace
 * and category discovery, failing closed on a lookup error, zero matches,
 * several matches or a match without an id), the render options handed to
 * docudesk and the availability probe, all through the public
 * `generateAndStore()` and `available()` entry points.
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
 * @spec openspec/specs/offer-esign/spec.md#REQ-OFFR-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\DocudeskTemplates;
use OCA\Humaniq\Service\OfferLetterService;
use OCA\Humaniq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Tests for OfferLetterService.
 *
 * @spec openspec/specs/offer-esign/spec.md#REQ-OFFR-002
 */
class OfferLetterServiceTest extends TestCase {

	/**
	 * A configured template id wins over discovery; the render options carry
	 * only the employer block and generation metadata.
	 *
	 * @return void
	 */
	public function testConfiguredTemplateAndRenderOptions(): void {
		$documents = $this->fakeDocumentService();
		$service = $this->service($documents, $this->fakeTemplateService([['id' => 'found', 'category' => 'aanbiedingsbrief']]), 'T9');

		$result = $service->generateAndStore('app-1', 'vac-1', 'hr-1');

		$this->assertTrue($result['success']);
		$this->assertSame(77, $result['fileId']);
		$call = $documents->calls[0];
		$this->assertSame('T9', $call['templateId']);
		$this->assertSame('pdf', $call['options']['format']);
		$this->assertSame('hr-1', $call['options']['userId']);
		$this->assertSame(['name' => 'Werkgever B.V.'], $call['options']['adHocData']['employer']);
		$this->assertSame('aanbiedingsbrief', $call['options']['adHocData']['document']['type']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $call['options']['adHocData']['document']['requestedAt']);
		$this->assertSame(['format', 'userId', 'adHocData'], array_keys($call['options']));

	}//end testConfiguredTemplateAndRenderOptions()

	/**
	 * A blank or null acting user renders as 'system'.
	 *
	 * @return void
	 */
	public function testBlankUserRendersAsSystem(): void {
		$documents = $this->fakeDocumentService();
		$this->service($documents, $this->fakeTemplateService([]), 'T9')->generateAndStore('app-1', 'vac-1', '  ');
		$this->service($documents, $this->fakeTemplateService([]), 'T9')->generateAndStore('app-1', 'vac-1', null);

		$this->assertSame('system', $documents->calls[0]['options']['userId']);
		$this->assertSame('system', $documents->calls[1]['options']['userId']);

	}//end testBlankUserRendersAsSystem()

	/**
	 * Discovery keeps only the matching category, reading the template id
	 * from `id` or `@self.id`.
	 *
	 * @return void
	 */
	public function testDiscoverySelectsTheSingleMatchingCategory(): void {
		$documents = $this->fakeDocumentService();
		$templates = [
			['id' => 'other', 'category' => 'getuigschrift'],
			['@self' => ['id' => 'self-id'], 'category' => 'aanbiedingsbrief'],
		];

		$result = $this->service($documents, $this->fakeTemplateService($templates))->generateAndStore('app-1', 'vac-1', null);

		$this->assertTrue($result['success']);
		$this->assertSame('self-id', $documents->calls[0]['templateId']);
		$this->assertSame('hrmq', $this->lastNamespace);

	}//end testDiscoverySelectsTheSingleMatchingCategory()

	/**
	 * Every fail-closed discovery outcome, with its exact message.
	 *
	 * @return array<string, array{0: array<int, mixed>|null, 1: string}>
	 */
	public static function failClosedCases(): array {
		return [
			'lookup throws' => [null, 'Kon docudesk-sjablonen niet opzoeken: template register unavailable'],
			'no match' => [
				[['id' => 'x', 'category' => 'getuigschrift']],
				'Geen docudesk-sjabloon gevonden voor "aanbiedingsbrief" in namespace "hrmq"; genereren is geweigerd.',
			],
			'several matches' => [
				[['name' => 'Brief A', 'category' => 'aanbiedingsbrief'], ['id' => 'b', 'category' => 'aanbiedingsbrief'], ['@self' => ['id' => 'c'], 'category' => 'aanbiedingsbrief'], ['category' => 'aanbiedingsbrief']],
				'Meerdere docudesk-sjablonen (Brief A, b, c, ?) voor "aanbiedingsbrief" in namespace "hrmq"; genereren is geweigerd (nooit gokken tussen sjablonen die officiële documenten opleveren).',
			],
			'match without id' => [[['name' => 'Brief', 'category' => 'aanbiedingsbrief']], 'Het gevonden docudesk-sjabloon heeft geen id.'],
		];

	}//end failClosedCases()

	/**
	 * Discovery fails closed without rendering.
	 *
	 * @param array<int, mixed>|null $templates The discovered templates, null when the lookup throws.
	 * @param string                 $error     The expected failure message.
	 *
	 * @return void
	 *
	 * @dataProvider failClosedCases
	 */
	public function testDiscoveryFailsClosed(?array $templates, string $error): void {
		$documents = $this->fakeDocumentService();

		$result = $this->service($documents, $this->fakeTemplateService($templates ?? [], $templates === null))->generateAndStore('app-1', 'vac-1', null);

		$this->assertSame(['success' => false, 'fileId' => 0, 'fileName' => '', 'error' => $error], $result);
		$this->assertSame([], $documents->calls);

	}//end testDiscoveryFailsClosed()

	/**
	 * The availability probe needs both docudesk services.
	 *
	 * @return void
	 */
	public function testAvailableNeedsBothDocudeskServices(): void {
		$this->assertTrue($this->service($this->fakeDocumentService(), $this->fakeTemplateService([]))->available());
		$this->assertFalse($this->service($this->fakeDocumentService(), null)->available());
		$this->assertFalse($this->service(null, $this->fakeTemplateService([]))->available());

	}//end testAvailableNeedsBothDocudeskServices()

	/**
	 * The namespace the last fake template lookup was asked for.
	 *
	 * @var string|null
	 */
	public ?string $lastNamespace = null;

	/**
	 * Build the service over fake docudesk and file services.
	 *
	 * @param object|null $documents  The fake DocumentService, null when not registered.
	 * @param object|null $templates  The fake TemplateService, null when not registered.
	 * @param string      $configured The configured template id.
	 *
	 * @return OfferLetterService
	 */
	private function service(?object $documents, ?object $templates, string $configured = ''): OfferLetterService {
		$file = new class {
			/**
			 * @param mixed  $objectId The object the file is attached to.
			 * @param string $fileName The file name.
			 * @param string $content  The file content.
			 *
			 * @return object A fake File with id 77.
			 */
			public function addFile(mixed $objectId, string $fileName, string $content): object {
				return new class {
					/**
					 * @return int
					 */
					public function getId(): int {
						return 77;
					}
				};
			}
		};
		$registered = array_filter(
			[
				'OCA\OpenRegister\Service\FileService' => $file,
				'OCA\Filinq\Service\DocumentService' => $documents,
				'OCA\Filinq\Service\TemplateService' => $templates,
				'OCA\DocuDesk\Service\DocumentService' => $documents,
				'OCA\DocuDesk\Service\TemplateService' => $templates,
			]
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static fn (string $id): object => $registered[$id] ?? throw new \RuntimeException('not registered: ' . $id)
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$settings->method('getDocumentsTemplateId')->willReturnCallback(
			static fn (string $type): string => $type === 'aanbiedingsbrief' ? $configured : 'wrong-type'
		);
		$settings->method('getDocumentsEmployerBlock')->willReturn(['name' => 'Werkgever B.V.']);

		return new OfferLetterService($container, $settings, new DocudeskTemplates($container, $settings));

	}//end service()

	/**
	 * A fake docudesk DocumentService recording every render call.
	 *
	 * @return object
	 */
	private function fakeDocumentService(): object {
		return new class {
			/**
			 * @var array<int, array<string, mixed>>
			 */
			public array $calls = [];

			/**
			 * @param string               $templateId The template id.
			 * @param array<int, mixed>    $dataRefs   The data refs.
			 * @param array<string, mixed> $options    The render options.
			 *
			 * @return array<string, mixed>
			 */
			public function generateDocument(string $templateId, array $dataRefs, array $options): array {
				$this->calls[] = ['templateId' => $templateId, 'dataRefs' => $dataRefs, 'options' => $options];
				return ['content' => '%PDF-1.4 fake'];
			}
		};

	}//end fakeDocumentService()

	/**
	 * A fake docudesk TemplateService answering one template list.
	 *
	 * @param array<int, mixed> $templates The templates to answer.
	 * @param bool              $throws    Whether the lookup throws.
	 *
	 * @return object
	 */
	private function fakeTemplateService(array $templates, bool $throws = false): object {
		$test = $this;
		return new class($templates, $throws, $test) {
			/**
			 * @param array<int, mixed>      $templates The templates to answer.
			 * @param bool                   $throws    Whether the lookup throws.
			 * @param OfferLetterServiceTest $test      The test recording the namespace asked for.
			 */
			public function __construct(
				private readonly array $templates,
				private readonly bool $throws,
				private readonly OfferLetterServiceTest $test,
			) {
			}

			/**
			 * @param string $namespace The template namespace.
			 *
			 * @return array<int, mixed>
			 *
			 * @throws \RuntimeException When simulating an unresolvable template register.
			 */
			public function getTemplatesByNamespace(string $namespace): array {
				$this->test->lastNamespace = $namespace;
				if ($this->throws === true) {
					throw new \RuntimeException('template register unavailable');
				}

				return $this->templates;
			}
		};

	}//end fakeTemplateService()

}//end class
