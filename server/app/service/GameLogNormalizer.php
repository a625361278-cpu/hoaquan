<?php

namespace app\service;

use support\Log;

class GameLogNormalizer
{
    private const DISPLAY_TIME_FORMAT = 'Y-m-d H:i:s';

    public function normalizeLines(array $lines): array
    {
        $normalized = [];
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line !== '') {
                $normalized[] = $line;
            }
        }
        return $normalized;
    }

    public function eventsFromLines(array $lines): array
    {
        $events = [];
        foreach ($lines as $line) {
            $evtIndex = strpos($line, '[[EVT]]');
            if ($evtIndex === false) {
                continue;
            }
            $json = trim(substr($line, $evtIndex + 7));
            $event = json_decode($json, true);
            if (is_array($event)) {
                $events[] = $event;
            }
        }
        return $this->normalizeEvents($events);
    }

    public function formatStructuredLog(array $payload): string
    {
        $parts = [];
        if (array_key_exists('time', $payload)) {
            $parts[] = $this->normalizeTime($payload['time'], 'log');
        }
        if (!empty($payload['level'])) {
            $parts[] = '[' . strtoupper((string)$payload['level']) . ']';
        }
        if (!empty($payload['category'])) {
            $parts[] = '[' . (string)$payload['category'] . ']';
        }
        $parts[] = (string)($payload['message'] ?? '');
        return trim(implode(' ', array_filter($parts)));
    }

    public function normalizeEvents(array $events): array
    {
        $normalized = [];
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            $module = trim((string)($event['module'] ?? $event['category'] ?? ''));
            $title = trim((string)($event['title'] ?? ''));
            $desc = trim((string)($event['desc'] ?? $event['message'] ?? ''));
            $status = trim((string)($event['status'] ?? ''));
            if ($module === '' && $title === '' && $desc === '') {
                continue;
            }
            $normalized[] = [
                'id' => (string)($event['id'] ?? bin2hex(random_bytes(8))),
                'module' => $module,
                'title' => $title,
                'desc' => $desc,
                'status' => $status,
                'time' => $this->normalizeTime($event['time'] ?? null, 'event'),
                'raw' => $event,
            ];
        }
        return $normalized;
    }

    public function normalizeTime(mixed $value, string $source): string
    {
        if ($value === null || $value === '') {
            return $this->fallbackTime($source, $value, 'empty');
        }

        if (is_int($value) || is_float($value) || is_string($value)) {
            $time = trim((string)$value);
            if ($time === '') {
                return $this->fallbackTime($source, $value, 'empty');
            }

            if (preg_match('/^\d{13}$/', $time) === 1) {
                return date(self::DISPLAY_TIME_FORMAT, intdiv((int)$time, 1000));
            }
            if (preg_match('/^\d{10}$/', $time) === 1) {
                return date(self::DISPLAY_TIME_FORMAT, (int)$time);
            }
            if (preg_match('/^\d+$/', $time) === 1) {
                return $this->fallbackTime($source, $value, 'unsupported_numeric_length');
            }

            $timestamp = strtotime($time);
            if ($timestamp !== false) {
                return date(self::DISPLAY_TIME_FORMAT, $timestamp);
            }
        }

        return $this->fallbackTime($source, $value, 'invalid');
    }

    private function fallbackTime(string $source, mixed $value, string $reason): string
    {
        Log::warning('Third-party log time invalid, falling back to server time', [
            'source' => $source,
            'reason' => $reason,
            'time_type' => get_debug_type($value),
            'time_value' => is_scalar($value) ? substr((string)$value, 0, 128) : '',
        ]);
        return date(self::DISPLAY_TIME_FORMAT);
    }
}
