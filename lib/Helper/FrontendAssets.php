<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Sydani Technology contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Helper;

use OCA\Libresign\AppInfo\Application;
use OCP\Util;

/** Content-hashed entry points avoid stale immutable browser caches after a build. */
class FrontendAssets {
	private static ?array $manifest = null;

	public static function addScript(string $application, string $name): void {
		Util::addScript($application, self::resolve($application, $name, 'script'));
	}

	public static function addStyle(string $application, string $name): void {
		Util::addStyle($application, self::resolve($application, $name, 'style'));
	}

	private static function resolve(string $application, string $name, string $type): string {
		if ($application !== Application::APP_ID || !str_starts_with($name, 'libresign-')) {
			return $name;
		}
		if (self::$manifest === null) {
			self::$manifest = [];
			$path = dirname(__DIR__, 2) . '/resources/frontend-assets.json';
			if (is_readable($path)) {
				try {
					$manifest = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
					self::$manifest = is_array($manifest) ? $manifest : [];
				} catch (\JsonException) {
					// Conventional builds without a manifest remain supported.
				}
			}
		}
		$entry = substr($name, strlen('libresign-'));
		$definition = self::$manifest[$entry] ?? null;
		$asset = is_array($definition) ? ($definition[$type] ?? null) : null;
		// Only build-generated basenames, never paths or remote URLs.
		return is_string($asset) && preg_match('/\A' . preg_quote($name, '/') . '-[A-Za-z0-9_-]{6,64}\z/', $asset)
			? $asset : $name;
	}
}
