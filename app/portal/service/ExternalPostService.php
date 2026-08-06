<?php

namespace app\portal\service;

use app\portal\model\ExternalContentModel;
use app\portal\model\PortalCategoryModel;
use app\portal\model\PortalPostModel;
use RuntimeException;
use think\facade\Db;

class ExternalPostService
{
    private $authorId;

    public function __construct(int $authorId)
    {
        $this->authorId = max(1, $authorId);
    }

    public function publish(array $item): string
    {
        $this->validate($item);
        $sourceHash = $this->sourceHash($item);

        return Db::transaction(function () use ($item, $sourceHash) {
            $record = ExternalContentModel::where('source', $item['source'])
                ->where('external_id', $item['external_id'])
                ->lock(true)
                ->find();

            if ($record && (int)$record->portal_post_id > 0 && $record->content_hash === $sourceHash) {
                $record->save([
                    'sync_status' => 'synced',
                    'last_error' => null,
                    'synced_at' => time(),
                ]);
                return 'unchanged';
            }

            if ($record && (int)$record->portal_post_id > 0) {
                $post = PortalPostModel::find((int)$record->portal_post_id);
                if (!$post || (int)$post->delete_time > 0 || (int)$post->post_status !== 1) {
                    $record->save([
                        'sync_status' => 'protected',
                        'last_error' => 'The linked article is missing, unpublished, or deleted; automatic republishing was blocked.',
                        'synced_at' => time(),
                    ]);
                    return 'protected';
                }

                if ($record->portal_hash !== '' && $this->portalHash($post) !== $record->portal_hash) {
                    $record->save([
                        'sync_status' => 'manual',
                        'last_error' => 'The website article was edited manually; source refresh was not applied.',
                        'synced_at' => time(),
                    ]);
                    return 'manual';
                }

                $post->save($this->postData($item));
                $this->attachCategory((int)$post->id, (int)$item['category_id']);
                $post = PortalPostModel::find((int)$post->id);
                $record->save($this->recordData($item, (int)$post->id, $sourceHash, $this->portalHash($post)));
                return 'updated';
            }

            $post = new PortalPostModel();
            $post->save($this->postData($item));
            $this->attachCategory((int)$post->id, (int)$item['category_id']);
            $post = PortalPostModel::find((int)$post->id);

            $data = $this->recordData($item, (int)$post->id, $sourceHash, $this->portalHash($post));
            if ($record) {
                $record->save($data);
            } else {
                (new ExternalContentModel())->save(array_merge($data, [
                    'source' => $item['source'],
                    'external_id' => $item['external_id'],
                ]));
            }

            return 'created';
        });
    }

    public function recordError(string $source, string $externalId, string $url, string $title, string $message): void
    {
        $record = ExternalContentModel::where('source', $source)
            ->where('external_id', $externalId)
            ->find();
        $data = [
            'source_url' => $this->truncate($url, 1000),
            'source_title' => $this->truncate($title, 255),
            'sync_status' => 'error',
            'last_error' => $this->truncate($message, 4000),
            'synced_at' => time(),
        ];

        if ($record) {
            $record->save($data);
            return;
        }

        (new ExternalContentModel())->save(array_merge($data, [
            'source' => $source,
            'external_id' => $externalId,
        ]));
    }

    public function ensureSecYearCategory(int $year, int $parentId): int
    {
        $parent = PortalCategoryModel::where('id', $parentId)
            ->where('delete_time', 0)
            ->find();
        if (!$parent) {
            throw new RuntimeException('SEC parent category ' . $parentId . ' does not exist.');
        }

        $category = PortalCategoryModel::where('parent_id', $parentId)
            ->where('name', (string)$year)
            ->where('delete_time', 0)
            ->find();
        if ($category) {
            return (int)$category->id;
        }

        return Db::transaction(function () use ($year, $parentId, $parent) {
            $category = PortalCategoryModel::where('parent_id', $parentId)
                ->where('name', (string)$year)
                ->where('delete_time', 0)
                ->lock(true)
                ->find();
            if ($category) {
                return (int)$category->id;
            }

            $category = new PortalCategoryModel();
            $category->save([
                'parent_id' => $parentId,
                'post_count' => 0,
                'status' => 1,
                'delete_time' => 0,
                'list_order' => 10000 - $year,
                'name' => (string)$year,
                'description' => 'SEC filings published in ' . $year,
                'path' => '',
                'seo_title' => 'SEC Filings ' . $year,
                'seo_keywords' => 'XMax,SEC,filings,' . $year,
                'seo_description' => 'XMax Inc. SEC filings published in ' . $year,
                'list_tpl' => 'list-2',
                'one_tpl' => 'article',
                'more' => [],
            ]);
            $path = rtrim((string)$parent->path, '-') . '-' . (int)$category->id;
            $category->save(['path' => ltrim($path, '-')]);
            return (int)$category->id;
        });
    }

