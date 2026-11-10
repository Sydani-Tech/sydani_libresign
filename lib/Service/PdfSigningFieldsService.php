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
		return $this->prepare($pdf, $fields)['pdf'];
	}

	/** @return array{pdf: string, prepared: bool, signatureFields: array<string, string>} */
	public function prepare(string $pdf, array $fields, array $signatureFields = []): array {
		if (!$fields && !$signatureFields) {
			return ['pdf' => $pdf, 'prepared' => false, 'signatureFields' => []];
		}
		$jar = \OCP\Server::get(PdfHelperRuntime::class)->classPath();
		$helper = dirname(__DIR__, 2) . '/resources/signing-fields.jar';
		$font = $this->appConfig->getValueString(Application::APP_ID, 'signing_fields_font', '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf');
		if (!is_file($helper) || !is_file($font)) {
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
			foreach (array_merge($fields, $signatureFields) as $field) {
				$lines[] = implode("\t", [
					$field['id'], $field['type'], $field['page'],
					$field['llx'], $field['lly'], $field['urx'], $field['ury'],
					$field['metadata']['required'] ? '1' : '0',
					base64_encode($field['metadata']['label']),
					array_key_exists('value', $field)
						? base64_encode(is_bool($field['value']) ? ($field['value'] ? 'true' : 'false') : $field['value']) : '-',
					(string)($field['owner'] ?? ''),
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
			$manifest = self::readManifest($process->getOutput(), $signatureFields);
			return ['pdf' => $result] + $manifest;
		} finally {
			foreach ($paths as $path) {
				if (is_file($path)) {
					unlink($path);
				}
			}
		}
	}

	/** Read only canonical slot names belonging to this server-generated plan. */
	public static function readManifest(string $output, array $signatureFields): array {
		$expected = [];
		foreach ($signatureFields as $field) {
			$expected[(string)$field['id']] = 'libresign_signature_' . $field['id'];
		}
		$slots = [];
		$prepared = null;
		foreach (explode("\n", trim($output)) as $line) {
			$parts = explode("\t", $line);
			if (count($parts) === 2 && $parts[0] === 'SIGNATURE_LAYOUT') {
				if ($prepared !== null || !in_array($parts[1], ['prepared', 'legacy'], true)) {
					throw new LibresignException('Invalid PDF layout result.');
				}
				$prepared = $parts[1] === 'prepared';
			} elseif (count($parts) === 3 && $parts[0] === 'SIGNATURE_FIELD') {
				if (!isset($expected[$parts[1]]) || $expected[$parts[1]] !== $parts[2] || isset($slots[$parts[1]])) {
					throw new LibresignException('Invalid prepared PDF signature field.');
				}
				$slots[$parts[1]] = $parts[2];
			} elseif ($line !== '') {
				throw new LibresignException('Unexpected PDF layout result.');
			}
		}
		if ($prepared === null) {
			throw new LibresignException('PDF layout support is out of date.');
		}
		if (!$prepared && $slots) {
			throw new LibresignException('Legacy PDF cannot add signature slots.');
		}
		if ($prepared) {
			foreach ($signatureFields as $field) {
				if (!empty($field['value']) && !isset($slots[(string)$field['id']])) {
					throw new LibresignException('The selected PDF signature field is unavailable.');
				}
			}
		}
		return ['prepared' => $prepared, 'signatureFields' => $slots];
	}
}
