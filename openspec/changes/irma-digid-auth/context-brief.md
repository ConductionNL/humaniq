---
status: draft
---
# IRMA + DigiD Authentication — Government HRM Identity Layer

## Placement & Information Architecture

**Placement type:** `SETTING` — Setting under the app's Beheer/Admin/Configuration surface. Lives in the existing settings UI; no top-level menu entry.

**Lives at:** Configuratie › Integraties

**Rationale:** Auth-layer.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

The `irma-digid-auth` app provides the authentication layer for hrmq deployments in Dutch government organisations, combining Yivi (formerly IRMA) for privacy-preserving attribute-based authentication, DigiD voor reguliere burger-/medewerker-zelfbediening, and eHerkenning voor manager- en HR-acties met verhoogde betrouwbaarheidseisen. The app delivers risk-based step-up authentication, full audit logging per inlog, fall-back via Nextcloud SSO when an external IdP is unreachable, and anti-fraud detectie op basis van inloggedrag, IP-reputatie en device-fingerprint.

Government HR systems handle special-category personal data (BSN, salarisgegevens, integriteitsmeldingen, ziekteverzuim, ras-/gezondheidsindicaties bij re-integratie) and therefore demand a level of identity assurance well beyond username/password. The Dutch eIDAS-NL stelsel maps DigiD-hoog en eHerkenning niveau 3-4 to eIDAS "substantial" en "high" respectively; the BIO (Baseline Informatiebeveiliging Overheid) verlangt voor systemen die persoonsgegevens verwerken een passend authenticatieniveau. Yivi voegt iets unieks toe: het minimaliseert dataonthulling door attribuut-gebaseerde verificatie (alleen "ouder dan 18" of "BSN bekend bij werkgever X" wordt onthuld, niet de hele identiteit).

The app abstracts five identity providers (DigiD via Logius, eHerkenning makelaars, Yivi via Privacy by Design Foundation, ADFS/Entra ID, en de built-in Nextcloud SSO) behind a single OIDC/SAML façade die voor andere hrmq-apps één enkele intentionele API levert: "geef me een geverifieerde medewerker met de juiste assurance level voor deze actie." Het ontwerp legt expliciet vast dat assurance-level een attribuut van de huidige sessie is, niet van de gebruiker — een gebruiker die gisteren met eHerkenning niveau 3 inlogde maar vandaag met DigiD midden, mag vandaag geen niveau-3 acties uitvoeren zonder step-up. Dit voorkomt sluipende privilege-escalation door cached credentials.

## Data Model

Schemas in the `hrmq-auth` register (separate from primary `hrmq` for security isolation):

- `IdentityProvider`: configuration record per IdP. `code` (digid|yivi|eherkenning|sso|fallback), `displayName_nl`, `displayName_en`, `protocol` (saml|oidc|irma), `endpoint`, `metadataUrl`, `certificate`, `assuranceLevel` (laag|midden|substantial|hoog), `eidasMapping`, `status` (active|suspended|maintenance).
- `AuthenticationContext`: per-action policy. `actionCode` (e.g. `payroll.mutate`, `bsn.update`, `dossier.view`), `minimumAssuranceLevel`, `requiredAttributes[]`, `stepUpRequired` (bool), `maxIdleMinutes`, `reauthenticationRequired` (bool), `policyOwner`.
- `Session`: live and historical sessions. `userId`, `idpUsed`, `assuranceLevel`, `attributesPresented[]`, `ipAddress`, `deviceFingerprint`, `userAgent`, `geoCity`, `startedAt`, `expiresAt`, `lastSeenAt`, `stepUpHistory[]`, `status` (active|stepped_up|expired|revoked).
- `AuthEvent`: immutable audit ledger. `sessionId`, `userId`, `eventType` (login|logout|step_up_request|step_up_success|step_up_failure|reauth|fraud_signal|anomaly), `idp`, `actionContext`, `riskScore`, `outcome`, `metadata`, `timestamp`.
- `FraudSignal`: detected anomalies. `userId`, `signalType` (impossible_travel|new_device|brute_force|credential_stuffing|tor_exit|known_bad_ip|attribute_mismatch), `severity` (low|medium|high|critical), `evidence`, `detectedAt`, `reviewStatus`, `reviewerId`, `decision`.
- `AttributeMapping`: how IdP claims map onto hrmq employee fields. `idpCode`, `idpClaimName`, `hrmqField`, `transformExpression`, `mandatory`, `lastVerifiedAt`.

Sessions and AuthEvents are append-only, with cryptographic chaining (each event's hash includes the previous event's hash per session) for tamper-evidence required by BIO.

