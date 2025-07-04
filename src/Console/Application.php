<?php

declare(strict_types=1);

namespace MiLopez\ClockifyWizard\Console;

use MiLopez\ClockifyWizard\Commands\ConfigureCommand;
use MiLopez\ClockifyWizard\Commands\CreateTaskCommand;
use MiLopez\ClockifyWizard\Commands\ListTasksCommand;
use MiLopez\ClockifyWizard\Commands\LogTimeCommand;
use MiLopez\ClockifyWizard\Commands\ReportsCommand;
use MiLopez\ClockifyWizard\Commands\StartCommand;
use MiLopez\ClockifyWizard\Commands\StatusCommand;
use MiLopez\ClockifyWizard\Commands\StopCommand;
use MiLopez\ClockifyWizard\Commands\TodayCommand;
use MiLopez\ClockifyWizard\Commands\WeekCommand;
use Symfony\Component\Console\Application as BaseApplication;

class Application extends BaseApplication
{
    private const NAME = 'Clockify CLI Wizard';
    private const VERSION = '1.0.0';

    public function __construct()
    {
        parent::__construct(self::NAME, self::VERSION);

        $this->addCommands([
            new LogTimeCommand(),
            new StartCommand(),
            new StopCommand(),
            new StatusCommand(),
            new CreateTaskCommand(),
            new ListTasksCommand(),
            new TodayCommand(),
            new WeekCommand(),
            new ReportsCommand(),
            new ConfigureCommand(),
        ]);

        // Set the default command to log time
        $this->setDefaultCommand('log');
    }

    public function getLongVersion(): string
    {
        return sprintf(
            '%s <info>%s</info> by <comment>Miguel Lopez</comment>',
            $this->getName(),
            $this->getVersion()
        );
    }
}
