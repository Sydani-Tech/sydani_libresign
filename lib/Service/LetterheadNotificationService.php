<?php
declare(strict_types=1);
/** SPDX-License-Identifier: AGPL-3.0-or-later */
namespace OCA\Libresign\Service;
use OCP\IUserManager;
use OCP\IURLGenerator;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;
class LetterheadNotificationService {
 public function __construct(private IUserManager $users,private IURLGenerator $urls,private IMailer $mailer,private NotificationPreferenceResolver $preferences,private LoggerInterface $logger) {}
 /** Returns sent/skipped/failed; email failure must never undo a recorded decision. */
 public function send(array $d,string $uid,string $action): string {
  if($this->preferences->isEmailNotificationDisabled($uid,'libresign_file_to_sign',true))return 'skipped';
  $u=$this->users->get($uid);if(!$u||!$u->isEnabled()||!filter_var($u->getEMailAddress(),FILTER_VALIDATE_EMAIL))return 'failed';
  try {
   $template=$this->mailer->createEMailTemplate('libresign.letterheadApproval');$name=preg_replace('/[\r\n]+/',' ',$d['name']);$title=$action==='submitted'?'Letterhead document awaiting your approval':($action==='approved'?'Letterhead document approved':'Letterhead document rejected');$template->setSubject('LibreSign: '.$title);$template->addHeader();$template->addHeading($title,false);$template->addBodyText($name);$template->addBodyText($action==='submitted'?'Review the exact letterhead, content and recipients before approving release. No signing invitations have been sent.':'Open Letterheads to review the decision. Approval does not automatically send signing invitations.');$template->addBodyButton('Open Letterheads',$this->urls->linkToRouteAbsolute('libresign.page.indexFPath',['path'=>'letterheads']).'?document='.(int)$d['id']);$message=$this->mailer->createMessage();$message->setTo([$u->getEMailAddress()=>$u->getDisplayName()]);$message->useTemplate($template);$this->mailer->send($message);return 'sent';
  }catch(\Throwable $e){$this->logger->error('Letterhead review notification failed',['documentId'=>$d['id'],'exception'=>$e]);return 'failed';}
 }
}
