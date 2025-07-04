<?php

declare(strict_types=1);

namespace MiLopez\ClockifyWizard\Helper;

use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

class ConsoleHelper
{
    public static function displayHeader(OutputInterface $output, string $title): void
    {
        $output->writeln('');
        $output->writeln("<fg=cyan>🕐 {$title}</>");
        $output->writeln('<fg=cyan>' . str_repeat('=', strlen($title) + 3) . '</>');
        $output->writeln('');
    }

    public static function displaySuccess(OutputInterface $output, string $message): void
    {
        $output->writeln("<fg=green>✅ {$message}</>");
    }

    public static function displayError(OutputInterface $output, string $message): void
    {
        $output->writeln("<fg=red>❌ {$message}</>");
    }

    public static function displayWarning(OutputInterface $output, string $message): void
    {
        $output->writeln("<fg=yellow>⚠️  {$message}</>");
    }

    public static function displayInfo(OutputInterface $output, string $message): void
    {
        $output->writeln("<fg=blue>ℹ️  {$message}</>");
    }

    public static function displaySection(OutputInterface $output, string $title, string $emoji = '📋'): void
    {
        $output->writeln('');
        $output->writeln("<fg=yellow>{$emoji} {$title}</>");
        $output->writeln('<fg=yellow>' . str_repeat('-', strlen($title) + 3) . '</>');
    }

    /**
     * Enhanced choice selection with search and pagination for large lists.
     */
    public static function askChoiceEnhanced(
        InputInterface $input,
        OutputInterface $output,
        string $question,
        array $choices,
        ?string $default = null,
        bool $allowCustom = false,
        int $pageSize = 20
    ): string {
        $questionHelper = new QuestionHelper();

        // If choices are manageable, use standard choice
        if (count($choices) <= $pageSize) {
            return self::askChoice($input, $output, $question, $choices, $default, $allowCustom);
        }

        // For large lists, offer search or pagination
        $output->writeln($question);
        $output->writeln('<fg=yellow>Found ' . count($choices) . ' options. Choose how to proceed:</>');

        $methods = [
            'search' => '🔍 Search by name',
            'browse' => '📋 Browse with pagination',
            'list' => '📝 Show first ' . $pageSize . ' items',
        ];

        if ($allowCustom) {
            $methods['custom'] = '✏️  Enter custom value';
        }

        $method = self::askChoice($input, $output, 'Selection method:', $methods);

        switch ($method) {
            case 'search':
                return self::searchAndSelect($input, $output, $choices, $questionHelper);

            case 'browse':
                return self::browseAndSelect($input, $output, $choices, $questionHelper, $pageSize);

            case 'list':
                $limitedChoices = array_slice($choices, 0, $pageSize, true);

                return self::askChoice($input, $output, 'Select from first ' . $pageSize . ' options:', $limitedChoices, $default, true);

            case 'custom':
                $customQuestion = new Question('Enter custom value: ');

                return $questionHelper->ask($input, $output, $customQuestion);

            default:
                return self::askChoice($input, $output, $question, $choices, $default, $allowCustom);
        }
    }

    /**
     * Search functionality for large choice lists.
     */
    private static function searchAndSelect(
        InputInterface $input,
        OutputInterface $output,
        array $choices,
        QuestionHelper $questionHelper
    ): string {
        while (true) {
            $searchQuestion = new Question('🔍 Enter search term (or "exit" to go back): ');
            $searchTerm = $questionHelper->ask($input, $output, $searchQuestion);

            if (strtolower(trim($searchTerm)) === 'exit') {
                throw new \RuntimeException('Search cancelled by user');
            }

            if (empty(trim($searchTerm))) {
                self::displayWarning($output, 'Please enter a search term');
                continue;
            }

            // Filter choices based on search term
            $filteredChoices = [];
            foreach ($choices as $key => $choice) {
                if (stripos($choice, $searchTerm) !== false) {
                    $filteredChoices[$key] = $choice;
                }
            }

            if (empty($filteredChoices)) {
                self::displayWarning($output, "No results found for: {$searchTerm}");
                continue;
            }

            $output->writeln('<fg=green>Found ' . count($filteredChoices) . ' matches:</>');

            if (count($filteredChoices) <= 20) {
                // Show results directly
                $choiceQuestion = new ChoiceQuestion('Select option:', $filteredChoices);
                $choiceQuestion->setErrorMessage('Choice %s is invalid.');

                return $questionHelper->ask($input, $output, $choiceQuestion);
            } else {
                // Too many results, refine search
                $output->writeln('<fg=yellow>Too many results (' . count($filteredChoices) . '). Please refine your search.</>');

                // Show first few matches as example
                $sampleResults = array_slice($filteredChoices, 0, 5, true);
                $output->writeln('<fg=cyan>Sample results:</>');
                foreach ($sampleResults as $choice) {
                    $output->writeln("  • {$choice}");
                }
                if (count($filteredChoices) > 5) {
                    $output->writeln('  ... and ' . (count($filteredChoices) - 5) . ' more');
                }
                continue;
            }
        }
    }

