<?php

declare(strict_types=1);

namespace MiLopez\ClockifyWizard\Commands;

use Carbon\Carbon;
use MiLopez\ClockifyWizard\Client\ClockifyClient;
use MiLopez\ClockifyWizard\Client\JiraClient;
use MiLopez\ClockifyWizard\Config\ConfigManager;
use MiLopez\ClockifyWizard\Helper\ConsoleHelper;
use MiLopez\ClockifyWizard\Helper\GitHelper;
use MiLopez\ClockifyWizard\Service\TaskResolver;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'start',
    description: 'Start a timer for time tracking',
    aliases: ['begin']
)]
class StartCommand extends Command
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
            ->addArgument('task', InputArgument::OPTIONAL, 'Task ID (e.g., CAM-451)')
            ->addOption('project', 'p', InputOption::VALUE_REQUIRED, 'Clockify project ID or name')
            ->addOption('description', 'd', InputOption::VALUE_REQUIRED, 'Description for the timer')
            ->addOption('auto', 'a', InputOption::VALUE_NONE, 'Auto-detect task from Git branch')
            ->addOption('task-name', null, InputOption::VALUE_REQUIRED, 'Custom Clockify task name')
            ->addOption('tags', null, InputOption::VALUE_REQUIRED, 'Comma-separated tags/labels (created if missing)')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Stop any running timer before starting')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output result as JSON (non-interactive)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be started without writing to Clockify')
            ->setHelp('
Start a timer for time tracking:

<info>Basic usage:</info>
  clockify-wizard start CAM-451
  clockify-wizard start --auto
  clockify-wizard start

<info>With options:</info>
  clockify-wizard start CAM-451 --project "My Project"
  clockify-wizard start CAM-451 --description "Working on feature implementation"

<info>Non-interactive (AI agents / scripts):</info>
  clockify-wizard start CAM-451 --json
  clockify-wizard start CAM-451 --tags "ai-agent,backend" --json
  clockify-wizard start CAM-451 --force --json   # replace a running timer
            ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->initializeClients();

            $json = (bool) $input->getOption('json');
            $dryRun = (bool) $input->getOption('dry-run');

            if ($json || $dryRun || !$input->isInteractive()) {
                return $this->executeNonInteractive($input, $output, $json, $dryRun);
            }

            ConsoleHelper::displayHeader($output, 'Start Timer');

            // Check if there's already an active timer
            $activeTimer = $this->checkActiveTimer($output);
            if ($activeTimer) {
                if (!ConsoleHelper::askConfirmation($input, $output, 'Stop current timer and start new one?', false)) {
                    ConsoleHelper::displayInfo($output, 'Timer start cancelled.');

                    return Command::SUCCESS;
                }
                $this->stopCurrentTimer($output);
            }

            // Show Git info if available
            if (GitHelper::isGitRepository()) {
                ConsoleHelper::displayGitInfo($output);
            }

            $taskData = $this->resolveTask($input, $output);

            // Display confirmation
            $this->displayStartSummary($output, $taskData);

            if (!ConsoleHelper::askConfirmation($input, $output, 'Start this timer?', true)) {
                ConsoleHelper::displayInfo($output, 'Timer start cancelled.');

                return Command::SUCCESS;
            }

            // Start the timer
            $this->startTimer($output, $taskData);

            return Command::SUCCESS;
        } catch (RuntimeException $e) {
            ConsoleHelper::displayError($output, $e->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * Non-interactive path for AI agents / scripts. Resolves the task from
     * flags + saved mappings (never prompts) and starts a live timer.
     */
    private function executeNonInteractive(InputInterface $input, OutputInterface $output, bool $json, bool $dryRun): int
    {
        try {
            $ticketId = $input->getArgument('task');
            if (!$ticketId && ($input->getOption('auto') || GitHelper::isGitRepository())) {
                $ticketId = GitHelper::extractTicketIdFromBranch();
            }

            if (!$ticketId) {
                throw new RuntimeException('A task id is required (argument or --auto from a Git branch).');
            }

            $clockifyConfig = $this->configManager->getClockifyConfig();
            $userId = $clockifyConfig['user_id'] ?? '';

            // Refuse to silently replace a running timer unless --force.
            $running = $userId ? $this->clockifyClient->getCurrentTimeEntryWithFallback($userId) : null;
            if ($running && !$input->getOption('force')) {
                throw new RuntimeException(
                    'A timer is already running (id ' . $running['id'] . '). Use --force to stop it and start a new one.'
                );
            }

            $resolver = new TaskResolver($this->clockifyClient, $this->configManager, $this->jiraClient);
            $taskData = $resolver->resolve(
                (string) $ticketId,
                $input->getOption('project'),
                $input->getOption('description'),
                $this->parseTags($input->getOption('tags')),
                $input->getOption('task-name'),
                !$dryRun
            );

            $project = $taskData['clockify_project'];
            $task = $taskData['clockify_task'];

            if ($dryRun) {
                return $this->emit($output, $json, [
                    'dryRun' => true,
                    'projectId' => $project['id'],
                    'projectName' => $project['name'],
                    'taskId' => $task['id'],
                    'taskName' => $task['name'],
                    'description' => $taskData['description'],
                    'tagIds' => $taskData['tag_ids'],
                    'willStopRunning' => $running ? $running['id'] : null,
                ]);
            }

            if ($running && $userId) {
                $this->clockifyClient->stopTimer($userId);
                $this->configManager->clearActiveTimer();
            }

            $timeEntry = $this->clockifyClient->startTimer(
                $project['id'],
                $task['id'],
                $taskData['description'],
                $taskData['tag_ids']
            );

            $this->configManager->saveActiveTimer([
                'id' => $timeEntry['id'],
                'project' => $project['name'],
                'task' => $task['name'],
                'start' => $timeEntry['timeInterval']['start'],
                'description' => $taskData['description'],
                'project_id' => $project['id'],
                'task_id' => $task['id'],
            ]);

            return $this->emit($output, $json, [
                'id' => $timeEntry['id'],
                'projectId' => $project['id'],
                'projectName' => $project['name'],
                'taskId' => $task['id'],
                'taskName' => $task['name'],
                'description' => $taskData['description'],
                'start' => $timeEntry['timeInterval']['start'],
                'tagIds' => $taskData['tag_ids'],
            ]);
        } catch (RuntimeException $e) {
            if ($json) {
                $output->writeln(json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } else {
                $output->writeln('<error>' . $e->getMessage() . '</error>');
            }

            return Command::FAILURE;
        }
    }

    private function emit(OutputInterface $output, bool $json, array $payload): int
    {
        if ($json) {
            $output->writeln(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $output->writeln((string) ($payload['id'] ?? ''));
        }

        return Command::SUCCESS;
    }

    /**
     * @return string[]
     */
    private function parseTags(?string $raw): array
    {
        if (!$raw) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn ($t) => $t !== ''));
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

    private function checkActiveTimer(OutputInterface $output): ?array
    {
        $clockifyConfig = $this->configManager->getClockifyConfig();
        $currentTimer = $this->clockifyClient->getCurrentTimeEntry($clockifyConfig['user_id']);

        if ($currentTimer) {
            ConsoleHelper::displaySection($output, 'Active Timer Detected', '⚠️');

            $start = Carbon::parse($currentTimer['timeInterval']['start']);
            $elapsed = $start->diffInMinutes(Carbon::now());

            $output->writeln("📁 Project: <fg=cyan>{$currentTimer['project']['name']}</>");
            $output->writeln('🎯 Task: <fg=green>' . ($currentTimer['task']['name'] ?? 'No task') . '</>');
            $output->writeln('📝 Description: ' . ($currentTimer['description'] ?? 'No description'));
            $output->writeln("🕐 Started: {$start->format('H:i')} ({$start->diffForHumans()})");
            $output->writeln('⏱️  Elapsed: <fg=magenta>' . $this->formatDuration((int) $elapsed) . '</>');
            $output->writeln('');

            return $currentTimer;
        }

        return null;
    }

    private function stopCurrentTimer(OutputInterface $output): void
    {
        ConsoleHelper::displayProgressBar($output, 'Stopping current timer');

        $clockifyConfig = $this->configManager->getClockifyConfig();
        $this->clockifyClient->stopTimer($clockifyConfig['user_id']);
        $this->configManager->clearActiveTimer();

        ConsoleHelper::finishProgressBar($output);
    }

    private function resolveTask(InputInterface $input, OutputInterface $output): array
    {
        $taskArgument = $input->getArgument('task');
        $projectOption = $input->getOption('project');
        $auto = $input->getOption('auto');
        $description = $input->getOption('description');

        // Auto-detection from Git
        if ($auto || (!$taskArgument && GitHelper::isGitRepository())) {
            $ticketId = GitHelper::extractTicketIdFromBranch();
            if ($ticketId) {
                ConsoleHelper::displayInfo($output, "Auto-detected ticket: {$ticketId}");
                $taskArgument = $ticketId;
            }
        }

        // Interactive task selection if not specified
        if (!$taskArgument) {
            return $this->interactiveTaskSelection($input, $output);
        }

        return $this->resolveTaskData($taskArgument, $projectOption, $description, $input, $output);
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

        // Selected a recent task
        $taskIndex = (int) str_replace('recent_', '', array_search($selection, $choices));
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
                        return $project;
                    }
                }
            }
        }

        // Interactive project selection
        ConsoleHelper::displaySection($output, 'Project Selection', '📁');

        $projectChoices = [];
        foreach ($projects as $project) {
            $projectChoices[] = $project['name'];
        }

        $selectedProjectName = ConsoleHelper::askChoice($input, $output, 'Select Clockify project:', $projectChoices);

        foreach ($projects as $project) {
            if ($project['name'] === $selectedProjectName) {
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

        $existingTask = $this->clockifyClient->findTask($projectId, $taskName);

        if ($existingTask) {
            ConsoleHelper::displayInfo($output, "Found existing task: {$taskName}");

            return $existingTask;
        }

        ConsoleHelper::displayInfo($output, "Creating new task: {$taskName}");

        return $this->clockifyClient->createTask($projectId, $taskName);
    }

    private function displayStartSummary(OutputInterface $output, array $taskData): void
    {
        ConsoleHelper::displaySection($output, 'Timer Summary', '⏱️');

        $output->writeln("📁 Project: <fg=cyan>{$taskData['clockify_project']['name']}</>");
        $output->writeln("🎯 Task: <fg=green>{$taskData['clockify_task']['name']}</>");
        $output->writeln("📝 Description: {$taskData['description']}");
        $output->writeln('🕐 Start time: <fg=magenta>' . Carbon::now()->format('H:i') . '</>');
        $output->writeln('');
    }

    private function startTimer(OutputInterface $output, array $taskData): void
    {
        ConsoleHelper::displayProgressBar($output, 'Starting timer');

        try {
            $timeEntry = $this->clockifyClient->startTimer(
                $taskData['clockify_project']['id'],
                $taskData['clockify_task']['id'],
                $taskData['description']
            );

            // Debug: Show what we got back from API
            if (!isset($timeEntry['id'])) {
                ConsoleHelper::finishProgressBar($output);
                throw new RuntimeException('Timer creation failed: No timer ID returned from API. Response: ' . json_encode($timeEntry));
            }

            // Save active timer info with all necessary data
            $timerData = [
                'id' => $timeEntry['id'],
                'project' => $taskData['clockify_project']['name'],
                'task' => $taskData['clockify_task']['name'],
                'start' => $timeEntry['timeInterval']['start'],
                'description' => $taskData['description'],
                'project_id' => $taskData['clockify_project']['id'],
                'task_id' => $taskData['clockify_task']['id'],
            ];

            $this->configManager->saveActiveTimer($timerData);

            // Verify the timer was saved
            $savedTimer = $this->configManager->getActiveTimer();
            if (!$savedTimer || $savedTimer['id'] !== $timeEntry['id']) {
                ConsoleHelper::finishProgressBar($output);
                throw new RuntimeException('Failed to save timer state locally');
            }

            ConsoleHelper::finishProgressBar($output);
            ConsoleHelper::displaySuccess($output, 'Timer started successfully!');

            $output->writeln("🔗 Timer ID: <fg=cyan>{$timeEntry['id']}</>");
            $output->writeln("⏱️  Tracking: <fg=green>{$taskData['clockify_task']['name']}</>");
            $output->writeln('');
            $output->writeln('<fg=yellow>💡 Use "clockify-wizard stop" to stop the timer</>');

            // Debug verification
            $output->writeln('');
            $output->writeln('<fg=blue>🔍 Verification:</>');
            $output->writeln("Local timer saved: <fg=green>Yes</> (ID: {$savedTimer['id']})");
        } catch (\Exception $e) {
            ConsoleHelper::finishProgressBar($output);
            throw new RuntimeException('Failed to start timer: ' . $e->getMessage());
        }
    }

    private function formatDuration(int $minutes): string
    {
        if ($minutes < 60) {
            return "{$minutes}m";
        }

        $hours = floor($minutes / 60);
        $remainingMinutes = $minutes % 60;

        if ($remainingMinutes === 0) {
            return "{$hours}h";
        }

        return "{$hours}h{$remainingMinutes}m";
    }
}
