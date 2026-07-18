<?php

/**
 * AVG DSR Request Store
 *
 * The `DsrRequest` bookkeeping record's load/save mechanics, extracted from
 * `AvgDsrService` to keep the DSAR composition logic (subject resolution,
 * the guarded erase call) separate from the plain OpenRegister CRUD that
 * records each operation's outcome onto the request (avg-dsr design.md D7 --
 * status transitions, `outcomeSummary`, `retainedObjectRefs`,
 * `rejectionReason`). Never writes a raw PII value (REQ-DSR-002/-005): only
 * the `held` refs OpenRegister's guarded `Gdpr\DataSubjectRequestService
 * ::erase()` returns (`uuid`/`reason` -- hrmq#99, no longer a bespoke
 * `schema`/`register`/`retainedUntil` shape) and changed field NAMES are
 * recorded.
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
 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-001
 */

declare(strict_types=1);

namespace OCA\Hrmq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use OCP\IUserSession;

/**
 * Loads and updates one `DsrRequest` record's lifecycle fields.
 */
final class AvgDsrRequestStore
{


    /**
     * @param ContainerInterface $container       DI container for the lazy ObjectService resolve.
     * @param SettingsService    $settingsService The register-slug source.
     * @param IUserSession       $userSession     The current (privileged) session -- read only for `handledBy`.
     * @param LoggerInterface    $logger          Logger.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly SettingsService $settingsService,
        private readonly IUserSession $userSession,
        private readonly LoggerInterface $logger,
    ) {

    }//end __construct()


    /**
     * RBAC-resolve a `DsrRequest` by id.
     *
     * @param string $dsrRequestId The DsrRequest id.
     *
     * @return array<string, mixed>|null
     */
    public function load(string $dsrRequestId): ?array
    {
        try {
            $entity = $this->objectService()->find(
                id: $dsrRequestId,
                register: $this->settingsService->getRegisterSlug(),
                schema: 'DsrRequest'
            );
        } catch (\Throwable $e) {
            $this->logger->info('AvgDsrRequestStore: DsrRequest '.$dsrRequestId.' kon niet worden opgehaald: '.$e->getMessage());
            return null;
        }

        if ($entity === null) {
            return null;
        }

        return $this->toArray($entity);

    }//end load()


    /**
     * Record a recorded erase-preview outcome (design.md D5) -- the evidence
     * `eraseSubject()`'s precondition checks for.
     *
     * @param string               $dsrRequestId The DsrRequest id.
     * @param array<string, mixed> $preview      {wouldErase, retained, failed}.
     *
     * @return void
     */
    public function recordPreview(string $dsrRequestId, array $preview): void
    {
        $dsrRequest = $this->load($dsrRequestId);
        if ($dsrRequest === null) {
            return;
        }

        $summary = sprintf(
            'Voorbeeld verwijdering: %d object(en) zouden worden verwijderd, %d object(en) retained (wettelijke bewaarplicht).',
            count($preview['wouldErase']),
            count($preview['retained'])
        );

        $this->save(
            $dsrRequest,
            [
                'status'             => 'in_behandeling',
                'outcomeSummary'     => $summary,
                'retainedObjectRefs' => json_encode($preview['retained']),
                'handledBy'          => $this->currentUserId(),
            ]
        );

    }//end recordPreview()


    /**
     * Record a completed export outcome.
     *
     * @param string $dsrRequestId The DsrRequest id.
     * @param string $right        `inzage` or `portabiliteit`.
     * @param int    $count        Number of objects the export returned.
     *
     * @return void
     */
    public function recordExportOutcome(string $dsrRequestId, string $right, int $count): void
    {
        $dsrRequest = $this->load($dsrRequestId);
        if ($dsrRequest === null) {
            return;
        }

        $this->save(
            $dsrRequest,
            [
                'right'          => $right,
                'status'         => 'voldaan',
                'completedDate'  => gmdate('Y-m-d\TH:i:s\Z'),
                'outcomeSummary' => sprintf('Export (%s): %d object(en) gevonden.', $right, $count),
                'handledBy'      => $this->currentUserId(),
            ]
        );

    }//end recordExportOutcome()


