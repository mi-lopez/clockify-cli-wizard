<?php

declare(strict_types=1);

namespace MiLopez\ClockifyWizard;

use Composer\Script\Event;

class Installer
{
    public static function postInstall(Event $event): void
    {
        $io = $event->getIO();

        $io->write('');
        $io->write('<info>🎉 Clockify CLI Wizard installed successfully!</info>');
        $io->write('');
        $io->write('<comment>Next steps:</comment>');
        $io->write('  1. Run: <info>./vendor/bin/clockify-wizard configure</info>');
        $io->write('  2. Set up your Clockify API key and workspace');
        $io->write('  3. Optionally configure Jira integration');
        $io->write('  4. Start tracking time: <info>./vendor/bin/clockify-wizard start</info>');
        $io->write('');
        $io->write('<comment>Documentation:</comment>');
        $io->write('  • Run <info>./vendor/bin/clockify-wizard --help</info> for available commands');
        $io->write('  • Check the README for detailed usage examples');
        $io->write('');
        $io->write('<comment>Quick start:</comment>');
        $io->write('  ./vendor/bin/clockify-wizard log 2h --auto   # Log 2 hours automatically');
        $io->write('  ./vendor/bin/clockify-wizard start           # Start a timer');
        $io->write('  ./vendor/bin/clockify-wizard today           # See today\'s summary');
        $io->write('');
    }

    public static function postUpdate(Event $event): void
    {
        $io = $event->getIO();

        $io->write('');
        $io->write('<info>✅ Clockify CLI Wizard updated successfully!</info>');
        $io->write('');
        $io->write('<comment>What\'s new in this version:</comment>');
        $io->write('  • Enhanced time parsing and suggestions');
        $io->write('  • Improved Git integration');
        $io->write('  • Better project mapping');
        $io->write('  • New reporting features');
        $io->write('');
        $io->write('<comment>Check your configuration:</comment>');
        $io->write('  Run: <info>./vendor/bin/clockify-wizard status</info>');
        $io->write('');
    }
}
