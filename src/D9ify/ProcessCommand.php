<?php

namespace D9ify;

use Composer\IO\IOInterface;
use D9ify\Exceptions\D9ifyExceptionBase;
use D9ify\Site\Directory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Yaml\Yaml;

/**
 * Class ProcessCommand
 *
 *
 *
 * @package D9ify
 */
class ProcessCommand extends Command
{

    /**
     * @var string
     */
    public static $HELP_TEXT = [
        "*******************************************************************************",
        "* THIS SCRIPT IS IN ALPHA VERSION STATUS AND AT THIS POINT HAS VERY LITTLE    *",
        "* ERROR CHECKING. PLEASE USE AT YOUR OWN RISK.                                *",
        "* The guide to use this file is in /README.md                                 *",
        "*******************************************************************************",
    ];

    /**
     * @var \Composer\IO\IOInterface|null
     */
    protected ?IOInterface $composerIOInterface = null;

    /**
     * @var string
     */
    protected static $defaultName = 'd9ify';
    /**
     * @var \D9ify\Site\Directory
     */
    protected Directory $sourceDirectory;
    /**
     * @var \D9ify\Site\Directory
     */
    protected Directory $destinationDirectory;

    /**
     * @return \D9ify\Site\Directory
     */
    public function getSourceDirectory(): Directory
    {
        return $this->sourceDirectory;
    }

    /**
     * @param \D9ify\Site\Directory $sourceDirectory
     */
    public function setSourceDirectory(Directory $sourceDirectory): void
    {
        $this->sourceDirectory = $sourceDirectory;
    }

    /**
     *
     */
    protected function configure()
    {
        $this
            ->setName('d9ify')
            ->setDescription('The magic d9ificiation machine')
            ->addArgument('source', InputArgument::REQUIRED, 'The pantheon site name or ID of the site')
            ->setHelp(static::$HELP_TEXT)
            ->setDefinition(new InputDefinition([
                new InputArgument(
                    'source',
                    InputArgument::REQUIRED,
                    "Pantheon Site Name or Site ID of the source"
                ),
                new InputArgument(
                    'destination',
                    InputArgument::OPTIONAL,
                    "Pantheon Site Name or Site ID of the destination"
                ),
            ]));
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     *
     * @return int|void
     * @throws \JsonException
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            $output->writeln(static::$HELP_TEXT);
            /**
             * Step 1. Set Source and Destination.
             *
             * Source Param is not optional and needs to be
             * a pantheon site ID or name.
             *
             */
            $this->setSourceDirectory(
                Directory::factory(
                    $input->getArgument('source'),
                    $output
                )
            );
            /**
             * Step 1b. Grab the org if there is one
             */
            $org = $this->getSourceDirectory()->getInfo()->getOrganization();
            /**
             * Step 1c. Do the same for destination
             *
             * Destination name will be {source}-{THIS YEAR} by default
             * if you don't provide a value. Destination name will be
             * {source}-{THIS YEAR} by default if you don't provide a value.
             *
             */
            $this->setDestinationDirectory(
                Directory::factory(
                    $input->getArgument('destination') ??
                    $this->sourceDirectory->getSiteInfo()->getName() . "-" . date('Y'),
                    $output,
                    $org
                )
            );
            /**
             * Step 2: Clone Source & Destination.
             *
             * Clone both sites to folders inside this root directory.
             */
            $this->getSourceDirectory()->ensure(false);
            $this->getDestinationDirectory()->ensure(true);
            $this->copyRepositoriesFromSource($input, $output);
            /**
             * Step 3: Move over Contrib
             *
             * Spelunk the old site for MODULE.info.yaml and after reading
             * those files.
             */
            $this->updateDestModulesAndThemesFromSource($input, $output);
            /**
             * Step 4: web/libraries folder (JS contrib/drupal libraries)
             *
             * Process /libraries folder if exists & Add ES Libraries to the composer
             * install payload
             */
            $this->updateDestEsLibrariesFromSource($input, $output);
            /**
             * Step 5: ...and GO!
             *
             * Write the composer file .
             */
            $this->writeComposer($input, $output);
            /**
             * Step 6: Attempt to do an composer install
             *
             * Exception will be thrown if install fails.
             */
            $this->getDestinationDirectory()->install($output);
            /**
             * Step 7: Custom Code
             *
             * This step looks for {MODULENAME}.info.yml files that also have "custom"
             * in the path. If they have THEME in the path it copies them to web/themes/custom.
             * If they have "module" in the path, it copies the folder to web/modules/custom.
             */
            $this->copyCustomCode($input, $output);
            // TODO:
            // 1. Spelunk custom code in new site and fix module
            //    version numbers (+ ^9) if necessary.
            // 1. Copy config files.
            // 1. commit-push code/config/composer additions.
            // 1. Rsync remote files to local directory
            // 1. Rsync remote files back up to new site
            // 1. Download database backup
            // 1. Restore Database backup to new site
        } catch (D9ifyExceptionBase $d9ifyException) {
            // TODO: Composer install exception help text
            $output->writeln((string) $d9ifyException);
            exit(1);
        } catch (\Exception $e) {
            // TODO: General help text and how to restart the process
            $output->writeln("Script ended in Exception state. " . $e->getMessage());
            $output->writeln($e->getTraceAsString());
            exit(1);
        } catch (\Throwable $t) {
            // TODO: General help text and how to restart the process
            $output->write("Script ended in error state. " . $t->getMessage());
            $output->writeln($t->getTraceAsString());
            exit(1);
        }
        exit(0);
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    protected function copyRepositoriesFromSource(InputInterface $input, OutputInterface $output)
    {
        $this->destinationDirectory->getComposerObject()->setRepositories(
            $this->sourceDirectory->getComposerObject()->getOriginal()['repositories'] ?? []
        );
        //$output->writeln(print_r($this->destinationDirectory->getComposerObject()->__toArray(), true));
    }

