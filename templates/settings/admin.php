<?php
// SPDX-License-Identifier: EUPL-1.2

use OCP\Util;

$appId = OCA\Humaniq\AppInfo\Application::APP_ID;
Util::addStyle($appId, 'nextcloud-vue');
Util::addScript($appId, $appId . '-settings');
?>
<div id="humaniq-settings"></div>
