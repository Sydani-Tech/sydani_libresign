<?php
declare(strict_types=1);
/** SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
namespace OCA\Libresign\Service;
use OCP\IUser;
use OCP\IUserSession;
/** Apply the authorised campaign owner's policies in both web and cron requests. */
class CampaignRequestContext {
 public function __construct(private IUserSession $session,private CampaignAccessService $access) {}
 public function run(IUser $owner,callable $callback): mixed {
  $this->access->assertCreator($owner);
  $previous=$this->session->getUser();
  $this->session->setVolatileActiveUser($owner);
  try{return $callback();}finally{$this->session->setVolatileActiveUser($previous);}
 }
}
