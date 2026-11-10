<?php
declare(strict_types=1);
// SPDX-License-Identifier: AGPL-3.0-or-later
// Pure tests: no Nextcloud bootstrap, database writes or email.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once (is_file(__DIR__ . '/../../lib/Exception/LibresignException.php') ? __DIR__ . '/../..' : '/var/www/html/apps/libresign') . '/lib/Exception/LibresignException.php';
require_once (is_file(__DIR__ . '/../../lib/Service/SigningFieldService.php') ? __DIR__ . '/../..' : '/var/www/html/apps/libresign') . '/lib/Service/SigningFieldService.php';
require_once __DIR__ . '/../../lib/Service/CampaignDefinition.php';
use OCA\Libresign\Service\CampaignDefinition as D;
$checks = 0;
$check = static function(bool $ok, string $message) use (&$checks): void { if (!$ok) throw new RuntimeException($message); $checks++; echo "PASS: $message\n"; };
$reject = static function(callable $fn, string $message) use ($check): void { try { $fn(); } catch (InvalidArgumentException|\OCA\Libresign\Exception\LibresignException $e) { $check(true, $message); return; } $check(false, $message); };
$field = ['role'=>'recipient', 'type'=>'signature', 'coordinates'=>['page'=>1, 'left'=>10, 'top'=>10, 'width'=>100, 'height'=>40], 'metadata'=>['label'=>'Signature', 'required'=>true]];
$dimensions = [['w'=>600,'h'=>800]];
$check(D::fields([$field], $dimensions, false, true)[0]['coordinates']['width'] === 100.0, 'valid PDF-point layout preserved');
$reject(fn()=>D::fields([], $dimensions, false, true), 'signature required before publication');
$reject(fn()=>D::fields([$field], $dimensions, true, true), 'counter role requires its own signature');
$other = $field; $other['role']='counter';
$check(count(D::fields([$field,$other], $dimensions, true, true))===2, 'independent recipient and counter roles accepted');
$reject(fn()=>D::fields([$other], $dimensions, false), 'unknown signer role rejected');
foreach (['page'=>2, 'left'=>-1, 'top'=>790, 'width'=>INF, 'height'=>0] as $key=>$value) {
  $bad=$field; $bad['coordinates'][$key]=$value; $reject(fn()=>D::fields([$bad],$dimensions,false), 'invalid coordinate rejected: '.$key);
}
$bad=$field; $bad['metadata']['required']='false'; $reject(fn()=>D::fields([$bad],$dimensions,false), 'required flag is genuinely boolean');
$bad=$field; $bad['type']='html'; $reject(fn()=>D::fields([$bad],$dimensions,false), 'unrecognised field type rejected');
$check(D::pdf(base64_encode('%PDF-1.7 test')) === '%PDF-1.7 test', 'base64 PDF accepted');
$reject(fn()=>D::pdf(base64_encode('<html>')), 'non-PDF rejected');
$reject(fn()=>D::text("bad\x00value",100,'name'), 'control characters rejected');
$reject(fn()=>D::text("bad\xFF",100,'name'), 'invalid UTF-8 rejected');
$base=['campaign_status'=>'active','queue_state'=>'pending','file_id'=>1,'file_status'=>1,'notified_at'=>100];
$done=[...$base,'file_status'=>3,'signed_hash'=>'hash','completed_at'=>'2026-10-22 12:00:00'];
$check(D::recipientStatus($done)==='completed', 'actual signed hash and completion required');
$check(D::recipientStatus([...$done,'completed_at'=>null])!=='completed', 'status alone does not imply completion');
$check(D::recipientStatus([...$base,'recipient_signed'=>'2026-10-22'])==='awaiting_counter', 'first signature is not full completion');
$check(D::recipientStatus([...$base,'queue_state'=>'failed'])==='failed', 'failed invitation remains outstanding');
$check(D::recipientStatus([...$base,'notified_at'=>null])==='notification_pending', 'not notified is distinguishable');
$check(D::recipientStatus([...$base,'campaign_status'=>'cancelled'])==='cancelled', 'cancelled requests not pending');
$check(D::recipientStatus([...$done,'campaign_status'=>'cancelled'])==='completed', 'cancellation preserves completed signatures');
$summary=D::summary([$done,$base,[...$base,'queue_state'=>'failed'],[...$base,'queue_state'=>'exempt']],10,20);
$check($summary['required']===3 && $summary['completed']===1 && $summary['pending']===2, 'completion denominator excludes only documented exemptions');
$check($summary['overdue']===2 && $summary['completionRate']===33.3, 'overdue and completion rates are accurate');
echo json_encode(['checksPassed'=>$checks,'documentChanges'=>0],JSON_PRETTY_PRINT).PHP_EOL;
