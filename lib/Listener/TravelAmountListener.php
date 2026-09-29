<?php

/**
 * Humaniq TravelAmountListener
 *
 * Pre-save listener on OpenRegister's `ObjectCreatingEvent` and
 * `ObjectUpdatingEvent` for `Expense` and `CommuteArrangement`
 * (expenses-travel-calculation D1 and D2).
 *
 * - A travel claim with a positive distance gets its amount from the
 *   distance times the employer's rate, split into a tax-free and a taxable
 *   part, with the rate it used. A claim without a distance keeps the amount
 *   the employee typed; a claim with neither is refused, because the schema
 *   no longer requires `amount` (this listener runs after validation, so the
 *   schema cannot demand what the listener fills in).
 * - A commuting arrangement gets its monthly allowance under the 214-day
 *   rule, and the employee's account and administration, so the approval
 *   guard compares accounts and the arrangement is scoped like a claim.
 *
 * Approved and paid claims and ended arrangements are left as they are: a
 * later change of the rate does not rewrite what was approved.
 *
 * @category Listener
 * @package  OCA\Humaniq\Listener
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
 * @spec openspec/specs/expenses-travel-calculation/spec.md#REQ-TRV-001
 * @spec openspec/specs/expenses-travel-calculation/spec.md#REQ-TRV-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Service\TravelAllowanceCalculator;
use OCA\Humaniq\Standards\Checks\NlTravelExpenseChecks;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Stamps computed travel amounts before an Expense or arrangement is saved.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/expenses-travel-calculation/spec.md#REQ-TRV-001
 */
class TravelAmountListener implements IEventListener {

	/**
	 * Lower-cased slug of the claim schema.
	 *
	 * @var string
	 */
	public const EXPENSE_SLUG = 'expense';

	/**
	 * Lower-cased slug of the arrangement schema.
	 *
	 * @var string
	 */
	public const COMMUTE_SLUG = 'commutearrangement';

	/**
	 * Claim states whose amount is no longer recalculated.
	 *
	 * @var list<string>
	 */
	private const SETTLED_CLAIM_STATES = ['approved', 'reimbursed'];

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway      $gateway    Resolves slugs, the employee and the manager.
	 * @param TravelAllowanceCalculator $calculator The arithmetic.
	 * @param SettingsService           $settings   The employer's rate.
	 * @param InternalWriteMarker       $marker     Tells humaniq's own writes apart.
	 * @param LoggerInterface           $logger     Logger.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly TravelAllowanceCalculator $calculator,
		private readonly SettingsService $settings,
		private readonly InternalWriteMarker $marker,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Stamp a claim or an arrangement on its way into the register.
	 *
	 * @param Event $event The pre-save event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/expenses-travel-calculation/spec.md#REQ-TRV-001
	 */
	public function handle(Event $event): void {
		if ($this->marker->isInternal() === true) {
			return;
		}

		$entity = $this->entityOf($event);
		if ($entity === null) {
			return;
		}

		try {
			$modified = $this->stampsFor(
				slug: strtolower($this->gateway->resolveSchemaSlug((string)$entity->getSchema())),
				data: ($entity->getObject() ?? [])
			);
		} catch (\Throwable $e) {
			$this->logger->warning('humaniq: TravelAmountListener could not calculate a travel amount', ['exception' => $e->getMessage()]);
			return;
		}

		if ($modified === null) {
			$this->refuse($event, 'Vul een bedrag in, of voor een reisdeclaratie het aantal kilometers.');
			return;
		}

		if ($modified !== [] && ($event instanceof \OCA\OpenRegister\Event\ObjectCreatingEvent || $event instanceof \OCA\OpenRegister\Event\ObjectUpdatingEvent)) {
			$event->setModifiedData($modified);
		}
	}//end handle()

	/**
	 * The object a create or update is about to save, or null for any other event.
	 *
	 * @param Event $event The event.
	 *
	 * @return object|null
	 */
	private function entityOf(Event $event): ?object {
		if ($event instanceof \OCA\OpenRegister\Event\ObjectCreatingEvent) {
			return $event->getObject();
		}

		if ($event instanceof \OCA\OpenRegister\Event\ObjectUpdatingEvent) {
			return $event->getNewObject();
		}

		return null;
	}//end entityOf()

	/**
	 * The stamps for an object of one of the two schemas; [] for any other
	 * schema, null when the write must be refused.
	 *
	 * @param string               $slug The lower-cased schema slug.
	 * @param array<string, mixed> $data The object as it will be saved.
	 *
	 * @return array<string, mixed>|null
	 */
	private function stampsFor(string $slug, array $data): ?array {
		if ($slug === self::EXPENSE_SLUG) {
			return $this->stampClaim($data);
		}

		if ($slug === self::COMMUTE_SLUG) {
			return $this->stampArrangement($data);
		}

		return [];
	}//end stampsFor()

