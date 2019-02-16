<?php

namespace Harmony\Flex\Installer;

use Composer\Composer;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Harmony\Flex\Configurator;
use Harmony\Flex\IO\ConsoleIO;

/**
 * Class BaseInstaller
 *
 * @package Harmony\Flex\Installer
 */
abstract class BaseInstaller
{

    /** @var array $locations */
    protected $locations = [];

    /** @var PackageInterface $package */
    protected $package;

    /** @var Composer $composer */
    protected $composer;

    /** @var ConsoleIO $io */
    protected $io;

    /** @var Configurator $configurator */
    protected $configurator;

    /**
     * BaseInstaller constructor.
     *
     * @param PackageInterface $package
     * @param Composer         $composer
     * @param ConsoleIO        $io
     * @param Configurator     $configurator
     */
    public function __construct(PackageInterface $package, Composer $composer, ConsoleIO $io,
                                Configurator $configurator)
    {
        $this->package      = $package;
        $this->composer     = $composer;
        $this->io           = $io;
        $this->configurator = $configurator;
    }

    /**
     * Returns install locations
     *
     * @return array
     */
    abstract protected function getLocations(): array;

    /**
     * Returns base directory
     *
     * @return null|string
     */
    public function getBaseDir(): ?string
    {
        return null;
    }

    /**
     * Return the install path based on package type.
     *
     * @return string
     */
    public function getInstallPath(): string
    {
        $type = $this->package->getType();

        $vendor = '';
        $name   = $prettyName = $this->package->getPrettyName();
        if (strpos($prettyName, '/') !== false) {
            list($vendor, $name) = explode('/', $prettyName);
        }

        $availableVars = $this->inflectPackageVars(compact('name', 'vendor', 'type'));

        $extra = $this->package->getExtra();
        if (!empty($extra['installer-name'])) {
            $availableVars['name'] = $extra['installer-name'];
        }

        // TODO: Update code, installing extensions will not works properly
        return $this->templatePath($this->getLocations()[0], $availableVars);
    }

    /**
     * For an installer to override to modify the vars per installer.
     *
     * @param  array $vars
     *
     * @return array
     */
    public function inflectPackageVars(array $vars): array
    {
        return $vars;
    }

    /**
     * Execute method during the install process
     *
     * @param InstalledRepositoryInterface $repo
     * @param PackageInterface             $package
     */
    public function install(InstalledRepositoryInterface $repo, PackageInterface $package): void
    {
    }

    /**
     * Execute method after package installed
     */
    public function postInstall(): void
    {
    }

    /**
     * Replace vars in a path
     *
     * @param  string $path
     * @param  array  $vars
     *
     * @return string
     */
    protected function templatePath($path, array $vars = [])
    {
        if (strpos($path, '{') !== false) {
            extract($vars);
            preg_match_all('@\{\$([A-Za-z0-9_]*)\}@i', $path, $matches);
            if (!empty($matches[1])) {
                foreach ($matches[1] as $var) {
                    $path = str_replace('{$' . $var . '}', $$var, $path);
                }
            }
        }

        return $path;
    }

    /**
     * @param PackageInterface $package
     * @param string           $operation
     *
     * @return array
     */
    protected function getClassNames(PackageInterface $package, string $operation): array
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
        $classPath = ($this->getBaseDir() ? $this->getBaseDir() . '/' : '') . $package->getPrettyName() . '/' . $path .
            '/';
        $parts     = explode('\\', $class);
        $class     = $parts[\count($parts) - 1];
        if (!$isPsr4) {
            $classPath .= str_replace('\\', '', implode('/', \array_slice($parts, 0, - 1))) . '/';
        }
        $classPath .= str_replace('\\', '/', $class) . '.php';

        return file_exists($classPath);
    }
}