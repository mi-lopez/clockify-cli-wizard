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
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'configure',
    description: 'Configure Clockify and Jira credentials and settings',
    aliases: ['config', 'setup']
)]
class ConfigureCommand extends Command
{
    private ConfigManager $configManager;

    public function __construct()
    {
        parent::__construct();
        $this->configManager = new ConfigManager();
    }

    protected function configure(): void
    {
        $this
            ->addOption('reset', 'r', InputOption::VALUE_NONE, 'Reset all configuration')
            ->addOption('clockify-only', null, InputOption::VALUE_NONE, 'Configure only Clockify settings')
            ->addOption('jira-only', null, InputOption::VALUE_NONE, 'Configure only Jira settings')
            ->setHelp('
Configure Clockify CLI Wizard:

<info>Basic usage:</info>
  clockify-wizard configure                # Full setup wizard
  clockify-wizard configure --reset       # Reset all settings
  clockify-wizard configure --clockify-only
  clockify-wizard configure --jira-only

<info>You will need:</info>
  • Clockify API key (from clockify.me/user/settings)
  • Clockify workspace ID
  • Jira URL, email, and API token (optional)

<info>Environment variables (optional):</info>
  CLOCKIFY_API_KEY, CLOCKIFY_WORKSPACE_ID
  JIRA_URL, JIRA_EMAIL, JIRA_TOKEN
            ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            ConsoleHelper::displayHeader($output, 'Configuration Wizard');

            // Check if we migrated config from old Jira CLI
            if (method_exists($this->configManager, 'wasMigrated') && $this->configManager->wasMigrated()) {
                ConsoleHelper::displayInfo($output, '🔄 Detected and migrated Jira configuration from previous Jira CLI setup!');
                $output->writeln('');
            }

            $reset = $input->getOption('reset');
            $clockifyOnly = $input->getOption('clockify-only');
            $jiraOnly = $input->getOption('jira-only');

            if ($reset) {
                $this->resetConfiguration($input, $output);

                return Command::SUCCESS;
            }

            // Show current configuration if exists
            if ($this->configManager->isConfigured() && !$clockifyOnly && !$jiraOnly) {
                $this->displayCurrentConfig($output);

                if (!ConsoleHelper::askConfirmation($input, $output, 'Update configuration?', false)) {
                    ConsoleHelper::displayInfo($output, 'Configuration unchanged.');

                    return Command::SUCCESS;
                }
            }

            $config = $this->configManager->getConfig();

            // Configure Clockify
            if (!$jiraOnly) {
                $config = $this->configureClockify($input, $output, $config);
            }

            // Configure Jira (optional)
            if (!$clockifyOnly) {
                $config = $this->configureJira($input, $output, $config);
            }

            // Configure general settings
            if (!$clockifyOnly && !$jiraOnly) {
                $config = $this->configureGeneralSettings($input, $output, $config);
            }

            // Save configuration
            $this->configManager->setConfig($config);

            // Test connections
            $this->testConnections($output, $config);

            ConsoleHelper::displaySuccess($output, 'Configuration saved successfully!');

            return Command::SUCCESS;
        } catch (RuntimeException $e) {
            ConsoleHelper::displayError($output, $e->getMessage());

            return Command::FAILURE;
        }
    }

    private function resetConfiguration(InputInterface $input, OutputInterface $output): void
    {
        if (!ConsoleHelper::askConfirmation($input, $output, 'Are you sure you want to reset all configuration?', false)) {
            ConsoleHelper::displayInfo($output, 'Configuration reset cancelled.');

            return;
        }

        $this->configManager->setConfig([]);
        ConsoleHelper::displaySuccess($output, 'Configuration reset successfully!');
    }

