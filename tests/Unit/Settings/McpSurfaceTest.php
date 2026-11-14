<?php

/**
 * The MCP surface humaniq declares, and everything it refuses.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * ADR-063 rules that an app declares a per-schema `x-openregister-mcp` block
 * and OpenRegister derives the tools; the app hand-writes none. humaniq had no
 * MCP surface at all before this, and it is the sharpest privacy case in the
 * fleet: its schemas hold BSNs, IBANs, gross salaries, payslips with per-employee
 * tax withholdings, dismissal reasons, severance amounts, candidate CVs, and
 * sick-leave cases with Wet verbetering poortwachter milestones, which is
 * health data under AVG art. 9. The derived surface has no field-level
 * projection, so a `get` returns the whole object.
 *
 * The design question was therefore not what can be exposed but what is left
 * once everything special-category, remunerative and identity-bearing is
 * removed. The answer is 6 schemas of 57, read-only, and that thinness is the
 * point.
 *
 * These tests exist because that decision is one careless block away from being
 * undone, and nothing else in the build would notice. A `create` verb on
 * Expense, or an MCP block on Employee, is a config edit that passes every
 * other check in this repository.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/humaniq-mcp-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the declared MCP surface.
 *
 * @spec openspec/specs/humaniq-mcp-surface/spec.md
 */
class McpSurfaceTest extends TestCase {

	/**
	 * The only schemas allowed to carry an MCP block.
	 *
	 * @var list<string>
	 */
	private const ALLOWED = [
		'Announcement',
		'Asset',
		'AssetAssignment',
		'Cao',
		'Expense',
		'LeaveType',
		'Normfunctie',
		'OrgUnit',
		'Timesheet',
		'Vacancy',
	];

	/**
	 * Schemas whose exposure was argued and REFUSED. Remuneration and identity
	 * (bsn, iban, grossMonthlySalary, hourlyWage, payslips, tax and pension
	 * filings), health (SickLeaveCase, and the whole leave cluster because
	 * humaniq models sick leave as a VALUE of the shared `leaveType` enum, so
	 * the dialect cannot filter it out), and recruitment (Application: candidate
	 * name, e-mail, phone, CV, motivation).
	 *
	 * @var list<string>
	 */
	private const REFUSED = [
		'Application',
		'AttendanceRecord',
		'Employee',
		'EmploymentContract',
		'GeneratedDocument',
		'LeaveBalance',
		'LeaveRequest',
		'LoonaangifteFiling',
		'Offboarding',
		'Onboarding',
		'OrgAssignment',
		'PayrollGLPost',
		'PayrollPaymentBatch',
		'PayrollRun',
		'Payslip',
		'PensionFiling',
		'SickLeaveCase',
	];

	/**
	 * Every declared schema, keyed by name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function schemas(): array {
		$out = [];
		$dir = dirname(__DIR__, 3) . '/lib/Settings/register.d';
		foreach ((glob($dir . '/*.json') ?: []) as $file) {
			$doc = json_decode((string)file_get_contents($file), true);
			foreach ((($doc['components']['schemas'] ?? []) ?: []) as $name => $schema) {
				$out[$name] = $schema;
			}
		}

		return $out;
	}

	/**
	 * The MCP blocks that are actually declared, keyed by schema name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function mcpBlocks(): array {
		$out = [];
		foreach ($this->schemas() as $name => $schema) {
			$block = ($schema['configuration']['x-openregister-mcp'] ?? null);
			if (is_array($block) === true) {
				$out[$name] = $block;
			}
		}

		return $out;
	}

	/**
	 * The surface is exactly the argued schemas, no more and no fewer: the six
	 * of the MCP surface, and the four reference schemas the HR question
	 * assistant answers from (self-service-policy-assistant D1). None of those
	 * four holds a salary, a BSN, a health value or an identified employee.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/humaniq-mcp-surface/spec.md
	 */
	public function testTheMcpSurfaceIsExactlyTheAllowlistedSchemas(): void {
		$declared = array_keys($this->mcpBlocks());
		sort($declared);

		$this->assertSame(
			self::ALLOWED,
			$declared,
			'the MCP surface moved; every addition needs its exclusion argument re-made'
		);

	}//end testTheMcpSurfaceIsExactlyTheAllowlistedSchemas()

	/**
	 * No refused schema has quietly grown a block.
	 *
	 * Listed separately from the allowlist test on purpose: this one names the
	 * schemas whose exposure was argued and rejected, so a failure here says
	 * which decision was reversed rather than only that the set changed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/humaniq-mcp-surface/spec.md
	 */
	public function testNoRefusedSchemaExposesAnMcpTool(): void {
		$exposed = array_values(array_intersect(self::REFUSED, array_keys($this->mcpBlocks())));

		$this->assertSame(
			[],
			$exposed,
			'a schema holding salary, health or candidate data is exposed to agents'
		);

	}//end testNoRefusedSchemaExposesAnMcpTool()

