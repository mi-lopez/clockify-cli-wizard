<?php

declare(strict_types=1);

namespace MiLopez\ClockifyWizard\Tests\Unit;

use MiLopez\ClockifyWizard\Commands\CreateTaskCommand;
use MiLopez\ClockifyWizard\Commands\LogTimeCommand;
use MiLopez\ClockifyWizard\Commands\MapCommand;
use MiLopez\ClockifyWizard\Commands\PauseCommand;
use MiLopez\ClockifyWizard\Commands\ResumeCommand;
use MiLopez\ClockifyWizard\Commands\StartCommand;
use MiLopez\ClockifyWizard\Commands\StopCommand;
use PHPUnit\Framework\TestCase;

/**
 * Guards the agent-mode contract: every command an AI agent drives must expose
 * --json so its output is machine-readable.
 */
class AgentModeOptionsTest extends TestCase
{
    public function testCreateTaskHasAgentOptions(): void
    {
        $def = (new CreateTaskCommand())->getDefinition();
        $this->assertTrue($def->hasOption('json'));
        $this->assertTrue($def->hasOption('dry-run'));
        $this->assertTrue($def->hasOption('project'));
    }

    public function testStartHasAgentOptions(): void
    {
        $def = (new StartCommand())->getDefinition();
        $this->assertTrue($def->hasOption('json'));
        $this->assertTrue($def->hasOption('dry-run'));
        $this->assertTrue($def->hasOption('tags'));
        $this->assertTrue($def->hasOption('force'));
    }

    public function testStopHasJsonOption(): void
    {
        $this->assertTrue((new StopCommand())->getDefinition()->hasOption('json'));
    }

    public function testLogHasAgentOptions(): void
    {
        $def = (new LogTimeCommand())->getDefinition();
        $this->assertTrue($def->hasOption('json'));
        $this->assertTrue($def->hasOption('dry-run'));
        $this->assertTrue($def->hasOption('tags'));
    }

    public function testPauseResumeMapHaveJsonOption(): void
    {
        $this->assertTrue((new PauseCommand())->getDefinition()->hasOption('json'));
        $this->assertTrue((new ResumeCommand())->getDefinition()->hasOption('json'));
        $this->assertTrue((new MapCommand())->getDefinition()->hasOption('json'));
    }
}
