<?php

declare(strict_types=1);

namespace MiLopez\ClockifyWizard\Helper;

use Carbon\Carbon;
use InvalidArgumentException;

class TimeHelper
{
    /**
     * Initialize timezone configuration.
     */
    public static function initializeTimezone(?string $timezone = null): void
    {
        // Set default timezone for the application
        $timezone = $timezone ?? self::detectTimezone();

        // Set for PHP
        date_default_timezone_set($timezone);

        // Set for Carbon
        Carbon::setLocale('es'); // Spanish locale for Chile

        // You can also set a default timezone for Carbon
        // Carbon::setDefaultTimezone($timezone);
    }

    /**
     * Detect timezone automatically.
     */
    public static function detectTimezone(): string
    {
        // Try to detect from system
        if (function_exists('date_default_timezone_get')) {
            $systemTimezone = date_default_timezone_get();
            if ($systemTimezone && $systemTimezone !== 'UTC') {
                return $systemTimezone;
            }
        }

        // Try environment variable
        if (!empty($_ENV['TZ'])) {
            return $_ENV['TZ'];
        }

        // Default to Chile timezone
        return 'America/Santiago';
    }

    /**
     * Parse duration string into minutes
     * Supports: 1h, 30m, 1.5h, 90m, 1h30m, etc.
     */
    public static function parseDuration(string $duration): int
    {
        $duration = strtolower(trim($duration));

        // Remove spaces
        $duration = str_replace(' ', '', $duration);

        // Pattern for complex format like "1h30m"
        if (preg_match('/^(\d+(?:\.\d+)?)h(\d+)m$/', $duration, $matches)) {
            $hours = (float) $matches[1];
            $minutes = (int) $matches[2];

            return (int) ($hours * 60 + $minutes);
        }

        // Pattern for hours only like "1.5h" or "2h"
        if (preg_match('/^(\d+(?:\.\d+)?)h$/', $duration, $matches)) {
            $hours = (float) $matches[1];

            return (int) ($hours * 60);
        }

        // Pattern for minutes only like "90m" or "30m"
        if (preg_match('/^(\d+)m$/', $duration, $matches)) {
            return (int) $matches[1];
        }

        // Pattern for decimal hours without unit like "1.5"
        if (preg_match('/^(\d+(?:\.\d+)?)$/', $duration, $matches)) {
            $hours = (float) $matches[1];

            return (int) ($hours * 60);
        }

        throw new InvalidArgumentException("Invalid duration format: {$duration}. Use formats like '1h', '30m', '1.5h', '1h30m'");
    }

    /**
     * Format minutes into human readable duration.
     */
    public static function formatDuration(int|float $minutes): string
    {
        $minutes = (int) $minutes;
        if ($minutes < 60) {
            return "{$minutes}m";
        }

        $hours = floor($minutes / 60);
        $remainingMinutes = $minutes % 60;

        if ($remainingMinutes === 0) {
            return "{$hours}h";
        }

        return "{$hours}h{$remainingMinutes}m";
    }

    /**
     * Calculate start time based on duration and end time.
     */
    public static function calculateStartTime(string $duration, ?Carbon $endTime = null): Carbon
    {
        $endTime = $endTime ?? Carbon::now();
        $minutes = self::parseDuration($duration);

        return $endTime->copy()->subMinutes($minutes);
    }

