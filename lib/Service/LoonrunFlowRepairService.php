<?php

/**
 * Loonrun Flow Repair Service
 *
 * The v1 `Loonrun` declaration shipped its switch-gate condition as
 * `{"var": "review.outcome"}`. OpenRegister's `UserTaskNode::placeOutcome()`
 * writes the outcome bag INTO the item's record (`json.<outcomeKey>`), and
 * `FlowExpression::dataFor()` hands a condition the record under the `json`
 * key of its data document — so the shipped path resolved to null, null is
 * false, and every review (approved or rejected) took the unconditioned exit
 * to `end-rejected`. Rig-reproduced twice on HQ#291; corrected to
 * `json.review.outcome` the full chain runs green.
 *
 * The corrected declaration re-imports on upgrade, but OpenRegister's
 * `SchemaFlowImportListener` only publishes version 1 of a FRESH import: a
 * re-import updates the flow's editable head in place and the broken
 * PUBLISHED version keeps backing every run. This service closes that gap,
 * with deliberately narrow semantics (payroll-run-as-a-flow design.md D8):
 *
 * - The flow is found by its import identity — (app, name, trigger schema),
 *   the `SchemaFlowImportListener::upsert()` key — never by uuid, which the
 *   declaration does not carry.
 * - ONLY an unmodified v1 publish is republished: the published graph must
 *   deep-equal the shipped declaration with the gate keypath rewritten back
 *   to the v1 form (derived from the shipped JSON at run time, so the
 *   comparison cannot drift from the file). Anything else was edited or
 *   republished by a person and is reported and left alone.
 * - A flow whose head sits in `draft` is never published over: somebody is
 *   mid-edit, and an upgrade must not ship their unfinished work.
 * - Enablement and ownership are untouched, the same rule the import
 *   listener follows on update.
 * - Idempotent: once the published gate reads the corrected keypath, every
 *   subsequent run is a reported no-op.
 *
 * The graph arithmetic (detection, the two rewrites, structural equality)
 * lives in {@see FlowGraphKeypaths}, testable without OpenRegister.
 *
 * @category Service
 * @package  OCA\Humaniq\Service
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
 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-007
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Republishes the shipped Loonrun flow when its published gate still reads
 * the v1 keypath, leaving modified graphs and open drafts alone.
 *
 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-007
 */
class LoonrunFlowRepairService {

	/**
	 * The shipped flow's import identity (SchemaFlowImportListener's key).
	 *
	 * @var string
	 */
	public const FLOW_APP = 'humaniq';

	/**
	 * The shipped flow's name.
	 *
	 * @var string
	 */
	public const FLOW_NAME = 'Loonrun';

	/**
	 * The schema whose save imports the declaration.
	 *
	 * @var string
	 */
	public const FLOW_TRIGGER_SCHEMA = 'PayrollRun';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService OpenRegister availability.
	 * @param ContainerInterface $container Resolves OpenRegister's flow store lazily.
	 * @param FlowGraphKeypaths $keypaths The graph arithmetic.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-007
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly ContainerInterface $container,
		private readonly FlowGraphKeypaths $keypaths,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Run the repair once, idempotently.
	 *
	 * @return array{status: string, detail: string} What happened, for the repair output.
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-007
	 */
	public function repair(): array {
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			return ['status' => 'skipped', 'detail' => 'OpenRegister is not installed or enabled.'];
		}

		$flow = $this->shippedFlow();
		if ($flow === null) {
			return ['status' => 'skipped', 'detail' => 'The shipped Loonrun flow is not imported on this instance.'];
		}

		$published = $this->publishedGraph((string)$flow->getUuid());
		if ($published === null) {
			return ['status' => 'skipped', 'detail' => 'Loonrun has no published version. The corrected head publishes on adoption.'];
		}

		$publishedNodes = (array)($published['nodes'] ?? []);
		$outcomeKeys = $this->keypaths->outcomeKeys($publishedNodes);
		if ($this->keypaths->carriesUnprefixedOutcomePath($publishedNodes, $outcomeKeys) === false) {
			return ['status' => 'ok', 'detail' => 'The published Loonrun gate already reads the record keypath. Nothing to do.'];
		}

		if ($this->matchesShippedV1($published) === false) {
			return [
				'status' => 'left-alone',
				'detail' => 'The published Loonrun was changed after import. Fix the gate condition to read '
					. FlowGraphKeypaths::JSON_PREFIX . 'review.outcome in the flow builder, then publish.',
			];
		}

		if ((string)$flow->getLifecycleStatus() === 'draft') {
			return [
				'status' => 'left-alone',
				'detail' => 'Loonrun has an open draft. Publishing now would ship an unfinished edit; publish the corrected gate from the builder.',
			];
		}

		$this->republish($flow);

