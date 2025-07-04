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
    name: 'week',
    description: 'Show weekly time tracking summary',
    aliases: ['wk']
)]
class WeekCommand extends Command
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
            ->addOption('week', 'w', InputOption::VALUE_REQUIRED, 'Week offset (0=current, -1=last week, 1=next week)', '0')
            ->addOption('detailed', 'd', InputOption::VALUE_NONE, 'Show detailed daily breakdown')
            ->addOption('export', null, InputOption::VALUE_REQUIRED, 'Export to CSV file')
            ->setHelp('
Show weekly time tracking summary:

<info>Basic usage:</info>
  clockify-wizard week                      # Current week
  clockify-wizard week --week -1            # Last week
  clockify-wizard week --detailed           # With daily breakdown
  clockify-wizard week --export week.csv    # Export to CSV
            ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->initializeClient();

            $weekOffset = (int) $input->getOption('week');
            $detailed = $input->getOption('detailed');
            $exportFile = $input->getOption('export');

            $weekStart = $this->getWeekStart($weekOffset);
            $weekEnd = $weekStart->copy()->endOfWeek();

            ConsoleHelper::displayHeader($output, 'Weekly Summary');

            $this->displayWeekInfo($output, $weekStart, $weekEnd);

            $timeEntries = $this->getWeekTimeEntries($weekStart, $weekEnd);

            if (empty($timeEntries)) {
                ConsoleHelper::displayInfo($output, 'No time entries found for this week.');

                return Command::SUCCESS;
            }

            // Expand time entries with project and task information
            $expandedEntries = $this->expandTimeEntries($output, $timeEntries);

            $this->showWeeklySummary($output, $expandedEntries, $weekStart, $weekEnd);

            if ($detailed) {
                $this->showDailyBreakdown($output, $expandedEntries, $weekStart);
            }

            $this->showProjectBreakdown($output, $expandedEntries);

            if ($exportFile) {
                $this->exportToCsv($output, $expandedEntries, $exportFile, $weekStart);
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

    private function getWeekStart(int $weekOffset): Carbon
    {
        return Carbon::now()->addWeeks($weekOffset)->startOfWeek();
    }

    private function displayWeekInfo(OutputInterface $output, Carbon $weekStart, Carbon $weekEnd): void
    {
        $weekNumber = $weekStart->weekOfYear;
        $year = $weekStart->year;

        $output->writeln("📅 Week {$weekNumber}, {$year}");
        $output->writeln("📍 {$weekStart->format('M j')} - {$weekEnd->format('M j, Y')}");

        if ($weekStart->isCurrentWeek()) {
            $output->writeln('📌 <fg=green>Current week</>');
        } elseif ($weekStart->isLastWeek()) {
            $output->writeln('📌 <fg=yellow>Last week</>');
        } elseif ($weekStart->isNextWeek()) {
            $output->writeln('📌 <fg=blue>Next week</>');
        }

        $output->writeln('');
    }

    private function getWeekTimeEntries(Carbon $weekStart, Carbon $weekEnd): array
    {
        $clockifyConfig = $this->configManager->getClockifyConfig();

        return $this->clockifyClient->getTimeEntries(
            $clockifyConfig['user_id'],
            $weekStart,
            $weekEnd->endOfDay()
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

            // Show progress every 10 entries
            if ($processedCount % 10 === 0) {
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

    private function showWeeklySummary(OutputInterface $output, array $timeEntries, Carbon $weekStart, Carbon $weekEnd): void
    {
        $totalMinutes = $this->calculateTotalDuration($timeEntries);
        $workingDays = $this->calculateWorkingDays($weekStart, $weekEnd);
        $daysWorked = $this->calculateDaysWorked($timeEntries);

        ConsoleHelper::displaySection($output, 'Weekly Overview', '📊');

        $output->writeln('⏱️  Total time: <fg=green>' . TimeHelper::formatDuration($totalMinutes) . '</>');
        $output->writeln('📈 Entries: ' . count($timeEntries));
        $output->writeln("📅 Days worked: {$daysWorked} / {$workingDays}");

        if ($daysWorked > 0) {
            $avgPerDay = $totalMinutes / $daysWorked;
            $output->writeln('📊 Average per day: ' . TimeHelper::formatDuration((int) $avgPerDay));
        }

        // Weekly targets
        $targetHours = 40; // 8 hours * 5 days
        $targetMinutes = $targetHours * 60;
        $percentage = $targetMinutes > 0 ? round(($totalMinutes / $targetMinutes) * 100) : 0;

        $output->writeln("🎯 Target progress: {$percentage}% (" . TimeHelper::formatDuration($totalMinutes) . ' / ' . TimeHelper::formatDuration($targetMinutes) . ')');

        if ($percentage >= 100) {
            $output->writeln('<fg=green>✅ Weekly target achieved!</>');
        } elseif ($percentage >= 80) {
            $output->writeln('<fg=yellow>⚠️  Close to weekly target</>');
        } else {
            $remaining = $targetMinutes - $totalMinutes;
            $output->writeln('<fg=red>📉 ' . TimeHelper::formatDuration($remaining) . ' remaining for target</>');
        }

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

    private function calculateDailySummary(array $timeEntries): array
    {
        $summary = [];

        foreach ($timeEntries as $entry) {
            $start = Carbon::parse($entry['timeInterval']['start'])->utc();
            $end = isset($entry['timeInterval']['end'])
                ? Carbon::parse($entry['timeInterval']['end'])->utc()
                : Carbon::now()->utc();

            $date = $start->setTimezone(date_default_timezone_get())->format('Y-m-d');
            $duration = abs($end->diffInMinutes($start));

            if (!isset($summary[$date])) {
                $summary[$date] = [
                    'minutes' => 0,
                    'count' => 0,
                    'first' => $entry['timeInterval']['start'],
                    'last' => $entry['timeInterval']['end'] ?? Carbon::now()->toISOString(),
                ];
            }

            $summary[$date]['minutes'] += $duration;
            $summary[$date]['count']++;

            // Update first/last times (keep in original format for consistency)
            $entryStart = Carbon::parse($entry['timeInterval']['start']);
            $summaryFirst = Carbon::parse($summary[$date]['first']);

            if ($entryStart->lt($summaryFirst)) {
                $summary[$date]['first'] = $entry['timeInterval']['start'];
            }

            $entryEnd = $entry['timeInterval']['end'] ?? Carbon::now()->toISOString();
            $summaryLast = Carbon::parse($summary[$date]['last']);

            if (Carbon::parse($entryEnd)->gt($summaryLast)) {
                $summary[$date]['last'] = $entryEnd;
            }
        }

        return $summary;
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

    private function showDailyBreakdown(OutputInterface $output, array $timeEntries, Carbon $weekStart): void
    {
        ConsoleHelper::displaySection($output, 'Daily Breakdown', '📅');

        $dailySummary = $this->calculateDailySummary($timeEntries);

        $table = new Table($output);
        $table->setHeaders(['Day', 'Date', 'Hours', 'Entries', 'First', 'Last']);

        for ($i = 0; $i < 7; $i++) {
            $date = $weekStart->copy()->addDays($i);
            $dateKey = $date->format('Y-m-d');
            $dayData = $dailySummary[$dateKey] ?? null;

            if ($dayData) {
                $duration = TimeHelper::formatDuration($dayData['minutes']);
                // Convert times to local timezone for display
                $first = Carbon::parse($dayData['first'])->setTimezone(date_default_timezone_get())->format('H:i');
                $last = Carbon::parse($dayData['last'])->setTimezone(date_default_timezone_get())->format('H:i');
                $entries = $dayData['count'];
            } else {
                $duration = '-';
                $first = '-';
                $last = '-';
                $entries = 0;
            }

            $dayName = $date->format('D');
            if ($date->isToday()) {
                $dayName = "<fg=green>{$dayName}</>";
            } elseif ($date->isWeekend()) {
                $dayName = "<fg=yellow>{$dayName}</>";
            }

            $table->addRow([
                $dayName,
                $date->format('M j'),
                $duration,
                $entries,
                $first,
                $last,
            ]);
        }

        $table->render();
        $output->writeln('');
    }

    private function exportToCsv(OutputInterface $output, array $timeEntries, string $filename, Carbon $weekStart): void
    {
        ConsoleHelper::displayProgressBar($output, 'Exporting to CSV');

        $csvData = [];
        $csvData[] = ['Week', 'Date', 'Start', 'End', 'Duration', 'Project', 'Task', 'Description'];

        $weekNumber = $weekStart->weekOfYear;

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
                "Week {$weekNumber}",
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

    private function showProjectBreakdown(OutputInterface $output, array $timeEntries): void
    {
        $projectSummary = $this->calculateProjectSummary($timeEntries);

        if (empty($projectSummary)) {
            return;
        }

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
    }

    private function calculateDaysWorked(array $timeEntries): int
    {
        $daysWithEntries = [];

        foreach ($timeEntries as $entry) {
            $date = Carbon::parse($entry['timeInterval']['start'])->format('Y-m-d');
            $daysWithEntries[$date] = true;
        }

        return count($daysWithEntries);
    }

    private function calculateWorkingDays(Carbon $weekStart, Carbon $weekEnd): int
    {
        $count = 0;
        $current = $weekStart->copy();

        while ($current->lte($weekEnd)) {
            if ($current->isWeekday()) {
                $count++;
            }
            $current->addDay();
        }

        return $count;
    }
}
