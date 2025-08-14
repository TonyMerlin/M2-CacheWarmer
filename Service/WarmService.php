<?php
declare(strict_types=1);
namespace Merlin\CacheWarmer\Service;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Stdlib\DateTime\DateTime;

class WarmService
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Curl $curl,
        private readonly DateTime $dateTime
    ) {}

    public function process(int $limit = 0, int $sleepMs = 0, int $timeout = 15, array $headers = [], string $userAgent = 'MerlinCacheWarmer/1.0'): array
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('merlin_cachewarmer_queue');
        $processed = $success = $failed = 0;
        $uuid = bin2hex(random_bytes(8));

        $headers = array_merge([
            'User-Agent' => $userAgent,
            'Accept-Encoding' => 'gzip, deflate',
            'X-Merlin-CacheWarmer' => '1'
        ], $headers);

        $this->curl->setTimeout($timeout);
        foreach ($headers as $k => $v) {
            $this->curl->addHeader((string)$k, (string)$v);
        }

        while (true) {
            $now = gmdate('Y-m-d H:i:s', $this->dateTime->gmtTimestamp());
            $select = $connection->select()
                ->from($table)
                ->where('status = ?', 'pending')
                ->where('not_before IS NULL OR not_before <= ?', $now)
                ->order('priority DESC')
                ->order('queue_id ASC')
                ->limit(1)
                ->forUpdate(true);

            $row = $connection->fetchRow($select);
            if (!$row) {
                break;
            }

            $connection->update(
                $table,
                ['status' => 'processing', 'lock_uuid' => $uuid],
                ['queue_id = ?' => (int)$row['queue_id'], 'status = ?' => 'pending']
            );

            $row2 = $connection->fetchRow(
                $connection->select()->from($table)
                    ->where('queue_id = ?', (int)$row['queue_id'])
                    ->where('lock_uuid = ?', $uuid)
            );
            if (!$row2) {
                continue;
            }

            $processed++;
            $start = microtime(true);
            try {
                $this->curl->get($row['url']);
                $code = $this->curl->getStatus();
                $duration = (microtime(true) - $start) * 1000.0;
                $ok = ($code >= 200 && $code < 400);

                $connection->update($table, [
                    'status' => $ok ? 'done' : 'failed',
                    'response_code' => $code,
                    'duration_ms' => $duration,
                    'attempts' => ((int)$row['attempts']) + 1,
                    'updated_at' => gmdate('Y-m-d H:i:s', $this->dateTime->gmtTimestamp()),
                    'last_error' => $ok ? null : 'HTTP ' . $code
                ], ['queue_id = ?' => (int)$row['queue_id']]);

                if ($ok) { $success++; } else { $failed++; }
            } catch (\Throwable $e) {
                $duration = (microtime(true) - $start) * 1000.0;
                $connection->update($table, [
                    'status' => 'failed',
                    'response_code' => null,
                    'duration_ms' => $duration,
                    'attempts' => ((int)$row['attempts']) + 1,
                    'updated_at' => gmdate('Y-m-d H:i:s', $this->dateTime->gmtTimestamp()),
                    'last_error' => substr($e->getMessage(), 0, 1024)
                ], ['queue_id = ?' => (int)$row['queue_id']]);
                $failed++;
            }

            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }

            if ($limit > 0 && $processed >= $limit) {
                break;
            }
        }

        return ['processed' => $processed, 'success' => $success, 'failed' => $failed];
    }

    public function getStatus(int $limitList = 20): array
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('merlin_cachewarmer_queue');

        $counts = [];
        foreach (['pending','processing','done','failed'] as $st) {
            $counts[$st] = (int)$connection->fetchOne(
                $connection->select()->from($table, ['cnt' => 'COUNT(*)'])->where('status = ?', $st)
            );
        }

        $remaining = $connection->fetchCol(
            $connection->select()
                ->from($table, ['url'])
                ->where('status = ?', 'pending')
                ->order('priority DESC')
                ->order('queue_id ASC')
                ->limit($limitList)
        );

        return ['counts' => $counts, 'remaining' => $remaining];
    }
}
