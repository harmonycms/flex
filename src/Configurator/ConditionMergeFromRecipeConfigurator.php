<?php

namespace Harmony\Flex\Configurator;

use Symfony\Component\Yaml\Yaml;
use Symfony\Flex\Lock;
use Symfony\Flex\Recipe;
use function array_merge;
use function chmod;
use function file_put_contents;
use function fileperms;
use function pathinfo;
use function explode;

/**
 * Class ConditionMergeFromRecipeConfigurator
 *
 * @package Harmony\Flex\Configurator
 */
class ConditionMergeFromRecipeConfigurator extends AbstractConfigurator
{

    /**
     * @param Recipe $recipe
     * @param array  $config
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
                $lock->add($recipe->getName(), ['files' => $this->mergeFiles($files, $recipe->getFiles(), $options)]);
            }
        }
    }

    /**
     * @param Recipe $recipe
     * @param array  $config
     * @param Lock   $lock
     */
    public function unconfigure($recipe, $config, Lock $lock)
    {
        $this->write('Removing configuration and files conditionally');
        $installedRepo = $this->composer->getRepositoryManager()->getLocalRepository();
        foreach ($config as $condition => $files) {
            [$name, $constraint] = explode(':', $condition) + ['', '*'];
            if ($installedRepo->findPackage($name, $constraint)) {
                // TODO: un-merge using array_diff?
            }
        }
    }

    /**
     * @param array $manifest
     * @param array $files
     * @param array $options
     *
     * @return array
     */
    private function mergeFiles(array $manifest, array $files, array $options): array
    {
        $mergedFiles = [];
        $to          = $options['root-dir'] ?? '.';
        foreach ($manifest as $source => $target) {
            $target          = $this->options->expandTargetDir($target);
            $sourceExtension = pathinfo($source, PATHINFO_EXTENSION);
            if ('yaml' === $sourceExtension || 'yml' === $sourceExtension) {
                $mergedFiles[] = $this->mergeYamlFile($this->path->concatenate([$to, $target]), $source,
                    $files[$source]['contents'], $files[$source]['executable'], $options);
            }
        }

        return $mergedFiles;
    }

    /**
     * @param string $to
     * @param string $source
     * @param string $contents
     * @param bool   $executable
     * @param array  $options
     *
     * @return string
     */
    private function mergeYamlFile(string $to, string $source, string $contents, bool $executable,
                                   array $options): string
    {
        $overwrite  = $options['force'] ?? false;
        $basePath   = $options['root-dir'] ?? '.';
        $mergedFile = str_replace($basePath . \DIRECTORY_SEPARATOR, '', $to);

        if (!$this->options->shouldWriteFile($to, $overwrite)) {
            return $mergedFile;
        }

        $SourceData = Yaml::parseFile($source);
        $recipeData = Yaml::parse($contents);
        $merged     = $this->arrayMergeRecursiveDistinct($SourceData, $recipeData);

        file_put_contents($to, Yaml::dump($merged, PHP_INT_MAX));
        if ($executable) {
            @chmod($to, fileperms($to) | 0111);
        }

        $this->write(sprintf('Updated <fg=green>"%s"</>', $this->path->relativize($to)));

        return $mergedFile;
    }

    /**
     * array_merge_recursive does indeed merge arrays, but it converts values with duplicate
     * keys to arrays rather than overwriting the value in the first array with the duplicate
     * value in the second array, as array_merge does. I.e., with array_merge_recursive,
     * this happens (documented behavior):
     * ---
     * array_merge_recursive(array('key' => 'org value'), array('key' => 'new value'));
     *     => array('key' => array('org value', 'new value'));
     * ---
     * array_merge_recursive_distinct does not change the datatypes of the values in the arrays.
     * Matching keys' values in the second array overwrite those in the first array, as is the
     * case with array_merge, i.e.:
     * ---
     * array_merge_recursive_distinct(array('key' => 'org value'), array('key' => 'new value'));
     *     => array('key' => array('new value'));
     * ---
     * Parameters are passed by reference, though only for performance reasons. They're not
     * altered by this function.
     *
     * @author Daniel <daniel (at) danielsmedegaardbuus (dot) dk>
     * @author Gabriel Sobrinho <gabriel (dot) sobrinho (at) gmail (dot) com>
     *
     * @param array $array1
     * @param array $array2
     *
     * @return array
     */
    protected function arrayMergeRecursiveDistinct(array &$array1, array &$array2)
    {
        $merged = $array1;
        foreach ($array2 as $key => &$value) {
            if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])) {
                $merged[$key] = $this->arrayMergeRecursiveDistinct($merged[$key], $value);
            } else {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }
}