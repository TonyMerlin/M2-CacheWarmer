<?php
declare(strict_types=1);
namespace Merlin\CacheWarmer\Service;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;

class EnqueueService
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly DateTime $dateTime
    ) {}

    public function addUrls(array $urls, int $storeId = 0, int $priority = 0, ?int $notBeforeTs = null, bool $dedupe = true): int
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('merlin_cachewarmer_queue');
        $now = $this->dateTime->gmtTimestamp();
        $inserted = 0;

        foreach ($urls as $url) {
            $url = trim($url);
            if ($url === '') continue;

            if ($dedupe) {
                $select = $connection->select()->from($table, ['queue_id'])
                    ->where('url = ?', $url)
                    ->where('status IN (?)', ['pending','processing']);
                if ($connection->fetchOne($select)) {
                    continue;
                }
            }

            $data = [
                'url' => $url,
                'store_id' => $storeId,
                'priority' => $priority,
                'status' => 'pending',
                'attempts' => 0,
                'not_before' => $notBeforeTs ? gmdate('Y-m-d H:i:s', $notBeforeTs) : null,
                'created_at' => gmdate('Y-m-d H:i:s', $now),
                'updated_at' => gmdate('Y-m-d H:i:s', $now),
            ];
            $connection->insert($table, $data);
            $inserted++;
        }

        return $inserted;
    }

    public function clearPending(): int
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('merlin_cachewarmer_queue');
        return $connection->delete($table, ['status IN (?)' => ['pending','processing']]);
    }
}
