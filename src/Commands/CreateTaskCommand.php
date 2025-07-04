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
            ->setHelp('
Create a new task in Clockify:

<info>Basic usage:</info>
  clockify-wizard create-task CAM-451
  clockify-wizard create-task CAM-451 --project "My Project"
  clockify-wizard create-task CAM-451 --task-name "Custom Task Name"

<info>The command will:</info>
  • Fetch ticket info from Jira (if configured)
  • Create task with format "TICKET-ID Summary"
  • Associate with correct Clockify project
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
