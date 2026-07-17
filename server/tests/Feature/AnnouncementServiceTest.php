<?php

namespace tests\Feature;

use app\repository\AnnouncementRepositoryInterface;
use app\service\AnnouncementService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AnnouncementServiceTest extends TestCase
{
    public function testLatestReturnsNullWhenNoEnabledAnnouncementExists(): void
    {
        $service = new AnnouncementService(new InMemoryAnnouncementRepository([
            [
                'id' => 1,
                'status' => 0,
                'title_zh_cn' => '旧公告',
                'title_vi' => 'Thong bao cu',
                'content_zh_cn' => '不可见',
                'content_vi' => 'Khong hien thi',
                'published_at' => '2026-07-01 10:00:00',
            ],
        ]));

        $result = $service->latest('zh_CN');

        $this->assertNull($result);
    }

    public function testLatestReturnsNewestEnabledAnnouncementForRequestedLocale(): void
    {
        $service = new AnnouncementService(new InMemoryAnnouncementRepository([
            [
                'id' => 1,
                'status' => 1,
                'title_zh_cn' => '旧公告',
                'title_vi' => 'Thong bao cu',
                'content_zh_cn' => '[red]旧内容',
                'content_vi' => '[red]Noi dung cu',
                'published_at' => '2026-07-01 10:00:00',
            ],
            [
                'id' => 2,
                'status' => 1,
                'title_zh_cn' => '最新公告',
                'title_vi' => 'Thong bao moi nhat',
                'content_zh_cn' => "[red]请勿切换账号\n[green]请勿删除缓存\n普通说明",
                'content_vi' => "[red]Khong doi tai khoan\n[green]Khong xoa bo nho dem\nNoi dung thuong",
                'published_at' => '2026-07-02 10:00:00',
            ],
        ]));

        $result = $service->latest('vi');

        $this->assertSame(2, $result['id']);
        $this->assertSame('Thong bao moi nhat', $result['title']);
        $this->assertSame('2026-07-02 10:00:00', $result['published_at']);
        $this->assertSame([
            ['text' => 'Khong doi tai khoan', 'color' => 'red', 'segments' => [['type' => 'text', 'text' => 'Khong doi tai khoan']]],
            ['text' => 'Khong xoa bo nho dem', 'color' => 'green', 'segments' => [['type' => 'text', 'text' => 'Khong xoa bo nho dem']]],
            ['text' => 'Noi dung thuong', 'color' => 'default', 'segments' => [['type' => 'text', 'text' => 'Noi dung thuong']]],
        ], $result['content_blocks']);
    }

    public function testParsesHttpLinksIntoClickableSegments(): void
    {
        $service = new AnnouncementService(new InMemoryAnnouncementRepository([]));

        $blocks = $service->parseContentBlocks('[red]Lien he https://www.facebook.com/share/1EyknTE679/. Cam on');

        $this->assertSame([
            [
                'text' => 'Lien he https://www.facebook.com/share/1EyknTE679/. Cam on',
                'color' => 'red',
                'segments' => [
                    ['type' => 'text', 'text' => 'Lien he '],
                    [
                        'type' => 'link',
                        'text' => 'https://www.facebook.com/share/1EyknTE679/',
                        'url' => 'https://www.facebook.com/share/1EyknTE679/',
                    ],
                    ['type' => 'text', 'text' => '. Cam on'],
                ],
            ],
        ], $blocks);
    }

    public function testRejectsUnsupportedContentColorPrefix(): void
    {
        $service = new AnnouncementService(new InMemoryAnnouncementRepository([]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('公告正文颜色标记不支持');

        $service->parseContentBlocks('[purple]不支持的颜色');
    }

    public function testRejectsContentWithoutVisibleTextBlocks(): void
    {
        $service = new AnnouncementService(new InMemoryAnnouncementRepository([]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('公告正文不能为空');

        $service->parseContentBlocks("[red]\n\n");
    }
}

class InMemoryAnnouncementRepository implements AnnouncementRepositoryInterface
{
    public function __construct(private array $rows)
    {
    }

    public function latestEnabled(): ?array
    {
        $enabled = array_values(array_filter($this->rows, static fn (array $row): bool => (int)($row['status'] ?? 0) === 1));
        usort($enabled, static function (array $left, array $right): int {
            return strcmp((string)($right['published_at'] ?? ''), (string)($left['published_at'] ?? ''));
        });

        return $enabled[0] ?? null;
    }
}
