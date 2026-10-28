<?php
declare(strict_types=1);
/** SPDX-License-Identifier: AGPL-3.0-or-later */
namespace OCA\Libresign\Service;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;
/** Adds reporting predicates only; callers must apply their own access scope first. */
final class DocumentListFilter {
 public static function apply(IQueryBuilder $qb, IDBConnection $db, IUserManager $users, array $filter): void {
  if (in_array($filter['letterhead'] ?? '', ['yes','no'], true)) {
   $sub=$db->getQueryBuilder();$sub->select('file_id')->from('libresign_letter_doc')->where($sub->expr()->isNotNull('file_id'));
   $qb->andWhere(($filter['letterhead']==='yes'?$qb->expr()->in('f.id',$qb->createFunction($sub->getSQL())):$qb->expr()->notIn('f.id',$qb->createFunction($sub->getSQL()))));
  }
  if (!empty($filter['name'])) $qb->andWhere($qb->expr()->iLike('f.name', $qb->createNamedParameter('%' . $db->escapeLikeParameter($filter['name']) . '%')));
  if (!empty($filter['sender'])) $qb->andWhere($qb->expr()->eq('f.user_id', $qb->createNamedParameter($filter['sender'])));
		// Keep both filters on the same participant; matching different people on
		// one document must not satisfy a name/email combination.
		if (!empty($filter['recipientName']) || !empty($filter['recipientEmail'])) {
			$sub = $db->getQueryBuilder();
			$sub->select('sr2.file_id')->from('libresign_sign_request', 'sr2')
				->leftJoin('sr2', 'libresign_identify_method', 'im2', 'im2.sign_request_id = sr2.id');
			if (!empty($filter['recipientName'])) {
				$sub->andWhere($sub->expr()->iLike('sr2.display_name', $qb->createNamedParameter('%' . $db->escapeLikeParameter($filter['recipientName']) . '%')));
			}
			if (!empty($filter['recipientEmail'])) {
				$matches = $sub->expr()->andX(
					$sub->expr()->eq('im2.identifier_key', $qb->createNamedParameter('email')),
					$sub->expr()->iLike('im2.identifier_value', $qb->createNamedParameter('%' . $db->escapeLikeParameter($filter['recipientEmail']) . '%')),
				);
				// Internal signers store a UID, not their email, in identify_method.
				$uids = [];
				$accounts = $db->getQueryBuilder();
				$accounts->selectDistinct('identifier_value')->from('libresign_identify_method')
					->where($accounts->expr()->eq('identifier_key', $accounts->createNamedParameter('account')));
				foreach ($accounts->executeQuery()->fetchAll() as $account) {
					$user = $users->get($account['identifier_value']);
					if ($user && stripos($user->getEMailAddress() ?? '', $filter['recipientEmail']) !== false) $uids[] = $user->getUID();
				}
				if ($uids) {
					$matches = $sub->expr()->orX($matches, $sub->expr()->andX(
						$sub->expr()->eq('im2.identifier_key', $qb->createNamedParameter('account')),
						$sub->expr()->in('im2.identifier_value', $qb->createNamedParameter(array_values(array_unique($uids)), IQueryBuilder::PARAM_STR_ARRAY)),
					));
				}
				$sub->andWhere($matches);
			}
			$qb->andWhere($qb->expr()->in('f.id', $qb->createFunction($sub->getSQL())));
		}
		$dateColumn = match ($filter['dateBy'] ?? 'requested') { 'completed' => 'f.completed_at', 'created' => 'f.created_at', default => 'f.requested_at' };
		foreach (['from' => 'gte', 'to' => 'lte'] as $key => $operation) {
			if (!empty($filter[$key])) {
				$date = (new \DateTimeImmutable('@' . (int)$filter[$key]))->format('Y-m-d H:i:s');
				$qb->andWhere($qb->expr()->$operation($dateColumn, $qb->createNamedParameter($date)));
			}
		}
 }
}
