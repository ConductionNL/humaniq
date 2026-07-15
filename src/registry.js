/**
 * HRMQ v2 component registry (ADR-036).
 *
 * Kind-tagged map passed as the `registry` prop to CnAppRoot. HRMQ ships
 * almost exclusively declarative `type: "index"` / `type: "detail"` pages,
 * which the library renders generically from each page's register+schema
 * config. The one exception is the pro-forma payslip simulator
 * (proforma-payslip design.md D6): manifest v2 has no declarative primitive
 * for a persist-nothing interactive compute-form, so that page is a host-app
 * SFC registered here under `kind: "page"`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

import ProformaPayslip from './views/ProformaPayslip.vue'

export default {
	ProformaPayslip: {
		kind: 'page',
		component: ProformaPayslip,
		_note: 'proforma-payslip design.md D6 — persist-nothing interactive gross-to-net compute-form. open-form persists its object and api-call only interpolates fixed/@token params + toast, so neither manifest action can express "gather hypothetical inputs → POST → render breakdown, save nothing". Host-app SFC calling POST /api/payroll/proforma.',
	},
}
