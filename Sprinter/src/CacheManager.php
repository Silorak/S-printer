<?php
declare(strict_types=1);

class CacheManager {
    private string $cacheDir;
    private int $cacheTtl;
    
    /**
     * Constructor
     */
    public function __construct(string $cacheDir = 'cache', int $cacheTtl = 300) {
        $this->cacheDir = rtrim($cacheDir, '/');
        $this->cacheTtl = $cacheTtl;
        
        // Ensure cache directory exists with proper permissions
        if (!file_exists($this->cacheDir)) {
            // Use @ to suppress warnings if permissions don't allow
            @mkdir($this->cacheDir, 0777, true);
        }
    }
    
    /**
     * Get cached data
     *
     * @param string $key Cache key
     * @return mixed|null Cached data or null if not found/expired
     */
    public function get(string $key) {
        $cacheFile = $this->getCacheFilePath($key);
        
        if (!file_exists($cacheFile)) {
            return null;
        }
        
        // Use @ to suppress warnings if permissions don't allow
        $fileTime = @filemtime($cacheFile);
        if ($fileTime === false || time() - $fileTime > $this->cacheTtl) {
            return null; // Cache expired or can't read file time
        }
        
        // Use @ to suppress warnings if permissions don't allow
        $contents = @file_get_contents($cacheFile);
        if ($contents === false) {
            return null;
        }
        
        $data = json_decode($contents, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }
        
        return $data;
    }
    
    /**
     * Store data in cache
     *
     * @param string $key Cache key
     * @param mixed $data Data to cache
     * @return bool Whether operation succeeded
     */
    public function set(string $key, $data): bool {
        $cacheFile = $this->getCacheFilePath($key);
        $jsonData = json_encode($data, JSON_PRETTY_PRINT);
        
        if ($jsonData === false) {
            return false;
        }
        
        // Suppress warnings using @ if permissions don't allow
        $result = @file_put_contents($cacheFile, $jsonData);
        return $result !== false;
    }
    
    /**
     * Delete cached item
     *
     * @param string $key Cache key
     * @return bool Whether operation succeeded
     */
    public function delete(string $key): bool {
        $cacheFile = $this->getCacheFilePath($key);
        
        if (file_exists($cacheFile)) {
            // Suppress warnings if permissions don't allow
            return @unlink($cacheFile);
        }
        
        return true; // Already doesn't exist
    }
    
    /**
     * Clear all cache
     *
     * @return bool Whether operation succeeded
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
     * Get path to cache file
     *
     * @param string $key Cache key
     * @return string Path to cache file
     */
    private function getCacheFilePath(string $key): string {
        return $this->cacheDir . '/' . md5($key) . '.json';
    }
}