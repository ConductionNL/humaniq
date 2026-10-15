<?php
// SPDX-License-Identifier: EUPL-1.2

use OCP\Util;

$appId = OCA\Humaniq\AppInfo\Application::APP_ID;
Util::addScript($appId, $appId . '-settings');
?>
<div id="humaniq-settings"></div>
