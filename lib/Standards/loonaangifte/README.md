# Loonaangifte XSD (third-party file)

`Loonaangifte2026v2.0.xsd` is the Belastingdienst's XML schema for the 2026 wage tax return
(aangifte loonheffingen), message version 2.0, namespace
`http://xml.belastingdienst.nl/schemas/Loonaangifte/2026/01`. humaniq validates every message it
renders against it (`OCA\Humaniq\Payroll\Loonaangifte\LoonaangifteMessage`).

- Source: the Belastingdienst ODB product "Loonheffingen Aangifte 2026 v09",
  https://odb.belastingdienst.nl/documentatie/loonheffingen-aangifte-2026v09/, zip
  https://odb.belastingdienst.nl/wp-content/uploads/2026/01/LH2026v09.zip, folder
  "Bericht- en Gegevensspecificaties".
- Downloaded: 2026-10-02. Unchanged copy, SHA-256
  `eb862bea8c7232154cfb30bb37c4ecf192b4a86540944358b065bb7fa54fc441`.
- Licence basis, exactly as known: the ODB copyright page states that the Creative Commons Zero
  declaration (CC0 Public Domain) applies to the text of the website, "tenzij bij een bepaald
  onderdeel anders staat vermeld". Neither the zip nor the XSD carries a licence statement of its
  own. The UBD XSD in `lib/Standards/ubd/` was shipped on the same basis. Confirmation that the XSD
  may be redistributed in this EUPL-1.2 repository has been asked of the maintainer.

This directory holds the XSD and this notice only, so the file can be removed or replaced by a
fetch step in one commit. The year entry that points at it is
`OCA\Humaniq\Payroll\Loonaangifte\LoonaangifteYear`.
