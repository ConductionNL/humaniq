<?php

/**
 * Payroll Flow Node Base
 *
 * Shared shape of the four payroll orchestration nodes humaniq contributes to
 * OpenRegister's flow engine (payroll-run-as-a-flow design.md D3). A node is
 * an ADAPTER: it renders its config against the item, hands the work to the
 * existing domain service, and puts the service's outcome back on the item.
 * No node re-implements a cent of payroll computation, and none resolves an
 * acting identity — the engine's RegistryStepDispatcher executes every
 * contributed node inside FlowRunAsScope, so self-wrapping here would
 * double-scope (REQ-PRF-006, the dossiq lesson the dispatcher docblock
 * records).
 *
 * Error posture is the engine's: a failed step THROWS so the step's `onError`
 * policy decides, because a swallowed failure is a silent pass-through whose
 * output key is simply absent (the DossiqFlowNodeBase rationale). An empty
 * firing returns empty — with no items there is no work, and the outcome
 * vocabulary belongs to items, not to an empty branch.
 *
 * @category Flow
 * @package  OCA\Humaniq\Flow
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
 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-006
 */

declare(strict_types=1);

namespace OCA\Humaniq\Flow;

use OCA\Humaniq\Service\SettingsService;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Base adapter for the humaniq payroll flow nodes.
 *
 * @psalm-suppress MissingDependency IFlowNode is OpenRegister's, loaded at
 *     runtime and suppressed as an undefined class in psalm.xml, so psalm
 *     cannot verify the implements-relationship here. The declared contract
 *     is real: the vendored test stub mirrors it method-for-method and the
 *     unit suite compiles against it.
 *
 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-001
 */
abstract class PayrollFlowNodeBase implements IFlowNode {

	/**
	 * Max PayrollRun objects scanned when resolving a run by id — the
	 * PayrollRunService::LIMIT convention.
	 *
	 * @var int
	 */
	protected const LIMIT = 10000;

	/**
	 * The `{{ dotted.path }}` placeholder shape, mirroring OpenRegister's
	 * FlowValueTemplate. Local on purpose: the engine's renderer is not a
	 * published seam for leaf apps, and two regex lines are cheaper than a
	 * cross-app class dependency (design.md D3).
	 *
	 * @var string
	 */
	private const PLACEHOLDER = '/\{\{\s*([A-Za-z0-9_@.]+)\s*\}\}/';

	/**
	 * @param IL10N $l10n Localisation for palette strings.
	 * @param IURLGenerator $urls URL generator for the node icon.
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param SettingsService $settingsService Register slug + OpenRegister availability.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		protected readonly IL10N $l10n,
		protected readonly IURLGenerator $urls,
		protected readonly ContainerInterface $container,
		protected readonly SettingsService $settingsService,
		protected readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The node icon: humaniq's own app icon, the dossiq node convention.
	 *
	 * @return string The icon URL.
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-001
	 */
	public function getIcon(): string {
		return $this->urls->imagePath('humaniq', 'app-dark.svg');
	}//end getIcon()