## Requirements

**REQ-001: Multi-IdP federation façade**
- GIVEN any hrmq-app initiates a login, WHEN the user lands on the unified login page, THEN they see the four configured IdPs (Yivi, DigiD, eHerkenning, SSO) with consistent branding and the recommended default for their organisation pre-selected.
- GIVEN an IdP is in `maintenance` or `suspended` status, WHEN the login page renders, THEN that IdP is hidden or shown with a clear "tijdelijk niet beschikbaar" notice plus the suggested alternative.
- GIVEN an organisation configures a custom default IdP order, WHEN any user starts a session, THEN the configured order applies and is logged at first render for auditability.

**REQ-002: DigiD niveau midden integration**
- GIVEN a medewerker chooses DigiD, WHEN the SAML AuthnRequest is constructed, THEN it includes `AuthnContextClassRef = urn:oasis:names:tc:SAML:2.0:ac:classes:MobileTwoFactorContract` voor niveau midden, signed met het Logius-geregistreerde certificaat.
- GIVEN DigiD returns a successful response, WHEN it is processed, THEN BSN, naam en geboortedatum worden gevalideerd tegen `employee-master`, en bij een mismatch wordt de login geweigerd met een audit-event `attribute_mismatch`.
- GIVEN DigiD niveau hoog (PKIoverheid/ID-kaart) wordt gevraagd voor een gevoelige actie, WHEN de huidige sessie alleen midden heeft, THEN een step-up DigiD-flow wordt gestart en alleen de nieuwe assurance level wordt geaccepteerd, niet beide tegelijk.

**REQ-003: Yivi / IRMA attribute-based authentication**
- GIVEN a medewerker chooses Yivi, WHEN the disclosure session is constructed, THEN only the strictly necessary attributes for the action zijn opgenomen (bv. `pbdf.gemeente.fullname`, `pbdf.sidn-pbdf.email`, eventueel `pbdf.nijmegen.ageLowerOver18`) en geen overige.
- GIVEN Yivi-attributen worden teruggegeven, WHEN ze worden gecontroleerd, THEN de attribuut-handtekening wordt geverifieerd tegen de Yivi scheme-manager en de uitgifte-datum wordt gecontroleerd op niet-verlopen.
- GIVEN een organisatie wil een eigen Yivi-attribuut voor medewerkers uitgeven (bv. `gemeente-foo.medewerker.werkemail`), WHEN dit wordt geconfigureerd, THEN de issuer-private-key wordt veilig in een HSM/secrets-vault opgeslagen en uitgifte gebeurt alleen via een interne admin-flow.

**REQ-004: eHerkenning niveau 3 voor managers**
- GIVEN een gebruiker met een management-rol wil inloggen voor manager-acties, WHEN ze eHerkenning kiezen, THEN de gebruikte AuthnContext is eIDAS-substantial (niveau 3) en de KvK-koppeling van de organisatie wordt gevalideerd.
- GIVEN eHerkenning levert een ketenmachtiging, WHEN deze wordt verwerkt, THEN de machtigingsrelatie wordt expliciet getoond aan de gebruiker en gelogd in `AuthEvent.metadata.chainOfTrust`.
- GIVEN niveau 4 (high) is vereist voor uitzonderlijke acties (bv. bulk-export salaris), WHEN niveau 3 wordt gepresenteerd, THEN step-up wordt afgedwongen via een nieuwe eHerkenning-flow met sterke authenticator.

**REQ-005: Step-up bij gevoelige acties**
- GIVEN een `AuthenticationContext` met `stepUpRequired = true` voor de actie `bsn.update`, WHEN de gebruiker deze actie initieert in een sessie zonder voldoende level, THEN een step-up flow wordt gestart en de oorspronkelijke actie wordt na succes hervat.
- GIVEN een gebruiker step-up annuleert, WHEN dit wordt gedetecteerd, THEN de actie wordt geblokkeerd met een duidelijke boodschap en de poging wordt gelogd zonder de oorspronkelijke sessie te beëindigen.
- GIVEN een step-up succesvol is, WHEN de nieuwe sessie-state wordt opgeslagen, THEN het verhoogde niveau geldt voor maximaal 15 minuten of de duur van de specifieke actie (configureerbaar per `AuthenticationContext`).

