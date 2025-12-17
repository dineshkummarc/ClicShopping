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

namespace ClicShopping\AI\Security;

use ClicShopping\AI\Infrastructure\Cache\Cache;

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
    
    // Performance optimization: Cache log level numeric values
    private static $levelCache = [
        'debug' => 0,
        'info' => 1,
        'warning' => 2,
        'error' => 3
    ];
    
    private $minimumLevelNumeric;
    
    // Performance optimization: Buffer log entries for batch writing
    private $logBuffer = [];
    private $bufferSize = 10; // Write after 10 entries
    private $bufferEnabled = false;

    /**
     * Constructor for SecurityLogger
     * Initializes logging parameters and ensures log directory exists
     *
     * @param string $logLevel Minimum log level to record (debug, info, warning, error)
     * @param int $maxLogSize Maximum log file size in bytes before rotation
     * @param int $logRotations Number of log rotations to maintain
     * @param bool $bufferEnabled Enable log buffering for better performance
     */
    public function __construct(string $logLevel = 'info', int $maxLogSize = 10485760, int $logRotations = 5, bool $bufferEnabled = false)
    {
        $this->logFile = Cache::getLogFilePath();
        $this->maxLogSize = $maxLogSize;
        $this->logRotations = $logRotations;
        $this->logLevel = $logLevel;
        $this->bufferEnabled = $bufferEnabled;
        
        // Cache numeric level for performance
        $this->minimumLevelNumeric = self::$levelCache[$logLevel] ?? self::$levelCache['info'];
        
        // Register shutdown function to flush buffer
        if ($bufferEnabled) {
            register_shutdown_function([$this, 'flushBuffer']);
        }
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
        // Performance optimization: Fast level check using cached numeric values
        $levelNumeric = self::$levelCache[$level] ?? self::$levelCache['info'];
        
        if ($levelNumeric < $this->minimumLevelNumeric) {
            return false;
        }
        
        // Format log entry
        $logEntry = $this->formatLogEntry($level, $message, $context);
        
        // Use buffering if enabled
        if ($this->bufferEnabled) {
            return $this->addToBuffer($logEntry);
        }
        
        // Direct write if buffering disabled
        return $this->writeLogEntry($logEntry);
    }
    
    /**
     * Format log entry (extracted for reusability)
     *
     * @param string $level Log level
     * @param string $message Log message
     * @param array $context Context data
     * @return string Formatted log entry
     */
    private function formatLogEntry(string $level, string $message, array $context = []): string
    {
        // Performance optimization: Use static timestamp format
        static $timestampFormat = 'Y-m-d H:i:s';
        $timestamp = date($timestampFormat);
        
        // Performance optimization: Only encode context if not empty
        $contextStr = empty($context) ? '' : (' ' . json_encode($context));
        
        return "[{$timestamp}] [{$level}] {$message}{$contextStr}" . PHP_EOL;
    }
    
    /**
     * Write log entry to file
     *
     * @param string $logEntry Formatted log entry
     * @return bool True if written successfully
     */
    private function writeLogEntry(string $logEntry): bool
    {
        // Check if log rotation is needed (only when actually writing)
        $this->rotateLogIfNeeded();
        
        // Write to log file
        return (bool)file_put_contents(
            $this->logFile,
            $logEntry,
            FILE_APPEND | LOCK_EX
        );
    }
    
    /**
     * Add log entry to buffer
     *
     * @param string $logEntry Formatted log entry
     * @return bool True if added successfully
     */
    private function addToBuffer(string $logEntry): bool
    {
        $this->logBuffer[] = $logEntry;
        
        // Flush buffer if size limit reached
        if (count($this->logBuffer) >= $this->bufferSize) {
            return $this->flushBuffer();
        }
        
        return true;
    }
    
    /**
     * Flush log buffer to file
     *
     * @return bool True if flushed successfully
     */
    public function flushBuffer(): bool
    {
        if (empty($this->logBuffer)) {
            return true;
        }
        
        // Check if log rotation is needed
        $this->rotateLogIfNeeded();
        
        // Write all buffered entries at once
        $success = (bool)file_put_contents(
            $this->logFile,
            implode('', $this->logBuffer),
            FILE_APPEND | LOCK_EX
        );
        
        // Clear buffer
        $this->logBuffer = [];
        
        return $success;
    }

    /**
     * Logs a structured event in JSON format
     * Provides consistent logging format across all components
     * 
     * @param string $level Log level (info, warning, error)
     * @param string $component Component name (e.g., 'Semantics', 'OrchestratorAgent')
     * @param string $operation Operation being performed (e.g., 'classification', 'validation')
     * @param array $data Additional data to log
     * @return bool True if log entry was written successfully
     */
    public function logStructured(string $level, string $component, string $operation, array $data = []): bool
    {
        // Performance optimization: Fast level check using cached numeric values
        $levelNumeric = self::$levelCache[$level] ?? self::$levelCache['info'];
        if ($levelNumeric < $this->minimumLevelNumeric) {
            return false;
        }
        
        // Create structured log entry
        $logEntry = [
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'), // UTC timestamp in ISO 8601 format
            'level' => $level,
            'component' => $component,
            'operation' => $operation,
            'data' => $data
        ];
        
        // Format as JSON with newline
        $jsonEntry = json_encode($logEntry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        
        // Use buffering if enabled
        if ($this->bufferEnabled) {
            return $this->addToBuffer($jsonEntry);
        }
        
        // Direct write if buffering disabled
        return $this->writeLogEntry($jsonEntry);
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
        
        // Performance optimization: Calculate minimum level once
        $minimumLevelNumeric = self::$levelCache[$level] ?? self::$levelCache['info'];
        
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
                        
                        // Performance optimization: Direct numeric comparison instead of shouldLog()
                        $entryLevelNumeric = self::$levelCache[$entryLevel] ?? self::$levelCache['info'];
                        if ($entryLevelNumeric < $minimumLevelNumeric) {
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
