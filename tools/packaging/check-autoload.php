<?php

/** SPDX-FileCopyrightText: 2026 Sydani Tech and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
$app=rtrim($argv[1]??dirname(__DIR__,2),'/');
$entry=$app.'/composer/autoload.php';if(!is_readable($entry))throw new RuntimeException('Nextcloud application autoload entry point missing.');
require $entry;
$loaders=Composer\Autoload\ClassLoader::getRegisteredLoaders();$loader=$loaders[$app.'/vendor']??null;if(!$loader)throw new RuntimeException('Application Composer loader was not registered.');$map=$loader->getClassMap();
$imports=[];$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($app.'/lib',FilesystemIterator::SKIP_DOTS));
foreach($iterator as $file){if($file->getExtension()!=='php')continue;$text=file_get_contents($file->getPathname());preg_match_all('/^use (OCA\\\\Libresign\\\\Vendor\\\\[^;\s]+)(?:\s+as\s+\w+)?;/m',$text,$matches);foreach($matches[1] as $class)$imports[$class]=true;}
if(count($imports)<30)throw new RuntimeException('Expected the application vendor imports to be discovered.');
$passed=0;$namespaces=0;$failed=[];
foreach(array_keys($imports) as $class){try{if(class_exists($class)||interface_exists($class)||trait_exists($class)||enum_exists($class)){$passed++;continue;}$prefix=$class.'\\';foreach(array_keys($map) as $known)if(str_starts_with($known,$prefix)){$namespaces++;continue 2;}$failed[]=$class;}catch(Throwable $error){$failed[]=$class.': '.$error->getMessage();}}
if($failed){foreach($failed as $failure)fwrite(STDERR,'FAIL: '.$failure.PHP_EOL);exit(1);}
$os=new OCA\Libresign\Vendor\LibreSign\WhatOSAmI\OperatingSystem();if($os->getFamily()!==PHP_OS_FAMILY)throw new RuntimeException('Operating-system detection disagrees with PHP.');
echo 'PASS: Nextcloud application entry point loads ',$passed,' referenced vendor types and ',$namespaces,' namespace aliases',PHP_EOL;
echo 'PASS: actual operating-system detection works',PHP_EOL;
