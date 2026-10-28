<?php
declare(strict_types=1);
/** SPDX-License-Identifier: AGPL-3.0-or-later */
namespace OCA\Libresign\Migration;
use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
class Version18005Date20261028000000 extends SimpleMigrationStep {
 public function changeSchema(IOutput $output,Closure $schemaClosure,array $options): ?ISchemaWrapper {
  $s=$schemaClosure();
  if (!$s->hasTable('libresign_letterhead')) {
   $t=$s->createTable('libresign_letterhead');$t->addColumn('id',Types::BIGINT,['autoincrement'=>true,'notnull'=>true]);
   foreach (['name'=>200,'filename'=>100,'hash'=>64,'owner_uid'=>64] as $name=>$length) $t->addColumn($name,Types::STRING,['length'=>$length,'notnull'=>true]);
   $t->addColumn('active',Types::INTEGER,['default'=>1,'notnull'=>true]);$t->addColumn('created_at',Types::BIGINT,['notnull'=>true]);$t->setPrimaryKey(['id']);
  }
  if (!$s->hasTable('libresign_letter_doc')) {
   $t=$s->createTable('libresign_letter_doc');$t->addColumn('id',Types::BIGINT,['autoincrement'=>true,'notnull'=>true]);
   foreach (['owner_uid'=>64,'name'=>200,'source_file'=>100,'composed_file'=>100,'composed_hash'=>64,'template_hash'=>64,'state'=>20,'layer'=>12] as $name=>$length) $t->addColumn($name,Types::STRING,['length'=>$length,'notnull'=>true]);
   $t->addColumn('approver_uid',Types::STRING,['length'=>64,'notnull'=>false]);
   foreach (['recipients','pdf_metadata','reason'] as $name) $t->addColumn($name,Types::TEXT,['notnull'=>false]);
   foreach (['template_id','created_at','submitted_at','approved_at','file_id'] as $name) $t->addColumn($name,Types::BIGINT,['notnull'=>in_array($name,['template_id','created_at'],true)]);
   $t->addColumn('revision',Types::INTEGER,['default'=>1,'notnull'=>true]);$t->setPrimaryKey(['id']);$t->addUniqueIndex(['file_id'],'ls_letter_file');$t->addIndex(['owner_uid','created_at'],'ls_letter_owner');$t->addIndex(['approver_uid','state'],'ls_letter_approver');
  }
  if (!$s->hasTable('libresign_letter_event')) {
   $t=$s->createTable('libresign_letter_event');$t->addColumn('id',Types::BIGINT,['autoincrement'=>true,'notnull'=>true]);$t->addColumn('document_id',Types::BIGINT,['notnull'=>true]);
   foreach (['actor'=>64,'action'=>32,'document_hash'=>64] as $name=>$length) $t->addColumn($name,Types::STRING,['length'=>$length,'notnull'=>true]);
   $t->addColumn('details',Types::TEXT,['notnull'=>true]);$t->addColumn('created_at',Types::BIGINT,['notnull'=>true]);$t->setPrimaryKey(['id']);$t->addIndex(['document_id','id'],'ls_letter_events');
  }
  if (!$s->hasTable('libresign_reminder')) {
   $t=$s->createTable('libresign_reminder');$t->addColumn('id',Types::BIGINT,['autoincrement'=>true,'notnull'=>true]);
   foreach (['sign_request_id','slot','created_at'] as $name) $t->addColumn($name,Types::BIGINT,['notnull'=>true]);
   $t->addColumn('state',Types::STRING,['length'=>16,'notnull'=>true]);$t->setPrimaryKey(['id']);$t->addUniqueIndex(['sign_request_id','slot'],'ls_reminder_once');
  }
  return $s;
 }
}
