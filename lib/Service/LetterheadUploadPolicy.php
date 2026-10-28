<?php
declare(strict_types=1);
/** SPDX-License-Identifier: AGPL-3.0-or-later */
namespace OCA\Libresign\Service;
use OCP\IDBConnection;
use OCP\Files\NotPermittedException;
/** Recognises exact managed PDF exports; this is not visual/logo recognition. */
class LetterheadUploadPolicy {
 private static ?array $approvedContext=null;
 public function __construct(private IDBConnection $db) {}
 public static function withApproval(int $id,string $hash,callable $action): mixed {$previous=self::$approvedContext;self::$approvedContext=['id'=>$id,'hash'=>$hash];try{return $action();}finally{self::$approvedContext=$previous;}}
 public function assertMayUpload(string $pdf): void {
  $hash=hash('sha256',$pdf);$q=$this->db->getQueryBuilder();$rows=$q->select('id')->from('libresign_letter_doc')->where($q->expr()->eq('composed_hash',$q->createNamedParameter($hash)))->executeQuery()->fetchAll();
  if(!$rows)return;
  if(self::$approvedContext!==null&&hash_equals(self::$approvedContext['hash'],$hash)&&in_array(self::$approvedContext['id'],array_map(fn($r)=>(int)$r['id'],$rows),true))return;
  throw new NotPermittedException('This PDF is managed by the letterhead approval workflow. Continue from Letterheads. A different recipient list requires a new approval.');
 }
}
