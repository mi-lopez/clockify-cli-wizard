<?php

declare(strict_types=1);

namespace MiLopez\ClockifyWizard\Commands;

use MiLopez\ClockifyWizard\Client\ClockifyClient;
use MiLopez\ClockifyWizard\Config\ConfigManager;
use MiLopez\ClockifyWizard\Helper\ConsoleHelper;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'list-tasks',
    description: 'List tasks from Clockify projects',
    aliases: ['tasks', 'ls']
)]
class ListTasksCommand extends Command
{
    private ConfigManager $configManager;

    private ?ClockifyClient $clockifyClient = null;

    public function __construct()
    {
        parent::__construct();
        $this->configManager = new ConfigManager();
    }

    protected function configure(): void
    {
        $this
            ->addOption('project', 'p', InputOption::VALUE_REQUIRED, 'Filter by project ID or name')
            ->addOption('status', 's', InputOption::VALUE_REQUIRED, 'Filter by status (ACTIVE/DONE)')
            ->addOption('search', null, InputOption::VALUE_REQUIRED, 'Search tasks by name')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format (table/json)', 'table')
            ->addOption('export', null, InputOption::VALUE_REQUIRED, 'Export to CSV file')
            ->setHelp('
List tasks from Clockify projects:

<info>Basic usage:</info>
  clockify-wizard list-tasks                    # All tasks
  clockify-wizard list-tasks --project "My Project"
  clockify-wizard list-tasks --status ACTIVE
  clockify-wizard list-tasks --search "CAM-"

<info>Output options:</info>
  clockify-wizard list-tasks --format json
  clockify-wizard list-tasks --export tasks.csv
            ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->initializeClient();

            $projectFilter = $input->getOption('project');
            $statusFilter = $input->getOption('status');
            $searchFilter = $input->getOption('search');
            $format = $input->getOption('format');
            $exportFile = $input->getOption('export');

            ConsoleHelper::displayHeader($output, 'Clockify Tasks');

            $projects = $this->getProjects($projectFilter);
            $allTasks = $this->getAllTasks($output, $projects, $statusFilter, $searchFilter);

            if (empty($allTasks)) {
                ConsoleHelper::displayInfo($output, 'No tasks found matching the criteria.');

                return Command::SUCCESS;
            }

            $this->displaySummary($output, $allTasks, $projects);

            switch ($format) {
                case 'json':
                    $this->outputJson($output, $allTasks);
                    break;
                case 'table':
                default:
                    $this->outputTable($output, $allTasks);
                    break;
            }

            if ($exportFile) {
                $this->exportToCsv($output, $allTasks, $exportFile);
            }

            return Command::SUCCESS;
        } catch (RuntimeException $e) {
            ConsoleHelper::displayError($output, $e->getMessage());

            return Command::FAILURE;
        }
    }

    private function initializeClient(): void
    {
        if (!$this->configManager->isConfigured()) {
            throw new RuntimeException('Clockify CLI is not configured. Run: clockify-wizard configure');
        }

        $clockifyConfig = $this->configManager->getClockifyConfig();
        $this->clockifyClient = new ClockifyClient(
            $clockifyConfig['api_key'],
            $clockifyConfig['workspace_id']
        );
    }

    private function getProjects(?string $projectFilter): array
    {
        $allProjects = $this->clockifyClient->getProjects();

        if (!$projectFilter) {
            return $allProjects;
        }

        $filteredProjects = [];
        foreach ($allProjects as $project) {
            if ($project['id'] === $projectFilter ||
                $project['name'] === $projectFilter ||
                stripos($project['name'], $projectFilter) !== false) {
                $filteredProjects[] = $project;
            }
        }

        if (empty($filteredProjects)) {
            throw new RuntimeException("No projects found matching: {$projectFilter}");
        }

        return $filteredProjects;
    }

