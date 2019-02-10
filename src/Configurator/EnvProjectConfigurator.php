<?php

namespace Harmony\Flex\Configurator;

use DotEnvWriter\DotEnvWriter;
use Harmony\Flex\Platform\Model;

/**
 * Class EnvProjectConfigurator
 *
 * @package Harmony\Flex\Configurator
 */
class EnvProjectConfigurator extends AbstractConfigurator
{

    /**
     * @param Model\Project $project
     * @param array         $vars
     * @param array         $options
     *
     * @throws \Exception
     */
    public function configure($project, $vars, array $options = [])
    {
        $this->write('Added environment variable defaults');
        $this->configureDatabaseEnv($project);
    }

    /**
     * @param Model\Project $project
     * @param               $config
     */
    public function unconfigure($project, $config)
    {
        // TODO: Implement unconfigure() method.
    }

    /**
     * @param Model\Project $project
     *
     * @throws \Exception
     */
    private function configureDatabaseEnv(Model\Project $project)
    {
        foreach (['.env.dist', '.env'] as $file) {
            $env = $this->options->get('root-dir') . '/' . $file;
            if (!is_file($env)) {
                continue;
            }
            $envWriter = new DotEnvWriter($env);

            /** @var Model\ProjectDatabase $database */
            foreach ($project->getDatabases() as $database) {
                foreach ($database->getEnv() as $key => $value) {
                    // Comment old `DATABASE_URL` variable
                    $comment = null;
                    if ('DATABASE_URL' === $key) {
                        if (true === is_array($oldDatabaseUrl = $envWriter->get('DATABASE_URL'))) {
                            $comment = $oldDatabaseUrl['key'] . '=' . $oldDatabaseUrl['value'];
                        }
                    }
                    $envWriter->set($key, $value, $comment);
                    // Save data
                    $envWriter->save()->save('.env');
                }
            }
        }
    }
}