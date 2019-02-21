<?php

namespace Harmony\Flex;

use Composer\Composer;
use Composer\Installer\BinaryInstaller;
use Composer\Installer\LibraryInstaller;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Util\Filesystem;
use Harmony\Flex\Installer\{BaseInstaller, Extension, Package, Stack, Theme};
use Symfony\Flex\Lock;

/**
 * Class Installer
 *
 * @package Harmony\Flex
 */
class Installer extends LibraryInstaller
{

    /** Constant */
    const PREFIX = 'harmony-';

    /** @var array $supports */
    protected $supports
        = [
            self::PREFIX . 'extension' => Extension::class,
            self::PREFIX . 'package'   => Package::class,
            self::PREFIX . 'stack'     => Stack::class,
            self::PREFIX . 'theme'     => Theme::class,
        ];

    /** @var Configurator $configurator */
    protected $configurator;

    /** @var Lock $lock */
    protected $lock;

    /**
     * Installer constructor.
     *
     * @param IOInterface          $io
     * @param Composer             $composer
     * @param string               $type
     * @param Filesystem|null      $filesystem
     * @param BinaryInstaller|null $binaryInstaller
     * @param Configurator|null    $configurator
     * @param Lock                 $lock
     */
    public function __construct(IOInterface $io, Composer $composer, string $type = 'library',
                                Filesystem $filesystem = null, BinaryInstaller $binaryInstaller = null,
                                Configurator $configurator = null, Lock $lock = null)
    {
        parent::__construct($io, $composer, $type, $filesystem, $binaryInstaller);
        $this->configurator = $configurator;
        $this->lock         = $lock;
    }

    /**
     * Decides if the installer supports the given type
     *
     * @param  string $packageType
     *
     * @return bool
     */
    public function supports($packageType): bool
    {
        return array_key_exists($packageType, $this->supports);
    }

    /**
     * Returns the installation path of a package
     *
     * @param  PackageInterface $package
     *
     * @return string           path
     */
    public function getInstallPath(PackageInterface $package): string
    {
        return $this->_getInstallerInstance($package)->getInstallPath();
    }

    /**
     * Installs specific package.
     *
     * @param InstalledRepositoryInterface $repo    repository in which to check
     * @param PackageInterface             $package package instance
     */
    public function install(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        $installer = $this->_getInstallerInstance($package);
        if (null !== $baseDir = $installer->getBaseDir()) {
            $this->vendorDir = $baseDir;
        }

        parent::install($repo, $package);

        $installer->install($repo, $package);
    }

    /**
     * Uninstalls specific package.
     *
     * @param InstalledRepositoryInterface $repo    repository in which to check
     * @param PackageInterface             $package package instance
     */
    public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package): void
    {
        $installer = $this->_getInstallerInstance($package);
        if (null !== $baseDir = $installer->getBaseDir()) {
            $this->vendorDir = $baseDir;
        }

        parent::uninstall($repo, $package);

        $installer->uninstall($repo, $package);

        $installPath = $this->getPackageBasePath($package);
        $this->io->write(sprintf('Deleting %s - %s', $installPath,
            !file_exists($installPath) ? '<comment>deleted</comment>' : '<error>not deleted</error>'));
    }

    /**
     * Execute method after package installed
     *
     * @param PackageInterface $package
     */
    public function postInstall(PackageInterface $package): void
    {
        $this->_getInstallerInstance($package)->postInstall();
    }

    /**
     * @param PackageInterface $package
     *
     * @return BaseInstaller
     */
    private function _getInstallerInstance(PackageInterface $package): BaseInstaller
    {
        $type = $package->getType();
        if (false === $this->supports($type)) {
            throw new \InvalidArgumentException('Sorry the package type of this package is not supported.');
        }
        $class = $this->supports[$type];

        return new $class($package, $this->composer, $this->io, $this->configurator, $this->lock);
    }
}