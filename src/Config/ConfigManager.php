<?php

declare(strict_types=1);

namespace MiLopez\ClockifyWizard\Config;

use MiLopez\ClockifyWizard\Helper\TimeHelper;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

class ConfigManager
{
    private const CONFIG_FILE = '.clockify-cli-config.json';
    private const CONFIG_VERSION = '1.0';

    private string $configPath;

    private Filesystem $filesystem;

    private ?array $config = null;

    public function __construct(?string $configPath = null)
    {
        $this->configPath = $configPath ?? $this->getDefaultConfigPath();
        $this->filesystem = new Filesystem();
    }

    public function getConfig(): array
    {
        if ($this->config === null) {
            $this->loadConfig();
        }

        return $this->config;
    }

    public function setConfig(array $config): void
    {
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->saveConfig();
    }

    public function isConfigured(): bool
    {
        if (!$this->filesystem->exists($this->configPath)) {
            return false;
        }

        $config = $this->getConfig();

        return !empty($config['clockify']['api_key']) && !empty($config['clockify']['workspace_id']);
    }

    public function getClockifyConfig(): array
    {
        return $this->getConfig()['clockify'] ?? [];
    }

    public function getJiraConfig(): array
    {
        return $this->getConfig()['jira'] ?? [];
    }

    public function getProjectMappings(): array
    {
        return $this->getConfig()['project_mappings'] ?? [];
    }

    public function getTimerConfig(): array
    {
        return $this->getConfig()['timer'] ?? [];
    }

    public function hasJiraConfig(): bool
    {
        $jiraConfig = $this->getJiraConfig();

        return !empty($jiraConfig['url']) && !empty($jiraConfig['email']) && !empty($jiraConfig['token']);
    }

    public function wasMigrated(): bool
    {
        $config = $this->getConfig();

        return isset($config['migrated_from_jira_cli']) && $config['migrated_from_jira_cli'] === true;
    }

    public function addProjectMapping(string $jiraProject, string $clockifyProject): void
    {
        $config = $this->getConfig();
        $config['project_mappings'][$jiraProject] = $clockifyProject;
        $this->setConfig($config);
    }

    public function getClockifyProjectForJira(string $jiraProject): ?string
    {
        $mappings = $this->getProjectMappings();

        return $mappings[$jiraProject] ?? null;
    }

    public function saveActiveTimer(array $timerData): void
    {
        $config = $this->getConfig();
        $config['active_timer'] = $timerData;
        $this->setConfig($config);
    }

    public function getActiveTimer(): ?array
    {
        $config = $this->getConfig();

        return $config['active_timer'] ?? null;
    }

    public function clearActiveTimer(): void
    {
        $config = $this->getConfig();
        unset($config['active_timer']);
        $this->setConfig($config);
    }

    private function loadConfig(): void
    {
        if (!$this->filesystem->exists($this->configPath)) {
            // Try to migrate from old Jira CLI config
            $this->config = $this->migrateFromOldJiraConfig();

            return;
        }

        $content = file_get_contents($this->configPath);
        if ($content === false) {
            throw new RuntimeException("Cannot read config file: {$this->configPath}");
        }

        $config = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Invalid JSON in config file: ' . json_last_error_msg());
        }

        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    private function migrateFromOldJiraConfig(): array
    {
        $config = $this->getDefaultConfig();

        // Try to find old Jira CLI config
        $homeDir = $_SERVER['HOME'] ?? $_SERVER['USERPROFILE'] ?? getcwd();
        $oldJiraConfigPath = rtrim($homeDir, '/\\') . DIRECTORY_SEPARATOR . '.jira-cli-config.json';

        if ($this->filesystem->exists($oldJiraConfigPath)) {
            $oldContent = file_get_contents($oldJiraConfigPath);
            if ($oldContent !== false) {
                $oldConfig = json_decode($oldContent, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($oldConfig)) {
                    // Migrate Jira config
                    if (!empty($oldConfig['jira_url'])) {
                        $config['jira']['url'] = $oldConfig['jira_url'];
                    }
                    if (!empty($oldConfig['jira_email'])) {
                        $config['jira']['email'] = $oldConfig['jira_email'];
                    }
                    if (!empty($oldConfig['jira_token'])) {
                        $config['jira']['token'] = $oldConfig['jira_token'];
                    }

                    // Mark as migrated
                    $config['migrated_from_jira_cli'] = true;

                    // Save the migrated config
                    $this->config = $config;
                    $this->saveConfig();

                    return $config;
                }
            }
        }

        return $config;
    }

    private function saveConfig(): void
    {
        $config = $this->config;
        $config['version'] = self::CONFIG_VERSION;
        $config['updated_at'] = date('Y-m-d H:i:s');

        $content = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($content === false) {
            throw new RuntimeException('Cannot encode config to JSON');
        }

        $this->filesystem->dumpFile($this->configPath, $content);
        $this->filesystem->chmod($this->configPath, 0600);
    }

    private function getDefaultConfig(): array
    {
        return [
            'version' => self::CONFIG_VERSION,
            'timezone' => 'America/Santiago', // Add this line
            'clockify' => [
                'api_key' => '',
                'workspace_id' => '',
                'user_id' => '',
            ],
            'jira' => [
                'url' => '',
                'email' => '',
                'token' => '',
            ],
            'project_mappings' => [],
            'timer' => [
                'default_duration' => '1h',
                'round_to_minutes' => 15,
                'auto_detect_branch' => true,
                'default_description' => 'Development work',
            ],
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    private function getDefaultConfigPath(): string
    {
        $homeDir = $_SERVER['HOME'] ?? $_SERVER['USERPROFILE'] ?? getcwd();

        return rtrim($homeDir, '/\\') . DIRECTORY_SEPARATOR . self::CONFIG_FILE;
    }

    /**
     * Get configured timezone or default.
     */
    public function getTimezone(): string
    {
        $config = $this->getConfig();

        return $config['timezone'] ?? 'America/Santiago';
    }

    /**
     * Set timezone in configuration.
     */
    public function setTimezone(string $timezone): void
    {
        $config = $this->getConfig();
        $config['timezone'] = $timezone;
        $this->setConfig($config);
    }

    /**
     * Initialize timezone from config.
     */
    public function initializeTimezone(): void
    {
        TimeHelper::initializeTimezone($this->getTimezone());
    }
}