    /**
     * Browse with pagination for large choice lists.
     */
    private static function browseAndSelect(
        InputInterface $input,
        OutputInterface $output,
        array $choices,
        QuestionHelper $questionHelper,
        int $pageSize
    ): string {
        $totalPages = (int) ceil(count($choices) / $pageSize);
        $currentPage = 1;
        $choiceKeys = array_keys($choices);

        while (true) {
            $startIndex = ($currentPage - 1) * $pageSize;
            $endIndex = min($startIndex + $pageSize, count($choices));

            $output->writeln('');
            $output->writeln("<fg=cyan>📄 Page {$currentPage} of {$totalPages} (items " . ($startIndex + 1) . "-{$endIndex} of " . count($choices) . ')</>');
            $output->writeln('');

            // Show current page items
            $pageChoices = [];
            for ($i = $startIndex; $i < $endIndex; $i++) {
                $key = $choiceKeys[$i];
                $choice = $choices[$key];
                $pageChoices[$key] = $choice;
                $output->writeln('  [<fg=cyan>' . ($i - $startIndex) . "</>] {$choice}");
            }

            $output->writeln('');

            // Navigation options
            $navOptions = [];
            if ($currentPage > 1) {
                $navOptions['prev'] = '⬅️  Previous page';
            }
            if ($currentPage < $totalPages) {
                $navOptions['next'] = '➡️  Next page';
            }
            $navOptions['select'] = '✅ Select from this page';
            $navOptions['search'] = '🔍 Search instead';
            $navOptions['jump'] = '🎯 Jump to page';

            $action = self::askChoice($input, $output, 'What would you like to do?', $navOptions);

            switch ($action) {
                case 'prev':
                    $currentPage--;
                    break;

                case 'next':
                    $currentPage++;
                    break;

                case 'select':
                    $indexChoices = [];
                    for ($i = 0; $i < count($pageChoices); $i++) {
                        $indexChoices[$i] = (string) $i;
                    }

                    $selectedIndex = self::askChoice($input, $output, 'Select item number:', $indexChoices);
                    $selectedKey = array_keys($pageChoices)[$selectedIndex];

                    return $choices[$selectedKey];

                case 'search':
                    return self::searchAndSelect($input, $output, $choices, $questionHelper);

                case 'jump':
                    $pageQuestion = new Question("Enter page number (1-{$totalPages}): ");
                    $pageNumber = $questionHelper->ask($input, $output, $pageQuestion);
                    $pageNumber = (int) $pageNumber;

                    if ($pageNumber >= 1 && $pageNumber <= $totalPages) {
                        $currentPage = $pageNumber;
                    } else {
                        self::displayWarning($output, "Invalid page number. Must be between 1 and {$totalPages}");
                    }
                    break;
            }
        }
    }

    public static function askChoice(
        InputInterface $input,
        OutputInterface $output,
        string $question,
        array $choices,
        ?string $default = null,
        bool $allowCustom = false
    ): string {
        $questionHelper = new QuestionHelper();

        if ($allowCustom) {
            // Display choices but allow custom input
            $output->writeln($question);
            foreach ($choices as $index => $choice) {
                $output->writeln("  [<fg=cyan>{$index}</>] {$choice}");
            }
            $output->writeln('  [<fg=cyan>custom</>] Enter custom value');

            $choiceQuestion = new Question('> ', $default);
            $answer = $questionHelper->ask($input, $output, $choiceQuestion);

            if (is_numeric($answer) && isset($choices[$answer])) {
                return $choices[$answer];
            } elseif ($answer === 'custom') {
                $customQuestion = new Question('Enter custom value: ');

                return $questionHelper->ask($input, $output, $customQuestion);
            }

            return $answer;
        }

        $choiceQuestion = new ChoiceQuestion($question, $choices, $default);
        $choiceQuestion->setErrorMessage('Choice %s is invalid.');

        return $questionHelper->ask($input, $output, $choiceQuestion);
    }

    public static function askQuestion(
        InputInterface $input,
        OutputInterface $output,
        string $question,
        ?string $default = null,
        bool $hidden = false
    ): string {
        $questionHelper = new QuestionHelper();
        $questionObj = new Question($question, $default);

        if ($hidden) {
            $questionObj->setHidden(true);
        }

        $answer = $questionHelper->ask($input, $output, $questionObj);

        return (string) ($answer ?? $default ?? '');
    }

    public static function askConfirmation(
        InputInterface $input,
        OutputInterface $output,
        string $question,
        bool $default = false
    ): bool {
        $questionHelper = new QuestionHelper();
        $suffix = $default ? '(Y/n)' : '(y/N)';
        $confirmationQuestion = new ConfirmationQuestion("{$question} {$suffix}: ", $default);

        return $questionHelper->ask($input, $output, $confirmationQuestion);
    }

