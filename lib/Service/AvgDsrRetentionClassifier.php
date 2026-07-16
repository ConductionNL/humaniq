<?php

/**
 * AVG DSR Retention Classifier
 *
 * The pure, side-effect-free statutory-retention predicate `AvgDsrService`
 * composes (avg-dsr design.md D4): splits every object
 * `DsarService::findObjectsForSubject()` returned into `retained` (a
 * populated `retainedUntil`/`identityDocumentRetainedUntil` dated on or
 * after today, or -- when unpopulated -- the AWR art. 52 lid 4 7-year
 * fallback derivation for the payroll/loonadministratie schema family) and
 * `eligible`. Extracted from `AvgDsrService` to keep the classification
 * predicate small, testable in isolation, and independent of the
 * OpenRegister I/O the rest of the service performs.
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
 * @spec openspec/changes/avg-dsr/specs/avg-dsr/spec.md#REQ-DSR-005
 */

declare(strict_types=1);

namespace OCA\Hrmq\Service;

use DateTimeImmutable;

/**
 * Pure predicate: classifies matched objects as retention-locked or
 * erase-eligible.
 */
final class AvgDsrRetentionClassifier
{

    /**
     * Schemas AVG art. 17 lid 3 sub b / AWR art. 52 lid 4 statutory retention
     * applies to when a `retainedUntil`/`identityDocumentRetainedUntil` field
     * is unpopulated (design.md D4) -- the payroll/loonadministratie family.
     *
     * @var array<int, string>
     */
    private const PAYROLL_FAMILY_SCHEMAS = [
        'Payslip',
        'PayrollRun',
        'LoonaangifteFiling',
        'PayrollMutationReport',
        'WkrDeclaration',
        'WkrAssessment',
    ];

    /**
     * AWR art. 52 lid 4 minimum retention duration in years -- the identical
     * constant `NlWageTaxFilingChecks::retainedYearsAfterPeriod()` already
     * enforces for `LoonaangifteFiling` (design.md D4). That method is
     * private to its CheckProvider, so this class replicates its exact
     * "31 December of (period year + 7)" derivation rather than
     * reimplementing AWR art. 52 lid 4 differently.
     *
     * @var int
     */
    private const AWR_RETENTION_YEARS = 7;

    /**
     * The label stamped on every retention-locked object (REQ-DSR-005) --
     * visible and reported, never a silent omission.
     *
     * @var string
     */
    public const RETAINED_LABEL = 'retained (wettelijke bewaarplicht)';


    /**
     * Classify every `findObjectsForSubject()` envelope as retention-locked
     * (`retained`) or erase-eligible (`eligible`).
     *
     * @param array<int, array<string, mixed>> $envelopes `findObjectsForSubject()`'s return value.
     *
     * @return array{retained: array<int, array<string, mixed>>, eligible: array<int, array<string, mixed>>}
     *
     * @spec openspec/changes/avg-dsr/specs/avg-dsr/spec.md#REQ-DSR-005
     */
    public function classify(array $envelopes): array
    {
        $retained = [];
        $eligible = [];
        $today    = new DateTimeImmutable('today');

        foreach ($envelopes as $envelope) {
            $this->classifyOne((array) ($envelope['object'] ?? []), $today, $retained, $eligible);
        }

        return [
            'retained' => $retained,
            'eligible' => $eligible,
        ];

    }//end classify()


    /**
     * Classify one object, appending to `$retained`/`$eligible` in place.
     *
     * @param array<string, mixed>            $object   The object data (as `findObjectsForSubject()`'s envelope carries it).
     * @param DateTimeImmutable                $today    Today, for the "on or after today" comparison.
     * @param array<int, array<string,mixed>> $retained Accumulator, by reference.
     * @param array<int, array<string,mixed>> $eligible Accumulator, by reference.
     *
     * @return void
     */
    private function classifyOne(array $object, DateTimeImmutable $today, array &$retained, array &$eligible): void
    {
        $uuid = (string) ($object['id'] ?? '');
        if ($uuid === '') {
            return;
        }

        $self     = (array) ($object['@self'] ?? []);
        $schema   = (string) ($self['schema'] ?? '');
        $register = (string) ($self['register'] ?? '');

        $retentionDate = ($this->populatedRetentionDate($object) ?? $this->derivedRetentionDate($object, $schema));

        if ($retentionDate !== null && $retentionDate >= $today) {
            $retained[] = [
                'uuid'          => $uuid,
                'schema'        => $schema,
                'register'      => $register,
                'retainedUntil' => $retentionDate->format('Y-m-d'),
                'label'         => self::RETAINED_LABEL,
            ];
            return;
        }

        $eligible[] = [
            'uuid'     => $uuid,
            'schema'   => $schema,
            'register' => $register,
        ];

    }//end classifyOne()


    /**
     * A populated `retainedUntil`/`identityDocumentRetainedUntil` value,
     * whichever the schema defines (design.md D4) -- the authoritative
     * source when HR has entered it; wins over the derived fallback even
     * when it is a date in the PAST (retention has lapsed).
     *
     * @param array<string, mixed> $object The object data.
     *
     * @return DateTimeImmutable|null
     */
    private function populatedRetentionDate(array $object): ?DateTimeImmutable
    {
        foreach (['retainedUntil', 'identityDocumentRetainedUntil'] as $field) {
            $value = (string) ($object[$field] ?? '');
            if ($value === '') {
                continue;
            }

            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                return (new DateTimeImmutable())->setTimestamp($timestamp);
            }
        }

        return null;

    }//end populatedRetentionDate()


    /**
     * The AWR art. 52 lid 4 fallback derivation (design.md D4) -- ONLY for
     * the payroll/loonadministratie schema family, and ONLY when a period
     * year can be extracted. Identical formula to
     * `NlWageTaxFilingChecks::retainedYearsAfterPeriod()`: 31 December of
     * (period year + 7).
     *
     * @param array<string, mixed> $object The object data.
     * @param string                $schema The object's schema.
     *
     * @return DateTimeImmutable|null
     */
    private function derivedRetentionDate(array $object, string $schema): ?DateTimeImmutable
    {
        if (in_array($schema, self::PAYROLL_FAMILY_SCHEMAS, true) === false) {
            return null;
        }

        $periodYear = $this->extractPeriodYear($object);
        if ($periodYear === null) {
            return null;
        }

        $timestamp = mktime(0, 0, 0, 12, 31, ($periodYear + self::AWR_RETENTION_YEARS));
        if ($timestamp === false) {
            return null;
        }

        return (new DateTimeImmutable())->setTimestamp($timestamp);

    }//end derivedRetentionDate()


    /**
     * Extract a 4-digit period year from whichever period-shaped field the
     * object carries (`period`/`toPeriod`/`fromPeriod` as YYYY-MM or
     * YYYY-Pnn, `date` as YYYY-MM-DD, or an integer `year`) -- design.md D4's
     * "carries a period (or date/fromPeriod) field".
     *
     * @param array<string, mixed> $object The object data.
     *
     * @return int|null
     */
    private function extractPeriodYear(array $object): ?int
    {
        foreach (['period', 'toPeriod', 'fromPeriod', 'date'] as $field) {
            $value = (string) ($object[$field] ?? '');
            if (preg_match('/^(\d{4})/', $value, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        if (isset($object['year']) === true && is_numeric($object['year']) === true) {
            return (int) $object['year'];
        }

        return null;

    }//end extractPeriodYear()


}//end class