	/**
	 * Every verb is read-only. Zero writes was the whole bargain.
	 *
	 * humaniq's back-office actions are payroll runs, tax filings, contract
	 * generation and approval transitions. None of them may be agent-initiated,
	 * so `create`, `update` and `delete` are absent everywhere, not merely
	 * absent from the schemas that carry sensitive fields.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/humaniq-mcp-surface/spec.md
	 */
	public function testEveryDeclaredVerbIsReadOnly(): void {
		$violations = [];
		foreach ($this->mcpBlocks() as $name => $block) {
			foreach ((($block['tools'] ?? []) ?: []) as $verb => $config) {
				if (in_array($verb, ['search', 'get'], true) === false) {
					$violations[] = $name . '.' . $verb . ' is a write verb';
				}

				if (($config['scope'] ?? null) !== 'read') {
					$violations[] = $name . '.' . $verb . ' has scope ' . var_export(($config['scope'] ?? null), true);
				}

				if (($config['readOnlyHint'] ?? null) !== true) {
					$violations[] = $name . '.' . $verb . ' is not marked readOnlyHint';
				}

				if (($config['destructiveHint'] ?? null) !== false) {
					$violations[] = $name . '.' . $verb . ' is not marked non-destructive';
				}
			}
		}

		$this->assertSame([], $violations, 'a write verb reached the MCP surface');

	}//end testEveryDeclaredVerbIsReadOnly()

	/**
	 * Every `search` filter names a real property.
	 *
	 * An unknown filter fails the whole register import, not just the one tool,
	 * so this would take every schema down with it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/humaniq-mcp-surface/spec.md
	 */
	public function testEverySearchFilterNamesADeclaredProperty(): void {
		$schemas = $this->schemas();
		$unknown = [];
		foreach ($this->mcpBlocks() as $name => $block) {
			$properties = array_keys((($schemas[$name]['properties'] ?? []) ?: []));
			foreach ((($block['tools']['search']['filters'] ?? []) ?: []) as $filter) {
				if (in_array($filter, $properties, true) === false) {
					$unknown[] = $name . '.' . $filter;
				}
			}
		}

		$this->assertSame([], $unknown, 'an unknown filter fails the entire register import');

	}//end testEverySearchFilterNamesADeclaredProperty()

	/**
	 * `filters` is a search-only key; OpenRegister rejects it on `get`.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/humaniq-mcp-surface/spec.md
	 */
	public function testFiltersAreDeclaredOnSearchOnly(): void {
		$misplaced = [];
		foreach ($this->mcpBlocks() as $name => $block) {
			foreach ((($block['tools'] ?? []) ?: []) as $verb => $config) {
				if ($verb !== 'search' && array_key_exists('filters', $config) === true) {
					$misplaced[] = $name . '.' . $verb;
				}
			}
		}

		$this->assertSame([], $misplaced, 'filters is valid on the search verb only');

	}//end testFiltersAreDeclaredOnSearchOnly()

	/**
	 * Every verb carries an agent-facing description worth reading.
	 *
	 * This string is what the model reads to choose the tool, so an empty or
	 * one-word description is the difference between a tool being used
	 * correctly and being used instead of the right one.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/humaniq-mcp-surface/spec.md
	 */
	public function testEveryVerbCarriesAUsefulDescription(): void {
		$thin = [];
		foreach ($this->mcpBlocks() as $name => $block) {
			foreach ((($block['tools'] ?? []) ?: []) as $verb => $config) {
				if (strlen((string)($config['description'] ?? '')) < 60) {
					$thin[] = $name . '.' . $verb;
				}
			}
		}

		$this->assertSame([], $thin, 'the description is what the model picks the tool by');

	}//end testEveryVerbCarriesAUsefulDescription()

	/**
	 * The surface is 20 derived tools: ten schemas, search and get each.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/humaniq-mcp-surface/spec.md
	 */
	public function testTheDerivedSurfaceIsTwentyTools(): void {
		$tools = 0;
		foreach ($this->mcpBlocks() as $block) {
			$this->assertTrue(($block['enabled'] ?? false), 'a declared block that is not enabled derives nothing');
			$tools += count((($block['tools'] ?? []) ?: []));
		}

		$this->assertSame(20, $tools);

	}//end testTheDerivedSurfaceIsTwentyTools()

}//end class
