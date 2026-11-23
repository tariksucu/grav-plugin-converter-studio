<?php

declare(strict_types=1);

namespace Grav\Plugin\Console;

use Composer\Autoload\ClassLoader;
use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\ConverterStudio\Job;
use Grav\Plugin\ConverterStudio\Services;
use Symfony\Component\Console\Input\InputOption;

/**
 * bin/plugin converter-studio work: finishes the conversions an upload left running (what Grav's
 * scheduler does every minute), for sites without the scheduler.
 */
class WorkCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('work')
            ->setDescription('Finishes unfinished Converter Studio conversions (videos that outlast an upload)')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Stop after this many seconds (default: wait until all are done)');
    }

    protected function serve(): int
    {
        $this->initializePlugins();
        self::autoload();
        $io = $this->getIO();
        $services = Services::fromGrav(Grav::instance());
        $timeout = $this->input->getOption('timeout');
        $deadline = is_numeric($timeout) ? microtime(true) + max(10, (int) $timeout) : PHP_FLOAT_MAX;
        $failed = 0;
        $jobs = $services->worker()->pending($deadline, true, static function (Job $job) use ($io, &$failed): void {
            $io->writeln($job->describe());
            if ($job->state() === Job::FAILED) {
                $failed++;
            }
        });
        $services->converter()->jobs()->prune();
        if ($jobs === []) {
            $io->writeln('Nothing to finish.');
        }
        return $failed > 0 ? 1 : 0;
    }

    private static function autoload(): void
    {
        if (!class_exists(Services::class)) {
            $loader = new ClassLoader();
            $loader->addPsr4('Grav\\Plugin\\ConverterStudio\\', dirname(__DIR__) . '/classes/');
            $loader->register();
        }
    }
}
