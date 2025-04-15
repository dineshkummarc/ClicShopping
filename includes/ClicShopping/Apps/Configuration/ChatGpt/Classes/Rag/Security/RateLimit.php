<?php
/**
 * Rate Limiting Class
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Configuration\ChatGpt\Classes\Rag\Security;

use ClicShopping\OM\CLICSHOPPING;

/**
 * Class RateLimit
 * Implements rate limiting to prevent abuse of system resources
 * Tracks and limits request frequency by user or IP address
 */
class RateLimit
{
    private $namespace;
    private $maxRequests;
    private $timeWindow;
    private $storage;
    private $storageFile;

    /**
     * Constructor for RateLimit
     * Initializes rate limiting parameters and storage
     *
     * @param string $namespace Unique identifier for the rate limit category
     * @param int $maxRequests Maximum number of requests allowed in time window
     * @param int $timeWindow Time window in seconds
     */
    public function __construct(string $namespace, int $maxRequests, int $timeWindow)
    {
        $this->namespace = $namespace;
        $this->maxRequests = $maxRequests;
        $this->timeWindow = $timeWindow;
        $this->storageFile = CLICSHOPPING::BASE_DIR . 'Work/Cache/rate_limits.cache';
        
        // Initialize storage
        $this->loadStorage();
    }

    /**
     * Checks if a request is within rate limits
     * Tracks request count and timestamps
     *
     * @param string $identifier User ID or IP address
     * @return bool True if request is allowed, false if rate limit exceeded
     */
    public function checkLimit(string $identifier): bool
    {
        $key = $this->namespace . ':' . $identifier;
        $now = time();
        
        // Clean up expired entries
        $this->cleanupStorage($now);
        
        // Initialize if not exists
        if (!isset($this->storage[$key])) {
            $this->storage[$key] = [
                'count' => 0,
                'timestamps' => [],
                'first_request' => $now
            ];
        }
        
        // Remove timestamps outside the window
        $this->storage[$key]['timestamps'] = array_filter(
            $this->storage[$key]['timestamps'],
            function ($timestamp) use ($now) {
                return $timestamp >= ($now - $this->timeWindow);
            }
        );
        
        // Count requests in the current window
        $count = count($this->storage[$key]['timestamps']);
        
        // Check if limit exceeded
        if ($count >= $this->maxRequests) {
            // Log rate limit exceeded
            $this->logRateLimitExceeded($identifier, $count);
            return false;
        }
        
        // Record this request
        $this->storage[$key]['timestamps'][] = $now;
        $this->storage[$key]['count']++;
        
        // Save updated storage
        $this->saveStorage();
        
        return true;
    }

    /**
     * Resets rate limit for a specific identifier
     * Useful for administrative overrides
     *
     * @param string $identifier User ID or IP address to reset
     * @return void
     */
    public function resetLimit(string $identifier): void
    {
        $key = $this->namespace . ':' . $identifier;
        
        if (isset($this->storage[$key])) {
            unset($this->storage[$key]);
            $this->saveStorage();
        }
    }

    /**
     * Gets current rate limit status for an identifier
     * Returns request count and remaining limit
     *
     * @param string $identifier User ID or IP address
     * @return array Rate limit status information
     */
    public function getStatus(string $identifier): array
    {
        $key = $this->namespace . ':' . $identifier;
        $now = time();
        
        if (!isset($this->storage[$key])) {
            return [
                'requests' => 0,
                'remaining' => $this->maxRequests,
                'reset_time' => $now + $this->timeWindow
            ];
        }
        
        // Count valid timestamps
        $validTimestamps = array_filter(
            $this->storage[$key]['timestamps'],
            function ($timestamp) use ($now) {
                return $timestamp >= ($now - $this->timeWindow);
            }
        );
        
        $count = count($validTimestamps);
        
        // Find reset time (when oldest request expires)
        $resetTime = $now + $this->timeWindow;
        if (!empty($validTimestamps)) {
            $oldestTimestamp = min($validTimestamps);
            $resetTime = $oldestTimestamp + $this->timeWindow;
        }
        
        return [
            'requests' => $count,
            'remaining' => max(0, $this->maxRequests - $count),
            'reset_time' => $resetTime
        ];
    }

    /**
     * Loads rate limit data from storage
     * Initializes storage if file doesn't exist
     *
     * @return void
     */
    private function loadStorage(): void
    {
        if (file_exists($this->storageFile)) {
            $content = file_get_contents($this->storageFile);
            $this->storage = json_decode($content, true) ?: [];
        } else {
            $this->storage = [];
        }
    }

    /**
     * Saves rate limit data to storage
     * Creates storage directory if it doesn't exist
     *
     * @return void
     */
    private function saveStorage(): void
    {
        $dir = dirname($this->storageFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        file_put_contents(
            $this->storageFile,
            json_encode($this->storage),
            LOCK_EX
        );
    }

    /**
     * Cleans up expired entries from storage
     * Removes entries older than twice the time window
     *
     * @param int $now Current timestamp
     * @return void
     */
    private function cleanupStorage(int $now): void
    {
        foreach ($this->storage as $key => $data) {
            // Remove entries older than twice the time window
            if (isset($data['first_request']) && $data['first_request'] < ($now - ($this->timeWindow * 2))) {
                unset($this->storage[$key]);
            }
        }
    }

    /**
     * Logs rate limit exceeded events
     * Creates standardized log entries for security monitoring
     *
     * @param string $identifier User ID or IP that exceeded the limit
     * @param int $count Current request count
     * @return void
     */
    private function logRateLimitExceeded(string $identifier, int $count): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[{$timestamp}] [warning] Rate limit exceeded: {$identifier} made {$count} requests in {$this->namespace} (limit: {$this->maxRequests}/{$this->timeWindow}s)" . PHP_EOL;
        
        // Ensure log directory exists
        $logDir = CLICSHOPPING::BASE_DIR . 'Work/Logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        // Write to security log file
        file_put_contents(
            $logDir . '/rate_limit.log',
            $logEntry,
            FILE_APPEND
        );
    }
}
