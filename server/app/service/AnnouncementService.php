<?php

namespace app\service;

use app\repository\AnnouncementRepositoryInterface;
use app\support\I18n;
use RuntimeException;

class AnnouncementService
{
    private const COLOR_PREFIXES = ['red', 'green', 'blue'];

    public function __construct(private AnnouncementRepositoryInterface $announcements)
    {
    }

    public function latest(string $locale): ?array
    {
        $row = $this->announcements->latestEnabled();
        if (!$row) {
            return null;
        }

        $locale = I18n::normalizeLocale($locale);
        $titleColumn = $locale === 'vi' ? 'title_vi' : 'title_zh_cn';
        $contentColumn = $locale === 'vi' ? 'content_vi' : 'content_zh_cn';

        return [
            'id' => (int)$row['id'],
            'title' => (string)($row[$titleColumn] ?? ''),
            'content_blocks' => $this->parseContentBlocks((string)($row[$contentColumn] ?? '')),
            'published_at' => (string)($row['published_at'] ?? ''),
        ];
    }

    public function parseContentBlocks(string $content): array
    {
        $blocks = [];
        $lines = preg_split('/\r\n|\r|\n/', $content);
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '') {
                continue;
            }

            $color = 'default';
            if (preg_match('/^\[([a-z]+)\](.*)$/', $line, $matches)) {
                $prefix = $matches[1];
                if (!in_array($prefix, self::COLOR_PREFIXES, true)) {
                    throw new RuntimeException(I18n::t('api.announcement.color_invalid'));
                }
                $color = $prefix;
                $line = trim($matches[2]);
            }

            if ($line !== '') {
                $blocks[] = [
                    'text' => $line,
                    'color' => $color,
                ];
            }
        }

        if ($blocks === []) {
            throw new RuntimeException(I18n::t('api.announcement.content_empty'));
        }

        return $blocks;
    }
}
