<?php

declare(strict_types=1);

namespace MiLopez\ClockifyWizard\Commands;

use MiLopez\ClockifyWizard\Client\ClockifyClient;
use MiLopez\ClockifyWizard\Config\ConfigManager;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Persists a Jira project key → Clockify project mapping so that unattended
 * `create-task` / `start` / `log` calls (PM and AI-agent flows) can resolve the
 * Clockify project without any prompt. With no arguments it lists the current
 * mappings.
 */
#[AsCommand(
    name: 'map',
    description: 'Map a Jira project key to a Clockify project (persisted for non-interactive use)'
)]
class MapCommand extends Command
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
            ->addArgument('jira-key', InputArgument::OPTIONAL, 'Jira project key (e.g., CAM)')
            ->addArgument('clockify-project', InputArgument::OPTIONAL, 'Clockify project id or name')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output result as JSON')
            ->setHelp(
                "Examples:\n"
                . "  clockify-wizard map                       # list mappings\n"
                . "  clockify-wizard map CAM \"My Project\"      # map by name\n"
                . "  clockify-wizard map CAM 64f...projectId    # map by id\n"
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $jiraKey = $input->getArgument('jira-key');
        $clockifyProject = $input->getArgument('clockify-project');
        $json = (bool) $input->getOption('json');

        if (!$this->configManager->isConfigured()) {
            $output->writeln('<error>Clockify CLI is not configured. Run: clockify-wizard configure</error>');

            return Command::FAILURE;
        }

        // No args → list mappings.
        if (!$jiraKey) {
            return $this->listMappings($output, $json);
        }

        if (!$clockifyProject) {
            $output->writeln('<error>A Clockify project (id or name) is required to create a mapping.</error>');

            return Command::FAILURE;
        }

        $clockifyConfig = $this->configManager->getClockifyConfig();
        $client = new ClockifyClient($clockifyConfig['api_key'], $clockifyConfig['workspace_id']);

        try {
            $project = $this->resolveProject($client, $clockifyProject);
        } catch (RuntimeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $jiraKey = strtoupper($jiraKey);
        $this->configManager->addProjectMapping($jiraKey, $project['id']);

        $payload = [
            'jiraKey' => $jiraKey,
            'projectId' => $project['id'],
            'projectName' => $project['name'],
        ];

        if ($json) {
            $output->writeln(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $output->writeln("<info>✅ Mapped {$jiraKey} → {$project['name']} ({$project['id']})</info>");

        return Command::SUCCESS;
    }

    private function listMappings(OutputInterface $output, bool $json): int
    {
        $mappings = $this->configManager->getProjectMappings();

        if ($json) {
            $output->writeln(json_encode($mappings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        if (empty($mappings)) {
            $output->writeln('<comment>No project mappings configured.</comment>');

            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['Jira Key', 'Clockify Project']);
        foreach ($mappings as $jira => $clockify) {
            $table->addRow([$jira, $clockify]);
        }
        $table->render();

        return Command::SUCCESS;
    }

    private function resolveProject(ClockifyClient $client, string $needle): array
    {
        foreach ($client->getProjects(true) as $project) {
            if ($project['id'] === $needle || strcasecmp($project['name'], $needle) === 0) {
                return $project;
            }
        }

        throw new RuntimeException("Clockify project '{$needle}' not found");
    }
}
