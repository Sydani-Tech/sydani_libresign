<?php
declare(strict_types=1);
/** SPDX-License-Identifier: AGPL-3.0-or-later */
namespace OCA\Libresign\Service;
use OCA\Libresign\AppInfo\Application;
use OCP\IAppConfig;
use OCA\Libresign\Service\Policy\PolicyService;
use OCA\Libresign\Service\Policy\Provider\Tsa\TsaPolicy;
use OCA\Libresign\Service\Policy\Provider\Tsa\TsaPolicyValue;
/** Strict LT configuration: no permissive trust and no silent downgrade to a basic signature. */
class PadesLtService {
 public function __construct(private IAppConfig $config, private PolicyService $policyService) {}
 public function enabled(): bool {return $this->config->getValueBool(Application::APP_ID,'pades_lt_enabled',false);}
 public function jar(string $legacy): string {if(!$this->enabled())return $legacy;$path=$this->config->getValueString(Application::APP_ID,'pades_lt_jar','');if(!$path||!is_file($path))throw new \RuntimeException('The PAdES-LT signing engine is not installed.');return $path;}
 public function engineDirectory(string $configured): string {
  if (!$this->enabled()) return $configured;
  $directory = dirname($this->jar(''));
  if (basename($directory) === 'lib') $directory = dirname($directory);
  if (!is_dir($directory . '/lib')) throw new \RuntimeException('The configured PAdES-LT engine distribution is incomplete.');
  return $directory;
 }
 /** Refresh local evidence after certificate issuance, without changing trust policy. */
 public function refreshLocalRevocation(string $pkcs12, string $password): void {
  if (!$this->enabled()) return;
  $bundle = [];
  if (!openssl_pkcs12_read($pkcs12, $bundle, $password) || empty($bundle['cert'])) throw new \RuntimeException('Cannot inspect the signing certificate for long-term validation.');
  $leaf = $bundle['cert'];
  unset($bundle);
  $parsed = openssl_x509_parse($leaf);
  if (!$parsed) throw new \RuntimeException('Cannot parse the signing certificate.');
  $urls = (new \OCA\Libresign\Service\Crl\CrlDistributionPointsExtractor())->extractFromExtensions($parsed['extensions'] ?? [])['urls'];
  $parser = \OCP\Server::get(\OCA\Libresign\Service\Crl\CrlUrlParserService::class);
  $crls = \OCP\Server::get(\OCA\Libresign\Service\Crl\CrlService::class);
  $seen = [];
  foreach ($urls as $url) {
   $scope = $parser->parseUrl($url);
   if ($scope === null) continue; // External issuers are handled by DSS, never by our CA.
   $key = implode(':', $scope);
   if (isset($seen[$key])) continue;
   $issuer = $crls->getRootCertificateFromCrlUrls([$url]);
   $publicKey = $issuer !== '' ? openssl_pkey_get_public($issuer) : false;
   if (!$publicKey || openssl_x509_verify($leaf, $publicKey) !== 1) throw new \RuntimeException('The local revocation endpoint does not match the signing certificate issuer.');
   // A daily cached CRL can predate a newly issued certificate. DSS correctly
   // refuses that evidence; issue a fresh signed CRL before online LT assembly.
   $crls->refreshGeneratedCrlDer($scope['instanceId'], $scope['generation'], $scope['engineType']);
   $seen[$key] = true;
  }
 }
 public function parameters(bool $countersigning = false): array {
  if(!$this->enabled())return [];
  $settings = TsaPolicyValue::decode($this->policyService->resolve(TsaPolicy::KEY)->getEffectiveValue());
  $tsa = $settings['url'];$trust=$this->config->getValueString(Application::APP_ID,'pades_lt_trust_files','');
  if(!$tsa||!filter_var($tsa,FILTER_VALIDATE_URL)||!$trust)throw new \RuntimeException('PAdES-LT requires a timestamp service and trusted CA certificates.');
  foreach(explode(',',$trust) as $path)if(!$path||!is_file($path)||!is_readable($path))throw new \RuntimeException('PAdES-LT trust material is unavailable.');
  return ['--engine'=>'dss','--pades-level'=>$countersigning?'T':'LT','--option'=>['engine.dss.online.enabled='.($countersigning?'false':'true'),'engine.dss.trust.certFiles='.$trust,'engine.dss.trust.allowUntrusted=false','engine.dss.tsa.nonce=true']];
 }
}
