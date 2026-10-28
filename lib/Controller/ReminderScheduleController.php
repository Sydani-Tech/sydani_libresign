<?php
declare(strict_types=1);
/** SPDX-License-Identifier: AGPL-3.0-or-later */
namespace OCA\Libresign\Controller;
use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Service\ScheduledReminderService;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
class ReminderScheduleController extends OCSController {
 public function __construct(IRequest $request,private ScheduledReminderService $schedule, private \OCA\Libresign\Service\CampaignAccessService $access, private \OCP\IUserSession $session) {parent::__construct(Application::APP_ID,$request);}
 #[NoCSRFRequired]
 #[ApiRoute(verb:'GET',url:'/api/{apiVersion}/admin/reminder-schedule',requirements:['apiVersion'=>'(v1)'])]
 public function settings(): DataResponse {if(!$this->access->isAdmin($this->session->getUser()))return new DataResponse(['message'=>'Administrator access required.'],403);return new DataResponse($this->schedule->settings());}
 #[PasswordConfirmationRequired]
 #[ApiRoute(verb:'POST',url:'/api/{apiVersion}/admin/reminder-schedule',requirements:['apiVersion'=>'(v1)'])]
 public function save(array $policy): DataResponse {if(!$this->access->isAdmin($this->session->getUser()))return new DataResponse(['message'=>'Administrator access required.'],403);try {return new DataResponse($this->schedule->save($policy));}catch(\InvalidArgumentException $e){return new DataResponse(['message'=>$e->getMessage()],422);}}
}
