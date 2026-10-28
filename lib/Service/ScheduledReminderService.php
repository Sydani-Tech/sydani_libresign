<?php
declare(strict_types=1);
/** SPDX-License-Identifier: AGPL-3.0-or-later */
namespace OCA\Libresign\Service;
use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Db\SignRequestMapper;
use OCA\Libresign\Db\FileMapper;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;
use Psr\Log\LoggerInterface;
class ScheduledReminderService {
 public function __construct(private IAppConfig $config,private IDBConnection $db,private SignRequestMapper $requests,private FileMapper $files,private IdentifyMethodService $identify,private CampaignPolicy $campaigns,private LoggerInterface $logger) {}
 public function configured(): bool {return $this->config->getValueArray(Application::APP_ID,'reminder_schedule',[])!==[];}
 public function settings(): array {return ReminderSchedulePolicy::normalise($this->config->getValueArray(Application::APP_ID,'reminder_schedule',[]));}
 public function save(array $policy): array {$p=ReminderSchedulePolicy::normalise($policy);$this->config->setValueArray(Application::APP_ID,'reminder_schedule',$p);return $p;}
 public function send(?int $now=null, ?array $onlyIds=null, ?array $testPolicy=null): array {
  if($testPolicy!==null||$onlyIds!==null){if(PHP_SAPI!=='cli'||gethostname()!=='nextcloud-staging'||!$onlyIds||$testPolicy===null)throw new \RuntimeException('Reminder overrides are restricted to isolated staging CLI tests.');foreach($onlyIds as $testId){$sr=$this->requests->getById((int)$testId);$f=$this->files->getById($sr->getFileId());if(!str_starts_with($f->getUserId(),'ordinary-date-qa-'))throw new \RuntimeException('Only synthetic workflow requests may be tested.');}}
  $p=$testPolicy!==null?ReminderSchedulePolicy::normalise($testPolicy):$this->settings();$result=['sent'=>0,'skipped'=>0,'failed'=>0];if (!$p['enabled']) return $result;$now??=time();$seen=[];
  foreach ($this->requests->findRemindersCandidates() as $method) {
   $id=$method->getSignRequestId();if ($onlyIds!==null&&!in_array($id,$onlyIds,true))continue;if (isset($seen[$id]) || !in_array($method->getIdentifierKey(),['account','email'],true)) continue;$seen[$id]=true;
   try {
    $request=$this->requests->getById($id);$request=$this->requests->getByUuidUncached($request->getUuid());
    $file=$this->files->getById($request->getFileId());
    $linkLife=$this->config->getValueInt(Application::APP_ID,'maximum_validity',0);if($linkLife>0&&$request->getCreatedAt()->getTimestamp()+$linkLife<=$now)continue;
    if ($request->getSigned() || $request->getStatus()!==1 || !in_array($file->getStatus(),[1,2],true) || !$this->campaigns->canSign($file)) continue;
    if (!empty(($file->getMetadata()??[])['letterhead']) && !\OCP\Server::get(LetterheadService::class)->canReleaseFile($file)) continue;
    $dates=array_column(($request->getMetadata()??[])['notify']??[],'date');if (!$dates) continue;
    $q=$this->db->getQueryBuilder();$sent=(int)$q->select($q->func()->count('id'))->from('libresign_reminder')->where($q->expr()->eq('sign_request_id',$q->createNamedParameter($id,IQueryBuilder::PARAM_INT)))->andWhere("state = 'sent'")->executeQuery()->fetchOne();
    $slot=ReminderSchedulePolicy::due($p,(int)min($dates),$now,$sent);
    if ($slot===null || (int)max($dates)>=$slot) { $result['skipped']++;continue; }
    // Unique (request,slot) is a durable at-most-once claim across concurrent workers.
    $q=$this->db->getQueryBuilder();$q->insert('libresign_reminder')->values(['sign_request_id'=>$q->createNamedParameter($id,IQueryBuilder::PARAM_INT),'slot'=>$q->createNamedParameter($slot,IQueryBuilder::PARAM_INT),'state'=>$q->createNamedParameter('sending'),'created_at'=>$q->createNamedParameter($now,IQueryBuilder::PARAM_INT)]);
    try {$q->executeStatement();} catch (\Throwable $e) {$existing=$this->db->getQueryBuilder();$exists=$existing->select('id')->from('libresign_reminder')->where($existing->expr()->eq('sign_request_id',$existing->createNamedParameter($id,IQueryBuilder::PARAM_INT)))->andWhere($existing->expr()->eq('slot',$existing->createNamedParameter($slot,IQueryBuilder::PARAM_INT)))->executeQuery()->fetchOne();if($exists!==false){$result['skipped']++;continue;}throw $e;}
    $deliveryId=(int)$q->getLastInsertId();
    try {
     $this->identify->clearCache();$this->identify->setCurrentIdentifyMethod($method);$instance=$this->identify->getInstanceOfIdentifyMethod($method->getIdentifierKey(),$method->getIdentifierValue());\OCP\Server::get(\OCP\EventDispatcher\IEventDispatcher::class)->dispatchTyped(new \OCA\Libresign\Events\SendSignNotificationEvent($request,$file,$instance,true));$ok=true;
     $this->state($deliveryId,$ok?'sent':'skipped');$result[$ok?'sent':'skipped']++;
    } catch (\Throwable $e) {$this->state($deliveryId,'failed');throw $e;}
   } catch (\Throwable $e) {$result['failed']++;$this->logger->error('Scheduled signature reminder failed',['signRequestId'=>$id,'exception'=>$e]);}
  }
  return $result;
 }
 private function state(int $id,string $state): void {$q=$this->db->getQueryBuilder();$q->update('libresign_reminder')->set('state',$q->createNamedParameter($state))->where($q->expr()->eq('id',$q->createNamedParameter($id,IQueryBuilder::PARAM_INT)))->executeStatement();}
}
