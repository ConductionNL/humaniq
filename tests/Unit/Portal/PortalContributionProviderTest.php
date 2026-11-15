<?php

/**
 * Unit tests for the humaniq PortalContributionProvider.
 *
 * Pins the ADR-046 contract-v2 contribution: the dual v2/v1 audience
 * declaration (external-employee + client), the fail-closed null for unknown
 * audiences, the exact scoping map (schema → scopeField → scopeClaim) for both
 * audiences, and the strict create-action whitelists (no scoping property, no
 * status/approval-stamp fields — the declarative lifecycle owns every
 * transition). The provider is constructed directly — it is a plain
 * dependency-free class by contract (amendment A1), so no mocks and no
 * container are involved.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Portal
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
 * @spec openspec/changes/portal-contribution/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Portal;

use OCA\Humaniq\Portal\PortalContributionProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PortalContributionProvider.
 *
 * @spec openspec/changes/portal-contribution/tasks.md#task-4
 */
class PortalContributionProviderTest extends TestCase {

	/**
	 * The provider under test.
	 *
	 * @var PortalContributionProvider
	 */
	private PortalContributionProvider $provider;

	/**
	 * A fully server-derived external-employee subject, as portaliq's auth
	 * edge builds it (claims are server-managed, amendment A4).
	 *
	 * @var array<string, mixed>
	 */
	private const EMPLOYEE_SUBJECT = [
		'subjectRef' => '00000000-0000-0000-0000-000000000000',
		'audience' => 'external-employee',
		'organisation' => '00000000-0000-0000-0000-000000000000',
		'trust' => 'low',
		'claims' => [
			'humaniq' => ['employeeId' => '00000000-0000-0000-0000-000000000000'],
		],
	];

	/**
	 * A fully server-derived client subject.
	 *
	 * @var array<string, mixed>
	 */
	private const CLIENT_SUBJECT = [
		'subjectRef' => '00000000-0000-0000-0000-000000000000',
		'audience' => 'client',
		'organisation' => '00000000-0000-0000-0000-000000000000',
		'trust' => 'low',
		'claims' => [
			'humaniq' => ['clientId' => '00000000-0000-0000-0000-000000000000'],
		],
	];

	/**
	 * Set up the provider — direct construction, no dependencies by contract.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->provider = new PortalContributionProvider();

	}//end setUp()

	/**
	 * The class is plain: no interfaces, no parent, no constructor deps
	 * (amendment A1 — inert without portaliq).
	 *
	 * @return void
	 */
	public function testClassIsPlainAndDependencyFree(): void {
		$reflection = new \ReflectionClass(PortalContributionProvider::class);

		$this->assertSame([], $reflection->getInterfaceNames());
		$this->assertFalse($reflection->getParentClass());
		$this->assertNull($reflection->getConstructor());

	}//end testClassIsPlainAndDependencyFree()

	/**
	 * getAudiences() (v2) declares external-employee and client.
	 *
	 * @return void
	 */
	public function testGetAudiencesReturnsBothAudiences(): void {
		$this->assertSame(['external-employee', 'client', 'manager', 'candidate', 'new-hire', 'former-employee'], $this->provider->getAudiences());

	}//end testGetAudiencesReturnsBothAudiences()

	public function testManagerManifestIsReadOnlyTimesheetsScopedByCostCentreClaim(): void {
		$manifest = $this->provider->getContribution(['audience' => 'manager']);

		$this->assertIsArray($manifest);
		// Team timesheets: scoped by the costCenter claim, projected to review
		// fields only.
		$collection = $manifest['collections'][0];
		$this->assertSame('teamTimesheets', $collection['id']);
		$this->assertSame('costCenter', $collection['scopeField']);
		$this->assertSame('costCenter', $collection['scopeClaim']);
		$this->assertNotContains('billable', $collection['fields']);
		$this->assertNotContains('costCenter', $collection['fields']);
		// Read-only: no rowActions and no actions — approve/reject is blocked by
		// humaniq's Timesheet lifecycle hook (needs A6 / a portal-aware hook).
		$this->assertArrayNotHasKey('rowActions', $collection);
		$this->assertSame([], $manifest['actions']);

	}//end testManagerManifestIsReadOnlyTimesheetsScopedByCostCentreClaim()

