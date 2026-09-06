<?php
declare(strict_types=1);

/**
 * CacheManager Class
 *
 * This class handles saving data to temporary files (caching).
 * We use this to avoid asking the printer for its status every single second.
 * Instead, we ask once, save it here for 5 minutes, and show the saved copy.
 *
 * Notes:
 * - Writes are atomic (write to temp file + rename) so concurrent page loads
 *   can never read a half-written cache file.
 * - The cache folder lives inside the web root (simple volume setups), so we
 *   drop an .htaccess file in it that denies all web access. Cached data may
 *   contain serial numbers and IPs - it is NOT public content.
 */
class CacheManager {
    private string $cacheDir; // Where to save the files (e.g., 'cache/')
    private int $cacheTtl;    // How long to keep them (Time To Live) in seconds

    /**
     * Constructor
     *
     * Sets up the cache folder.
     */
    public function __construct(string $cacheDir = 'cache', int $cacheTtl = 300) {
        $this->cacheDir = rtrim($cacheDir, '/');
        $this->cacheTtl = $cacheTtl;

        // Bind-mounted docroots (docker-compose: ./src:/var/www/html) are often
        // read-only for www-data. Without a working cache dir the app would
        // re-poll every printer on EVERY page load, so fall back to the system
        // temp dir instead of failing silently.
        if (!$this->ensureCacheDir($this->cacheDir)) {
            $tmp = sys_get_temp_dir() . '/sprinter-cache';
            if ($this->ensureCacheDir($tmp)) {
                error_log("CacheManager: '{$this->cacheDir}' not writable, using '{$tmp}' instead");
                $this->cacheDir = $tmp;
            } else {
                error_log('CacheManager: no writable cache directory, caching disabled');
            }
        }
    }

    /**
     * Create a cache folder and protect it from web access.
     *
     * @return bool True when the folder exists and is writable
     */
    private function ensureCacheDir(string $dir): bool {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
        if (!is_writable($dir)) {
            return false;
        }

        // Deny direct web access to cached data (Apache 2.4).
        // Cheap to write on every boot; overwrites any tampered copy.
        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "Require all denied\n");
        }

        // Belt and suspenders: empty index so directory listing is blank
        // even if access rules change.
        $index = $dir . '/index.html';
        if (!file_exists($index)) {
            @file_put_contents($index, '');
        }

        return true;
    }

    /**
     * Get Cached Data
     *
     * Tries to read data from a cache file.
     * Returns NULL if the file is missing or too old.
     *
     * Note: callers must compare with  === null , not empty()/falsy checks:
     * a legitimate cached value can be an empty array.
     *
     * @param string $key The unique name for this data (e.g., printer IP)
     * @return mixed|null The data, or null if not found
     */
    /**
     * Adjust the TTL after construction (page + poller agree on a fleet-size
     * dependent TTL so entries never expire mid-sweep).
     */
    public function setTtl(int $seconds): void {
        $this->cacheTtl = max(1, $seconds);
    }

    public function get(string $key) {
        $cacheFile = $this->getCacheFilePath($key);

        // If file doesn't exist, we have no cache
        if (!file_exists($cacheFile)) {
            return null;
        }

        // Check if it's too old
        $fileTime = @filemtime($cacheFile);
        if ($fileTime === false || time() - $fileTime > $this->cacheTtl) {
            return null; // It's expired
        }

        // Read the file
        $contents = @file_get_contents($cacheFile);
        if ($contents === false || $contents === '') {
            return null;
        }

        // Decode the JSON back into an array
        $data = json_decode($contents, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null; // File was corrupted
        }

        return $data;
    }

    /**
     * Set Cached Data
     *
     * Saves data to a file using an atomic write, so a concurrent reader
     * never sees a half-written file.
     *
     * @param string $key The unique name
     * @param mixed $data The data to save
     * @return bool True if it worked
     */
    public function set(string $key, $data): bool {
        $cacheFile = $this->getCacheFilePath($key);

        // Compact JSON: PRETTY_PRINT doubled the file size for zero benefit
        $jsonData = json_encode($data);
        if ($jsonData === false) {
            return false;
        }

        // Write to a unique temp file, then rename (atomic on POSIX)
        $tmpFile = $cacheFile . '.' . (getmypid() ?: 'x') . '.tmp';
        if (@file_put_contents($tmpFile, $jsonData, LOCK_EX) === false) {
            return false;
        }

        if (!@rename($tmpFile, $cacheFile)) {
            @unlink($tmpFile);
            return false;
        }

        return true;
    }

    /**
     * Delete Cached Item
     *
     * Removes a specific cache file.
     */
    public function delete(string $key): bool {
        $cacheFile = $this->getCacheFilePath($key);

        if (file_exists($cacheFile)) {
            return @unlink($cacheFile);
        }

        return true; // It's already gone
    }

    /**
     * Clear All Cache
     *
     * Deletes ALL cache files. Used when you want to force a refresh.
     */
    public function clear(): bool {
        $files = @glob($this->cacheDir . '/*.json');

        if ($files === false) {
            return false;
        }

        $success = true;
        foreach ($files as $file) {
            if (!@unlink($file)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Get Cache File Path
     *
     * Turns a key (like "192.168.1.50") into a safe filename.
     */
    private function getCacheFilePath(string $key): string {
        // md5 creates a safe, random-looking string from the key
        return $this->cacheDir . '/' . md5($key) . '.json';
    }
}
