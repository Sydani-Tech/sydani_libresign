<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2020-2024 LibreCode coop and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Libresign\Handler\SignEngine\JSignPdf;

use Imagick;
use ImagickPixel;
use OCA\Libresign\AppInfo\Application;
use OCA\Libresign\Exception\LibresignException;
use OCA\Libresign\Exception\TemporaryTimestampException;
use OCA\Libresign\Service\TimestampRetryService;
use OCA\Libresign\Handler\CertificateEngine\CertificateEngineFactory;
use OCA\Libresign\Handler\SignEngine\Pkcs12Handler;
use OCA\Libresign\Helper\JavaHelper;
use OCA\Libresign\Service\DocMdp\ConfigService as DocMdpConfigService;
use OCA\Libresign\Service\Policy\PolicyService;
use OCA\Libresign\Service\Policy\Provider\SignatureText\SignatureTextPolicyValue;
use OCA\Libresign\Service\Policy\Provider\Tsa\TsaPolicy;
use OCA\Libresign\Service\Policy\Provider\Tsa\TsaPolicyValue;
use OCA\Libresign\Service\SignatureBackgroundService;
use OCA\Libresign\Service\SignatureTextService;
use OCA\Libresign\Service\SignerElementsService;
use OCA\Libresign\Service\TemporarySignatureImageService;
use OCA\Libresign\Vendor\Jeidison\JSignPDF\JSignPDF;
use OCA\Libresign\Vendor\Jeidison\JSignPDF\Sign\JSignParam;
use OCP\Files\File;
use OCP\IAppConfig;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;

class JSignPdfHandler extends Pkcs12Handler {
	private const float MIN_PDF_VERSION = 1.2;
	private const string TARGET_OLD_PDF_VERSION = '1.3';
	private const string TARGET_PDF_VERSION_SHA256 = '1.6';
	private const int PAGE_FIRST = 1;
	private const int SCALE_FACTOR_MIN = 5;

