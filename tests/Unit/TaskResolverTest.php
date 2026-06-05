<?php

declare(strict_types=1);

namespace MiLopez\ClockifyWizard\Tests\Unit;

use MiLopez\ClockifyWizard\Client\ClockifyClient;
use MiLopez\ClockifyWizard\Config\ConfigManager;
use MiLopez\ClockifyWizard\Service\TaskResolver;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TaskResolverTest extends TestCase
{
    private string $configPath;

    private ConfigManager $config;

    protected function setUp(): void
    {
        $this->configPath = sys_get_temp_dir() . '/clockify-test-' . uniqid() . '.json';
        $this->config = new ConfigManager($this->configPath);
    }

    protected function tearDown(): void
    {
        @unlink($this->configPath);
    }

    public function testResolvesProjectFromOptionAndPersistsMapping(): void
    {
        $resolver = new TaskResolver($this->fakeClient(), $this->config, null);

        $result = $resolver->resolve('CAM-451', 'My Project');

        $this->assertSame('p1', $result['clockify_project']['id']);
        $this->assertSame('t1', $result['clockify_task']['id']);
        $this->assertSame('CAM-451', $result['clockify_task']['name']);
        // The mapping is persisted so the next call needs no --project.
        $this->assertSame('p1', $this->config->getClockifyProjectForJira('CAM'));
    }

    public function testResolvesProjectFromSavedMapping(): void
    {
        $this->config->addProjectMapping('CAM', 'p1');
        $resolver = new TaskResolver($this->fakeClient(), $this->config, null);

        $result = $resolver->resolve('CAM-451');

        $this->assertSame('p1', $result['clockify_project']['id']);
    }

    public function testThrowsWhenProjectCannotBeResolved(): void
    {
        $resolver = new TaskResolver($this->fakeClient(), $this->config, null);

        $this->expectException(RuntimeException::class);
        $resolver->resolve('CAM-451');
    }

    public function testUnknownProjectOptionThrows(): void
    {
        $resolver = new TaskResolver($this->fakeClient(), $this->config, null);

        $this->expectException(RuntimeException::class);
        $resolver->resolve('CAM-451', 'Does Not Exist');
    }

    private function fakeClient(): ClockifyClient
    {
        return new class ('k', 'w') extends ClockifyClient {
            public function getProjects(bool $includeArchived = false): array
            {
                return [
                    ['id' => 'p1', 'name' => 'My Project'],
                    ['id' => 'p2', 'name' => 'Other'],
                ];
            }

            public function findTask(string $projectId, string $taskName): ?array
            {
                return null;
            }

            public function createTask(string $projectId, string $name, ?string $status = 'ACTIVE'): array
            {
                return ['id' => 't1', 'name' => $name, 'status' => $status];
            }

            public function resolveTagIds(array $tagNames): array
            {
                return [];
            }
        };
    }
}
