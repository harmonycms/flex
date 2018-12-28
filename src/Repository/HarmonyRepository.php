<?php

namespace Harmony\Flex\Repository;

use Composer\Config;
use Composer\EventDispatcher\EventDispatcher;
use Composer\IO\IOInterface;
use Composer\Repository\ComposerRepository;
use Composer\Util\RemoteFilesystem;

/**
 * Class HarmonyRepository
 *
 * @package Harmony\Flex\Repository
 */
class HarmonyRepository extends ComposerRepository
{

    /** Constants */
    const REPOSITORY_NAME = 'harmonycms.net';
    const REPOSITORY_URL  = 'api.' . self::REPOSITORY_NAME;
    const ACCOUNT_URL     = 'account.' . self::REPOSITORY_NAME;

    /**
     * Default HarmonyCMS composer repository
     *
     * @var array $defaultRepositories
     */
    public static $defaultRepositories
        = [
            'type' => 'composer',
            'url'  => 'https?://' . self::REPOSITORY_URL
        ];

    /** @var array $providerFiles */
    private $providerFiles;

    /**
     * HarmonyRepository constructor.
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
        parent::__construct(self::$defaultRepositories, $io, $config, $eventDispatcher, $rfs);
    }

    /**
     * @param mixed $data
     */
    protected function loadProviderListings($data)
    {
        if (null !== $this->providerFiles) {
            parent::loadProviderListings($data);

            return;
        }

        $data = [$data];

        while ($data) {
            $this->providerFiles = [];
            foreach ($data as $data) {
                $this->loadProviderListings($data);
            }

            $loadingFiles        = $this->providerFiles;
            $this->providerFiles = null;
            $data                = [];
            $this->rfs->download($loadingFiles, function (...$args) use (&$data) {
                $data[] = $this->fetchFile(...$args);
            });
        }
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
        if (null !== $this->providerFiles) {
            $this->providerFiles[] = [$filename, $cacheKey, $sha256, $storeLastModifiedTime];

            return [];
        }

        return parent::fetchFile($filename, $cacheKey, $sha256, $storeLastModifiedTime);
    }
}