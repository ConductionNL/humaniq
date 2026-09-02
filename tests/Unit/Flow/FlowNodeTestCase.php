<?php

/**
 * Shared substrate for the payroll flow node tests
 *
 * Builds the node constructor's cross-cutting doubles (l10n echoing its
 * input, URL generator, container, SettingsService with OpenRegister
 * reported available, logger) and the suite's established fake
 * ObjectService double (the PayrollMutationServiceTest shape: `findAll()`
 * answers the seeded rows for the current schema, `saveObject()` records
 * every write) — mirroring the REAL call surface production code already
 * proves against a live OpenRegister, not a shape invented at the call
 * site.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Flow
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
 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Flow;

use OCA\Humaniq\Service\SettingsService;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Base test case wiring the node constructor doubles.
 */
abstract class FlowNodeTestCase extends TestCase {

	/**
	 * An IL10N double that echoes its input.
	 *
	 * @return IL10N
	 */
	protected function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, $parameters = []): string => vsprintf($text, is_array($parameters) === true ? $parameters : [$parameters])
		);

		return $l10n;
	}//end l10n()

	/**
	 * A URL generator double.
	 *
	 * @return IURLGenerator
	 */
	protected function urls(): IURLGenerator {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('imagePath')->willReturn('/apps/humaniq/img/app-dark.svg');

		return $urls;
	}//end urls()

	/**
	 * A SettingsService double reporting OpenRegister available.
	 *
	 * @return SettingsService
	 */
	protected function settings(): SettingsService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn(true);
		$settings->method('getRegisterSlug')->willReturn('humaniq');

		return $settings;
	}//end settings()

	/**
	 * A container double answering the ObjectService lookup with the given
	 * fake.
	 *
	 * @param object|null $objectService The fake ObjectService, when needed.
	 *
	 * @return ContainerInterface
	 */
	protected function container(?object $objectService = null): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		if ($objectService !== null) {
			$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($objectService);
		}

		return $container;
	}//end container()

	/**
	 * A logger double.
	 *
	 * @return LoggerInterface
	 */
	protected function logger(): LoggerInterface {
		return $this->createMock(LoggerInterface::class);
	}//end logger()

	/**
	 * The suite's established fake ObjectService double.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Seed rows keyed by schema.
	 *
	 * @return object The fake ObjectService.
	 */
	protected function fakeObjectService(array $rowsBySchema = []): object {
		return new class($rowsBySchema) {
			/**
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Every saveObject() call, as `['schema' => ..., 'uuid' => ..., 'object' => ...]`.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $saved = [];

			/**
			 * @param array<string, array<int, array<string, mixed>>> $rowsBySchema Seed rows keyed by schema.
			 */
			public function __construct(
				public array $rowsBySchema,
			) {

			}//end __construct()

			/**
			 * @param string $register Register slug (unused by the fake).
			 *
			 * @return self
			 */
			public function setRegister(string $register): self {
				return $this;
			}//end setRegister()

			/**
			 * @param string $schema Schema name.
			 *
			 * @return self
			 */
			public function setSchema(string $schema): self {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * @param array<string, mixed> $options Query options (unused by the fake).
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $options = []): array {
				return $this->rowsBySchema[$this->schema] ?? [];
			}//end findAll()

			/**
			 * @param array<string, mixed> $object The payload.
			 * @param string|null $register Register slug.
			 * @param string|null $schema Schema name.
			 * @param string|null $uuid Existing object id.
			 * @param bool $_rbac Unused by the fake.
			 * @param bool $_multitenancy Unused by the fake.
			 *
			 * @return array<string, mixed> The saved object.
			 */
			public function saveObject(
				array $object,
				?string $register = null,
				?string $schema = null,
				?string $uuid = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
			): array {
				$this->saved[] = [
					'schema' => (string)$schema,
					'uuid' => $uuid,
					'object' => $object,
				];

				return array_merge($object, ['id' => ($uuid ?? 'generated-1')]);
			}//end saveObject()
		};
	}//end fakeObjectService()

	/**
	 * One flow item carrying the given record.
	 *
	 * @param array<string, mixed> $json The record.
	 *
	 * @return array<string, mixed> The item.
	 */
	protected function item(array $json): array {
		return [
			'json' => $json,
			'binary' => [],
			'pairedItem' => null,
		];
	}//end item()

}//end class
