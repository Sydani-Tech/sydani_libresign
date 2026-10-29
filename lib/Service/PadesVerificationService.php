<?php
declare(strict_types=1);
/** SPDX-License-Identifier: AGPL-3.0-or-later */
namespace OCA\Libresign\Service;
use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Helper\JavaHelper;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\ITempManager;
use Symfony\Component\Process\Process;
class PadesVerificationService {
 public function __construct(private IAppConfig $config,private JavaHelper $java,private ITempManager $temp,private ICacheFactory $cache, private \Psr\Log\LoggerInterface $logger) {}
 public function verify(string $pdf, ?\DateTimeImmutable $validationTime = null): array {
  $unknown=['checked'=>false,'offline'=>true,'trustScope'=>'organisation','signatures'=>[]];
  $dir=$this->config->getValueString(Application::APP_ID,'pades_lt_lib_dir','');$trust=$this->config->getValueString(Application::APP_ID,'pades_lt_trust_files','');$helper=dirname(__DIR__,2).'/resources/pades-report.jar';
  if(!$dir||!is_dir($dir)||!$trust||!is_file($helper))return $unknown+['reason'=>'verification_unavailable'];
  $trustHash='';foreach(explode(',',$trust) as $cert)$trustHash.=is_readable($cert)?hash_file('sha256',$cert):'missing';
  $key=hash('sha256','revision-v2'.hash_file('sha256',$helper).$pdf.$trustHash.($validationTime?->format(\DateTimeInterface::ATOM)??gmdate('Y-m-d-H')));$cache=$this->cache->createLocal('libresign-pades');$cached=$cache->get($key);if(is_array($cached))return $cached;
  $path=$this->temp->getTemporaryFile('.pdf');if(!$path)return $unknown+['reason'=>'verification_unavailable'];chmod($path,0600);
  try {
   file_put_contents($path,$pdf);$command=[$this->java->getJavaPath()?:'java','-Xmx512m','-Djava.awt.headless=true','-cp',$helper.PATH_SEPARATOR.$dir.'/*','coop.libresign.PadesReport',$path,$trust];if($validationTime)$command[]=$validationTime->format(\DateTimeInterface::ATOM);$process=new Process($command);$process->setTimeout(60);$process->mustRun();$signatures=[];$revisions=[];
   foreach(explode("\n",$process->getOutput()) as $line){$v=explode("\t",$line);
    if(count($v)===12&&$v[0]==='REVISION'&&preg_match('/^[01]{3}$/',$v[2].$v[3].$v[4])&&count(array_filter(array_slice($v,5),'ctype_digit'))===7){$revisions[$v[1]]=['checked'=>$v[2]==='1','byteRangeValid'=>$v[3]==='1','dictionaryConsistent'=>$v[4]==='1','extensionChanges'=>(int)$v[5],'signatureOrFormChanges'=>(int)$v[6],'annotationChanges'=>(int)$v[7],'undefinedChanges'=>(int)$v[8],'visualDifferences'=>(int)$v[9],'pageDifferences'=>(int)$v[10],'annotationOverlaps'=>(int)$v[11]];continue;}
    if(count($v)!==8||$v[0]!=='SIGNATURE')continue;$signatures[]=['id'=>$v[1],'name'=>base64_decode($v[2],true)?:'','format'=>$v[3],'indication'=>$v[4],'subIndication'=>$v[5],'timestampVerified'=>$v[6]==='1','bestSignatureTime'=>$v[7]];}
   foreach($signatures as &$signature)if(isset($revisions[$signature['id']]))$signature['revision']=$revisions[$signature['id']];unset($signature);
   $result=['checked'=>true,'offline'=>true,'trustScope'=>'organisation','checkedAt'=>gmdate(\DateTimeInterface::ATOM),'validationTime'=>$validationTime?->format(\DateTimeInterface::ATOM)??gmdate(\DateTimeInterface::ATOM),'signatures'=>$signatures];$cache->set($key,$result,1800);return $result;
  }catch(\Throwable $e){$this->logger->warning('Offline PAdES verification incomplete',['exception'=>$e]);return $unknown+['reason'=>'verification_incomplete'];}finally{if(is_file($path))unlink($path);}
 }
 public function assertLt(string $pdf): void {$result=$this->verify($pdf);if(!$result['checked']||!$result['signatures'])throw new \RuntimeException('The long-term signature could not be verified. No signed document was saved.');foreach($result['signatures'] as $s)if($s['indication']!=='TOTAL_PASSED'||!in_array($s['format'],['PAdES_BASELINE_LT','PAdES_BASELINE_LTA'],true)||!$s['timestampVerified'])throw new \RuntimeException('Long-term signing evidence is incomplete. Check timestamp and certificate revocation services. No signed document was saved.');}
}
