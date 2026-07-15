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
import Account from 'vue-material-design-icons/Account.vue'
import ViewDashboardOutline from 'vue-material-design-icons/ViewDashboardOutline.vue'
import CalendarClock from 'vue-material-design-icons/CalendarClock.vue'
import CogPlayOutline from 'vue-material-design-icons/CogPlayOutline.vue'
import ScaleBalance from 'vue-material-design-icons/ScaleBalance.vue'
import EmoticonSickOutline from 'vue-material-design-icons/EmoticonSickOutline.vue'
import FileSendOutline from 'vue-material-design-icons/FileSendOutline.vue'
import PiggyBankOutline from 'vue-material-design-icons/PiggyBankOutline.vue'
import AccountPlus from 'vue-material-design-icons/AccountPlus.vue'
import AccountPlusOutline from 'vue-material-design-icons/AccountPlusOutline.vue'
import BriefcaseSearchOutline from 'vue-material-design-icons/BriefcaseSearchOutline.vue'
import FileAccountOutline from 'vue-material-design-icons/FileAccountOutline.vue'
import AccountMinus from 'vue-material-design-icons/AccountMinus.vue'
import AccountMinusOutline from 'vue-material-design-icons/AccountMinusOutline.vue'
import CalendarSyncOutline from 'vue-material-design-icons/CalendarSyncOutline.vue'
import ClipboardAccountOutline from 'vue-material-design-icons/ClipboardAccountOutline.vue'
import StarCheckOutline from 'vue-material-design-icons/StarCheckOutline.vue'
import Calculator from 'vue-material-design-icons/Calculator.vue'

export default {
	AccountOutline,
	Account,
	ViewDashboardOutline,
	CheckDecagramOutline,
	ClockCheckOutline,
	Receipt,
	ReceiptTextOutline,
	CalendarClock,
	CogPlayOutline,
	ScaleBalance,
	EmoticonSickOutline,
	FileSendOutline,
	PiggyBankOutline,
	AccountPlus,
	AccountPlusOutline,
	BriefcaseSearchOutline,
	FileAccountOutline,
	AccountMinus,
	AccountMinusOutline,
	CalendarSyncOutline,
	ClipboardAccountOutline,
	StarCheckOutline,
	Calculator,
}
