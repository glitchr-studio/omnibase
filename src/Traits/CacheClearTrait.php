<?php

namespace Base\Traits;

use Base\Console\Command\CacheClearCommand;
use Base\Database\Mapping\ClassMetadataFactory;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\WebpackEncoreBundle\Asset\EntrypointLookupInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Process\Process;

trait CacheClearTrait
{
    protected function checkCache(SymfonyStyle $io): void
    {
        $io->write("<info> [INFO] Cache directory:</info> " . $this->cacheDir . PHP_EOL, true);
    }

    protected function checkVirtualization(SymfonyStyle $io): void
    {
        $isDocker = file_exists('/.dockerenv') || (file_exists('/proc/1/cgroup') && strpos(file_get_contents('/proc/1/cgroup'), 'docker') !== false);

        if ($isDocker) {
            $io->write("<info> [INFO]</info> Docker environment used." . PHP_EOL, true);
        } else {
            $io->write("<warning> [WARNING] No Docker environment detected.</warning>" . PHP_EOL, true);
        }
    }

    protected function checkExtensions(SymfonyStyle $io): void
    {
        $extensions = [
            'xdebug' => 'Xdebug',
            'blackfire' => 'Blackfire',
            'git2' => 'Git',
            'amqp' => 'AMQP',
            'Zend OPcache' => 'OPcache',
            'apcu' => 'APCu',
            'igbinary' => 'Igbinary',
            'imagick' => 'Imagick',
            'gd' => 'GD',
        ];
    
        $io->write("<info> [INFO] PHP Extensions:</info> (cli and webserver extensions might differ)", true);

        
        $currentLineCount = 0;
        $lineBreakCount = (int) ceil(sqrt(count($extensions))); 
        $maxLength = max(array_map('strlen', $extensions));

        foreach ($extensions as $extension => $name) {
            // Default check if the extension is loaded
            $isLoaded = extension_loaded($extension);
    
            switch ($extension) {
                case 'apcu':
                    $isLoaded = $isLoaded && ini_get('apc.enabled');
                    break;
    
                case 'gd':
                    $isLoaded = $isLoaded && function_exists('gd_info') && gd_info();
                    break;
    
                case 'xdebug':
                    $isLoaded = $isLoaded && ini_get('xdebug.mode') !== '';
                    break;
    
                case 'blackfire':
                    $isLoaded = $isLoaded && getenv('BLACKFIRE_SERVER_ID') && getenv('BLACKFIRE_SERVER_TOKEN');
                    break;
    
                case 'Zend OPcache':
                    $isLoaded = $isLoaded && ini_get('opcache.enable') == 1;
                    break;
    
                case 'amqp':
                    $isLoaded = $isLoaded && class_exists('AMQPConnection');
                    break;
    
                case 'imagick':
                    $isLoaded = $isLoaded && class_exists('Imagick');
                    break;
    
                case 'igbinary':
                    $isLoaded = $isLoaded && function_exists('igbinary_serialize');
                    break;
    
                default:
                    // No special handling required for other extensions
                    break;
            }
    
            // Determine the status and output the result
            $status = $isLoaded ? '<info>✓</info>' : '<error>✗</error>';

            // Pad the extension name to align it based on the max length
            $paddedName = str_pad($name, $maxLength);

            // Output the result with aligned names
            $io->write("        [$status] $paddedName\t", ++$currentLineCount % $lineBreakCount == 0);

        }
    }    

