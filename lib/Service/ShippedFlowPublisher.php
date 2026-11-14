<?php

/**
 * Humaniq Shipped Flow Publisher
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Makes sure every flow humaniq declares under `x-openregister-flows` in its
 * register fragments (`lib/Settings/register.d/*.json`) has a PUBLISHED
 * version that matches the shipped declaration, so a flow shipped after an
 * instance first installed humaniq reaches that instance on upgrade.
 *
 * Per declared flow, found by its import identity (app, name, trigger schema):
 *
 * - Not imported: reported, nothing done. The schema import creates it.
 * - Imported but never published (the import's version-one publish was
 *   refused): published, but only while the head still carries exactly the
 *   shipped nodes and edges. A head that differs was edited by a person and
 *   is left for them to publish.
 * - Published: handed to OpenRegister's `publishShippedUpdate()`, which owns
 *   the guards for that case (never over a person's own publish, never over
 *   an open draft, only when the graph changed). Older OpenRegister versions
 *   without it are reported as up to date.
 *
 * Enablement and ownership are never touched: a shipped flow stays disabled
 * until an administrator adopts it. A second run publishes nothing.
 *
 * @category Service
 * @package  OCA\Humaniq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/platform-hr-workflows/specs/hr-workflows/spec.md#REQ-HWF-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Publishes every humaniq-declared flow whose shipped graph is not yet published.
 *
 * @spec openspec/changes/platform-hr-workflows/specs/hr-workflows/spec.md#REQ-HWF-001
 */
class ShippedFlowPublisher {

	/**
	 * The app the shipped flows belong to.
	 *
	 * @var string
	 */
	public const FLOW_APP = 'humaniq';

	/**
	 * Constructor.
	 *
	 * @param OpenRegisterAvailability $openRegister Whether OpenRegister is installed.
	 * @param ContainerInterface       $container    Resolves OpenRegister's flow services lazily.
	 * @param FlowGraphKeypaths        $keypaths     Structural graph comparison.
	 * @param LoggerInterface          $logger       The logger.
	 * @param string|null              $fragmentDir  Where the register fragments live; the shipped directory when null.
	 *
	 * @spec openspec/changes/platform-hr-workflows/specs/hr-workflows/spec.md#REQ-HWF-001
	 */
	public function __construct(
		private readonly OpenRegisterAvailability $openRegister,
		private readonly ContainerInterface $container,
		private readonly FlowGraphKeypaths $keypaths,
		private readonly LoggerInterface $logger,
		private readonly ?string $fragmentDir=null,
	) {

	}//end __construct()

	/**
	 * Publish what needs publishing and report per flow.
	 *
	 * @return array<int, array{flow: string, status: string, detail: string}> One report per declared flow,
	 *                                                                          or one `skipped` report without OpenRegister.
	 *
	 * @spec openspec/changes/platform-hr-workflows/specs/hr-workflows/spec.md#REQ-HWF-001
	 */
	public function publishAll(): array {
		if ($this->openRegister->isAvailable() === false) {
			return [['flow' => '*', 'status' => 'skipped', 'detail' => 'OpenRegister is not installed or enabled.']];
		}

		$stored  = $this->container->get('OCA\OpenRegister\Db\FlowMapper')->findAllFlows(app: self::FLOW_APP, limit: 500);
		$reports = [];
		foreach ($this->declaredFlows() as $declared) {
			$reports[] = $this->publishOne(declared: $declared, stored: $stored);
		}

		return $reports;

	}//end publishAll()

