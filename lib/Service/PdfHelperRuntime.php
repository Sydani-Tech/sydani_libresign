<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Sydani Technology contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service;

use OCA\Libresign\AppInfo\Application;
use OCP\IAppConfig;

/** PDF helper dependencies are independent of the upstream engine's retired JAR setting. */
class PdfHelperRuntime {
	public function __construct(private IAppConfig $config) {
	}

	public function classPath(): string {
		$directory = $this->config->getValueString(Application::APP_ID, 'signing_fields_lib_dir', '');
		if ($directory === '') {
			$directory = $this->config->getValueString(Application::APP_ID, 'pades_lt_lib_dir', '');
		}
		if ($directory === '') {
			$engine = $this->config->getValueString(Application::APP_ID, 'jsignpdf_path', '');
			$directory = $engine === '' ? '' : rtrim($engine, '/') . '/lib';
		}
		if ($directory === '' || !is_dir($directory) || !glob(rtrim($directory, '/') . '/openpdf-3.*.jar')) {
			throw new \RuntimeException('The PDF helper libraries are unavailable. Configure the tested OpenPDF 3 library directory.');
		}
		return rtrim($directory, '/') . '/*';
	}
}