	/** @var JSignPDF */
	private $jSignPdf;
	/** @var JSignParam */
	private $jSignParam;
	private array $parsedSignatureText = [];
	private bool $preparedSignatureLayout = false;
	private array $preparedSignatureFields = [];

	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private SignatureTextService $signatureTextService,
		private ITempManager $tempManager,
		private SignatureBackgroundService $signatureBackgroundService,
		private PolicyService $policyService,
		protected CertificateEngineFactory $certificateEngineFactory,
		protected JavaHelper $javaHelper,
		private DocMdpConfigService $docMdpConfigService,
		private HashAlgorithmResolver $hashAlgorithmResolver,
		private TemporarySignatureImageService $temporarySignatureImageService,
		private \OCA\Libresign\Service\PdfSigningFieldsService $pdfSigningFieldsService,
		private TimestampRetryService $timestampRetryService,
	) {
	}

	public function setJSignPdf(JSignPDF $jSignPdf): void {
		$this->jSignPdf = $jSignPdf;
	}

	public function getJSignPdf(): JSignPDF {
		if (!$this->jSignPdf) {
			// @codeCoverageIgnoreStart
			$this->setJSignPdf(new JSignPDF());
			// @codeCoverageIgnoreEnd
		}
		return $this->jSignPdf;
	}

	/**
	 * @psalm-suppress MixedReturnStatement
	 */
	public function getJSignParam(): JSignParam {
		if (!$this->jSignParam) {
			$javaPath = $this->javaHelper->getJavaPath();
			$tempPath = $this->appConfig->getValueString(Application::APP_ID, 'jsignpdf_temp_path', sys_get_temp_dir() . DIRECTORY_SEPARATOR);
			if (!is_writable($tempPath)) {
				throw new \Exception('The path ' . $tempPath . ' is not writtable. Fix this or change the LibreSign app setting jsignpdf_temp_path to a writtable path');
			}
			$jSignPdfPath = \OCP\Server::get(\OCA\Libresign\Service\PadesLtService::class)->engineDirectory(
				$this->appConfig->getValueString(Application::APP_ID, 'jsignpdf_path'),
			);
			if (!is_dir($jSignPdfPath)) {
				throw new \Exception('Invalid JSignPdf path. Run occ libresign:install --jsignpdf');
			}
			$home = $this->getHome();
			$this->jSignParam = (new JSignParam())
				->setTempPath($tempPath)
				->setIsUseJavaInstalled(empty($javaPath))
				->setJavaDownloadUrl('')
				->setJSignPdfDownloadUrl('')
				->setJSignPdfPath($jSignPdfPath)
				->setJavaOptions(['-Duser.home=' . $home])
				->setEnvironmentVariables(['JSIGNPDF_HOME' => $home]);
			if (!empty($javaPath)) {
				if (!file_exists($javaPath)) {
					throw new \Exception('Invalid Java binary. Run occ libresign:install --java');
				}
				$this->jSignParam->setJavaPath($javaPath);
			}
		}
		return $this->jSignParam;
	}

	/**
	 * It's a workaround to create the folder structure that JSignPdf needs. Without
	 * this, the JSignPdf will return the follow message to all commands:
	 * > FINE Config file conf/conf.properties doesn't exists.
	 * > FINE Default property file /root/.JSignPdf doesn't exists.
	 */
	private function getHome(): string {
		$configuredHome = $this->getConfiguredHome();
		if ($configuredHome !== null) {
			return $configuredHome;
		}

		$tempFolder = $this->createJSignPdfTempFolder();
		$this->initializeJSignPdfConfigurationFiles($tempFolder);
		return $tempFolder;
	}

	private function getConfiguredHome(): ?string {
		$jSignPdfHome = $this->appConfig->getValueString(Application::APP_ID, 'jsignpdf_home', '');
		if ($jSignPdfHome && is_dir($jSignPdfHome)) {
			return $jSignPdfHome;
		}
		return null;
	}

	private function createJSignPdfTempFolder(): string {
		$jsignpdfTempFolder = $this->tempManager->getTemporaryFolder('jsignpdf');
		if (!$jsignpdfTempFolder) {
			throw new \Exception('Temporary file not accessible');
		}
		mkdir(
			directory: $jsignpdfTempFolder . '/conf',
			recursive: true
		);
		return $jsignpdfTempFolder;
	}

	private function initializeJSignPdfConfigurationFiles(string $folder): void {
		$this->createEmptyFile($folder . '/conf/conf.properties');
		$this->createEmptyFile($folder . '/.JSignPdf');
	}

	private function createEmptyFile(string $path): void {
		$file = fopen($path, 'w');
		fclose($file);
	}

	private function extractPdfVersion(string $content): ?float {
		if (!preg_match('/^%PDF-(?<version>\d+(\.\d+)?)/', $content, $match)) {
			return null;
		}
		return (float)$match['version'];
	}

	/**
	 * Normalizes very old PDFs (1.0/1.1) to 1.3.
	 * Rationale: JSignPDF enum PdfVersion only defines 1.2+; for 1.0/1.1,
	 * PdfVersion.fromCharVersion(...) returns null and SignerLogic.signFile() NPEs.
	 * See JSignPDF 2.3.0 sources: types/PdfVersion.java and SignerLogic.signFile().
	 */
	private function normalizePdfVersion(string $content): string {
		$version = $this->extractPdfVersion($content);
		if ($version === null) {
			return $content;
		}

		// Convert very old PDFs (< 1.2) to 1.3 to avoid JSignPDF NullPointerException
		if ($this->isVeryOldPdfVersion($version)) {
			return $this->replacePdfVersion($content, self::TARGET_OLD_PDF_VERSION);
		}

		// Convert PDFs < 1.6 to 1.6 if using SHA-256 (the default hash algorithm)
		// This prevents "The chosen hash algorithm (SHA-256) requires a newer PDF version" error
		if ($this->hashAlgorithmResolver->requiresPdfVersionUpgradeForSha256($version)) {
			return $this->replacePdfVersion($content, self::TARGET_PDF_VERSION_SHA256);
		}

		return $content;
	}

	private function isVeryOldPdfVersion(float $version): bool {
		return $version > 0 && $version < self::MIN_PDF_VERSION;
	}

	private function replacePdfVersion(string $content, string $newVersion): string {
		return (string)preg_replace('/^%PDF-\d+(\.\d+)?/', '%PDF-' . $newVersion, $content, 1);
	}

	private function getCertificationLevel(): ?string {
		if (!$this->docMdpConfigService->isEnabled()) {
			return null;
		}

		return $this->docMdpConfigService->getLevel()->name;
	}

	#[\Override]
	public function sign(): File {
		$this->beforeSign();

		$signedContent = $this->getSignedContent();
		$this->getInputFile()->putContent($signedContent);
		return $this->getInputFile();
	}

	#[\Override]
	public function getSignedContent(): string {
		// The handler is reused by sequential/worker flows. Never append a new
		// signer's options to the previous signature's coordinates or certificate.
		$this->jSignParam = null;
		$this->parsedSignatureText = [];
		$this->preparedSignatureLayout = false;
		$this->preparedSignatureFields = [];
		$inputPdf = $this->getInputFile()->getContent();
		$lt = \OCP\Server::get(\OCA\Libresign\Service\PadesLtService::class);
		$verifier = $lt->enabled() ? \OCP\Server::get(\OCA\Libresign\Service\PadesVerificationService::class) : null;
		$existing = [];
		$mixed = false;
		$checkedField = null;
		$visibleElements = $this->getVisibleElements();
		$fields = $this->getSignatureParams()['SigningFields'] ?? [];
		$signatureFields = $lt->enabled() ? ($this->getSignatureParams()['SignatureFields'] ?? []) : [];
		if ($verifier && $this->hasExistingSignatures($inputPdf)) {
			$existing = \OCA\Libresign\Service\PdfCountersigningPolicy::existing($verifier->verify($inputPdf));
			$mixed = count(array_filter($existing, [\OCA\Libresign\Service\PdfCountersigningPolicy::class, 'isLt'])) !== count($existing);
			$hasFieldLocks = (bool)array_filter($existing, static fn (array $signature): bool => !empty($signature['fieldLocked']));
			$nativeLayout = (bool)array_filter($existing, static fn (array $signature): bool => (bool)array_filter($signature['signatureFields'] ?? [], static fn (string $name): bool => str_starts_with($name, 'libresign_signature_')));
			if ($mixed || $hasFieldLocks || (!$fields && !$nativeLayout)) {
				$available = array_values(array_filter(
					$verifier->availableSignatureFields($inputPdf),
					static fn (array $field): bool => \OCA\Libresign\Service\PdfCountersigningPolicy::permitsExistingField($existing, $field['name']),
				));
				$checkedField = $this->existingSignatureField($available, $visibleElements);
				if ($mixed || $hasFieldLocks || $checkedField !== null) {
					\OCA\Libresign\Service\PdfCountersigningPolicy::preflight($existing, $fields, $checkedField);
					$mixed = true;
				}
			}
		}
		$lt->refreshLocalRevocation($this->getCertificate(), $this->getPassword());
		$normalizedPdf = $this->hasExistingSignatures($inputPdf) ? $inputPdf : $this->normalizePdfVersion($inputPdf);
		$layout = $this->pdfSigningFieldsService->prepare($normalizedPdf, $fields, $mixed ? [] : $signatureFields);
		$normalizedPdf = $layout['pdf'];
		$this->preparedSignatureLayout = $layout['prepared'] && $signatureFields !== [];
		$this->preparedSignatureFields = $layout['signatureFields'];
		$finish = function (string $signed) use ($mixed, $verifier, $inputPdf, $existing, $checkedField): string {
			if ($mixed) return $verifier->completeCountersignature($inputPdf, $signed, $existing, $this->getCertificate(), $this->getPassword(), $checkedField);
			if ($this->preparedSignatureLayout && $verifier) return $verifier->completeManagedSignatures($inputPdf, $signed, $existing, $this->getCertificate(), $this->getPassword());
			$verifier?->assertLt($signed);
			return $signed;
		};
		$hashAlgorithm = $lt->enabled()
			? $this->hashAlgorithmResolver->forLongTermSignature()
			: $this->hashAlgorithmResolver->forSignature($this->extractPdfVersion($normalizedPdf));
		$param = $this->getJSignParam();
		$param->setCertificate($this->getCertificate())
			->setPdf($normalizedPdf)
			->setPassword($this->getPassword());

		$parameters = [];
		$certificationLevel = $this->getCertificationLevel();
		if ($certificationLevel !== null && !$visibleElements && !$this->hasExistingSignatures($normalizedPdf)) {
			$parameters['-cl'] = $certificationLevel;
		}
		if ($this->preparedSignatureLayout && !$visibleElements) {
			$slotId = $this->getSignatureParams()['InvisibleSignatureField'] ?? '';
			$parameters['-sf'] = $this->preparedSignatureField((string)$slotId);
		}
		$parameters += $this->getTsaParameters();
		$parameters += $lt->parameters($mixed);
		if ($checkedField !== null) {
			$parameters['-sf'] = $checkedField;
			if (!$visibleElements) $parameters['-V'] = null;
		}
		$param->addJSignParameters($this->toJSignParameters($parameters));
		$tsaPassword = $this->getTsaPassword();
		if ($tsaPassword !== '') $param->setTsaPassword($tsaPassword);

		$signed = $this->signUsingVisibleElements($normalizedPdf, $hashAlgorithm);
		if ($signed) return $finish($signed);

		$param->addJSignParameters(['--hash-algorithm' => $hashAlgorithm]);
		$jSignPdf = $this->getJSignPdf();
		$jSignPdf->setParam($param);
		return $finish($this->signWrapper($jSignPdf));
	}

	/**
	 * Reuse a box when the sender's marker is inside it. Without a marker,
	 * choose an existing field only when the document offers exactly one.
	 */
	private function existingSignatureField(array $available, array $visibleElements): ?string {
		if (!$visibleElements) {
			if (count($available) > 1) {
				throw new LibresignException('This PDF has several available signature fields. Place a signature marker inside the intended field.');
			}
			return $available[0]['name'] ?? null;
		}
		$matches = [];
		foreach ($visibleElements as $element) {
			$position = $element->getFileElement();
			$x = ($position->getLlx() + $position->getUrx()) / 2;
			$y = ($position->getLly() + $position->getUry()) / 2;
			foreach ($available as $field) {
				[$left, $bottom, $right, $top] = $field['rectangle'];
				if ((int)$position->getPage() === $field['page'] && $x >= $left && $x <= $right && $y >= $bottom && $y <= $top) {
					$matches[] = $field['name'];
				}
			}
		}
		if ($matches && (count($matches) !== 1 || count($visibleElements) !== 1)) {
			throw new LibresignException('To reuse a PDF signature field, place one signature marker inside the intended field.');
		}
		return $matches[0] ?? null;
	}

	private function signUsingVisibleElements(string $normalizedPdf, string $hashAlgorithm): string {
		$visibleElements = $this->getVisibleElements();
		if ($visibleElements) {
			$signed = '';
			$jSignPdf = $this->getJSignPdf();

			$renderMode = $this->signatureTextService->getRenderMode();

			$params = [
				'--l2-text' => $this->getSignatureText(),
				'-V' => null,
			];

			// When l2-text is empty, add hash-algorithm at the beginning
			if ($params['--l2-text'] === '') {
				$params = [
					'--hash-algorithm' => $hashAlgorithm,
					'--l2-text' => $params['--l2-text'],
					'-V' => null,
				];
			}

			$fontSize = $this->parseSignatureText()['templateFontSize'];
			if ($fontSize === SignatureTextPolicyValue::DEFAULT_SIGNATURE_FONT_SIZE || !$fontSize || $params['--l2-text'] === '') {
				$fontSize = 0;
			}

			$backgroundType = $this->signatureBackgroundService->getSignatureBackgroundType();
			if ($backgroundType !== 'deleted') {
				$backgroundPath = $this->signatureBackgroundService->getImagePath();
			} else {
				$backgroundPath = '';
			}

			$certificationLevel = $this->getCertificationLevel();
			$applyCertification = $certificationLevel !== null && !$this->hasExistingSignatures($normalizedPdf);
			$elementIndex = 0;

			$originalParam = $this->getJSignParam();

			foreach ($visibleElements as $element) {
				unset($params['--img-path'], $params['--bg-path'], $params['--render-mode'], $params['--font-size'], $params['--bg-scale']);
				$elementIndex++;
				if ($this->preparedSignatureLayout) $params['-sf'] = $this->preparedSignatureField((string)$element->getFileElement()->getId());
				$params['-pg'] = $element->getFileElement()->getPage();
				if ($params['-pg'] <= self::PAGE_FIRST) {
					unset($params['-pg']);
				}
				$params['-llx'] = $element->getFileElement()->getLlx();
				$params['-lly'] = $element->getFileElement()->getLly();
				$params['-urx'] = $element->getFileElement()->getUrx();
				$params['-ury'] = $element->getFileElement()->getUry();

				$scaleFactor = $this->getScaleFactor($params['-urx'] - $params['-llx']);
				if ($fontSize) {
					$params['--font-size'] = $fontSize * $scaleFactor;
				}

				$backgroundPathForElement = $backgroundPath
					? $this->prepareBackgroundForPdf($backgroundPath, $this->normalizeScaleFactor($scaleFactor))
					: '';

				$signatureImagePath = $element->getTempFile();
				$this->temporarySignatureImageService->requireAvailable($signatureImagePath);
				if ($renderMode === SignerElementsService::RENDER_MODE_GRAPHIC_ONLY) {
					$signatureImagePath = $this->fitSignatureToField($signatureImagePath, $params['-urx'] - $params['-llx'], $params['-ury'] - $params['-lly']);
					$params['--bg-scale'] = -1;
				}
				if ($backgroundType === 'deleted') {
					if ($renderMode === SignerElementsService::RENDER_MODE_SIGNAME_AND_DESCRIPTION) {
						$params['--render-mode'] = SignerElementsService::RENDER_MODE_GRAPHIC_AND_DESCRIPTION;
						$params['--img-path'] = $this->createTextImage(
							width: ($params['-urx'] - $params['-llx']),
							height: ($params['-ury'] - $params['-lly']),
							fontSize: $this->signatureTextService->getSignatureFontSize() * $scaleFactor,
							scaleFactor: $this->normalizeScaleFactor($scaleFactor),
						);
					} elseif ($signatureImagePath) {
						$params['--bg-path'] = $signatureImagePath;
					}
				} elseif ($params['--l2-text'] === '') {
					if ($backgroundPathForElement && $signatureImagePath) {
						$params['--bg-path'] = $this->mergeBackgroundWithSignature(
							$backgroundPathForElement,
							$signatureImagePath,
							$this->normalizeScaleFactor($scaleFactor),
						);
					} elseif ($backgroundPathForElement) {
						$params['--bg-path'] = $backgroundPathForElement;
					} elseif ($signatureImagePath) {
						$params['--bg-path'] = $signatureImagePath;
					}
				} else {
					if ($renderMode === SignerElementsService::RENDER_MODE_GRAPHIC_AND_DESCRIPTION) {
						$params['--render-mode'] = SignerElementsService::RENDER_MODE_GRAPHIC_AND_DESCRIPTION;
						$params['--bg-path'] = $backgroundPathForElement;
						if ($signatureImagePath) {
							$params['--img-path'] = $signatureImagePath;
						}
					} elseif ($renderMode === SignerElementsService::RENDER_MODE_SIGNAME_AND_DESCRIPTION) {
						$params['--render-mode'] = SignerElementsService::RENDER_MODE_GRAPHIC_AND_DESCRIPTION;
						$params['--bg-path'] = $backgroundPathForElement;
						$params['--img-path'] = $this->createTextImage(
							width: (int)(($params['-urx'] - $params['-llx']) / 2),
							height: $params['-ury'] - $params['-lly'],
							fontSize: $this->signatureTextService->getSignatureFontSize() * $scaleFactor,
							scaleFactor: $this->normalizeScaleFactor($scaleFactor),
						);

					} else {
						$params['--bg-path'] = $backgroundPathForElement;
					}
				}

				// Only add hash-algorithm at the end if l2-text is not empty
				if ($params['--l2-text'] !== '') {
					$params['--hash-algorithm'] = $hashAlgorithm;
				}

				$param = clone $originalParam;
				if ($applyCertification && $elementIndex === 1) {
					$param->addJSignParameters(['-cl' => $certificationLevel]);
				}
				$param->addJSignParameters($this->toJSignParameters($params));
				$param->setPdf($normalizedPdf);
				$jSignPdf->setParam($param);
				$signed = $this->signWrapper($jSignPdf);
				$normalizedPdf = $signed;
			}
			return $signed;
		}
		return '';
	}

	private function preparedSignatureField(string $id): string {
		if (!isset($this->preparedSignatureFields[$id])) throw new LibresignException('The assigned PDF signature slot is unavailable. No signed document was saved.');
		return $this->preparedSignatureFields[$id];
	}

	private function hasExistingSignatures(string $pdfContent): bool {
		return (bool)preg_match('/\/ByteRange\s*\[|\/Type\s*\/Sig\b|\/DocMDP\b|\/Perms\b/', $pdfContent);
	}

	private function fitSignatureToField(string $signaturePath, float $fieldWidth, float $fieldHeight): string {
		if (!extension_loaded('imagick') || $fieldWidth <= 0 || $fieldHeight <= 0) {
			return $signaturePath;
		}

		$signature = new Imagick($signaturePath);
		$signature->setImageFormat('png32');
		$signature->trimImage(0.01);
		$signature->setImagePage(0, 0, 0, 0);

		$canvasWidth = max(1, (int)round($fieldWidth * 4));
		$canvasHeight = max(1, (int)round($fieldHeight * 4));
		$fit = min(
			$canvasWidth * 0.94 / $signature->getImageWidth(),
			$canvasHeight * 0.94 / $signature->getImageHeight(),
		);
		$signature->resizeImage(
			max(1, (int)round($signature->getImageWidth() * $fit)),
			max(1, (int)round($signature->getImageHeight() * $fit)),
			Imagick::FILTER_LANCZOS,
			1,
		);

		$canvas = new Imagick();
		$canvas->newImage($canvasWidth, $canvasHeight, new ImagickPixel('transparent'));
		$canvas->setImageFormat('png32');
		$canvas->compositeImage(
			$signature,
			Imagick::COMPOSITE_OVER,
			(int)round(($canvasWidth - $signature->getImageWidth()) / 2),
			(int)round(($canvasHeight - $signature->getImageHeight()) / 2),
		);
		$output = $this->tempManager->getTemporaryFile('_fitted_signature.png');
		if (!$output) {
			throw new \RuntimeException('Temporary signature file not accessible');
		}
		$canvas->writeImage($output);
		$canvas->clear();
		$signature->clear();
		return $output;
	}


	private function getScaleFactor(float $width): float {
		$systemWidth = $this->signatureTextService->getFullSignatureWidth();
		if (!$systemWidth) {
			return 1;
		}
		return $width / $systemWidth;
	}

	private function normalizeScaleFactor(float $scaleFactor): float {
		return max($scaleFactor, self::SCALE_FACTOR_MIN);
	}

	#[\Override]
	public function readCertificate(): array {
		$result = $this->certificateEngineFactory
			->getEngine()
			->readCertificate(
				$this->getCertificate(),
				$this->getPassword()
			);

		if (!is_array($result)) {
			throw new \RuntimeException('Failed to read certificate data');
		}

		return $result;
	}

	private function createTextImage(int $width, int $height, float $fontSize, float $scaleFactor): string {
		$params = $this->getSignatureParams();
		if (!empty($params['SignerCommonName'])) {
			$commonName = $params['SignerCommonName'];
		} else {
			$certificateData = $this->readCertificate();
			$commonName = $certificateData['subject']['CN'] ?? throw new \RuntimeException('Certificate must have a Common Name (CN) in subject field');
		}
		$content = $this->signatureTextService->signerNameImage(
			width: $width,
			height: $height,
			text: $commonName,
			fontSize: $fontSize,
			scale: $scaleFactor,
		);

		$tmpPath = $this->tempManager->getTemporaryFile('_text_image.png');
		if (!$tmpPath) {
			throw new \Exception('Temporary file not accessible');
		}
		file_put_contents($tmpPath, $content);
		return $tmpPath;
	}

	private function mergeBackgroundWithSignature(string $backgroundPath, string $signaturePath, float $scaleFactor): string {
		if (!extension_loaded('imagick')) {
			throw new \Exception('Extension imagick is not loaded.');
		}
		$baseWidth = $this->signatureTextService->getFullSignatureWidth();
		$baseHeight = $this->signatureTextService->getFullSignatureHeight();

		$canvasWidth = round($baseWidth * $scaleFactor);
		$canvasHeight = round($baseHeight * $scaleFactor);

		$background = new Imagick($backgroundPath);
		$signature = new Imagick($signaturePath);

		$background->setImageFormat('png');
		$signature->setImageFormat('png');

		$background->setImageAlphaChannel(Imagick::ALPHACHANNEL_ACTIVATE);
		$signature->setImageAlphaChannel(Imagick::ALPHACHANNEL_ACTIVATE);

		$background->resizeImage(
			(int)$canvasWidth,
			(int)$canvasHeight,
			Imagick::FILTER_LANCZOS,
			1,
			true
		);

		$signature->resizeImage(
			(int)$canvasWidth,
			(int)$canvasHeight,
			Imagick::FILTER_LANCZOS,
			1,
			true
		);

		$canvas = new Imagick();
		$canvas->newImage((int)$canvasWidth, (int)$canvasHeight, new ImagickPixel('transparent'));
		$canvas->setImageFormat('png32');
		$canvas->setImageAlphaChannel(Imagick::ALPHACHANNEL_ACTIVATE);

		$bgX = (int)(($canvasWidth - $background->getImageWidth()) / 2);
		$bgY = (int)(($canvasHeight - $background->getImageHeight()) / 2);
		$canvas->compositeImage($background, Imagick::COMPOSITE_OVER, $bgX, $bgY);

		$sigX = (int)(($canvasWidth - $signature->getImageWidth()) / 2);
		$sigY = (int)(($canvasHeight - $signature->getImageHeight()) / 2);
		$canvas->compositeImage($signature, Imagick::COMPOSITE_OVER, $sigX, $sigY);

		$tmpPath = $this->tempManager->getTemporaryFile('_merged.png');
		if (!$tmpPath) {
			throw new \Exception('Temporary file not accessible');
		}
		$canvas->writeImage($tmpPath);

		$canvas->clear();
		$background->clear();
		$signature->clear();

		return $tmpPath;
	}

	private function prepareBackgroundForPdf(string $backgroundPath, float $scaleFactor): string {
		if (!extension_loaded('imagick')) {
			throw new \Exception('Extension imagick is not loaded.');
		}
		$baseWidth = $this->signatureTextService->getFullSignatureWidth();
		$baseHeight = $this->signatureTextService->getFullSignatureHeight();

		$canvasWidth = (int)round($baseWidth * $scaleFactor);
		$canvasHeight = (int)round($baseHeight * $scaleFactor);

		$background = new Imagick($backgroundPath);
		$background->setImageFormat('png');
		$background->setImageAlphaChannel(Imagick::ALPHACHANNEL_ACTIVATE);
		$background->resizeImage(
			$canvasWidth,
			$canvasHeight,
			Imagick::FILTER_LANCZOS,
			1,
			true
		);

		$canvas = new Imagick();
		$canvas->newImage($canvasWidth, $canvasHeight, new ImagickPixel('transparent'));
		$canvas->setImageFormat('png32');
		$canvas->setImageAlphaChannel(Imagick::ALPHACHANNEL_ACTIVATE);

		$bgX = (int)(($canvasWidth - $background->getImageWidth()) / 2);
		$bgY = (int)(($canvasHeight - $background->getImageHeight()) / 2);
		$canvas->compositeImage($background, Imagick::COMPOSITE_OVER, $bgX, $bgY);

		$tmpPath = $this->tempManager->getTemporaryFile('_background.png');
		if (!$tmpPath) {
			throw new \Exception('Temporary file not accessible');
		}
		$canvas->writeImage($tmpPath);

		$canvas->clear();
		$background->clear();

		return $tmpPath;
	}

	private function parseSignatureText(): array {
		if (!$this->parsedSignatureText) {
			$params = $this->getSignatureParams();
			$template = $this->signatureTextService->getTemplate();
			$params['ServerSignatureDate'] = $this->shouldUseJSignTimestampPlaceholder($template)
				? '${timestamp}'
				: (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
					->format(\DateTimeInterface::ATOM);
			$this->parsedSignatureText = $this->signatureTextService->parse(context: $params);
		}
		return $this->parsedSignatureText;
	}

	private function shouldUseJSignTimestampPlaceholder(string $template): bool {
		if (!preg_match_all('/{{\s*(.*?)\s*}}/s', $template, $matches)) {
			return true;
		}

		$hasPlainServerSignatureDate = false;
		foreach ($matches[1] as $expression) {
			if (!str_contains($expression, 'ServerSignatureDate')) {
				continue;
			}
			if (trim($expression) === 'ServerSignatureDate') {
				$hasPlainServerSignatureDate = true;
				continue;
			}
			// Any transformation (for example Twig date filter) requires a real date value.
			return false;
		}

		return $hasPlainServerSignatureDate;
	}

	public function getSignatureText(): string {
		$renderMode = $this->signatureTextService->getRenderMode();
		if ($renderMode !== SignerElementsService::RENDER_MODE_GRAPHIC_ONLY) {
			return $this->parseSignatureText()['parsed'];
		}
		return '';
	}

	/**
	 * Options with a null value are flags. Every other value reaches the
	 * wrapper as a string; the wrapper escapes it for the shell.
	 *
	 * @param array<string, string|int|float|list<string>|null> $params
	 * @return array<array-key, string>
	 */
	private function toJSignParameters(array $params): array {
		$parameters = [];
		foreach ($params as $option => $value) {
			if (is_array($value)) {
				foreach ($value as $argument) {
					$parameters[] = $option;
					$parameters[] = (string)$argument;
				}
				continue;
			}
			if ($value === null) {
				$parameters[] = $option;
				continue;
			}
			$parameters[$option] = (string)$value;
		}
		return $parameters;
	}

	/**
	 * @return array<string, string>
	 */
	private function getTsaParameters(): array {
		$tsaSettings = $this->getTsaSettings();
		if (empty($tsaSettings['url'])) {
			return [];
		}

		$params = [
			'--tsa-server-url' => $tsaSettings['url'],
			'--tsa-hash-algorithm' => $this->hashAlgorithmResolver->forTsa(),
		];
		if ($tsaSettings['policy_oid']) {
			$params['--tsa-policy-oid'] = $tsaSettings['policy_oid'];
		}
		if ($this->getTsaPassword() !== '') {
			$params['--tsa-authentication'] = 'PASSWORD';
			$params['--tsa-user'] = $tsaSettings['username'];
		}

		return $params;
	}

	/**
	 * The TSA password never goes to the command line: the wrapper writes it
	 * to the stdin of JSignPdf.
	 */
	private function getTsaPassword(): string {
		$tsaSettings = $this->getTsaSettings();
		if (empty($tsaSettings['url']) || $tsaSettings['auth_type'] !== 'basic' || empty($tsaSettings['username'])) {
			return '';
		}
		return $this->appConfig->getValueString(Application::APP_ID, TsaPolicy::PASSWORD_APP_CONFIG_KEY, '');
	}

	/**
	 * @return array{url: string, policy_oid: string, auth_type: string, username: string, hash_algorithm: string}
	 */
	private function getTsaSettings(): array {
		$resolved = $this->policyService->resolve(TsaPolicy::KEY)->getEffectiveValue();
		$settings = TsaPolicyValue::decode($resolved);
		return $settings;
	}

	private function signWrapper(JSignPDF $jSignPDF): string {
		try {
			return $this->timestampRetryService->run(
				fn (): string => $jSignPDF->sign(),
				$this->getTsaSettings()['url'],
			);
		} catch (\Throwable $th) {
			$errorMessage = $th->getMessage();

			// Log before the checks below: they throw, and the original message
			// from JSignPdf is the only place where the real cause is described.
			$this->logger->error('JSignPdf signing failed.', ['exception' => $th]);

			if ($th instanceof TemporaryTimestampException) {
				throw $th;
			}

			$this->checkTsaError($errorMessage);
			$this->checkHashAlgorithmError($errorMessage);

			throw new \Exception($errorMessage);
		}
	}

	private function checkTsaError(string $errorMessage): void {
		$tsaErrors = ['TSAClientBouncyCastle', 'UnknownHostException', 'Invalid TSA'];
		$isTsaError = false;
		foreach ($tsaErrors as $error) {
			if (str_contains($errorMessage, $error)) {
				$isTsaError = true;
				break;
			}
		}

		if ($isTsaError) {
			$rejectionMessage = $this->getTsaRejectionMessage($errorMessage);
			if ($rejectionMessage !== null) {
				throw new LibresignException($rejectionMessage);
			}

			if (str_contains($errorMessage, 'Invalid TSA') && preg_match("/Invalid TSA '([^']+)'/", $errorMessage, $matches)) {
				$friendlyMessage = 'Timestamp Authority (TSA) service is unavailable. Check DNS/network/firewall connectivity from this server: ' . $matches[1];
			} else {
				$friendlyMessage = 'Timestamp Authority (TSA) service error.' . "\n"
					. 'Check TSA endpoint and DNS/network/firewall connectivity from this server.';
			}
			throw new LibresignException($friendlyMessage);
		}
	}

	/**
	 * An HTTP status in the JSignPdf output means that the TSA was reached and
	 * answered: the request was rejected. Reporting DNS/network/firewall in this
	 * case sends whoever is debugging to the wrong place.
	 */
	private function getTsaRejectionMessage(string $errorMessage): ?string {
		if (!preg_match('/HTTP response code: (?<status>\d{3})(?: for URL: (?<url>[^\s"\',]+))?/', $errorMessage, $matches)) {
			return null;
		}

		$endpoint = isset($matches['url']) && $matches['url'] !== '' ? ': ' . $matches['url'] : '.';
		return 'Timestamp Authority (TSA) rejected the request with HTTP status ' . $matches['status'] . $endpoint . "\n"
			. 'The server was reached, so this is not a DNS/network/firewall problem.' . "\n"
			. 'Check what the authority expects: hash algorithm, policy OID and authentication.';
	}

	private function checkHashAlgorithmError(string $errorMessage): void {
		$rows = str_getcsv($errorMessage);
		$hashAlgorithm = array_filter($rows, fn ($r) => str_contains((string)$r, 'The chosen hash algorithm'));

		if (!empty($hashAlgorithm)) {
			$hashAlgorithm = current($hashAlgorithm);
			$hashAlgorithm = trim((string)$hashAlgorithm, 'INFO ');
			$hashAlgorithm = str_replace('\"', '"', $hashAlgorithm);
			$hashAlgorithm = preg_replace('/\.( )/', ".\n", $hashAlgorithm);
			throw new LibresignException($hashAlgorithm);
		}
	}
}
