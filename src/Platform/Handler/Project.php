<?php

namespace Harmony\Flex\Platform\Handler;

use Composer\Composer;
use Composer\Config;
use Composer\DependencyResolver\DefaultPolicy;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Pool;
use Composer\DependencyResolver\Request;
use Composer\Factory;
use Composer\Installer\InstallationManager;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Json\JsonManipulator;
use Composer\Package\Dumper\ArrayDumper;
use Composer\Package\Locker;
use Composer\Package\PackageInterface;
use Composer\Repository\CompositeRepository;
use Composer\Repository\InstalledFilesystemRepository;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Repository\InvalidRepositoryException;
use Harmony\Flex\Autoload\AutoloadGenerator;
use Harmony\Flex\Configurator;
use Harmony\Flex\Platform\Model\Project as ProjectModel;
use Harmony\Flex\Platform\Model\ProjectDatabase;
use Harmony\Flex\Serializer\Normalizer\ProjectNormalizer;
use Harmony\Sdk\HttpClient;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Serializer\Encoder\JsonDecode;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Serializer;
use Symfony\Flex\Lock;
use Symfony\Flex\ScriptExecutor;

/**
 * Class Project
 *
 * @package Harmony\Flex\Platform
 */
class Project
{

    /** @var HttpClient\Client $client */
    protected $client;

    /** @var IOInterface|SymfonyStyle $io */
    protected $io;

    /** @var Composer $composer */
    protected $composer;

    /** @var Filesystem $fs */
    protected $fs;

    /** @var string $harmonyCacheFile */
    protected $harmonyCacheFile;

    /** @var ProjectModel $projectData */
    protected $projectData;

    /** @var Stack $stack */
    protected $stack;

    /** @var InstallationManager $installationManager */
    protected $installationManager;

    /** @var Serializer $serializer */
    protected $serializer;

    /** @var Configurator $configurator */
    protected $configurator;

    /** @var bool $activated */
    protected $activated = true;

    /** @var ScriptExecutor $executor */
    protected $executor;

    /** @var Lock $lock */
    protected $lock;

    /** @var InstallOperation[] $operations */
    protected $operations = [];

    /** @var AutoloadGenerator $autoloadGenerator */
    protected $autoloadGenerator;

    /** @var InstalledFilesystemRepository $installedFilesystemRepository */
    protected $installedFilesystemRepository;

    /** @var InstalledRepositoryInterface $localRepository */
    protected $localRepository;

    /**
     * Project constructor.
     *
     * @param IOInterface       $io
     * @param HttpClient\Client $client
     * @param Composer          $composer
     * @param Configurator      $configurator
     * @param Config            $config
     * @param ScriptExecutor    $executor
     * @param Lock              $lock
     *
     * @throws \Http\Client\Exception
     */
    public function __construct(IOInterface $io, HttpClient\Client $client, Composer $composer,
                                Configurator $configurator, Config $config, ScriptExecutor $executor, Lock $lock)
    {
        $this->client                        = $client;
        $this->io                            = $io;
        $this->composer                      = $composer;
        $this->fs                            = new Filesystem();
        $this->harmonyCacheFile              = $config->get('data-dir') . '/harmony.json';
        $this->stack                         = new Stack($io, $client, $composer);
        $this->installationManager           = $composer->getInstallationManager();
        $this->serializer                    = new Serializer([new ProjectNormalizer()], [new JsonEncoder()]);
        $this->configurator                  = $configurator;
        $this->executor                      = $executor;
        $this->lock                          = $lock;
        $this->autoloadGenerator             = $this->composer->getAutoloadGenerator();
        $this->installedFilesystemRepository = new InstalledFilesystemRepository(new JsonFile('php://memory'));
        $this->localRepository               = $this->composer->getRepositoryManager()->getLocalRepository();

        $this->getOrAskForId();
    }

    /**
     * Removing useless/unused files or directories by HarmonyCMS, like `templates` folder
     *
     * @return void
     */
    public function clear(): void
    {
        $rootDir = dirname($this->composer->getConfig()->get('vendor-dir'));
        $this->fs->remove($rootDir . '/templates');
    }

    /**
     * Configure `DATABASE_URL` env variable from configured project information.
     *
     * @throws \Exception
     * @return void
     */
    public function configDatabases(): void
    {
        if ($this->projectData->hasDatabases()) {
            $this->configurator->get('env-project')->configure($this->projectData, [], $this->lock);
        }
    }

