<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Harmony\Flex\Repository;

use Composer\Config;
use Composer\EventDispatcher\EventDispatcher;
use Composer\IO\IOInterface;
use Composer\Repository\ComposerRepository as BaseComposerRepository;
use Composer\Util\RemoteFilesystem;
use Harmony\Flex\Cache;

/**
 * Class TruncatedComposerRepository
 *
 * @package Harmony\Flex\Repository
 */
class TruncatedComposerRepository extends BaseComposerRepository
{

    /**
     * TruncatedComposerRepository constructor.
     *
     * @param array                 $repoConfig
     * @param IOInterface           $io
     * @param Config                $config
     * @param EventDispatcher|null  $eventDispatcher
     * @param RemoteFilesystem|null $rfs
     */
    public function __construct(array $repoConfig, IOInterface $io, Config $config,
                                EventDispatcher $eventDispatcher = null, RemoteFilesystem $rfs = null)
    {
        parent::__construct($repoConfig, $io, $config, $eventDispatcher, $rfs);
        $this->cache = new Cache($io,
            $config->get('cache-repo-dir') . '/' . preg_replace('{[^a-z0-9.]}i', '-', $this->url), 'a-z0-9.$');
    }

    /**
     * @param string      $symfonyRequire
     * @param IOInterface $io
     */
    public function setSymfonyRequire(string $symfonyRequire, IOInterface $io)
    {
        $this->cache->setSymfonyRequire($symfonyRequire, $io);
    }

    /**
     * @param      $filename
     * @param null $cacheKey
     * @param null $sha256
     * @param bool $storeLastModifiedTime
     *
     * @return array|mixed
     * @throws \Composer\Repository\RepositorySecurityException
     */
    protected function fetchFile($filename, $cacheKey = null, $sha256 = null, $storeLastModifiedTime = false)
    {
        $data = parent::fetchFile($filename, $cacheKey, $sha256, $storeLastModifiedTime);

        return \is_array($data) ? $this->cache->removeLegacyTags($data) : $data;
    }
}