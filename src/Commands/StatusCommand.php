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
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'status',
    description: 'Show current status, configuration, and active timers',
    aliases: ['info']
)]
class StatusCommand extends Command
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
            ->addOption('detailed', 'd', InputOption::VALUE_NONE, 'Show detailed information')
            ->addOption('config-only', null, InputOption::VALUE_NONE, 'Show only configuration')
            ->addOption('timer-only', null, InputOption::VALUE_NONE, 'Show only timer status')
            ->setHelp('
Show current status and configuration:

<info>Basic usage:</info>
  clockify-wizard status                   # Overview
  clockify-wizard status --detailed        # Detailed view
  clockify-wizard status --config-only     # Configuration only
  clockify-wizard status --timer-only      # Timer status only
            ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            ConsoleHelper::displayHeader($output, 'Clockify CLI Status');

            $detailed = $input->getOption('detailed');
            $configOnly = $input->getOption('config-only');
            $timerOnly = $input->getOption('timer-only');

            if (!$this->configManager->isConfigured()) {
                ConsoleHelper::displayWarning($output, 'Clockify CLI is not configured.');
                $output->writeln('Run: <fg=cyan>clockify-wizard configure</> to get started.');

                return Command::SUCCESS;
            }

            $this->initializeClients();

            if (!$timerOnly) {
                $this->showConfiguration($output, $detailed);
            }

            if (!$configOnly) {
                $this->showActiveTimer($output);
                $this->showGitInfo($output);

                if ($detailed) {
                    $this->showTodaysSummary($output);
                    $this->showRecentActivity($output);
                }
            }

            if (!$configOnly && !$timerOnly) {
                $this->showConnectionStatus($output);
            }

            return Command::SUCCESS;
        } catch (RuntimeException $e) {
            ConsoleHelper::displayError($output, $e->getMessage());

            return Command::FAILURE;
        }
    }

    private function initializeClients(): void
    {
        $clockifyConfig = $this->configManager->getClockifyConfig();
        if (!empty($clockifyConfig['api_key']) && !empty($clockifyConfig['workspace_id'])) {
            $this->clockifyClient = new ClockifyClient(
                $clockifyConfig['api_key'],
                $clockifyConfig['workspace_id']
            );
        }

        $jiraConfig = $this->configManager->getJiraConfig();
        if (!empty($jiraConfig['url']) && !empty($jiraConfig['email']) && !empty($jiraConfig['token'])) {
            $this->jiraClient = new JiraClient(
                $jiraConfig['url'],
                $jiraConfig['email'],
                $jiraConfig['token']
            );
        }
    }

    private function showConfiguration(OutputInterface $output, bool $detailed): void
    {
        $config = $this->configManager->getConfig();

        ConsoleHelper::displaySection($output, 'Configuration', '⚙️');

        // Clockify Configuration
        $clockifyConfig = $config['clockify'] ?? [];
        $output->writeln('<fg=yellow>Clockify:</>');
        $output->writeln('  API Key: ' . (empty($clockifyConfig['api_key']) ? '<fg=red>Not set</>' : '<fg=green>✓ Configured</>'));
        $output->writeln('  Workspace: ' . (empty($clockifyConfig['workspace_id']) ? '<fg=red>Not set</>' : '<fg=green>✓ Configured</>'));

        if ($detailed && !empty($clockifyConfig['workspace_id'])) {
            $output->writeln("  Workspace ID: <fg=cyan>{$clockifyConfig['workspace_id']}</>");
            $output->writeln('  User ID: <fg=cyan>' . ($clockifyConfig['user_id'] ?? 'Unknown') . '</>');
        }

        // Jira Configuration
        $jiraConfig = $config['jira'] ?? [];
        $output->writeln('<fg=yellow>Jira:</>');

        if (empty($jiraConfig['url'])) {
            $output->writeln('  <fg=yellow>⚠ Not configured</>');
        } else {
            $output->writeln("  URL: {$jiraConfig['url']}");
            $output->writeln('  Email: ' . (empty($jiraConfig['email']) ? '<fg=red>Not set</>' : '<fg=green>✓ Configured</>'));
            $output->writeln('  Token: ' . (empty($jiraConfig['token']) ? '<fg=red>Not set</>' : '<fg=green>✓ Configured</>'));
        }

        // Project Mappings
        $mappings = $config['project_mappings'] ?? [];
        $output->writeln('<fg=yellow>Project Mappings:</>');
        if (empty($mappings)) {
            $output->writeln('  <fg=yellow>⚠ None configured</>');
        } else {
            foreach ($mappings as $jira => $clockify) {
                $output->writeln("  <fg=cyan>{$jira}</> → <fg=green>{$clockify}</>");
            }
        }

        // Timer Settings
        if ($detailed) {
            $timerConfig = $config['timer'] ?? [];
            $output->writeln('<fg=yellow>Timer Settings:</>');
            $output->writeln('  Default duration: ' . ($timerConfig['default_duration'] ?? '1h'));
            $output->writeln('  Round to minutes: ' . ($timerConfig['round_to_minutes'] ?? 15));
            $output->writeln('  Auto-detect branch: ' . (($timerConfig['auto_detect_branch'] ?? true) ? 'Yes' : 'No'));
        }

        $output->writeln('');
    }

    private function showActiveTimer(OutputInterface $output): void
    {
        if (!$this->clockifyClient) {
            return;
        }

        try {
            $clockifyConfig = $this->configManager->getClockifyConfig();
            $currentTimer = $this->clockifyClient->getCurrentTimeEntry($clockifyConfig['user_id']);

            if ($currentTimer) {
                $start = Carbon::parse($currentTimer['timeInterval']['start']);
                $elapsed = $start->diffInMinutes(Carbon::now());

                ConsoleHelper::displaySection($output, 'Active Timer', '⏱️');

                $output->writeln("📁 Project: <fg=cyan>{$currentTimer['project']['name']}</>");

                if (isset($currentTimer['task']['name'])) {
                    $output->writeln("🎯 Task: <fg=green>{$currentTimer['task']['name']}</>");
                }

                if (!empty($currentTimer['description'])) {
                    $output->writeln("📝 Description: {$currentTimer['description']}");
                }

                $output->writeln("🕐 Started: {$start->format('H:i')} ({$start->diffForHumans()})");
                $output->writeln('⏱️  Elapsed: <fg=magenta>' . TimeHelper::formatDuration($elapsed) . '</>');
                $output->writeln('');
            } else {
                ConsoleHelper::displaySection($output, 'Timer Status', '⏱️');
                $output->writeln('<fg=yellow>⚠ No active timer</>');
                $output->writeln('Use <fg=cyan>clockify-wizard start</> to begin tracking time.');
                $output->writeln('');
            }
        } catch (RuntimeException $e) {
            ConsoleHelper::displaySection($output, 'Timer Status', '⏱️');
            ConsoleHelper::displayError($output, 'Could not fetch timer status: ' . $e->getMessage());
        }
    }

    private function showGitInfo(OutputInterface $output): void
    {
        if (!GitHelper::isGitRepository()) {
            return;
        }

        $gitInfo = GitHelper::getRepositoryInfo();

        ConsoleHelper::displaySection($output, 'Git Information', '🌿');

        $output->writeln("🌿 Branch: <fg=cyan>{$gitInfo['branch']}</>");

        if ($gitInfo['ticket']) {
            $output->writeln("🎫 Detected ticket: <fg=green>{$gitInfo['ticket']}</>");
        } else {
            $output->writeln('🎫 No ticket detected in branch name');
        }

        $output->writeln("📝 Commit: <fg=yellow>{$gitInfo['commit']}</>");

        if ($gitInfo['has_changes']) {
            $output->writeln('📂 Working directory: <fg=yellow>⚠ Has uncommitted changes</>');
        } else {
            $output->writeln('📂 Working directory: <fg=green>✓ Clean</>');
        }

        if ($gitInfo['upstream']['ahead'] > 0 || $gitInfo['upstream']['behind'] > 0) {
            $ahead = $gitInfo['upstream']['ahead'];
            $behind = $gitInfo['upstream']['behind'];
            $output->writeln("🔄 Sync status: <fg=yellow>⚠ {$ahead} ahead, {$behind} behind</>");
        } else {
            $output->writeln('🔄 Sync status: <fg=green>✓ In sync</>');
        }

        $output->writeln('');
    }

    private function showTodaysSummary(OutputInterface $output): void
    {
        if (!$this->clockifyClient) {
            return;
        }

        try {
            $clockifyConfig = $this->configManager->getClockifyConfig();
            $today = Carbon::today();
            $tomorrow = Carbon::tomorrow();

            $timeEntries = $this->clockifyClient->getTimeEntries(
                $clockifyConfig['user_id'],
                $today,
                $tomorrow
            );

            if (empty($timeEntries)) {
                return;
            }

            ConsoleHelper::displaySection($output, 'Today\'s Summary', '📊');

            $totalMinutes = TimeHelper::calculateTotalDuration($timeEntries);
            $output->writeln('⏱️  Total time today: <fg=green>' . TimeHelper::formatDuration($totalMinutes) . '</>');
            $output->writeln('📈 Entries: ' . count($timeEntries));

            // Group by project
            $projectSummary = [];
            foreach ($timeEntries as $entry) {
                $projectName = $entry['project']['name'];
                if (!isset($projectSummary[$projectName])) {
                    $projectSummary[$projectName] = 0;
                }

                $start = Carbon::parse($entry['timeInterval']['start']);
                $end = isset($entry['timeInterval']['end'])
                    ? Carbon::parse($entry['timeInterval']['end'])
                    : Carbon::now();

                $projectSummary[$projectName] += $end->diffInMinutes($start);
            }

            $output->writeln('');
            $output->writeln('<fg=yellow>By project:</>');
            foreach ($projectSummary as $project => $minutes) {
                $output->writeln("  <fg=cyan>{$project}</>: " . TimeHelper::formatDuration($minutes));
            }

            $output->writeln('');
        } catch (RuntimeException $e) {
            // Silently skip if can't fetch today's summary
        }
    }

    private function showRecentActivity(OutputInterface $output): void
    {
        if (!$this->clockifyClient) {
            return;
        }

        try {
            $clockifyConfig = $this->configManager->getClockifyConfig();
            $timeEntries = $this->clockifyClient->getTimeEntries(
                $clockifyConfig['user_id'],
                Carbon::now()->subDays(7),
                Carbon::now()
            );

            if (empty($timeEntries)) {
                return;
            }

            // Get last 5 entries
            $recentEntries = array_slice($timeEntries, 0, 5);

            ConsoleHelper::displaySection($output, 'Recent Activity', '📅');

            $table = new Table($output);
            $table->setHeaders(['Date', 'Duration', 'Project', 'Task']);

            foreach ($recentEntries as $entry) {
                $start = Carbon::parse($entry['timeInterval']['start']);
                $end = isset($entry['timeInterval']['end'])
                    ? Carbon::parse($entry['timeInterval']['end'])
                    : Carbon::now();

                $duration = $end->diffInMinutes($start);

                $table->addRow([
                    $start->format('M d'),
                    TimeHelper::formatDuration($duration),
                    $entry['project']['name'],
                    $entry['task']['name'] ?? 'No task',
                ]);
            }

            $table->render();
            $output->writeln('');
        } catch (RuntimeException $e) {
            // Silently skip if can't fetch recent activity
        }
    }

    private function showConnectionStatus(OutputInterface $output): void
    {
        ConsoleHelper::displaySection($output, 'Connection Status', '🔍');

        // Test Clockify
        if ($this->clockifyClient) {
            try {
                $this->clockifyClient->testConnection();
                $output->writeln('🕐 Clockify: <fg=green>✓ Connected</>');
            } catch (RuntimeException $e) {
                $output->writeln('🕐 Clockify: <fg=red>✗ Connection failed</>');
                $output->writeln("   Error: {$e->getMessage()}");
            }
        } else {
            $output->writeln('🕐 Clockify: <fg=yellow>⚠ Not configured</>');
        }

        // Test Jira
        if ($this->jiraClient) {
            try {
                $this->jiraClient->testConnection();
                $output->writeln('🎫 Jira: <fg=green>✓ Connected</>');
            } catch (RuntimeException $e) {
                $output->writeln('🎫 Jira: <fg=red>✗ Connection failed</>');
                $output->writeln("   Error: {$e->getMessage()}");
            }
        } else {
            $output->writeln('🎫 Jira: <fg=yellow>⚠ Not configured</>');
        }

        $output->writeln('');

        // Show help if there are issues
        $clockifyOk = $this->clockifyClient && $this->clockifyClient->testConnection();
        if (!$clockifyOk) {
            $output->writeln('<fg=yellow>💡 Run "clockify-wizard configure" to fix connection issues.</>');
        }
    }
}
