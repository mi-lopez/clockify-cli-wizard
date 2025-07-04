<?php

declare(strict_types=1);

namespace MiLopez\ClockifyWizard\Commands;

use Carbon\Carbon;
use MiLopez\ClockifyWizard\Client\ClockifyClient;
use MiLopez\ClockifyWizard\Client\JiraClient;
use MiLopez\ClockifyWizard\Config\ConfigManager;
use MiLopez\ClockifyWizard\Helper\ConsoleHelper;
use MiLopez\ClockifyWizard\Helper\GitHelper;
use MiLopez\ClockifyWizard\Helper\TimeHelper;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'log',
    description: 'Log time to Clockify with smart detection and wizard interface',
    aliases: ['l']
)]
class LogTimeCommand extends Command
{
    private ConfigManager $configManager;

    private ?ClockifyClient $clockifyClient = null;

    private ?JiraClient $jiraClient = null;

    public function __construct()
    {
        parent::__construct();
        $this->configManager = new ConfigManager();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('duration', InputArgument::OPTIONAL, 'Duration to log (e.g., 2h, 1h30m, 90m)')
            ->addOption('task', 't', InputOption::VALUE_REQUIRED, 'Task ID or name (e.g., CAM-451)')
            ->addOption('project', 'p', InputOption::VALUE_REQUIRED, 'Clockify project ID or name')
            ->addOption('description', 'd', InputOption::VALUE_REQUIRED, 'Description for the time entry')
            ->addOption('start', 's', InputOption::VALUE_REQUIRED, 'Start time (e.g., 9:30am, 14:30)')
            ->addOption('end', 'e', InputOption::VALUE_REQUIRED, 'End time (e.g., 11:30am, 16:30)')
            ->addOption('end-now', null, InputOption::VALUE_NONE, 'Use current time as end time')
            ->addOption('auto', 'a', InputOption::VALUE_NONE, 'Auto-detect task from Git branch')
            ->addOption('interactive', 'i', InputOption::VALUE_NONE, 'Use interactive wizard mode')
            ->setHelp('
Log time to Clockify with various input methods:

<info>Basic usage:</info>
  clockify-wizard log 2h --task CAM-451
  clockify-wizard log 1h30m --auto
  clockify-wizard log --interactive

<info>Time specifications:</info>
  clockify-wizard log 2h --start 9am --task CAM-451
  clockify-wizard log --start 9am --end 11am --task CAM-451
  clockify-wizard log 2h --end-now --task CAM-451

<info>Auto-detection:</info>
  clockify-wizard log 2h --auto  # Detects task from Git branch
  clockify-wizard log 2h         # Interactive mode if no task specified

<info>Supported duration formats:</info>
  • 2h, 1.5h, 90m, 1h30m
            ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->initializeClients();

            // Initialize timezone from config
            $this->configManager->initializeTimezone();

            ConsoleHelper::displayHeader($output, 'Time Logger Wizard');

            // Show Git info if available
            if (GitHelper::isGitRepository()) {
                ConsoleHelper::displayGitInfo($output);
            }

            $timeData = $this->collectTimeData($input, $output);
            $taskData = $this->resolveTask($input, $output, $timeData);

            // Display confirmation
            $this->displaySummary($output, $timeData, $taskData);

            if (!ConsoleHelper::askConfirmation($input, $output, 'Create this time entry?', true)) {
                ConsoleHelper::displayInfo($output, 'Time entry cancelled.');

                return Command::SUCCESS;
            }

            // Create the time entry
            $this->createTimeEntry($output, $timeData, $taskData);

            return Command::SUCCESS;
        } catch (RuntimeException $e) {
            ConsoleHelper::displayError($output, $e->getMessage());

            return Command::FAILURE;
        }
    }

    private function initializeClients(): void
    {
        if (!$this->configManager->isConfigured()) {
            throw new RuntimeException('Clockify CLI is not configured. Run: clockify-wizard configure');
        }

        $clockifyConfig = $this->configManager->getClockifyConfig();
        $this->clockifyClient = new ClockifyClient(
            $clockifyConfig['api_key'],
            $clockifyConfig['workspace_id']
        );

        // Initialize Jira client if configured
        $jiraConfig = $this->configManager->getJiraConfig();
        if (!empty($jiraConfig['url']) && !empty($jiraConfig['email']) && !empty($jiraConfig['token'])) {
            $this->jiraClient = new JiraClient(
                $jiraConfig['url'],
                $jiraConfig['email'],
                $jiraConfig['token']
            );
        }
    }

