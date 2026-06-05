<?php

declare(strict_types=1);

namespace MiLopez\ClockifyWizard\Commands;

use MiLopez\ClockifyWizard\Client\ClockifyClient;
use MiLopez\ClockifyWizard\Client\JiraClient;
use MiLopez\ClockifyWizard\Config\ConfigManager;
use MiLopez\ClockifyWizard\Helper\ConsoleHelper;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'create-task',
    description: 'Create a new task in Clockify from Jira ticket',
    aliases: ['task']
)]
class CreateTaskCommand extends Command
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
            ->addArgument('ticket-id', InputArgument::REQUIRED, 'Jira ticket ID (e.g., CAM-451)')
            ->addOption('project', 'p', InputOption::VALUE_REQUIRED, 'Clockify project ID or name')
            ->addOption('task-name', 't', InputOption::VALUE_REQUIRED, 'Custom task name')  // Changed from 'name' to 'task-name' and shortcut from 'n' to 't'
            ->addOption('status', 's', InputOption::VALUE_REQUIRED, 'Task status (ACTIVE/DONE)', 'ACTIVE')
            ->addOption('summary', null, InputOption::VALUE_REQUIRED, 'Ticket summary; builds "TICKET-ID summary" without querying Jira')
            ->addOption('no-jira', null, InputOption::VALUE_NONE, 'Never query Jira (use --summary/--task-name and --project instead)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output result as JSON (non-interactive)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be created without writing to Clockify')
            ->setHelp('
Create a new task in Clockify:

<info>Basic usage:</info>
  clockify-wizard create-task CAM-451
  clockify-wizard create-task CAM-451 --project "My Project"
  clockify-wizard create-task CAM-451 --task-name "Custom Task Name"

<info>Non-interactive (AI agents / scripts):</info>
  clockify-wizard create-task CAM-451 --json
  clockify-wizard create-task CAM-451 --project "My Project" --json
  clockify-wizard create-task CAM-451 --dry-run

<info>Skip the Jira lookup (you already know the summary):</info>
  clockify-wizard create-task CAM-451 --project "My Project" --summary "Fix checkout" --no-jira --json
  # → creates task "CAM-451 Fix checkout" with no call to Jira

<info>The command will:</info>
  • Fetch the summary from Jira only when needed (skipped with --summary/--no-jira)
  • Create task with format "TICKET-ID Summary"
  • Associate with correct Clockify project (flag or saved mapping)
            ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->initializeClients();

            $ticketId = $input->getArgument('ticket-id');
            $projectOption = $input->getOption('project');
            $customName = $input->getOption('task-name');  // Updated reference
            $status = $input->getOption('status');

            $json = (bool) $input->getOption('json');
            $dryRun = (bool) $input->getOption('dry-run');

            if ($json || $dryRun || !$input->isInteractive()) {
                return $this->executeNonInteractive($input, $output, $ticketId, $projectOption, $customName, $status, $json, $dryRun);
            }

            ConsoleHelper::displayHeader($output, 'Create Clockify Task');

            // Get ticket info from Jira
            $ticketInfo = $this->getTicketInfo($output, $ticketId);

            // Determine task name
            $taskName = $customName ?: $this->generateTaskName($ticketId, $ticketInfo);

            // Resolve project
            $project = $this->resolveProject($input, $output, $ticketInfo, $projectOption);

            // Check if task already exists
            $existingTask = $this->clockifyClient->findTask($project['id'], $taskName);
            if ($existingTask) {
                ConsoleHelper::displayWarning($output, "Task already exists: {$taskName}");
                $output->writeln("Task ID: <fg=cyan>{$existingTask['id']}</>");

                return Command::SUCCESS;
            }

            // Show summary
            $this->showTaskSummary($output, $taskName, $project, $ticketInfo);

            if (!ConsoleHelper::askConfirmation($input, $output, 'Create this task?', true)) {
                ConsoleHelper::displayInfo($output, 'Task creation cancelled.');

                return Command::SUCCESS;
            }

            // Create the task
            $task = $this->createTask($output, $project['id'], $taskName, $status);

            ConsoleHelper::displaySuccess($output, 'Task created successfully!');
            $output->writeln("🎯 Task: <fg=green>{$task['name']}</>");
            $output->writeln("📁 Project: <fg=cyan>{$project['name']}</>");
            $output->writeln("🆔 Task ID: <fg=yellow>{$task['id']}</>");

            return Command::SUCCESS;
        } catch (RuntimeException $e) {
            ConsoleHelper::displayError($output, $e->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * Non-interactive path for AI agents / scripts: resolve everything from
     * flags + saved mappings, never prompt, and emit a machine-readable result.
     */
    private function executeNonInteractive(
        InputInterface $input,
        OutputInterface $output,
        string $ticketId,
        ?string $projectOption,
        ?string $customName,
        string $status,
        bool $json,
        bool $dryRun
    ): int {
        try {
            $summary = $input->getOption('summary');
            $noJira = (bool) $input->getOption('no-jira');

            // The only thing Jira gives us here is the summary for the task name.
            // If the caller already provides a name (--task-name/--summary) or
            // forbids Jira, skip the lookup entirely.
            $needJira = !$noJira && !$customName && ($summary === null || $summary === '');
            $ticketInfo = $needJira ? $this->fetchTicketSilently($ticketId) : null;

            $projectKey = $ticketInfo['fields']['project']['key'] ?? $this->projectKeyFromTicket($ticketId);

            if ($customName) {
                $taskName = $customName;
            } elseif ($summary !== null && $summary !== '') {
                $taskName = "{$ticketId} {$summary}";
            } else {
                $taskName = $this->generateTaskName($ticketId, $ticketInfo);
            }

            $project = $this->resolveProjectNonInteractive($ticketId, $projectKey, $projectOption);

            if (!$dryRun && $projectOption && $projectKey) {
                $this->configManager->addProjectMapping($projectKey, $project['id']);
            }

            $existingTask = $this->clockifyClient->findTask($project['id'], $taskName);

            if ($dryRun) {
                return $this->emit($output, $json, [
                    'dryRun' => true,
                    'name' => $taskName,
                    'status' => $status,
                    'projectId' => $project['id'],
                    'projectName' => $project['name'],
                    'jiraKey' => $ticketId,
                    'existed' => $existingTask !== null,
                ]);
            }

            $task = $existingTask ?: $this->clockifyClient->createTask($project['id'], $taskName, $status);

            return $this->emit($output, $json, [
                'id' => $task['id'],
                'name' => $task['name'],
                'status' => $task['status'] ?? $status,
                'projectId' => $project['id'],
                'projectName' => $project['name'],
                'jiraKey' => $ticketId,
                'existed' => $existingTask !== null,
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
            $output->writeln((string) ($payload['id'] ?? $payload['name'] ?? ''));
        }

        return Command::SUCCESS;
    }

    private function fetchTicketSilently(string $ticketId): ?array
    {
        if (!$this->jiraClient) {
            return null;
        }

        try {
            return $this->jiraClient->getIssue($ticketId);
        } catch (RuntimeException $e) {
            return null;
        }
    }

    private function resolveProjectNonInteractive(string $ticketId, ?string $projectKey, ?string $projectOption): array
    {
        $projects = $this->clockifyClient->getProjects();

        $needle = $projectOption;
        if (!$needle && $projectKey) {
            $needle = $this->configManager->getClockifyProjectForJira($projectKey);
        }

        if (!$needle) {
            $hint = $projectKey
                ? "Pass --project=<id|name>, or set a mapping once: clockify-wizard map {$projectKey} \"<Clockify project>\"."
                : 'Pass --project=<id|name>.';
            throw new RuntimeException("Could not resolve a Clockify project for {$ticketId}. {$hint}");
        }

        foreach ($projects as $project) {
            if ($project['id'] === $needle || strcasecmp($project['name'], $needle) === 0) {
                return $project;
            }
        }

        throw new RuntimeException("Clockify project '{$needle}' not found.");
    }

    private function projectKeyFromTicket(string $ticketId): ?string
    {
        if (preg_match('/^([A-Za-z]+)[-_]\d+/', $ticketId, $m)) {
            return strtoupper($m[1]);
        }

        return null;
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

        $jiraConfig = $this->configManager->getJiraConfig();
        if (!empty($jiraConfig['url']) && !empty($jiraConfig['email']) && !empty($jiraConfig['token'])) {
            $this->jiraClient = new JiraClient(
                $jiraConfig['url'],
                $jiraConfig['email'],
                $jiraConfig['token']
            );
        }
    }

    private function getTicketInfo(OutputInterface $output, string $ticketId): ?array
    {
        if (!$this->jiraClient) {
            ConsoleHelper::displayWarning($output, 'Jira not configured. Creating task with ticket ID only.');

            return null;
        }

        try {
            ConsoleHelper::displayProgressBar($output, "Fetching Jira ticket {$ticketId}");
            $issue = $this->jiraClient->getIssue($ticketId);
            ConsoleHelper::finishProgressBar($output);

            ConsoleHelper::displayInfo($output, "Found: {$ticketId} - {$issue['fields']['summary']}");

            return $issue;
        } catch (RuntimeException $e) {
            ConsoleHelper::finishProgressBar($output);
            ConsoleHelper::displayWarning($output, "Could not fetch Jira ticket: {$e->getMessage()}");

            return null;
        }
    }

    private function generateTaskName(string $ticketId, ?array $ticketInfo): string
    {
        if (!$ticketInfo) {
            return $ticketId;
        }

        $summary = $ticketInfo['fields']['summary'];

        return "{$ticketId} {$summary}";
    }

    private function resolveProject(InputInterface $input, OutputInterface $output, ?array $ticketInfo, ?string $projectOption): array
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
        if ($ticketInfo) {
            $jiraProjectKey = $ticketInfo['fields']['project']['key'];
            $mappedProject = $this->configManager->getClockifyProjectForJira($jiraProjectKey);

            if ($mappedProject) {
                foreach ($projects as $project) {
                    if ($project['id'] === $mappedProject || $project['name'] === $mappedProject) {
                        ConsoleHelper::displayInfo($output, "Using mapped project: {$project['name']}");

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
                // Save mapping for future use
                if ($ticketInfo) {
                    $jiraProjectKey = $ticketInfo['fields']['project']['key'];
                    $this->configManager->addProjectMapping($jiraProjectKey, $project['id']);
                    ConsoleHelper::displayInfo($output, "Saved project mapping: {$jiraProjectKey} → {$project['name']}");
                }

                return $project;
            }
        }

        throw new RuntimeException('Selected project not found');
    }

    private function showTaskSummary(OutputInterface $output, string $taskName, array $project, ?array $ticketInfo): void
    {
        ConsoleHelper::displaySection($output, 'Task Summary', '📋');

        $output->writeln("🎯 Task name: <fg=green>{$taskName}</>");
        $output->writeln("📁 Project: <fg=cyan>{$project['name']}</>");

        if ($ticketInfo) {
            $output->writeln("🎫 Jira ticket: {$ticketInfo['key']}");
            $output->writeln("📊 Status: {$ticketInfo['fields']['status']['name']}");
            $output->writeln("🎯 Type: {$ticketInfo['fields']['issuetype']['name']}");

            if (isset($ticketInfo['fields']['assignee']['displayName'])) {
                $output->writeln("👤 Assignee: {$ticketInfo['fields']['assignee']['displayName']}");
            }
        }

        $output->writeln('');
    }

    private function createTask(OutputInterface $output, string $projectId, string $taskName, string $status): array
    {
        ConsoleHelper::displayProgressBar($output, 'Creating task in Clockify');

        $task = $this->clockifyClient->createTask($projectId, $taskName, $status);

        ConsoleHelper::finishProgressBar($output);

        return $task;
    }
}