    private function postData(array $item): array
    {
        $published = max(1, (int)$item['published_time']);
        $more = [
            'thumbnail' => (string)($item['thumbnail'] ?? ''),
            'external_source' => $item['source'],
            'external_id' => $item['external_id'],
            'external_url' => $item['source_url'],
        ];

        return [
            'parent_id' => 0,
            'post_type' => 1,
            'post_format' => 1,
            'user_id' => $this->authorId,
            'post_status' => 1,
            'comment_status' => 0,
            'is_top' => 0,
            'recommended' => 0,
            'published_time' => date('Y-m-d H:i:s', $published),
            'delete_time' => 0,
            'post_title' => $this->truncate((string)$item['title'], 100),
            'post_keywords' => $this->truncate((string)($item['keywords'] ?? ''), 150),
            'post_excerpt' => $this->truncate((string)($item['excerpt'] ?? ''), 500),
            'post_source' => $this->truncate((string)($item['post_source'] ?? $item['source_url']), 150),
            'thumbnail' => '',
            'post_content' => (string)($item['content'] ?? ''),
            'post_content_filtered' => '',
            'more' => $more,
        ];
    }

    private function recordData(array $item, int $postId, string $sourceHash, string $portalHash): array
    {
        return [
            'portal_post_id' => $postId,
            'source_url' => $this->truncate((string)$item['source_url'], 1000),
            'source_title' => $this->truncate((string)$item['title'], 255),
            'content_hash' => $sourceHash,
            'portal_hash' => $portalHash,
            'sync_status' => 'synced',
            'last_error' => null,
            'source_published_time' => (int)$item['published_time'],
            'synced_at' => time(),
        ];
    }

    private function attachCategory(int $postId, int $categoryId): void
    {
        $exists = Db::name('portal_category_post')
            ->where('post_id', $postId)
            ->where('category_id', $categoryId)
            ->find();
        if ($exists) {
            Db::name('portal_category_post')->where('id', $exists['id'])->update(['status' => 1]);
            return;
        }

        Db::name('portal_category_post')->insert([
            'post_id' => $postId,
            'category_id' => $categoryId,
            'list_order' => 10000,
            'status' => 1,
        ]);
        Db::name('portal_category')->where('id', $categoryId)->inc('post_count')->update();
    }

    private function portalHash(PortalPostModel $post): string
    {
        $more = $post->more;
        if (!is_array($more)) {
            $more = [];
        }
        $data = [
            'title' => (string)$post->post_title,
            'keywords' => (string)$post->post_keywords,
            'excerpt' => (string)$post->post_excerpt,
            'source' => (string)$post->post_source,
            'content' => (string)$post->post_content,
            'published_time' => (int)$post->published_time,
            'external_url' => (string)($more['external_url'] ?? ''),
            'thumbnail' => (string)($more['thumbnail'] ?? ''),
        ];
        return hash('sha256', json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function sourceHash(array $item): string
    {
        unset($item['category_id']);
        ksort($item);
        return hash('sha256', json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function validate(array $item): void
    {
        foreach (['source', 'external_id', 'source_url', 'title', 'published_time', 'category_id'] as $key) {
            if (!isset($item[$key]) || $item[$key] === '') {
                throw new RuntimeException('External item is missing required field: ' . $key);
            }
        }

        $scheme = strtolower((string)parse_url((string)$item['source_url'], PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('External item source URL is not HTTP or HTTPS.');
        }
        if ((int)$item['published_time'] <= 0) {
            throw new RuntimeException('External item publication time is invalid.');
        }
        if ((int)$item['category_id'] <= 0) {
            throw new RuntimeException('External item category is invalid.');
        }
    }

    private function truncate(string $value, int $length): string
    {
        return mb_strlen($value, 'UTF-8') > $length
            ? mb_substr($value, 0, $length, 'UTF-8')
            : $value;
    }
}
