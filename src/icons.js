// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Schema + menu icons used across HRMQ. CnIcon (and CnIndexPage/CnDetailPage
// headers + empty states + CnAppNav menu) look up an `icon` by PascalCase name
// in the @conduction/nextcloud-vue icon registry; unregistered names fall back
// to a help-circle. These names mirror the `icon` fields in src/manifest.json
// and lib/Settings/register.d/*.json — keep the three in sync.

import CheckDecagramOutline from 'vue-material-design-icons/CheckDecagramOutline.vue'
import ClockCheckOutline from 'vue-material-design-icons/ClockCheckOutline.vue'
import Receipt from 'vue-material-design-icons/Receipt.vue'
import ReceiptTextOutline from 'vue-material-design-icons/ReceiptTextOutline.vue'
import AccountOutline from 'vue-material-design-icons/AccountOutline.vue'

export default {
	AccountOutline,
	CheckDecagramOutline,
	ClockCheckOutline,
	Receipt,
	ReceiptTextOutline,
}
