<?php

declare(strict_types=1);

namespace MiLopez\ClockifyWizard\Tests\Unit;

use MiLopez\ClockifyWizard\Commands\ListCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class ListCommandTest extends TestCase
{
    private CommandTester $tester;

    protected function setUp(): void
    {
        $command = new ListCommand();
        $app = new Application();
        $app->add($command);
        $this->tester = new CommandTester($command);
    }

    public function testExposesExpectedResources(): void
    {
        $this->assertSame(
            ['projects', 'tasks', 'tags', 'workspaces', 'current'],
            ListCommand::RESOURCES
        );
    }

    public function testNoArgumentListsResourcesAsJson(): void
    {
        $this->tester->execute([]);

        $output = $this->tester->getDisplay();
        $data = json_decode($output, true);

        $this->assertIsArray($data);
        $this->assertSame(ListCommand::RESOURCES, $data['resources']);
        $this->assertSame(0, $this->tester->getStatusCode());
    }

    public function testUnknownResourceFails(): void
    {
        $this->tester->execute(['resource' => 'bananas']);

        $this->assertSame(1, $this->tester->getStatusCode());
        $this->assertStringContainsString('Unknown resource', $this->tester->getDisplay());
    }
}
