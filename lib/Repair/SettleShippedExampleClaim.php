<?php

/**
 * Humaniq Settle Shipped Example Claim Repair Step
 *
 * The register seed used to ship the example travel claim
 * `expense-devries-train` as approved on the payroll route without a run, so a
 * payroll run would pay it. On a tenant that imported that seed, this step
 * marks the claim reimbursed on the direct route, once, and only while the
 * claim still holds exactly what the seed shipped. It writes as humaniq's own
 * write, touches nothing else, and logs the claim's id. A second run finds
 * nothing to change.
 *
 * @category Repair
 * @package  OCA\Humaniq\Repair
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
 * @spec openspec/changes/settle-the-shipped-example-claim/specs/humaniq-expenses/spec.md#Requirement:-The-shipped-example-claim-SHALL-be-settled-once-on-tenants-that-imported-it-as-payable
 */

declare(strict_types=1);

namespace OCA\Humaniq\Repair;

use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\Humaniq\Service\OpenRegisterAvailability;
use OCA\Humaniq\Service\RecordStoreInterface;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Settles the shipped example claim once.
 *
 * @spec openspec/changes/settle-the-shipped-example-claim/specs/humaniq-expenses/spec.md#Requirement:-The-shipped-example-claim-SHALL-be-settled-once-on-tenants-that-imported-it-as-payable
 */
class SettleShippedExampleClaim implements IRepairStep {

	/**
	 * The example claim's slug.
	 *
	 * @var string
	 */
	public const CLAIM_SLUG = 'expense-devries-train';

	/**
	 * The slug of the example claim's employee.
	 *
	 * @var string
	 */
	public const EMPLOYEE_SLUG = 'employee-devries';

	/**
	 * What the old seed shipped, field by field (the amount compares in cents).
	 *
	 * @var array<string, string>
	 */
	private const SHIPPED = [
		'title' => 'Treinreis Utrecht: Den Haag',
		'description' => 'Retour tweede klas voor projectoverleg.',
		'currency' => 'EUR',
		'category' => 'travel',
		'expenseDate' => '2026-05-20',
		'status' => 'approved',
		'submittedAt' => '2026-05-20T18:00:00Z',
		'approvedBy' => 'manager-pietersen',
		'approvedAt' => '2026-05-21T09:15:00Z',
		'reimbursementRoute' => 'payroll',
	];

	/**
	 * The shipped amount, in cents.
	 *
	 * @var int
	 */
	private const SHIPPED_CENTS = 2740;

	/**
	 * What the settled claim says.
	 *
	 * @var array<string, string>
	 */
	private const SETTLED = ['status' => 'reimbursed', 'reimbursementRoute' => 'direct', 'reimbursedAt' => '2026-05-26T10:00:00Z'];

	/**
	 * Constructor.
	 *
	 * @param RecordStoreInterface $gateway The register.
	 * @param InternalWriteMarker $marker Marks the write as humaniq's own.
	 * @param OpenRegisterAvailability $openRegister Availability check.
	 * @param LoggerInterface $logger Records the settled claim.
	 */
	public function __construct(
		private readonly RecordStoreInterface $gateway,
		private readonly InternalWriteMarker $marker,
		private readonly OpenRegisterAvailability $openRegister,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The repair step name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/settle-the-shipped-example-claim/specs/humaniq-expenses/spec.md#Requirement:-The-shipped-example-claim-SHALL-be-settled-once-on-tenants-that-imported-it-as-payable
	 */
	public function getName(): string {
		return 'Settle the shipped example expense claim';
	}//end getName()

	/**
	 * Settle the example claim when it still holds the shipped content.
	 *
	 * @param IOutput $output The output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/settle-the-shipped-example-claim/specs/humaniq-expenses/spec.md#Requirement:-The-shipped-example-claim-SHALL-be-settled-once-on-tenants-that-imported-it-as-payable
	 */
	public function run(IOutput $output): void {
		if ($this->openRegister->isAvailable() === false) {
			$output->warning('OpenRegister is not installed or enabled. Skipping the example claim.');
			return;
		}

		try {
			$claim = $this->unchangedClaim();
			if ($claim === null) {
				$output->info('The shipped example claim needs no change.');
				return;
			}

			$id = (string)$claim['id'];
			$payload = array_merge($claim, self::SETTLED);
			unset($payload['@self']);
			$this->marker->runInternal(fn () => $this->gateway->save($payload, 'Expense', $id));
		} catch (\Throwable $e) {
			$output->warning('Could not settle the shipped example claim: ' . $e->getMessage());
			$this->logger->error('humaniq: settling the shipped example claim failed', ['exception' => $e->getMessage()]);
			return;
		}

		$this->logger->notice('humaniq: shipped example claim settled', ['id' => $id]);
		$output->info('Settled the shipped example claim ' . $id . '.');
	}//end run()

	/**
	 * The example claim when it still holds exactly the shipped content, its
	 * employee is the shipped one and no payroll run holds it; else null.
	 *
	 * @return array<string, mixed>|null
	 */
	private function unchangedClaim(): ?array {
		$claim = $this->bySlug(schema: 'Expense', slug: self::CLAIM_SLUG);
		$employee = $this->bySlug(schema: 'Employee', slug: self::EMPLOYEE_SLUG);
		if ($claim === null || trim((string)($claim['payrollRunId'] ?? '')) !== '') {
			return null;
		}

		if ((string)($claim['employeeId'] ?? '') !== (string)($employee['id'] ?? '') || is_numeric($claim['amount'] ?? null) === false) {
			return null;
		}

		if ((int)round(((float)$claim['amount']) * 100) !== self::SHIPPED_CENTS) {
			return null;
		}

		foreach (self::SHIPPED as $field => $value) {
			if (($claim[$field] ?? null) !== $value) {
				return null;
			}
		}

		return $claim;
	}//end unchangedClaim()

	/**
	 * The row of a schema whose slug is this one, or null.
	 *
	 * @param string $schema The schema.
	 * @param string $slug The slug.
	 *
	 * @return array<string, mixed>|null
	 */
	private function bySlug(string $schema, string $slug): ?array {
		foreach ($this->gateway->loadAll($schema) as $row) {
			if (($row['@self']['slug'] ?? null) === $slug) {
				return $row;
			}
		}

		return null;
	}//end bySlug()

}//end class