    private function getAllTasks(OutputInterface $output, array $projects, ?string $statusFilter, ?string $searchFilter): array
    {
        $allTasks = [];

        foreach ($projects as $project) {
            ConsoleHelper::displayProgressBar($output, "Fetching tasks from {$project['name']}");

            try {
                $tasks = $this->clockifyClient->getTasks($project['id']);

                foreach ($tasks as $task) {
                    // Apply status filter
                    if ($statusFilter && $task['status'] !== strtoupper($statusFilter)) {
                        continue;
                    }

                    // Apply search filter
                    if ($searchFilter && stripos($task['name'], $searchFilter) === false) {
                        continue;
                    }

                    $task['project_name'] = $project['name'];
                    $task['project_id'] = $project['id'];
                    $allTasks[] = $task;
                }
            } catch (RuntimeException $e) {
                ConsoleHelper::displayWarning($output, "Could not fetch tasks from {$project['name']}: {$e->getMessage()}");
            }
        }

        ConsoleHelper::finishProgressBar($output);

        // Sort tasks by project name, then by task name
        usort($allTasks, function ($a, $b) {
            $projectCompare = strcmp($a['project_name'], $b['project_name']);
            if ($projectCompare !== 0) {
                return $projectCompare;
            }

            return strcmp($a['name'], $b['name']);
        });

        return $allTasks;
    }

    private function displaySummary(OutputInterface $output, array $tasks, array $projects): void
    {
        ConsoleHelper::displaySection($output, 'Summary', '📊');

        $output->writeln('📂 Projects: ' . count($projects));
        $output->writeln('🎯 Total tasks: ' . count($tasks));

        // Count by status
        $statusCount = [];
        foreach ($tasks as $task) {
            $status = $task['status'];
            $statusCount[$status] = ($statusCount[$status] ?? 0) + 1;
        }

        foreach ($statusCount as $status => $count) {
            $statusColor = $status === 'ACTIVE' ? 'green' : 'yellow';
            $output->writeln("📊 {$status}: <fg={$statusColor}>{$count}</>");
        }

        $output->writeln('');
    }

    private function outputTable(OutputInterface $output, array $tasks): void
    {
        $table = new Table($output);
        $table->setHeaders(['Project', 'Task Name', 'Status', 'ID']);

        foreach ($tasks as $task) {
            $statusColor = $task['status'] === 'ACTIVE' ? 'green' : 'yellow';

            $table->addRow([
                $task['project_name'],
                $this->truncateText($task['name'], 50),
                "<fg={$statusColor}>{$task['status']}</>",
                $task['id'],
            ]);
        }

        $table->render();
        $output->writeln('');
    }

    private function outputJson(OutputInterface $output, array $tasks): void
    {
        $jsonData = [];

        foreach ($tasks as $task) {
            $jsonData[] = [
                'id' => $task['id'],
                'name' => $task['name'],
                'status' => $task['status'],
                'project_id' => $task['project_id'],
                'project_name' => $task['project_name'],
            ];
        }

        $output->writeln(json_encode($jsonData, JSON_PRETTY_PRINT));
    }

    private function exportToCsv(OutputInterface $output, array $tasks, string $filename): void
    {
        ConsoleHelper::displayProgressBar($output, 'Exporting to CSV');

        $csvData = [];
        $csvData[] = ['Project ID', 'Project Name', 'Task ID', 'Task Name', 'Status'];

        foreach ($tasks as $task) {
            $csvData[] = [
                $task['project_id'],
                $task['project_name'],
                $task['id'],
                $task['name'],
                $task['status'],
            ];
        }

        $fp = fopen($filename, 'w');
        if (!$fp) {
            throw new RuntimeException("Cannot create file: {$filename}");
        }

        foreach ($csvData as $row) {
            fputcsv($fp, $row);
        }

        fclose($fp);

        ConsoleHelper::finishProgressBar($output);
        ConsoleHelper::displaySuccess($output, "Exported to {$filename}");
    }

    private function truncateText(string $text, int $length): string
    {
        if (strlen($text) <= $length) {
            return $text;
        }

        return substr($text, 0, $length - 3) . '...';
    }
}