    // ... resto de métodos existentes sin cambios ...

    public static function displayTicketSummary(
        OutputInterface $output,
        array $ticketData,
        ?array $timeData = null
    ): void {
        self::displaySection($output, 'Ticket Summary', '📝');

        $output->writeln("📁 Project: <fg=cyan>{$ticketData['project']}</>");
        $output->writeln("🎯 Ticket: <fg=green>{$ticketData['key']}</>");
        $output->writeln("📝 Summary: {$ticketData['summary']}");

        if (isset($ticketData['status'])) {
            $output->writeln("📊 Status: <fg=yellow>{$ticketData['status']}</>");
        }

        if (isset($ticketData['assignee'])) {
            $output->writeln("👤 Assignee: {$ticketData['assignee']}");
        }

        if ($timeData) {
            $output->writeln("⏱️  Duration: <fg=magenta>{$timeData['duration']}</>");
            $output->writeln("🕐 Start: {$timeData['start']}");
            $output->writeln("🕕 End: {$timeData['end']}");
        }
    }

    public static function displayTimeEntryTable(OutputInterface $output, array $timeEntries): void
    {
        if (empty($timeEntries)) {
            self::displayInfo($output, 'No time entries found.');

            return;
        }

        $table = new Table($output);
        $table->setHeaders(['Date', 'Duration', 'Project', 'Task', 'Description']);

        $totalMinutes = 0;

        foreach ($timeEntries as $entry) {
            $start = \Carbon\Carbon::parse($entry['timeInterval']['start']);
            $end = isset($entry['timeInterval']['end'])
                ? \Carbon\Carbon::parse($entry['timeInterval']['end'])
                : \Carbon\Carbon::now();

            $duration = $end->diffInMinutes($start);
            $totalMinutes += $duration;

            $table->addRow([
                $start->format('M d'),
                TimeHelper::formatDuration((int) $duration),
                $entry['project']['name'] ?? 'N/A',
                $entry['task']['name'] ?? 'No task',
                $entry['description'] ?? '',
            ]);
        }

        $separator = new TableSeparator();
        $table->addRow($separator);
        $table->addRow([
            '<fg=yellow>Total</>',
            '<fg=green>' . TimeHelper::formatDuration($totalMinutes) . '</>',
            '',
            '',
            '',
        ]);

        $table->render();
    }

    public static function displayProjectMappings(OutputInterface $output, array $mappings): void
    {
        if (empty($mappings)) {
            self::displayInfo($output, 'No project mappings configured.');

            return;
        }

        self::displaySection($output, 'Project Mappings', '🔗');

        $table = new Table($output);
        $table->setHeaders(['Jira Project', 'Clockify Project']);

        foreach ($mappings as $jiraProject => $clockifyProject) {
            $table->addRow([$jiraProject, $clockifyProject]);
        }

        $table->render();
    }

    public static function displayProgressBar(OutputInterface $output, string $message): void
    {
        $output->write("🔄 {$message}...");
    }

    public static function finishProgressBar(OutputInterface $output): void
    {
        $output->writeln(' <fg=green>Done!</>');
    }

    public static function displayDurationSuggestions(OutputInterface $output): void
    {
        $suggestions = TimeHelper::getDurationSuggestions();

        $output->writeln('<fg=yellow>💡 Duration suggestions:</>');
        foreach ($suggestions as $duration => $description) {
            $output->writeln("  • <fg=cyan>{$duration}</> - {$description}");
        }
        $output->writeln('');
    }

    public static function displayActiveTimer(OutputInterface $output, array $timerData): void
    {
        $start = \Carbon\Carbon::parse($timerData['start']);
        $elapsed = $start->diffInMinutes(\Carbon\Carbon::now());

        self::displaySection($output, 'Active Timer', '⏱️');
        $output->writeln("📁 Project: <fg=cyan>{$timerData['project']}</>");
        $output->writeln("🎯 Task: <fg=green>{$timerData['task']}</>");
        $output->writeln("🕐 Started: {$start->format('H:i')} ({$start->diffForHumans()})");
        $output->writeln('⏱️  Elapsed: <fg=magenta>' . TimeHelper::formatDuration((int) $elapsed) . '</>');
        $output->writeln('');
    }

    public static function clearLine(OutputInterface $output): void
    {
        $output->write("\r\033[K");
    }

    public static function displayGitInfo(OutputInterface $output): void
    {
        $branch = GitHelper::getCurrentBranch();
        $ticketId = GitHelper::extractTicketIdFromBranch($branch);

        if ($branch) {
            self::displaySection($output, 'Git Information', '🌿');
            $output->writeln("🌿 Current branch: <fg=cyan>{$branch}</>");

            if ($ticketId) {
                $output->writeln("🎫 Detected ticket: <fg=green>{$ticketId}</>");
            } else {
                $output->writeln('🎫 No ticket ID detected in branch name');
            }
            $output->writeln('');
        }
    }
}