    /**
     * Parse time string into Carbon instance with proper timezone
     * Supports: 9:30am, 14:30, 2:30pm, now, etc.
     */
    public static function parseTime(string $timeString, ?Carbon $date = null): Carbon
    {
        $timeString = strtolower(trim($timeString));
        $date = $date ?? Carbon::now();

        if ($timeString === 'now') {
            return Carbon::now();
        }

        // Handle 12-hour format with am/pm
        if (preg_match('/^(\d{1,2}):(\d{2})(am|pm)$/', $timeString, $matches)) {
            $hour = (int) $matches[1];
            $minute = (int) $matches[2];
            $ampm = $matches[3];

            if ($ampm === 'pm' && $hour !== 12) {
                $hour += 12;
            } elseif ($ampm === 'am' && $hour === 12) {
                $hour = 0;
            }

            return $date->copy()->setTime($hour, $minute, 0);
        }

        // Handle 24-hour format
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $timeString, $matches)) {
            $hour = (int) $matches[1];
            $minute = (int) $matches[2];

            return $date->copy()->setTime($hour, $minute, 0);
        }

        // Handle hour only with am/pm
        if (preg_match('/^(\d{1,2})(am|pm)$/', $timeString, $matches)) {
            $hour = (int) $matches[1];
            $ampm = $matches[2];

            if ($ampm === 'pm' && $hour !== 12) {
                $hour += 12;
            } elseif ($ampm === 'am' && $hour === 12) {
                $hour = 0;
            }

            return $date->copy()->setTime($hour, 0, 0);
        }

        throw new InvalidArgumentException("Invalid time format: {$timeString}. Use formats like '9:30am', '14:30', '2pm'");
    }

    /**
     * Round time to nearest interval.
     */
    public static function roundTime(Carbon $time, int $roundToMinutes = 15): Carbon
    {
        $minutes = $time->minute;
        $roundedMinutes = round($minutes / $roundToMinutes) * $roundToMinutes;

        return $time->copy()->setMinute((int) $roundedMinutes)->setSecond(0);
    }

    /**
     * Get common duration suggestions.
     */
    public static function getDurationSuggestions(): array
    {
        return [
            '30m' => '30 minutos',
            '1h' => '1 hora',
            '1h30m' => '1.5 horas',
            '2h' => '2 horas',
            '3h' => '3 horas',
            '4h' => '4 horas',
            '6h' => '6 horas',
            '8h' => '8 horas',
        ];
    }

    /**
     * Validate if two time ranges overlap.
     */
    public static function timeRangesOverlap(
        Carbon $start1,
        Carbon $end1,
        Carbon $start2,
        Carbon $end2
    ): bool {
        return $start1->lt($end2) && $start2->lt($end1);
    }

    /**
     * Calculate total minutes from time entries.
     */
    public static function calculateTotalDuration(array $timeEntries): int
    {
        $totalMinutes = 0;

        foreach ($timeEntries as $entry) {
            if (isset($entry['timeInterval']['start']) && isset($entry['timeInterval']['end'])) {
                $start = Carbon::parse($entry['timeInterval']['start']);
                $end = Carbon::parse($entry['timeInterval']['end']);
                $totalMinutes += $end->diffInMinutes($start);
            }
        }

        return (int) $totalMinutes;
    }

    /**
     * Get smart time suggestions based on current time.
     */
    public static function getSmartTimeSuggestions(): array
    {
        $now = Carbon::now();
        $suggestions = [];

        // Suggest common work start times in Chile context
        $workStartTimes = ['8:00am', '8:30am', '9:00am', '9:30am', '10:00am'];

        foreach ($workStartTimes as $startTime) {
            try {
                $start = self::parseTime($startTime);
                if ($start->lt($now)) {
                    $duration = $now->diffInMinutes($start);
                    if ($duration <= 480) { // Max 8 hours
                        $suggestions[] = [
                            'duration' => self::formatDuration((int) $duration),
                            'description' => "Desde las {$startTime}",
                            'start_time' => $start->format('H:i'),
                        ];
                    }
                }
            } catch (InvalidArgumentException $e) {
                // Skip invalid time
            }
        }

        return $suggestions;
    }

    /**
     * Convert UTC time to local time for display.
     */
    public static function toLocalTime(string $utcTimeString): Carbon
    {
        return Carbon::parse($utcTimeString)->setTimezone(date_default_timezone_get());
    }

    /**
     * Convert local time to UTC for API calls.
     */
    public static function toUtcTime(Carbon $localTime): Carbon
    {
        return $localTime->copy()->utc();
    }

    /**
     * Format time for display with timezone awareness.
     */
    public static function formatTimeForDisplay(Carbon $time): string
    {
        return $time->format('Y-m-d H:i T');
    }

    /**
     * Create a Carbon instance with proper timezone.
     */
    public static function now(): Carbon
    {
        return Carbon::now(date_default_timezone_get());
    }

    /**
     * Parse ISO string and convert to local timezone.
     */
    public static function parseIsoToLocal(string $isoString): Carbon
    {
        return Carbon::parse($isoString)->setTimezone(date_default_timezone_get());
    }

    /**
     * Get current timezone information.
     */
    public static function getTimezoneInfo(): array
    {
        $timezone = date_default_timezone_get();
        $now = Carbon::now();

        return [
            'name' => $timezone,
            'abbreviation' => $now->format('T'),
            'offset' => $now->format('P'),
            'offset_seconds' => $now->getOffset(),
            'is_dst' => $now->isDST(),
        ];
    }
}
