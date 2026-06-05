<?php

declare(strict_types=1);

namespace MiLopez\ClockifyWizard\Commands;

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

/**
 * Clockify has no native "pause": a timer is either running or stopped.
 * This command stops the running timer and remembers its context (project,
 * task, description, tags) so `resume` can restart it later, giving agents and
 * humans a pause/resume experience without losing what they were tracking.
 */
#[AsCommand(
    name: 'pause',
    description: 'Pause the running timer (stops it and remembers it for `resume`)'
)]
class PauseCommand extends Command
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
            ->addOption('time', 't', InputOption::VALUE_REQUIRED, 'Stop time (e.g., 17:30, 5:30pm, now)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output result as JSON')
            ->setHelp('
Pause the running timer (Clockify has no native pause: this stops it and saves
its context so `resume` can restart it):

  clockify-wizard pause
  clockify-wizard pause --json
            ');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = (bool) $input->getOption('json');

        try {
            if (!$this->configManager->isConfigured()) {
                throw new RuntimeException('Clockify CLI is not configured. Run: clockify-wizard configure');
            }

            $clockifyConfig = $this->configManager->getClockifyConfig();
            $client = new ClockifyClient($clockifyConfig['api_key'], $clockifyConfig['workspace_id']);
            $userId = $clockifyConfig['user_id'] ?? '';

            $current = $userId ? $client->getCurrentTimeEntryWithFallback($userId) : null;
            if (!$current) {
                return $this->emit($output, $json, ['paused' => false, 'message' => 'No running timer to pause.']);
            }

            // Remember context before stopping, so `resume` can restart it.
            $snapshot = [
                'project_id' => $current['projectId'] ?? null,
                'task_id' => $current['taskId'] ?? null,
                'description' => $current['description'] ?? '',
                'tag_ids' => $current['tagIds'] ?? [],
                'paused_at' => TimeHelper::now()->toISOString(),
            ];

            $stopTime = $this->getStopTime($input);
            $client->stopTimer($userId, TimeHelper::toUtcTime($stopTime)->toISOString());

            $this->configManager->clearActiveTimer();
            $this->configManager->savePausedTimer($snapshot);

            return $this->emit($output, $json, [
                'paused' => true,
                'id' => $current['id'],
                'projectId' => $snapshot['project_id'],
                'taskId' => $snapshot['task_id'],
                'description' => $snapshot['description'],
                'tagIds' => $snapshot['tag_ids'],
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

    private function getStopTime(InputInterface $input): \Carbon\Carbon
    {
        $timeOption = $input->getOption('time');
        if (!$timeOption || $timeOption === 'now') {
            return TimeHelper::now();
        }

        try {
            return TimeHelper::parseTime($timeOption);
        } catch (\InvalidArgumentException $e) {
            throw new RuntimeException("Invalid stop time: {$timeOption}. Use formats like '17:30', '5:30pm', or 'now'.");
        }
    }

    private function emit(OutputInterface $output, bool $json, array $payload): int
    {
        if ($json) {
            $output->writeln(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $message = ($payload['paused'] ?? false)
                ? '⏸️  Timer paused. Use "clockify-wizard resume" to continue.'
                : ($payload['message'] ?? '');
            $output->writeln($message);
        }

        return Command::SUCCESS;
    }
}
