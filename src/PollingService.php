<?php
declare(strict_types=1);

/**
 * PollingService
 *
 * Shared live-data gathering used by BOTH the web page (index.php) and the
 * background poller (cron.php). Keeping one implementation guarantees the
 * page and the alerts always see the same data pipeline:
 *
 *   per-printer cache (fresh?) -> SNMP probe -> persist last-known levels
 *
 * The cache stores ONLY volatile network data (status + levels); everything
 * static always comes straight from the database.
 */
class PollingService {
    /**
     * Gather live data for all printers.
     *
     * @param array $dbPrinters Raw rows from Database::getPrinters()
     * @param PrinterDiscovery $discovery
     * @param Database $db
     * @param CacheManager $cache
     * @param bool $cacheEnabled
     * @param float $budgetSeconds Soft cap for SNMP polling in this run
     *        (web: ~20s; cron: pass a generous value)
     * @return array{printers: array, lastUpdated: ?int}
     *         printers keyed by IP: ['info' => ..., 'levels' => [], 'status' => 'online'|'offline']
     */
    public static function gather(
        array $dbPrinters,
        PrinterDiscovery $discovery,
        Database $db,
        CacheManager $cache,
        bool $cacheEnabled,
        float $budgetSeconds
    ): array {
        $budgetDeadline = microtime(true) + $budgetSeconds;
        $printers = [];
        $lastUpdated = null;

        foreach ($dbPrinters as $row) {
            $ip = $row['ip'];
            $live = $cacheEnabled ? $cache->get('live_' . $ip) : null;

            if (!is_array($live) || !isset($live['status'])) {
                // Stale or missing cache entry.
                // Time budget exhausted -> show DB data without a live probe.
                $live = microtime(true) < $budgetDeadline
                    ? self::pollPrinter($row, $discovery, $db, $cache, $cacheEnabled)
                    : ['status' => 'offline', 'levels' => [], 'fetched_at' => null, 'stale' => true];
            }

            $fetchedAt = $live['fetched_at'] ?? null;
            if (is_int($fetchedAt)) {
                $lastUpdated = $lastUpdated === null ? $fetchedAt : min($lastUpdated, $fetchedAt);
            }

            $name = (string)($row['name'] ?? '');
            $model = (string)($row['model'] ?? '');

            $printers[$ip] = [
                'info' => [
                    'ip' => $ip,
                    'id' => (int)$row['id'],
                    'floor_id' => isset($row['floor_id']) ? (int)$row['floor_id'] : null,
                    'full_name' => $name !== '' ? $name : ($model !== '' ? $model : 'Unknown Printer'),
                    'description' => $row['description'] ?? null,
                    'purchase_url' => $row['purchase_url'] ?? null,
                    'serial_number' => $row['serial'] ?? null,
                    'model' => $model !== '' ? $model : null,
                    'snmp' => Database::snmpAuthView($row['snmp_auth'] ?? null),
                    'last_levels' => $row['last_levels'] ?? null
                ],
                'levels' => is_array($live['levels'] ?? null) ? $live['levels'] : [],
                'status' => $live['status'] ?? 'offline'
            ];
        }

        return ['printers' => $printers, 'lastUpdated' => $lastUpdated];
    }

    /**
     * Poll one printer and store the result.
     *
     * @return array ['status', 'levels', 'fetched_at']
     */
    public static function pollPrinter(
        array $row,
        PrinterDiscovery $discovery,
        Database $db,
        CacheManager $cache,
        bool $cacheEnabled
    ): array {
        // Per-printer SNMP access (v1/v2c community or v3 credentials)
        $discovery->configureSnmp($row['snmp_auth'] ?? null);
        $live = $discovery->getLiveStatus($row['ip']);
        $live['fetched_at'] = time();

        if ($cacheEnabled) {
            $cache->set('live_' . $row['ip'], $live);
        }

        // Persist "last known levels" for the offline display - but only
        // when they actually changed (no UPDATE spam).
        if (($live['status'] ?? '') === 'online' && !empty($live['levels'])) {
            $newJson = Database::normalizeLevelsJson($live['levels']);
            $oldJson = Database::normalizeLevelsJson(
                isset($row['last_levels']) && is_string($row['last_levels'])
                    ? json_decode($row['last_levels'], true)
                    : null
            );
            if ($newJson !== null && $newJson !== $oldJson) {
                $db->updatePrinterLevels((int)$row['id'], $live['levels']);
            }
        }

        return $live;
    }
}
