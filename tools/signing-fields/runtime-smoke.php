<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Sydani Technology contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Usage: php runtime-smoke.php NEXTCLOUD_ROOT INPUT_PDF OUTPUT_PDF
 * Uses a synthetic document only. It does not create a signature request.
 */

if ($argc !== 4) {
	throw new InvalidArgumentException('Expected Nextcloud root, input PDF, and output PDF.');
}
require_once rtrim($argv[1], '/') . '/lib/base.php';

$service = \OC::$server->get(\OCA\Libresign\Service\PdfSigningFieldsService::class);
$input = file_get_contents($argv[2]);
if (!$input) {
	throw new RuntimeException('Could not read the synthetic PDF.');
}
$result = $service->fill($input, [[
	'id' => 9999999,
	'type' => 'text',
	'page' => 1,
	'llx' => 30,
	'lly' => 30,
	'urx' => 200,
	'ury' => 55,
	'metadata' => ['label' => 'Runtime smoke test', 'required' => true],
	'value' => 'LibreSign staging',
]]);
if (!str_starts_with($result, $input)) {
	throw new RuntimeException('The field revision did not preserve the input PDF.');
}
if (file_put_contents($argv[3], $result) === false) {
	throw new RuntimeException('Could not save the synthetic result.');
}
echo "PDF field service smoke test passed.\n";