**REQ-006: Audit logging per inlog**
- GIVEN elke login-poging, succesvol of niet, WHEN ze plaatsvindt, THEN een `AuthEvent` wordt geschreven met IdP, assurance level, IP, device-fingerprint, geo-locatie, en de chained hash van het vorige event.
- GIVEN de audit log wordt geëxporteerd voor een compliance-onderzoek, WHEN de export wordt gegenereerd, THEN de hash-keten wordt meegeleverd zodat tampering detecteerbaar is, en een tooling-script kan de keten valideren.
- GIVEN een medewerker een AVG-inzageverzoek doet, WHEN de export wordt aangemaakt, THEN alleen de events van die medewerker worden uitgeleverd, met IP-adressen geanonimiseerd tot /24 voor IPv4 en /48 voor IPv6.

**REQ-007: Backup auth via Nextcloud SSO**
- GIVEN alle externe IdPs zijn onbereikbaar (gedetecteerd via een healthcheck-batch), WHEN een gebruiker probeert in te loggen, THEN de Nextcloud SSO fallback wordt aangeboden met expliciete `degraded mode` melding en gereduceerde permissies (geen toegang tot acties die niveau substantial of hoger vereisen).
- GIVEN de fallback actief is, WHEN een gebruiker probeert een actie uit te voeren die een hogere assurance level vereist, THEN de actie wordt geblokkeerd en de gebruiker wordt geadviseerd om later te proberen.
- GIVEN de externe IdP weer beschikbaar is, WHEN de healthcheck dit detecteert, THEN bestaande fallback-sessies worden niet automatisch geupgraded; bij de eerstvolgende gevoelige actie wordt een herauthenticatie via de externe IdP gevraagd.

**REQ-008: Anti-fraud detection**
- GIVEN een succesvolle login, WHEN het IP en device worden vergeleken met de historie van de gebruiker, THEN signalen als `impossible_travel` (geo-afstand vs tijd), `new_device`, of `tor_exit` worden berekend en als `FraudSignal` opgeslagen met een risk score.
- GIVEN een risk score boven een drempel (configureerbaar, default 70), WHEN deze wordt overschreden tijdens login of midden-sessie, THEN de sessie wordt gemarkeerd als verdacht en een step-up wordt geforceerd vóór elke nieuwe actie.
- GIVEN een `FraudSignal` met severity `critical`, WHEN deze wordt geregistreerd, THEN de sessie wordt direct beëindigd, de SOC krijgt een real-time notificatie via webhook, en de gebruiker krijgt een mailmelding met instructies voor account-recovery.

**REQ-009: Attribute mapping en data minimization**
- GIVEN een IdP-respons bevat meer attributen dan strikt nodig, WHEN deze worden verwerkt, THEN alleen de in `AttributeMapping` als `mandatory` of `optional` gemarkeerde attributen worden naar de sessie gepromoveerd; overige worden direct gediscarded en niet gelogd.
- GIVEN een mandatory attribuut ontbreekt in de respons, WHEN deze fout wordt gedetecteerd, THEN login wordt geweigerd met een specifieke foutcode, een audit-event wordt geschreven, en de admin krijgt een operationele notificatie voor IdP-configuratie review.
- GIVEN een transformExpression wordt toegepast (bv. `pseudonymize_bsn`, `extract_initialen`), WHEN de transformatie faalt, THEN de raw-waarde wordt nooit gepersisteerd en de login wordt geweigerd.

**REQ-010: Session management & revocation**
- GIVEN een actieve sessie, WHEN de gebruiker handmatig uitlogt, THEN de sessie wordt direct gerevokeerd, een logout-event wordt naar de IdP gestuurd (Single Logout) waar ondersteund, en alle gerelateerde tokens worden geïnvalideerd.
- GIVEN een admin een gebruiker uit dienst zet in `employee-master`, WHEN dat event wordt ontvangen, THEN alle actieve sessies voor die gebruiker worden direct gerevokeerd, ongeacht IdP, en toekomstige logins worden geweigerd tot heractivering.
- GIVEN een sessie de configured `maxIdleMinutes` heeft overschreden, WHEN de eerstvolgende request binnenkomt, THEN de sessie expireert, een soft-logout event wordt gelogd, en de gebruiker wordt teruggestuurd naar de login-pagina met een vriendelijke "uw sessie is verlopen" melding.

## Standards & Sources

