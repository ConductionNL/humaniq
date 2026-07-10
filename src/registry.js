/**
 * HRMQ v2 component registry (ADR-036).
 *
 * Kind-tagged map passed as the `registry` prop to CnAppRoot. HRMQ ships only
 * declarative `type: "index"` / `type: "detail"` pages, which the library
 * renders generically from each page's register+schema config — there are no
 * bespoke `type: "custom"` page components to register, so this map is empty.
 * It exists so the wiring matches the rest of the fleet and a future custom
 * page can be added without touching App.vue / main.js.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

export default {}
