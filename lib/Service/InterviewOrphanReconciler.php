<?php

/**
 * Interview Orphan Reconciler
 *
 * Orphan reconciliation for interview-scheduling, extracted out of
 * `InterviewSyncEngine` as its own single-responsibility collaborator
 * (design.md D4): lists the configured calendar's objects and deletes every
 * `hrmq-interview-*.ics` URI whose embedded uuid is not among the live
 * Interview-id set (a hard-deleted Interview's leftover event). Objects
 * without the `hrmq-interview-` prefix (manually created by users) are
 * never touched.
 *
 * @category Service
 * @package  OCA\Hrmq\Service
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
 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-004
 */

declare(strict_types=1);

namespace OCA\Hrmq\Service;

use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Deletes calendar objects whose source Interview no longer exists in the register.
 */
class InterviewOrphanReconciler
{

    /**
     * @var string
     */
    private const URI_PREFIX = 'hrmq-interview-';


    /**
     * @param LoggerInterface $logger Logger.
     */
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {

    }//end __construct()


    /**
     * List the configured calendar's objects and delete every
     * `hrmq-interview-*.ics` URI whose embedded uuid is not among the given
     * (full, unbounded, any-status) live id set (design.md D4).
     *
     * @param InterviewCalendarTarget          $target  The resolved sync target.
     * @param array<int, string>               $liveIds All currently-existing Interview ids.
     * @param array<int, array<string, mixed>> &$results Outcome accumulator.
     *
     * @return void
     *
     * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-004
     */
    public function reconcile(InterviewCalendarTarget $target, array $liveIds, array &$results): void
    {
        try {
            $objects = (method_exists($target->backend, 'getCalendarObjects') === true) ? $target->backend->getCalendarObjects($target->calendarId) : [];
        } catch (Throwable $e) {
            $this->logger->warning('InterviewOrphanReconciler: kon kalenderobjecten niet ophalen voor reconciliatie: '.$e->getMessage());
            return;
        }

        foreach ((is_array($objects) === true ? $objects : []) as $object) {
            $objectUri = (string) (is_array($object) === true ? ($object['uri'] ?? '') : '');
            $sourceId  = $this->parseHrmqUri($objectUri);
            if ($sourceId === null || in_array($sourceId, $liveIds, true) === true) {
                continue;
            }

            $this->deleteOrphan($target, $objectUri, $sourceId, $results);
        }

    }//end reconcile()


    /**
     * Delete one orphaned calendar object.
     *
     * @param InterviewCalendarTarget          $target    The resolved sync target.
     * @param string                           $objectUri The orphaned object's URI.
     * @param string                           $sourceId  The embedded Interview id, for the outcome row.
     * @param array<int, array<string, mixed>> &$results  Outcome accumulator.
     *
     * @return void
     */
    private function deleteOrphan(InterviewCalendarTarget $target, string $objectUri, string $sourceId, array &$results): void
    {
        try {
            if (method_exists($target->backend, 'deleteCalendarObject') === false) {
                throw new RuntimeException('CalDavBackend::deleteCalendarObject is not available.');
            }

            $target->backend->deleteCalendarObject($target->calendarId, $objectUri);
        } catch (Throwable $e) {
            $results[] = $this->outcome($sourceId, 'failed', 'Verwijderen weeskalenderobject mislukt: '.$e->getMessage());
            return;
        }

        $results[] = $this->outcome($sourceId, 'removed', 'Weeskalenderobject verwijderd (bron niet meer aanwezig in het register).');

    }//end deleteOrphan()


    /**
     * Parse an hrmq-managed object URI (`hrmq-interview-{uuid}.ics`) into
     * its source id, or null when the URI carries no hrmq-interview prefix.
     *
     * @param string $objectUri The calendar object's URI.
     *
     * @return string|null
     */
    private function parseHrmqUri(string $objectUri): ?string
    {
        if (str_starts_with($objectUri, self::URI_PREFIX) === true && str_ends_with($objectUri, '.ics') === true) {
            return substr($objectUri, strlen(self::URI_PREFIX), -4);
        }

        return null;

    }//end parseHrmqUri()


    /**
     * Build one outcome row.
     *
     * @param string $sourceId The Interview id.
     * @param string $status   `removed|failed`.
     * @param string $message  A human-readable outcome message.
     *
     * @return array<string, mixed>
     */
    private function outcome(string $sourceId, string $status, string $message): array
    {
        return [
            'type'     => 'interview',
            'sourceId' => $sourceId,
            'status'   => $status,
            'message'  => $message,
        ];

    }//end outcome()


}//end class
