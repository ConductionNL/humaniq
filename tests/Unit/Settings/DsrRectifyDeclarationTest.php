<?php

/**
 * Declaration tests for compliance-dsr-rectify-form (REQ-DSR-R01).
 *
 * The seeded rectification request fits the DsrRequest fragment, a pair
 * naming a field outside the list does not, the schema's field enum is the
 * controller's list, and the Rectify action on DsrRequestDetail sends the
 * request's own requestedChanges.
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
 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-R01
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Settings;

use OCA\Humaniq\Controller\AvgDsrController;
use OCA\Humaniq\Tests\Unit\Support\RegisterSchemaValidator;
use PHPUnit\Framework\TestCase;

class DsrRectifyDeclarationTest extends TestCase {

	public function testTheSeededRequestFitsAndAForbiddenFieldDoesNot(): void {
		$seed = null;
		foreach ($this->fragment()['components']['objects'] as $object) {
			if ($object['@self']['slug'] === 'dsr-devries-rectify-surname') {
				$seed = $object;
			}
		}

		self::assertNotNull($seed);
		unset($seed['@self']);
		$seed['employeeId'] = '6b7f2c1e-3d4a-4b5c-8d9e-0f1a2b3c4d5e';
		self::assertSame([['field' => 'lastName', 'value' => 'de Vries-Jansen']], $seed['requestedChanges']);
		self::assertSame([], RegisterSchemaValidator::errors('DsrRequest', $seed));
		self::assertNotSame([], RegisterSchemaValidator::errors('DsrRequest', array_merge($seed, ['requestedChanges' => [['field' => 'grossMonthlySalary', 'value' => '9000']]])));
	}//end testTheSeededRequestFitsAndAForbiddenFieldDoesNot()

	public function testTheFieldEnumIsTheControllersList(): void {
		$field = $this->fragment()['components']['schemas']['DsrRequest']['properties']['requestedChanges']['items']['properties']['field'];

		self::assertSame(AvgDsrController::RECTIFIABLE_FIELDS, $field['enum']);
	}//end testTheFieldEnumIsTheControllersList()

	public function testTheRectifyActionSendsTheRequestedChanges(): void {
		$manifest = json_decode((string)file_get_contents(__DIR__ . '/../../../src/manifest.d/hr-dsr.json'), true);
		$found = null;
		array_walk_recursive(
			$manifest,
			static function (mixed $value, mixed $key) use (&$found): void {
				if ($key === 'changes') {
					$found = $value;
				}
			}
		);

		self::assertSame('@object.requestedChanges', $found);
	}//end testTheRectifyActionSendsTheRequestedChanges()

	private function fragment(): array {
		return json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/register.d/hr-dsr.json'), true);
	}//end fragment()

}//end class
