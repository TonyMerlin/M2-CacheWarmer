<?php
declare(strict_types=1);
namespace Merlin\CacheWarmer\Service;

use Magento\Framework\App\ResourceConnection;

class WarmService
{
    public function __construct(
        private readonly ResourceConnection $resource
    ) {}

    public function process(int $limit = 0, int $sleepMs = 0, int $timeout = 15, array $headers = [], string $userAgent = 'MerlinCacheWarmer/1.0', bool $ignoreDelay = false): array
    {
        return $this->processBatch(max(1, $limit), $sleepMs, $timeout, $headers, $userAgent, 1, $ignoreDelay);
    }

    /**
     * Concurrent batch processor using curl_multi.
     * @return array{processed:int,success:int,failed:int}
     */
    public function processBatch(int $limit, int $sleepMs, int $timeout, array $headers, string $userAgent, int $concurrency, bool $ignoreDelay = false): array
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
        $headerLines = [];
        foreach ($headers as $k => $v) {
            $headerLines[] = $k . ': ' . $v;
        }

        while (true) {
            $take = $limit > 0 ? min($concurrency, $limit - $processed) : $concurrency;
            if ($take <= 0) break;

            $now = gmdate('Y-m-d H:i:s');
            $select = $connection->select()
                ->from($table, ['queue_id','url','attempts'])
                ->where('status = ?', 'pending');
            if (!$ignoreDelay) {
                $select->where('not_before IS NULL OR not_before <= ?', $now);
            }
            $select->order('priority DESC')->order('queue_id ASC')->limit($take)->forUpdate(true);

            $rows = $connection->fetchAll($select);
            if (!$rows) break;

            $claimed = [];
            foreach ($rows as $row) {
                $updated = $connection->update(
                    $table,
                    ['status' => 'processing', 'lock_uuid' => $uuid],
                    ['queue_id = ?' => (int)$row['queue_id'], 'status = ?' => 'pending']
                );
                if ($updated) {
                    $claimed[] = $row;
                }
            }
            if (!$claimed) break;

            $mh = curl_multi_init();
            $handles = [];
            foreach ($claimed as $row) {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $row['url']);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
                curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
                curl_setopt($ch, CURLOPT_ENCODING, '');
                curl_setopt($ch, CURLOPT_NOBODY, false);
                curl_multi_add_handle($mh, $ch);
                $handles[] = ['handle' => $ch, 'row' => $row, 'start' => microtime(true)];
            }

            do {
                $status = curl_multi_exec($mh, $running);
                if ($running) curl_multi_select($mh, 1.0);
            } while ($running && $status == CURLM_OK);

            foreach ($handles as $info) {
                $h = $info['handle'];
                $row = $info['row'];
                $duration = (microtime(true) - $info['start']) * 1000.0;
                $code = curl_getinfo($h, CURLINFO_HTTP_CODE);
                $err = curl_error($h);
                $ok = ($code >= 200 && $code < 400);

                $connection->update($table, [
                    'status' => $ok ? 'done' : 'failed',
                    'response_code' => $code ?: null,
                    'duration_ms' => $duration,
                    'attempts' => ((int)$row['attempts']) + 1,
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                    'last_error' => $ok ? null : (strlen($err) ? substr($err, 0, 1024) : ('HTTP ' . (int)$code))
                ], ['queue_id = ?' => (int)$row['queue_id']]);

                curl_multi_remove_handle($mh, $h);
                curl_close($h);
                $processed++; $ok ? $success++ : $failed++;
            }
            curl_multi_close($mh);

            if ($sleepMs > 0):
                usleep($sleepMs * 1000);
            endif;

            if ($limit > 0 && $processed >= $limit) break;
        }

        return compact('processed','success','failed');
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
