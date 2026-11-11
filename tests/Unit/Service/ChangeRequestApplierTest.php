<?php

/**
 * Unit tests for ChangeRequestApplier.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use DateTime;
use OCA\Humaniq\Service\ChangeRequestApplier;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\Humaniq\Service\RecordStoreInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

/**
 * Applying an approved change request to the employee.
 */
class ChangeRequestApplierTest extends TestCase {

	/**
	 * The whole outcome of applying every kind of request: the result and
	 * every write, with whether it ran as an internal write.
	 *
	 * @return void
	 */
	public function testTheWholeOutcomeOfEveryApply(): void {
		$employee = ['id' => 'emp-1', '@self' => ['x' => 1], 'iban' => 'NL01', 'grossMonthlySalary' => 3800, 'straat' => 'Oudegracht'];
		$approved = ['id' => 'req-1', '@self' => ['y' => 2], 'status' => 'goedgekeurd', 'employeeId' => 'emp-1', 'changes' => ['iban' => 'NL02'], 'previousValues' => ['iban' => 'NL01']];
		$cases = [
			'applied'          => $approved,
			'not-approved'     => array_merge($approved, ['status' => 'ingediend']),
			'no-status'        => array_diff_key($approved, ['status' => true]),
			'already-applied'  => array_merge($approved, ['appliedAt' => '2026-09-01T10:00:00+00:00']),
			'blank-applied'    => array_merge($approved, ['appliedAt' => '  ']),
			'earlier-error'    => array_merge($approved, ['applyError' => 'Niet doorgevoerd']),
			'array-error'      => array_merge($approved, ['applyError' => ['x']]),
			'stale'            => array_merge($approved, ['previousValues' => ['iban' => 'NL99']]),
			'stale-two'        => array_merge($approved, ['changes' => ['iban' => 'NL02', 'straat' => 'Laan'], 'previousValues' => ['iban' => 'NL99']]),
			'numeric-same'     => array_merge($approved, ['changes' => ['grossMonthlySalary' => 4000], 'previousValues' => ['grossMonthlySalary' => '3800.00']]),
			'unknown-employee' => array_merge($approved, ['employeeId' => 'emp-gone']),
			'no-changes'       => array_merge($approved, ['changes' => 'iban']),
			'no-previous'      => array_merge($approved, ['previousValues' => null, 'changes' => ['nickname' => 'Jo']]),
		];
		$got = [];
		foreach ($cases as $name => $request) {
			$writes = [];
			$marker = new InternalWriteMarker();
			$store = $this->createMock(RecordStoreInterface::class);
			$store->method('findObjectData')->willReturnCallback(static fn (string $uuid): ?array => ($uuid === 'emp-1' ? $employee : null));
			$store->method('save')->willReturnCallback(
				static function (array $payload, string $schema, ?string $uuid=null) use (&$writes, $marker): object {
					$writes[] = ['schema' => $schema, 'uuid' => $uuid, 'internal' => $marker->isInternal(), 'payload' => $payload];
					return (object)[];
				}
			);
			$time = $this->createMock(ITimeFactory::class);
			$time->method('getDateTime')->willReturnCallback(static fn (): DateTime => new DateTime('2026-09-29T10:00:00+00:00'));
			$applier = new ChangeRequestApplier(gateway: $store, marker: $marker, time: $time);
			$got[$name] = ['applied' => $applier->apply('req-1', $request), 'writes' => $writes];
		}

		self::assertSame((string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/golden/change-request-apply.json'), json_encode($got, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n");
	}//end testTheWholeOutcomeOfEveryApply()

}//end class
