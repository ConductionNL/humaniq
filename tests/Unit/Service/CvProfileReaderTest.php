<?php

/**
 * CV profile reader tests
 *
 * Read CV against filinq's own ProfileExtractionService and CvProfileParser
 * (vendored in tests/stubs/Filinq from filinq#2320) over a text stub: an
 * empty profile is filled and marked as read from the CV, a profile HR typed
 * is left alone, and without filinq nothing is written.
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
 *
 * @spec openspec/changes/hiring-cv-profile-extraction/specs/candidate-assessment/spec.md#REQ-CAS-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Filinq\Service\DocumentTextSource;
use OCA\Filinq\Service\Extraction\CvProfileParser;
use OCA\Filinq\Service\ProfileExtractionService;
use OCA\Humaniq\Service\CvProfileReader;
use OCA\Humaniq\Service\RecordStoreInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Read CV through the real filinq profile rules.
 */
class CvProfileReaderTest extends TestCase {

	/**
	 * The text of CV file 501.
	 */
	private const CV = "Opleiding\nMaster Bedrijfskunde\n\nWerkervaring\n2018 - 2021 Salarisadministrateur\n2021 - heden Payroll specialist\nloonheffing, Excel\n";

	/**
	 * What the store saved, as [payload, schema, uuid].
	 *
	 * @var list<array{0: array<string, mixed>, 1: string, 2: string|null}>
	 */
	private array $saved = [];

	/**
	 * The reader, with or without filinq.
	 *
	 * @param bool $filinq Whether filinq is installed.
	 *
	 * @return CvProfileReader
	 */
	private function reader(bool $filinq = true): CvProfileReader {
		$source = new DocumentTextSource();
		$source->texts[501] = self::CV;
		$time = $this->createMock(ITimeFactory::class);
		$time->method('now')->willReturn(new DateTimeImmutable('2026-11-15'));
		$profiles = new ProfileExtractionService(textSource: $source, parser: new CvProfileParser(), time: $time);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($filinq, $profiles): object {
				if ($filinq === true && $id === ProfileExtractionService::class) {
					return $profiles;
				}

				throw new \RuntimeException('not registered: ' . $id);
			}
		);

		$store = $this->createMock(RecordStoreInterface::class);
		$store->method('findObjectData')->willReturn(['requiredCompetences' => ['loonheffing'], 'preferredCompetences' => ['excel', 'sap']]);
		$store->method('save')->willReturnCallback(
			function (array $payload, string $schema, ?string $uuid = null): object {
				$this->saved[] = [$payload, $schema, $uuid];
				return (object)$payload;
			}
		);

		return new CvProfileReader(container: $container, store: $store, logger: $this->createMock(LoggerInterface::class));
	}//end reader()

	/**
	 * An application with CV file 501 and no profile.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return array<string, mixed>
	 */
	private function application(array $overrides = []): array {
		return array_merge(['id' => 'app-1', 'vacancyId' => 'vac-1', 'cvFile' => 501, 'educationLevel' => null, 'experienceYears' => null, 'competenceCodes' => []], $overrides);
	}//end application()

	/**
	 * "A CV fills an empty profile": level, years and the vacancy's
	 * competences found in the CV, marked as read from the CV.
	 *
	 * @return void
	 */
	public function testACvFillsAnEmptyProfile(): void {
		$answer = $this->reader()->read(application: $this->application(), userId: 'hr-demo');

		$this->assertSame('filled', $answer['status']);
		$this->assertCount(1, $this->saved);
		[$payload, $schema, $uuid] = $this->saved[0];
		$this->assertSame('job-application', $schema);
		$this->assertSame('app-1', $uuid);
		$this->assertSame(7, $payload['educationLevel']);
		$this->assertSame(8.8, $payload['experienceYears']);
		$this->assertSame(['loonheffing', 'excel'], $payload['competenceCodes']);
		$this->assertSame('cv', $payload['profileSource']);
	}//end testACvFillsAnEmptyProfile()

	/**
	 * A rendered file object names the file by its id.
	 *
	 * @return void
	 */
	public function testARenderedFileObjectIsReadByItsId(): void {
		$answer = $this->reader()->read(application: $this->application(['cvFile' => ['id' => '501', 'path' => '/cv.pdf']]), userId: 'hr-demo');

		$this->assertSame('filled', $answer['status']);
	}//end testARenderedFileObjectIsReadByItsId()

	/**
	 * "A profile HR typed is left alone".
	 *
	 * @return void
	 */
	public function testAProfileHrTypedIsLeftAlone(): void {
		$answer = $this->reader()->read(application: $this->application(['experienceYears' => 3]), userId: 'hr-demo');

		$this->assertSame('kept', $answer['status']);
		$this->assertSame([], $this->saved);
	}//end testAProfileHrTypedIsLeftAlone()

	/**
	 * Without filinq the application is unchanged and the answer says why.
	 *
	 * @return void
	 */
	public function testWithoutFilinqNothingIsWritten(): void {
		$answer = $this->reader(filinq: false)->read(application: $this->application(), userId: 'hr-demo');

		$this->assertSame('skipped-no-filinq', $answer['status']);
		$this->assertStringContainsString('filinq', $answer['message']);
		$this->assertSame([], $this->saved);
	}//end testWithoutFilinqNothingIsWritten()

	/**
	 * A file filinq cannot read leaves the application unchanged.
	 *
	 * @return void
	 */
	public function testAnUnreadableFileWritesNothing(): void {
		$answer = $this->reader()->read(application: $this->application(['cvFile' => 999]), userId: 'hr-demo');

		$this->assertSame('unreadable', $answer['status']);
		$this->assertStringContainsString('could not be read', $answer['message']);
		$this->assertSame([], $this->saved);
	}//end testAnUnreadableFileWritesNothing()

	/**
	 * An application without a CV is answered without asking filinq.
	 *
	 * @return void
	 */
	public function testAnApplicationWithoutACvIsRefused(): void {
		$answer = $this->reader()->read(application: $this->application(['cvFile' => null]), userId: 'hr-demo');

		$this->assertSame('no-cv', $answer['status']);
		$this->assertSame([], $this->saved);
	}//end testAnApplicationWithoutACvIsRefused()
}//end class
