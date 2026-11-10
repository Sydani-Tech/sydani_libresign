<?php
declare(strict_types=1);
/** SPDX-License-Identifier: AGPL-3.0-or-later */
namespace OCA\Libresign\Service;
class ReminderSchedulePolicy {
 public static function normalise(array $input): array {
  if(array_key_exists('enabled',$input)&&!is_bool($input['enabled']))throw new \InvalidArgumentException('Reminder enabled must be true or false.');
  if(isset($input['until'])&&!is_string($input['until']))throw new \InvalidArgumentException('Choose a valid reminder stop date.');
  $p=['enabled'=>(bool)($input['enabled']??false),'intervalHours'=>(int)($input['intervalHours']??24),'firstAfterDays'=>(int)($input['firstAfterDays']??1),'maxReminders'=>(int)($input['maxReminders']??10),'stopAfterDays'=>(int)($input['stopAfterDays']??30),'sendTime'=>$input['sendTime']??'09:00','secondTime'=>$input['secondTime']??'17:00','until'=>$input['until']??'','timezone'=>'Africa/Lagos'];
  if (!in_array($p['intervalHours'],[12,24,48],true) || $p['firstAfterDays']<1 || $p['firstAfterDays']>365 || $p['maxReminders']<1 || $p['maxReminders']>100 || $p['stopAfterDays']<1 || $p['stopAfterDays']>365) throw new \InvalidArgumentException('Choose a valid reminder interval, delay, limit and duration.');
  foreach (['sendTime','secondTime'] as $key) if (!is_string($p[$key]) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$p[$key])) throw new \InvalidArgumentException('Reminder times must use HH:mm.');
  if ($p['intervalHours']===12 && $p['secondTime']<=$p['sendTime']) throw new \InvalidArgumentException('The second reminder time must follow the first.');
  $date=$p['until']===''?null:\DateTimeImmutable::createFromFormat('!Y-m-d',(string)$p['until'],new \DateTimeZone('Africa/Lagos'));
  if ($p['until']!=='' && (!$date || $date->format('Y-m-d')!==$p['until'])) throw new \InvalidArgumentException('Choose a valid reminder stop date.');
  return $p;
 }
 /** Latest due slot only: a delayed cron never sends a burst of missed reminders. */
 public static function due(array $p,int $invitedAt,int $now,int $sent): ?int {
  if (!$p['enabled'] || $sent >= $p['maxReminders'] || $invitedAt<=0 || $now >= $invitedAt+$p['stopAfterDays']*86400) return null;
  $zone=new \DateTimeZone('Africa/Lagos');$today=(new \DateTimeImmutable('@'.$now))->setTimezone($zone)->setTime(0,0);
  $first=(new \DateTimeImmutable('@'.$invitedAt))->setTimezone($zone)->setTime(0,0)->modify('+'.$p['firstAfterDays'].' days');
  if ($p['until']!=='' && $today->format('Y-m-d')>$p['until']) return null;
  if ($today<$first) return null;
  $days=(int)$first->diff($today)->days;
  if ($p['intervalHours']===48 && $days%2!==0) return null;
  $times=$p['intervalHours']===12?[$p['sendTime'],$p['secondTime']]:[$p['sendTime']];$slot=null;
  foreach ($times as $time) {[$h,$m]=array_map('intval',explode(':',$time));$candidate=$today->setTime($h,$m)->getTimestamp();if ($candidate<=$now && $candidate>$invitedAt) $slot=$candidate;}
  return $slot;
 }
}
