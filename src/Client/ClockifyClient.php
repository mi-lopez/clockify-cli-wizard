<?php

declare(strict_types=1);

namespace MiLopez\ClockifyWizard\Client;

use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

class ClockifyClient
{
    private const API_BASE_URL = 'https://api.clockify.me/api/v1';
    private const REPORTS_BASE_URL = 'https://reports.api.clockify.me/v1';
    private const DEFAULT_PAGE_SIZE = 50;
    private const MAX_PAGE_SIZE = 200; // Clockify's maximum

    private Client $httpClient;

    private string $apiKey;

    private string $workspaceId;

    public function __construct(string $apiKey, string $workspaceId)
    {
        $this->apiKey = trim($apiKey);
        $this->workspaceId = $workspaceId;

        // Clean the API key from any invisible characters
        $cleanApiKey = preg_replace('/[^\x20-\x7E]/', '', trim($apiKey));

        $this->httpClient = new Client([
            'timeout' => 30,
            'headers' => [
                'X-Api-Key' => $cleanApiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'User-Agent' => 'ClockifyWizard/1.0',
            ],
        ]);
    }

    public function getCurrentUser(): array
    {
        try {
            $response = $this->httpClient->get(self::API_BASE_URL . '/user');

            return json_decode($response->getBody()->getContents(), true);
        } catch (ClientException $e) {
            $responseBody = $e->getResponse() ? $e->getResponse()->getBody()->getContents() : 'No response body';
            throw new RuntimeException('Failed to get current user. Response: ' . $responseBody . ' Status: ' . $e->getCode());
        } catch (GuzzleException $e) {
            throw new RuntimeException('Failed to get current user: ' . $e->getMessage());
        }
    }

    public function getWorkspaces(): array
    {
        try {
            $response = $this->httpClient->get(self::API_BASE_URL . '/workspaces');

            return json_decode($response->getBody()->getContents(), true);
        } catch (ClientException $e) {
            $responseBody = $e->getResponse() ? $e->getResponse()->getBody()->getContents() : 'No response body';
            throw new RuntimeException('Failed to get workspaces. Response: ' . $responseBody . ' Status: ' . $e->getCode());
        } catch (GuzzleException $e) {
            throw new RuntimeException('Failed to get workspaces: ' . $e->getMessage());
        }
    }

    /**
     * Get all projects with automatic pagination.
     */
    public function getProjects(bool $includeArchived = false): array
    {
        $allProjects = [];
        $page = 1;
        $pageSize = self::MAX_PAGE_SIZE;

        do {
            $projects = $this->getProjectsPage($page, $pageSize, $includeArchived);
            $allProjects = array_merge($allProjects, $projects);

            // If we got less than the page size, we've reached the end
            $hasMore = count($projects) === $pageSize;
            $page++;
        } while ($hasMore);

        return $allProjects;
    }

    /**
     * Get a specific page of projects.
     */
    public function getProjectsPage(int $page = 1, int $pageSize = null, bool $includeArchived = false): array
    {
        $pageSize = $pageSize ?? self::DEFAULT_PAGE_SIZE;
        $pageSize = min($pageSize, self::MAX_PAGE_SIZE); // Respect Clockify's limits

        try {
            $queryParams = [
                'page' => $page,
                'page-size' => $pageSize,
            ];

            if ($includeArchived) {
                $queryParams['archived'] = 'true';
            }

            $query = http_build_query($queryParams);
            $url = self::API_BASE_URL . "/workspaces/{$this->workspaceId}/projects?{$query}";

            $response = $this->httpClient->get($url);

            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            throw new RuntimeException('Failed to get projects: ' . $e->getMessage());
        }
    }

    /**
     * Search projects by name.
     */
    public function searchProjects(string $searchTerm, bool $includeArchived = false): array
    {
        try {
            $queryParams = [
                'name' => $searchTerm,
                'page-size' => self::MAX_PAGE_SIZE,
            ];

            if ($includeArchived) {
                $queryParams['archived'] = 'true';
            }

            $query = http_build_query($queryParams);
            $url = self::API_BASE_URL . "/workspaces/{$this->workspaceId}/projects?{$query}";

            $response = $this->httpClient->get($url);

            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            throw new RuntimeException('Failed to search projects: ' . $e->getMessage());
        }
    }

    public function getProject(string $projectId): array
    {
        try {
            $response = $this->httpClient->get(
                self::API_BASE_URL . "/workspaces/{$this->workspaceId}/projects/{$projectId}"
            );

            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            throw new RuntimeException('Failed to get project: ' . $e->getMessage());
        }
    }

