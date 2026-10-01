<?php

/**
 * Third-Party Statement Service
 *
 * The yearly statement a third-party payee gets of what was paid to them
 * and reported (filings-ib47, document type `ubd-jaaropgaaf`): the variable
 * contract, and its generation through filinq's template store in the
 * `hrmq` namespace (the HR documents' discovery rule: exactly one template
 * whose category is the document type, fail closed on none or several),
 * stored as a file on the payee.
 *
 * @category Service
 * @package  OCA\Humaniq\Service
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
 * @spec openspec/specs/third-party-payments/spec.md#REQ-UBD-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCA\Humaniq\Support\FleetAppId;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The ubd-jaaropgaaf statement for a third-party payee.
 *
 * @spec openspec/specs/third-party-payments/spec.md#REQ-UBD-003
 */
class ThirdPartyStatementService {

	/**
	 * The document type, also the filinq template category.
	 *
	 * @var string
	 */
	public const DOCUMENT_TYPE = 'ubd-jaaropgaaf';

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway   The register plumbing.
	 * @param ContainerInterface   $container Resolves filinq and OpenRegister's FileService.
	 * @param SettingsService      $settings  The register slug.
	 * @param LoggerInterface      $logger    The logger.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly ContainerInterface $container,
		private readonly SettingsService $settings,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The statement's variables, or null when the payee does not resolve.
	 *
	 * @param string $payeeId The ThirdPartyPayee id.
	 * @param int    $year    The year.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/third-party-payments/spec.md#REQ-UBD-003
	 */
	public function variables(string $payeeId, int $year): ?array {
		$payee = $this->gateway->findObjectData(uuid: $payeeId, schema: 'ThirdPartyPayee');
		if ($payee === null) {
			return null;
		}

		$administrationId = (string)($payee['administrationId'] ?? '');
		$administration = ($this->gateway->findFiltered('hrAdministration', ['administrationId' => $administrationId])[0] ?? []);

		$payments = [];
		$cents = 0;
		foreach ($this->gateway->findFiltered('ThirdPartyPayment', ['payeeId' => $payeeId]) as $payment) {
			$paidOn = (string)($payment['paidOn'] ?? '');
			if (str_starts_with($paidOn, $year . '-') === false) {
				continue;
			}

			$amount = (int)round((float)($payment['amount'] ?? 0) * 100);
			$expense = (int)round((float)($payment['expenseAllowance'] ?? 0) * 100);
			$cents += ($amount + $expense);
			$payments[] = ['paidOn' => $paidOn, 'description' => (string)($payment['description'] ?? ''), 'amount' => round($amount / 100, 2), 'expenseAllowance' => round($expense / 100, 2), 'total' => round(($amount + $expense) / 100, 2)];
		}

		usort($payments, static fn (array $a, array $b): int => $a['paidOn'] <=> $b['paidOn']);

		return [
			'documentType' => self::DOCUMENT_TYPE,
			'year' => $year,
			'payee' => [
				'name' => trim(implode(' ', array_filter([(string)($payee['initials'] ?? ''), (string)($payee['prefix'] ?? ''), (string)($payee['lastName'] ?? '')], static fn (string $p): bool => trim($p) !== ''))),
				'addressLines' => [
					trim((string)($payee['street'] ?? '') . ' ' . (string)($payee['houseNumber'] ?? '') . (string)($payee['houseNumberAddition'] ?? '')),
					trim((string)($payee['postcode'] ?? '') . ' ' . (string)($payee['city'] ?? '')),
				],
				'lastName' => (string)($payee['lastName'] ?? ''),
				'bsn' => (string)($payee['bsn'] ?? ''),
				'dateOfBirth' => (string)($payee['dateOfBirth'] ?? ''),
				'activity' => (string)($payee['activity'] ?? ''),
			],
			'administration' => [
				'administrationId' => $administrationId,
				'name' => (string)($administration['name'] ?? ''),
				'loonheffingennummer' => (string)($administration['loonheffingennummer'] ?? ''),
				'postalAddress' => (string)($administration['postalAddress'] ?? ''),
			],
			'payments' => $payments,
			'totalPaid' => round($cents / 100, 2),
			'totalReported' => intdiv($cents, 100),
		];
	}//end variables()

	/**
	 * Generate the statement through filinq and store it on the payee.
	 *
	 * @param string $payeeId The ThirdPartyPayee id.
	 * @param int    $year    The year.
	 * @param string $userId  The account generating it.
	 *
	 * @return array{status: string, message: string, fileName?: string}
	 *
	 * @spec openspec/specs/third-party-payments/spec.md#REQ-UBD-003
	 */
	public function generate(string $payeeId, int $year, string $userId): array {
		$variables = $this->variables(payeeId: $payeeId, year: $year);
		if ($variables === null || $variables['payments'] === []) {
			return ['status' => 'nothing-to-state', 'message' => 'Aan deze ontvanger is in ' . $year . ' niets betaald.'];
		}

		$templates = FleetAppId::getService($this->container, 'filinq', 'Service\TemplateService');
		$documents = FleetAppId::getService($this->container, 'filinq', 'Service\DocumentService');
		if ($templates === null || $documents === null) {
			return ['status' => 'skipped-no-filinq', 'message' => 'filinq is niet geïnstalleerd; de jaaropgaaf kan later worden gemaakt.'];
		}

		try {
			$matches = array_values(array_filter((array)$templates->getTemplatesByNamespace('hrmq'), static fn (mixed $t): bool => is_array($t) === true && ($t['category'] ?? '') === self::DOCUMENT_TYPE));
			if (count($matches) !== 1) {
				return ['status' => 'failed', 'message' => count($matches) . ' filinq-sjablonen voor "' . self::DOCUMENT_TYPE . '" in namespace "hrmq"; er moet er precies een zijn.'];
			}

			$rendered = $documents->generateDocument(
				(string)($matches[0]['id'] ?? ''),
				[['register' => $this->settings->getRegisterSlug(), 'schema' => 'ThirdPartyPayee', 'id' => $payeeId]],
				['format' => 'pdf', 'userId' => $userId, 'adHocData' => ['statement' => $variables]]
			);
			$content = (string)($rendered['content'] ?? '');
			if ($content === '') {
				return ['status' => 'failed', 'message' => 'filinq leverde geen documentinhoud terug.'];
			}

			$fileName = sprintf('%s-%d-%s.pdf', self::DOCUMENT_TYPE, $year, trim(strtolower((string)preg_replace('/[^A-Za-z0-9]+/', '-', $variables['payee']['lastName'])), '-'));
			$this->container->get('OCA\OpenRegister\Service\FileService')->addFile($payeeId, $fileName, $content);
		} catch (\Throwable $e) {
			$this->logger->warning('ThirdPartyStatementService: statement for ' . $payeeId . ' failed: ' . $e->getMessage());
			return ['status' => 'failed', 'message' => 'De jaaropgaaf kon niet worden gemaakt: ' . $e->getMessage()];
		}

		return ['status' => 'generated', 'message' => 'Jaaropgaaf gegenereerd en bij de ontvanger opgeslagen.', 'fileName' => $fileName];
	}//end generate()

}//end class
