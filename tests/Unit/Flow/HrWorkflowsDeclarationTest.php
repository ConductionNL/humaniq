<?php

/**
 * Structural guard over the shipped HR flows: Indiensttreding,
 * Uitdiensttreding and Verlofaanvraag blijft liggen.
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
 * @spec openspec/changes/platform-hr-workflows/specs/hr-workflows/spec.md#REQ-HWF-001
 * @spec openspec/changes/platform-hr-workflows/specs/hr-workflows/spec.md#REQ-HWF-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Flow;

use PHPUnit\Framework\TestCase;

/**
 * The three HR flows are wired, start on their own schema, and only write fields their case has.
 *
 * @coversNothing
 */
class HrWorkflowsDeclarationTest extends TestCase {

	/**
	 * The flows under test: fragment, schema, flow name, trigger event.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
	 */
	public static function flows(): array {
		return [
			'onboarding'       => ['hr-onboarding.json', 'Onboarding', 'Indiensttreding', 'object.created'],
			'offboarding'      => ['hr-onboarding.json', 'Offboarding', 'Uitdiensttreding', 'object.created'],
			'leave escalation' => ['hr-leave.json', 'LeaveRequest', 'Verlofaanvraag blijft liggen', 'object.updated'],
		];

	}//end flows()

	/**
	 * Load a schema from a fragment.
	 *
	 * @param string $fragment The fragment file.
	 * @param string $schema   The schema key.
	 *
	 * @return array<string, mixed> The schema.
	 */
	private function schema(string $fragment, string $schema): array {
		$data = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/register.d/' . $fragment), true);
		self::assertIsArray($data, $fragment . ' must parse');
		return $data['components']['schemas'][$schema];

	}//end schema()

	/**
	 * Find the named flow on a schema.
	 *
	 * @param array<string, mixed> $schema The schema.
	 * @param string               $name   The flow name.
	 *
	 * @return array<string, mixed> The flow declaration.
	 */
	private function flow(array $schema, string $name): array {
		foreach (($schema['configuration']['x-openregister-flows'] ?? []) as $flow) {
			if ($flow['name'] === $name) {
				return $flow;
			}
		}

		self::fail('Flow ' . $name . ' is not declared');

	}//end flow()

	/**
	 * Nodes keyed by id.
	 *
	 * @param array<string, mixed> $flow The flow.
	 *
	 * @return array<string, array<string, mixed>> The nodes.
	 */
	private function nodes(array $flow): array {
		$nodes = [];
		foreach ($flow['nodes'] as $node) {
			$nodes[$node['id']] = $node;
		}

		return $nodes;

	}//end nodes()

	/**
	 * The flow starts on its own schema, belongs to humaniq and arrives inert.
	 *
	 * @param string $fragment The fragment file.
	 * @param string $schema   The schema key.
	 * @param string $name     The flow name.
	 * @param string $event    The trigger event.
	 *
	 * @return void
	 *
	 * @dataProvider flows
	 */
	public function testTheFlowStartsOnItsSchemaAndArrivesInert(string $fragment, string $schema, string $name, string $event): void {
		$flow = $this->flow($this->schema($fragment, $schema), $name);

		self::assertSame('humaniq', $flow['app']);
		self::assertSame($event, $flow['trigger']);
		self::assertSame('humaniq', $flow['triggerRegister']);
		self::assertArrayNotHasKey('enabled', $flow, 'a shipped flow must arrive disabled');
		self::assertStringContainsString('arrives disabled', $flow['description']);

		$triggers = array_values(array_filter($flow['nodes'], static fn (array $n): bool => $n['type'] === 'openregister.trigger-object'));
		self::assertCount(1, $triggers);
		self::assertSame(['event' => $event, 'register' => 'humaniq', 'schema' => $schema], $triggers[0]['config']);

	}//end testTheFlowStartsOnItsSchemaAndArrivesInert()

	/**
	 * Every edge joins two declared nodes, every switch exit is wired once and
	 * carries an unconditioned else, and every non-end node has a way out.
	 *
	 * @param string $fragment The fragment file.
	 * @param string $schema   The schema key.
	 * @param string $name     The flow name.
	 *
	 * @return void
	 *
	 * @dataProvider flows
	 */
	public function testTheGraphIsWired(string $fragment, string $schema, string $name): void {
		$flow  = $this->flow($this->schema($fragment, $schema), $name);
		$nodes = $this->nodes($flow);
		self::assertCount(count($flow['nodes']), $nodes, 'node ids must be unique');

		$out = [];
		foreach ($flow['edges'] as $edge) {
			self::assertArrayHasKey($edge['from'], $nodes, 'edge ' . $edge['id'] . ' starts nowhere');
			self::assertArrayHasKey($edge['to'], $nodes, 'edge ' . $edge['id'] . ' ends nowhere');
			$out[$edge['from']][] = ($edge['fromExit'] ?? '');
		}

		foreach ($nodes as $id => $node) {
			if ($node['type'] === 'openregister.end') {
				self::assertArrayNotHasKey($id, $out);
				continue;
			}

			self::assertArrayHasKey($id, $out, $id . ' is a dead end');
			if ($node['type'] !== 'openregister.switch') {
				self::assertSame([''], $out[$id], $id . ' must have exactly one outgoing edge');
				continue;
			}

			$exits = array_column($node['exits'], 'id');
			sort($exits);
			$wired = $out[$id];
			sort($wired);
			self::assertSame($exits, $wired, $id . ' must wire each exit once');
			self::assertCount(1, array_filter($node['exits'], static fn (array $x): bool => isset($x['condition']) === false), $id . ' needs one else exit');
		}

	}//end testTheGraphIsWired()

