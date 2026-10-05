<?php

/**
 * UBD Message
 *
 * Renders and validates the Belastingdienst's delivery message for
 * payments to third parties (UBD 1.0, formerly IB47) against the XSD the
 * Belastingdienst publishes (UBD_1.0_V1.20211028.xsd, CC0, shipped in
 * lib/Standards/ubd/ from the ODB product zip "Uitbetaalde Bedragen aan
 * Derden m.i.v. 01-01-2024 v08"). An inhoudingsplichtige reports as IHP
 * bron with its loonheffingennummer; every line is one opvoerofwijziging.
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
 * @spec openspec/specs/third-party-payments/spec.md#REQ-UBD-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DOMDocument;
use DOMElement;
use OCA\Humaniq\Payroll\XsdFile;

/**
 * The UBD 1.0 delivery message.
 *
 * @spec openspec/specs/third-party-payments/spec.md#REQ-UBD-001
 */
final class UbdMessage {

	/**
	 * The message namespace.
	 *
	 * @var string
	 */
	public const NS = 'http://xml.belastingdienst.nl/schemas/UBD/DELIVERY/1.0';

	/**
	 * The XSD the message is validated against.
	 *
	 * @var string
	 */
	public const XSD = __DIR__ . '/../Standards/ubd/UBD_1.0_V1.20211028.xsd';

	/**
	 * Render the message.
	 *
	 * @param array<string, string>            $header The header: aanmaakmoment, leveringsId, relNr, software, softwareVersion, name, loonheffingennummer, postalAddress.
	 * @param list<array<string, string|int>>  $lines  One line per payee: meldingsId, amount, paidOn, lastName, prefix, initials, dateOfBirth, street, houseNumber, houseNumberAddition, postcode, city, country, bsn.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/third-party-payments/spec.md#REQ-UBD-001
	 */
	public static function render(array $header, array $lines): string {
		$doc = new DOMDocument('1.0', 'UTF-8');
		$doc->formatOutput = true;
		$root = $doc->createElementNS(self::NS, 'UBD');
		$root->setAttribute('version', '1.0');
		$doc->appendChild($root);

		self::add($root, 'berichttype', 'UBD');
		self::add($root, 'aanmaakmoment', $header['aanmaakmoment']);
		self::add($root, 'LeveringsID', $header['leveringsId']);
		self::add($root, 'relNr', $header['relNr']);
		self::add($root, 'naamSwPakket', $header['software']);
		self::add($root, 'versieSwPakket', $header['softwareVersion']);

		$berichtgever = self::add($root, 'berichtgever');
		self::add($berichtgever, 'naamBerichtgever', $header['name']);
		self::add($berichtgever, 'LHnrBerichtgever', $header['loonheffingennummer']);

		$bron = self::add($root, 'bronbericht');
		self::add($bron, 'naamBron', $header['name']);
		$vrij = self::add(self::add($bron, 'adresBron'), 'adresvrij');
		self::add($vrij, 'adresregel', $header['postalAddress']);
		self::add($vrij, 'land', 'NL');
		$ihp = self::add($bron, 'IHPbron');
		self::add($ihp, 'LHnrBron', $header['loonheffingennummer']);

		foreach ($lines as $line) {
			self::melding(self::add($ihp, 'Uitbetalingsmelding'), $line);
		}

		return (string)$doc->saveXML();
	}//end render()

	/**
	 * The XSD validation errors of a message; empty when it is valid.
	 *
	 * @param string $xml The message.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/specs/third-party-payments/spec.md#REQ-UBD-001
	 */
	public static function errors(string $xml): array {
		$previous = libxml_use_internal_errors(true);
		libxml_clear_errors();
		$doc = new DOMDocument();
		$valid = ($xml !== '' && $doc->loadXML($xml) === true && XsdFile::validates(doc: $doc, xsd: self::XSD) === true);
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
	 * One opvoerofwijziging for a payee's year.
	 *
	 * @param DOMElement                $melding The Uitbetalingsmelding element.
	 * @param array<string, string|int> $line    The line.
	 *
	 * @return void
	 */
	private static function melding(DOMElement $melding, array $line): void {
		$opvoer = self::add($melding, 'opvoerofwijziging');
		self::add($opvoer, 'meldingsID', (string)$line['meldingsId']);
		$uitbetaling = self::add($opvoer, 'uitbetaling');
		self::add($uitbetaling, 'bedrag', (string)$line['amount']);
		self::add($uitbetaling, 'uitbetalingsDatum', (string)$line['paidOn']);

		$ontvanger = self::add($opvoer, 'ontvanger');
		self::add($ontvanger, 'achternaam', (string)$line['lastName']);
		self::addIfFilled($ontvanger, 'voorvoegsels', (string)($line['prefix'] ?? ''));
		self::add($ontvanger, 'voorletters', (string)$line['initials']);
		self::add($ontvanger, 'geboortedatum', (string)$line['dateOfBirth']);
		$vast = self::add(self::add($ontvanger, 'adresOntvanger'), 'adresvast');
		self::add($vast, 'straat', (string)$line['street']);
		self::add($vast, 'huisnummer', (string)$line['houseNumber']);
		self::addIfFilled($vast, 'huisnummerToev', (string)($line['houseNumberAddition'] ?? ''));
		self::add($vast, 'postcode', (string)$line['postcode']);
		self::add($vast, 'plaats', (string)$line['city']);
		self::add($vast, 'land', (string)$line['country']);

		self::add($opvoer, 'bSN', (string)$line['bsn']);
	}//end melding()

	/**
	 * Append a child element in the message namespace.
	 *
	 * @param DOMElement  $parent The parent.
	 * @param string      $name   The element name.
	 * @param string|null $text   The text, or null for a group.
	 *
	 * @return DOMElement
	 */
	private static function add(DOMElement $parent, string $name, ?string $text = null): DOMElement {
		$element = $parent->ownerDocument->createElementNS(self::NS, $name);
		if ($text !== null) {
			$element->appendChild($parent->ownerDocument->createTextNode($text));
		}

		$parent->appendChild($element);
		return $element;
	}//end add()

	/**
	 * Append an optional element only when it has a value.
	 *
	 * @param DOMElement $parent The parent.
	 * @param string     $name   The element name.
	 * @param string     $text   The text.
	 *
	 * @return void
	 */
	private static function addIfFilled(DOMElement $parent, string $name, string $text): void {
		if (trim($text) !== '') {
			self::add($parent, $name, trim($text));
		}
	}//end addIfFilled()

}//end class