	/**
	 * Payroll steps carry no extra privilege of their own: available in both
	 * scopes, like every action-bearing node in the fleet. Authorisation is
	 * the acting identity's, applied by the dispatcher's FlowRunAsScope.
	 *
	 * @param int $scope The Nextcloud workflow scope constant.
	 *
	 * @return bool Whether the node is offered in this scope.
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-001
	 */
	public function isAvailableForScope(int $scope): bool {
		return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);
	}//end isAvailableForScope()

	/**
	 * Every config key of these nodes is optional with a documented default,
	 * so there is nothing to refuse at save time by default. Subclasses
	 * override when a literal value can be malformed.
	 *
	 * @param array $config The step's authored configuration.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $config is part of the
	 *     IFlowNode::validateConfig() contract; the base accepts every config.
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-001
	 */
	public function validateConfig(array $config): void {

	}//end validateConfig()

	/**
	 * Render one authored config value against the item's record: every
	 * `{{ dotted.path }}` is substituted from the item json, an unresolvable
	 * path becomes the empty string (the caller treats '' as "fall back to
	 * the default").
	 *
	 * @param string $value The authored value.
	 * @param array<string, mixed> $json The item's record.
	 *
	 * @return string The rendered value.
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-002
	 */
	protected function render(string $value, array $json): string {
		$rendered = preg_replace_callback(
			self::PLACEHOLDER,
			static function (array $match) use ($json): string {
				$cursor = $json;
				foreach (explode('.', $match[1]) as $segment) {
					if (is_array($cursor) === false || array_key_exists($segment, $cursor) === false) {
						return '';
					}

					$cursor = $cursor[$segment];
				}

				return is_scalar($cursor) === true ? (string)$cursor : '';
			},
			$value
		);

		return trim((string)$rendered);
	}//end render()

	/**
	 * Resolve the run id from config (default `{{ payroll.runId }}`, the key
	 * the calculate node writes) and load that PayrollRun, refusing loudly
	 * when it cannot be found — a step acting on a run that does not exist
	 * must not report success (design.md D3).
	 *
	 * @param array $config The step configuration.
	 * @param array<string, mixed> $json The item's record.
	 *
	 * @return array<string, mixed> The PayrollRun.
	 *
	 * @throws RuntimeException When no run id resolves or no run matches it.
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-003
	 */
	protected function requireRun(array $config, array $json): array {
		$runId = $this->render((string)($config['runId'] ?? '{{ payroll.runId }}'), $json);
		if ($runId === '') {
			throw new RuntimeException('Geen loonrun-id: de stap verwacht `runId` in de configuratie of `payroll.runId` op het item.');
		}

		$run = $this->findRun($runId);
		if ($run === null) {
			throw new RuntimeException('Loonrun ' . $runId . ' bestaat niet.');
		}

		return $run;
	}//end requireRun()

	/**
	 * The PayrollRun with the given id, or null (the
	 * PayrollRunService::recalculateRun scan idiom).
	 *
	 * @param string $runId The PayrollRun id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function findRun(string $runId): ?array {
		try {
			$rows = $this->objectService()->setRegister($this->register())->setSchema('PayrollRun')->findAll(['limit' => self::LIMIT]);
		} catch (\Throwable $e) {
			throw new RuntimeException('Loonrun ' . $runId . ' kon niet geladen worden: ' . $e->getMessage(), 0, $e);
		}

		foreach ((is_array($rows) === true ? $rows : []) as $row) {
			$run = $this->toArray($row);
			if ($this->idOf($run) === $runId) {
				return $run;
			}
		}

		return null;
	}//end findRun()

	/**
	 * Normalise an ObjectService row (entity or array) to an array — the
	 * PayrollRunService convention.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-001
	 */
	protected function toArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			return (array)$row->jsonSerialize();
		}

		return [];
	}//end toArray()

	/**
	 * The object id of a row, falling back to `@self.id`.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-001
	 */
	protected function idOf(array $row): string {
		return (string)($row['id'] ?? $row['@self']['id'] ?? '');
	}//end idOf()

	/**
	 * Place a service outcome on an item's record under this node's key.
	 *
	 * Into the record (`json`), not beside it: a key at the envelope level is
	 * invisible to a Switch and silently dropped by the next rebuild — the
	 * engine's own AwaitSignal node documents that trap.
	 *
	 * @param array<string, mixed> $item The item.
	 * @param string $key The outcome key.
	 * @param array<string, mixed> $outcome The service outcome.
	 *
	 * @return array<string, mixed> The item with the outcome merged in.
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-002
	 */
	protected function withOutcome(array $item, string $key, array $outcome): array {
		$json = (array)($item['json'] ?? []);
		$json[$key] = $outcome;
		$item['json'] = $json;

		return $item;
	}//end withOutcome()

	/**
	 * @return mixed The OpenRegister ObjectService (ADR-083: availability
	 *               established before reaching, so the refusal names the app
	 *               to install).
	 *
	 * @throws RuntimeException When OpenRegister is not installed.
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-001
	 */
	protected function objectService(): mixed {
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			throw new RuntimeException(
				'humaniq requires the OpenRegister app, which is not installed on this instance.'
			);
		}

		return $this->container->get('OCA\OpenRegister\Service\ObjectService');
	}//end objectService()

	/**
	 * @return string The configured humaniq register slug.
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-001
	 */
	protected function register(): string {
		return $this->settingsService->getRegisterSlug();
	}//end register()

}//end class