	/**
	 * Every task names a group and can be marked done, and every gate reads
	 * the outcome where the engine writes it.
	 *
	 * @param string $fragment The fragment file.
	 * @param string $schema   The schema key.
	 * @param string $name     The flow name.
	 *
	 * @return void
	 *
	 * @dataProvider flows
	 */
	public function testTasksAreAddressedAndGatesReadTheirOutcome(string $fragment, string $schema, string $name): void {
		$flow = $this->flow($this->schema($fragment, $schema), $name);
		$keys = [];
		foreach ($flow['nodes'] as $node) {
			if ($node['type'] === 'openregister.user-task') {
				self::assertNotSame([], $node['config']['candidateGroups'], $node['id'] . ' names nobody');
				self::assertContains('gedaan', $node['config']['outcomes']);
				$keys[] = $node['config']['outcomeKey'];
			}
		}

		self::assertNotSame([], $keys);
		foreach ($flow['nodes'] as $node) {
			if ($node['type'] !== 'openregister.switch') {
				continue;
			}

			$var = $node['exits'][0]['condition']['=='][0]['var'];
			if (str_ends_with($var, '.outcome') === true) {
				self::assertMatchesRegularExpression('/^json\.(' . implode('|', $keys) . ')\.outcome$/', $var);
			}
		}

	}//end testTasksAreAddressedAndGatesReadTheirOutcome()

	/**
	 * Every write updates the triggering case by its uuid and only fills fields the case declares.
	 *
	 * @param string $fragment The fragment file.
	 * @param string $schema   The schema key.
	 * @param string $name     The flow name.
	 *
	 * @return void
	 *
	 * @dataProvider flows
	 */
	public function testWritesOnlyTouchTheCase(string $fragment, string $schema, string $name): void {
		$declared = $this->schema($fragment, $schema);
		$flow     = $this->flow($declared, $name);
		foreach ($flow['nodes'] as $node) {
			if ($node['type'] !== 'openregister.object-write') {
				continue;
			}

			self::assertSame($schema, $node['config']['schema']);
			self::assertSame('update', $node['config']['operation']);
			self::assertSame([['property' => 'uuid', 'value' => '{{ id }}']], $node['config']['match']);
			foreach (array_keys($node['config']['fields']) as $field) {
				if ($field !== 'uuid') {
					self::assertArrayHasKey($field, $declared['properties'], $node['id'] . ' writes an undeclared field');
				}
			}
		}

	}//end testWritesOnlyTouchTheCase()

	/**
	 * The onboarding and offboarding flows tick exactly the checklist fields the change names.
	 *
	 * @return void
	 */
	public function testTheChecklistFieldsAreTicked(): void {
		$ticked = [];
		foreach (['Onboarding' => 'Indiensttreding', 'Offboarding' => 'Uitdiensttreding'] as $schema => $name) {
			foreach ($this->flow($this->schema('hr-onboarding.json', $schema), $name)['nodes'] as $node) {
				if ($node['type'] === 'openregister.object-write') {
					$fields = $node['config']['fields'];
					unset($fields['uuid']);
					$ticked += $fields;
				}
			}
		}

		self::assertSame(
			[
				'itProvisioned'      => true,
				'assetsIngeleverd'   => true,
				'toegangIngetrokken' => true,
				'exitGesprekDone'    => '{{ exitGesprekDatum }}',
			],
			$ticked
		);

	}//end testTheChecklistFieldsAreTicked()

	/**
	 * The escalation only continues for a submitted request, waits, and
	 * re-checks the request before telling HR.
	 *
	 * @return void
	 */
	public function testTheEscalationWaitsAndRechecks(): void {
		$flow  = $this->flow($this->schema('hr-leave.json', 'LeaveRequest'), 'Verlofaanvraag blijft liggen');
		$nodes = $this->nodes($flow);
		$path  = [];
		$next  = 'start';
		$edges = [];
		foreach ($flow['edges'] as $edge) {
			if (($edge['fromExit'] ?? 'submitted') === 'submitted') {
				$edges[$edge['from']] = $edge['to'];
			}
		}

		while (isset($edges[$next]) === true) {
			$next   = $edges[$next];
			$path[] = $nodes[$next]['type'];
		}

		self::assertSame(
			[
				'openregister.switch',
				'openregister.wait',
				'openregister.object-read',
				'openregister.switch',
				'openregister.send-notification',
				'openregister.user-task',
				'openregister.end',
			],
			$path
		);
		self::assertSame('5 days', $nodes['wait']['config']['for']);
		self::assertSame(['humaniq-hr'], $nodes['notify-hr']['config']['recipients']);
		self::assertSame(['==' => [['var' => 'json.status'], 'submitted']], $nodes['only-submitted']['exits'][0]['condition']);
		self::assertSame(['==' => [['var' => 'json.current.0.status'], 'submitted']], $nodes['still-submitted']['exits'][0]['condition']);

	}//end testTheEscalationWaitsAndRechecks()

}//end class
