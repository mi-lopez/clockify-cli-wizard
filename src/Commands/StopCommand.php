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
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'stop',
    description: 'Stop the active timer',
    aliases: ['end']
)]
class StopCommand extends Command
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
            ->addOption('time', 't', InputOption::VALUE_REQUIRED, 'Stop time (e.g., 17:30, 5:30pm, now)')
            ->addOption('debug', null, InputOption::VALUE_NONE, 'Show debug information')
            ->setHelp('
Stop the active timer:

<info>Basic usage:</info>
  clockify-wizard stop
  clockify-wizard stop --time 17:30
  clockify-wizard stop --time "5:30pm"

<info>The command will:</info>
  • Stop the currently running timer
  • Display elapsed time and summary
  • Clear the active timer state
            ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->initializeClient();

            $debug = $input->getOption('debug');

            ConsoleHelper::displayHeader($output, 'Stop Timer');

            if ($debug) {
                $this->showDebugInfo($output);
            }

            // Check if there's an active timer
            $activeTimer = $this->getActiveTimer($output, $debug);
            if (!$activeTimer) {
                ConsoleHelper::displayWarning($output, 'No active timer found.');

                if (!$debug) {
                    $output->writeln('💡 Try running with --debug flag to see more information:');
                    $output->writeln('   <fg=cyan>clockify-wizard stop --debug</>');
                }

                return Command::SUCCESS;
            }

            // Display current timer info
            $this->displayActiveTimerInfo($output, $activeTimer);

            // Get stop time
            $stopTime = $this->getStopTime($input, $output);

            // Confirm stop
            if (!ConsoleHelper::askConfirmation($input, $output, 'Stop this timer?', true)) {
                ConsoleHelper::displayInfo($output, 'Timer stop cancelled.');

                return Command::SUCCESS;
            }

            // Stop the timer
            $stoppedTimer = $this->stopTimer($output, $stopTime);

            // Display summary
            $this->displayStopSummary($output, $stoppedTimer);

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

    private function showDebugInfo(OutputInterface $output): void
    {
        ConsoleHelper::displaySection($output, 'Debug Information', '🔍');

        $clockifyConfig = $this->configManager->getClockifyConfig();
        $output->writeln("User ID: <fg=cyan>{$clockifyConfig['user_id']}</>");
        $output->writeln("Workspace ID: <fg=cyan>{$clockifyConfig['workspace_id']}</>");

        // Check local timer state
        $localTimer = $this->configManager->getActiveTimer();
        if ($localTimer) {
            $output->writeln('Local timer found: <fg=green>Yes</>');
            $output->writeln("Local timer ID: <fg=cyan>{$localTimer['id']}</>");
            $output->writeln("Local timer project: <fg=cyan>{$localTimer['project']}</>");
        } else {
            $output->writeln('Local timer found: <fg=red>No</>');
        }

        $output->writeln('');
    }

    private function getActiveTimer(OutputInterface $output, bool $debug = false): ?array
    {
        $clockifyConfig = $this->configManager->getClockifyConfig();

        // Check local config first
        $localTimer = $this->configManager->getActiveTimer();
        if ($debug && $localTimer) {
            $output->writeln("Found local timer: {$localTimer['id']}");
        }

        try {
            // Try primary method first
            ConsoleHelper::displayProgressBar($output, 'Checking for active timer');
            $currentTimer = $this->clockifyClient->getCurrentTimeEntry($clockifyConfig['user_id']);

            if ($debug) {
                if ($currentTimer) {
                    $output->writeln('Primary API method: <fg=green>Found timer</>');
                    $output->writeln("API timer ID: <fg=cyan>{$currentTimer['id']}</>");
                } else {
                    $output->writeln('Primary API method: <fg=red>No timer found</>');
                }
            }

            // If primary method fails, try fallback method
            if (!$currentTimer && method_exists($this->clockifyClient, 'findRunningTimeEntry')) {
                if ($debug) {
                    $output->writeln('Trying fallback method...');
                }

                $currentTimer = $this->clockifyClient->findRunningTimeEntry($clockifyConfig['user_id']);

                if ($debug) {
                    if ($currentTimer) {
                        $output->writeln('Fallback method: <fg=green>Found timer</>');
                        $output->writeln("Fallback timer ID: <fg=cyan>{$currentTimer['id']}</>");
                    } else {
                        $output->writeln('Fallback method: <fg=red>No timer found</>');
                    }
                }
            }

            ConsoleHelper::finishProgressBar($output);

            // If no timer found via API but we have local state, validate it
            if (!$currentTimer && $localTimer) {
                if ($debug) {
                    $output->writeln('No API timer but have local timer, validating...');
                }

                // Try to validate local timer by checking recent entries
                if ($this->validateLocalTimer($localTimer, $debug, $output)) {
                    if ($debug) {
                        $output->writeln('Local timer validated, using it');
                    }

                    return $this->convertLocalTimerToApiFormat($localTimer);
                } else {
                    if ($debug) {
                        $output->writeln('Local timer validation failed, clearing');
                    }
                    $this->configManager->clearActiveTimer();

                    return null;
                }
            }

            if (!$currentTimer) {
                // Clear local timer if none exists in Clockify
                if ($localTimer) {
                    $this->configManager->clearActiveTimer();
                    if (!$debug) {
                        ConsoleHelper::displayInfo($output, 'Cleared stale local timer data.');
                    }
                }

                return null;
            }

            // Expand timer data with project and task information
            $expandedTimer = $this->expandTimerData($output, $currentTimer, $debug);

            // If we have a current timer, make sure local state matches
            if (!$localTimer || $localTimer['id'] !== $currentTimer['id']) {
                // Update local state to match API
                $this->updateLocalTimerState($expandedTimer);
                if ($debug) {
                    $output->writeln('Updated local timer state to match API');
                }
            }

            return $expandedTimer;
        } catch (RuntimeException $e) {
            ConsoleHelper::finishProgressBar($output);

            if ($debug) {
                ConsoleHelper::displayError($output, "API call failed: {$e->getMessage()}");
            }

            // If API call fails but we have local timer, try to use it
            if ($localTimer) {
                ConsoleHelper::displayWarning($output, 'API call failed, but found local timer data.');
                if (ConsoleHelper::askConfirmation($input ?? null, $output, 'Use local timer data?', true)) {
                    return $this->convertLocalTimerToApiFormat($localTimer);
                }
            }

            throw $e;
        }
    }

    private function expandTimerData(OutputInterface $output, array $timer, bool $debug = false): array
    {
        // Load projects cache if needed
        $this->loadProjectsCache();

        // Handle different API response formats
        $projectId = $timer['projectId'] ?? $timer['project']['id'] ?? null;
        $taskId = $timer['taskId'] ?? $timer['task']['id'] ?? null;

        if ($debug) {
            $output->writeln("Expanding timer data - Project ID: {$projectId}, Task ID: " . ($taskId ?: 'None'));
        }

        // Get project information
        if ($projectId && isset($this->projectsCache[$projectId])) {
            $timer['project'] = $this->projectsCache[$projectId];
        } elseif (!isset($timer['project']) || empty($timer['project']['name'])) {
            $timer['project'] = [
                'id' => $projectId ?? 'unknown',
                'name' => 'Unknown Project',
                'color' => '#666666',
            ];
        }

        // Get task information if needed
        if ($taskId && !isset($timer['task']['name'])) {
            $taskInfo = $this->getTaskInfo($projectId, $taskId);
            if ($taskInfo) {
                $timer['task'] = $taskInfo;
            } else {
                $timer['task'] = [
                    'id' => $taskId,
                    'name' => 'Unknown Task',
                ];
            }
        } elseif (!isset($timer['task'])) {
            $timer['task'] = [
                'id' => null,
                'name' => null,
            ];
        }

        return $timer;
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

    private function validateLocalTimer(array $localTimer, bool $debug, OutputInterface $output): bool
    {
        try {
            $clockifyConfig = $this->configManager->getClockifyConfig();

            // Get recent entries and look for the timer ID
            $recentEntries = $this->clockifyClient->getTimeEntriesPage(
                $clockifyConfig['user_id'],
                1,
                10
            );

            foreach ($recentEntries as $entry) {
                if ($entry['id'] === $localTimer['id']) {
                    // Check if it's still running (no end time)
                    $isRunning = !isset($entry['timeInterval']['end']) || $entry['timeInterval']['end'] === null;

                    if ($debug) {
                        $output->writeln("Found timer {$entry['id']} in recent entries, running: " . ($isRunning ? 'Yes' : 'No'));
                    }

                    return $isRunning;
                }
            }

            if ($debug) {
                $output->writeln("Timer {$localTimer['id']} not found in recent entries");
            }

            return false;
        } catch (RuntimeException $e) {
            if ($debug) {
                $output->writeln("Timer validation failed: {$e->getMessage()}");
            }

            return false;
        }
    }

    private function updateLocalTimerState(array $apiTimer): void
    {
        $localTimerData = [
            'id' => $apiTimer['id'],
            'project' => $apiTimer['project']['name'] ?? 'Unknown Project',
            'task' => $apiTimer['task']['name'] ?? 'Unknown Task',
            'start' => $apiTimer['timeInterval']['start'],
            'description' => $apiTimer['description'] ?? '',
        ];

        $this->configManager->saveActiveTimer($localTimerData);
    }

    private function convertLocalTimerToApiFormat(array $localTimer): array
    {
        // Convert local timer format to API format for consistency
        return [
            'id' => $localTimer['id'],
            'description' => $localTimer['description'] ?? '',
            'timeInterval' => [
                'start' => $localTimer['start'],
                'end' => null, // Active timer
            ],
            'project' => [
                'id' => $localTimer['project_id'] ?? 'unknown',
                'name' => $localTimer['project'] ?? 'Unknown Project',
            ],
            'task' => [
                'id' => $localTimer['task_id'] ?? null,
                'name' => $localTimer['task'] ?? null,
            ],
        ];
    }

    private function displayActiveTimerInfo(OutputInterface $output, array $timer): void
    {
        $start = Carbon::parse($timer['timeInterval']['start'])->setTimezone(date_default_timezone_get());
        $elapsed = $start->diffInMinutes(Carbon::now());

        ConsoleHelper::displaySection($output, 'Active Timer', '⏱️');

        $output->writeln("📁 Project: <fg=cyan>{$timer['project']['name']}</>");

        if (isset($timer['task']['name'])) {
            $output->writeln("🎯 Task: <fg=green>{$timer['task']['name']}</>");
        }

        if (!empty($timer['description'])) {
            $output->writeln("📝 Description: {$timer['description']}");
        }

        $output->writeln("🕐 Started: {$start->format('H:i')} ({$start->diffForHumans()})");
        $output->writeln('⏱️  Elapsed: <fg=magenta>' . TimeHelper::formatDuration((int) $elapsed) . '</>');
        $output->writeln('');
    }

    private function getStopTime(InputInterface $input, OutputInterface $output): Carbon
    {
        $timeOption = $input->getOption('time');

        if (!$timeOption) {
            return Carbon::now();
        }

        if ($timeOption === 'now') {
            return Carbon::now();
        }

        try {
            return TimeHelper::parseTime($timeOption);
        } catch (\InvalidArgumentException $e) {
            throw new RuntimeException("Invalid stop time format: {$timeOption}. Use formats like '17:30', '5:30pm', or 'now'");
        }
    }

    private function stopTimer(OutputInterface $output, Carbon $stopTime): array
    {
        ConsoleHelper::displayProgressBar($output, 'Stopping timer');

        try {
            $clockifyConfig = $this->configManager->getClockifyConfig();

            // Convert local time to UTC for API
            $stopTimeUtc = TimeHelper::toUtcTime($stopTime);

            $stoppedTimer = $this->clockifyClient->stopTimer(
                $clockifyConfig['user_id'],
                $stopTimeUtc->toISOString()
            );

            // Expand the stopped timer data with project/task info
            $expandedStoppedTimer = $this->expandTimerData($output, $stoppedTimer, false);

            // Clear local timer state
            $this->configManager->clearActiveTimer();

            ConsoleHelper::finishProgressBar($output);

            return $expandedStoppedTimer;
        } catch (RuntimeException $e) {
            ConsoleHelper::finishProgressBar($output);
            throw new RuntimeException("Failed to stop timer: {$e->getMessage()}");
        }
    }

    private function displayStopSummary(OutputInterface $output, array $timer): void
    {
        // Parse times and convert to local timezone for display
        $start = Carbon::parse($timer['timeInterval']['start'])->setTimezone(date_default_timezone_get());
        $end = Carbon::parse($timer['timeInterval']['end'])->setTimezone(date_default_timezone_get());

        // Calculate duration using UTC times to avoid timezone issues
        $startUtc = Carbon::parse($timer['timeInterval']['start'])->utc();
        $endUtc = Carbon::parse($timer['timeInterval']['end'])->utc();
        $duration = abs($endUtc->diffInMinutes($startUtc));

        ConsoleHelper::displaySection($output, 'Timer Stopped', '✅');

        $output->writeln("📁 Project: <fg=cyan>{$timer['project']['name']}</>");

        if (isset($timer['task']['name'])) {
            $output->writeln("🎯 Task: <fg=green>{$timer['task']['name']}</>");
        }

        if (!empty($timer['description'])) {
            $output->writeln("📝 Description: {$timer['description']}");
        }

        $output->writeln("🕐 Started: {$start->format('H:i')}");
        $output->writeln("🕕 Stopped: {$end->format('H:i')}");
        $output->writeln('⏱️  Total: <fg=green>' . TimeHelper::formatDuration((int) $duration) . '</>');
        $output->writeln('');

        ConsoleHelper::displaySuccess($output, 'Timer stopped successfully!');

        if (isset($timer['id'])) {
            $output->writeln("🔗 Entry ID: <fg=cyan>{$timer['id']}</>");
        }
    }
}
