// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Icon registry for humaniq (ADR-077 semantic icon vocabulary).
//
// CnAppNav, CnIcon, CnIndexPage / CnDetailPage headers and empty states resolve
// an `icon` by PascalCase name through the registry that `registerIcons()`
// populates. A name that is not registered renders NO icon in the navigation —
// not a fallback glyph — so this file must cover every `icon` the manifests and
// register files name. Keep it in sync when you add a menu entry.
//
// Generated from the app's own manifests; every name is verified to exist in
// vue-material-design-icons.

import Account from 'vue-material-design-icons/Account.vue'
import AccountArrowRightOutline from 'vue-material-design-icons/AccountArrowRightOutline.vue'
import AccountBoxOutline from 'vue-material-design-icons/AccountBoxOutline.vue'
import AccountClockOutline from 'vue-material-design-icons/AccountClockOutline.vue'
import AccountGroupOutline from 'vue-material-design-icons/AccountGroupOutline.vue'
import AccountKeyOutline from 'vue-material-design-icons/AccountKeyOutline.vue'
import AccountMinus from 'vue-material-design-icons/AccountMinus.vue'
import AccountMinusOutline from 'vue-material-design-icons/AccountMinusOutline.vue'
import AccountMultipleOutline from 'vue-material-design-icons/AccountMultipleOutline.vue'
import AccountOutline from 'vue-material-design-icons/AccountOutline.vue'
import AccountPlus from 'vue-material-design-icons/AccountPlus.vue'
import AccountPlusOutline from 'vue-material-design-icons/AccountPlusOutline.vue'
import AccountTieOutline from 'vue-material-design-icons/AccountTieOutline.vue'
import AlertOutline from 'vue-material-design-icons/AlertOutline.vue'
import BankTransfer from 'vue-material-design-icons/BankTransfer.vue'
import BookEditOutline from 'vue-material-design-icons/BookEditOutline.vue'
import BriefcaseOutline from 'vue-material-design-icons/BriefcaseOutline.vue'
import BriefcaseSearchOutline from 'vue-material-design-icons/BriefcaseSearchOutline.vue'
import BullseyeArrow from 'vue-material-design-icons/BullseyeArrow.vue'
import Calculator from 'vue-material-design-icons/Calculator.vue'
import CalculatorVariantOutline from 'vue-material-design-icons/CalculatorVariantOutline.vue'
import CalendarAccountOutline from 'vue-material-design-icons/CalendarAccountOutline.vue'
import CalendarCheck from 'vue-material-design-icons/CalendarCheck.vue'
import CalendarClock from 'vue-material-design-icons/CalendarClock.vue'
import CalendarClockOutline from 'vue-material-design-icons/CalendarClockOutline.vue'
import CalendarMonthOutline from 'vue-material-design-icons/CalendarMonthOutline.vue'
import CalendarRange from 'vue-material-design-icons/CalendarRange.vue'
import CalendarSyncOutline from 'vue-material-design-icons/CalendarSyncOutline.vue'
import CalendarWeekendOutline from 'vue-material-design-icons/CalendarWeekendOutline.vue'
import CarKey from 'vue-material-design-icons/CarKey.vue'
import CarOutline from 'vue-material-design-icons/CarOutline.vue'
import CarSide from 'vue-material-design-icons/CarSide.vue'
import Cash from 'vue-material-design-icons/Cash.vue'
import CashCheck from 'vue-material-design-icons/CashCheck.vue'
import CashMinus from 'vue-material-design-icons/CashMinus.vue'
import CashMultiple from 'vue-material-design-icons/CashMultiple.vue'
import CashPlus from 'vue-material-design-icons/CashPlus.vue'
import CashRemove from 'vue-material-design-icons/CashRemove.vue'
import CashSync from 'vue-material-design-icons/CashSync.vue'
import ChartBar from 'vue-material-design-icons/ChartBar.vue'
import CheckCircleOutline from 'vue-material-design-icons/CheckCircleOutline.vue'
import CheckDecagramOutline from 'vue-material-design-icons/CheckDecagramOutline.vue'
import ClipboardAccountOutline from 'vue-material-design-icons/ClipboardAccountOutline.vue'
import ClipboardCheckOutline from 'vue-material-design-icons/ClipboardCheckOutline.vue'
import ClipboardList from 'vue-material-design-icons/ClipboardList.vue'
import ClipboardListOutline from 'vue-material-design-icons/ClipboardListOutline.vue'
import ClipboardPulseOutline from 'vue-material-design-icons/ClipboardPulseOutline.vue'
import ClockCheckOutline from 'vue-material-design-icons/ClockCheckOutline.vue'
import ClockOutline from 'vue-material-design-icons/ClockOutline.vue'
import ClockPlusOutline from 'vue-material-design-icons/ClockPlusOutline.vue'
import CloseCircleOutline from 'vue-material-design-icons/CloseCircleOutline.vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import CogPlayOutline from 'vue-material-design-icons/CogPlayOutline.vue'
import CreditCardOutline from 'vue-material-design-icons/CreditCardOutline.vue'
import CurrencyEur from 'vue-material-design-icons/CurrencyEur.vue'
import DeleteAlertOutline from 'vue-material-design-icons/DeleteAlertOutline.vue'
import DesktopTowerMonitor from 'vue-material-design-icons/DesktopTowerMonitor.vue'
import Domain from 'vue-material-design-icons/Domain.vue'
import DownloadOutline from 'vue-material-design-icons/DownloadOutline.vue'
import EmoticonSickOutline from 'vue-material-design-icons/EmoticonSickOutline.vue'
import EyeOutline from 'vue-material-design-icons/EyeOutline.vue'
import FileAccountOutline from 'vue-material-design-icons/FileAccountOutline.vue'
import FileCertificateOutline from 'vue-material-design-icons/FileCertificateOutline.vue'
import FileDocument from 'vue-material-design-icons/FileDocument.vue'
import FileDocumentMultipleOutline from 'vue-material-design-icons/FileDocumentMultipleOutline.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import FileDocumentPlusOutline from 'vue-material-design-icons/FileDocumentPlusOutline.vue'
import FileSendOutline from 'vue-material-design-icons/FileSendOutline.vue'
import FileSign from 'vue-material-design-icons/FileSign.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import Gavel from 'vue-material-design-icons/Gavel.vue'
import GiftOutline from 'vue-material-design-icons/GiftOutline.vue'
import HandshakeOutline from 'vue-material-design-icons/HandshakeOutline.vue'
import LinkVariant from 'vue-material-design-icons/LinkVariant.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import OfficeBuildingOutline from 'vue-material-design-icons/OfficeBuildingOutline.vue'
import PackageVariantClosed from 'vue-material-design-icons/PackageVariantClosed.vue'
import PencilOutline from 'vue-material-design-icons/PencilOutline.vue'
import PercentOutline from 'vue-material-design-icons/PercentOutline.vue'
import PiggyBankOutline from 'vue-material-design-icons/PiggyBankOutline.vue'
import PlusCircleOutline from 'vue-material-design-icons/PlusCircleOutline.vue'
import PowerPlugOutline from 'vue-material-design-icons/PowerPlugOutline.vue'
import Receipt from 'vue-material-design-icons/Receipt.vue'
import ReceiptOutline from 'vue-material-design-icons/ReceiptOutline.vue'
import ReceiptTextOutline from 'vue-material-design-icons/ReceiptTextOutline.vue'
import ScaleBalance from 'vue-material-design-icons/ScaleBalance.vue'
import SchoolOutline from 'vue-material-design-icons/SchoolOutline.vue'
import ShieldAccountOutline from 'vue-material-design-icons/ShieldAccountOutline.vue'
import ShieldCheckOutline from 'vue-material-design-icons/ShieldCheckOutline.vue'
import ShieldLockOutline from 'vue-material-design-icons/ShieldLockOutline.vue'
import Sitemap from 'vue-material-design-icons/Sitemap.vue'
import SitemapOutline from 'vue-material-design-icons/SitemapOutline.vue'
import StarCheckOutline from 'vue-material-design-icons/StarCheckOutline.vue'
import SwapHorizontal from 'vue-material-design-icons/SwapHorizontal.vue'
import TableClock from 'vue-material-design-icons/TableClock.vue'
import TableColumn from 'vue-material-design-icons/TableColumn.vue'
import TargetVariant from 'vue-material-design-icons/TargetVariant.vue'
import TextRecognition from 'vue-material-design-icons/TextRecognition.vue'
import Timeline from 'vue-material-design-icons/Timeline.vue'
import TimelineClockOutline from 'vue-material-design-icons/TimelineClockOutline.vue'
import TimerSand from 'vue-material-design-icons/TimerSand.vue'
import ViewDashboardOutline from 'vue-material-design-icons/ViewDashboardOutline.vue'

