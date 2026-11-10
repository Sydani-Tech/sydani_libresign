<?php
declare(strict_types=1);
/** SPDX-License-Identifier: AGPL-3.0-or-later */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__,2).'/lib/Service/ReminderSchedulePolicy.php';
require_once dirname(__DIR__,2).'/lib/Service/CampaignDefinition.php';
require_once dirname(__DIR__,2).'/lib/Service/CampaignFilters.php';
use OCA\Libresign\Service\ReminderSchedulePolicy as R;
use OCA\Libresign\Service\CampaignFilters as F;
$n=0;$check=static function(bool $ok,string $label)use(&$n):void{if(!$ok)throw new RuntimeException($label);$n++;};$stamp=static fn(string $date):int=>(new DateTimeImmutable($date,new DateTimeZone('Africa/Lagos')))->getTimestamp();
$p=R::normalise(['enabled'=>true]);$invited=$stamp('2026-10-01 10:00');
$check(R::due($p,$invited,$stamp('2026-10-01 18:00'),0)===null,'no same-day reminder');
$check(R::due($p,$invited,$stamp('2026-10-02 08:59'),0)===null,'wait until WAT slot');
$check(R::due($p,$invited,$stamp('2026-10-02 09:01'),0)===$stamp('2026-10-02 09:00'),'daily WAT slot');
$twice=R::normalise(['enabled'=>true,'intervalHours'=>12]);$check(R::due($twice,$invited,$stamp('2026-10-02 17:01'),0)===$stamp('2026-10-02 17:00'),'twice daily second slot');
$alternate=R::normalise(['enabled'=>true,'intervalHours'=>48]);$check(R::due($alternate,$invited,$stamp('2026-10-03 10:00'),0)===null,'alternate day skipped');$check(R::due($alternate,$invited,$stamp('2026-10-04 10:00'),0)!==null,'alternate day due');
$check(R::due($p,$invited,$stamp('2026-10-02 10:00'),10)===null,'max reminders');$check(R::due($p,$invited,$stamp('2026-11-01 10:00'),0)===null,'duration reached');
$until=R::normalise(['enabled'=>true,'until'=>'2026-10-03']);$check(R::due($until,$invited,$stamp('2026-10-03 10:00'),0)!==null,'until inclusive WAT');$check(R::due($until,$invited,$stamp('2026-10-04 10:00'),0)===null,'until stops reminders');
foreach([['enabled'=>'false'],['until'=>[]],['intervalHours'=>6],['until'=>'2026-02-30'],['intervalHours'=>12,'secondTime'=>'08:00'],['sendTime'=>'25:00'],['stopAfterDays'=>0]] as $bad){try{R::normalise($bad);throw new RuntimeException('Bad policy accepted');}catch(InvalidArgumentException){$n++;}}
$f=F::normalise(['from'=>'2026-10-01','to'=>'2026-10-02','status'=>'completed']);$check($f['fromTimestamp']===$stamp('2026-10-01 00:00'),'campaign dates use WAT');
foreach([['status'=>'sql'],['sortBy'=>'c.secret'],['from'=>'2026-02-30'],['from'=>'2026-10-02','to'=>'2026-10-01']] as $bad){try{F::normalise($bad);throw new RuntimeException('Bad filter accepted');}catch(InvalidArgumentException){$n++;}}
echo json_encode(['policyChecksPassed'=>$n,'documentChanges'=>0,'emails'=>0]).PHP_EOL;
