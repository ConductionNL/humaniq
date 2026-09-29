<?php

/**
 * EmployeeRelationsCase declaration tests
 *
 * The case's access and states are declared on the schema and enforced by
 * OpenRegister (people-employee-relations-cases D1, D2). The rules were also
 * evaluated with OpenRegister's own PropertyRbacHandler, ConditionMatcher and
 * LifecycleAnnotationValidator; see the PR body.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Settings
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
 * @spec openspec/specs/employee-relations-cases/spec.md#REQ-ERC-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Settings;

use OCA\Humaniq\Service\HumaniqRoles;
use OCA\Humaniq\Tests\Unit\Support\RegisterSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * Who reads a relations case, and how it closes.
 */
class RelationsCaseDeclarationTest extends TestCase {

	/**
	 * The subject's rule: their own case, closed, a warning or a measure.
	 *
	 * @var array<string, mixed>
	 */
	private const SUBJECT = [
		'group' => 'authenticated',
		'match' => [
			'userId' => '$userId',
			'status' => 'afgesloten',
			'kind' => ['$in' => ['schriftelijke-waarschuwing', 'disciplinaire-maatregel']],
		],
	];

	/**
	 * Scenario: the manager sees a case exists, not what it says.
	 *
	 * @return void
	 */
	public function testHrReadsAllTheManagerTheCaseAndTheSubjectAClosedMeasure(): void {
		$schema = RegisterSchemaValidator::schema('EmployeeRelationsCase');
		$manager = ['group' => 'authenticated', 'match' => ['managerUserId' => '$userId']];

		self::assertSame(
			['read' => [HumaniqRoles::HR_GROUP, $manager, self::SUBJECT], 'create' => [HumaniqRoles::HR_GROUP], 'update' => [HumaniqRoles::HR_GROUP], 'delete' => [HumaniqRoles::HR_GROUP]],
			$schema['authorization']
		);
		foreach (['facts', 'measure', 'measureFrom', 'measureUntil', 'outcome', 'documents'] as $field) {
			self::assertSame(['read' => [HumaniqRoles::HR_GROUP, self::SUBJECT], 'update' => [HumaniqRoles::HR_GROUP]], $schema['properties'][$field]['authorization'], $field);
		}

		foreach (['kind', 'status', 'openedOn', 'employeeId'] as $field) {
			self::assertArrayNotHasKey('authorization', $schema['properties'][$field], $field);
		}
	}//end testHrReadsAllTheManagerTheCaseAndTheSubjectAClosedMeasure()

	/**
	 * Scenario: a case cannot close without an outcome.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/employee-relations-cases/spec.md#REQ-ERC-001
	 */
	public function testClosingNeedsAnOutcomeAndADate(): void {
		$lifecycle = RegisterSchemaValidator::schema('EmployeeRelationsCase')['x-openregister-lifecycle'];

		self::assertSame('geopend', $lifecycle['initial']);
		self::assertSame(['in-behandeling'], $lifecycle['transitions']['afsluiten']['from']);
		self::assertSame([['field' => 'outcome', 'required' => true], ['field' => 'closedOn', 'required' => true]], $lifecycle['transitions']['afsluiten']['inputs']);
		self::assertSame('in-behandeling', $lifecycle['transitions']['heropenen']['to']);
	}//end testClosingNeedsAnOutcomeAndADate()

	/**
	 * The register lists the schema, so it is imported.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/employee-relations-cases/spec.md#REQ-ERC-001
	 */
	public function testTheRegisterListsTheSchema(): void {
		$register = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/humaniq_register.json'), true);

		self::assertContains('EmployeeRelationsCase', $register['components']['registers']['humaniq']['schemas']);
	}//end testTheRegisterListsTheSchema()

}//end class
