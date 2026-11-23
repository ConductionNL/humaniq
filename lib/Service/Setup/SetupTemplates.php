<?php
/**
 * Humaniq SetupTemplates.
 *
 * The setup wizard's Sjablonen step, server side. It lists the configuration
 * sets the Marketplace page lists and installs a picked one, both through the
 * store controller OpenRegister binds for this app. humaniq holds no store or
 * install code of its own: this class only asks that controller and records
 * what the administrator answered.
 *
 * @category Service
 * @package  OCA\Humaniq\Service\Setup
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/configuration-store/specs/first-time-setup/spec.md#requirement-the-setup-wizard-shall-offer-a-sjablonen-step-that-installs-chosen-sets-req-fts-007
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service\Setup;

use OCA\Humaniq\AppInfo\Application;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Lists, installs and records the configuration sets of the Sjablonen step.
 *
 * @spec openspec/changes/configuration-store/specs/first-time-setup/spec.md#requirement-the-setup-wizard-shall-offer-a-sjablonen-step-that-installs-chosen-sets-req-fts-007
 */
class SetupTemplates {

	/**
	 * The store controller OpenRegister binds under this app's namespace.
	 *
	 * @var string
	 */
	public const STORE_CONTROLLER = 'OCA\\Humaniq\\Controller\\StoreController';

	/**
	 * App-config key holding the step's answer: a JSON list of installed set slugs, or `["none"]`.
	 *
	 * It is also the step's `configKey`, so the wizard's own save and an install write the same key.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'setup_templates';

	/**
	 * The card that installs nothing.
	 *
	 * @var string
	 */
	public const NONE = 'none';