- **eIDAS-verordening (EU 910/2014) + Nederlandse implementatiewet** — assurance levels low/substantial/high.
- **Stelsel Elektronische Toegangsdiensten (ETD) / Logius** — DigiD, DigiD Machtigen, eHerkenning kaders.
- **DigiD koppelvlak SAML 2.0** — Logius-publicaties (interface specificatie, conformiteitstoets).
- **eHerkenning afsprakenstelsel v1.x** — actueel makelaars-protocol.
- **Yivi (voorheen IRMA) protocol + Privacy by Design Foundation specs** — attribute-based credentials.
- **OpenID Connect Core 1.0 + SAML 2.0** — federatie-protocollen.
- **BIO (Baseline Informatiebeveiliging Overheid)** — passend authenticatieniveau, audit-logging.
- **NIST SP 800-63-3** — IAL/AAL/FAL als referentie voor stap-up policies.
- **AVG art. 32 + DPIA-eisen** — gegevensbescherming, dataminimalisatie.
- **NCSC ICT-beveiligingsrichtlijnen voor webapplicaties** — sessiebeheer, anti-fraud.
- **ISO 27001 controle A.9** — toegangsbeheer.
- **NORA katern Informatiebeveiliging** — overheidsbrede architectuur.
- **Forum Standaardisatie "pas-toe-of-leg-uit"-lijst** — verplichte standaarden, o.a. OIDC, SAML 2.0, TLS 1.3.
- **NL-GOV OIDC profiel** — Nederlandse profilering van OIDC voor overheidsdiensten.
- **Wet digitale overheid (Wdo)** — kader voor inlogmiddelen voor publieke dienstverlening.
- **eIDAS 2.0 / EUDI Wallet** — toekomstige Europese identity wallet, voorbereid via Yivi-compatibele attribute flows.

## Cross-app Integration

- **hrmq base** (alle apps): elke beschermde route delegeert authenticatie naar deze app via een lichte SDK; de SDK levert `currentUser` + `currentAssuranceLevel` + `hasStepUp(actionCode)` helpers.
- **openconnector**: DigiD-adapter (SAML), eHerkenning-makelaar (SAML), Yivi-server-adapter (REST), en healthchecks naar elke IdP.
- **employee-master**: bron van waarheid voor BSN, status (in/uit dienst), rollen die assurance-level eisen voorschrijven.
- **payroll-engine-nl**: bevestigt `bsn.update`, `iban.update`, `salary.mutate` acties alleen na verhoogde authenticatie.
- **aor-ambtenarenrecht**: dossier-acties op vertrouwelijke cases vereisen step-up; klokkenluider-meldingen lopen via Yivi met pseudonieme attributen.
- **ikb-rijk-gemeenten**: medewerker-zelfbediening op midden, IKB-jaarafsluiting door HR op substantial.
- **mydash / dashboarding**: anti-fraud SOC-dashboard met FraudSignals, sessie-anomalieën, geografische heatmaps (geanonimiseerd).
- **docudesk**: documenten met `confidentialityLevel = geheim` zijn alleen leesbaar in een sessie met `assuranceLevel >= substantial`.
- **bhv-organisatie**: mobiele app authenticatie en consent-flow voor delen van locatie tijdens incidenten lopen via deze app.
- **opencatalogi**: publieke API's authentiseren bezoekers met Yivi voor abonnement op publicatie-feeds (waar gewenst).
- **SIEM / SOC-pijplijn**: alle `AuthEvent` records streamen via een audit-log-export endpoint (JSON-lines) naar de organisatie-SIEM voor real-time correlatie.

## Target Users

1. **Medewerker overheid** (1k–500k per instance) — dagelijks inloggen via DigiD/Yivi voor self-service.
2. **Manager / leidinggevende** — inloggen via eHerkenning niveau 3 voor team-acties, fiat-werk.
3. **HR-medewerker / loonadministrateur** — eHerkenning niveau 3-4 voor mutaties, met step-up voor bulk-acties.
4. **IT-security officer / SOC-analist** — beheerdersdashboard, fraud-signalen, sessie-overzicht; reviewt en sluit `FraudSignal`s af.
5. **Privacy officer / FG** — DPIA-monitoring, dataminimalisatie-audit, jaarrapport AP.
6. **Auditor (intern, ADR, accountantsdienst)** — read-only audit log met hash-keten validator.
7. **Burger / extern (in beperkte scenario's)** — inzage in eigen werkgever-dossier via DigiD wanneer ex-medewerker.
8. **IdP-leverancier / Logius / makelaar** — operationeel contact bij incidenten, niet directe gebruiker maar wel SLA-contractant.
9. **Penetration tester / red team** — periodieke security-assessment van auth-flows, sessie-management, fraud-detection effectiviteit; ontvangt aparte test-tenant met realistische data.
10. **DPO / functionaris voor gegevensbescherming** — periodieke review van dataminimalisatie in `AttributeMapping`, controle dat geen overtollige claims worden gelogd, signoff op DPIA-updates bij nieuwe IdP-koppelingen.
