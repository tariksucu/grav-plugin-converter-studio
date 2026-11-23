<?php

declare(strict_types=1);

namespace Grav\Plugin\Console;

use Composer\Autoload\ClassLoader;
use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\ConverterStudio\Cli;
use Grav\Plugin\ConverterStudio\Job;
use Grav\Plugin\ConverterStudio\Services;
use Grav\Plugin\ConverterStudio\Settings;

/**
 * bin/plugin converter-studio status: the account, the settings and the conversions in progress.
 */
class StatusCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('status')
            ->setDescription('Shows the Converter Studio account, settings and unfinished conversions');
    }

    protected function serve(): int
    {
        $this->initializePlugins();
        self::autoload();
        $io = $this->getIO();
        $services = Services::fromGrav(Grav::instance());
        $settings = $services->settings();

        $io->writeln('<info>Converter Studio</info> ' . ($settings->enabled() ? 'enabled' : '<red>disabled</red>'));
        $io->writeln('API: ' . $settings->apiUrl() . (getenv(Settings::URL_VARIABLE) ? ' (from ' . Settings::URL_VARIABLE . ')' : ''));
        $ignored = $settings->ignoredApiUrl();
        if ($ignored !== null) {
            $io->writeln('<yellow>api_url in the plugin settings (' . $ignored . ') is ignored: the key only goes to '
                . 'converter.studio, or to an address set in ' . Settings::URL_VARIABLE . ' in the environment.</yellow>');
        }
        if ($settings->apiKey() === null) {
            $io->writeln('API key: <red>none</red> (plugin settings, or ' . Settings::KEY_VARIABLE . ')');
        } else {
            $account = $services->converter()->client()->account();
            if ($account->ok()) {
                $renews = substr((string) ($account->get('renewsAt') ?? ''), 0, 10);
                $largest = $account->get('maxFileBytes');
                $io->writeln(sprintf(
                    'Account: %s plan, %s credits%s%s',
                    (string) $account->get('plan'),
                    (string) $account->get('credits'),
                    $renews !== '' ? ' (renews on ' . $renews . ')' : '',
                    is_numeric($largest) ? ', files up to ' . Cli::bytes((int) $largest) : '',
                ));
            } else {
                $io->writeln('<red>Account: ' . $account->message() . '</red>');
            }
        }
        $groups = $settings->uploadGroups();
        $io->writeln('Converted on upload: ' . ($groups === [] ? 'nothing (switch kinds on in the settings)' : implode(', ', array_map(
            static fn (string $group): string => $group . ' → ' . $settings->target($group),
            $groups,
        ))));
        $io->writeln('Originals: ' . $settings->originals() . '; up to ' . $settings->maxCredits() . ' credits a file without approval');
        $io->writeln('Scheduler: ' . ($settings->scheduler() ? 'finishes slow conversions (needs bin/grav scheduler in cron)' : 'off; run bin/plugin converter-studio work'));

        $jobs = $services->converter()->jobs()->all();
        $counts = array_count_values(array_map(static fn (Job $job): string => $job->state(), $jobs));
        $io->writeln(sprintf(
            'Conversions: %d running, %d waiting for approval, %d done, %d failed (last 30 days)',
            ($counts[Job::UPLOADING] ?? 0) + ($counts[Job::CONVERTING] ?? 0),
            $counts[Job::QUOTED] ?? 0,
            $counts[Job::DONE] ?? 0,
            $counts[Job::FAILED] ?? 0,
        ));
        $shown = 0;
        foreach (array_reverse($jobs) as $job) {
            if ($job->state() === Job::DONE || $shown >= 10) {
                continue;
            }
            $io->writeln('  ' . $job->id() . '  ' . $job->describe());
            $shown++;
        }
        return 0;
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
