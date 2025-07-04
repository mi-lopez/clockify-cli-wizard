<?php

declare(strict_types=1);

namespace MiLopez\ClockifyWizard\Helper;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class GitHelper
{
    public static function getCurrentBranch(): ?string
    {
        try {
            $process = new Process(['git', 'rev-parse', '--abbrev-ref', 'HEAD']);
            $process->mustRun();

            $branch = trim($process->getOutput());

            return $branch !== 'HEAD' ? $branch : null;
        } catch (ProcessFailedException $e) {
            return null;
        }
    }

    public static function extractTicketIdFromBranch(?string $branch = null): ?string
    {
        $branch = $branch ?? self::getCurrentBranch();

        if (!$branch) {
            return null;
        }

        // Pattern to match ticket IDs like CAM-451, PROJ-123, etc.
        // Supports: feature/CAM-451-description, bugfix/PROJ-123, CAM-451, etc.
        preg_match('/([A-Z]+[-_]\d+)/', $branch, $matches);

        return $matches[1] ?? null;
    }

    public static function getRecentCommits(int $count = 10): array
    {
        try {
            $process = new Process([
                'git', 'log',
                '--oneline',
                "-n{$count}",
                '--pretty=format:%h|%s|%cr|%an',
            ]);
            $process->mustRun();

            $output = trim($process->getOutput());
            if (empty($output)) {
                return [];
            }

            $commits = [];
            foreach (explode("\n", $output) as $line) {
                $parts = explode('|', $line, 4);
                if (count($parts) === 4) {
                    $commits[] = [
                        'hash' => $parts[0],
                        'message' => $parts[1],
                        'time' => $parts[2],
                        'author' => $parts[3],
                    ];
                }
            }

            return $commits;
        } catch (ProcessFailedException $e) {
            return [];
        }
    }

    public static function getCommitsSince(string $since): array
    {
        try {
            $process = new Process([
                'git', 'log',
                '--oneline',
                "--since={$since}",
                '--pretty=format:%h|%s|%ci|%an',
            ]);
            $process->mustRun();

            $output = trim($process->getOutput());
            if (empty($output)) {
                return [];
            }

            $commits = [];
            foreach (explode("\n", $output) as $line) {
                $parts = explode('|', $line, 4);
                if (count($parts) === 4) {
                    $commits[] = [
                        'hash' => $parts[0],
                        'message' => $parts[1],
                        'date' => $parts[2],
                        'author' => $parts[3],
                    ];
                }
            }

            return $commits;
        } catch (ProcessFailedException $e) {
            return [];
        }
    }

    public static function isGitRepository(): bool
    {
        try {
            $process = new Process(['git', 'rev-parse', '--git-dir']);
            $process->run();

            return $process->isSuccessful();
        } catch (ProcessFailedException $e) {
            return false;
        }
    }

    public static function getRepositoryRoot(): ?string
    {
        try {
            $process = new Process(['git', 'rev-parse', '--show-toplevel']);
            $process->mustRun();

            return trim($process->getOutput());
        } catch (ProcessFailedException $e) {
            return null;
        }
    }

    public static function getLastCommitTime(): ?string
    {
        try {
            $process = new Process(['git', 'log', '-1', '--format=%ci']);
            $process->mustRun();

            return trim($process->getOutput());
        } catch (ProcessFailedException $e) {
            return null;
        }
    }

    public static function getBranchTickets(): array
    {
        try {
            $process = new Process(['git', 'branch', '-a']);
            $process->mustRun();

            $output = trim($process->getOutput());
            $branches = explode("\n", $output);

            $tickets = [];
            foreach ($branches as $branch) {
                $branch = trim($branch);
                $branch = preg_replace('/^\*\s+/', '', $branch); // Remove current branch marker
                $branch = preg_replace('/^remotes\/[^\/]+\//', '', $branch); // Remove remote prefix

                $ticketId = self::extractTicketIdFromBranch($branch);
                if ($ticketId && !in_array($ticketId, $tickets)) {
                    $tickets[] = $ticketId;
                }
            }

            return array_unique($tickets);
        } catch (ProcessFailedException $e) {
            return [];
        }
    }

    public static function getWorkingDirectoryStatus(): array
    {
        try {
            $process = new Process(['git', 'status', '--porcelain']);
            $process->mustRun();

            $output = trim($process->getOutput());
            if (empty($output)) {
                return ['clean' => true, 'files' => []];
            }

            $files = [];
            foreach (explode("\n", $output) as $line) {
                if (strlen($line) >= 3) {
                    $status = substr($line, 0, 2);
                    $file = substr($line, 3);
                    $files[] = ['status' => $status, 'file' => $file];
                }
            }

            return ['clean' => false, 'files' => $files];
        } catch (ProcessFailedException $e) {
            return ['clean' => true, 'files' => []];
        }
    }