	/**
	 * The stamps for a claim; null when it must be refused.
	 *
	 * @param array<string, mixed> $claim The claim as it will be saved.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/expenses-travel-calculation/spec.md#REQ-TRV-001
	 */
	private function stampClaim(array $claim): ?array {
		if (in_array((string)($claim['status'] ?? ''), self::SETTLED_CLAIM_STATES, true) === true) {
			return [];
		}

		$distance = ($claim['distanceKm'] ?? null);
		$rates = $this->rates();
		if (($claim['category'] ?? null) === 'travel' && is_numeric($distance) === true && (float)$distance > 0.0 && $rates !== null) {
			$split = $this->calculator->claim(distanceKm: (float)$distance, ratePerKm: $rates['rate'], taxFreeRatePerKm: $rates['taxFree']);

			return array_merge($split, ['ratePerKm' => $rates['rate'], 'amountSource' => 'calculated']);
		}

		if (is_numeric($claim['amount'] ?? null) === false) {
			return null;
		}

		return ['amountSource' => 'entered'];
	}//end stampClaim()

	/**
	 * The stamps for a commuting arrangement.
	 *
	 * @param array<string, mixed> $arrangement The arrangement as it will be saved.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/expenses-travel-calculation/spec.md#REQ-TRV-002
	 */
	private function stampArrangement(array $arrangement): array {
		if (($arrangement['status'] ?? null) === 'ended') {
			return [];
		}

		$modified = $this->identity($arrangement);
		if (in_array(($arrangement['distanceSource'] ?? null), ['manual', 'routeplanner'], true) === false) {
			$modified['distanceSource'] = 'manual';
		}

		$distance = ($arrangement['distanceKmOneWay'] ?? null);
		$days = ($arrangement['daysPerWeek'] ?? null);
		$rates = $this->rates();
		if (is_numeric($distance) === false || is_numeric($days) === false || $rates === null) {
			return $modified;
		}

		$monthly = $this->calculator->monthly(distanceKmOneWay: (float)$distance, daysPerWeek: (float)$days, ratePerKm: $rates['rate'], taxFreeRatePerKm: $rates['taxFree']);

		return array_merge($modified, $monthly, ['ratePerKm' => $rates['rate']]);
	}//end stampArrangement()

	/**
	 * The employee's account, administration and manager for an arrangement.
	 *
	 * @param array<string, mixed> $arrangement The arrangement.
	 *
	 * @return array<string, mixed>
	 */
	private function identity(array $arrangement): array {
		$employeeId = trim((string)($arrangement['employeeId'] ?? ''));
		$employee = $employeeId === '' ? null : $this->gateway->findObjectData($employeeId, 'Employee');
		if ($employee === null) {
			return [];
		}

		$identity = [
			'userId' => $this->nullableTrim($employee['nextcloudUserId'] ?? null),
			'administrationId' => $this->nullableTrim($employee['administrationId'] ?? null),
		];
		$manager = $this->gateway->uniqueManagerUserIdFor($employeeId, (string)($arrangement['startDate'] ?? gmdate('Y-m-d')));
		if ($manager !== null) {
			$identity['managerUserId'] = $manager;
		}

		return $identity;
	}//end identity()

	/**
	 * The employer's rate and the tax-free rate; null when the tax-free rate
	 * cannot be read from the corpus, so nothing is computed from a guess.
	 *
	 * @return array{rate: float, taxFree: float}|null
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) The rule corpus is static data read
	 *  through the check that owns it, so the rate has one home.
	 */
	private function rates(): ?array {
		$taxFree = NlTravelExpenseChecks::taxFreeRatePerKm();
		if ($taxFree === null) {
			return null;
		}

		return ['rate' => ($this->settings->getMileageRatePerKm() ?? $taxFree), 'taxFree' => $taxFree];
	}//end rates()

	/**
	 * Refuse the write with a user-facing message.
	 *
	 * @param Event  $event   The pre-save event.
	 * @param string $message The message.
	 *
	 * @return void
	 */
	private function refuse(Event $event, string $message): void {
		if (method_exists($event, 'setErrors') === false) {
			return;
		}

		$event->setErrors(['message' => $message]);
		$event->stopPropagation();
	}//end refuse()

	/**
	 * Trim to a non-empty string or null.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string|null
	 */
	private function nullableTrim(mixed $value): ?string {
		$trimmed = trim((string)($value ?? ''));

		return $trimmed === '' ? null : $trimmed;
	}//end nullableTrim()

}//end class