    private function displayCurrentConfig(OutputInterface $output): void
    {
        $config = $this->configManager->getConfig();

        ConsoleHelper::displaySection($output, 'Current Configuration', '⚙️');

        // Clockify config
        $clockifyConfig = $config['clockify'] ?? [];
        $output->writeln('<fg=yellow>Clockify:</>');
        $output->writeln('  API Key: ' . (empty($clockifyConfig['api_key']) ? '<fg=red>Not set</>' : '<fg=green>Configured</>'));
        $output->writeln('  Workspace: ' . (empty($clockifyConfig['workspace_id']) ? '<fg=red>Not set</>' : '<fg=green>Configured</>'));

        // Jira config
        $jiraConfig = $config['jira'] ?? [];
        $output->writeln('<fg=yellow>Jira:</>');
        $output->writeln('  URL: ' . ($jiraConfig['url'] ?? '<fg=red>Not set</>'));
        $output->writeln('  Email: ' . (empty($jiraConfig['email']) ? '<fg=red>Not set</>' : '<fg=green>Configured</>'));
        $output->writeln('  Token: ' . (empty($jiraConfig['token']) ? '<fg=red>Not set</>' : '<fg=green>Configured</>'));

        // Project mappings
        $mappings = $config['project_mappings'] ?? [];
        $output->writeln('<fg=yellow>Project Mappings:</>');
        if (empty($mappings)) {
            $output->writeln('  <fg=red>None configured</>');
        } else {
            foreach ($mappings as $jira => $clockify) {
                $output->writeln("  {$jira} → {$clockify}");
            }
        }

        $output->writeln('');
    }

    private function configureClockify(InputInterface $input, OutputInterface $output, array $config): array
    {
        ConsoleHelper::displaySection($output, 'Clockify Configuration', '🕐');

        $output->writeln('<fg=yellow>Get your API key from: https://clockify.me/user/settings</>');
        $output->writeln('');

        // API Key
        $currentApiKey = $config['clockify']['api_key'] ?? '';
        $apiKey = $this->getConfigValue(
            $input,
            $output,
            'Enter Clockify API key',
            $currentApiKey,
            'CLOCKIFY_API_KEY',
            true
        );

        if (empty($apiKey)) {
            throw new RuntimeException('Clockify API key is required');
        }

        // Test API key and get workspaces
        $output->writeln('');
        ConsoleHelper::displayProgressBar($output, 'Testing API key and fetching workspaces');

        try {
            $tempClient = new ClockifyClient($apiKey, ''); // Empty workspace for now
            $workspaces = $tempClient->getWorkspaces();
            $user = $tempClient->getCurrentUser();

            ConsoleHelper::finishProgressBar($output);
            ConsoleHelper::displaySuccess($output, 'API key is valid!');
        } catch (RuntimeException $e) {
            ConsoleHelper::finishProgressBar($output);
            throw new RuntimeException('Invalid Clockify API key: ' . $e->getMessage());
        }

        // Workspace selection
        $output->writeln('');
        $output->writeln('<fg=yellow>Available workspaces:</>');

        $workspaceChoices = [];
        foreach ($workspaces as $workspace) {
            $workspaceChoices[] = "{$workspace['name']} ({$workspace['id']})";
        }

        $selectedWorkspace = ConsoleHelper::askChoice($input, $output, 'Select workspace:', $workspaceChoices);

        // Extract workspace ID
        preg_match('/\(([^)]+)\)$/', $selectedWorkspace, $matches);
        $workspaceId = $matches[1];

        $config['clockify'] = [
            'api_key' => $apiKey,
            'workspace_id' => $workspaceId,
            'user_id' => $user['id'],
        ];

        return $config;
    }