    public static function getCurrentCommitHash(): ?string
    {
        try {
            $process = new Process(['git', 'rev-parse', 'HEAD']);
            $process->mustRun();

            return trim($process->getOutput());
        } catch (ProcessFailedException $e) {
            return null;
        }
    }

    public static function getShortCommitHash(): ?string
    {
        try {
            $process = new Process(['git', 'rev-parse', '--short', 'HEAD']);
            $process->mustRun();

            return trim($process->getOutput());
        } catch (ProcessFailedException $e) {
            return null;
        }
    }

    public static function hasUncommittedChanges(): bool
    {
        $status = self::getWorkingDirectoryStatus();

        return !$status['clean'];
    }

    public static function getRemoteUrl(): ?string
    {
        try {
            $process = new Process(['git', 'remote', 'get-url', 'origin']);
            $process->mustRun();

            return trim($process->getOutput());
        } catch (ProcessFailedException $e) {
            return null;
        }
    }

    public static function getBranchUpstreamStatus(): array
    {
        try {
            $process = new Process(['git', 'status', '--porcelain=v1', '--branch']);
            $process->mustRun();

            $output = trim($process->getOutput());
            $lines = explode("\n", $output);
            $branchLine = $lines[0] ?? '';

            // Parse branch status line like: ## main...origin/main [ahead 1, behind 2]
            if (preg_match('/## ([^\.]+)\.\.\.([^\s]+)(?:\s+\[([^\]]+)\])?/', $branchLine, $matches)) {
                $local = $matches[1];
                $remote = $matches[2];
                $status = $matches[3] ?? '';

                $ahead = 0;
                $behind = 0;

                if (preg_match('/ahead (\d+)/', $status, $aheadMatch)) {
                    $ahead = (int) $aheadMatch[1];
                }

                if (preg_match('/behind (\d+)/', $status, $behindMatch)) {
                    $behind = (int) $behindMatch[1];
                }

                return [
                    'local' => $local,
                    'remote' => $remote,
                    'ahead' => $ahead,
                    'behind' => $behind,
                    'in_sync' => $ahead === 0 && $behind === 0,
                ];
            }

            return ['local' => self::getCurrentBranch(), 'remote' => null, 'ahead' => 0, 'behind' => 0, 'in_sync' => true];
        } catch (ProcessFailedException $e) {
            return ['local' => self::getCurrentBranch(), 'remote' => null, 'ahead' => 0, 'behind' => 0, 'in_sync' => true];
        }
    }

    public static function getCommitsByAuthor(string $author, int $days = 7): array
    {
        try {
            $since = date('Y-m-d', strtotime("-{$days} days"));
            $process = new Process([
                'git', 'log',
                "--author={$author}",
                "--since={$since}",
                '--pretty=format:%h|%s|%ci',
            ]);
            $process->mustRun();

            $output = trim($process->getOutput());
            if (empty($output)) {
                return [];
            }

            $commits = [];
            foreach (explode("\n", $output) as $line) {
                $parts = explode('|', $line, 3);
                if (count($parts) === 3) {
                    $commits[] = [
                        'hash' => $parts[0],
                        'message' => $parts[1],
                        'date' => $parts[2],
                    ];
                }
            }

            return $commits;
        } catch (ProcessFailedException $e) {
            return [];
        }
    }

    public static function getTicketsFromCommitMessages(int $days = 7): array
    {
        $commits = self::getCommitsSince("{$days} days ago");
        $tickets = [];

        foreach ($commits as $commit) {
            preg_match_all('/([A-Z]+[-_]\d+)/', $commit['message'], $matches);
            foreach ($matches[1] as $ticket) {
                if (!in_array($ticket, $tickets)) {
                    $tickets[] = $ticket;
                }
            }
        }

        return $tickets;
    }

    public static function suggestTaskFromGit(): ?array
    {
        // Try to get ticket from current branch first
        $branchTicket = self::extractTicketIdFromBranch();
        if ($branchTicket) {
            return [
                'source' => 'branch',
                'ticket' => $branchTicket,
                'confidence' => 'high',
            ];
        }

        // Try to get ticket from recent commits
        $commitTickets = self::getTicketsFromCommitMessages(1);
        if (!empty($commitTickets)) {
            return [
                'source' => 'commits',
                'ticket' => $commitTickets[0],
                'confidence' => 'medium',
            ];
        }

        return null;
    }

    public static function getRepositoryInfo(): array
    {
        return [
            'is_repo' => self::isGitRepository(),
            'root' => self::getRepositoryRoot(),
            'branch' => self::getCurrentBranch(),
            'commit' => self::getShortCommitHash(),
            'ticket' => self::extractTicketIdFromBranch(),
            'has_changes' => self::hasUncommittedChanges(),
            'remote' => self::getRemoteUrl(),
            'upstream' => self::getBranchUpstreamStatus(),
        ];
    }
}
