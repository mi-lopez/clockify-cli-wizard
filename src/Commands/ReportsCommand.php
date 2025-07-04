<?php

declare(strict_types=1);

namespace MiLopez\ClockifyWizard\Commands;

use Carbon\Carbon;
use MiLopez\ClockifyWizard\Client\ClockifyClient;
use MiLopez\ClockifyWizard\Config\ConfigManager;
use MiLopez\ClockifyWizard\Helper\ConsoleHelper;
use MiLopez\ClockifyWizard\Helper\TimeHelper;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'reports',
    description: 'Generate time tracking reports',
    aliases: ['report']
)]
class ReportsCommand extends Command
{
    private ConfigManager $configManager;

    private ?ClockifyClient $clockifyClient = null;

    private array $projectsCache = [];

    private array $tasksCache = [];

    public function __construct()
    {
        parent::__construct();
        $this->configManager = new ConfigManager();
    }

    protected function configure(): void
    {
        $this
            ->addOption('period', 'p', InputOption::VALUE_REQUIRED, 'Report period (today/week/month/custom)', 'week')
            ->addOption('start', 's', InputOption::VALUE_REQUIRED, 'Start date (YYYY-MM-DD)')
            ->addOption('end', 'e', InputOption::VALUE_REQUIRED, 'End date (YYYY-MM-DD)')
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'Filter by project')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format (table/csv/json)', 'table')
            ->addOption('group-by', 'g', InputOption::VALUE_REQUIRED, 'Group by (project/task/date)', 'project')
            ->addOption('export', null, InputOption::VALUE_REQUIRED, 'Export to file')
            ->setHelp('
Generate detailed time tracking reports:

<info>Basic usage:</info>
  clockify-wizard reports                           # Current week
  clockify-wizard reports --period month            # Current month
  clockify-wizard reports --period today            # Today only
  clockify-wizard reports --period custom --start 2024-01-01 --end 2024-01-31

<info>Filtering and grouping:</info>
  clockify-wizard reports --project "My Project"
  clockify-wizard reports --group-by task
  clockify-wizard reports --group-by date

<info>Export options:</info>
  clockify-wizard reports --format csv --export report.csv
  clockify-wizard reports --format json --export report.json
            ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->initializeClient();

            $period = $input->getOption('period');
            $startDate = $input->getOption('start');
            $endDate = $input->getOption('end');
            $projectFilter = $input->getOption('project');
            $format = $input->getOption('format');
            $groupBy = $input->getOption('group-by');
            $exportFile = $input->getOption('export');

            ConsoleHelper::displayHeader($output, 'Time Tracking Reports');

            // Determine date range
            [$start, $end] = $this->getDateRange($period, $startDate, $endDate);

            $this->displayReportInfo($output, $start, $end, $projectFilter, $groupBy);

            // Fetch time entries
            $timeEntries = $this->getTimeEntries($start, $end, $projectFilter);

            if (empty($timeEntries)) {
                ConsoleHelper::displayInfo($output, 'No time entries found for the specified criteria.');

                return Command::SUCCESS;
            }

            // Expand time entries with project and task information
            $expandedEntries = $this->expandTimeEntries($output, $timeEntries);

            // Generate report
            $reportData = $this->generateReport($expandedEntries, $groupBy);

            // Display summary
            $this->displaySummary($output, $expandedEntries, $start, $end);

            // Output report
            switch ($format) {
                case 'csv':
                    $this->outputCsv($output, $reportData, $groupBy, $exportFile);
                    break;
                case 'json':
                    $this->outputJson($output, $reportData, $exportFile);
                    break;
                case 'table':
                default:
                    $this->outputTable($output, $reportData, $groupBy);
                    break;
            }

            if ($exportFile && $format === 'table') {
                $this->exportToCsv($output, $reportData, $exportFile, $groupBy);
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

    private function expandTimeEntries(OutputInterface $output, array $timeEntries): array
    {
        if (empty($timeEntries)) {
            return [];
        }

        ConsoleHelper::displayProgressBar($output, 'Loading project and task information');

        // Cache all projects first
        $this->loadProjectsCache();

        $expandedEntries = [];
        $processedCount = 0;
        $totalCount = count($timeEntries);

        foreach ($timeEntries as $entry) {
            $processedCount++;

            // Show progress every 20 entries
            if ($processedCount % 20 === 0) {
                ConsoleHelper::clearLine($output);
                ConsoleHelper::displayProgressBar($output, "Processing entries ({$processedCount}/{$totalCount})");
            }

            $expandedEntry = $this->expandSingleEntry($entry);
            $expandedEntries[] = $expandedEntry;
        }

        ConsoleHelper::finishProgressBar($output);

        return $expandedEntries;
    }

    private function loadProjectsCache(): void
    {
        if (empty($this->projectsCache)) {
            $projects = $this->clockifyClient->getProjects();
            foreach ($projects as $project) {
                $this->projectsCache[$project['id']] = $project;
            }
        }
    }

    private function expandSingleEntry(array $entry): array
    {
        // Handle different API response formats
        $projectId = $entry['projectId'] ?? $entry['project']['id'] ?? null;
        $taskId = $entry['taskId'] ?? $entry['task']['id'] ?? null;

        // Get project information
        if ($projectId && isset($this->projectsCache[$projectId])) {
            $entry['project'] = $this->projectsCache[$projectId];
        } elseif (!isset($entry['project']) || empty($entry['project']['name'])) {
            $entry['project'] = [
                'id' => $projectId ?? 'unknown',
                'name' => 'Unknown Project',
                'color' => '#666666',
            ];
        }

        // Get task information if needed
        if ($taskId && !isset($entry['task']['name'])) {
            $taskInfo = $this->getTaskInfo($projectId, $taskId);
            if ($taskInfo) {
                $entry['task'] = $taskInfo;
            } else {
                $entry['task'] = [
                    'id' => $taskId,
                    'name' => 'Unknown Task',
                ];
            }
        } elseif (!isset($entry['task'])) {
            $entry['task'] = [
                'id' => null,
                'name' => null,
            ];
        }

        return $entry;
    }

    private function getTaskInfo(?string $projectId, string $taskId): ?array
    {
        if (!$projectId) {
            return null;
        }

        $cacheKey = "{$projectId}:{$taskId}";

        if (!isset($this->tasksCache[$cacheKey])) {
            try {
                $tasks = $this->clockifyClient->getTasks($projectId);
                foreach ($tasks as $task) {
                    $this->tasksCache["{$projectId}:{$task['id']}"] = $task;
                }
            } catch (RuntimeException $e) {
                // If we can't get tasks, cache empty result to avoid repeated requests
                $this->tasksCache[$cacheKey] = null;

                return null;
            }
        }

        return $this->tasksCache[$cacheKey] ?? null;
    }

    private function getDateRange(string $period, ?string $startDate, ?string $endDate): array
    {
        switch ($period) {
            case 'today':
                $start = Carbon::today();
                $end = Carbon::tomorrow();
                break;

            case 'week':
                $start = Carbon::now()->startOfWeek();
                $end = Carbon::now()->endOfWeek();
                break;

            case 'month':
                $start = Carbon::now()->startOfMonth();
                $end = Carbon::now()->endOfMonth();
                break;

            case 'custom':
                if (!$startDate || !$endDate) {
                    throw new RuntimeException('Custom period requires --start and --end dates');
                }
                $start = Carbon::createFromFormat('Y-m-d', $startDate)->startOfDay();
                $end = Carbon::createFromFormat('Y-m-d', $endDate)->endOfDay();
                break;

            default:
                throw new RuntimeException("Invalid period: {$period}. Use: today, week, month, or custom");
        }

        return [$start, $end];
    }

    private function displayReportInfo(OutputInterface $output, Carbon $start, Carbon $end, ?string $projectFilter, string $groupBy): void
    {
        $output->writeln("📅 Period: {$start->format('M j, Y')} - {$end->format('M j, Y')}");
        $output->writeln("📊 Grouped by: <fg=cyan>{$groupBy}</>");

        if ($projectFilter) {
            $output->writeln("📁 Project filter: <fg=yellow>{$projectFilter}</>");
        }

        $output->writeln('');
    }

    private function getTimeEntries(Carbon $start, Carbon $end, ?string $projectFilter): array
    {
        $clockifyConfig = $this->configManager->getClockifyConfig();
        $timeEntries = $this->clockifyClient->getTimeEntries(
            $clockifyConfig['user_id'],
            $start,
            $end
        );

        if ($projectFilter) {
            $timeEntries = array_filter($timeEntries, function ($entry) use ($projectFilter) {
                // Check both expanded and non-expanded formats
                $projectName = $entry['project']['name'] ?? 'Unknown Project';
                $projectId = $entry['project']['id'] ?? $entry['projectId'] ?? '';

                return $projectName === $projectFilter ||
                    $projectId === $projectFilter ||
                    stripos($projectName, $projectFilter) !== false;
            });
        }

        return $timeEntries;
    }

    private function generateReport(array $timeEntries, string $groupBy): array
    {
        $reportData = [];

        foreach ($timeEntries as $entry) {
            $start = Carbon::parse($entry['timeInterval']['start'])->utc();
            $end = isset($entry['timeInterval']['end'])
                ? Carbon::parse($entry['timeInterval']['end'])->utc()
                : Carbon::now()->utc();

            $duration = abs($end->diffInMinutes($start));

            $groupKey = $this->getGroupKey($entry, $groupBy, $start);

            if (!isset($reportData[$groupKey])) {
                $reportData[$groupKey] = [
                    'key' => $groupKey,
                    'minutes' => 0,
                    'entries' => 0,
                    'first_entry' => $entry['timeInterval']['start'],
                    'last_entry' => $entry['timeInterval']['end'] ?? Carbon::now()->toISOString(),
                    'projects' => [],
                    'tasks' => [],
                ];
            }

            $reportData[$groupKey]['minutes'] += $duration;
            $reportData[$groupKey]['entries']++;

            // Track projects and tasks
            $projectName = $entry['project']['name'] ?? 'Unknown Project';
            $taskName = $entry['task']['name'] ?? 'No task';

            if (!isset($reportData[$groupKey]['projects'][$projectName])) {
                $reportData[$groupKey]['projects'][$projectName] = 0;
            }
            $reportData[$groupKey]['projects'][$projectName] += $duration;

            if (!isset($reportData[$groupKey]['tasks'][$taskName])) {
                $reportData[$groupKey]['tasks'][$taskName] = 0;
            }
            $reportData[$groupKey]['tasks'][$taskName] += $duration;

            // Update first/last entries
            if (Carbon::parse($entry['timeInterval']['start'])->lt(Carbon::parse($reportData[$groupKey]['first_entry']))) {
                $reportData[$groupKey]['first_entry'] = $entry['timeInterval']['start'];
            }

            $entryEnd = $entry['timeInterval']['end'] ?? Carbon::now()->toISOString();
            if (Carbon::parse($entryEnd)->gt(Carbon::parse($reportData[$groupKey]['last_entry']))) {
                $reportData[$groupKey]['last_entry'] = $entryEnd;
            }
        }

        // Sort by minutes descending
        uasort($reportData, function ($a, $b) {
            return $b['minutes'] - $a['minutes'];
        });

        return $reportData;
    }

    private function getGroupKey(array $entry, string $groupBy, Carbon $start): string
    {
        switch ($groupBy) {
            case 'project':
                return $entry['project']['name'] ?? 'Unknown Project';

            case 'task':
                $project = $entry['project']['name'] ?? 'Unknown Project';
                $task = $entry['task']['name'] ?? 'No task';

                return "{$project} → {$task}";

            case 'date':
                return $start->setTimezone(date_default_timezone_get())->format('Y-m-d (D, M j)');

            default:
                throw new RuntimeException("Invalid group-by option: {$groupBy}");
        }
    }

    private function displaySummary(OutputInterface $output, array $timeEntries, Carbon $start, Carbon $end): void
    {
        $totalMinutes = $this->calculateTotalDuration($timeEntries);
        $days = $start->diffInDays($end) + 1;

        ConsoleHelper::displaySection($output, 'Summary', '📊');

        $output->writeln('⏱️  Total time: <fg=green>' . TimeHelper::formatDuration($totalMinutes) . '</>');
        $output->writeln('📈 Total entries: ' . count($timeEntries));
        $output->writeln("📅 Days in period: {$days}");

        if ($days > 0) {
            $avgPerDay = $totalMinutes / $days;
            $output->writeln('📊 Average per day: ' . TimeHelper::formatDuration((int) $avgPerDay));
        }

        // Unique projects and tasks
        $uniqueProjects = [];
        $uniqueTasks = [];

        foreach ($timeEntries as $entry) {
            $projectName = $entry['project']['name'] ?? 'Unknown Project';
            $taskName = $entry['task']['name'] ?? null;

            $uniqueProjects[$projectName] = true;
            if ($taskName) {
                $uniqueTasks[$taskName] = true;
            }
        }

        $output->writeln('🗂️  Projects worked on: ' . count($uniqueProjects));
        $output->writeln('🎯 Tasks worked on: ' . count($uniqueTasks));

        $output->writeln('');
    }

    private function calculateTotalDuration(array $timeEntries): int
    {
        $totalMinutes = 0;

        foreach ($timeEntries as $entry) {
            if (isset($entry['timeInterval']['start'])) {
                // Parse times in UTC (as they come from Clockify API)
                $start = Carbon::parse($entry['timeInterval']['start'])->utc();

                if (isset($entry['timeInterval']['end'])) {
                    $end = Carbon::parse($entry['timeInterval']['end'])->utc();
                } else {
                    // For running timers, use current UTC time
                    $end = Carbon::now()->utc();
                }

                $duration = abs($end->diffInMinutes($start)); // abs() prevents negative durations
                $totalMinutes += $duration;
            }
        }

        return (int) $totalMinutes;
    }

    private function outputTable(OutputInterface $output, array $reportData, string $groupBy): void
    {
        $table = new Table($output);

        switch ($groupBy) {
            case 'project':
                $table->setHeaders(['Project', 'Duration', 'Entries', 'Tasks', 'Percentage']);
                break;
            case 'task':
                $table->setHeaders(['Project → Task', 'Duration', 'Entries', 'Percentage']);
                break;
            case 'date':
                $table->setHeaders(['Date', 'Duration', 'Entries', 'Projects', 'First', 'Last']);
                break;
        }

        $totalMinutes = array_sum(array_column($reportData, 'minutes'));

        foreach ($reportData as $data) {
            $percentage = $totalMinutes > 0 ? round(($data['minutes'] / $totalMinutes) * 100) : 0;

            switch ($groupBy) {
                case 'project':
                    $table->addRow([
                        $data['key'],
                        TimeHelper::formatDuration($data['minutes']),
                        $data['entries'],
                        count($data['tasks']),
                        "{$percentage}%",
                    ]);
                    break;

                case 'task':
                    $table->addRow([
                        $data['key'],
                        TimeHelper::formatDuration($data['minutes']),
                        $data['entries'],
                        "{$percentage}%",
                    ]);
                    break;

                case 'date':
                    $first = Carbon::parse($data['first_entry'])->setTimezone(date_default_timezone_get())->format('H:i');
                    $last = Carbon::parse($data['last_entry'])->setTimezone(date_default_timezone_get())->format('H:i');

                    $table->addRow([
                        $data['key'],
                        TimeHelper::formatDuration($data['minutes']),
                        $data['entries'],
                        count($data['projects']),
                        $first,
                        $last,
                    ]);
                    break;
            }
        }

        $table->render();
        $output->writeln('');
    }

    private function outputCsv(OutputInterface $output, array $reportData, string $groupBy, ?string $filename): void
    {
        $csvData = $this->prepareCsvData($reportData, $groupBy);

        if ($filename) {
            $this->writeCsvToFile($csvData, $filename);
            ConsoleHelper::displaySuccess($output, "Exported to {$filename}");
        } else {
            foreach ($csvData as $row) {
                $output->writeln(implode(',', array_map(function ($field) {
                    return '"' . str_replace('"', '""', $field) . '"';
                }, $row)));
            }
        }
    }

    private function outputJson(OutputInterface $output, array $reportData, ?string $filename): void
    {
        $jsonData = array_values($reportData);
        $json = json_encode($jsonData, JSON_PRETTY_PRINT);

        if ($filename) {
            file_put_contents($filename, $json);
            ConsoleHelper::displaySuccess($output, "Exported to {$filename}");
        } else {
            $output->writeln($json);
        }
    }

    private function exportToCsv(OutputInterface $output, array $reportData, string $filename, string $groupBy): void
    {
        ConsoleHelper::displayProgressBar($output, 'Exporting to CSV');

        $csvData = $this->prepareCsvData($reportData, $groupBy);
        $this->writeCsvToFile($csvData, $filename);

        ConsoleHelper::finishProgressBar($output);
        ConsoleHelper::displaySuccess($output, "Exported to {$filename}");
    }

    private function prepareCsvData(array $reportData, string $groupBy): array
    {
        $csvData = [];

        // Headers
        switch ($groupBy) {
            case 'project':
                $csvData[] = ['Project', 'Duration (minutes)', 'Duration (formatted)', 'Entries', 'Tasks'];
                break;
            case 'task':
                $csvData[] = ['Project → Task', 'Duration (minutes)', 'Duration (formatted)', 'Entries'];
                break;
            case 'date':
                $csvData[] = ['Date', 'Duration (minutes)', 'Duration (formatted)', 'Entries', 'Projects', 'First Entry', 'Last Entry'];
                break;
        }

        // Data rows
        foreach ($reportData as $data) {
            switch ($groupBy) {
                case 'project':
                    $csvData[] = [
                        $data['key'],
                        $data['minutes'],
                        TimeHelper::formatDuration($data['minutes']),
                        $data['entries'],
                        count($data['tasks']),
                    ];
                    break;

                case 'task':
                    $csvData[] = [
                        $data['key'],
                        $data['minutes'],
                        TimeHelper::formatDuration($data['minutes']),
                        $data['entries'],
                    ];
                    break;

                case 'date':
                    $csvData[] = [
                        $data['key'],
                        $data['minutes'],
                        TimeHelper::formatDuration($data['minutes']),
                        $data['entries'],
                        count($data['projects']),
                        Carbon::parse($data['first_entry'])->setTimezone(date_default_timezone_get())->format('H:i'),
                        Carbon::parse($data['last_entry'])->setTimezone(date_default_timezone_get())->format('H:i'),
                    ];
                    break;
            }
        }

        return $csvData;
    }

    private function writeCsvToFile(array $csvData, string $filename): void
    {
        $fp = fopen($filename, 'w');
        if (!$fp) {
            throw new RuntimeException("Cannot create file: {$filename}");
        }

        foreach ($csvData as $row) {
            fputcsv($fp, $row);
        }

        fclose($fp);
    }
}