    /**
     * @description This script searches for every {modulename}.info.yml. If that
     * file has a 'project' proerty (i.e. it's been thru the automated services at
     * drupal.org), it records that property and version number and ensures
     * those values are in the composer.json 'require' array. Your old composer
     * file will re renamed backup-*-composer.json.
     *
     *
     *
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     *
     */
    protected function updateDestModulesAndThemesFromSource(InputInterface $input, OutputInterface $output)
    {
        $infoFiles = $this->sourceDirectory->spelunkFilesFromRegex('/(\.info\.yml|\.info\.yaml?)/', $output);
        $toMerge = [];
        $composerFile = $this->getDestinationDirectory()
            ->getComposerObject();
        foreach ($infoFiles as $fileName => $fileInfo) {
            $contents = file_get_contents($fileName);
            preg_match('/project\:\ ?\'(.*)\'$/m', $contents, $projectMatches);
            preg_match('/version\:\ ?\'(.*)\'$/m', $contents, $versionMatches);
            if (is_array($projectMatches) && isset($projectMatches[1])) {
                if ($projectMatches[1]) {
                        $composerFile->addRequirement(
                            "drupal/" . $projectMatches[1],
                            "^" . str_replace("8.x-", "", $versionMatches[1])
                        );
                }
            }
        }
        $output->write(PHP_EOL);
        $output->write(PHP_EOL);
        $output->writeln([
            "*******************************************************************************",
            "* Found new Modules & themes from the source site:                            *",
            "*******************************************************************************",
            print_r($composerFile->getDiff(), true)
        ]);
        return 0;
    }

    /**
     * @return \D9ify\Site\Directory
     */
    public function getDestinationDirectory(): Directory
    {
        return $this->destinationDirectory;
    }