    private function collectTimeData(InputInterface $input, OutputInterface $output): array
    {
        $duration = $input->getArgument('duration');
        $start = $input->getOption('start');
        $end = $input->getOption('end');
        $endNow = $input->getOption('end-now');
        $interactive = $input->getOption('interactive');

        // Interactive mode or missing duration
        if ($interactive || (!$duration && !$start && !$end)) {
            return $this->interactiveTimeCollection($input, $output);
        }

        // Validate time inputs
        if (($start || $end) && $duration) {
            throw new RuntimeException('Cannot specify both duration and start/end times');
        }

        $timeData = [];

        if ($duration) {
            $minutes = TimeHelper::parseDuration($duration);
            $endTime = $endNow ? TimeHelper::now() : TimeHelper::now();
            $startTime = $endTime->copy()->subMinutes($minutes);

            $timeData = [
                'start' => $startTime,
                'end' => $endTime,
                'duration' => TimeHelper::formatDuration($minutes),
                'minutes' => $minutes,
            ];
        } elseif ($start && $end) {
            $startTime = TimeHelper::parseTime($start);
            $endTime = TimeHelper::parseTime($end);

            if ($startTime->gte($endTime)) {
                throw new RuntimeException('Start time must be before end time');
            }

            $minutes = $endTime->diffInMinutes($startTime);

            $timeData = [
                'start' => $startTime,
                'end' => $endTime,
                'duration' => TimeHelper::formatDuration((int) $minutes),
                'minutes' => $minutes,
            ];
        } else {
            throw new RuntimeException('Must specify either duration or start/end times');
        }

        return $timeData;
    }

    private function interactiveTimeCollection(InputInterface $input, OutputInterface $output): array
    {
        ConsoleHelper::displaySection($output, 'Time Entry Details', '⏱️');

        // Show smart suggestions with proper timezone handling
        $suggestions = $this->getSmartTimeSuggestions();
        if (!empty($suggestions)) {
            $output->writeln('<fg=yellow>💡 Smart suggestions based on current time:</>');
            foreach ($suggestions as $i => $suggestion) {
                $output->writeln("  [<fg=cyan>{$i}</>] {$suggestion['duration']} - {$suggestion['description']}");
            }
            $output->writeln('');
        }

        // Show common durations
        ConsoleHelper::displayDurationSuggestions($output);

        $choices = [
            'duration' => 'Enter duration (e.g., 2h, 1h30m)',
            'start-end' => 'Specify start and end times',
        ];

        // Only show suggestions if we have valid ones
        if (!empty($suggestions)) {
            $choices['suggestions'] = 'Use smart suggestions';
        }

        $method = ConsoleHelper::askChoice($input, $output, 'How would you like to specify time?', $choices);

        switch ($method) {
            case 'duration':
                $duration = ConsoleHelper::askQuestion($input, $output, 'Enter duration: ');
                $minutes = TimeHelper::parseDuration($duration);
                $endTime = TimeHelper::now();
                $startTime = $endTime->copy()->subMinutes($minutes);
                break;

            case 'start-end':
                $startInput = ConsoleHelper::askQuestion($input, $output, 'Start time (e.g., 9:30am): ');
                $endInput = ConsoleHelper::askQuestion($input, $output, 'End time (e.g., 11:30am, or "now"): ', 'now');

                $startTime = TimeHelper::parseTime($startInput);
                $endTime = $endInput === 'now' ? TimeHelper::now() : TimeHelper::parseTime($endInput);
                $minutes = $endTime->diffInMinutes($startTime);
                break;

            case 'suggestions':
                if (empty($suggestions)) {
                    throw new RuntimeException('No smart suggestions available');
                }

                $suggestionChoices = [];
                foreach ($suggestions as $i => $suggestion) {
                    $suggestionChoices[$i] = "{$suggestion['duration']} - {$suggestion['description']}";
                }

                $selectedIndex = (int) ConsoleHelper::askChoice($input, $output, 'Select suggestion:', $suggestionChoices);
                $selectedSuggestion = $suggestions[$selectedIndex];

                $startTime = TimeHelper::parseTime($selectedSuggestion['start_time']);
                $endTime = TimeHelper::now();
                $minutes = $endTime->diffInMinutes($startTime);
                break;

            default:
                throw new RuntimeException('Invalid time specification method');
        }

        return [
            'start' => $startTime,
            'end' => $endTime,
            'duration' => TimeHelper::formatDuration((int) $minutes),
            'minutes' => (int) $minutes,
        ];
    }