	/**
	 * Every flow this app declares, with the schema it is declared on.
	 *
	 * @return array<int, array{schema: string, declaration: array<string, mixed>}> The declarations, in file order.
	 *
	 * @spec openspec/changes/platform-hr-workflows/specs/hr-workflows/spec.md#REQ-HWF-001
	 */
	public function declaredFlows(): array {
		$dir   = ($this->fragmentDir ?? (__DIR__ . '/../Settings/register.d'));
		$files = glob($dir . '/*.json');
		if ($files === false) {
			return [];
		}

		sort($files);
		$declared = [];
		foreach ($files as $file) {
			$fragment = json_decode((string)file_get_contents($file), true);
			$schemas  = ($fragment['components']['schemas'] ?? null);
			if (is_array($schemas) === false) {
				continue;
			}

			foreach ($schemas as $key => $schema) {
				$flows = ($schema['configuration']['x-openregister-flows'] ?? null);
				if (is_array($flows) === false) {
					continue;
				}

				foreach ($flows as $flow) {
					if (is_array($flow) === true && (string)($flow['app'] ?? '') === self::FLOW_APP && (string)($flow['name'] ?? '') !== '') {
						$declared[] = ['schema' => (string)($schema['slug'] ?? $key), 'declaration' => $flow];
					}
				}
			}
		}//end foreach

		return $declared;

	}//end declaredFlows()

	/**
	 * Bring one declared flow's published version in line, guarded.
	 *
	 * @param array{schema: string, declaration: array<string, mixed>} $declared The declared flow.
	 * @param iterable<object>                                         $stored   The app's stored flows.
	 *
	 * @return array{flow: string, status: string, detail: string} The report.
	 */
	private function publishOne(array $declared, iterable $stored): array {
		$name = (string)$declared['declaration']['name'];
		$flow = $this->storedFlow(name: $name, schema: $declared['schema'], stored: $stored);
		if ($flow === null) {
			return ['flow' => $name, 'status' => 'not-imported', 'detail' => 'Not imported on this instance yet.'];
		}

		try {
			return $this->publishStored(flow: $flow, name: $name, declaration: $declared['declaration']);
		} catch (Throwable $e) {
			$this->logger->warning('Humaniq: shipped flow "' . $name . '" could not be published', ['exception' => $e->getMessage()]);
			return ['flow' => $name, 'status' => 'failed', 'detail' => $e->getMessage()];
		}

	}//end publishOne()

	/**
	 * Publish an imported flow's shipped head when that is safe.
	 *
	 * @param object               $flow        The stored flow.
	 * @param string               $name        The flow name, for the report.
	 * @param array<string, mixed> $declaration The shipped declaration.
	 *
	 * @return array{flow: string, status: string, detail: string} The report.
	 */
	private function publishStored(object $flow, string $name, array $declaration): array {
		$versions  = $this->container->get('OCA\OpenRegister\Service\Flow\FlowVersionService');
		$published = $this->container->get('OCA\OpenRegister\Service\Flow\FlowPublishedGraph')->graphOf((string)$flow->getUuid());

		if (is_array($published) === true) {
			if (method_exists($versions, 'publishShippedUpdate') === false) {
				return ['flow' => $name, 'status' => 'ok', 'detail' => 'Published.'];
			}

			if ($versions->publishShippedUpdate(flow: $flow) === null) {
				return ['flow' => $name, 'status' => 'ok', 'detail' => 'Published version is current, or kept by a person.'];
			}

			return ['flow' => $name, 'status' => 'republished', 'detail' => 'Published the shipped update.'];
		}

		$headMatches = $this->keypaths->sameStructure((array)($flow->getNodes() ?? []), (array)($declaration['nodes'] ?? []))
			&& $this->keypaths->sameStructure((array)($flow->getEdges() ?? []), (array)($declaration['edges'] ?? []));
		if ($headMatches === false) {
			return ['flow' => $name, 'status' => 'left-alone', 'detail' => 'Never published and edited since import; publish it from the flow builder.'];
		}

		$versions->publish(flow: $flow);

		return ['flow' => $name, 'status' => 'published', 'detail' => 'Published the shipped declaration.'];

	}//end publishStored()

	/**
	 * Find a stored flow by its import identity.
	 *
	 * @param string           $name   The flow name.
	 * @param string           $schema The trigger schema slug.
	 * @param iterable<object> $stored The app's stored flows.
	 *
	 * @return object|null The flow, or null when not imported.
	 */
	private function storedFlow(string $name, string $schema, iterable $stored): ?object {
		foreach ($stored as $candidate) {
			if ((string)$candidate->getName() === $name && (string)$candidate->getTriggerSchema() === $schema) {
				return $candidate;
			}
		}

		return null;

	}//end storedFlow()

}//end class