    protected function customFeatureWarnings(SymfonyStyle $io): void
    {
        $io->write("", true);

        $useCustomLoader = $this->parameterBag->get("base.twig.use_custom");
        $useCustomReader = $this->parameterBag->get("base.attributes.use_custom");
        $useCustomRouter = $this->parameterBag->get("base.router.use_custom");
        $useMailer = $this->parameterBag->get("base.notifier.mailer");
        $useSettingBag = $this->parameterBag->get("base.parameter_bag.use_setting_bag");
        $useHotParameterBag = $this->parameterBag->get("base.parameter_bag.use_hot_bag");
        $useCustomDbFeatures = $this->parameterBag->get("base.database.use_custom");

        $table = new Table($io);
        $table
            ->setHeaders(['Base components', 'Option paths', ''])
            ->setRows([
                ['Twig loader', 'base.twig.use_custom', $useCustomLoader ? "<info>✓</info>" : "<error>✗</error>"],
                ['Attribute reader', 'base.attributes.use_custom',  $useCustomReader ? "<info>✓</info>" : "<error>✗</error>"],
                ['Setting bag', 'base.parameter_bag.use_setting_bag',  $useSettingBag ? "<info>✓</info>" : "<error>✗</error>"],
                ['Hot parameter bag', 'base.parameter_bag.use_hot_bag',  $useHotParameterBag ? "<info>✓</info>" : "<error>✗</error>"],
                ['Custom router', 'base.router.use_custom',  $useCustomRouter ? "<info>✓</info>" : "<error>✗</error>"],
                ['Custom database', 'base.database.use_custom',  $useCustomDbFeatures ? "<info>✓</info>" : "<error>✗</error>"],
                ['Mail notification', 'base.notifier.mail',  $useMailer ? "<info>✓</info>" : "<error>✗</error>"]
            ])
        ;
        $table->render();

        if ($useCustomRouter === true && $this->parameterBag->get("base.router.use_fallback") === true) {
            if ($this->parameterBag->get("base.router.fallback_warning") && !$this->router->getHostFallback()) {
                $io->warning("No host fallback configured in `base.yaml`" . PHP_EOL . "(configure 'base.router.fallbacks' to remove this message or disable `base.router.fallback_warning` warning).");
            }

            if ($this->parameterBag->get("base.database.fallback_warning") && !$this->entityManager->getMetadataFactory() instanceof ClassMetadataFactory) {
                $io->warning("Custom ClassMetadataFactory is configured. No fallback configured in `base.yaml`" . PHP_EOL . "(configure 'doctrine.orm.class_metadata_factory_name' to remove this message or disable `base.database.fallback_warning` warning).");
            }
        }
    }

    //
    // Check for node_modules directory
    protected function webpackCheck(SymfonyStyle $io): void
    {
        if (class_exists(EntrypointLookupInterface::class) && !is_dir($this->projectDir . "/var/modules") && !is_dir($this->projectDir . "/node_modules")) {
            $io->error(
                'Node package manager directory `' . $this->projectDir . "/node_modules" . '` is missing. ' . PHP_EOL .
                'Run `npm install` to setup your dependencies !'
            );
        }
    }

    protected function clearOPCache(SymfonyStyle $io): void
    {
        if (extension_loaded('Zend OPcache')) {
            $io->note("Zend OPcache Cleared (CLI)");
            \opcache_reset();
        }
    }
    
    //
    // Run second cache clear command
    protected function doubleCacheClear(SymfonyStyle $io)
    {
        $autoClear = $this->parameterBag->get("base.autoclear");
        if (CacheClearCommand::isFirstClear() && $autoClear) {

           $io->warning('Automatic double `cache:clear` is now running to account for base bundle features.');
           $clearProcess = new Process(['php', 'bin/console', 'cache:clear']);
           $clearProcess->setWorkingDirectory($this->projectDir);
           $clearProcess->mustRun();
        }

        return false;
    }

    //
    // Disk space and memory checks
    protected function diskAndMemoryCheck(SymfonyStyle $io): void
    {
        $freeSpace = disk_free_space(".");
        $diskSpace = disk_total_space(".");
        $remainingSpace = $diskSpace - $freeSpace;
        $percentSpace = round(100 * $remainingSpace / $diskSpace, 2);
        $diskSpaceStr = byte2str($freeSpace) . ' / ' . byte2str($diskSpace) . " available (" . $percentSpace . " % used)";

        $memoryLimit = str2dec(ini_get("memory_limit"));
        $memoryLimitStr = $memoryLimit > 1 ? byte2str($memoryLimit, array_slice(DECIMAL_PREFIX, 0, 3)) : "";

        if ($percentSpace > 95) {
            $fn = "error";
        } elseif ($percentSpace > 75) {
            $fn = "warning";
        } else {
            $fn = "info";
        }

        $io->write(" <$fn>[" . strtoupper($fn) . "] Disk space information:</$fn> " . $diskSpaceStr . PHP_EOL, true);
        if ($memoryLimit > 1) {

            if ($memoryLimit < str2dec("512M")) {
                $io->write(" <warning>[WARNING]</warning> Memory limit is very low.. Please consider increasing it", true);
                $io->write('PHP Memory limit: ' . $memoryLimitStr);
            } else {
                $io->write(" <info>[INFO] PHP Memory limit:</info> " . $memoryLimitStr . PHP_EOL, true);
            }
        }
    }