    /**
     * Get smart time suggestions with proper timezone handling.
     */
    private function getSmartTimeSuggestions(): array
    {
        $now = TimeHelper::now(); // This returns Carbon in local timezone
        $suggestions = [];

        // Suggest common work start times in Chile context
        $workStartTimes = ['8:00am', '8:30am', '9:00am', '9:30am', '10:00am'];

        foreach ($workStartTimes as $startTime) {
            try {
                $start = TimeHelper::parseTime($startTime);

                // Only suggest if start time is before current time and within reasonable range
                if ($start->lt($now)) {
                    $duration = $now->diffInMinutes($start);

                    // Only suggest if duration is reasonable (max 12 hours)
                    if ($duration > 0 && $duration <= 720) {
                        $suggestions[] = [
                            'duration' => TimeHelper::formatDuration((int) $duration),
                            'description' => "Desde las {$startTime}",
                            'start_time' => $start->format('H:i'),
                        ];
                    }
                }
            } catch (\InvalidArgumentException $e) {
                // Skip invalid time
                continue;
            }
        }

        return $suggestions;
    }

    private function resolveTask(InputInterface $input, OutputInterface $output, array $timeData): array
    {
        $taskOption = $input->getOption('task');
        $projectOption = $input->getOption('project');
        $auto = $input->getOption('auto');
        $description = $input->getOption('description');

        // Auto-detection from Git
        if ($auto || (!$taskOption && GitHelper::isGitRepository())) {
            $ticketId = GitHelper::extractTicketIdFromBranch();
            if ($ticketId) {
                ConsoleHelper::displayInfo($output, "Auto-detected ticket: {$ticketId}");
                $taskOption = $ticketId;
            }
        }

        // Interactive task selection if not specified
        if (!$taskOption) {
            return $this->interactiveTaskSelection($input, $output);
        }

        return $this->resolveTaskData($taskOption, $projectOption, $description, $input, $output);
    }

    private function interactiveTaskSelection(InputInterface $input, OutputInterface $output): array
    {
        ConsoleHelper::displaySection($output, 'Task Selection', '🎯');

        // Get recent tasks from Jira if available
        $recentTasks = [];
        if ($this->jiraClient) {
            try {
                $recentIssues = $this->jiraClient->getRecentIssues(10);
                foreach ($recentIssues['issues'] as $issue) {
                    $recentTasks[] = [
                        'key' => $issue['key'],
                        'summary' => $issue['fields']['summary'],
                        'project' => $issue['fields']['project']['key'],
                    ];
                }
            } catch (RuntimeException $e) {
                ConsoleHelper::displayWarning($output, 'Could not fetch recent Jira issues: ' . $e->getMessage());
            }
        }

        $choices = ['manual' => 'Enter task manually'];

        if (!empty($recentTasks)) {
            $output->writeln('<fg=yellow>Recent Jira issues:</>');
            foreach ($recentTasks as $i => $task) {
                $choices["recent_{$i}"] = "{$task['key']} - {$task['summary']}";
            }
        }

        $selection = ConsoleHelper::askChoice($input, $output, 'Select task:', $choices);

        if ($selection === 'manual') {
            $taskId = ConsoleHelper::askQuestion($input, $output, 'Enter task ID (e.g., CAM-451): ');
            $description = ConsoleHelper::askQuestion($input, $output, 'Enter description (optional): ');

            return $this->resolveTaskData($taskId, null, $description, $input, $output);
        }

        // Selected a recent task - Fix the str_replace error
        $taskIndexKey = str_replace('recent_', '', $selection);

        // Validate that the key is numeric and exists
        if (!is_numeric($taskIndexKey)) {
            throw new RuntimeException('Invalid task selection');
        }

        $taskIndex = (int) $taskIndexKey;

        // Validate that the task exists
        if (!isset($recentTasks[$taskIndex])) {
            throw new RuntimeException('Selected task not found');
        }

        $selectedTask = $recentTasks[$taskIndex];

        return $this->resolveTaskData($selectedTask['key'], null, null, $input, $output);
    }

    private function resolveTaskData(string $taskId, ?string $projectOption, ?string $description, InputInterface $input, OutputInterface $output): array
    {
        $taskData = ['task_id' => $taskId];

        // Get Jira ticket info if available
        if ($this->jiraClient) {
            try {
                $issue = $this->jiraClient->getIssue($taskId);
                $taskData['jira_issue'] = $issue;
                $taskData['project_key'] = $issue['fields']['project']['key'];
                $taskData['summary'] = $issue['fields']['summary'];
                $taskData['description'] = $description ?: "Work on {$taskId}: {$issue['fields']['summary']}";

                ConsoleHelper::displayInfo($output, "Found Jira issue: {$taskId} - {$issue['fields']['summary']}");
            } catch (RuntimeException $e) {
                ConsoleHelper::displayWarning($output, "Could not fetch Jira issue {$taskId}: " . $e->getMessage());
                $taskData['description'] = $description ?: "Work on {$taskId}";
            }
        } else {
            $taskData['description'] = $description ?: "Work on {$taskId}";
        }

        // Resolve Clockify project
        $clockifyProject = $this->resolveClockifyProject($taskData, $projectOption, $input, $output);
        $taskData['clockify_project'] = $clockifyProject;

        // Resolve or create Clockify task
        $clockifyTask = $this->resolveClockifyTask($clockifyProject['id'], $taskId, $taskData['summary'] ?? null, $output);
        $taskData['clockify_task'] = $clockifyTask;

        return $taskData;
    }

