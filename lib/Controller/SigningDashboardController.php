<?php
declare(strict_types=1);
/** SPDX-License-Identifier: AGPL-3.0-or-later */
namespace OCA\Libresign\Controller;
use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Service\SigningDashboardService;
use OCP\AppFramework\OCSController;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Files\NotPermittedException;
use Psr\Log\LoggerInterface;
class SigningDashboardController extends OCSController {
	public function __construct(IRequest $request, private IUserSession $session, private SigningDashboardService $dashboard, private LoggerInterface $logger) {parent::__construct(Application::APP_ID, $request);}
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[ApiRoute(verb: 'GET', url: '/api/{apiVersion}/dashboard', requirements: ['apiVersion' => '(v1)'])]
	public function index(string $view = 'ready', int $page = 1): DataResponse {
		try {
			$user = $this->session->getUser();
			if (!$user) return new DataResponse(['message' => 'Sign in to view your dashboard.'], 403);
			return new DataResponse($this->dashboard->get($user, $view, $page), 200, ['Cache-Control' => 'private, no-store']);
		} catch (NotPermittedException $e) {return new DataResponse(['message' => $e->getMessage()], 403);}
		catch (\InvalidArgumentException $e) {return new DataResponse(['message' => $e->getMessage()], 422);}
		catch (\Throwable $e) {$this->logger->error('Signing dashboard lookup failed', ['exception' => $e]); return new DataResponse(['message' => 'Unable to load your signing dashboard. Please retry.'], 500);}
	}
}
