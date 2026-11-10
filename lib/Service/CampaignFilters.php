<?php
declare(strict_types=1);
/** SPDX-License-Identifier: AGPL-3.0-or-later */
namespace OCA\Libresign\Service;

/** Strict shared query contract. Dates are calendar dates in West Africa Time. */
class CampaignFilters {
 public static function normalise(array $input): array {
  $f = [];
  foreach (['name'=>200,'version'=>80,'sender'=>64,'recipientName'=>200,'recipientEmail'=>254] as $key=>$limit) $f[$key] = CampaignDefinition::text($input[$key] ?? '', $limit, $key, false);
  foreach (['status'=>['','draft','active','completed','cancelled','overdue'],'kind'=>['','internal','external'],'dateBy'=>['created','requested','completed','deadline'],'sortBy'=>['created','requested','completed','deadline','name'],'direction'=>['asc','desc']] as $key=>$allowed) {
   $value = $input[$key] ?? match($key) {'dateBy','sortBy'=>'created','direction'=>'desc',default=>''};
   if (!in_array($value,$allowed,true)) throw new \InvalidArgumentException('Invalid campaign '.$key.'.');
   $f[$key]=$value;
  }
  foreach (['from','to'] as $key) {
   $value=$input[$key] ?? '';
   if (!is_string($value)) throw new \InvalidArgumentException('Invalid date.');
   $date=$value === '' ? null : \DateTimeImmutable::createFromFormat('!Y-m-d',$value,new \DateTimeZone('Africa/Lagos'));
   if ($value !== '' && (!$date || $date->format('Y-m-d') !== $value)) throw new \InvalidArgumentException('Invalid campaign date.');
   $f[$key]=$value; $f[$key.'Timestamp']=$date?->getTimestamp();
  }
  if ($f['from'] && $f['to'] && $f['from'] > $f['to']) throw new \InvalidArgumentException('The end date must be on or after the start date.');
  return $f;
 }
}
