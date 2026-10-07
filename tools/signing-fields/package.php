<?php
/**
 * SPDX-FileCopyrightText: 2026 Sydani Technology contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
$root = $argv[1];
$jar = new ZipArchive();
if ($jar->open($root . '/resources/signing-fields.jar', ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
	throw new RuntimeException('Cannot create signing-fields.jar');
}
$entry = 'coop/libresign/SigningFields.class';
$jar->addFile($root . '/build/signing-fields/' . $entry, $entry);
$jar->close();
