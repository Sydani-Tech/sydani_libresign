<?php
declare(strict_types=1);
/** SPDX-License-Identifier: AGPL-3.0-or-later */
namespace OCA\Libresign\Service;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
class LetterheadStorage {
 public function __construct(private IAppDataFactory $factory) {}
 private function folder(): Folder {$simple=$this->factory->get('libresign')->getFolder('/');$root=(new \ReflectionClass($simple))->getProperty('folder')->getValue($simple);try{$folder=$root->get('letterheads');}catch(NotFoundException){$folder=$root->newFolder('letterheads');}if (!$folder instanceof Folder) throw new \RuntimeException('Private letterhead storage unavailable.');return $folder;}
 public function write(string $content,string $extension='pdf'): File {if (!in_array($extension,['pdf','png'],true)) throw new \InvalidArgumentException('Invalid letterhead file type.');return $this->folder()->newFile(bin2hex(random_bytes(20)).'.'.$extension,$content);}
 public function get(string $name): File {if (!preg_match('/^[a-f0-9]{40}\.(pdf|png)$/',$name)) throw new NotFoundException('Document unavailable.');$f=$this->folder()->get($name);if (!$f instanceof File) throw new NotFoundException('Document unavailable.');return $f;}
 public function verified(string $name,string $hash): File {$f=$this->get($name);if (!hash_equals($hash,hash('sha256',$f->getContent()))) throw new \RuntimeException('Letterhead document integrity check failed.');return $f;}
}
