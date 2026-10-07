<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Sydani Technology contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Service;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Exception\LibresignException;
use OCA\Libresign\Helper\JavaHelper;
use OCP\IAppConfig;
use OCP\ITempManager;
use Symfony\Component\Process\Process;

class PdfSigningFieldsService {
	public function __construct(
		private IAppConfig $appConfig,
		private ITempManager $tempManager,
		private JavaHelper $javaHelper,
	) {
	}

	public function fill(string $pdf, array $fields): string {
		if (!$fields) {
			return $pdf;
		}
		$jar = $this->appConfig->getValueString(Application::APP_ID, 'jsignpdf_jar_path');
		$helper = dirname(__DIR__, 2) . '/resources/signing-fields.jar';
		$font = $this->appConfig->getValueString(Application::APP_ID, 'signing_fields_font', '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf');
		if (!is_file($jar) || !is_file($helper) || !is_file($font)) {
			throw new LibresignException('PDF form support is not installed. Build signing-fields.jar and configure a Unicode font.');
		}
		$paths = [];
		try {
			foreach (['input.pdf', 'output.pdf', 'fields.tsv'] as $suffix) {
				$path = $this->tempManager->getTemporaryFile($suffix);
				if (!$path) {
					throw new LibresignException('Could not allocate a temporary form file.');
				}
				chmod($path, 0600);
				$paths[] = $path;
			}
			file_put_contents($paths[0], $pdf);
			$lines = [];
			foreach ($fields as $field) {
				$lines[] = implode("\t", [
					$field['id'], $field['type'], $field['page'],
					$field['llx'], $field['lly'], $field['urx'], $field['ury'],
					$field['metadata']['required'] ? '1' : '0',
					base64_encode($field['metadata']['label']),
					array_key_exists('value', $field)
						? base64_encode(is_bool($field['value']) ? ($field['value'] ? 'true' : 'false') : $field['value']) : '-',
				]);
			}
			file_put_contents($paths[2], implode("\n", $lines));
			$process = new Process([
				$this->javaHelper->getJavaPath() ?: 'java', '-Djava.awt.headless=true',
				'-cp', $helper . PATH_SEPARATOR . $jar, 'coop.libresign.SigningFields',
				$paths[0], $paths[1], $paths[2], $font,
			]);
			$process->setTimeout(60);
			$process->run();
			if (!$process->isSuccessful()) {
				throw new LibresignException('Could not fill PDF fields: ' . mb_substr($process->getErrorOutput(), 0, 1000));
			}
			$result = file_get_contents($paths[1]);
			if (!$result || !str_starts_with($result, $pdf)) {
				throw new LibresignException('PDF field processing did not preserve the original revision.');
			}
			return $result;
		} finally {
			foreach ($paths as $path) {
				if (is_file($path)) {
					unlink($path);
				}
			}
		}
	}
}
