<?php

/**
 * Humaniq ReviewSignOffStampListener
 *
 * Pre-save listener on OpenRegister's `ObjectUpdatingEvent` for the
 * `PerformanceReview` schema (REQ-PRV-007). It records both sides of a
 * finished review on the server, never from client input:
 *
 * - on the `vaststellen` edge (to `vastgesteld`) the date the reviewer set it,
 *   `vastgesteldOp`;
 * - on the `ondertekenen` edge (`vastgesteld` to `ondertekend`) who signed as
 *   the employee and when, `medewerkerOndertekendDoor` and
 *   `medewerkerOndertekendOp`, beside the employee's optional comment
 *   `medewerkerOpmerking`.
 *
 * Signing does not change the review: on that edge, and on every write while
 * the review stays signed, the rating, strengths, development points,
 * agreements, goals and the reviewer's side keep their stored values. Leaving
 * `ondertekend` (a reopen) clears the employee's sign-off, so a corrected
 * review is signed again. Who may sign is the ReviewSignOffGuard's call.
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
 * @spec openspec/changes/matrix-rows-without-a-requirement/specs/performance-reviews/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCA\Humaniq\Service\OrgLookupInterface;
use OCA\Humaniq\Service\RecordStoreInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Stamps the reviewer's and the employee's sign-off on a performance review.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/matrix-rows-without-a-requirement/specs/performance-reviews/spec.md
 */
class ReviewSignOffStampListener implements IEventListener {

	use StampsProcessFields;

	/**
	 * Lower-cased slug of the review schema.
	 *
	 * @var string
	 */
	public const SLUG = 'performancereview';

	/**
	 * The status a signed review has.
	 *
	 * @var string
	 */
	public const SIGNED = 'ondertekend';

	/**
	 * The status a set review has.
	 *
	 * @var string
	 */
	public const SET = 'vastgesteld';

	/**
	 * The employee's sign-off, written only here.
	 *
	 * @var array<int, string>
	 */
	public const SIGN_OFF_FIELDS = ['medewerkerOndertekendDoor', 'medewerkerOndertekendOp', 'medewerkerOpmerking'];

	/**
	 * What a sign-off must leave as it was.
	 *
	 * @var array<int, string>
	 */
	public const CONTENT_FIELDS = ['rating', 'sterktes', 'ontwikkelpunten', 'afspraken', 'goals', 'vastgesteldDoor', 'vastgesteldOp'];

	/**
	 * The warning logged when stamping throws.
	 *
	 * @var string
	 */
	private const STAMP_FAILURE = 'humaniq: ReviewSignOffStampListener could not stamp a PerformanceReview write';

	/**
	 * The user-facing message a write is refused with when stamping throws.
	 *
	 * @var string
	 */
	private const STAMP_REFUSAL = 'De beoordeling kon niet worden verwerkt; probeer het later opnieuw.';

	/**
	 * Constructor.
	 *
	 * @param OrgLookupInterface   $gateway     Resolves the schema slug.
	 * @param RecordStoreInterface $records     Reads the stored review when the event has no old object.
	 * @param IUserSession         $userSession The signing user.
	 * @param LoggerInterface      $logger      The logger.
	 *
	 * @spec openspec/changes/matrix-rows-without-a-requirement/specs/performance-reviews/spec.md
	 */
	public function __construct(
		private readonly OrgLookupInterface $gateway,
		private readonly RecordStoreInterface $records,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle a pre-save event for a PerformanceReview write.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/matrix-rows-without-a-requirement/specs/performance-reviews/spec.md
	 */
	public function handle(Event $event): void {
		$this->stampOrRefuse(event: $event);
	}//end handle()

	/**
	 * A new review carries no sign-off: whatever the client sent is dropped.
	 *
	 * @param \OCA\OpenRegister\Event\ObjectCreatingEvent $event The pre-save event.
	 *
	 * @return void
	 */
	private function stampCreating(\OCA\OpenRegister\Event\ObjectCreatingEvent $event): void {
		if ($this->isReview(entity: $event->getObject()) === true) {
			$event->setModifiedData(self::cleared());
		}
	}//end stampCreating()

	/**
	 * An update is judged against the stored review; without it the write is refused.
	 *
	 * @param \OCA\OpenRegister\Event\ObjectUpdatingEvent $event The pre-save event.
	 *
	 * @return void
	 */
	private function stampUpdating(\OCA\OpenRegister\Event\ObjectUpdatingEvent $event): void {
		$entity = $event->getNewObject();
		if ($this->isReview(entity: $entity) === false) {
			return;
		}

		$stored = $event->getOldObject()?->getObject();
		if (is_array($stored) === false) {
			$stored = $this->records->findObjectData((string)$entity->getUuid(), 'PerformanceReview');
		}

		if ($stored === null) {
			$this->refuse(event: $event, message: 'De beoordeling kon niet worden geladen; de wijziging is niet opgeslagen.');
			return;
		}

		$event->setModifiedData($this->stamps(incoming: ($entity->getObject() ?? []), stored: $stored));
	}//end stampUpdating()

	/**
	 * The server-owned fields of one update.
	 *
	 * @param array<string, mixed> $incoming The review as written.
	 * @param array<string, mixed> $stored   The review as stored.
	 *
	 * @return array<string, mixed> The fields to set.
	 *
	 * @spec openspec/changes/matrix-rows-without-a-requirement/specs/performance-reviews/spec.md
	 */
	public function stamps(array $incoming, array $stored): array {
		$from = (string)($stored['status'] ?? '');
		$to = (string)($incoming['status'] ?? $from);

		if ($to === self::SIGNED && $from === self::SET) {
			return array_merge(
				self::kept(stored: $stored),
				[
					'medewerkerOndertekendDoor' => (string)($this->userSession->getUser()?->getUID() ?? ''),
					'medewerkerOndertekendOp' => substr($this->now(), 0, 10),
					'medewerkerOpmerking' => self::nullableTrim(value: $incoming['medewerkerOpmerking'] ?? null),
				]
			);
		}

		if ($to === self::SIGNED && $from === self::SIGNED) {
			$signOff = [];
			foreach (self::SIGN_OFF_FIELDS as $field) {
				$signOff[$field] = ($stored[$field] ?? null);
			}

			return array_merge(self::kept(stored: $stored), $signOff);
		}

		$stamps = self::cleared();
		$stamps['vastgesteldOp'] = ($stored['vastgesteldOp'] ?? null);
		if ($to === self::SET && $from !== self::SET) {
			$stamps['vastgesteldOp'] = substr($this->now(), 0, 10);
		}

		return $stamps;
	}//end stamps()

	/**
	 * The stored content of the review, kept as it was.
	 *
	 * @param array<string, mixed> $stored The review as stored.
	 *
	 * @return array<string, mixed>
	 */
	private static function kept(array $stored): array {
		$kept = [];
		foreach (self::CONTENT_FIELDS as $field) {
			$kept[$field] = ($stored[$field] ?? null);
		}

		return $kept;
	}//end kept()

	/**
	 * No employee sign-off.
	 *
	 * @return array<string, null>
	 */
	private static function cleared(): array {
		return array_fill_keys(self::SIGN_OFF_FIELDS, null);
	}//end cleared()

	/**
	 * Whether the entity is a performance review.
	 *
	 * @param object $entity The object entity.
	 *
	 * @return boolean
	 */
	private function isReview(object $entity): bool {
		return strtolower($this->gateway->resolveSchemaSlug((string)$entity->getSchema())) === self::SLUG;
	}//end isReview()
}//end class
