<?php

/**
 * XsdFile
 *
 * Validates a document against an XSD that ships with humaniq. Nextcloud's
 * OC::boot() sets a libxml external entity loader that returns null, so
 * libxml can open no file: DOMDocument::schemaValidate($path) fails on every
 * real instance and each message reads as invalid. Reading the schema with
 * PHP and handing libxml its source keeps Nextcloud's loader (and its XXE
 * protection) in place. The shipped schemas include or import no other file.
 *
 * @category Payroll
 * @package  OCA\Humaniq\Payroll
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
 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Payroll;

use DOMDocument;

/**
 * Schema validation that works inside a booted Nextcloud.
 *
 * @spec openspec/specs/third-party-payments/spec.md#REQ-UBD-001
 */
final class XsdFile {

	/**
	 * Whether the document is valid against the XSD at the given path.
	 *
	 * Errors go to libxml's error buffer, as with schemaValidate(). An
	 * unreadable schema is invalid with no libxml error; both callers then
	 * report the message as not valid.
	 *
	 * @param DOMDocument $doc The loaded document.
	 * @param string      $xsd The path of a shipped XSD.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-001
	 * @spec openspec/specs/third-party-payments/spec.md#REQ-UBD-001
	 */
	public function validates(DOMDocument $doc, string $xsd): bool {
		$source = (is_file($xsd) === true ? file_get_contents($xsd) : false);
		if ($source === false || $source === '') {
			return false;
		}

		return $doc->schemaValidateSource($source);
	}//end validates()

	/**
	 * The validation errors of a message against the XSD at the given path;
	 * empty when it is valid. Each libxml error carries its line; a message
	 * that is not valid without a libxml error is named as not valid XML.
	 * The caller's libxml error mode is restored.
	 *
	 * @param string $xml The message.
	 * @param string $xsd The path of a shipped XSD.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-001
	 * @spec openspec/specs/third-party-payments/spec.md#REQ-UBD-001
	 */
	public function errors(string $xml, string $xsd): array {
		$previous = libxml_use_internal_errors(true);
		libxml_clear_errors();
		$doc = new DOMDocument();
		$valid = ($xml !== '' && $doc->loadXML($xml) === true && $this->validates(doc: $doc, xsd: $xsd) === true);
		$errors = self::bufferedErrors();
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		if ($valid === false && $errors === []) {
			$errors[] = 'Het bericht is geen geldige XML.';
		}

		return $errors;
	}//end errors()

	/**
	 * The errors in libxml's buffer, each with its line.
	 *
	 * @return list<string>
	 */
	private static function bufferedErrors(): array {
		$errors = [];
		foreach (libxml_get_errors() as $error) {
			$errors[] = trim($error->message) . ' (regel ' . $error->line . ')';
		}

		return $errors;
	}//end bufferedErrors()
}//end class
