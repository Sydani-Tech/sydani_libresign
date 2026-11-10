<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
if (PHP_SAPI !== 'cli') exit(1);
$app = realpath($argv[1] ?? dirname(__DIR__, 2));
if (!$app || !is_dir($app . '/tools')) throw new RuntimeException('Application tools directory missing.');
$count = 0;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($app . '/tools', FilesystemIterator::SKIP_DOTS)) as $file) {
	if (!$file->isFile() || $file->isLink() || $file->getExtension() !== 'sh') continue;
	if (str_contains(file_get_contents($file->getPathname()), "\r")) throw new RuntimeException('Shell script contains CR line endings: ' . substr($file->getPathname(), strlen($app) + 1));
	$process = proc_open(['bash', '-n', $file->getPathname()], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
	if (!is_resource($process)) throw new RuntimeException('Cannot parse shell script.');
	fclose($pipes[1]);$error = stream_get_contents($pipes[2]);fclose($pipes[2]);
	if (proc_close($process) !== 0) throw new RuntimeException('Shell syntax failed: ' . $file->getFilename() . ': ' . $error);
	$count++;
}
if ($count === 0) throw new RuntimeException('No deployment shell scripts found.');
echo 'PASS: ', $count, ' packaged shell scripts use Unix line endings and pass Bash parsing.', PHP_EOL;