	/**
	 * getAudience() (v1 fallback) returns the primary audience and agrees
	 * with the v2 declaration.
	 *
	 * @return void
	 */
	public function testGetAudienceFallbackIsPrimaryAudience(): void {
		$this->assertSame('external-employee', $this->provider->getAudience());
		$this->assertContains($this->provider->getAudience(), $this->provider->getAudiences());

	}//end testGetAudienceFallbackIsPrimaryAudience()

	/**
	 * Unknown, foreign, or missing audiences get null — fail-closed filtering.
	 *
	 * @return void
	 */
	public function testGetContributionReturnsNullForUnknownAudiences(): void {
		$supplierSubject = self::EMPLOYEE_SUBJECT;
		$supplierSubject['audience'] = 'supplier';
		$this->assertNull($this->provider->getContribution($supplierSubject));

		$emptySubject = self::EMPLOYEE_SUBJECT;
		$emptySubject['audience'] = '';
		$this->assertNull($this->provider->getContribution($emptySubject));

		$audiencelessSubject = self::EMPLOYEE_SUBJECT;
		unset($audiencelessSubject['audience']);
		$this->assertNull($this->provider->getContribution($audiencelessSubject));

		$this->assertNull($this->provider->getContribution([]));

	}//end testGetContributionReturnsNullForUnknownAudiences()

	/**
	 * The external-employee manifest exposes the exact six read collections
	 * with the contracted scoping map (schema → scopeField → scopeClaim).
	 *
	 * @return void
	 */
	public function testExternalEmployeeCollectionsMatchScopingMap(): void {
		$manifest = $this->provider->getContribution(self::EMPLOYEE_SUBJECT);

		$this->assertIsArray($manifest);
		$this->assertNotSame('Humaniq', $manifest['label']);
		$this->assertSame([], $manifest['notifications']);

		$expected = [
			'myEmployeeRecord' => ['Employee', 'id', false],
			'payslips' => ['Payslip', 'employeeId', true],
			'employmentContracts' => ['EmploymentContract', 'employeeId', true],
			'timesheets' => ['Timesheet', 'employeeId', true],
			'expenses' => ['Expense', 'employeeId', true],
			'leaveRequests' => ['LeaveRequest', 'employeeId', true],
		];

		$collections = $manifest['collections'];
		$this->assertCount(count($expected), $collections);

		foreach ($collections as $collection) {
			$this->assertArrayHasKey($collection['id'], $expected);
			[$schema, $scopeField, $listable] = $expected[$collection['id']];

			$this->assertSame('humaniq', $collection['register'], $collection['id']);
			$this->assertSame($schema, $collection['schema'], $collection['id']);
			$this->assertSame($scopeField, $collection['scopeField'], $collection['id']);
			$this->assertSame('employeeId', $collection['scopeClaim'], $collection['id']);
			$this->assertSame('low', $collection['minTrust'], $collection['id']);
			$this->assertSame($listable, $collection['listable'], $collection['id']);
		}

	}//end testExternalEmployeeCollectionsMatchScopingMap()

	/**
	 * The external-employee create-actions carry the exact conservative field
	 * whitelists: no scoping property (portaliq stamps it server-side) and no
	 * status/approval-stamp fields (the declarative lifecycle owns every
	 * transition).
	 *
	 * @return void
	 */
	public function testExternalEmployeeCreateWhitelistsAreConservative(): void {
		$manifest = $this->provider->getContribution(self::EMPLOYEE_SUBJECT);

		$this->assertIsArray($manifest);

		$expected = [
			'createTimesheet' => [
				'Timesheet',
				[
					'period',
					'hours',
					'description',
					'projectId',
					'costCenter',
					'billable',
					'clientRef',
				],
			],
			'createExpense' => [
				'Expense',
				[
					'title',
					'description',
					'amount',
					'currency',
					'category',
					'expenseDate',
				],
			],
			'createLeaveRequest' => [
				'LeaveRequest',
				[
					'leaveType',
					'startDate',
					'endDate',
					'hours',
					'reason',
				],
			],
		];

		$actions = $manifest['actions'];
		$this->assertCount(count($expected), $actions);

		$forbidden = [
			'employeeId',
			'status',
			'submittedAt',
			'approvedBy',
			'approvedAt',
			'rejectionReason',
			'reimbursedAt',
		];

		foreach ($actions as $action) {
			$this->assertArrayHasKey($action['id'], $expected);
			[$schema, $fields] = $expected[$action['id']];

			$this->assertSame('create', $action['type'], $action['id']);
			$this->assertSame('humaniq', $action['register'], $action['id']);
			$this->assertSame($schema, $action['schema'], $action['id']);
			$this->assertSame($fields, $action['fields'], $action['id']);

			foreach ($forbidden as $field) {
				$this->assertNotContains($field, $action['fields'], $action['id'] . ' must not whitelist ' . $field);
			}
		}

	}//end testExternalEmployeeCreateWhitelistsAreConservative()