    private function configureJira(InputInterface $input, OutputInterface $output, array $config): array
    {
        ConsoleHelper::displaySection($output, 'Jira Configuration (Optional)', '🎫');

        // Check if Jira is already configured
        if (method_exists($this->configManager, 'hasJiraConfig') && $this->configManager->hasJiraConfig()) {
            $jiraConfig = $this->configManager->getJiraConfig();
            $output->writeln('<fg=green>✅ Jira is already configured!</>');
            $output->writeln("   URL: {$jiraConfig['url']}");
            $output->writeln("   Email: {$jiraConfig['email']}");
            $output->writeln('');

            if (!ConsoleHelper::askConfirmation($input, $output, 'Update Jira configuration?', false)) {
                ConsoleHelper::displayInfo($output, 'Keeping existing Jira configuration.');

                return $config;
            }
        }

        $output->writeln('<fg=yellow>Jira integration enables automatic ticket information fetching.</>');
        $output->writeln('<fg=yellow>You can skip this and configure later.</>');
        $output->writeln('');

        if (!ConsoleHelper::askConfirmation($input, $output, 'Configure Jira integration?', true)) {
            $output->writeln('');
            ConsoleHelper::displayInfo($output, 'Skipping Jira configuration.');

            return $config;
        }

        $output->writeln('');
        $output->writeln('<fg=yellow>Get your API token from: https://id.atlassian.com/manage-profile/security/api-tokens</>');
        $output->writeln('');

        // Jira URL
        $currentUrl = $config['jira']['url'] ?? '';
        $jiraUrl = $this->getConfigValue(
            $input,
            $output,
            'Enter Jira URL (e.g., https://company.atlassian.net)',
            $currentUrl,
            'JIRA_URL'
        );

        if (empty($jiraUrl)) {
            ConsoleHelper::displayInfo($output, 'Skipping Jira configuration.');

            return $config;
        }

        // Email
        $currentEmail = $config['jira']['email'] ?? '';
        $email = $this->getConfigValue(
            $input,
            $output,
            'Enter Jira email',
            $currentEmail,
            'JIRA_EMAIL'
        );

        // API Token
        $currentToken = $config['jira']['token'] ?? '';
        $token = $this->getConfigValue(
            $input,
            $output,
            'Enter Jira API token',
            $currentToken,
            'JIRA_TOKEN',
            true
        );

        if (!empty($jiraUrl) && !empty($email) && !empty($token)) {
            // Test Jira connection
            ConsoleHelper::displayProgressBar($output, 'Testing Jira connection');

            try {
                $jiraClient = new JiraClient($jiraUrl, $email, $token);
                $jiraClient->getCurrentUser();

                ConsoleHelper::finishProgressBar($output);
                ConsoleHelper::displaySuccess($output, 'Jira connection successful!');

                $config['jira'] = [
                    'url' => $jiraUrl,
                    'email' => $email,
                    'token' => $token,
                ];
            } catch (RuntimeException $e) {
                ConsoleHelper::finishProgressBar($output);
                ConsoleHelper::displayWarning($output, 'Jira connection failed: ' . $e->getMessage());

                if (ConsoleHelper::askConfirmation($input, $output, 'Save Jira settings anyway?', false)) {
                    $config['jira'] = [
                        'url' => $jiraUrl,
                        'email' => $email,
                        'token' => $token,
                    ];
                }
            }
        }

        return $config;
    }

