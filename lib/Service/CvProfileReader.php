<?php

/**
 * CV profile reader
 *
 * Read CV on an application: asks filinq to read the education level, the
 * years of experience and the vacancy's competences from the application's
 * CV, and fills them only when HR has not filled the profile yet. filinq is
 * called duck-typed, by class name, as the receipt OCR is; without it the
 * application is left as it is and the answer says why.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Service
 * @package   OCA\Humaniq\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://conduction.nl
 *
 * @spec openspec/changes/hiring-cv-profile-extraction/specs/candidate-assessment/spec.md#REQ-CAS-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCA\Humaniq\Support\FleetAppId;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fills an empty candidate profile from the CV.
 *
 * @spec openspec/changes/hiring-cv-profile-extraction/specs/candidate-assessment/spec.md#REQ-CAS-004
 */
class CvProfileReader {

	/**
	 * filinq's profile contract, relative to its namespace (filinq#2320).
	 */
	private const PROFILE_SERVICE = 'Service\ProfileExtractionService';

	/**
	 * @param ContainerInterface   $container The server container, to find filinq.
	 * @param RecordStoreInterface $store     Reads the vacancy and saves the application.
	 * @param HumaniqRoles         $roles     Who is HR.
	 * @param LoggerInterface      $logger    Logs a failed read.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly RecordStoreInterface $store,
		private readonly HumaniqRoles $roles,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Read the CV of an application into its profile.
	 *
	 * The answer is `{status, message, profile}`: `filled` with the fields
	 * written, or `forbidden` (not HR or an administrator), `kept` (HR filled
	 * the profile), `no-cv`, `skipped-no-filinq`, `unreadable` or `failed`,
	 * each leaving the application unchanged.
	 *
	 * @param array<string, mixed> $application The application, as the caller may read it.
	 * @param string               $userId      Who asked.
	 *
	 * @return array{status: string, message: string, profile: array<string, mixed>}
	 *
	 * @spec openspec/changes/hiring-cv-profile-extraction/specs/candidate-assessment/spec.md#REQ-CAS-004
	 */
	public function read(array $application, string $userId): array {
		if ($this->roles->isHr($userId) === false) {
			return self::answer(status: 'forbidden', message: 'Only HR or an administrator can read a CV into the profile.');
		}

		if (self::hasProfile(application: $application) === true) {
			return self::answer(status: 'kept', message: 'The profile was filled by hand and is left as it is.');
		}

		$reference = self::fileReference(value: ($application['cvFile'] ?? null));
		if ($reference === '') {
			return self::answer(status: 'no-cv', message: 'This application has no CV to read.');
		}

		$filinq = FleetAppId::getService($this->container, 'filinq', self::PROFILE_SERVICE);
		if ($filinq === null || method_exists($filinq, 'extractProfile') === false) {
			return self::answer(status: 'skipped-no-filinq', message: 'Reading a CV needs filinq, which is not installed. Type the profile instead.');
		}

		$request = ['competenceCodes' => $this->competencesOf(application: $application)];
		$request[(ctype_digit($reference) === true ? 'fileId' : 'documentUri')] = $reference;
		try {
			$read = (array)$filinq->extractProfile($request, $userId);
		} catch (Throwable $e) {
			$this->logger->warning('CvProfileReader: filinq could not read the CV: ' . $e->getMessage());
			return self::answer(status: 'failed', message: 'The CV could not be read. Type the profile instead.');
		}

		if (($read['status'] ?? '') !== 'extracted') {
			return self::answer(status: 'unreadable', message: (string)($read['message'] ?? 'The CV could not be read.'));
		}

		return $this->fill(application: $application, read: $read);
	}//end read()

	/**
	 * Whether HR filled any part of the profile.
	 *
	 * @param array<string, mixed> $application The application.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/hiring-cv-profile-extraction/specs/candidate-assessment/spec.md#REQ-CAS-004
	 */
	public static function hasProfile(array $application): bool {
		return (($application['educationLevel'] ?? null) !== null
			|| ($application['experienceYears'] ?? null) !== null
			|| ((array)($application['competenceCodes'] ?? [])) !== []);
	}//end hasProfile()

	/**
	 * Write what the CV gave and mark the profile as read from the CV; when
	 * it gave nothing, write nothing.
	 *
	 * @param array<string, mixed> $application The application.
	 * @param array<string, mixed> $read        filinq's answer.
	 *
	 * @return array{status: string, message: string, profile: array<string, mixed>}
	 */
	private function fill(array $application, array $read): array {
		$profile = array_filter(
			[
				'educationLevel' => (is_int($read['educationLevel'] ?? null) === true ? $read['educationLevel'] : null),
				'experienceYears' => (is_numeric($read['experienceYears'] ?? null) === true ? (float)$read['experienceYears'] : null),
				'competenceCodes' => array_values(array_map('strval', (array)($read['competenceCodes'] ?? []))),
			],
			static fn (mixed $value): bool => ($value !== null && $value !== [])
		);
		if ($profile === []) {
			return self::answer(status: 'unreadable', message: 'The CV names no education level, experience or competence that could be read. Type the profile instead.');
		}

		$saved = array_merge($application, $profile, ['profileSource' => 'cv']);
		unset($saved['@self']);
		$this->store->save($saved, 'job-application', (string)($application['id'] ?? $application['@self']['id'] ?? ''));

		return self::answer(status: 'filled', message: 'The profile was read from the CV. Check it before you compare candidates.', profile: $profile);
	}//end fill()

	/**
	 * The competence codes the vacancy asks for, required first.
	 *
	 * @param array<string, mixed> $application The application.
	 *
	 * @return list<string>
	 */
	private function competencesOf(array $application): array {
		$vacancyId = trim((string)($application['vacancyId'] ?? ''));
		$vacancy = ($vacancyId === '' ? null : $this->store->findObjectData($vacancyId, 'Vacancy'));
		$codes = array_merge((array)($vacancy['requiredCompetences'] ?? []), (array)($vacancy['preferredCompetences'] ?? []));

		return array_values(array_unique(array_filter(array_map(static fn (mixed $code): string => trim((string)$code), $codes), static fn (string $code): bool => $code !== '')));
	}//end competencesOf()

	/**
	 * The CV as filinq takes it: a file id as digits, a Files path, or ''.
	 * `cvFile` holds an OpenRegister file id, its rendered file object, or a
	 * path saved before (the `Expense.receiptFile` pattern).
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private static function fileReference(mixed $value): string {
		if (is_array($value) === true) {
			$value = ($value['id'] ?? $value['fileId'] ?? $value['path'] ?? null);
		}

		if (is_int($value) === true && $value > 0) {
			return (string)$value;
		}

		return (is_string($value) === true ? trim($value) : '');
	}//end fileReference()

	/**
	 * An answer.
	 *
	 * @param string               $status  What happened.
	 * @param string               $message What to tell HR.
	 * @param array<string, mixed> $profile The fields written.
	 *
	 * @return array{status: string, message: string, profile: array<string, mixed>}
	 */
	private static function answer(string $status, string $message, array $profile = []): array {
		return ['status' => $status, 'message' => $message, 'profile' => $profile];
	}//end answer()
}//end class