    /**
     * Get all tasks for a project with automatic pagination.
     */
    public function getTasks(string $projectId): array
    {
        $allTasks = [];
        $page = 1;
        $pageSize = self::MAX_PAGE_SIZE;

        do {
            $tasks = $this->getTasksPage($projectId, $page, $pageSize);
            $allTasks = array_merge($allTasks, $tasks);

            // If we got less than the page size, we've reached the end
            $hasMore = count($tasks) === $pageSize;
            $page++;
        } while ($hasMore);

        return $allTasks;
    }

    /**
     * Get a specific page of tasks.
     */
    public function getTasksPage(string $projectId, int $page = 1, int $pageSize = null): array
    {
        $pageSize = $pageSize ?? self::DEFAULT_PAGE_SIZE;
        $pageSize = min($pageSize, self::MAX_PAGE_SIZE);

        try {
            $queryParams = [
                'page' => $page,
                'page-size' => $pageSize,
            ];

            $query = http_build_query($queryParams);
            $url = self::API_BASE_URL . "/workspaces/{$this->workspaceId}/projects/{$projectId}/tasks?{$query}";

            $response = $this->httpClient->get($url);

            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            throw new RuntimeException('Failed to get tasks: ' . $e->getMessage());
        }
    }

    public function findTask(string $projectId, string $taskName): ?array
    {
        $tasks = $this->getTasks($projectId);

        foreach ($tasks as $task) {
            if ($task['name'] === $taskName) {
                return $task;
            }
        }

        return null;
    }

    public function createTask(string $projectId, string $name, ?string $status = 'ACTIVE'): array
    {
        try {
            $response = $this->httpClient->post(
                self::API_BASE_URL . "/workspaces/{$this->workspaceId}/projects/{$projectId}/tasks",
                [
                    'json' => [
                        'name' => $name,
                        'status' => $status,
                    ],
                ]
            );

            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            throw new RuntimeException('Failed to create task: ' . $e->getMessage());
        }
    }

    public function startTimer(string $projectId, ?string $taskId = null, ?string $description = null): array
    {
        $data = [
            'start' => Carbon::now()->utc()->toISOString(), // Force UTC
            'projectId' => $projectId,
        ];

        if ($taskId) {
            $data['taskId'] = $taskId;
        }

        if ($description) {
            $data['description'] = $description;
        }

        try {
            $timeEntry = $this->createTimeEntry($data);

            // Verify the response has required fields
            if (!isset($timeEntry['id'])) {
                throw new RuntimeException('Invalid response from startTimer: missing timer ID. Response: ' . json_encode($timeEntry));
            }

            if (!isset($timeEntry['timeInterval']['start'])) {
                throw new RuntimeException('Invalid response from startTimer: missing start time. Response: ' . json_encode($timeEntry));
            }

            return $timeEntry;
        } catch (GuzzleException $e) {
            throw new RuntimeException('Failed to start timer: ' . $e->getMessage());
        }
    }

    public function createTimeEntry(array $timeEntryData): array
    {
        try {
            $response = $this->httpClient->post(
                self::API_BASE_URL . "/workspaces/{$this->workspaceId}/time-entries",
                ['json' => $timeEntryData]
            );

            $responseBody = $response->getBody()->getContents();
            $timeEntry = json_decode($responseBody, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException('Invalid JSON response from createTimeEntry: ' . json_last_error_msg() . '. Response: ' . $responseBody);
            }

            return $timeEntry;
        } catch (ClientException $e) {
            $responseBody = '';
            if ($e->getResponse()) {
                $responseBody = $e->getResponse()->getBody()->getContents();
            }

            throw new RuntimeException(
                'Failed to create time entry (HTTP ' . $e->getCode() . '): ' . $responseBody
            );
        } catch (GuzzleException $e) {
            throw new RuntimeException('Failed to create time entry: ' . $e->getMessage());
        }
    }

    public function stopTimer(string $userId, ?string $endTime = null): array
    {
        try {
            $data = [
                'end' => $endTime ?? Carbon::now()->toISOString(),
            ];

            $response = $this->httpClient->patch(
                self::API_BASE_URL . "/workspaces/{$this->workspaceId}/user/{$userId}/time-entries",
                ['json' => $data]
            );

            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            throw new RuntimeException('Failed to stop timer: ' . $e->getMessage());
        }
    }

