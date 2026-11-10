<?php
declare(strict_types=1);
/** SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */

/** Authorise sequential request overrides only for this run's synthetic sender. */
function prepareSequentialQaPolicy(object $container, \OCP\IUser $owner): void {
 if(PHP_SAPI!=='cli'||gethostname()!=='nextcloud-staging'||posix_geteuid()!==(posix_getpwnam('www-data')['uid']??-1)
  ||!preg_match('/^(ordinary-date-qa-|archive-qa-|campaign-qa-)/',$owner->getUID()))throw new RuntimeException('Sequential QA policies require a synthetic staging owner.');
 $groups=\OCP\Server::get(\OCP\IGroupManager::class);$session=\OCP\Server::get(\OCP\IUserSession::class);
 $policy=$container->get(\OCA\Libresign\Service\Policy\PolicyService::class);$admin=$groups->get('admin');
 if(!$admin)throw new RuntimeException('Administrator group is unavailable.');
 $group=$groups->createGroup('sequential-qa-'.bin2hex(random_bytes(8)));if(!$group)throw new RuntimeException('Cannot create isolated QA group.');
 $group->addUser($owner);$originalUser=$session->getUser();$wasAdmin=$admin->inGroup($owner);
 // The root-launched QA test may create a policy through the normal administrator
 // service. The delegation applies only to its new group, never to real users.
 register_shutdown_function(static function()use($groups,$group,$owner,$session,$policy,$admin,$wasAdmin):void{
  $previous=$session->getUser();
  try{$admin->addUser($owner);$session->setUser($owner);$policy->clearGroupPolicy('signature_flow',$group->getGID());$group->removeUser($owner);$group->delete();}
  finally{if(!$wasAdmin)$admin->removeUser($owner);$session->setUser($previous);}
 });
 try{$admin->addUser($owner);$session->setUser($owner);$policy->saveGroupPolicy('signature_flow',$group->getGID(),'ordered_numeric',true);}
 finally{if(!$wasAdmin)$admin->removeUser($owner);$session->setUser($originalUser);}
 $resolved=$policy->resolveForUser('signature_flow',$owner,['signature_flow'=>'ordered_numeric']);
 if(!$resolved->canUseAsRequestOverride()||$resolved->getEffectiveValue()!=='ordered_numeric')throw new RuntimeException('Isolated sequential policy did not resolve.');
 echo "PASS: sequential flow delegated only to this synthetic sender; global policies unchanged.\n";
}
