<?php
declare(strict_types=1);
/** SPDX-License-Identifier: AGPL-3.0-or-later */
namespace OCA\Libresign\Service;
use OCA\Libresign\AppInfo\Application;
use OCP\IAppConfig;
/** Strict LT configuration: no permissive trust and no silent downgrade to a basic signature. */
class PadesLtService {
 public function __construct(private IAppConfig $config) {}
 public function enabled(): bool {return $this->config->getValueBool(Application::APP_ID,'pades_lt_enabled',false);}
 public function jar(string $legacy): string {if(!$this->enabled())return $legacy;$path=$this->config->getValueString(Application::APP_ID,'pades_lt_jar','');if(!$path||!is_file($path))throw new \RuntimeException('The PAdES-LT signing engine is not installed.');return $path;}
 public function parameters(): array {
  if(!$this->enabled())return [];
  $tsa=$this->config->getValueString(Application::APP_ID,'tsa_url','');$trust=$this->config->getValueString(Application::APP_ID,'pades_lt_trust_files','');
  if(!$tsa||!filter_var($tsa,FILTER_VALIDATE_URL)||!$trust)throw new \RuntimeException('PAdES-LT requires a timestamp service and trusted CA certificates.');
  foreach(explode(',',$trust) as $path)if(!$path||!is_file($path)||!is_readable($path))throw new \RuntimeException('PAdES-LT trust material is unavailable.');
  return ['--engine'=>'dss','--pades-level'=>'LT','--option'=>['engine.dss.online.enabled=true','engine.dss.trust.certFiles='.$trust,'engine.dss.trust.allowUntrusted=false','engine.dss.tsa.nonce=true']];
 }
}
