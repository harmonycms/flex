<?php

namespace Harmony\Flex\Autoload;

use Composer\Autoload\AutoloadGenerator as BaseAutoloadGenerator;
use Composer\Installer\InstallationManager;
use Composer\Package\PackageInterface;

/**
 * Class AutoloadGenerator
 *
 * @package Harmony\Flex\Autoload
 */
class AutoloadGenerator extends BaseAutoloadGenerator
{

    /** @var null|PackageInterface $customPackage */
    protected $customPackage;

    /**
     * Set CustomPackage
     *
     * @param PackageInterface $customPackage
     *
     * @return AutoloadGenerator
     */
    public function setCustomPackage(PackageInterface $customPackage)
    {
        $this->customPackage = $customPackage;

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
        if (null === $this->customPackage) {
            return $packageMap;
        }

        return array_merge($packageMap, [
            [
                $this->customPackage,
                $installationManager->getInstallPath($this->customPackage)
            ]
        ]);
    }
}