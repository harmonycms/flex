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
use Symfony\Flex\Recipe;

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

    /**
     * Installer constructor.
     *
     * @param IOInterface          $io
     * @param Composer             $composer
     * @param string               $type
     * @param Filesystem|null      $filesystem
     * @param BinaryInstaller|null $binaryInstaller
     * @param Configurator|null    $configurator
     */
    public function __construct(IOInterface $io, Composer $composer, string $type = 'library',
                                Filesystem $filesystem = null, BinaryInstaller $binaryInstaller = null,
                                Configurator $configurator = null)
    {
        parent::__construct($io, $composer, $type, $filesystem, $binaryInstaller);
        $this->configurator = $configurator;
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

        $manifest = [];
        foreach ($this->_getClassNames($package, 'install') as $class) {
            $manifest['manifest']['themes'][$class] = ['all'];
        }
        $this->configurator->install(new Recipe($package, $package->getName(), 'install', $manifest));
    }

    /**
     * Uninstalls specific package.
     *
     * @param InstalledRepositoryInterface $repo    repository in which to check
     * @param PackageInterface             $package package instance
     */
    public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package): void
    {
        parent::uninstall($repo, $package);
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
     * @param string           $operation
     *
     * @return array
     */
    private function _getClassNames(PackageInterface $package, string $operation): array
    {
        $uninstall = 'uninstall' === $operation;
        $classes   = [];
        $autoload  = $package->getAutoload();
        foreach (['psr-4' => true, 'psr-0' => false] as $psr => $isPsr4) {
            if (!isset($autoload[$psr])) {
                continue;
            }

            foreach ($autoload[$psr] as $namespace => $paths) {
                if (!\is_array($paths)) {
                    $paths = [$paths];
                }
                foreach ($paths as $path) {
                    foreach ($this->_extractClassNames($namespace) as $class) {
                        // we only check class existence on install as we do have the code available
                        // in contrast to uninstall operation
                        if (!$uninstall && !$this->_checkClassExists($package, $class, $path, $isPsr4)) {
                            continue;
                        }

                        $classes[] = $class;
                    }
                }
            }
        }

        return $classes;
    }

    /**
     * @param string $namespace
     *
     * @return array
     */
    private function _extractClassNames(string $namespace): array
    {
        $namespace = trim($namespace, '\\');
        $class     = $namespace . '\\';
        $parts     = explode('\\', $namespace);
        $suffix    = $parts[\count($parts) - 1];

        return [$class . $parts[0] . $suffix];
    }

    /**
     * @param PackageInterface $package
     * @param string           $class
     * @param string           $path
     * @param bool             $isPsr4
     *
     * @return bool
     */
    private function _checkClassExists(PackageInterface $package, string $class, string $path, bool $isPsr4): bool
    {
        $classPath = ($this->vendorDir ? $this->vendorDir . '/' : '') . $package->getPrettyName() . '/' . $path . '/';
        $parts     = explode('\\', $class);
        $class     = $parts[\count($parts) - 1];
        if (!$isPsr4) {
            $classPath .= str_replace('\\', '', implode('/', \array_slice($parts, 0, - 1))) . '/';
        }
        $classPath .= str_replace('\\', '/', $class) . '.php';

        return file_exists($classPath);
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

        return new $class($package, $this->composer, $this->io);
    }
}