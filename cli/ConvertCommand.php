<?php

declare(strict_types=1);

namespace Grav\Plugin\Console;

use Composer\Autoload\ClassLoader;
use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\ConverterStudio\Cli;
use Grav\Plugin\ConverterStudio\Job;
use Grav\Plugin\ConverterStudio\Rules;
use Grav\Plugin\ConverterStudio\Services;
use Grav\Plugin\ConverterStudio\Settings;
use Symfony\Component\Console\Input\InputOption;

/**
 * bin/plugin converter-studio convert: converts media that is already on the site.
 */
class ConvertCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        // Written out: the plugin's classes may not be loadable yet while commands are listed.
        $groups = 'heic, raw, tiff, video, office, audio';
        $this
            ->setName('convert')
            ->setDescription('Converts existing page media and site media with Converter Studio')
            ->addOption('page', 'p', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'A page route, such as /blog/my-post (repeatable)')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Every page')
            ->addOption('media', 'm', InputOption::VALUE_NONE, 'The site media folder (user/media)')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, "Which files: {$groups}, or all (default: the kinds switched on for uploads)")
            ->addOption('to', 't', InputOption::VALUE_REQUIRED, 'What they become, when their kind allows it (jpg, webp, png, mp4, webm)')
            ->addOption('originals', 'o', InputOption::VALUE_REQUIRED, 'keep (moved to user/data/converter-studio/originals), beside or delete (default: the settings)')
            ->addOption('max-credits', null, InputOption::VALUE_REQUIRED, 'Credits a file may cost without approval (default: the settings)')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Convert at most this many files')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Also files that already have a converted file beside them')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Seconds to wait for each file; the scheduler or the work command finishes the rest (default: wait)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only list the files')
            ->setHelp(<<<'HELP'
Converts files that are already on the site, which uploads never touched:

  <info>bin/plugin converter-studio convert --all --format=heic</info>
  <info>bin/plugin converter-studio convert --page=/blog/my-post --format=video --to=webm</info>
  <info>bin/plugin converter-studio convert --all --format=all --dry-run</info>

Each converted file is saved next to its original (photo.heic → photo.jpg). A file that
already has its converted file beside it is skipped unless --force.
HELP);
    }

    protected function serve(): int
    {
        $this->initializePlugins();
        self::autoload();
        $io = $this->getIO();
        $grav = Grav::instance();
        $services = Services::fromGrav($grav);
        $settings = $services->settings();
        if ($settings->apiKey() === null && !$this->input->getOption('dry-run')) {
            $io->error('No Converter Studio API key is set. Add one in the plugin settings, or set ' . Settings::KEY_VARIABLE . '.');
            return 1;
        }

        [$groups, $unknown] = Cli::groups((array) $this->input->getOption('format'));
        if ($unknown !== []) {
            $io->error('Unknown --format: ' . implode(', ', $unknown) . '. Use ' . implode(', ', array_keys(Rules::GROUPS)) . ' or all.');
            return 1;
        }
        if ($groups === []) {
            $groups = $settings->uploadGroups();
        }
        if ($groups === []) {
            $io->error('Say which files with --format (' . implode(', ', array_keys(Rules::GROUPS)) . ', or all).');
            return 1;
        }

        $folders = [];
        foreach ((array) $this->input->getOption('page') as $route) {
            $this->initializePages();
            $page = $grav['pages']->find('/' . ltrim((string) $route, '/'));
            if ($page === null || !$page->path()) {
                $io->error("No page at {$route}.");
                return 1;
            }
            $folders[] = (string) $page->path();
        }
        if ($this->input->getOption('all')) {
            $folders = array_merge($folders, Cli::pageFolders($grav));
        }
        if ($this->input->getOption('media')) {
            $folders = array_merge($folders, Cli::mediaFolders($grav));
        }
        $folders = array_values(array_unique($folders));
        if ($folders === []) {
            $io->error('Say where with --page=/route, --all or --media.');
            return 1;
        }

        $originals = $this->input->getOption('originals');
        if ($originals !== null && !in_array($originals, Settings::ORIGINALS, true)) {
            $io->error('--originals takes keep, beside or delete.');
            return 1;
        }
        $maxCredits = $this->input->getOption('max-credits');
        $maxCredits = is_numeric($maxCredits) ? max(0, (int) $maxCredits) : null;
        $to = $this->input->getOption('to');
        $targets = [];
        if (is_string($to) && $to !== '') {
            foreach ($groups as $group) {
                if (in_array(strtolower($to), Rules::GROUPS[$group]['to'], true)) {
                    $targets[$group] = strtolower($to);
                }
            }
            if ($targets === []) {
                $io->error("None of the chosen kinds converts to {$to}.");
                return 1;
            }
        }

        $worker = $services->worker();
        $found = $worker->existing($folders, $groups, $targets, (bool) $this->input->getOption('force'));
        $limit = $this->input->getOption('limit');
        if (is_numeric($limit)) {
            $found = array_slice($found, 0, max(0, (int) $limit));
        }
        if ($found === []) {
            $io->writeln('Nothing to convert.');
            return 0;
        }

        $root = $services->userDir() . '/';
        if ($this->input->getOption('dry-run')) {
            foreach ($found as $item) {
                $io->writeln(sprintf('%s → %s (%s)', substr($item['file'], strlen($root)), $item['to'], Cli::bytes((int) filesize($item['file']))));
            }
            $io->writeln(sprintf('%d file%s would be converted.', count($found), count($found) === 1 ? '' : 's'));
            return 0;
        }

        $timeout = $this->input->getOption('timeout');
        $failed = 0;
        foreach ($found as $item) {
            $job = $worker->jobFor($item['file'], $item['group'], $targets[$item['group']] ?? null, $originals, $maxCredits);
            if ($job === null) {
                continue;
            }
            $io->write(substr($item['file'], strlen($root)) . ' … ');
            $deadline = is_numeric($timeout) ? microtime(true) + max(10, (int) $timeout) : PHP_FLOAT_MAX;
            if ($job->pending()) {
                $job = $services->converter()->run($job, $deadline);
            }
            $io->writeln($job->describe());
            if ($job->state() === Job::FAILED || $job->state() === Job::QUOTED) {
                $failed++;
            }
        }
        return $failed > 0 ? 1 : 0;
    }

    /**
     * The plugin's classes, also when the plugin is switched off (no autoloader then).
     */
    private static function autoload(): void
    {
        if (!class_exists(Services::class)) {
            $loader = new ClassLoader();
            $loader->addPsr4('Grav\\Plugin\\ConverterStudio\\', dirname(__DIR__) . '/classes/');
            $loader->register();
        }
    }
}
