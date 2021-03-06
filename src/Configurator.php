<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Harmony\Flex;

use Composer\Composer;
use Composer\IO\IOInterface;
use Symfony\Flex\Lock;
use Symfony\Flex\Options;
use Symfony\Flex\Configurator as SymfonyConfigurator;
use Symfony\Flex\Recipe;

/**
 * @author Fabien Potencier <fabien@symfony.com>
 * @author David Sanchez <david38sanchez@gmail.com>
 */
class Configurator
{

    /** @var Composer $composer */
    private $composer;

    /** @var IOInterface $io */
    private $io;

    /** @var Options $options */
    private $options;

    /** @var array $configurators */
    private $configurators;

    /** @var array $cache */
    private $cache;

    /**
     * Configurator constructor.
     *
     * @param Composer    $composer
     * @param IOInterface $io
     * @param Options     $options
     */
    public function __construct(Composer $composer, IOInterface $io, Options $options)
    {
        $this->composer = $composer;
        $this->io       = $io;
        $this->options  = $options;
        // ordered list of configurators
        $this->configurators = [
            'bundles'              => SymfonyConfigurator\BundlesConfigurator::class,
            'copy-from-recipe'     => SymfonyConfigurator\CopyFromRecipeConfigurator::class,
            'copy-from-package'    => SymfonyConfigurator\CopyFromPackageConfigurator::class,
            'env'                  => SymfonyConfigurator\EnvConfigurator::class,
            'container'            => SymfonyConfigurator\ContainerConfigurator::class,
            'makefile'             => SymfonyConfigurator\MakefileConfigurator::class,
            'composer-scripts'     => SymfonyConfigurator\ComposerScriptsConfigurator::class,
            'gitignore'            => SymfonyConfigurator\GitignoreConfigurator::class,
            'env-project'          => Configurator\EnvProjectConfigurator::class,
            'themes'               => Configurator\ThemesConfigurator::class,
            'extensions'           => Configurator\ExtensionsConfigurator::class,
            'copy-from-recipe-if'  => Configurator\ConditionCopyFromRecipeConfigurator::class,
            'merge-from-recipe-if' => Configurator\ConditionMergeFromRecipeConfigurator::class
        ];
    }

    /**
     * @param Recipe $recipe
     * @param Lock   $lock
     * @param array  $options
     */
    public function install(Recipe $recipe, Lock $lock, array $options = [])
    {
        $manifest = $recipe->getManifest();
        foreach (array_keys($this->configurators) as $key) {
            if (isset($manifest[$key])) {
                $this->get($key)->configure($recipe, $manifest[$key], $lock, $options);
            }
        }
    }

    /**
     * @param Recipe $recipe
     * @param Lock   $lock
     */
    public function uninstall(Recipe $recipe, Lock $lock)
    {
        $manifest = $recipe->getManifest();
        foreach (array_keys($this->configurators) as $key) {
            if (isset($manifest[$key])) {
                $this->get($key)->unconfigure($recipe, $manifest[$key], $lock);
            }
        }
    }

    /**
     * @param $key
     *
     * @return Configurator\AbstractConfigurator
     */
    public function get($key): Configurator\AbstractConfigurator
    {
        if (!isset($this->configurators[$key])) {
            throw new \InvalidArgumentException(sprintf('Unknown configurator "%s".', $key));
        }

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $class = $this->configurators[$key];

        return $this->cache[$key] = new $class($this->composer, $this->io, $this->options);
    }
}