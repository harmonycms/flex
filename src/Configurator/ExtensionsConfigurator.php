<?php

namespace Harmony\Flex\Configurator;

/**
 * Class ExtensionsConfigurator
 *
 * @author  David Sanchez <david38sanchez@gmail.com>
 * @package Harmony\Flex\Configurator
 */
class ExtensionsConfigurator extends AbstractConfigurator
{

    /**
     * @param       $recipe
     * @param array $extensions
     * @param array $options
     */
    public function configure($recipe, $extensions, array $options = [])
    {
        $this->write('Enabling the package as a HarmonyCMS extension');
        $file       = $this->_getConfFile();
        $registered = $this->_load($file);
        $classes    = $this->_parse($extensions, $registered);
        foreach ($classes as $class => $envs) {
            foreach ($envs as $env) {
                $registered[$class][$env] = true;
            }
        }
        $this->_dump($file, $registered);
    }

    /**
     * @param       $recipe
     * @param array $extensions
     */
    public function unconfigure($recipe, $extensions)
    {
        $this->write('Disabling the HarmonyCMS extension');
        $file = $this->_getConfFile();
        if (!file_exists($file)) {
            return;
        }
        $registered = $this->_load($file);
        foreach (array_keys($this->_parse($extensions, [])) as $class) {
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
        $extensions = [];
        foreach ($manifest as $class => $envs) {
            if (!isset($registered[$class])) {
                $extensions[ltrim($class, '\\')] = $envs;
            }
        }

        return $extensions;
    }

    /**
     * @param string $file
     *
     * @return array
     */
    private function _load(string $file): array
    {
        $extensions = file_exists($file) ? (require $file) : [];
        if (!\is_array($extensions)) {
            $extensions = [];
        }

        return $extensions;
    }

    /**
     * @param string $file
     * @param array  $extensions
     */
    private function _dump(string $file, array $extensions)
    {
        $contents = "<?php\n\nreturn [\n";
        foreach ($extensions as $class => $envs) {
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
        return $this->options->get('root-dir') . '/' . $this->options->expandTargetDir('%CONFIG_DIR%/extensions.php');
    }
}