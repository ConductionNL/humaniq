<?php

/**
 * LoonaangifteMessage
 *
 * Writes the wage tax return (aangifte loonheffingen) as XML in the
 * Belastingdienst's message format and validates it against the year's XSD
 * (lib/Standards/loonaangifte/, see LoonaangifteYear). The content and the
 * element order come from LoonaangifteMessageBuilder; this class only
 * serialises and validates, the UbdMessage precedent.
 *
 * @category Payroll
 * @package  OCA\Humaniq\Payroll\Loonaangifte
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

namespace OCA\Humaniq\Payroll\Loonaangifte;

use DOMDocument;
use DOMElement;
use OCA\Humaniq\Payroll\XsdFile;

/**
 * The wage tax return message: serialise and validate.
 *
 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-001
 */
final class LoonaangifteMessage {

	/**
	 * Serialise a message.
	 *
	 * The tree is an ordered map of element name to value: a string is a text
	 * element, a map is a group, a list repeats the element once per entry, and
	 * null leaves an optional element out.
	 *
	 * @param string               $namespace The year's message namespace.
	 * @param string               $version   The message version attribute.
	 * @param array<string, mixed> $tree      The content of the Loonaangifte element.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-001
	 */
	public static function render(string $namespace, string $version, array $tree): string {
		$doc = new DOMDocument('1.0', 'UTF-8');
		$doc->formatOutput = true;
		$root = $doc->createElementNS($namespace, 'Loonaangifte');
		$root->setAttribute('version', $version);
		$doc->appendChild($root);
		self::write($root, $namespace, $tree);

		return (string)$doc->saveXML();
	}//end render()

	/**
	 * The XSD validation errors of a message; empty when it is valid.
	 *
	 * @param string $xml The message.
	 * @param string $xsd The path of the year's XSD.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-001
	 */
	public static function errors(string $xml, string $xsd): array {
		$previous = libxml_use_internal_errors(true);
		libxml_clear_errors();
		$doc = new DOMDocument();
		$valid = ($xml !== '' && $doc->loadXML($xml) === true && (new XsdFile())->validates(doc: $doc, xsd: $xsd) === true);
		$errors = [];
		foreach (libxml_get_errors() as $error) {
			$errors[] = trim($error->message) . ' (regel ' . $error->line . ')';
		}

		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		if ($valid === false && $errors === []) {
			$errors[] = 'Het bericht is geen geldige XML.';
		}

		return $errors;
	}//end errors()

	/**
	 * Write a tree under a parent.
	 *
	 * @param DOMElement           $parent    The parent element.
	 * @param string               $namespace The namespace.
	 * @param array<string, mixed> $tree      The ordered content.
	 *
	 * @return void
	 */
	private static function write(DOMElement $parent, string $namespace, array $tree): void {
		foreach ($tree as $name => $value) {
			if ($value === null) {
				continue;
			}

			$entries = (is_array($value) === true && array_is_list($value) === true) ? $value : [$value];
			foreach ($entries as $entry) {
				$element = $parent->ownerDocument->createElementNS($namespace, (string)$name);
				$parent->appendChild($element);
				if (is_array($entry) === true) {
					self::write($element, $namespace, $entry);
					continue;
				}

				$element->appendChild($parent->ownerDocument->createTextNode((string)$entry));
			}
		}
	}//end write()

}//end class
