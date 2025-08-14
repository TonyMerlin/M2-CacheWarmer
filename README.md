# Merlin_CacheWarmer (console-only)

v2.2 adds concurrency

Cache warming extension for Magento 2.4.5+ (PHP 8.1+) by **Merlin**.  
No admin UI everything works via CLI.

## Install
1. Copy the module to `app/code/Merlin/CacheWarmer`
2. Enable & deploy:
   ```bash
   bin/magento module:enable Merlin_CacheWarmer
   bin/magento setup:upgrade
   bin/magento setup:di:compile
   bin/magento cache:flush
   ```

## Commands

### Build queue
bash

bin/magento merlin:cache:queue:build [--sitemap <sitemap.xml|URL>] [--file <urls.txt>] [--add "https://a,https://b"]       [--store-id 0] [--priority 0] [--not-before 60] [--clear] [--allow-duplicates]

- `--sitemap`: path or URL to a sitemap (also supports sitemap index).
- `--file`: text file with one URL per line.
- `--add`: comma-separated list of URLs to enqueue.
- `--not-before`: delay processing by N seconds.
- `--clear`: purge existing `pending`/`processing` items before enqueue.
- By default, duplicates in `pending`/`processing` are skipped.

### Start processing

bin/magento merlin:cache:queue:start [--concurrency 10] [--limit 0] [--sleep 0] [--timeout 15] [--header "Key: Value" ...] [--user-agent "UA"]
Added - **Concurrency** via `--concurrency` (default 8), using curl_multi

- Makes HTTP GET requests to each URL with headers:
  - `User-Agent: MerlinCacheWarmer/1.0` (override with `--user-agent`)
  - `Accept-Encoding: gzip, deflate`
  - `X-Merlin-CacheWarmer: 1`
- Success = HTTP 2xx/3xx. Failures recorded with the reason.
- `--sleep` adds a delay (ms) between hits (nice for HAProxy/Varnish).

### Status / remaining
```bash
bin/magento merlin:cache:queue:status [--list 20]
```
Shows counts and the next N `pending` URLs by priority/age.

## Table
`merlin_cachewarmer_queue`:
- `queue_id`, `url`, `store_id`, `priority`, `status`, `attempts`, `lock_uuid`,
  `response_code`, `duration_ms`, `last_error`, `not_before`, `created_at`, `updated_at`

## Notes for Varnish/HAProxy
- This module simply requests URLs; Varnish should cache responses as per your VCL.
- Consider allowing `X-Merlin-CacheWarmer` through HAProxy/Nginx logs for visibility.
- To reduce backend thundering herd, use `--sleep` and/or HAProxy rate limits.

## Uninstall
Standard declarative schema table will be dropped on module removal if you implement an uninstall script (not included).
```
bin/magento module:disable Merlin_CacheWarmer
```