    /**
     * Ask user to initialize database.
     * 1. Create database
     * 2. Create schema
     *
     * @return void
     */
    public function initDatabase(): void
    {
        if ($this->projectData->hasDatabases()) {
            // Execute init commands for database
            if ($this->io->confirm('Initialize database?', false)) {
                $this->executor->execute('symfony-cmd', 'doctrine:database:create --if-not-exists');
                $this->executor->execute('symfony-cmd', 'doctrine:schema:update --force');
            }
        }
    }

    /**
     * Install stacks.
     * 1. Database stack (ORM, MongoDB, CouchDB)
     *
     * @return void
     */
    public function installStacks(): void
    {
        if ($this->projectData->hasDatabases()) {
            $config  = $this->stack->getConfigJson();
            $schemes = [];
            /** @var ProjectDatabase $database */
            foreach ($this->projectData->getDatabases() as $database) {
                $schemes[$database->getScheme()] = $database->getScheme();
            }
            foreach ($schemes as $scheme) {
                if (isset($config['doctrine']['scheme'][$scheme])) {
                    $package = $this->composer->getRepositoryManager()
                        ->findPackage($config['doctrine']['scheme'][$scheme], 'master');
                    if (null !== $package) {
                        $operation = new InstallOperation($package);
                        $this->installationManager->install(new InstalledFilesystemRepository(new JsonFile('php://memory')),
                            $operation);
                        $this->installationManager->notifyInstalls($this->io);
                    }
                }
            }
        }
    }

    /**
     * Register themes
     *
     * @return void
     */
    public function registerThemes(): void
    {
        if ($this->projectData->hasThemes()) {
            foreach ($this->projectData->getThemes() as $name => $options) {
                $this->registerOperation($name, $options['version']);
            }
        }
    }

    /**
     * Register packages
     *
     * @return void
     */
    public function registerPackages(): void
    {
        if ($this->projectData->hasPackages()) {
            foreach ($this->projectData->getPackages() as $name => $options) {
                $this->registerOperation($name, $options['version']);
            }
        }
    }

    /**
     * Register extensions
     *
     * @return void
     */
    public function registerExtensions(): void
    {
        if ($this->projectData->hasExtensions()) {
            foreach ($this->projectData->getExtensions() as $name => $options) {
                $this->registerOperation($name, $options['version']);
            }
        }
    }

    /**
     * @param string $name
     * @param string $constraint
     */
    protected function registerOperation(string $name, string $constraint): void
    {
        if (null !== $rootPackage = $this->composer->getRepositoryManager()->findPackage($name, $constraint)) {
            foreach ($rootPackage->getRequires() as $link) {
                if (null !== $package = $this->composer->getRepositoryManager()
                        ->findPackage($link->getTarget(), $link->getConstraint())) {
                    $this->operations[] = new InstallOperation($package);
                }
            }
            $this->operations[] = new InstallOperation($rootPackage, 'root-package');
        }
    }

    /**
     * @throws InvalidRepositoryException
     * @throws \Exception
     */
    public function executeOperations(): void
    {
        foreach ($this->operations as $operation) {
            // Execute install operation
            $this->installationManager->install($this->installedFilesystemRepository, $operation);
            $this->installationManager->notifyInstalls($this->io);

            $this->autoloadGenerator->addCustomPackage($operation->getPackage());
        }

        // Update `composer.json` & `composer.lock`
        $this->updateComposer();

        // Update installed.json
        $this->updateInstalledJson();

        // Dump autoloader
        $this->autoloadGenerator->dump($this->composer->getConfig(), $this->localRepository,
            $this->composer->getPackage(), $this->installationManager, 'composer');

        foreach ($this->operations as $operation) {
            // Dispatch event for Symfony Flex operations
            $this->composer->getEventDispatcher()
                ->dispatchPackageEvent(PackageEvents::POST_PACKAGE_INSTALL, false, new DefaultPolicy(false, false),
                    new Pool(), new CompositeRepository([]), new Request(), [$operation], $operation);
        }
    }

    /**
     * Execute `fos:user:create` command to create a new user.
     *
     * @return void
     */
    public function createUser(): void
    {
        if ($this->projectData->hasDatabases()) {
            if ($this->io->confirm('Create super-admin user?', false)) {
                $this->executor->execute('symfony-cmd', 'fos:user:create --super-admin');
            }
        }
    }

    /**
     * @return bool
     */
    public function isActivated(): bool
    {
        return $this->activated;
    }