    /**
     * Record an erase execute outcome (design.md D5) -- `voldaan` when
     * nothing failed, `afgewezen` naming the failure count otherwise.
     *
     * @param array<string, mixed>            $dsrRequest The current DsrRequest.
     * @param array<int, array<string,mixed>> $erased     Erased/anonymised refs.
     * @param array<int, array<string,mixed>> $retained   Retention-locked refs (REQ-DSR-005).
     * @param array<int, array<string,mixed>> $failed     Failed refs.
     *
     * @return string The new status (`voldaan`|`afgewezen`).
     */
    public function recordEraseOutcome(array $dsrRequest, array $erased, array $retained, array $failed): string
    {
        $newStatus = ($failed === []) ? 'voldaan' : 'afgewezen';

        $update = [
            'status'             => $newStatus,
            'completedDate'      => gmdate('Y-m-d\TH:i:s\Z'),
            'outcomeSummary'     => sprintf(
                'Verwijdering uitgevoerd: %d verwijderd, %d retained (wettelijke bewaarplicht), %d mislukt.',
                count($erased),
                count($retained),
                count($failed)
            ),
            'retainedObjectRefs' => json_encode($retained),
            'handledBy'          => $this->currentUserId(),
        ];
        if ($newStatus === 'afgewezen') {
            $update['rejectionReason'] = sprintf('%d object(en) konden niet worden verwijderd of geanonimiseerd.', count($failed));
        }

        $this->save($dsrRequest, $update);

        return $newStatus;

    }//end recordEraseOutcome()


    /**
     * Record a rectification outcome (design.md D6) -- `afgewezen` with a
     * rejection reason on failure, `voldaan` naming only the changed field
     * NAMES (never before/after values) on success.
     *
     * @param array<string, mixed>      $dsrRequest The current DsrRequest.
     * @param array<string, mixed>|null $result     `rectifyObjectForSubject()`'s return value.
     * @param array<string, mixed>      $changes    The applied change set (field names only are recorded).
     *
     * @return void
     */
    public function recordRectifyOutcome(array $dsrRequest, ?array $result, array $changes): void
    {
        if ($result === null) {
            $this->save(
                $dsrRequest,
                [
                    'status'          => 'afgewezen',
                    'completedDate'   => gmdate('Y-m-d\TH:i:s\Z'),
                    'rejectionReason' => 'Rectificatie mislukt — object kon niet worden geladen of bijgewerkt.',
                    'handledBy'       => $this->currentUserId(),
                ]
            );
            return;
        }

        $this->save(
            $dsrRequest,
            [
                'status'         => 'voldaan',
                'completedDate'  => gmdate('Y-m-d\TH:i:s\Z'),
                'outcomeSummary' => 'Rectificatie toegepast op veld(en): '.implode(', ', array_keys($changes)).'.',
                'handledBy'      => $this->currentUserId(),
            ]
        );

    }//end recordRectifyOutcome()


    /**
     * Merge updates onto a `DsrRequest` and save it in place (upsert keyed
     * on its own id -- never a second record).
     *
     * @param array<string, mixed> $dsrRequest The current DsrRequest.
     * @param array<string, mixed> $updates    Fields to merge over it.
     *
     * @return array<string, mixed> The saved object.
     */
    private function save(array $dsrRequest, array $updates): array
    {
        $id = (string) ($dsrRequest['id'] ?? $dsrRequest['@self']['id'] ?? '');

        $object = array_merge($dsrRequest, $updates);
        unset($object['@self']);

        $saved = $this->objectService()->saveObject(
            object: $object,
            register: $this->settingsService->getRegisterSlug(),
            schema: 'DsrRequest',
            uuid: ($id === '' ? null : $id)
        );

        return $this->toArray($saved);

    }//end save()


    /**
     * The current caller's Nextcloud user id, or '' when there is none.
     *
     * @return string
     */
    private function currentUserId(): string
    {
        return (string) ($this->userSession->getUser()?->getUID() ?? '');

    }//end currentUserId()


    /**
     * @return mixed The OpenRegister ObjectService, resolved with the caller's ambient RBAC (default $_rbac=true).
     */
    private function objectService(): mixed
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');

    }//end objectService()


    /**
     * Normalise an ObjectService row (entity or array) to an array.
     *
     * @param mixed $row The row.
     *
     * @return array<string, mixed>
     */
    private function toArray(mixed $row): array
    {
        if (is_array($row) === true) {
            return $row;
        }

        if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
            return (array) $row->jsonSerialize();
        }

        return [];

    }//end toArray()


}//end class
