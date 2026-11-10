<?php

declare(strict_types=1);

/** SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */

namespace OCA\Libresign\Service;

use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;

/** Private snapshots, not a shared folder or a user's editable Files tree. */
class CampaignStorage {
	public function __construct(private IAppDataFactory $factory) {
	}

	private function folder(int $id, bool $create = false): Folder {
		if ($id < 1) throw new \InvalidArgumentException('Invalid campaign identifier.');
		$simple = $this->factory->get('libresign')->getFolder('/');
		// Same SimpleFS-to-Node bridge used by FolderService/DocumentArchiveService.
		$folder = (new \ReflectionClass($simple))->getProperty('folder')->getValue($simple);
		foreach (['campaigns', (string)$id] as $name) {
			try { $next = $folder->get($name); } catch (NotFoundException $e) {
				if (!$create) throw $e;
				$next = $folder->newFolder($name);
			}
			if (!$next instanceof Folder) throw new \RuntimeException('Invalid campaign storage folder.');
			$folder = $next;
		}
		return $folder;
	}

	public function write(int $id, string $content, string $prefix = 'source'): File {
		if (!preg_match('/^(source|recipient-[1-9][0-9]*)$/', $prefix)) throw new \InvalidArgumentException('Invalid campaign source name.');
		return $this->folder($id, true)->newFile($prefix . '-' . bin2hex(random_bytes(12)) . '.pdf', $content);
	}

	public function source(array $campaign): File {
		$name = $campaign['source_file'] ?? '';
		if (!is_string($name) || !preg_match('/^source-[a-f0-9]{24}\.pdf$/', $name)) throw new NotFoundException('Campaign PDF is not available.');
		$node = $this->folder((int)$campaign['id'])->get($name);
		if (!$node instanceof File) throw new NotFoundException('Campaign PDF is not available.');
		return $node;
	}

	public function verifiedContent(array $campaign): string {
		$content = $this->source($campaign)->getContent();
		if (empty($campaign['source_hash']) || !hash_equals($campaign['source_hash'], hash('sha256', $content))) throw new \RuntimeException('Campaign PDF integrity check failed.');
		return $content;
	}
}
