<?php

declare(strict_types=1);

namespace MiLopez\ClockifyWizard\Commands;

use MiLopez\ClockifyWizard\Client\ClockifyClient;
use MiLopez\ClockifyWizard\Client\JiraClient;
use MiLopez\ClockifyWizard\Config\ConfigManager;
use MiLopez\ClockifyWizard\Helper\ConsoleHelper;
use MiLopez\ClockifyWizard\Service\TaskResolver;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Restart a timer that was paused with `pause` (or, with --task, start a fresh
 * timer for a given ticket). Non-interactive and JSON-friendly for agents.
 */
#[AsCommand(
    name: 'resume',
    description: 'Resume a paused timer (or start one for --task)'
)]
class ResumeCommand extends Command
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
            ->addOption('task', null, InputOption::VALUE_REQUIRED, 'Start a fresh timer for this ticket instead of the paused one')
            ->addOption('project', 'p', InputOption::VALUE_REQUIRED, 'Clockify project id or name (with --task)')
            ->addOption('description', 'd', InputOption::VALUE_REQUIRED, 'Override description (with --task)')
            ->addOption('tags', null, InputOption::VALUE_REQUIRED, 'Comma-separated tags/labels (with --task)')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Stop any running timer before resuming')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output result as JSON')
            ->setHelp('
Resume a paused timer:

  clockify-wizard resume
  clockify-wizard resume --json
  clockify-wizard resume --task CAM-451 --json   # start fresh for a ticket
            ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = (bool) $input->getOption('json');

        try {
            $this->initializeClients();

            $clockifyConfig = $this->configManager->getClockifyConfig();
            $userId = $clockifyConfig['user_id'] ?? '';

            $running = $userId ? $this->clockifyClient->getCurrentTimeEntryWithFallback($userId) : null;
            if ($running && !$input->getOption('force')) {
                throw new RuntimeException(
                    'A timer is already running (id ' . $running['id'] . '). Use --force to stop it and resume.'
                );
            }

            $context = $this->resolveContext($input);

            if ($running && $userId && $input->getOption('force')) {
                $this->clockifyClient->stopTimer($userId);
                $this->configManager->clearActiveTimer();
            }

            $timeEntry = $this->clockifyClient->startTimer(
                $context['project_id'],
                $context['task_id'],
                $context['description'],
                $context['tag_ids']
            );

            $this->configManager->saveActiveTimer([
                'id' => $timeEntry['id'],
                'project' => $context['project_name'] ?? '',
                'task' => $context['task_name'] ?? '',
                'start' => $timeEntry['timeInterval']['start'],
                'description' => $context['description'],
                'project_id' => $context['project_id'],
                'task_id' => $context['task_id'],
            ]);
            $this->configManager->clearPausedTimer();

            return $this->emit($output, $json, [
                'resumed' => true,
                'id' => $timeEntry['id'],
                'projectId' => $context['project_id'],
                'taskId' => $context['task_id'],
                'description' => $context['description'],
                'start' => $timeEntry['timeInterval']['start'],
                'tagIds' => $context['tag_ids'],
            ]);
        } catch (RuntimeException $e) {
            if ($json) {
                $output->writeln(json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } else {
                ConsoleHelper::displayError($output, $e->getMessage());
            }

            return Command::FAILURE;
        }
    }

    /**
     * @return array{project_id: string, task_id: ?string, description: string, tag_ids: string[], project_name?: string, task_name?: string}
     */
    private function resolveContext(InputInterface $input): array
    {
        $task = $input->getOption('task');

        if ($task) {
            $resolver = new TaskResolver($this->clockifyClient, $this->configManager, $this->jiraClient);
            $data = $resolver->resolve(
                (string) $task,
                $input->getOption('project'),
                $input->getOption('description'),
                $this->parseTags($input->getOption('tags'))
            );

            return [
                'project_id' => $data['clockify_project']['id'],
                'task_id' => $data['clockify_task']['id'],
                'description' => $data['description'],
                'tag_ids' => $data['tag_ids'],
                'project_name' => $data['clockify_project']['name'],
                'task_name' => $data['clockify_task']['name'],
            ];
        }

        $paused = $this->configManager->getPausedTimer();
        if (!$paused || empty($paused['project_id'])) {
            throw new RuntimeException('No paused timer found. Pass --task=KEY to start a new one.');
        }

        return [
            'project_id' => $paused['project_id'],
            'task_id' => $paused['task_id'] ?? null,
            'description' => $paused['description'] ?? '',
            'tag_ids' => $paused['tag_ids'] ?? [],
        ];
    }

    private function initializeClients(): void
    {
        if (!$this->configManager->isConfigured()) {
            throw new RuntimeException('Clockify CLI is not configured. Run: clockify-wizard configure');
        }

        $clockifyConfig = $this->configManager->getClockifyConfig();
        $this->clockifyClient = new ClockifyClient($clockifyConfig['api_key'], $clockifyConfig['workspace_id']);

        $jiraConfig = $this->configManager->getJiraConfig();
        if (!empty($jiraConfig['url']) && !empty($jiraConfig['email']) && !empty($jiraConfig['token'])) {
            $this->jiraClient = new JiraClient($jiraConfig['url'], $jiraConfig['email'], $jiraConfig['token']);
        }
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

    private function emit(OutputInterface $output, bool $json, array $payload): int
    {
        if ($json) {
            $output->writeln(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $output->writeln('▶️  Timer resumed (id ' . ($payload['id'] ?? '') . ').');
        }

        return Command::SUCCESS;
    }
}
