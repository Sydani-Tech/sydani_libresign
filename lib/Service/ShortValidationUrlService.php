<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Libresign\Service;

use OCA\Libresign\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IURLGenerator;

/** Persistent grouped aliases for new links, with lossless legacy UUID links retained. */
class ShortValidationUrlService {
	public const ROOT_ENABLED_KEY = 'short_validation_root_enabled';
	public const GROUPED_ENABLED_KEY = 'short_validation_grouped_enabled';
	public const STORE_READY_KEY = 'short_validation_store_ready';

	public function __construct(private IURLGenerator $urls, private IAppConfig $config, private ShortValidationCodeStore $codes) {
	}

	public function forUuid(string $uuid): string {
		$code = self::encode($uuid);
		if ($this->config->getValueBool(Application::APP_ID, self::GROUPED_ENABLED_KEY, false)) {
			if (!$this->config->getValueBool(Application::APP_ID, self::STORE_READY_KEY, false)) {
				throw new \RuntimeException('Validation link storage is not configured.');
			}
			$code = ShortValidationCodeStore::format($this->codes->getOrCreate(strtolower($uuid)));
		}
		if ($this->config->getValueBool(Application::APP_ID, self::ROOT_ENABLED_KEY, false)) {
			return $this->urls->getAbsoluteURL('/v/' . $code);
		}
		return $this->urls->linkToRouteAbsolute('libresign.shortValidation.open', ['code' => $code]);
	}

	public function resolve(string $code): ?string {
		$legacy = self::decode($code);
		if ($legacy !== null) {
			return $legacy;
		}
		$id = ShortValidationCodeStore::parse($code);
		if ($id === null || !$this->config->getValueBool(Application::APP_ID, self::STORE_READY_KEY, false)) {
			return null;
		}
		return $this->codes->findUuid($id);
	}

	public static function encode(string $uuid): string {
		if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $uuid)) {
			throw new \InvalidArgumentException('Invalid validation UUID');
		}
		return rtrim(strtr(base64_encode(hex2bin(str_replace('-', '', $uuid))), '+/', '-_'), '=');
	}

	public static function decode(string $code): ?string {
		if (!preg_match('/^[A-Za-z0-9_-]{22}$/D', $code)) return null;
		$bytes = base64_decode(strtr($code, '-_', '+/') . '==', true);
		if ($bytes === false || strlen($bytes) !== 16) return null;
		$hex = bin2hex($bytes);
		$uuid = substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
		return hash_equals(self::encode($uuid), $code) ? $uuid : null;
	}
}
