<?php

declare(strict_types=1);

namespace MiLopez\ClockifyWizard\Client;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

class JiraClient
{
    private Client $httpClient;

    private string $baseUrl;

    public function __construct(string $baseUrl, string $email, string $token)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->httpClient = new Client([
            'timeout' => 30,
            'auth' => [$email, $token],
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    public function getCurrentUser(): array
    {
        try {
            $response = $this->httpClient->get($this->baseUrl . '/rest/api/3/myself');

            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            throw new RuntimeException('Failed to get current user: ' . $e->getMessage());
        }
    }

    public function getIssue(string $issueKey): array
    {
        try {
            $response = $this->httpClient->get(
                $this->baseUrl . "/rest/api/3/issue/{$issueKey}"
            );

            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            throw new RuntimeException("Failed to get issue {$issueKey}: " . $e->getMessage());
        }
    }

    public function getProjects(): array
    {
        try {
            $response = $this->httpClient->get($this->baseUrl . '/rest/api/3/project');

            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            throw new RuntimeException('Failed to get projects: ' . $e->getMessage());
        }
    }

    public function getProject(string $projectKey): array
    {
        try {
            $response = $this->httpClient->get(
                $this->baseUrl . "/rest/api/3/project/{$projectKey}"
            );

            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            throw new RuntimeException("Failed to get project {$projectKey}: " . $e->getMessage());
        }
    }

    public function searchIssues(string $jql, int $maxResults = 50): array
    {
        try {
            $response = $this->httpClient->get(
                $this->baseUrl . '/rest/api/3/search',
                [
                    'query' => [
                        'jql' => $jql,
                        'maxResults' => $maxResults,
                        'fields' => 'summary,status,assignee,project,issuetype,priority',
                    ],
                ]
            );

            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            throw new RuntimeException('Failed to search issues: ' . $e->getMessage());
        }
    }

    public function getRecentIssues(int $maxResults = 20): array
    {
        $jql = 'assignee = currentUser() AND updated >= -7d ORDER BY updated DESC';

        return $this->searchIssues($jql, $maxResults);
    }

    public function getIssuesByProject(string $projectKey, int $maxResults = 50): array
    {
        $jql = "project = {$projectKey} AND assignee = currentUser() ORDER BY updated DESC";

        return $this->searchIssues($jql, $maxResults);
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
}
