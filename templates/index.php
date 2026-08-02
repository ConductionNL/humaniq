<?php
// SPDX-License-Identifier: EUPL-1.2

use OCP\Util;

$appId = OCA\Hrmq\AppInfo\Application::APP_ID;
Util::addScript($appId, $appId . '-main');
?>
<?php
/*
 * The mount host is `#hrmq-app`, NOT `#content`.
 *
 * Nextcloud core's own `core/templates/layout.user.php` already emits
 * `<div id="content" class="app-hrmq">` and this template renders INSIDE it,
 * so a `<div id="content">` here is a DUPLICATE id nested in the original.
 *
 * Vue 2 hid the problem: `new Vue().$mount('#content')` REPLACED the matched
 * element. Vue 3's `createApp().mount()` renders INSIDE the match instead, and
 * `document.querySelector('#content')` returns core's outer div (first in
 * document order) — so the app mounted into core's wrapper while this
 * element stayed permanently empty and unused. It happened to render, which
 * is exactly what makes it worth naming: nothing errors, and the app's
 * declared host element is simply dead.
 *
 * A unique id removes the ambiguity rather than relying on which div wins.
 */
?>
<div id="hrmq-app"></div>
