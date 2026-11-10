<?php
declare(strict_types=1);
/** SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
namespace OCA\Libresign\Service;

use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;

/** Only the selected approver sees the count; administrator membership grants no additional scope. */
class LetterheadApprovalDashboardService {
 public function __construct(private IDBConnection $db, private IGroupManager $groups) {}
 public function summary(IUser $user): array {
  if (!$user->isEnabled() || !$this->groups->isInGroup($user->getUID(), LetterheadService::APPROVER_GROUP)) {
   return ['canApprove' => false, 'pending' => 0];
  }
  $q = $this->db->getQueryBuilder();
  $q->select($q->func()->count('id'))->from('libresign_letter_doc')
   ->where($q->expr()->eq('approver_uid', $q->createNamedParameter($user->getUID())))
   ->andWhere($q->expr()->neq('owner_uid', $q->createNamedParameter($user->getUID())))
   ->andWhere($q->expr()->eq('state', $q->createNamedParameter('pending')));
  $result = $q->executeQuery();
  $pending = (int)$result->fetchOne();
  $result->closeCursor();
  return ['canApprove' => true, 'pending' => $pending];
 }
}
