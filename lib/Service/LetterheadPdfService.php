<?php
declare(strict_types=1);
/** SPDX-License-Identifier: AGPL-3.0-or-later */
namespace OCA\Libresign\Service;
use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Helper\JavaHelper;
use OCP\IAppConfig;
use OCP\ITempManager;
use Symfony\Component\Process\Process;
class LetterheadPdfService {
 public function __construct(private IAppConfig $config,private ITempManager $temp,private JavaHelper $java) {}
 public function compose(string $source,string $template,string $layer): string {
  $paths=[];try {
   foreach (['source.pdf','template.pdf','letterheaded.pdf'] as $suffix) {$p=$this->temp->getTemporaryFile($suffix);if(!$p)throw new \RuntimeException('Cannot prepare document.');chmod($p,0600);$paths[]=$p;}
   file_put_contents($paths[0],$source);file_put_contents($paths[1],$template);
   $jar=$this->config->getValueString(Application::APP_ID,'jsignpdf_jar_path');$helper=dirname(__DIR__,2).'/resources/letterhead-pdf.jar';
   $process=new Process([$this->java->getJavaPath()?:'java','-Djava.awt.headless=true','-cp',$helper.PATH_SEPARATOR.$jar,'coop.libresign.LetterheadPdf',...$paths,$layer]);$process->setTimeout(60);$process->mustRun();
   $pdf=file_get_contents($paths[2]);if(!$pdf||!str_starts_with($pdf,'%PDF-'))throw new \RuntimeException('Unable to apply letterhead.');return $pdf;
  } finally {foreach($paths as $p)if(is_file($p))unlink($p);}
 }
 public function preview(string $pdf,int $page): string {
  if($page<1||$page>100)throw new \InvalidArgumentException('Invalid preview page.');$path=$this->temp->getTemporaryFile('.pdf');if(!$path)throw new \RuntimeException('Cannot prepare preview.');chmod($path,0600);
  try {file_put_contents($path,$pdf);$process=new Process(['/usr/bin/pdftoppm','-f',(string)$page,'-l',(string)$page,'-singlefile','-scale-to','1400','-png',$path]);$process->setTimeout(30);$process->mustRun();$png=$process->getOutput();if(!str_starts_with($png,"\x89PNG"))throw new \RuntimeException('Preview unavailable.');return $png;}finally{if(is_file($path))unlink($path);}
 }
}
