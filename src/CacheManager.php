<?php
declare(strict_types=1);

/**
 * CacheManager Class
 * 
 * This class handles saving data to temporary files (caching).
 * We use this to avoid asking the printer for its status every single second.
 * Instead, we ask once, save it here for a configured TTL, and show the saved copy.
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
        
        // Create the cache folder if it doesn't exist
        if (!file_exists($this->cacheDir)) {
            // Use @ to hide warnings if we don't have permission
            @mkdir($this->cacheDir, 0777, true);
        }
    }
    
    /**
     * Get Cached Data
     * 
     * Tries to read data from a cache file.
     * Returns NULL if the file is missing or too old.
     * 
     * @param string $key The unique name for this data (e.g., printer IP)
     * @return mixed|null The data, or null if not found
     */
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
        if ($contents === false) {
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
     * Saves data to a file.
     * 
     * @param string $key The unique name
     * @param mixed $data The data to save
     * @return bool True if it worked
     */
    public function set(string $key, $data): bool {
        $cacheFile = $this->getCacheFilePath($key);
        // Convert array to JSON string
        $jsonData = json_encode($data, JSON_PRETTY_PRINT);
        
        if ($jsonData === false) {
            return false;
        }
        
        // Write to file
        $result = @file_put_contents($cacheFile, $jsonData);
        return $result !== false;
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