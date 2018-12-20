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
use Harmony\Flex\Configurator\AbstractConfigurator;
use Symfony\Component\Yaml\Yaml;

/**
 * @author Fabien Potencier <fabien@symfony.com>
 */
class Configurator
{

    /** Constant */
    const HARMONY_CONFIG_YAML = 'harmony.yaml';

    /** @var string $configDir */
    protected $configDir;

    /** @var string $defaultConfigFile */
    protected $defaultConfigFile;

    private   $composer;

    private   $io;

    private   $options;

    private   $configurators;

    private   $cache;

    public function __construct(Composer $composer, IOInterface $io, Options $options)
    {
        $this->composer = $composer;
        $this->io       = $io;
        $this->options  = $options;
        // ordered list of configurators
        $this->configurators     = [
            'bundles'           => Configurator\BundlesConfigurator::class,
            'copy-from-recipe'  => Configurator\CopyFromRecipeConfigurator::class,
            'copy-from-package' => Configurator\CopyFromPackageConfigurator::class,
            'env'               => Configurator\EnvConfigurator::class,
            'container'         => Configurator\ContainerConfigurator::class,
            'makefile'          => Configurator\MakefileConfigurator::class,
            'composer-scripts'  => Configurator\ComposerScriptsConfigurator::class,
            'gitignore'         => Configurator\GitignoreConfigurator::class,
        ];
        $this->configDir         = dirname($composer->getConfig()->get('vendor-dir'));
        $this->defaultConfigFile = $this->configDir . '/config/packages/' . self::HARMONY_CONFIG_YAML;
    }

    public function install(Recipe $recipe, array $options = [])
    {
        $manifest = $recipe->getManifest();
        foreach (array_keys($this->configurators) as $key) {
            if (isset($manifest[$key])) {
                $this->get($key)->configure($recipe, $manifest[$key], $options);
            }
        }
    }

    /**
     * @param string $key
     * @param        $value
     *
     * @return bool|int
     */
    public function update(string $key, $value)
    {
        $yaml                  = Yaml::parseFile($this->defaultConfigFile);
        $yaml['harmony'][$key] = $value;

        return file_put_contents($this->defaultConfigFile, Yaml::dump($yaml));
    }

    public function unconfigure(Recipe $recipe)
    {
        $manifest = $recipe->getManifest();
        foreach (array_keys($this->configurators) as $key) {
            if (isset($manifest[$key])) {
                $this->get($key)->unconfigure($recipe, $manifest[$key]);
            }
        }
    }

    private function get($key): AbstractConfigurator
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
