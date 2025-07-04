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
    name: 'today',
    description: 'Show today\'s time tracking summary',
    aliases: ['td']
)]
class TodayCommand extends Command
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
            ->addOption('detailed', 'd', InputOption::VALUE_NONE, 'Show detailed breakdown')
            ->addOption('export', null, InputOption::VALUE_REQUIRED, 'Export to CSV file')
            ->setHelp('
Show today\'s time tracking summary:

<info>Basic usage:</info>
  clockify-wizard today                    # Basic summary
  clockify-wizard today --detailed         # Detailed breakdown
  clockify-wizard today --export today.csv # Export to CSV
            ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->initializeClient();

            $detailed = $input->getOption('detailed');
            $exportFile = $input->getOption('export');

            ConsoleHelper::displayHeader($output, 'Today\'s Summary');

            $timeEntries = $this->getTodaysTimeEntries();

            if (empty($timeEntries)) {
                ConsoleHelper::displayInfo($output, 'No time entries found for today.');
                $output->writeln('Use <fg=cyan>clockify-wizard start</> or <fg=cyan>clockify-wizard log</> to track time.');

                return Command::SUCCESS;
            }

            // Expand time entries with project and task information
            $expandedEntries = $this->expandTimeEntries($output, $timeEntries);

            $this->showSummary($output, $expandedEntries);

            if ($detailed) {
                $this->showDetailedBreakdown($output, $expandedEntries);
            }

            $this->showTimelineView($output, $expandedEntries);

            if ($exportFile) {
                $this->exportToCsv($output, $expandedEntries, $exportFile);
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

    private function getTodaysTimeEntries(): array
    {
        $clockifyConfig = $this->configManager->getClockifyConfig();
        $today = Carbon::today();
        $tomorrow = Carbon::tomorrow();

        return $this->clockifyClient->getTimeEntries(
            $clockifyConfig['user_id'],
            $today,
            $tomorrow
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
        foreach ($timeEntries as $entry) {
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

    private function showSummary(OutputInterface $output, array $timeEntries): void
    {
        $totalMinutes = $this->calculateTotalDuration($timeEntries);
        $projectSummary = $this->calculateProjectSummary($timeEntries);

        ConsoleHelper::displaySection($output, 'Overview', '📊');

        $output->writeln('📅 Date: <fg=cyan>' . Carbon::today()->format('F j, Y') . '</>');
        $output->writeln('⏱️  Total time: <fg=green>' . TimeHelper::formatDuration($totalMinutes) . '</>');
        $output->writeln('📈 Entries: ' . count($timeEntries));
        $output->writeln('🗂️  Projects: ' . count($projectSummary));

        // Check for active timer
        $clockifyConfig = $this->configManager->getClockifyConfig();
        $activeTimer = $this->clockifyClient->getCurrentTimeEntry($clockifyConfig['user_id']);

        if ($activeTimer) {
            $start = Carbon::parse($activeTimer['timeInterval']['start']);
            $elapsed = $start->diffInMinutes(Carbon::now());
            $output->writeln('⏱️  Active timer: <fg=magenta>' . TimeHelper::formatDuration((int) $elapsed) . '</> (running)');
        }

        $output->writeln('');
    }

    private function showDetailedBreakdown(OutputInterface $output, array $timeEntries): void
    {
        $projectSummary = $this->calculateProjectSummary($timeEntries);
        $taskSummary = $this->calculateTaskSummary($timeEntries);

        ConsoleHelper::displaySection($output, 'Project Breakdown', '🗂️');

        $table = new Table($output);
        $table->setHeaders(['Project', 'Duration', 'Entries', 'Percentage']);

        $totalMinutes = $this->calculateTotalDuration($timeEntries);

        foreach ($projectSummary as $project => $data) {
            $percentage = $totalMinutes > 0 ? round(($data['minutes'] / $totalMinutes) * 100) : 0;

            $table->addRow([
                $project,
                TimeHelper::formatDuration($data['minutes']),
                $data['count'],
                "{$percentage}%",
            ]);
        }

        $table->render();
        $output->writeln('');

        // Task breakdown
        if (!empty($taskSummary)) {
            ConsoleHelper::displaySection($output, 'Task Breakdown', '🎯');

            $taskTable = new Table($output);
            $taskTable->setHeaders(['Task', 'Project', 'Duration']);

            foreach ($taskSummary as $task => $data) {
                $taskTable->addRow([
                    $task ?: 'No task',
                    $data['project'],
                    TimeHelper::formatDuration($data['minutes']),
                ]);
            }

            $taskTable->render();
            $output->writeln('');
        }
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

    private function showTimelineView(OutputInterface $output, array $timeEntries): void
    {
        ConsoleHelper::displaySection($output, 'Timeline', '⏰');

        // Sort entries by start time
        usort($timeEntries, function ($a, $b) {
            return Carbon::parse($a['timeInterval']['start'])->timestamp -
                Carbon::parse($b['timeInterval']['start'])->timestamp;
        });

        $table = new Table($output);
        $table->setHeaders(['Time', 'Duration', 'Project', 'Task', 'Description']);

        foreach ($timeEntries as $entry) {
            // Parse in UTC then convert to local for display
            $start = Carbon::parse($entry['timeInterval']['start'])->utc();
            $startLocal = $start->setTimezone(date_default_timezone_get());

            if (isset($entry['timeInterval']['end'])) {
                $end = Carbon::parse($entry['timeInterval']['end'])->utc();
                $endLocal = $end->setTimezone(date_default_timezone_get());
            } else {
                $end = Carbon::now()->utc();
                $endLocal = $end->setTimezone(date_default_timezone_get());
            }

            $duration = abs($end->diffInMinutes($start));
            $timeRange = $startLocal->format('H:i') . '-' . $endLocal->format('H:i');

            if (!isset($entry['timeInterval']['end'])) {
                $timeRange .= ' (active)';
            }

            $table->addRow([
                $timeRange,
                TimeHelper::formatDuration((int) $duration),
                $entry['project']['name'] ?? 'Unknown Project',
                $entry['task']['name'] ?? 'No task',
                $this->truncateDescription($entry['description'] ?? '', 30),
            ]);
        }

        $table->render();
        $output->writeln('');

        // Show gaps if any
        $this->showTimeGaps($output, $timeEntries);
    }

    private function calculateProjectSummary(array $timeEntries): array
    {
        $summary = [];

        foreach ($timeEntries as $entry) {
            $projectName = $entry['project']['name'] ?? 'Unknown Project';

            if (!isset($summary[$projectName])) {
                $summary[$projectName] = ['minutes' => 0, 'count' => 0];
            }

            $start = Carbon::parse($entry['timeInterval']['start'])->utc();
            $end = isset($entry['timeInterval']['end'])
                ? Carbon::parse($entry['timeInterval']['end'])->utc()
                : Carbon::now()->utc();

            $duration = abs($end->diffInMinutes($start));
            $summary[$projectName]['minutes'] += $duration;
            $summary[$projectName]['count']++;
        }

        // Sort by duration
        uasort($summary, function ($a, $b) {
            return $b['minutes'] - $a['minutes'];
        });

        return $summary;
    }

    private function calculateTaskSummary(array $timeEntries): array
    {
        $summary = [];

        foreach ($timeEntries as $entry) {
            $taskName = $entry['task']['name'] ?? 'No task';
            $projectName = $entry['project']['name'] ?? 'Unknown Project';

            if (!isset($summary[$taskName])) {
                $summary[$taskName] = ['minutes' => 0, 'project' => $projectName];
            }

            $start = Carbon::parse($entry['timeInterval']['start'])->utc();
            $end = isset($entry['timeInterval']['end'])
                ? Carbon::parse($entry['timeInterval']['end'])->utc()
                : Carbon::now()->utc();

            $duration = abs($end->diffInMinutes($start));
            $summary[$taskName]['minutes'] += $duration;
        }

        // Sort by duration
        uasort($summary, function ($a, $b) {
            return $b['minutes'] - $a['minutes'];
        });

        return $summary;
    }

    private function exportToCsv(OutputInterface $output, array $timeEntries, string $filename): void
    {
        ConsoleHelper::displayProgressBar($output, 'Exporting to CSV');

        $csvData = [];
        $csvData[] = ['Date', 'Start', 'End', 'Duration', 'Project', 'Task', 'Description'];

        foreach ($timeEntries as $entry) {
            $start = Carbon::parse($entry['timeInterval']['start'])->utc();
            $end = isset($entry['timeInterval']['end'])
                ? Carbon::parse($entry['timeInterval']['end'])->utc()
                : Carbon::now()->utc();

            // Convert to local timezone for CSV
            $startLocal = $start->setTimezone(date_default_timezone_get());
            $endLocal = $end->setTimezone(date_default_timezone_get());

            $duration = abs($end->diffInMinutes($start));

            $csvData[] = [
                $startLocal->format('Y-m-d'),
                $startLocal->format('H:i:s'),
                $endLocal->format('H:i:s'),
                TimeHelper::formatDuration((int) $duration),
                $entry['project']['name'] ?? 'Unknown Project',
                $entry['task']['name'] ?? '',
                $entry['description'] ?? '',
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

    private function showTimeGaps(OutputInterface $output, array $timeEntries): void
    {
        if (count($timeEntries) < 2) {
            return;
        }

        $gaps = [];
        for ($i = 0; $i < count($timeEntries) - 1; $i++) {
            $currentEnd = isset($timeEntries[$i]['timeInterval']['end'])
                ? Carbon::parse($timeEntries[$i]['timeInterval']['end'])
                : null;

            $nextStart = Carbon::parse($timeEntries[$i + 1]['timeInterval']['start']);

            if ($currentEnd && $nextStart->diffInMinutes($currentEnd) > 15) {
                $gaps[] = [
                    'start' => $currentEnd,
                    'end' => $nextStart,
                    'duration' => $nextStart->diffInMinutes($currentEnd),
                ];
            }
        }

        if (!empty($gaps)) {
            $output->writeln('<fg=yellow>💡 Time gaps detected:</>');
            foreach ($gaps as $gap) {
                $output->writeln("   {$gap['start']->format('H:i')} - {$gap['end']->format('H:i')} (" .
                    TimeHelper::formatDuration($gap['duration']) . ')');
            }
            $output->writeln('');
        }
    }

    private function truncateDescription(string $description, int $length): string
    {
        if (strlen($description) <= $length) {
            return $description;
        }

        return substr($description, 0, $length - 3) . '...';
    }
}
