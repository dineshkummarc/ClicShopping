<?php
/**
 * Security Logger Class
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Configuration\ChatGpt\Classes\Rag\Security;

use ClicShopping\Apps\Configuration\ChatGpt\Classes\Rag\Cache;

/**
 * Class SecurityLogger
 * Provides comprehensive security event logging
 * Supports multiple log levels and formats
 */
class SecurityLogger
{

    private $logFile;
    private $maxLogSize;
    private $logRotations;
    private $logLevel;

    /**
     * Constructor for SecurityLogger
     * Initializes logging parameters and ensures log directory exists
     *
     * @param string $logLevel Minimum log level to record (debug, info, warning, error)
     * @param int $maxLogSize Maximum log file size in bytes before rotation
     * @param int $logRotations Number of log rotations to maintain
     */
    public function __construct(string $logLevel = 'info', int $maxLogSize = 10485760, int $logRotations = 5)
    {
        $this->logFile = Cache::getLogFilePath();
        $this->maxLogSize = $maxLogSize;
        $this->logRotations = $logRotations;
        $this->logLevel = $logLevel;
    }

    /**
     * Logs a security event with specified level
     * Formats log entry with timestamp, level, and message
     *
     * @param string $message Security event message
     * @param string $level Log level (debug, info, warning, error)
     * @param array $context Additional context data for the log entry
     * @return bool True if log entry was written successfully
     */
    public function logSecurityEvent(string $message, string $level = 'info', array $context = []): bool
    {
        // Check if this level should be logged
        if (!$this->shouldLog($level)) {
            return false;
        }
        
        // Check if log rotation is needed
        $this->rotateLogIfNeeded();
        
        // Format timestamp
        $timestamp = date('Y-m-d H:i:s');
        
        // Format context data if provided
        $contextStr = '';
        if (!empty($context)) {
            $contextStr = ' ' . json_encode($context);
        }
        
        // Format log entry
        $logEntry = "[{$timestamp}] [{$level}] {$message}{$contextStr}" . PHP_EOL;
        
        // Write to log file
        return (bool)file_put_contents(
            $this->logFile,
            $logEntry,
            FILE_APPEND | LOCK_EX
        );
    }

    /**
     * Determines if a log entry with the given level should be recorded
     * Based on configured minimum log level
     *
     * @param string $level Log level to check
     * @return bool True if the level should be logged
     */
    private function shouldLog(string $level): bool
    {
        $levels = [
            'debug' => 0,
            'info' => 1,
            'warning' => 2,
            'error' => 3
        ];
        
        // Default to info level if unknown level provided
        $currentLevel = $levels[$level] ?? $levels['info'];
        $minimumLevel = $levels[$this->logLevel] ?? $levels['info'];
        
        return $currentLevel >= $minimumLevel;
    }

    /**
     * Rotates log file if it exceeds maximum size
     * Maintains specified number of log rotations
     *
     * @return void
     */
    private function rotateLogIfNeeded(): void
    {
        // Check if log file exists and exceeds size limit
        if (file_exists($this->logFile) && filesize($this->logFile) > $this->maxLogSize) {
            // Remove oldest log if rotation limit reached
            if (file_exists($this->logFile . '.' . $this->logRotations)) {
                unlink($this->logFile . '.' . $this->logRotations);
            }
            
            // Shift existing logs
            for ($i = $this->logRotations - 1; $i >= 1; $i--) {
                if (file_exists($this->logFile . '.' . $i)) {
                    rename(
                        $this->logFile . '.' . $i,
                        $this->logFile . '.' . ($i + 1)
                    );
                }
            }
            
            // Rename current log
            rename($this->logFile, $this->logFile . '.1');
        }
    }

    /**
     * Gets all security logs with optional filtering
     * Retrieves log entries from all rotated files
     *
     * @param string $level Minimum log level to include
     * @param int $limit Maximum number of entries to return
     * @param int $offset Starting offset for pagination
     * @return array Array of log entries
     */
    public function getSecurityLogs(string $level = 'info', int $limit = 100, int $offset = 0): array
    {
        $logs = [];
        $count = 0;
        $skip = $offset;
        
        // Process all log files in reverse order (newest first)
        for ($i = $this->logRotations; $i >= 0; $i--) {
            $currentFile = $i === 0 ? $this->logFile : $this->logFile . '.' . $i;
            
            if (!file_exists($currentFile)) {
                continue;
            }
            
            $fileHandle = fopen($currentFile, 'r');
            if ($fileHandle) {
                while (($line = fgets($fileHandle)) !== false) {
                    // Parse log level from line
                    if (preg_match('/\[(debug|info|warning|error)\]/', $line, $matches)) {
                        $entryLevel = $matches[1];
                        
                        // Skip if below minimum level
                        if (!$this->shouldLog($entryLevel)) {
                            continue;
                        }
                        
                        // Skip entries for pagination
                        if ($skip > 0) {
                            $skip--;
                            continue;
                        }
                        
                        // Add to results
                        $logs[] = $line;
                        $count++;
                        
                        // Stop if limit reached
                        if ($count >= $limit) {
                            break 2;
                        }
                    }
                }
                fclose($fileHandle);
            }
        }
        
        return $logs;
    }

    /**
     * Clears all security logs
     * Removes all log files including rotations
     *
     * @return bool True if logs were successfully cleared
     */
    public function clearLogs(): bool
    {
        $success = true;
        
        // Remove main log file
        if (file_exists($this->logFile)) {
            $success = $success && unlink($this->logFile);
        }
        
        // Remove rotated logs
        for ($i = 1; $i <= $this->logRotations; $i++) {
            if (file_exists($this->logFile . '.' . $i)) {
                $success = $success && unlink($this->logFile . '.' . $i);
            }
        }
        
        return $success;
    }
}
