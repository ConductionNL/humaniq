/**
 * HRMQ v2 component registry (ADR-036).
 *
 * Kind-tagged map passed as the `registry` prop to CnAppRoot. HRMQ ships
 * almost exclusively declarative `type: "index"` / `type: "detail"` pages,
 * which the library renders generically from each page's register+schema
 * config. The exceptions are the pro-forma payslip simulator
 * (proforma-payslip design.md D6) and the multi-administratie switcher
 * (multi-administratie design.md D4/D5): manifest v2 has no declarative
 * primitive for a persist-nothing interactive compute-form, nor for an
 * active-tenant indicator + switch backed by GET/POST endpoints, so each is
 * a host-app SFC registered here under `kind: "page"`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

import AdministrationSwitcher from './views/AdministrationSwitcher.vue'
import ProformaPayslip from './views/ProformaPayslip.vue'

export default {
	ProformaPayslip: {
		kind: 'page',
		component: ProformaPayslip,
		_note: 'proforma-payslip design.md D6 — persist-nothing interactive gross-to-net compute-form. open-form persists its object and api-call only interpolates fixed/@token params + toast, so neither manifest action can express "gather hypothetical inputs → POST → render breakdown, save nothing". Host-app SFC calling POST /api/payroll/proforma.',
	},
	AdministrationSwitcher: {
		kind: 'page',
		component: AdministrationSwitcher,
		_note: 'multi-administratie design.md D4/D5 — the ADR-001 Rule 3 active-administratie indicator + switch. The manifest v2 renderer chrome exposes no topbar-switcher slot (the known v2 renderer chrome gap) and no declarative primitive can gather the caller\'s accessible administraties, POST a guarded switch, and re-scope sibling pages. Host-app SFC calling GET/POST /api/administration/*.',
	},
}
