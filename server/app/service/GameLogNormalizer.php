<?php

namespace app\service;

class GameLogNormalizer
{
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
                'time' => (string)($event['time'] ?? date('Y-m-d H:i:s')),
                'raw' => $event,
            ];
        }
        return $normalized;
    }
}
