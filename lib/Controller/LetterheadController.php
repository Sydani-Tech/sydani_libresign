<?php
declare(strict_types=1);
/** SPDX-License-Identifier: AGPL-3.0-or-later */
namespace OCA\Libresign\Controller;
use OCP\AppFramework\Http;
use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Service\LetterheadService;
use OCA\Libresign\Exception\LibresignException;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\AppFramework\OCSController;
use OCP\Files\NotPermittedException;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
class LetterheadController extends OCSController {
 public function __construct(IRequest $request,private LetterheadService $letters,private IUserSession $session,private LoggerInterface $logger){parent::__construct(Application::APP_ID,$request);}
 private function user(): \OCP\IUser {$u=$this->session->getUser();if(!$u||!$u->isEnabled())throw new NotPermittedException('Sign in with an active account.');return $u;}
 private function respond(callable $action): DataResponse {try{return new DataResponse($action(),200,['Cache-Control'=>'private, no-store']);}catch(NotPermittedException $e){return new DataResponse(['message'=>$e->getMessage()],403);}catch(\OCP\AppFramework\Db\DoesNotExistException|\OCP\Files\NotFoundException){return new DataResponse(['message'=>'Document unavailable.'],404);}catch(\InvalidArgumentException $e){return new DataResponse(['message'=>$e->getMessage()],422);}catch(LibresignException $e){if($e->getCode()===422)return new DataResponse(['message'=>$e->getMessage()],422);$this->logger->error('Letterhead operation failed',['exception'=>$e]);return new DataResponse(['message'=>'Unable to complete the letterhead operation. Please contact an administrator.'],500);}catch(\Throwable $e){$this->logger->error('Letterhead operation failed',['exception'=>$e]);return new DataResponse(['message'=>$e->getCode()===409?$e->getMessage():'Unable to complete the letterhead operation. Please contact an administrator.'],$e->getCode()===409?409:500);}}
 /**
  * List shared letterhead templates.
  *
  * @return DataResponse<Http::STATUS_OK, array<string, mixed>|list<array<string, mixed>>, array{}>
  *
  * 200: Operation completed
  */
 #[NoAdminRequired] #[NoCSRFRequired] #[ApiRoute(verb:'GET',url:'/api/{apiVersion}/letterheads',requirements:['apiVersion'=>'(v1)'])]
 public function templates(): DataResponse {return $this->respond(fn()=>$this->letters->templates($this->user()));}
 /**
  * Create a shared letterhead template from a PDF.
  *
  * @param string $name Document name search
  * @param string $pdf Base64 encoded PDF
  * @return DataResponse<Http::STATUS_OK, array<string, mixed>|list<array<string, mixed>>, array{}>
  *
  * 200: Operation completed
  */
 #[PasswordConfirmationRequired] #[ApiRoute(verb:'POST',url:'/api/{apiVersion}/letterheads',requirements:['apiVersion'=>'(v1)'])]
 public function createTemplate(string $name,string $pdf): DataResponse {return $this->respond(fn()=>$this->letters->addTemplate($this->user(),$name,$pdf));}
 /**
  * Activate or deactivate a shared letterhead.
  *
  * @param int $id Record identifier
  * @param bool $active Whether the template is available for new documents
  * @return DataResponse<Http::STATUS_OK, array<string, mixed>|list<array<string, mixed>>, array{}>
  *
  * 200: Operation completed
  */
 #[PasswordConfirmationRequired] #[ApiRoute(verb:'POST',url:'/api/{apiVersion}/letterheads/{id}/state',requirements:['apiVersion'=>'(v1)','id'=>'\d+'])]
 public function templateState(int $id,bool $active): DataResponse {return $this->respond(fn()=>$this->letters->templateState($this->user(),$id,$active));}
 /**
  * Search internal recipients or authorised letterhead approvers.
  *
  * @param string $search Search text
  * @param bool $approvers Search approvers instead of recipients
  * @return DataResponse<Http::STATUS_OK, array<string, mixed>|list<array<string, mixed>>, array{}>
  *
  * 200: Operation completed
  */
 #[NoAdminRequired] #[NoCSRFRequired] #[ApiRoute(verb:'GET',url:'/api/{apiVersion}/letterhead/users',requirements:['apiVersion'=>'(v1)'])]
 public function users(string $search='',bool $approvers=false): DataResponse {return $this->respond(fn()=>$this->letters->people($this->user(),$search,$approvers));}
 /**
  * Resolve the intended letterhead document recipient.
  *
  * @param array<string, mixed> $recipient Recipient name, email and optional account identifier
  * @return DataResponse<Http::STATUS_OK, array<string, mixed>|list<array<string, mixed>>, array{}>
  *
  * 200: Operation completed
  */
 #[NoAdminRequired] #[ApiRoute(verb:'POST',url:'/api/{apiVersion}/letterhead/resolve',requirements:['apiVersion'=>'(v1)'])]
 public function resolve(array $recipient): DataResponse {return $this->respond(fn()=>$this->letters->resolve($this->user(),$recipient));}
 /**
  * List personal documents, assigned approvals or organisational documents.
  *
  * @param int $page One-based page number
  * @param string $view List scope or dashboard section
  * @param string $state Empty for all states, or pending
  * @return DataResponse<Http::STATUS_OK, array<string, mixed>|list<array<string, mixed>>, array{}>
  *
  * 200: Operation completed
  */
 #[NoAdminRequired] #[NoCSRFRequired] #[ApiRoute(verb:'GET',url:'/api/{apiVersion}/letterhead/documents',requirements:['apiVersion'=>'(v1)'])]
 public function index(int $page=1,string $view='mine',string $state=''): DataResponse {return $this->respond(fn()=>$this->letters->list($this->user(),$page,$view,$state));}
 /**
  * Compose a PDF with a shared letterhead without sending invitations.
  *
  * @param array<string, mixed> $document Document name, template, PDF, recipients and approver
  * @return DataResponse<Http::STATUS_OK, array<string, mixed>|list<array<string, mixed>>, array{}>
  *
  * 200: Operation completed
  */
 #[NoAdminRequired] #[ApiRoute(verb:'POST',url:'/api/{apiVersion}/letterhead/documents',requirements:['apiVersion'=>'(v1)'])]
 public function create(array $document): DataResponse {return $this->respond(fn()=>$this->letters->create($this->user(),$document));}
 /**
  * Read a letterheaded document and its approval history.
  *
  * @param int $id Record identifier
  * @return DataResponse<Http::STATUS_OK, array<string, mixed>|list<array<string, mixed>>, array{}>
  *
  * 200: Operation completed
  */
 #[NoAdminRequired] #[NoCSRFRequired] #[ApiRoute(verb:'GET',url:'/api/{apiVersion}/letterhead/documents/{id}',requirements:['apiVersion'=>'(v1)','id'=>'\d+'])]
 public function show(int $id): DataResponse {return $this->respond(fn()=>$this->letters->get($this->user(),$id));}
 /**
  * Submit a frozen letterheaded document for the selected approver.
  *
  * @param int $id Record identifier
  * @param int $revision Current revision for optimistic concurrency
  * @return DataResponse<Http::STATUS_OK, array<string, mixed>|list<array<string, mixed>>, array{}>
  *
  * 200: Operation completed
  */
 #[NoAdminRequired] #[ApiRoute(verb:'POST',url:'/api/{apiVersion}/letterhead/documents/{id}/submit',requirements:['apiVersion'=>'(v1)','id'=>'\d+'])]
 public function submit(int $id,int $revision): DataResponse {return $this->respond(fn()=>$this->letters->submit($this->user(),$id,$revision));}
 /**
  * Approve or reject an assigned frozen document.
  *
  * @param int $id Record identifier
  * @param int $revision Current revision for optimistic concurrency
  * @param bool $approve Approve when true; otherwise reject
  * @param string $reason Recorded reason for the action
  * @return DataResponse<Http::STATUS_OK, array<string, mixed>|list<array<string, mixed>>, array{}>
  *
  * 200: Operation completed
  */
 #[NoAdminRequired] #[ApiRoute(verb:'POST',url:'/api/{apiVersion}/letterhead/documents/{id}/decision',requirements:['apiVersion'=>'(v1)','id'=>'\d+'])]
 public function decide(int $id,int $revision,bool $approve,string $reason=''): DataResponse {return $this->respond(fn()=>$this->letters->decide($this->user(),$id,$revision,$approve,$reason));}
 /**
  * Prepare signing for the approved recipient list without sending invitations.
  *
  * @param int $id Record identifier
  * @return DataResponse<Http::STATUS_OK, array<string, mixed>|list<array<string, mixed>>, array{}>
  *
  * 200: Operation completed
  */
 #[NoAdminRequired] #[ApiRoute(verb:'POST',url:'/api/{apiVersion}/letterhead/documents/{id}/request',requirements:['apiVersion'=>'(v1)','id'=>'\d+'])]
 public function request(int $id): DataResponse {return $this->respond(fn()=>$this->letters->prepareRequest($this->user(),$id));}
 /**
  * Render a preview page without releasing the composed PDF.
  *
  * @param int $id Record identifier
  * @param int $page One-based page number
  * @return DataDownloadResponse<Http::STATUS_OK, string, array{}>|DataResponse<Http::STATUS_OK, array<string, mixed>|list<array<string, mixed>>, array{}>
  *
  * 200: Operation completed
  */
 #[NoAdminRequired] #[NoCSRFRequired] #[ApiRoute(verb:'GET',url:'/api/{apiVersion}/letterhead/documents/{id}/preview/{page}',requirements:['apiVersion'=>'(v1)','id'=>'\d+','page'=>'\d+'])]
 public function preview(int $id,int $page): DataDownloadResponse|DataResponse {$content=null;$r=$this->respond(function()use($id,$page,&$content){$content=$this->letters->preview($this->user(),$id,$page);return [];});if($content===null)return $r;$response=new DataDownloadResponse($content,'preview.png','image/png');$response->addHeader('Cache-Control','private, no-store');$response->addHeader('Content-Disposition','inline');$response->addHeader('X-Content-Type-Options','nosniff');return $response;}
 /**
  * Download a document only after its release rules are satisfied.
  *
  * @param int $id Record identifier
  * @return FileDisplayResponse<Http::STATUS_OK, array{Content-Type: string}>|DataResponse<Http::STATUS_OK, array<string, mixed>|list<array<string, mixed>>, array{}>
  *
  * 200: Operation completed
  */
 #[NoAdminRequired] #[NoCSRFRequired] #[ApiRoute(verb:'GET',url:'/api/{apiVersion}/letterhead/documents/{id}/download',requirements:['apiVersion'=>'(v1)','id'=>'\d+'])]
 public function download(int $id): FileDisplayResponse|DataResponse {$node=null;$r=$this->respond(function()use($id,&$node){$node=$this->letters->download($this->user(),$id);return [];});return $node?new FileDisplayResponse($node,200,['Content-Type'=>'application/pdf','Content-Disposition'=>'attachment; filename="letterhead-document.pdf"','Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']):$r;}
}
