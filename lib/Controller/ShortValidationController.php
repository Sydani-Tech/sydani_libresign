<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Libresign\Controller;

use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Service\ShortValidationUrlService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use OCP\IURLGenerator;

#[OpenAPI(scope: OpenAPI::SCOPE_IGNORE)]
class ShortValidationController extends Controller {
	public function __construct(IRequest $request, private IURLGenerator $urls, private ShortValidationUrlService $shortUrls) {
		parent::__construct(Application::APP_ID, $request);
	}

	/** Redirect only: the existing validation page enforces its access policy. */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function open(string $code): RedirectResponse|DataResponse {
		$uuid = $this->shortUrls->resolve($code);
		if ($uuid === null) return new DataResponse([], Http::STATUS_NOT_FOUND, ['Cache-Control' => 'no-store']);
		// Never accept a destination URL or look up signer credentials here.
		$response = new RedirectResponse($this->urls->linkToRouteAbsolute('libresign.page.validationFileWithShortUrl', ['uuid' => $uuid]), Http::STATUS_SEE_OTHER);
		$response->addHeader('Cache-Control', 'no-store');
		$response->addHeader('Referrer-Policy', 'no-referrer');
		$response->addHeader('X-Robots-Tag', 'noindex, nofollow');
		return $response;
	}
}
