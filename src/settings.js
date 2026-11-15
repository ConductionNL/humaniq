// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { registerTranslations } from '@conduction/nextcloud-vue'
import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { createApp, h } from 'vue'
import AdminSettings from './views/settings/AdminSettings.vue'

// Library CSS. This entry is its own bundle, so the import in main.js does not
// reach the admin page: without this line the admin shell renders unstyled.
import '@conduction/nextcloud-vue/css/index.css'

try {
	registerTranslations()
} catch (e) {
	// Non-fatal: library strings fall back to their English source.
	console.warn('[humaniq] registerTranslations failed; falling back to English', e)
}

const app = createApp({ render: () => h(AdminSettings) })
app.mixin({ methods: { t, n } })
app.mount('#humaniq-settings')
