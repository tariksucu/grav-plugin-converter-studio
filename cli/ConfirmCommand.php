<?php

declare(strict_types=1);

namespace Grav\Plugin\Console;

use Composer\Autoload\ClassLoader;
use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\ConverterStudio\Job;
use Grav\Plugin\ConverterStudio\Services;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * bin/plugin converter-studio confirm: approves conversions that cost more credits than a file may
 * cost without asking, then finishes them.
 */
class ConfirmCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('confirm')
            ->setDescription('Approves Converter Studio conversions that wait because of their price')
            ->addArgument('id', InputArgument::IS_ARRAY, 'The conversions to approve (ids from the status command)')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Every conversion that waits for approval');
    }

    protected function serve(): int
    {
        $this->initializePlugins();
        self::autoload();
        $io = $this->getIO();
        $services = Services::fromGrav(Grav::instance());
        $store = $services->converter()->jobs();
        $jobs = [];
        if ($this->input->getOption('all')) {
            $jobs = array_values(array_filter($store->all(), static fn (Job $job): bool => $job->state() === Job::QUOTED));
        }
        foreach ((array) $this->input->getArgument('id') as $id) {
            $job = $store->get((string) $id);
            if ($job === null || $job->state() !== Job::QUOTED) {
                $io->error("{$id} is not a conversion that waits for approval.");
                return 1;
            }
            $jobs[] = $job;
        }
        if ($jobs === []) {
            $io->writeln('Nothing waits for approval.');
            return 0;
        }
        $failed = 0;
        foreach ($jobs as $job) {
            $job = $services->converter()->confirm($job, PHP_FLOAT_MAX);
            $io->writeln($job->describe());
            if ($job->state() === Job::FAILED) {
                $failed++;
            }
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
