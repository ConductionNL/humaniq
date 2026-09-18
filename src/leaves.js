/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The `humaniq-leaves` bundle: this app's OpenRegister leaves, and nothing else.
 *
 * WHERE THIS RUNS. Not on humaniq's own pages, where `main.js` already registers
 * the leaf. This entry is what OpenRegister's LeafScriptListener enqueues on
 * OTHER apps' pages, so a dossiq case page can show the hours booked against
 * that case without dossiq reading humaniq's register itself.
 *
 * WHY IT EXISTS AT ALL. It did not, and that is why `humaniq-hours` rendered
 * nowhere for as long as it shipped. Both halves of the descriptor were
 * registered and the parity gate compared them to each other and passed, but
 * nothing put humaniq's code on a consuming page: the listener looks for
 * `js/humaniq-leaves.js`, finds nothing, and skips the app silently, because
 * enqueuing a script that does not exist is a 404 in someone else's page.
 *
 * WHY IT IS A SEPARATE ENTRY. `humaniq-main.js` carries the whole SPA. Loading
 * that on another app's page would trade a feature for a performance regression
 * on every page of every consuming app.
 *
 * KEEP IT THIN. Anything imported here lands on other apps' pages. Import the
 * leaf registrations and nothing else: no router, no pinia, no app shell, no
 * `./manifest.json`, and no component library.
 */
import { loadTranslations } from '@nextcloud/l10n'
import { registerAgendaLeaf } from './integrations/registerAgendaLeaf.js'
import { registerHoursLeaf } from './integrations/registerHoursLeaf.js'

// Register FIRST, translate second.
//
// The registry entry must exist before the host looks for it, and the host may
// look during the same tick. `loadTranslations` is a fetch, so awaiting it
// before registering would lose the race on a cold cache and the leaf would
// simply not be in the registry when the page asked, rendering nothing with
// nothing logged. The labels fall back to their English source until the
// catalogue lands, which is the lesser failure and a visible one.
registerHoursLeaf()
registerAgendaLeaf()

// `loadTranslations` REJECTS on a 404, which is any locale for which
// l10n/<lang>.json was never generated, so an unguarded call here would produce
// an unhandled rejection inside a page that does not belong to this app.
loadTranslations('humaniq').catch(() => {
	// Deliberately silent: this bundle runs inside another app's page, where a
	// console warning about humaniq's catalogue is noise the reader cannot act
	// on. The leaf still renders, in English.
})