    //
    // PHP config check
    protected function phpConfigCheck(SymfonyStyle $io): void
    {
        $phpConfig = php_ini_loaded_file();
        $maxSize = UploadedFile::getMaxFilesize();
        $maxPathLength = constant("PHP_MAXPATHLEN");

        $io->note(
            "Loaded PHP Configuration: " . $phpConfig . PHP_EOL . "(might differ from webserver)\n" .
            "Maximum uploadable filesize: " . byte2str($maxSize, BINARY_PREFIX) . "\n" .
            "Maximum path length: " . $maxPathLength . " characters"
        );
    }

    protected function technicalSupportCheck(SymfonyStyle $io): void
    {
        //
        // Technical contact and language
        $technicalRecipient = $this->notifier->getTechnicalRecipient();
        if (is_stringeable($technicalRecipient)) {
            $io->note("Technical recipient configured: " . $technicalRecipient);
        }
    }

    /**
     * Generates or deletes a phpinfo.php file in the public directory.
     *
     * @param SymfonyStyle $io
     * @param bool $delete If true, deletes the file instead of generating it.
     */
    protected function generatePhpInfo(SymfonyStyle $io, bool $delete = false): void
    {
        $targetFile = $this->projectDir . '/public/phpinfo.php';

        // Detect storage backends if available
        $storageNames = method_exists($this->flysystem, 'getStorageNames')
            ? $this->flysystem->getStorageNames(false)
            : [];

        if (!is_file($targetFile)) {
            if (!empty($storageNames)) {
                $io->note(sprintf(
                    "PHP Info file will be generated in the public directory at '%s'.\nDetected storage backends: %s",
                    $targetFile,
                    implode(', ', $storageNames)
                ));
            }
        }

        $alreadyExists = is_file($targetFile);
        if ($delete) {
            if ($alreadyExists) {
                if (@unlink($targetFile)) {
                    $io->success(sprintf('phpinfo.php successfully removed from %s', $targetFile));
                } else {
                    $io->error(sprintf('Failed to remove phpinfo.php from %s', $targetFile));
                }
            }

            return;
        }

        $content = <<<'PHP'
<?php
use App\Kernel;

$_SERVER["APP_TIMER"] = microtime(true);
require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return function (array $context) {
    if (! (bool) ($context['APP_DEBUG'] ?? false)) {
        header("Location: .");
        exit;
    }

    phpinfo();
    phpinfo(INFO_MODULES);
};
PHP;

        // Try to write the file, handle errors
        if (@file_put_contents($targetFile, $content) === false) {
            $io->error(sprintf('Could not write phpinfo.php to %s', $targetFile));
            return;
        }

        if(!$alreadyExists && \file_exists($targetFile)) {
            $io->success(sprintf('phpinfo.php successfully generated at %s', $targetFile));
        }
    }

    protected function generateSymlinks(SymfonyStyle $io): void
    {
        //
        // Generate flysystem public symlink
        $storageNames = $this->flysystem->getStorageNames(false);
        if ($storageNames) {
            $io->note("Flysystem symlink(s) got generated in public directory.");
        }

        foreach ($storageNames as $storageName) {

            if (!$this->flysystem->hasStorage($storageName . ".public")) {
                continue;
            }

            $realPath = str_rstrip($this->flysystem->prefixPath("", $storageName), "/");

            $publicPath = $this->flysystem->getPublicRoot($storageName . ".public");
            $publicPath = str_rstrip($publicPath, "/");
            if ($realPath == $publicPath) {
                continue;
            }

            // Several processes clear the cache at once when a stack starts (the web
            // container and the worker): the link is replaced in one step
            // (symlink_atomic()), and what another process just did is not an error.
            // unlink() then symlink() let the second one die on "symlink(): File exists".
            $target = relative_path($realPath, dirname($publicPath));
            if (!is_link($publicPath) && file_exists($publicPath)) {

                if (is_dir($publicPath)) {
                    if (is_emptydir($publicPath)) {

                        if (!@rmdir($publicPath) && is_dir($publicPath) && !is_link($publicPath)) {
                            exit("Directory \"$publicPath\" exists, but you don't have the permissions.");
                        }

                    } else {
                        exit("Directory \"$publicPath\" exists and is not empty.\n");
                    }
                } elseif (is_file($publicPath)) {
                    @unlink($publicPath);
                } else {
                    exit("Cannot safely remove \"$publicPath\" — unknown file type.\n");
                }
            }

            if (!symlink_atomic($target, $publicPath)) {
                $io->warning(sprintf('The public link "%s" could not be made (to "%s").', $publicPath, $target));
            }
        }
    }
}
