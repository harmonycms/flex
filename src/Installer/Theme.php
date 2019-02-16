<?php

namespace Harmony\Flex\Installer;

use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Symfony\Flex\Recipe;

/**
 * Class Theme
 *
 * @package Harmony\Flex\Installer
 */
class Theme extends BaseInstaller
{

    /** Constant */
    const DIRNAME = 'themes';

    /**
     * Returns install locations
     *
     * @return array
     */
    protected function getLocations(): array
    {
        return [self::DIRNAME . '/{$vendor}/{$name}/'];
    }

    /**
     * Returns base directory
     *
     * @return string
     */
    public function getBaseDir(): string
    {
        return rtrim(dirname($this->composer->getConfig()->get('vendor-dir')), '/') . '/' . self::DIRNAME;
    }

    /**
     * Execute method during the install process
     *
     * @param InstalledRepositoryInterface $repo
     * @param PackageInterface             $package
     */
    public function install(InstalledRepositoryInterface $repo, PackageInterface $package): void
    {
        $manifest = [];
        foreach (parent::getClassNames($package, 'install') as $class) {
            $manifest['manifest']['themes'][$class] = ['all'];
        }
        $this->configurator->install(new Recipe($package, $package->getName(), 'install', $manifest));
    }

    /**
     * Execute method during the uninstall process
     *
     * @param InstalledRepositoryInterface $repo
     * @param PackageInterface             $package
     */
    public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package): void
    {
        $manifest = [];
        foreach (parent::getClassNames($package, 'uninstall') as $class) {
            $manifest['manifest']['themes'][$class] = ['all'];
        }
        $this->configurator->install(new Recipe($package, $package->getName(), 'uninstall', $manifest));
    }

    /**
     * Success installed message.
     * Ask user to set as default theme
     */
    public function postInstall(): void
    {
        $prettyName = $this->package->getPrettyName();
        list(, $name) = explode('/', $prettyName);

        $this->io->success('Theme "' . $prettyName . '" successfully installed');

        if ($this->io->confirm('Set as default theme?', false)) {
            // TODO: implements
        }
    }
}