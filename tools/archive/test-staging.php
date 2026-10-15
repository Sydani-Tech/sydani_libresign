<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Explicit opt-in real integration test. Creates isolated users/documents, never
// touches another user's files, and deliberately leaves signed QA evidence intact.
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--allow-staging-mutations') {
	fwrite(STDERR, "CLI staging test requires --allow-staging-mutations\n"); exit(1);
}
umask(0007);
// sudo preserves the caller's working directory (often /root), which www-data
// cannot traverse. PDF subprocesses must inherit an accessible directory.
if (!chdir('/var/www/html')) {
	fwrite(STDERR, "Cannot enter the staging Nextcloud directory.\n"); exit(1);
}
$_SERVER['SCRIPT_FILENAME'] = '/var/www/html/occ';
define('OC_CONSOLE', true);
require '/var/www/html/lib/base.php';
// Nextcloud's CLI exception handler can log and return without a failing exit
// status. Make an incomplete integration run unambiguously fail for the caller.
set_exception_handler(static function(\Throwable $error): void {
	fwrite(STDERR, 'FAIL: ' . get_class($error) . ': ' . $error->getMessage() . PHP_EOL);
	fwrite(STDERR, $error->getFile() . ':' . $error->getLine() . PHP_EOL);
	foreach (array_slice($error->getTrace(), 0, 8) as $frame) {
		fwrite(STDERR, ($frame['file'] ?? '') . ':' . ($frame['line'] ?? '') . ' '
			. ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '') . PHP_EOL);
	}
	exit(1);
});
$config = \OCP\Server::get(\OCP\IConfig::class);
if (!in_array('nextcloud.sydani.org', $config->getSystemValue('trusted_domains', []), true)) {
	throw new RuntimeException('This integration test is restricted to the agreed staging server.');
}
\OCP\Server::get(\OCP\App\IAppManager::class)->loadApp('libresign');
$app = new \OCA\Libresign\AppInfo\Application();
$c = $app->getContainer();
$checks = 0;
$check = static function(bool $condition, string $message) use (&$checks): void {
	if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
	$checks++; echo 'PASS: ' . $message . PHP_EOL;
};
$denied = static function(callable $operation, string $message) use ($check): void {
	try { $operation(); } catch (\OCP\Files\NotPermittedException|\InvalidArgumentException $e) { $check(true, $message); return; }
	$check(false, $message);
};
$users = \OCP\Server::get(\OCP\IUserManager::class);
$groups = \OCP\Server::get(\OCP\IGroupManager::class);
$session = \OCP\Server::get(\OCP\IUserSession::class);
$root = \OCP\Server::get(\OCP\Files\IRootFolder::class);
$suffix = gmdate('YmdHis') . '-' . bin2hex(random_bytes(2));
$qaUsers = [];
$qaIds = [];
register_shutdown_function(static function() use (&$qaIds, $users): void {
	foreach ($qaIds as $uid) {
		try { $users->get($uid)?->setEnabled(false); } catch (\Throwable) { }
	}
});
foreach (['sender', 'recipient'] as $role) {
	$uid = 'archive-qa-' . $role . '-' . $suffix;
	$qaIds[] = $uid;
	$user = $users->createUser($uid, bin2hex(random_bytes(24)));
	$user->setDisplayName('Archive QA ' . ucfirst($role) . ' ' . $suffix);
	$qaUsers[$role] = $user;
}
$adminUsers = $groups->get('admin')->getUsers();
$readOnlyAdmin = reset($adminUsers);
if (!$readOnlyAdmin) { throw new RuntimeException('An existing administrator is required for the read-only access check.'); }
$session->setUser($qaUsers['sender']);
$source = $root->getUserFolder($qaUsers['sender']->getUID())->newFile('Archive QA source ' . $suffix . '.pdf', file_get_contents(__DIR__ . '/../../tests/php/fixtures/pdfs/small_valid.pdf'));
$requests = $c->get(\OCA\Libresign\Service\RequestSignatureService::class);
$file = $requests->save([
	'file' => ['fileNode' => $source], 'name' => 'Archive QA sequential ' . $suffix,
	'userManager' => $qaUsers['sender'], 'status' => 1, 'signatureFlow' => 'ordered_numeric',
	'signers' => [
		['displayName' => $qaUsers['sender']->getDisplayName(), 'identifyMethods' => [['method' => 'account', 'value' => $qaUsers['sender']->getUID()]], 'notify' => 0, 'signingOrder' => 1],
		['displayName' => $qaUsers['recipient']->getDisplayName(), 'identifyMethods' => [['method' => 'account', 'value' => $qaUsers['recipient']->getUID()]], 'notify' => 0, 'signingOrder' => 2],
	],
]);
$fileMapper = $c->get(\OCA\Libresign\Db\FileMapper::class);
$signRequestMapper = $c->get(\OCA\Libresign\Db\SignRequestMapper::class);
$fieldMapper = $c->get(\OCA\Libresign\Db\FileElementMapper::class);
$archive = $c->get(\OCA\Libresign\Service\DocumentArchiveService::class);
$query = $c->get(\OCA\Libresign\Service\ArchiveQueryService::class);
$fileService = $c->get(\OCA\Libresign\Service\FileService::class);
$signers = $signRequestMapper->getByFileId($file->getId());
usort($signers, static fn ($a, $b) => $a->getSigningOrder() <=> $b->getSigningOrder());
$check(count($signers) === 2, 'two sequential participants created');
$check($file->getRequestedAt() !== null && $file->getCompletedAt() === null, 'Date Requested recorded, Date Completed empty before signing');
$dimensions = $file->getMetadata()['d'][0];
$input = [];
foreach ($signers as $index => $signer) {
	foreach (['text' => 'QA signer ' . ($index + 1), 'date' => gmdate('Y-m-d'), 'checkbox' => true] as $type => $value) {
		$field = new \OCA\Libresign\Db\FileElement();
		$field->setFileId($file->getId()); $field->setSignRequestId($signer->getId());
		$field->setType($type); $field->setPage(1); $field->setCreatedAt(new DateTime());
		$field->setMetadata(['label' => 'QA ' . $type . ' ' . ($index + 1), 'required' => true]);
		$offset = ['text' => 0, 'date' => 24, 'checkbox' => 48][$type];
		$y = max(8, (int)($dimensions['h'] * (0.65 - 0.3 * $index)) - $offset);
		$field->setLlx(10); $field->setLly($y);
		$field->setUrx($type === 'checkbox' ? 26 : min(150, (int)$dimensions['w'] - 10)); $field->setUry($y + 18);
		$fieldMapper->insert($field);
		$input[$index][] = ['documentElementId' => $field->getId(), 'value' => $value];
	}
}
$check(count($input[0]) === 3 && count($input[1]) === 3, 'required text, date and checkbox prepared for both participants');
$sign = static function(int $index) use ($c, $fileMapper, $signRequestMapper, $session, $qaUsers, $signers, $file, $input): void {
	$user = $index === 0 ? $qaUsers['sender'] : $qaUsers['recipient'];
	$session->setUser($user);
	// Clear request-scoped engine state because this CLI test emulates two
	// independent HTTP requests inside one PHP process.
	$c->get(\OCA\Libresign\Handler\SignEngine\Pkcs12Handler::class)->setCertificate('');
	$service = $c->get(\OCA\Libresign\Service\SignFileService::class);
	$service->prepareForSigning($fileMapper->getById($file->getId()), $signRequestMapper->getById($signers[$index]->getId()), $user, 'account:' . $user->getUID(), $user->getDisplayName(), true, null, 'clickToSign');
	$service->setVisibleElements($input[$index])->sign();
};
$sign(0);
$file = $fileMapper->getById($file->getId());
$check($file->getStatus() === 2, 'first signature produces protected partially signed document');
$check($archive->findByNodeId($file->getSignedNodeId()) !== null, 'signed master stored outside personal Files');
$check($file->getCompletedAt() === null, 'partial signature does not mark completion');
$denied(static fn () => $fileService->delete($file->getId()), 'LibreSign refuses deletion of partially signed record');
$denied(static fn () => $archive->resolve($file)->delete(), 'filesystem deletion hook protects signed master');
$firstHash = $file->getSignedHash();
$source->delete();
$file = $fileMapper->getById($file->getId());
$check($file->getNodeId() === null, 'original source is deletable');
$check(hash_equals($firstHash, hash('sha256', $archive->resolve($file)->getContent())), 'deleting source leaves signed PDF unchanged');
$check(count($signRequestMapper->getByFileId($file->getId())) === 2, 'source deletion preserves both participants');
$check(count($c->get(\OCA\Libresign\Service\SignFileService::class)->getNextcloudFiles($file)) === 1, 'signer PDF retrieval works without source');
$check($file->getDisplayNodeId() === $file->getSignedNodeId(), 'PDF-view API keeps a valid node identifier after source deletion');
$sign(1);
$file = $fileMapper->getById($file->getId());
$check($file->getStatus() === 3 && $file->getCompletedAt() !== null, 'second participant completes signing after source deletion');
$check(count(array_filter($signRequestMapper->getByFileId($file->getId()), static fn ($request) => $request->getSigned() !== null)) === 2, 'both signature records preserved');
$check(count($query->describe($file)['recipients'][1]['fields']) === 3, 'second signer text/date/checkbox retained');
$denied(static fn () => $fileService->delete($file->getId(), false), 'deleting metadata only cannot bypass protection');
$denied(static fn () => $requests->deleteRequestSignature(['file' => ['fileId' => $file->getId()]]), 'alternate deletion API cannot erase signed history');
$denied(static fn () => $requests->unassociateToUser($file->getId(), $signers[0]->getId()), 'signed participant cannot be removed');
$check($query->list(['name' => $suffix, 'recipient' => $qaUsers['recipient']->getUID()])['total'] === 1, 'admin list filters recipient');
$check($query->list(['name' => $suffix, 'sender' => $qaUsers['sender']->getUID(), 'dateBy' => 'requested', 'from' => time() - 600, 'to' => time() + 600])['total'] === 1, 'admin list filters sender and Date Requested');
$check($query->list(['name' => $suffix, 'dateBy' => 'completed', 'from' => time() - 600, 'to' => time() + 600])['total'] === 1, 'admin list filters Date Completed');
$controller = $c->get(\OCA\Libresign\Controller\ArchiveController::class);
$session->setUser($qaUsers['recipient']);
$check($controller->index()->getStatus() === 403 && $controller->history($file->getId())->getStatus() === 403 && $controller->file($file->getId())->getStatus() === 403, 'ordinary participant denied every organisational archive endpoint');
$session->setUser(null);
$check($controller->index()->getStatus() === 403 && $controller->history($file->getId())->getStatus() === 403 && $controller->file($file->getId())->getStatus() === 403, 'anonymous request denied every organisational archive endpoint');
$session->setUser($readOnlyAdmin);
$check($controller->index()->getStatus() === 200 && $controller->history($file->getId())->getStatus() === 200 && $controller->file($file->getId())->getStatus() === 200, 'unrelated admin can list, read history and retrieve signed PDF');
$temporary = $root->getUserFolder($qaUsers['sender']->getUID())->newFile('Archive QA unsigned ' . $suffix . '.pdf', file_get_contents(__DIR__ . '/../../tests/php/fixtures/pdfs/small_valid.pdf'));
$unsigned = $requests->saveFile(['file' => ['fileNode' => $temporary], 'name' => 'Archive QA unsigned ' . $suffix, 'userManager' => $qaUsers['sender'], 'status' => 0]);
$fileService->delete($unsigned->getId());
$check(true, 'unsigned drafts remain deletable');
$destination = tempnam(sys_get_temp_dir(), 'libresign-archive-qa-');
if ($destination === false || file_put_contents($destination, $archive->resolve($file)->getContent()) === false) {
	throw new RuntimeException('Could not save the synthetic signed QA document.');
}
chmod($destination, 0600);
foreach ($qaUsers as $user) { $user->setEnabled(false); }
$session->setUser(null);
echo json_encode(['checksPassed' => $checks, 'signedFileId' => $file->getId(), 'signedPdf' => $destination, 'qaUserIds' => array_map(static fn ($user) => $user->getUID(), $qaUsers)], JSON_PRETTY_PRINT) . PHP_EOL;
