<?php

namespace Harmony\Flex\Configurator;

use Symfony\Flex\Lock;

/**
 * Class ThemesConfigurator
 *
 * @package Harmony\Flex\Configurator
 */
class ThemesConfigurator extends AbstractConfigurator
{

    /**
     * @param       $recipe
     * @param array $themes
     * @param Lock  $lock
     * @param array $options
     */
    public function configure($recipe, $themes, Lock $lock, array $options = [])
    {
        $this->write('Enabling the package as a HarmonyCMS theme');
        $file       = $this->_getConfFile();
        $registered = $this->_load($file);
        $classes    = $this->_parse($themes, $registered);
        foreach ($classes as $class => $envs) {
            foreach ($envs as $env) {
                $registered[$class][$env] = true;
            }
        }
        $this->_dump($file, $registered);
    }

    /**
     * @param       $recipe
     * @param array $themes
     * @param Lock  $lock
     */
    public function unconfigure($recipe, $themes, Lock $lock)
    {
        $this->write('Disabling the HarmonyCMS theme');
        $file = $this->_getConfFile();
        if (!file_exists($file)) {
            return;
        }
        $registered = $this->_load($file);
        foreach (array_keys($this->_parse($themes, [])) as $class) {
            unset($registered[$class]);
        }
        $this->_dump($file, $registered);
    }

    /**
     * @param array $manifest
     * @param array $registered
     *
     * @return array
     */
    private function _parse(array $manifest, array $registered): array
    {
        $themes = [];
        foreach ($manifest as $class => $envs) {
            if (!isset($registered[$class])) {
                $themes[ltrim($class, '\\')] = $envs;
            }
        }

        return $themes;
    }

    /**
     * @param string $file
     *
     * @return array
     */
    private function _load(string $file): array
    {
        $themes = file_exists($file) ? (require $file) : [];
        if (!\is_array($themes)) {
            $themes = [];
        }

        return $themes;
    }

    /**
     * @param string $file
     * @param array  $themes
     */
    private function _dump(string $file, array $themes)
    {
        $contents = "<?php\n\nreturn [\n";
        foreach ($themes as $class => $envs) {
            $contents .= "    $class::class => [";
            foreach (array_keys($envs) as $env) {
                $contents .= "'$env' => true, ";
            }
            $contents = substr($contents, 0, - 2) . "],\n";
        }
        $contents .= "];\n";

        if (!is_dir(\dirname($file))) {
            mkdir(\dirname($file), 0777, true);
        }

        file_put_contents($file, $contents);

        if (\function_exists('opcache_invalidate')) {
            opcache_invalidate($file);
        }
    }

    /**
     * @return string
     */
    private function _getConfFile(): string
    {
        return $this->options->get('root-dir') . '/' . $this->options->expandTargetDir('%CONFIG_DIR%/themes.php');
    }
}