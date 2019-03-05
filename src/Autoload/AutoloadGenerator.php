<?php

namespace Harmony\Flex\Autoload;

use Composer\Autoload\AutoloadGenerator as BaseAutoloadGenerator;
use Composer\Installer\InstallationManager;
use Composer\Package\AliasPackage;
use Composer\Package\PackageInterface;

/**
 * Class AutoloadGenerator
 *
 * @package Harmony\Flex\Autoload
 */
class AutoloadGenerator extends BaseAutoloadGenerator
{

    /** @var PackageInterface[] $customPackages */
    protected $customPackages = [];

    /**
     * Set CustomPackages
     *
     * @param PackageInterface[] $customPackages
     *
     * @return AutoloadGenerator
     */
    public function setCustomPackages(array $customPackages): AutoloadGenerator
    {
        $this->customPackages = $customPackages;

        return $this;
    }

    /**
     * Add CustomPackage
     *
     * @param PackageInterface $package
     *
     * @return AutoloadGenerator
     */
    public function addCustomPackage(PackageInterface $package): AutoloadGenerator
    {
        $this->customPackages[] = $package;

        return $this;
    }

    /**
     * @param InstallationManager $installationManager
     * @param PackageInterface    $mainPackage
     * @param array               $packages
     *
     * @return array
     */
    public function buildPackageMap(InstallationManager $installationManager, PackageInterface $mainPackage,
                                    array $packages)
    {
        $packageMap = parent::buildPackageMap($installationManager, $mainPackage, $packages);
        if (empty($this->customPackages)) {
            return $packageMap;
        }

        foreach ($this->customPackages as $package) {
            if ($package instanceof AliasPackage) {
                continue;
            }
            $this->validatePackage($package);

            $packageMap[] = [
                $package,
                $installationManager->getInstallPath($package),
            ];
        }

        return $packageMap;
    }

}