		return [
			'status' => 'republished',
			'detail' => 'Published Loonrun v' . (int)$flow->getVersion() . ' with the gate reading the record keypath.',
		];

	}//end repair()

	/**
	 * The stored flow matching the shipped declaration's import identity.
	 *
	 * @return object|null The flow entity, or null when never imported.
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-007
	 */
	private function shippedFlow(): ?object {
		$mapper = $this->container->get('OCA\OpenRegister\Db\FlowMapper');

		foreach ($mapper->findAllFlows(app: self::FLOW_APP, limit: 500) as $candidate) {
			if ((string)$candidate->getName() === self::FLOW_NAME
				&& (string)$candidate->getTriggerSchema() === self::FLOW_TRIGGER_SCHEMA
			) {
				return $candidate;
			}
		}

		return null;

	}//end shippedFlow()

	/**
	 * The published graph of a flow, or null when nothing is published.
	 *
	 * @param string $flowUuid The flow.
	 *
	 * @return array<string, mixed>|null The published graph document.
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-007
	 */
	private function publishedGraph(string $flowUuid): ?array {
		$graph = $this->container->get('OCA\OpenRegister\Service\Flow\FlowPublishedGraph')
			->graphOf($flowUuid);

		if (is_array($graph) === false) {
			return null;
		}

		return $graph;

	}//end publishedGraph()

	/**
	 * Whether the published graph is the v1 import, untouched.
	 *
	 * The expectation is DERIVED: the shipped declaration's nodes with every
	 * `json.`-prefixed outcome keypath rewritten back to the v1 form. A
	 * hardcoded copy of the v1 graph would be a second source of truth to
	 * drift; the file plus the one known rewrite cannot.
	 *
	 * @param array<string, mixed> $published The published graph document.
	 *
	 * @return bool True when nodes and edges equal the v1 import exactly.
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-007
	 */
	private function matchesShippedV1(array $published): bool {
		$declaration = $this->shippedDeclaration();
		if ($declaration === null) {
			return false;
		}

		$shippedNodes = (array)($declaration['nodes'] ?? []);
		$v1Nodes = $this->keypaths->withV1OutcomePaths($shippedNodes, $this->keypaths->outcomeKeys($shippedNodes));

		return $this->keypaths->sameStructure((array)($published['nodes'] ?? []), $v1Nodes)
			&& $this->keypaths->sameStructure((array)($published['edges'] ?? []), (array)($declaration['edges'] ?? []));

	}//end matchesShippedV1()

	/**
	 * The shipped Loonrun declaration, read from the register fragment.
	 *
	 * @return array<string, mixed>|null The declaration, or null when unreadable.
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-007
	 */
	private function shippedDeclaration(): ?array {
		$path = (__DIR__ . '/../Settings/register.d/hr-objects.json');
		$raw = file_get_contents($path);
		if ($raw === false) {
			return null;
		}

		$fragment = json_decode($raw, true);
		if (is_array($fragment) === false) {
			return null;
		}

		$flows = ($fragment['components']['schemas'][self::FLOW_TRIGGER_SCHEMA]['configuration']['x-openregister-flows'] ?? null);
		if (is_array($flows) === false) {
			return null;
		}

		foreach ($flows as $declaration) {
			if (is_array($declaration) === true && (string)($declaration['name'] ?? '') === self::FLOW_NAME) {
				return $declaration;
			}
		}

		return null;

	}//end shippedDeclaration()

	/**
	 * Republish the corrected head, mirroring the builder's own sequence:
	 * draft the published graph at N+1, then publish the head.
	 *
	 * The head was already corrected by the schema re-import (this step runs
	 * after `InitializeRegister`); when it somehow still carries the v1
	 * keypath, it is rewritten here first — belt and braces, never the
	 * expected path.
	 *
	 * @param object $flow The flow entity.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the head still routes approvals wrongly after the rewrite.
	 *
	 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-007
	 */
	private function republish(object $flow): void {
		$versions = $this->container->get('OCA\OpenRegister\Service\Flow\FlowVersionService');
		$versions->createDraft(flow: $flow);

		$nodes = (array)($flow->getNodes() ?? []);
		$keys = $this->keypaths->outcomeKeys($nodes);
		if ($this->keypaths->carriesUnprefixedOutcomePath($nodes, $keys) === true) {
			$flow->setNodes($this->keypaths->withJsonOutcomePaths($nodes, $keys));
			$this->container->get('OCA\OpenRegister\Db\FlowMapper')->update($flow);
			$this->logger->info('Humaniq: the Loonrun head still carried the v1 gate keypath and was rewritten before publishing.');
		}

		if ($this->keypaths->carriesUnprefixedOutcomePath((array)($flow->getNodes() ?? []), $keys) === true) {
			throw new RuntimeException('The Loonrun head still reads an outcome beside the record after the rewrite.');
		}

		$versions->publish(flow: $flow, publishedBy: null);

	}//end republish()

}//end class
