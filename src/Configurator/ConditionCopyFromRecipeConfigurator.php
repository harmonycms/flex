<?php

namespace Harmony\Flex\Configurator;

use Symfony\Flex\Lock;
use Symfony\Flex\Recipe;
use function array_column;
use function array_reduce;
use function array_unique;
use function array_keys;
use function count;
use function file_exists;
use function glob;
use function array_merge;
use function chmod;
use function dirname;
use function file_put_contents;
use function fileperms;
use function is_dir;
use function mkdir;
use function sprintf;
use function str_replace;
use function strlen;
use function strpos;
use function substr;
use function unlink;

/**
 * Class ConditionCopyFromRecipeConfigurator
 *
 * @package Harmony\Flex\Configurator
 */
class ConditionCopyFromRecipeConfigurator extends AbstractConfigurator
{

    /**
     * @param Recipe $recipe
     * @param        $config
     * @param Lock   $lock
     * @param array  $options
     */
    public function configure($recipe, $config, Lock $lock, array $options = [])
    {
        $this->write('Setting configuration and copying files conditionally');
        $options       = array_merge($this->options->toArray(), $options);
        $installedRepo = $this->composer->getRepositoryManager()->getLocalRepository();

        foreach ($config as $condition => $files) {
            [$name, $constraint] = explode(':', $condition) + ['', '*'];
            if ($installedRepo->findPackage($name, $constraint)) {
                $lock->add($recipe->getName(), ['files' => $this->copyFiles($files, $recipe->getFiles(), $options)]);
            }
        }
    }

    /**
     * @param Recipe $recipe
     * @param        $config
     * @param Lock   $lock
     */
    public function unconfigure($recipe, $config, Lock $lock)
    {
        $this->write('Removing configuration and files conditionally');
        $installedRepo = $this->composer->getRepositoryManager()->getLocalRepository();
        foreach ($config as $condition => $files) {
            [$name, $constraint] = explode(':', $condition) + ['', '*'];
            if ($installedRepo->findPackage($name, $constraint)) {
                $this->removeFiles($config, $this->getRemovableFilesFromRecipeAndLock($recipe, $lock),
                    $this->options->get('root-dir'));
            }
        }
    }

    /**
     * @param Recipe $recipe
     * @param Lock   $lock
     *
     * @return array
     */
    private function getRemovableFilesFromRecipeAndLock(Recipe $recipe, Lock $lock): array
    {
        $lockedFiles = array_unique(array_reduce(array_column($lock->all(), 'files'),
            function (array $carry, array $package) {
                return array_merge($carry, $package);
            }, []));

        $removableFiles = $recipe->getFiles();
        foreach ($lockedFiles as $file) {
            if (isset($removableFiles[$file])) {
                unset($removableFiles[$file]);
            }
        }

        return $removableFiles;
    }

    /**
     * @param array $manifest
     * @param array $files
     * @param array $options
     *
     * @return array
     */
    private function copyFiles(array $manifest, array $files, array $options): array
    {
        $copiedFiles = [];
        $to          = $options['root-dir'] ?? '.';

        foreach ($manifest as $source => $target) {
            $target = $this->options->expandTargetDir($target);
            if ('/' === substr($source, - 1)) {
                $copiedFiles = array_merge($copiedFiles,
                    $this->copyDir($source, $this->path->concatenate([$to, $target]), $files, $options));
            } else {
                $copiedFiles[] = $this->copyFile($this->path->concatenate([$to, $target]), $files[$source]['contents'],
                    $files[$source]['executable'], $options);
            }
        }

        return $copiedFiles;
    }

    /**
     * @param string $source
     * @param string $target
     * @param array  $files
     * @param array  $options
     *
     * @return array
     */
    private function copyDir(string $source, string $target, array $files, array $options): array
    {
        $copiedFiles = [];
        foreach ($files as $file => $data) {
            if (0 === strpos($file, $source)) {
                $file          = $this->path->concatenate([$target, substr($file, strlen($source))]);
                $copiedFiles[] = $this->copyFile($file, $data['contents'], $data['executable'], $options);
            }
        }

        return $copiedFiles;
    }

    /**
     * @param string $to
     * @param string $contents
     * @param bool   $executable
     * @param array  $options
     *
     * @return string
     */
    private function copyFile(string $to, string $contents, bool $executable, array $options): string
    {
        $overwrite  = $options['force'] ?? false;
        $basePath   = $options['root-dir'] ?? '.';
        $copiedFile = str_replace($basePath . \DIRECTORY_SEPARATOR, '', $to);

        if (!$this->options->shouldWriteFile($to, $overwrite)) {
            return $copiedFile;
        }

        if (!is_dir(dirname($to))) {
            mkdir(dirname($to), 0777, true);
        }

        file_put_contents($to, $this->options->expandTargetDir($contents));
        if ($executable) {
            @chmod($to, fileperms($to) | 0111);
        }

        $this->write(sprintf('Created <fg=green>"%s"</>', $this->path->relativize($to)));

        return $copiedFile;
    }

    /**
     * @param array  $manifest
     * @param array  $files
     * @param string $to
     */
    private function removeFiles(array $manifest, array $files, string $to)
    {
        foreach ($manifest as $source => $target) {
            $target = $this->options->expandTargetDir($target);

            if ('.git' === $target) {
                // never remove the main Git directory, even if it was created by a recipe
                continue;
            }

            if ('/' === substr($source, - 1)) {
                foreach (array_keys($files) as $file) {
                    if (0 === strpos($file, $source)) {
                        $this->removeFile($this->path->concatenate([$to, $target, substr($file, strlen($source))]));
                    }
                }
            } else {
                $this->removeFile($this->path->concatenate([$to, $target]));
            }
        }
    }

    /**
     * @param string $to
     */
    private function removeFile(string $to)
    {
        if (!file_exists($to)) {
            return;
        }

        @unlink($to);
        $this->write(sprintf('Removed <fg=green>"%s"</>', $this->path->relativize($to)));

        if (0 === count(glob(\dirname($to) . '/*', GLOB_NOSORT))) {
            @rmdir(\dirname($to));
        }
    }
}