    public function getCurrentTimeEntry(string $userId): ?array
    {
        try {
            $response = $this->httpClient->get(
                self::API_BASE_URL . "/workspaces/{$this->workspaceId}/user/{$userId}/time-entries/current"
            );

            $content = $response->getBody()->getContents();

            // Check if response is empty
            if (empty($content) || trim($content) === '') {
                return null;
            }

            $timeEntry = json_decode($content, true);

            // Check for JSON decode errors
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException('Invalid JSON response from getCurrentTimeEntry: ' . json_last_error_msg());
            }

            // Some APIs return empty object {} instead of null
            if (empty($timeEntry) || !isset($timeEntry['id'])) {
                return null;
            }

            return $timeEntry;
        } catch (ClientException $e) {
            $statusCode = $e->getResponse()->getStatusCode();

            if ($statusCode === 404) {
                return null; // No active timer
            }

            // For other client errors, try to get response body for debugging
            $responseBody = '';
            if ($e->getResponse()) {
                $responseBody = $e->getResponse()->getBody()->getContents();
            }

            throw new RuntimeException(
                "Failed to get current time entry (HTTP {$statusCode}): {$responseBody}"
            );
        } catch (GuzzleException $e) {
            throw new RuntimeException('Failed to get current time entry: ' . $e->getMessage());
        }
    }

    /**
     * Alternative method using time entries endpoint to find running timer.
     */
    /**
     * Alternative method using time entries endpoint to find running timer.
     */
    public function findRunningTimeEntry(string $userId): ?array
    {
        try {
            // Get recent time entries and look for one without end time
            $timeEntries = $this->getTimeEntriesPage($userId, 1, 20);

            foreach ($timeEntries as $entry) {
                // If there's no end time, it's a running timer
                if (!isset($entry['timeInterval']['end']) || $entry['timeInterval']['end'] === null) {
                    return $entry;
                }
            }

            return null;
        } catch (GuzzleException $e) {
            throw new RuntimeException('Failed to find running time entry: ' . $e->getMessage());
        }
    }

    /**
     * Get current time entry with fallback method.
     */
    public function getCurrentTimeEntryWithFallback(string $userId): ?array
    {
        // Try primary method first
        try {
            $current = $this->getCurrentTimeEntry($userId);
            if ($current) {
                return $current;
            }
        } catch (RuntimeException $e) {
            // If primary method fails, log error but continue to fallback
            error_log('Primary getCurrentTimeEntry failed: ' . $e->getMessage());
        }

        // Try fallback method
        try {
            return $this->findRunningTimeEntry($userId);
        } catch (RuntimeException $e) {
            error_log('Fallback findRunningTimeEntry failed: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Get time entries with automatic pagination and optional hydration.
     */
    public function getTimeEntries(string $userId, ?Carbon $start = null, ?Carbon $end = null, bool $hydrate = false): array
    {
        $allEntries = [];
        $page = 1;
        $pageSize = self::MAX_PAGE_SIZE;

        do {
            $entries = $this->getTimeEntriesPage($userId, $page, $pageSize, $start, $end, $hydrate);
            $allEntries = array_merge($allEntries, $entries);

            // If we got less than the page size, we've reached the end
            $hasMore = count($entries) === $pageSize;
            $page++;
        } while ($hasMore);

        return $allEntries;
    }

    /**
     * Get a specific page of time entries with optional hydration.
     */
    public function getTimeEntriesPage(string $userId, int $page = 1, int $pageSize = null, ?Carbon $start = null, ?Carbon $end = null, bool $hydrate = false): array
    {
        $pageSize = $pageSize ?? self::DEFAULT_PAGE_SIZE;
        $pageSize = min($pageSize, self::MAX_PAGE_SIZE);

        try {
            $params = [
                'page' => $page,
                'page-size' => $pageSize,
            ];

            if ($start) {
                $params['start'] = $start->toISOString();
            }

            if ($end) {
                $params['end'] = $end->toISOString();
            }

            // Try to get expanded data from Clockify API
            if ($hydrate) {
                $params['hydrated'] = 'true';
                // or try: $params['in-progress'] = 'true';
            }

            $query = !empty($params) ? '?' . http_build_query($params) : '';

            $response = $this->httpClient->get(
                self::API_BASE_URL . "/workspaces/{$this->workspaceId}/user/{$userId}/time-entries{$query}"
            );

            $entries = json_decode($response->getBody()->getContents(), true);

            // If hydration didn't work, we'll expand manually in the command
            return $entries;
        } catch (GuzzleException $e) {
            throw new RuntimeException('Failed to get time entries: ' . $e->getMessage());
        }
    }

    /**
     * Get time entries with full project and task information using Reports API.
     */
    public function getDetailedTimeEntries(string $userId, ?Carbon $start = null, ?Carbon $end = null): array
    {
        try {
            $requestData = [
                'dateRangeStart' => $start ? $start->toISOString() : Carbon::now()->startOfWeek()->toISOString(),
                'dateRangeEnd' => $end ? $end->toISOString() : Carbon::now()->endOfWeek()->toISOString(),
                'detailedFilter' => [
                    'page' => 1,
                    'pageSize' => 1000,
                ],
                'users' => [
                    'ids' => [$userId],
                    'contains' => 'CONTAINS',
                    'status' => 'ALL',
                ],
                'exportType' => 'JSON',
            ];

            $response = $this->httpClient->post(
                self::REPORTS_BASE_URL . "/workspaces/{$this->workspaceId}/reports/detailed",
                ['json' => $requestData]
            );

            $reportData = json_decode($response->getBody()->getContents(), true);

            // Convert report format to time entries format
            return $this->convertReportToTimeEntries($reportData);
        } catch (GuzzleException $e) {
            throw new RuntimeException('Failed to get detailed time entries: ' . $e->getMessage());
        }
    }

    /**
     * Convert Reports API response to time entries format.
     */
    private function convertReportToTimeEntries(array $reportData): array
    {
        $timeEntries = [];

        if (!isset($reportData['timeentries']) || !is_array($reportData['timeentries'])) {
            return [];
        }

        foreach ($reportData['timeentries'] as $entry) {
            $timeEntry = [
                'id' => $entry['_id'] ?? null,
                'description' => $entry['description'] ?? '',
                'timeInterval' => [
                    'start' => $entry['timeInterval']['start'] ?? null,
                    'end' => $entry['timeInterval']['end'] ?? null,
                    'duration' => $entry['timeInterval']['duration'] ?? null,
                ],
                'project' => [
                    'id' => $entry['projectId'] ?? null,
                    'name' => $entry['projectName'] ?? 'Unknown Project',
                    'color' => $entry['projectColor'] ?? '#666666',
                ],
                'task' => [
                    'id' => $entry['taskId'] ?? null,
                    'name' => $entry['taskName'] ?? null,
                ],
                'user' => [
                    'id' => $entry['userId'] ?? null,
                    'name' => $entry['userName'] ?? null,
                ],
                'tags' => $entry['tags'] ?? [],
                'billable' => $entry['billable'] ?? false,
            ];

            $timeEntries[] = $timeEntry;
        }

        return $timeEntries;
    }

    public function deleteTimeEntry(string $timeEntryId): bool
    {
        try {
            $this->httpClient->delete(
                self::API_BASE_URL . "/workspaces/{$this->workspaceId}/time-entries/{$timeEntryId}"
            );

            return true;
        } catch (GuzzleException $e) {
            throw new RuntimeException('Failed to delete time entry: ' . $e->getMessage());
        }
    }

    public function updateTimeEntry(string $timeEntryId, array $data): array
    {
        try {
            $response = $this->httpClient->put(
                self::API_BASE_URL . "/workspaces/{$this->workspaceId}/time-entries/{$timeEntryId}",
                ['json' => $data]
            );

            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            throw new RuntimeException('Failed to update time entry: ' . $e->getMessage());
        }
    }

    public function testConnection(): bool
    {
        try {
            $this->getCurrentUser();

            return true;
        } catch (RuntimeException $e) {
            return false;
        }
    }

    /**
     * Get total count of projects (useful for progress indicators).
     */
    public function getProjectsCount(bool $includeArchived = false): int
    {
        try {
            $queryParams = [
                'page' => 1,
                'page-size' => 1, // Minimal request to get headers
            ];

            if ($includeArchived) {
                $queryParams['archived'] = 'true';
            }

            $query = http_build_query($queryParams);
            $url = self::API_BASE_URL . "/workspaces/{$this->workspaceId}/projects?{$query}";

            $response = $this->httpClient->get($url);

            // Some APIs return total count in headers
            $totalHeader = $response->getHeader('X-Total-Count');
            if (!empty($totalHeader)) {
                return (int) $totalHeader[0];
            }

            // Fallback: get all and count (not ideal but works)
            return count($this->getProjects($includeArchived));
        } catch (GuzzleException $e) {
            // If we can't get count, return 0 and let normal pagination handle it
            return 0;
        }
    }

    /**
     * Debug method to see what parameters Clockify actually accepts.
     */
    public function debugProjectsApi(): array
    {
        try {
            // Try with maximum parameters to see what works
            $queryParams = [
                'page' => 1,
                'page-size' => 5,
                'archived' => 'false',
                'sort-order' => 'ASCENDING',
                'sort-column' => 'NAME',
            ];

            $query = http_build_query($queryParams);
            $url = self::API_BASE_URL . "/workspaces/{$this->workspaceId}/projects?{$query}";

            $response = $this->httpClient->get($url);
            $projects = json_decode($response->getBody()->getContents(), true);

            return [
                'url' => $url,
                'count' => count($projects),
                'headers' => $response->getHeaders(),
                'first_project' => $projects[0] ?? null,
            ];
        } catch (GuzzleException $e) {
            throw new RuntimeException('Failed to debug projects API: ' . $e->getMessage());
        }
    }
}