export default {
	Account,
	AccountArrowRightOutline,
	AccountBoxOutline,
	AccountClockOutline,
	AccountGroupOutline,
	AccountKeyOutline,
	AccountMinus,
	AccountMinusOutline,
	AccountMultipleOutline,
	AccountOutline,
	AccountPlus,
	AccountPlusOutline,
	AccountTieOutline,
	AlertOutline,
	BankTransfer,
	BookEditOutline,
	BriefcaseOutline,
	BriefcaseSearchOutline,
	BullseyeArrow,
	Calculator,
	CalculatorVariantOutline,
	CalendarAccountOutline,
	CalendarCheck,
	CalendarClock,
	CalendarClockOutline,
	CalendarMonthOutline,
	CalendarRange,
	CalendarSyncOutline,
	CalendarWeekendOutline,
	CarKey,
	CarOutline,
	CarSide,
	Cash,
	CashCheck,
	CashMinus,
	CashMultiple,
	CashPlus,
	CashRemove,
	CashSync,
	ChartBar,
	CheckCircleOutline,
	CheckDecagramOutline,
	ClipboardAccountOutline,
	ClipboardCheckOutline,
	ClipboardList,
	ClipboardListOutline,
	ClipboardPulseOutline,
	ClockCheckOutline,
	ClockOutline,
	ClockPlusOutline,
	CloseCircleOutline,
	CogOutline,
	CogPlayOutline,
	CreditCardOutline,
	CurrencyEur,
	DeleteAlertOutline,
	DesktopTowerMonitor,
	Domain,
	DownloadOutline,
	EmoticonSickOutline,
	EyeOutline,
	FileAccountOutline,
	FileCertificateOutline,
	FileDocument,
	FileDocumentMultipleOutline,
	FileDocumentOutline,
	FileDocumentPlusOutline,
	FileSendOutline,
	FileSign,
	FolderOutline,
	Gavel,
	GiftOutline,
	HandshakeOutline,
	LinkVariant,
	Magnify,
	OfficeBuildingOutline,
	PackageVariantClosed,
	PencilOutline,
	PercentOutline,
	PiggyBankOutline,
	PlusCircleOutline,
	PowerPlugOutline,
	Receipt,
	ReceiptOutline,
	ReceiptTextOutline,
	ScaleBalance,
	SchoolOutline,
	ShieldAccountOutline,
	ShieldCheckOutline,
	ShieldLockOutline,
	Sitemap,
	SitemapOutline,
	StarCheckOutline,
	SwapHorizontal,
	TableClock,
	TableColumn,
	TargetVariant,
	TextRecognition,
	Timeline,
	TimelineClockOutline,
	TimerSand,
	ViewDashboardOutline,
}
