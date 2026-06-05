<?php

declare(strict_types=1);

namespace MiLopez\ClockifyWizard\Commands;

use MiLopez\ClockifyWizard\Client\ClockifyClient;
use MiLopez\ClockifyWizard\Config\ConfigManager;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Machine-readable discovery for scripts and AI agents: lists Clockify
 * resources as JSON so an agent can find the project/task/tag ids it needs
 * before logging billable time. Distinct from `list-tasks`, which renders a
 * human-friendly table.
 */
#[AsCommand(
    name: 'list',
    description: 'List Clockify resources as JSON (for scripting/AI agents)'
)]
class ListCommand extends Command
{
    public const RESOURCES = ['projects', 'tasks', 'tags', 'workspaces', 'current'];

    private ConfigManager $configManager;

    public function __construct()
    {
        parent::__construct();
        $this->configManager = new ConfigManager();
    }

    protected function configure(): void
    {
        $resourceList = implode(', ', self::RESOURCES);

        $this
            ->addArgument('resource', InputArgument::OPTIONAL, "Resource to list. One of: {$resourceList}")
            ->addOption('project', 'p', InputOption::VALUE_REQUIRED, 'Project id or name (required for "tasks")')
            ->addOption('archived', null, InputOption::VALUE_NONE, 'Include archived items (projects/tags)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Accepted for consistency; this command always outputs JSON')
            ->setHelp(
                "Available resources: {$resourceList}\n\n"
                . "Examples:\n"
                . "  clockify-wizard list projects\n"
                . "  clockify-wizard list tasks --project \"My Project\"\n"
                . "  clockify-wizard list tags\n"
                . "  clockify-wizard list workspaces\n"
                . "  clockify-wizard list current\n"
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $resource = $input->getArgument('resource');

        if (!$resource) {
            $output->writeln(json_encode(['resources' => self::RESOURCES], JSON_PRETTY_PRINT));

            return Command::SUCCESS;
        }

        if (!in_array($resource, self::RESOURCES, true)) {
            $output->writeln('<error>Unknown resource \'' . $resource . '\'. Available: ' . implode(', ', self::RESOURCES) . '</error>');

            return Command::FAILURE;
        }

        if (!$this->configManager->isConfigured()) {
            $output->writeln('<error>Clockify CLI is not configured. Run: clockify-wizard configure</error>');

            return Command::FAILURE;
        }

        $clockifyConfig = $this->configManager->getClockifyConfig();
        $client = new ClockifyClient($clockifyConfig['api_key'], $clockifyConfig['workspace_id']);

        try {
            $data = $this->fetchResource($client, $resource, $input, $clockifyConfig);
        } catch (RuntimeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return Command::SUCCESS;
    }

    private function fetchResource(ClockifyClient $client, string $resource, InputInterface $input, array $clockifyConfig): array
    {
        $archived = (bool) $input->getOption('archived');

        switch ($resource) {
            case 'projects':
                return array_map(
                    static fn (array $p) => [
                        'id' => $p['id'],
                        'name' => $p['name'],
                        'clientName' => $p['clientName'] ?? null,
                        'archived' => $p['archived'] ?? false,
                    ],
                    $client->getProjects($archived)
                );

            case 'tasks':
                $projectRef = $input->getOption('project');
                if (!$projectRef) {
                    throw new RuntimeException('--project is required for tasks');
                }

                $project = $this->resolveProject($client, $projectRef);

                return array_map(
                    static fn (array $t) => [
                        'id' => $t['id'],
                        'name' => $t['name'],
                        'status' => $t['status'] ?? null,
                        'projectId' => $project['id'],
                    ],
                    $client->getTasks($project['id'])
                );

            case 'tags':
                return array_map(
                    static fn (array $t) => ['id' => $t['id'], 'name' => $t['name']],
                    $client->getTags($archived)
                );

            case 'workspaces':
                return array_map(
                    static fn (array $w) => ['id' => $w['id'], 'name' => $w['name']],
                    $client->getWorkspaces()
                );

            case 'current':
                $userId = $clockifyConfig['user_id'] ?? '';
                $current = $userId ? $client->getCurrentTimeEntryWithFallback($userId) : null;

                if (!$current) {
                    return ['running' => false, 'entry' => null];
                }

                return [
                    'running' => true,
                    'entry' => [
                        'id' => $current['id'],
                        'description' => $current['description'] ?? '',
                        'projectId' => $current['projectId'] ?? null,
                        'taskId' => $current['taskId'] ?? null,
                        'start' => $current['timeInterval']['start'] ?? null,
                        'tagIds' => $current['tagIds'] ?? [],
                    ],
                ];

            default:
                throw new RuntimeException("Unknown resource: {$resource}");
        }
    }

    private function resolveProject(ClockifyClient $client, string $needle): array
    {
        foreach ($client->getProjects() as $project) {
            if ($project['id'] === $needle || strcasecmp($project['name'], $needle) === 0) {
                return $project;
            }
        }

        throw new RuntimeException("Clockify project '{$needle}' not found");
    }
}