    /**
     * Get or ask for ProjectID.
     *
     * @return bool
     * @throws \Http\Client\Exception
     * @throws \Exception
     */
    protected function getOrAskForId(): bool
    {
        if (true === $this->fs->exists($this->harmonyCacheFile)) {
            $file      = new SplFileInfo($this->harmonyCacheFile, '', '');
            $data      = (new JsonDecode(true))->decode($file->getContents(), JsonEncoder::FORMAT);
            $projectId = key((array)$data);

            /** @var HttpClient\Receiver\Projects $projects */
            $projects    = $this->client->getReceiver(HttpClient\Client::RECEIVER_PROJECTS);
            $projectData = $projects->getProject($projectId);

            if (null === $projectId || false === is_array($projectData) ||
                isset($projectData['code']) && 400 === $projectData['code']) {

                goto askForId;
            }
            $this->projectData = $this->serializer->deserialize(json_encode($projectData), ProjectModel::class, 'json');

            return $this->activated = true;
        }

        askForId:

        $projectId
            = $this->io->ask("Please provide an HarmonyCMS Project ID or press any key to complete installation: ",
            null, function ($value) {
                return $value;
            });
        if (null !== $projectId) {
            $retries = 3;
            $step    = 1;
            while ($retries --) {
                /** @var HttpClient\Receiver\Projects $projects */
                $projects    = $this->client->getReceiver(HttpClient\Client::RECEIVER_PROJECTS);
                $projectData = $projects->getProject($projectId);
                if (is_array($projectData) || isset($projectData['code']) && 400 !== $projectData['code']) {
                    $this->projectData = $this->serializer->deserialize(json_encode($projectData), ProjectModel::class,
                        'json');
                    try {
                        // store value in `harmony.json` file
                        $this->fs->dumpFile($this->harmonyCacheFile, json_encode([$projectId => []]));
                        $this->io->success('HarmonyCMS Project ID verified.');

                        return $this->activated = true;
                    }
                    catch (IOException $e) {
                        $this->io->error('Error saving project ID!');

                        return $this->activated = false;
                    }
                } else {
                    $this->io->error(sprintf('[%d/3] Invalid HarmonyCMS Project ID provided, please try again', $step));
                    ++ $step;
                    if ($retries) {
                        usleep(100000);
                        continue;
                    }
                }
            }
        }

        return $this->activated = false;
    }

    /**
     * Update `composer.json` file with installed package.
     *
     * @throws \Exception
     */
    protected function updateComposer()
    {
        $json        = new JsonFile(Factory::getComposerFile());
        $contents    = file_get_contents($json->getPath());
        $manipulator = new JsonManipulator($contents);
        foreach ($this->operations as $operation) {
            if ('root-package' === $operation->getReason()) {
                $package = $operation->getPackage();
                $manipulator->addLink('require', $package->getName(), $package->getPrettyVersion(), true);
            }
        }
        file_put_contents($json->getPath(), $manipulator->getContents());

        $this->updateComposerLock();
    }

    /**
     * Update `composer.lock` after updating `composer.json` file.
     *
     * @throws \Exception
     */
    protected function updateComposerLock()
    {
        $lock                     = substr(Factory::getComposerFile(), 0, - 4) . 'lock';
        $composerJson             = file_get_contents(Factory::getComposerFile());
        $lockFile                 = new JsonFile($lock, null, $this->io);
        $locker                   = new Locker($this->io, $lockFile, $this->composer->getRepositoryManager(),
            $this->composer->getInstallationManager(), $composerJson);
        $lockData                 = $locker->getLockData();
        $lockData['content-hash'] = Locker::getContentHash($composerJson);
        $lockFile->write($lockData);
    }

    /**
     * @throws InvalidRepositoryException
     * @throws \Exception
     */
    protected function updateInstalledJson(): void
    {
        $vendorDir         = $this->composer->getConfig()->get('vendor-dir');
        $installedJsonFile = new JsonFile($vendorDir . '/composer/installed.json', null, $this->io);
        try {
            if (!is_array($installedJsonFile->read())) {
                throw new \UnexpectedValueException('Could not parse package list from the repository');
            }
        }
        catch (\Exception $e) {
            throw new InvalidRepositoryException('Invalid repository data in ' . $installedJsonFile->getPath() .
                ', packages could not be loaded: [' . get_class($e) . '] ' . $e->getMessage());
        }

        $dumper = new ArrayDumper();
        foreach ($this->composer->getRepositoryManager()
                     ->getLocalRepository()
                     ->getCanonicalPackages() as $canonicalPackage) {
            $data[] = $dumper->dump($canonicalPackage);
        }
        foreach ($this->operations as $operation) {
            $data[] = $dumper->dump($operation->getPackage());
        }

        usort($data, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });

        $installedJsonFile->write($data);
    }
}