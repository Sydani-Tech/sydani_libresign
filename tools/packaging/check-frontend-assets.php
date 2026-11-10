<?php
declare(strict_types=1);
/** SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
// Read-only release check. Vite can emit lazy runtime assets outside js/.
if (PHP_SAPI !== 'cli') exit(1);
$app = realpath($argv[1] ?? dirname(__DIR__, 2));
if ($app === false) throw new RuntimeException('Application directory is unavailable.');
$manifest = json_decode(file_get_contents($app . '/resources/frontend-assets.json'), true, 512, JSON_THROW_ON_ERROR);
$runtimeAssets = [];
foreach (['main', 'init', 'tab', 'settings', 'external', 'validation'] as $entry) {
 $name = $manifest[$entry]['script'] ?? '';
 if (!is_string($name) || !preg_match('/^[a-zA-Z0-9_-]+$/', $name)) throw new RuntimeException('Invalid frontend entry: ' . $entry);
 $script = $app . '/js/' . $name . '.mjs';
 if (!is_readable($script)) throw new RuntimeException('Missing frontend entry: ' . $entry);
 $source = file_get_contents($script);
 // Nextcloud's Vite integration resolves generated asset URLs through OC.filePath.
 preg_match_all('~[\x22\x27\x60](dist/[a-zA-Z0-9_./-]+)[\x22\x27\x60]~', $source, $matches);
 foreach ($matches[1] as $asset) $runtimeAssets[$asset] = true;
 $style = $manifest[$entry]['style'] ?? null;
 if ($style !== null && (!is_string($style) || !preg_match('/^[a-zA-Z0-9_-]+$/', $style) || !is_readable($app . '/css/' . $style . '.css'))) throw new RuntimeException('Missing frontend stylesheet: ' . $entry);
}
$workers = 0;
foreach (array_keys($runtimeAssets) as $asset) {
 $path = realpath($app . '/' . $asset);
 if ($path === false || !str_starts_with($path, $app . DIRECTORY_SEPARATOR) || !is_file($path) || !is_readable($path)) throw new RuntimeException('Missing frontend runtime asset: ' . $asset);
 if (preg_match('~(?:^|/)pdf[.]worker[.]min-[a-zA-Z0-9_-]+[.]mjs$~', $asset)) $workers++;
}
if ($workers === 0) throw new RuntimeException('The PDF worker is not referenced by the frontend entries.');
echo 'PASS: six frontend entries and all ', count($runtimeAssets), ' referenced runtime assets exist, including the PDF worker.', PHP_EOL;