    /**
     * @param \D9ify\Site\Directory $destinationDirectory
     */
    public function setDestinationDirectory(Directory $destinationDirectory): void
    {
        $this->destinationDirectory = $destinationDirectory;
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     *
     * @throws \JsonException
     */
    protected function updateDestEsLibrariesFromSource(InputInterface $input, OutputInterface $output)
    {
        $fileList = $this->sourceDirectory->spelunkFilesFromRegex('/libraries\/[0-9a-z-]*\/(package\.json$)/', $output);
        $repos = $this->sourceDirectory->getComposerObject()->getOriginal()['repositories'];
        $composerFile = $this->getDestinationDirectory()->getComposerObject();
        foreach ($fileList as $key => $file) {
            $package = \json_decode(file_get_contents($file->getRealPath()), true, 10, JSON_THROW_ON_ERROR);
            $repoString = (string) $package['name'];
            if (empty($repoString)) {
                $repoString = is_string($package['repository']) ?
                    $package['repository'] : $package['repository']['url'];
            }
            if (empty($repoString) || is_array($repoString)) {
                $output->writeln([
                    "*******************************************************************************",
                    "* Skipping the file below because the package.json file does not have         *",
                    "* a 'name' or 'repository' property. Add it by hand to the composer file.     *",
                    "* like so: \"npm-asset/{npm-registry-name}\": \"{Version Spec}\" in           *",
                    "* the REQUIRE section. Search for the id on https://www.npmjs.com             *",
                    "*******************************************************************************",
                    $file->getRealPath(),
                ]);
                continue;
            }
            $array = explode("/", $repoString);
            $libraryName = @array_pop($array);
            if (isset($repos[$libraryName])) {
                $composerFile->addRequirement(
                    $repos[$libraryName]['package']['name'],
                    $repos[$libraryName]['package']['version']
                );
                continue;
            }
            if ($libraryName !== "") {
                // Last ditch guess:
                $composerFile->addRequirement("npm-asset/" . $libraryName, "^" . $package['version']);
            }
        }
        $installPaths = $composerFile->getExtraProperty('installer-paths');
        if (!isset($installPaths['web/libraries/{$name}'])) {
            $installPaths['web/libraries/{$name}'] = [];
        }
        $installPaths['web/libraries/{$name}'] = array_unique(
            array_merge($installPaths['web/libraries/{$name}'] ?? [], [
                "type:bower-asset",
                "type:npm-asset",
            ])
        );

        $composerFile->setExtraProperty('installer-paths', $installPaths);
        $installerTypes = $composerFile->getExtraProperty('installer-types') ?? [];
        $composerFile->setExtraProperty(
            'installer-types',
            array_unique(
                array_merge($installerTypes, [
                    "bower-asset",
                    "npm-asset",
                    "library",
                ])
            )
        );
        $output->write(PHP_EOL);
        $output->write(PHP_EOL);
        $output->writeln([
            "*******************************************************************************",
            "* Found new ESLibraries from the source site:                                 *",
            "*******************************************************************************",
            print_r($composerFile->getDiff(), true)
            ]);
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     *
     * @return int|mixed
     */
    protected function writeComposer(InputInterface $input, OutputInterface $output)
    {
        $output->writeln([
            "*********************************************************************",
            "* These changes are being applied to the destination site composer: *",
            "*********************************************************************",
        ]);
        $output->writeln(print_r($this->destinationDirectory
                                     ->getComposerObject()
                                     ->getDiff(), true));
        $output->writeln(
            sprintf(
                "Write these changes to the composer file at %s?",
                $this->destinationDirectory
                    ->getComposerObject()
                    ->getRealPath()
            )
        );
        $this->destinationDirectory
            ->getComposerObject()
            ->backupFile();
        $question = new ConfirmationQuestion(" Type '(y)es' to continue: ", false);
        $helper = $this->getHelper('question');
        if ($helper->ask($input, $output, $question)) {
            return $this->getDestinationDirectory()
                ->getComposerObject()
                ->write();
        }
        $output->writeln("The composer Files were not changed");
        return 0;
    }

    /**
     * @return IOInterface|null
     */
    public function getComposerIOInterface(): ?IOInterface
    {
        return $this->composerIOInterface;
    }

    /**
     * @param IOInterface $composerIOInterface
     */
    public function setComposerIOInterface(IOInterface $composerIOInterface): void
    {
        $this->composerIOInterface = $composerIOInterface;
    }

    /**
     *
     */
    public function copyCustomCode(InputInterface $input, OutputInterface $output) :bool
    {
        $this->getDestinationDirectory()->ensureCustomCodeFoldersExist($input, $output);
        $failure_list = [];
        $infoFiles = $this
            ->sourceDirectory
            ->spelunkFilesFromRegex('/(\.info\.yml|\.info\.yaml?)/', $output);
        foreach ($infoFiles as $fileName => $fileInfo) {
            try {
                $contents = Yaml::parse(file_get_contents($fileName));
            } catch (\Exception $exception) {
                if ($output->isVerbose()) {
                    $output->writeln($exception->getTraceAsString());
                }
                continue;
            }
            if (!isset($contents['type'])) {
                continue;
            }
            $sourceDir = dirname($fileInfo->getRealPath());
            switch ($contents['type']) {
                case "module":
                    $destination = $this->getDestinationDirectory()->getClonePath() . "/web/sites/modules/custom";
                    break;

                case "theme":
                    $destination = $this->getDestinationDirectory()->getClonePath() . "/web/sites/themes/custom";
                    break;

                default:
                    continue 2;
            }
            $command = sprintf(
                "cp -Rf %s %s",
                $sourceDir,
                $destination
            );
            if ($output->isVerbose()) {
                $output->writeln($command);
            }
            exec(
                $command,
                $result,
                $status
            );
            if ($status !== 0) {
                $failure_list[$fileName] = $result;
            }
        }
        $output->write(PHP_EOL);
        $output->write(PHP_EOL);
        $failures = count($failure_list);
        $output->writeln(sprintf("Copy operations are complete with %d errors.", $failures));
        if ($failures) {
            $toWrite = [
                    "*******************************************************************************",
                    "* The following files had an error while copying. You will need to inspect    *",
                    "* The folders by hand or diff them. I'm not saying the folders weren't copied.*",
                    "* I'm saying I'm not sure they were copied in-tact. Double check the contents *",
                    "* They might have errored on a single file which would stop the copy.         *",
                    "* If you want to see the complete output from the copies, run this command    *",
                    "* with the --verbose switch.                                                  *",
                    "*******************************************************************************",
                ] + array_keys($failure_list);
            $output->writeln($toWrite);
            if ($output->isVerbose()) {
                $output->write(print_r($failure_list, true));
            }
        }
        return true;
    }
}