	/**
	 * The slug shape the store's install route accepts.
	 *
	 * @var string
	 */
	private const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]*[a-z0-9]$/';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's store controller for this app.
	 * @param IAppConfig         $appConfig Records the answer.
	 * @param LoggerInterface    $logger    Records a failing store.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's cards: "no templates" first, then the sets the store lists.
	 *
	 * A missing or failing store leaves only the "no templates" card, so the
	 * step can always be answered and never blocks the app.
	 *
	 * @return array<int,array{id:string,name:string,description:string}>
	 *
	 * @spec openspec/changes/configuration-store/specs/first-time-setup/spec.md#requirement-the-setup-wizard-shall-offer-a-sjablonen-step-that-installs-chosen-sets-req-fts-007
	 */
	public function choices(): array {
		$choices = [[
			'id'          => self::NONE,
			'name'        => 'No templates',
			'description' => 'Start without a published set. You can install one later from the Marketplace.',
		],
		];

		$data = $this->ask(call: static fn (object $store): mixed => $store->search());
		if ($data === null) {
			return $choices;
		}

		$seen = [self::NONE => true];
		foreach ([...self::listOf(value: ($data['builtIn'] ?? [])), ...self::listOf(value: ($data['cards'] ?? []))] as $item) {
			$choice = self::choiceOf(item: $item);
			if ($choice === null || isset($seen[$choice['id']]) === true) {
				continue;
			}

			$seen[$choice['id']] = true;
			$choices[] = $choice;
		}

		return $choices;
	}//end choices()

	/**
	 * Whether the step holds an answer: a set installed, or "no templates" picked.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/configuration-store/specs/first-time-setup/spec.md#scenario-a-skipped-step-stays-closed
	 */
	public function isAnswered(): bool {
		return $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_KEY, '') !== '';
	}//end isAnswered()

	/**
	 * Record "no templates" as the step's answer.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/configuration-store/specs/first-time-setup/spec.md#scenario-a-skipped-step-stays-closed
	 */
	public function skip(): void {
		if ($this->installed() === []) {
			$this->appConfig->setValueString(Application::APP_ID, self::CONFIG_KEY, (string)json_encode([self::NONE]));
		}
	}//end skip()

	/**
	 * Install one set through the store and record it, or record a skip for "none".
	 *
	 * @param mixed $slug The posted card value.
	 *
	 * @return array{success:bool,message:string,status:int}
	 *
	 * @spec openspec/changes/configuration-store/specs/first-time-setup/spec.md#scenario-picked-templates-show-in-the-summary
	 */
	public function install(mixed $slug): array {
		if ($slug === self::NONE) {
			$this->skip();

			return ['success' => true, 'message' => 'No template installed, as chosen.', 'status' => 200];
		}

		if (is_string($slug) === false || preg_match(self::SLUG_PATTERN, $slug) !== 1) {
			return ['success' => false, 'message' => 'Pick a template first.', 'status' => 400];
		}

		$response = $this->respond(call: static fn (object $store): mixed => $store->install($slug));
		if ($response === null) {
			return ['success' => false, 'message' => 'The store is not available on this server.', 'status' => 503];
		}

		$data = $response->getData();
		if ($response->getStatus() >= 400 || (is_array($data) === true && ($data['success'] ?? true) === false)) {
			$message = 'The template could not be installed.';
			if (is_array($data) === true && is_string($data['message'] ?? null) === true) {
				$message = $data['message'];
			}

			return ['success' => false, 'message' => $message, 'status' => max($response->getStatus(), 400)];
		}

		$this->record(slug: $slug);

		return ['success' => true, 'message' => 'Template installed.', 'status' => 200];
	}//end install()

	/**
	 * The slugs of the sets the step installed, "none" left out.
	 *
	 * @return array<int,string>
	 *
	 * @spec openspec/changes/configuration-store/specs/first-time-setup/spec.md#scenario-picked-templates-show-in-the-summary
	 */
	public function installed(): array {
		$stored = json_decode($this->appConfig->getValueString(Application::APP_ID, self::CONFIG_KEY, ''), true);
		if (is_array($stored) === false) {
			return [];
		}

		return array_values(array_filter($stored, static fn (mixed $slug): bool => is_string($slug) === true && $slug !== self::NONE));
	}//end installed()

	/**
	 * Add an installed slug to the answer, replacing an earlier "none".
	 *
	 * @param string $slug The installed set.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/configuration-store/specs/first-time-setup/spec.md#scenario-picked-templates-show-in-the-summary
	 */
	private function record(string $slug): void {
		$installed = $this->installed();
		if (in_array($slug, $installed, true) === false) {
			$installed[] = $slug;
		}

		$this->appConfig->setValueString(Application::APP_ID, self::CONFIG_KEY, (string)json_encode($installed));
	}//end record()

	/**
	 * The data of the store's answer to a call, or null when there is no store or it failed.
	 *
	 * @param callable $call Receives the store controller.
	 *
	 * @return array<string,mixed>|null
	 *
	 * @spec openspec/changes/configuration-store/specs/first-time-setup/spec.md#requirement-the-setup-wizard-shall-offer-a-sjablonen-step-that-installs-chosen-sets-req-fts-007
	 */
	private function ask(callable $call): ?array {
		$response = $this->respond(call: $call);
		if ($response === null || $response->getStatus() >= 400) {
			return null;
		}

		$data = $response->getData();
		if (is_array($data) === false) {
			return null;
		}

		return $data;
	}//end ask()

	/**
	 * Run a call on the store controller, or null when there is none or it threw.
	 *
	 * @param callable $call Receives the store controller.
	 *
	 * @return JSONResponse|null
	 *
	 * @spec openspec/changes/configuration-store/specs/first-time-setup/spec.md#requirement-the-setup-wizard-shall-offer-a-sjablonen-step-that-installs-chosen-sets-req-fts-007
	 */
	private function respond(callable $call): ?JSONResponse {
		try {
			if ($this->container->has(self::STORE_CONTROLLER) === false) {
				return null;
			}

			$response = $call($this->container->get(self::STORE_CONTROLLER));
		} catch (\Throwable $e) {
			$this->logger->warning('Setup: the configuration store failed: ' . $e::class);

			return null;
		}

		if ($response instanceof JSONResponse === false) {
			return null;
		}

		return $response;
	}//end respond()

	/**
	 * A list of arrays out of a value that should be one.
	 *
	 * @param mixed $value The value.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/changes/configuration-store/specs/first-time-setup/spec.md#requirement-the-setup-wizard-shall-offer-a-sjablonen-step-that-installs-chosen-sets-req-fts-007
	 */
	private static function listOf(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		return array_values(array_filter($value, static fn (mixed $item): bool => is_array($item) === true));
	}//end listOf()

	/**
	 * One card of the step from one store item, or null for an item without a usable slug.
	 *
	 * @param array<string,mixed> $item The store item.
	 *
	 * @return array{id:string,name:string,description:string}|null
	 *
	 * @spec openspec/changes/configuration-store/specs/first-time-setup/spec.md#requirement-the-setup-wizard-shall-offer-a-sjablonen-step-that-installs-chosen-sets-req-fts-007
	 */
	private static function choiceOf(array $item): ?array {
		$slug = $item['slug'] ?? null;
		if (is_string($slug) === false || preg_match(self::SLUG_PATTERN, $slug) !== 1) {
			return null;
		}

		$name = $slug;
		if (is_string($item['title'] ?? null) === true && trim($item['title']) !== '') {
			$name = trim($item['title']);
		}

		$description = '';
		if (is_string($item['description'] ?? null) === true) {
			$description = $item['description'];
		}

		return ['id' => $slug, 'name' => $name, 'description' => $description];
	}//end choiceOf()
}//end class
