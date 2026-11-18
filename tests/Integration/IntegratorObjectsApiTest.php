<?php

/**
 * Integration test: an integrator reads and writes through the objects API.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Runs against a live instance (`phpunit -c phpunit-integration.xml`) with a
 * Nextcloud app password per service account, as docs/getting-started/
 * integrator-api.md describes. Every test skips itself unless its accounts
 * are configured:
 *
 * - HUMANIQ_BASE_URL: the instance, e.g. https://cloud.example.org (never assumed);
 * - HUMANIQ_EMPLOYEE_ID: an employee both the payroll and the HR account may read;
 * - HUMANIQ_PAYROLL_USER / HUMANIQ_PAYROLL_APP_PASSWORD: an account in humaniq-payroll;
 * - HUMANIQ_HR_USER / HUMANIQ_HR_APP_PASSWORD: an account in humaniq-hr;
 * - HUMANIQ_NOROLE_USER / HUMANIQ_NOROLE_APP_PASSWORD: an account in no humaniq group.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/matrix-rows-without-a-requirement/specs/hris-api-public/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * The three REQ-HRIS-006 scenarios over HTTP.
 *
 * @spec openspec/changes/matrix-rows-without-a-requirement/specs/hris-api-public/spec.md#requirement-an-integrator-shall-read-and-write-hr-records-through-the-objects-api-with-an-app-password-req-hris-006
 */
final class IntegratorObjectsApiTest extends TestCase {

	/**
	 * Scenario: a payroll bureau reads an employee with the payroll role's fields.
	 *
	 * @return void
	 */
	public function testAPayrollBureauReadsAnEmployee(): void {
		$account = self::account('PAYROLL');
		$id = self::employeeId();

		$response = self::call(account: $account, method: 'GET', path: '/Employee/' . rawurlencode($id));

		self::assertSame(200, $response['status'], 'The payroll account reads the employee.');
		self::assertSame($id, (string)($response['body']['id'] ?? $response['body']['@self']['id'] ?? ''));
		self::assertArrayHasKey('grossMonthlySalary', $response['body'], 'The payroll role reads the salary.');
	}//end testAPayrollBureauReadsAnEmployee()

	/**
	 * Scenario: an identity manager changes a field and the audit trail names it.
	 *
	 * @return void
	 */
	public function testAnHrAccountChangesAFieldAndTheAuditTrailNamesIt(): void {
		$account = self::account('HR');
		$id = self::employeeId();
		$phone = '+31 20 ' . random_int(100, 999) . ' ' . random_int(1000, 9999);

		$patch = self::call(account: $account, method: 'PATCH', path: '/Employee/' . rawurlencode($id), body: ['phone' => $phone]);
		self::assertSame(200, $patch['status'], 'The HR account may change the employee.');

		$read = self::call(account: $account, method: 'GET', path: '/Employee/' . rawurlencode($id));
		self::assertSame($phone, $read['body']['phone'] ?? null);

		$trail = self::call(account: $account, method: 'GET', path: '/Employee/' . rawurlencode($id) . '/audit-trails');
		self::assertSame(200, $trail['status']);
		$rows = (array)($trail['body']['results'] ?? $trail['body']);
		self::assertContains($account['user'], array_map(static fn (mixed $row): string => (string)(is_array($row) === true ? ($row['user'] ?? '') : ''), $rows), 'The audit trail names the service account.');
	}//end testAnHrAccountChangesAFieldAndTheAuditTrailNamesIt()

	/**
	 * Scenario: an account without the role gets no employee.
	 *
	 * @return void
	 */
	public function testAnAccountWithoutTheRoleGetsNothing(): void {
		$account = self::account('NOROLE');

		$response = self::call(account: $account, method: 'GET', path: '/Employee');

		self::assertContains($response['status'], [200, 403, 404]);
		if ($response['status'] === 200) {
			self::assertSame([], (array)($response['body']['results'] ?? []), 'No employee is returned.');
		}
	}//end testAnAccountWithoutTheRoleGetsNothing()

	/**
	 * The configured account for a role, or skip the test.
	 *
	 * @param string $role PAYROLL, HR or NOROLE.
	 *
	 * @return array{user: string, password: string}
	 */
	private static function account(string $role): array {
		$user = (string)getenv('HUMANIQ_' . $role . '_USER');
		$password = (string)getenv('HUMANIQ_' . $role . '_APP_PASSWORD');
		if ((string)getenv('HUMANIQ_BASE_URL') === '' || $user === '' || $password === '' || function_exists('curl_init') === false) {
			self::markTestSkipped('Set HUMANIQ_BASE_URL, HUMANIQ_' . $role . '_USER and HUMANIQ_' . $role . '_APP_PASSWORD to run against an instance.');
		}

		return ['user' => $user, 'password' => $password];
	}//end account()

	/**
	 * The employee the tests read, or skip.
	 *
	 * @return string
	 */
	private static function employeeId(): string {
		$id = (string)getenv('HUMANIQ_EMPLOYEE_ID');
		if ($id === '') {
			self::markTestSkipped('Set HUMANIQ_EMPLOYEE_ID to an employee the service accounts may read.');
		}

		return $id;
	}//end employeeId()

	/**
	 * One call to the humaniq objects API with an app password.
	 *
	 * @param array{user: string, password: string} $account The service account.
	 * @param string                                 $method  The HTTP method.
	 * @param string                                 $path    The path under /api/objects/humaniq.
	 * @param array<string, mixed>|null              $body    A JSON body, or null.
	 *
	 * @return array{status: int, body: array<mixed>}
	 */
	private static function call(array $account, string $method, string $path, ?array $body = null): array {
		$url = rtrim((string)getenv('HUMANIQ_BASE_URL'), '/') . '/apps/openregister/api/objects/humaniq' . $path;
		$curl = curl_init($url);
		curl_setopt_array(
			$curl,
			[
				CURLOPT_CUSTOMREQUEST => $method,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_USERPWD => $account['user'] . ':' . $account['password'],
				CURLOPT_HTTPHEADER => ['OCS-APIRequest: true', 'Accept: application/json', 'Content-Type: application/json'],
				CURLOPT_TIMEOUT => 30,
			]
		);
		if ($body !== null) {
			curl_setopt($curl, CURLOPT_POSTFIELDS, (string)json_encode($body));
		}

		$raw = curl_exec($curl);
		$status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
		curl_close($curl);
		$decoded = json_decode(is_string($raw) === true ? $raw : '', true);

		return ['status' => $status, 'body' => (is_array($decoded) === true ? $decoded : [])];
	}//end call()
}//end class
