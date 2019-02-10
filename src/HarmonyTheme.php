<?php

namespace Harmony\Flex;

use Composer\Composer;
use Composer\Package\PackageInterface;

/**
 * Class HarmonyTheme
 *
 * @package Harmony\Flex
 */
class HarmonyTheme
{

    /** @var PackageInterface $package */
    private $package;

    /** @var string $operation */
    private $operation;

    /** @var string $themesDir */
    private $themesDir;

    /**
     * HarmonyTheme constructor.
     *
     * @param Composer         $composer
     * @param PackageInterface $package
     * @param string           $operation
     */
    public function __construct(Composer $composer, PackageInterface $package, string $operation)
    {
        $this->package   = $package;
        $this->operation = $operation;
        $this->themesDir = rtrim(dirname($composer->getConfig()->get('vendor-dir')), '/') . '/themes';
    }

    /**
     * @return array
     */
    public function getClassNames(): array
    {
        $uninstall = 'uninstall' === $this->operation;
        $classes   = [];
        $autoload  = $this->package->getAutoload();
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
                        if (!$uninstall && !$this->_checkClassExists($class, $path, $isPsr4)) {
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
        if ('Theme' !== substr($suffix, - 6)) {
            $suffix .= 'Theme';
        }
        $classes = [$class . $suffix];
        $acc     = '';
        foreach (\array_slice($parts, 0, - 1) as $part) {
            if ('Theme' === $part) {
                continue;
            }
            $classes[] = $class . $part . $suffix;
            $acc       .= $part;
            $classes[] = $class . $acc . $suffix;
        }

        return $classes;
    }

    /**
     * @param string $class
     * @param string $path
     * @param bool   $isPsr4
     *
     * @return bool
     */
    private function _checkClassExists(string $class, string $path, bool $isPsr4): bool
    {
        $classPath = ($this->themesDir ? $this->themesDir . '/' : '') . $this->package->getPrettyName() . '/' . $path .
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