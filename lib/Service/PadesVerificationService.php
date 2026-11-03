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
   file_put_contents($path,$pdf);$command=[$this->java->getJavaPath()?:'java','-Xmx512m','-Djava.awt.headless=true','-cp',$helper.PATH_SEPARATOR.$dir.'/*','coop.libresign.PadesReport',$path,$trust];if($validationTime)$command[]=$validationTime->format(\DateTimeInterface::ATOM);$process=new Process($command);$process->setTimeout(60);$process->mustRun();$signatures=self::parseReport($process->getOutput());
   $result=['checked'=>true,'offline'=>true,'trustScope'=>'organisation','checkedAt'=>gmdate(\DateTimeInterface::ATOM),'validationTime'=>$validationTime?->format(\DateTimeInterface::ATOM)??gmdate(\DateTimeInterface::ATOM),'signatures'=>$signatures];$cache->set($key,$result,1800);return $result;
  }catch(\Throwable $e){$this->logger->warning('Offline PAdES verification incomplete',['exception'=>$e]);return $unknown+['reason'=>'verification_incomplete'];}finally{if(is_file($path))unlink($path);}
 }
 /** Present the signature evidence without exposing internal PDF field names. */
 public function userReport(string $pdf): array {
  $report = $this->verify($pdf);
  foreach ($report['signatures'] as &$signature) {
   unset($signature['signatureFields'], $signature['fieldLocks']);
  }
  return $report;
 }

 /** Parse the private verifier output; tolerate unrelated Java log lines. */
 public static function parseReport(string $output): array {
  $signatures=[];$revisions=[];$integrity=[];$fields=[];$locks=[];
   foreach(explode("\n",$output) as $line){$v=explode("\t",$line);
    if (count($v) === 3 && $v[0] === 'SIGNED_FIELD' && ($field = base64_decode($v[2], true)) !== false) { $fields[$v[1]][] = $field; continue; }
    if (count($v) === 5 && $v[0] === 'FIELD_LOCK') {
     $names = $v[4] === '' ? [] : array_map(static fn (string $name) => base64_decode($name, true), explode(',', $v[4]));
     if (!in_array(false, $names, true) && !in_array('', $names, true)) $locks[$v[1]][] = ['action' => $v[2], 'permission' => $v[3], 'fields' => $names];
     continue;
    }
    if(count($v)===9&&$v[0]==='INTEGRITY'&&preg_match('/^[01]{5}$/',$v[2].$v[5].$v[6].$v[7].$v[8])){$integrity[$v[1]]=['cryptographicIntegrity'=>$v[2]==='1','certificateId'=>$v[3],'docMdp'=>$v[4],'fieldLocked'=>$v[5]==='1','issuerTrusted'=>$v[6]==='1','coversFile'=>$v[7]==='1','timestampAnchored'=>$v[8]==='1'];continue;}
    if(count($v)===12&&$v[0]==='REVISION'&&preg_match('/^[01]{3}$/',$v[2].$v[3].$v[4])&&count(array_filter(array_slice($v,5),'ctype_digit'))===7){$revisions[$v[1]]=['checked'=>$v[2]==='1','byteRangeValid'=>$v[3]==='1','dictionaryConsistent'=>$v[4]==='1','extensionChanges'=>(int)$v[5],'signatureOrFormChanges'=>(int)$v[6],'annotationChanges'=>(int)$v[7],'undefinedChanges'=>(int)$v[8],'visualDifferences'=>(int)$v[9],'pageDifferences'=>(int)$v[10],'annotationOverlaps'=>(int)$v[11]];continue;}
    if(count($v)!==8||$v[0]!=='SIGNATURE')continue;$signatures[]=['id'=>$v[1],'name'=>base64_decode($v[2],true)?:'','format'=>$v[3],'indication'=>$v[4],'subIndication'=>$v[5],'timestampVerified'=>$v[6]==='1','bestSignatureTime'=>$v[7]];}
   foreach($signatures as &$signature){$id=$signature['id'];if(isset($revisions[$id]))$signature['revision']=$revisions[$id];$signature+=$integrity[$id]??[];$signature['signatureFields']=$fields[$id]??[];$signature['fieldLocks']=$locks[$id]??[];}unset($signature);
  return $signatures;
 }
 /** List visible, empty signature fields that the PDF permission checker allows. */
 public function availableSignatureFields(string $pdf): array {
  $dir = $this->config->getValueString(Application::APP_ID, 'pades_lt_lib_dir', '');
  $helper = dirname(__DIR__, 2) . '/resources/pades-report.jar';
  $path = $this->temp->getTemporaryFile('.pdf');
  try {
   if (!$path || !chmod($path, 0600) || file_put_contents($path, $pdf) !== strlen($pdf)) {
    throw new \RuntimeException('Could not prepare a PDF for field inspection.');
   }
   $process = new Process([$this->java->getJavaPath() ?: 'java', '-Xmx512m', '-Djava.awt.headless=true', '-cp', $helper . PATH_SEPARATOR . $dir . '/*', 'coop.libresign.PadesSignatureFields', $path]);
   $process->setTimeout(30);
   $process->mustRun();
   $fields = [];
   foreach (explode("\n", $process->getOutput()) as $line) {
    $parts = explode("\t", trim($line));
    if (count($parts) !== 7 || $parts[0] !== 'FIELD') continue;
    $name = base64_decode($parts[1], true);
    if ($name === false || $name === '' || !ctype_digit($parts[2]) || count(array_filter(array_slice($parts, 3), 'is_numeric')) !== 4) continue;
    $fields[] = ['name' => $name, 'page' => (int)$parts[2], 'rectangle' => array_map('floatval', array_slice($parts, 3))];
   }
   return $fields;
  } catch (\Throwable $e) {
   $this->logger->warning('PDF signature field inspection failed', ['exception' => $e]);
   throw new \OCA\Libresign\Exception\LibresignException('LibreSign could not check the existing signature fields in this PDF.');
  } finally {
   if ($path && is_file($path)) unlink($path);
  }
 }
 /** A timestamp-only intermediate is never returned to the signing workflow. */
 public function completeCountersignature(string $original, string $intermediate, array $existing, string $pkcs12, string $password, ?string $checkedField = null): string {
  $bundle = [];
  if (!openssl_pkcs12_read($pkcs12, $bundle, $password) || empty($bundle['cert'])) {
   throw new \RuntimeException('Could not check the new signing certificate.');
  }
  $certificateId = 'C-' . strtoupper(openssl_x509_fingerprint($bundle['cert'], 'sha256'));
  unset($bundle);
  $ids = PdfCountersigningPolicy::appended($original, $intermediate, $existing, $this->verify($intermediate), $certificateId, false, $checkedField);
  $dir = $this->config->getValueString(Application::APP_ID, 'pades_lt_lib_dir', '');
  $trust = $this->config->getValueString(Application::APP_ID, 'pades_lt_trust_files', '');
  $helper = dirname(__DIR__, 2) . '/resources/pades-report.jar';
  $input = $this->temp->getTemporaryFile('.pdf');
  $output = $this->temp->getTemporaryFile('.pdf');
  try {
   if (!$input || !$output || !chmod($input, 0600) || !chmod($output, 0600) || file_put_contents($input, $intermediate) !== strlen($intermediate)) {
    throw new \RuntimeException('Could not prepare private temporary PDF files.');
   }
   $process = new Process([$this->java->getJavaPath() ?: 'java', '-Xmx512m', '-Djava.awt.headless=true', '-cp', $helper . PATH_SEPARATOR . $dir . '/*', 'coop.libresign.PadesLtAugment', $input, $output, $trust, implode(',', $ids)]);
   $process->setTimeout(60);
   $process->mustRun();
   $pdf = file_get_contents($output);
   if (!$pdf || !str_starts_with($pdf, $intermediate)) throw new \RuntimeException('The timestamped PDF revision was not preserved.');
  } catch (\Throwable $e) {
   $this->logger->warning('Could not collect long-term evidence for the new signature', ['exception' => $e]);
   throw new \OCA\Libresign\Exception\LibresignException('Long-term evidence for the new signature is unavailable. No signed document was saved.');
  } finally {
   foreach ([$input, $output] as $path) if ($path && is_file($path)) unlink($path);
  }
  $finalIds = PdfCountersigningPolicy::appended($original, $pdf, $existing, $this->verify($pdf), $certificateId, true, $checkedField);
  if (array_diff($ids, $finalIds) || array_diff($finalIds, $ids)) throw new \RuntimeException('Unexpected signature changes while collecting long-term evidence.');
  return $pdf;
 }
 public function assertLt(string $pdf): void {$result=$this->verify($pdf);if(!$result['checked']||!$result['signatures'])throw new \RuntimeException('The long-term signature could not be verified. No signed document was saved.');foreach($result['signatures'] as $s)if($s['indication']!=='TOTAL_PASSED'||!in_array($s['format'],['PAdES_BASELINE_LT','PAdES_BASELINE_LTA'],true)||!$s['timestampVerified'])throw new \RuntimeException('Long-term signing evidence is incomplete. Check timestamp and certificate revocation services. No signed document was saved.');}
}