    private function resolveClockifyProject(array $taskData, ?string $projectOption, InputInterface $input, OutputInterface $output): array
    {
        $projects = $this->clockifyClient->getProjects();

        if ($projectOption) {
            // Find project by ID or name
            foreach ($projects as $project) {
                if ($project['id'] === $projectOption || $project['name'] === $projectOption) {
                    return $project;
                }
            }
            throw new RuntimeException("Clockify project '{$projectOption}' not found");
        }

        // Try to find mapped project
        if (isset($taskData['project_key'])) {
            $mappedProject = $this->configManager->getClockifyProjectForJira($taskData['project_key']);
            if ($mappedProject) {
                foreach ($projects as $project) {
                    if ($project['id'] === $mappedProject || $project['name'] === $mappedProject) {
                        ConsoleHelper::displayInfo($output, "Using mapped project: {$project['name']}");

                        return $project;
                    }
                }
                ConsoleHelper::displayWarning($output, 'Mapped project not found, selecting manually...');
            }
        }

        // Interactive project selection with enhanced UI
        ConsoleHelper::displaySection($output, 'Project Selection', '📁');

        $projectChoices = [];
        foreach ($projects as $project) {
            $projectChoices[] = $project['name'];
        }

        // Use enhanced choice selection for large lists
        $selectedProjectName = ConsoleHelper::askChoiceEnhanced(
            $input,
            $output,
            'Select Clockify project:',
            $projectChoices,
            null,
            false,
            15  // Show 15 items per page
        );

        foreach ($projects as $project) {
            if ($project['name'] === $selectedProjectName) {
                // Save mapping for future use
                if (isset($taskData['project_key'])) {
                    $this->configManager->addProjectMapping($taskData['project_key'], $project['id']);
                    ConsoleHelper::displayInfo($output, "Saved project mapping: {$taskData['project_key']} → {$project['name']}");
                }

                return $project;
            }
        }

        throw new RuntimeException('Selected project not found');
    }

    private function resolveClockifyTask(string $projectId, string $taskId, ?string $summary, OutputInterface $output): array
    {
        $taskName = $summary ? "{$taskId} {$summary}" : $taskId;

        // Check if task already exists
        $existingTask = $this->clockifyClient->findTask($projectId, $taskName);

        if ($existingTask) {
            ConsoleHelper::displayInfo($output, "Found existing task: {$taskName}");

            return $existingTask;
        }

        // Create new task
        ConsoleHelper::displayInfo($output, "Creating new task: {$taskName}");

        return $this->clockifyClient->createTask($projectId, $taskName);
    }

    private function displaySummary(OutputInterface $output, array $timeData, array $taskData): void
    {
        ConsoleHelper::displaySection($output, 'Time Entry Summary', '📋');

        $output->writeln("📁 Project: <fg=cyan>{$taskData['clockify_project']['name']}</>");
        $output->writeln("🎯 Task: <fg=green>{$taskData['clockify_task']['name']}</>");
        $output->writeln("📝 Description: {$taskData['description']}");
        $output->writeln("⏱️  Duration: <fg=magenta>{$timeData['duration']}</>");
        $output->writeln("🕐 Start: {$timeData['start']->format('Y-m-d H:i')}");
        $output->writeln("🕕 End: {$timeData['end']->format('Y-m-d H:i')}");
        $output->writeln('');
    }

    private function createTimeEntry(OutputInterface $output, array $timeData, array $taskData): void
    {
        ConsoleHelper::displayProgressBar($output, 'Creating time entry');

        // Convert local times to UTC for API
        $startUtc = TimeHelper::toUtcTime($timeData['start']);
        $endUtc = TimeHelper::toUtcTime($timeData['end']);

        $timeEntryData = [
            'start' => $startUtc->toISOString(),
            'end' => $endUtc->toISOString(),
            'projectId' => $taskData['clockify_project']['id'],
            'taskId' => $taskData['clockify_task']['id'],
            'description' => $taskData['description'],
        ];

        $timeEntry = $this->clockifyClient->createTimeEntry($timeEntryData);

        ConsoleHelper::finishProgressBar($output);
        ConsoleHelper::displaySuccess($output, 'Time entry created successfully!');

        $output->writeln("🔗 Entry ID: <fg=cyan>{$timeEntry['id']}</>");
        $output->writeln("⏱️  Logged: <fg=green>{$timeData['duration']}</> to {$taskData['clockify_project']['name']}");
    }
}
