<?php

declare(strict_types=1);

namespace MiLopez\ClockifyWizard\Service;

use MiLopez\ClockifyWizard\Client\ClockifyClient;
use MiLopez\ClockifyWizard\Client\JiraClient;
use MiLopez\ClockifyWizard\Config\ConfigManager;
use RuntimeException;

/**
 * Resolves a Jira ticket key into the Clockify project + task it should be
 * tracked against, WITHOUT any user interaction.
 *
 * This consolidates the project/task resolution logic that used to be
 * duplicated across StartCommand and LogTimeCommand, and is what makes the
 * non-interactive ("agent") mode possible: every piece of context is resolved
 * from flags, the Jira issue, and saved project mappings — never a prompt.
 */
class TaskResolver
{
    private ClockifyClient $clockifyClient;

    private ConfigManager $configManager;

    private ?JiraClient $jiraClient;

    public function __construct(
        ClockifyClient $clockifyClient,
        ConfigManager $configManager,
        ?JiraClient $jiraClient = null
    ) {
        $this->clockifyClient = $clockifyClient;
        $this->configManager = $configManager;
        $this->jiraClient = $jiraClient;
    }

    /**
     * @param string[] $tagNames Tag/label names; missing ones are created on the fly.
     *
     * @return array{
     *     task_id: string,
     *     project_key: ?string,
     *     summary: ?string,
     *     description: string,
     *     jira_issue: ?array,
     *     clockify_project: array,
     *     clockify_task: array,
     *     tag_ids: string[]
     * }
     */
    /**
     * @param bool $write When false (dry-run), nothing is mutated: the project
     *                     mapping is not persisted, a missing Clockify task is
     *                     NOT created (returned with a null id), and missing
     *                     tags are NOT created.
     */
    public function resolve(
        string $ticketKey,
        ?string $projectOption = null,
        ?string $description = null,
        array $tagNames = [],
        ?string $customTaskName = null,
        bool $write = true
    ): array {
        $ticketKey = trim($ticketKey);
        if ($ticketKey === '') {
            throw new RuntimeException('A task/ticket id is required (e.g., CAM-451).');
        }

        $result = [
            'task_id' => $ticketKey,
            'project_key' => $this->projectKeyFromTicket($ticketKey),
            'summary' => null,
            'description' => $description ?: "Work on {$ticketKey}",
            'jira_issue' => null,
        ];

        // Enrich from Jira when available (non-fatal if it fails).
        if ($this->jiraClient) {
            try {
                $issue = $this->jiraClient->getIssue($ticketKey);
                $result['jira_issue'] = $issue;
                $result['project_key'] = $issue['fields']['project']['key'] ?? $result['project_key'];
                $result['summary'] = $issue['fields']['summary'] ?? null;
                if (!$description && $result['summary']) {
                    $result['description'] = "Work on {$ticketKey}: {$result['summary']}";
                }
            } catch (RuntimeException $e) {
                // Keep going with the ticket key only.
            }
        }

        $project = $this->resolveProject($result['project_key'], $projectOption, $ticketKey);
        $result['clockify_project'] = $project;

        // Persist the mapping so subsequent calls for this Jira project are automatic.
        if ($write && $projectOption && $result['project_key']) {
            $this->configManager->addProjectMapping($result['project_key'], $project['id']);
        }

        $taskName = $customTaskName ?: ($result['summary'] ? "{$ticketKey} {$result['summary']}" : $ticketKey);
        $result['clockify_task'] = $this->resolveTask($project['id'], $taskName, $write);
        $result['tag_ids'] = $this->resolveTags($tagNames, $write);

        return $result;
    }

    /**
     * Resolve a project from an explicit option or a saved Jira→Clockify mapping.
     * Throws (never prompts) when it cannot be determined.
     */
    private function resolveProject(?string $projectKey, ?string $projectOption, string $ticketKey): array
    {
        $projects = $this->clockifyClient->getProjects();

        if ($projectOption) {
            $match = $this->matchProject($projects, $projectOption);
            if (!$match) {
                throw new RuntimeException("Clockify project '{$projectOption}' not found.");
            }

            return $match;
        }

        if ($projectKey) {
            $mapped = $this->configManager->getClockifyProjectForJira($projectKey);
            if ($mapped) {
                $match = $this->matchProject($projects, $mapped);
                if ($match) {
                    return $match;
                }
            }
        }

        $hint = $projectKey
            ? "Pass --project=<id|name>, or set a mapping once: clockify-wizard map {$projectKey} \"<Clockify project>\"."
            : 'Pass --project=<id|name>.';

        throw new RuntimeException("Could not resolve a Clockify project for {$ticketKey}. {$hint}");
    }

    private function matchProject(array $projects, string $needle): ?array
    {
        foreach ($projects as $project) {
            if ($project['id'] === $needle || strcasecmp($project['name'], $needle) === 0) {
                return $project;
            }
        }

        return null;
    }

    private function resolveTask(string $projectId, string $taskName, bool $write): array
    {
        $existing = $this->clockifyClient->findTask($projectId, $taskName);
        if ($existing) {
            return $existing;
        }

        if (!$write) {
            // Dry-run: report what would be created without creating it.
            return ['id' => null, 'name' => $taskName, 'status' => null, '_wouldCreate' => true];
        }

        return $this->clockifyClient->createTask($projectId, $taskName);
    }

    /**
     * @param string[] $tagNames
     *
     * @return string[]
     */
    private function resolveTags(array $tagNames, bool $write): array
    {
        if (empty($tagNames)) {
            return [];
        }

        if ($write) {
            return $this->clockifyClient->resolveTagIds($tagNames);
        }

        // Dry-run: only map names that already exist; do not create tags.
        $byName = [];
        foreach ($this->clockifyClient->getTags(true) as $tag) {
            $byName[mb_strtolower($tag['name'])] = $tag['id'];
        }

        $ids = [];
        foreach ($tagNames as $name) {
            $key = mb_strtolower(trim($name));
            if ($key !== '' && isset($byName[$key])) {
                $ids[] = $byName[$key];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Best-effort Jira project key from a ticket id (CAM-451 → CAM), used as a
     * mapping fallback when Jira is not configured.
     */
    private function projectKeyFromTicket(string $ticketKey): ?string
    {
        if (preg_match('/^([A-Za-z]+)[-_]\d+/', $ticketKey, $m)) {
            return strtoupper($m[1]);
        }

        return null;
    }
}
