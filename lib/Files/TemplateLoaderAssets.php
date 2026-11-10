<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Files;

use OCA\Libresign\Helper\FrontendAssets;

class TemplateLoaderAssets {
	public function addInitScript(string $appId, string $script): void {
		FrontendAssets::addInitScript($appId, $script);
	}

	public function addScript(string $appId, string $script): void {
		FrontendAssets::addScript($appId, $script);
	}

	public function addStyle(string $appId, string $style): void {
		FrontendAssets::addStyle($appId, $style);
	}
}