    private function configureGeneralSettings(InputInterface $input, OutputInterface $output, array $config): array
    {
        ConsoleHelper::displaySection($output, 'General Settings', '⚙️');

        // Timezone configuration
        $timezoneChoices = [
            'America/Santiago' => 'Chile Continental (CLT/CLST)',
            'Pacific/Easter' => 'Isla de Pascua (EAST/EASST)',
            'UTC' => 'UTC (Coordinated Universal Time)',
            'America/New_York' => 'Eastern Time (EST/EDT)',
            'America/Los_Angeles' => 'Pacific Time (PST/PDT)',
            'Europe/Madrid' => 'Central European Time (CET/CEST)',
        ];

        $currentTimezone = $config['timezone'] ?? 'America/Santiago';
        $selectedTimezone = ConsoleHelper::askChoice(
            $input,
            $output,
            "Select timezone [{$currentTimezone}]:",
            array_keys($timezoneChoices),
            $currentTimezone
        );

        // Show timezone info
        $output->writeln('');
        $output->writeln("<fg=green>Selected: {$timezoneChoices[$selectedTimezone]}</>");

        // Test with current time
        date_default_timezone_set($selectedTimezone);
        $now = new \DateTime();
        $output->writeln("Current time in {$selectedTimezone}: <fg=cyan>{$now->format('Y-m-d H:i:s T')}</>");
        $output->writeln('');

        // Default duration
        $currentDuration = $config['timer']['default_duration'] ?? '1h';
        $defaultDuration = ConsoleHelper::askQuestion(
            $input,
            $output,
            "Default duration [{$currentDuration}]: ",
            $currentDuration
        );

        // Round to minutes
        $currentRound = $config['timer']['round_to_minutes'] ?? 15;
        $roundChoices = ['5', '10', '15', '30'];
        $selectedRound = ConsoleHelper::askChoice(
            $input,
            $output,
            "Round time entries to nearest minutes [{$currentRound}]:",
            $roundChoices,
            (string) $currentRound
        );

        // Auto-detect branch
        $currentAutoDetect = $config['timer']['auto_detect_branch'] ?? true;
        $autoDetect = ConsoleHelper::askConfirmation(
            $input,
            $output,
            'Auto-detect tasks from Git branch names?',
            $currentAutoDetect
        );

        $config['timezone'] = $selectedTimezone;
        $config['timer'] = array_merge($config['timer'] ?? [], [
            'default_duration' => $defaultDuration,
            'round_to_minutes' => (int) $selectedRound,
            'auto_detect_branch' => $autoDetect,
        ]);

        return $config;
    }

    private function getConfigValue(
        InputInterface $input,
        OutputInterface $output,
        string $prompt,
        string $current = '',
        ?string $envVar = null,
        bool $hidden = false
    ): string {
        // Check environment variable first
        if ($envVar && !empty($_ENV[$envVar])) {
            $output->writeln("Using {$envVar} from environment");

            return trim($_ENV[$envVar]);
        }

        // Show current value if exists
        if (!empty($current)) {
            $prompt .= ' [current: ' . ($hidden ? str_repeat('*', 8) : $current) . ']';
        }

        $prompt .= ': ';

        $value = ConsoleHelper::askQuestion($input, $output, $prompt, $current, $hidden);

        // Only trim whitespace, don't modify the actual content
        return trim($value);
    }

    private function testConnections(OutputInterface $output, array $config): void
    {
        ConsoleHelper::displaySection($output, 'Connection Tests', '🔍');

        // Test Clockify
        if (!empty($config['clockify']['api_key']) && !empty($config['clockify']['workspace_id'])) {
            ConsoleHelper::displayProgressBar($output, 'Testing Clockify connection');

            try {
                $clockifyClient = new ClockifyClient(
                    $config['clockify']['api_key'],
                    $config['clockify']['workspace_id']
                );
                $clockifyClient->testConnection();

                ConsoleHelper::finishProgressBar($output);
                ConsoleHelper::displaySuccess($output, 'Clockify connection: OK');
            } catch (RuntimeException $e) {
                ConsoleHelper::finishProgressBar($output);
                ConsoleHelper::displayError($output, 'Clockify connection: FAILED - ' . $e->getMessage());
            }
        }

        // Test Jira
        if (!empty($config['jira']['url']) && !empty($config['jira']['email']) && !empty($config['jira']['token'])) {
            ConsoleHelper::displayProgressBar($output, 'Testing Jira connection');

            try {
                $jiraClient = new JiraClient(
                    $config['jira']['url'],
                    $config['jira']['email'],
                    $config['jira']['token']
                );
                $jiraClient->testConnection();

                ConsoleHelper::finishProgressBar($output);
                ConsoleHelper::displaySuccess($output, 'Jira connection: OK');
            } catch (RuntimeException $e) {
                ConsoleHelper::finishProgressBar($output);
                ConsoleHelper::displayError($output, 'Jira connection: FAILED - ' . $e->getMessage());
            }
        }
    }
}