	/**
	 * The client manifest is a single read-only Timesheet collection scoped by
	 * clientRef == the clientId claim; no actions (approve/reject is deferred
	 * to endpoint-action adoption, amendment A6).
	 *
	 * @return void
	 */
	public function testClientManifestIsReadOnlyClientRefScopedTimesheets(): void {
		$manifest = $this->provider->getContribution(self::CLIENT_SUBJECT);

		$this->assertIsArray($manifest);
		$this->assertNotSame('Humaniq', $manifest['label']);
		$this->assertSame([], $manifest['actions']);
		$this->assertSame([], $manifest['notifications']);
		$this->assertCount(1, $manifest['collections']);

		$collection = $manifest['collections'][0];
		$this->assertSame('clientTimesheets', $collection['id']);
		$this->assertSame('humaniq', $collection['register']);
		$this->assertSame('Timesheet', $collection['schema']);
		$this->assertSame('clientRef', $collection['scopeField']);
		$this->assertSame('clientId', $collection['scopeClaim']);
		$this->assertSame('low', $collection['minTrust']);
		$this->assertTrue($collection['listable']);

	}//end testClientManifestIsReadOnlyClientRefScopedTimesheets()

	/**
	 * Every page of every audience names its menu group, says its name once, and every label is Dutch.
	 *
	 * Portaliq's group contract: pages with the same `group` share one heading
	 * in the site's menu. Without declared pages the menu named them after the
	 * app ("Humaniq") and in English ("Timesheets to review").
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-pages-in-dutch-groups/specs/portal-contribution/spec.md#requirement-every-portal-page-names-its-menu-group-in-dutch
	 */
	public function testEveryPageHasAGroupAndEveryLabelIsDutch(): void {
		$english = '/\b(My|Log|Submit|Request|Timesheets?|Team|Vacancies|Apply|Before|Hand|details|record)\b/';
		$groups = [
			'external-employee' => 'Werk en uren',
			'client' => 'Werk en uren',
			'manager' => 'Werk en uren',
			'candidate' => 'Vacatures',
			'new-hire' => 'Uw nieuwe baan',
			'former-employee' => 'Uw vroegere baan',
		];
		foreach ($groups as $audience => $group) {
			$manifest = $this->provider->getContribution(['audience' => $audience]);
			$this->assertSame($group, $manifest['label'], $audience);
			$this->assertNotEmpty($manifest['pages'], $audience);

			$collections = array_column($manifest['collections'], 'label', 'id');
			foreach ($manifest['pages'] as $page) {
				$this->assertSame($group, $page['group'], $page['id']);
				foreach ($page['blocks'] as $block) {
					if ($block['type'] === 'richText') {
						$this->assertStringNotContainsString('#', $block['markdown'], $page['id']);
					}

					if ($block['type'] === 'collection') {
						$this->assertSame($page['label'], $collections[$block['collection']], 'one heading on '.$page['id']);
					}
				}
			}

			foreach ([...$manifest['collections'], ...$manifest['actions']] as $entry) {
				$this->assertDoesNotMatchRegularExpression($english, $entry['label'], $entry['id']);
			}
		}//end foreach

		$client = $this->provider->getContribution(['audience' => 'client']);
		$this->assertSame('Te beoordelen urenstaten', $client['pages'][0]['label']);
	}//end testEveryPageHasAGroupAndEveryLabelIsDutch()

}//end class
