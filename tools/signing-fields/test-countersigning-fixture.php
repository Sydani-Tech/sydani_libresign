<?php
declare(strict_types=1);
/** SPDX-License-Identifier: AGPL-3.0-or-later */
use OCA\Libresign\Service\PadesVerificationService as Report;
use OCA\Libresign\Service\PdfCountersigningPolicy as Policy;
if (PHP_SAPI !== 'cli' || $argc !== 4) exit(2);
require_once __DIR__.'/../../lib/Exception/LibresignException.php';
require_once __DIR__.'/../../lib/Service/PdfCountersigningPolicy.php';
require_once __DIR__.'/../../lib/Service/PadesVerificationService.php';
[, $dir, $source, $intermediate] = $argv;
$report = static fn(string $stage): array => ['checked'=>true,'signatures'=>Report::parseReport(file_get_contents($dir.'/'.$stage.'.tsv'))];
$existing = Policy::existing($report('old'));
Policy::preflight($existing, []);
$certificateId = 'C-'.strtoupper(openssl_x509_fingerprint(file_get_contents($dir.'/new.pem'), 'sha256'));
$original = file_get_contents($dir.'/'.$source);
$temp = file_get_contents($dir.'/'.$intermediate);
$final = file_get_contents($dir.'/final.pdf');
$ids = Policy::appended($original,$temp,$existing,$report('new'),$certificateId,false);
if (count($ids)!==1 || $ids!==Policy::appended($original,$final,$existing,$report('final'),$certificateId,true)) {
 throw new RuntimeException('Unexpected change to signatures while adding evidence.');
}
echo "PASS: the production policy accepts the same preserved revision and independently verified new LT signature\n";
