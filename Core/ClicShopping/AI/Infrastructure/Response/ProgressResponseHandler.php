<?php
/**
 * Progress Response Handler for Long-Running RAG Queries
 * 
 * Provides progressive feedback to users during long-running query execution.
 * Sends progress updates, processing messages, and completion notifications.
 * 
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 */

namespace ClicShopping\AI\Infrastructure\Response;

use ClicShopping\AI\Security\SecurityLogger;

/**
 * ProgressResponseHandler Class
 * 
 * Manages progressive response updates during long-running query execution.
 * 
 * Features:
 * - Progress update sending with percentage completion
 * - Initial processing message for user feedback
 * - Completion message with execution time
 * - Configurable update interval (default: 5 seconds)
 * - Estimated time remaining calculation
 * 
 * Update Strategy:
 * - Send initial processing message immediately
 * - Send progress updates every 5 seconds
 * - Send completion message when query finishes
 * - Include estimated time remaining when available
 * 
 * Usage:
 * ```php
 * $handler = new ProgressResponseHandler();
 * 
 * // Send initial processing message
 * $handler->sendProcessingMessage($query, $cacheState);
 * 
 * // During execution, send progress updates
 * if ($handler->shouldSendUpdate($elapsedTime)) {
 *   $handler->sendProgressUpdate("Processing query...", 50.0, 10);
 * }
 * 
 * // Send completion message
 * $handler->sendCompletionMessage($executionTime);
 * ```
 */
class ProgressResponseHandler
{
  private bool $debug = false;
  private ?SecurityLogger $logger = null;
  private int $updateInterval = 5; // seconds
  private float $lastUpdateTime = 0.0;

  /**
   * Constructor
   * 
   * @param bool $debug Enable debug logging
   * @param int $updateInterval Interval between progress updates in seconds (default: 5)
   */
  public function __construct(bool $debug = false, int $updateInterval = 5)
  {
    $this->debug = $debug;
    $this->updateInterval = $updateInterval;
    $this->lastUpdateTime = microtime(true);
    
    // Initialize logger
    try {
      $this->logger = new SecurityLogger('info');
    } catch (\Exception $e) {
      error_log("ProgressResponseHandler: Failed to initialize SecurityLogger: " . $e->getMessage());
    }
    
    if ($this->debug) {
      error_log("ProgressResponseHandler: Initialized with update interval={$updateInterval}s");
    }
  }

  /**
   * Send progress update to client
   * 
   * Sends a progress update with completion percentage and optional estimated time remaining.
   * Updates are sent via JSON response and logged for monitoring.
   * 
   * @param string $message Progress message (e.g., "Processing query...")
   * @param float $percentComplete Completion percentage (0-100)
   * @param int|null $estimatedSecondsRemaining Estimated time remaining in seconds
   * @return void
   */
  public function sendProgressUpdate(
    string $message,
    float $percentComplete,
    ?int $estimatedSecondsRemaining = null
  ): void {
    // Ensure percentage is within valid range
    $percentComplete = max(0.0, min(100.0, $percentComplete));
    
    // Build progress update structure
    $update = [
      'type' => 'progress',
      'message' => $message,
      'percent_complete' => round($percentComplete, 1),
      'timestamp' => time()
    ];
    
    // Add estimated time remaining if provided
    if ($estimatedSecondsRemaining !== null) {
      $update['estimated_seconds_remaining'] = $estimatedSecondsRemaining;
      $update['estimated_completion'] = date('H:i:s', time() + $estimatedSecondsRemaining);
    }
    
    // Send update to client (via JSON response)
    $this->sendToClient($update);
    
    // Update last update time
    $this->lastUpdateTime = microtime(true);
    
    // Log progress update
    if ($this->logger !== null) {
      try {
        $this->logger->logSecurityEvent(
          "Progress update sent: {$message} ({$percentComplete}%)",
          'info',
          $update
        );
      } catch (\Exception $e) {
        error_log("ProgressResponseHandler: Failed to log progress update: " . $e->getMessage());
      }
    }
    
    if ($this->debug) {
      $eta = $estimatedSecondsRemaining !== null ? " (ETA: {$estimatedSecondsRemaining}s)" : "";
      error_log("ProgressResponseHandler: Progress update sent: {$percentComplete}%{$eta}");
    }
  }

  /**
   * Send initial processing message
   * 
   * Sends an initial message to inform the user that query processing has started.
   * Includes cache state information to set user expectations.
   * 
   * @param string $query User query being processed
   * @param array $cacheState Cache state from CacheStateDetector
   *   - state: 'cold', 'warm', or 'expired'
   *   - exists: bool
   *   - valid: bool
   * @return void
   */
  public function sendProcessingMessage(string $query, array $cacheState): void
  {
    $state = $cacheState['state'] ?? 'unknown';
    
    // Determine appropriate message based on cache state
    if ($state === 'cold' || $state === 'expired') {
      $message = "Traitement de votre requête en cours. Première exécution, cela peut prendre un moment...";
      $expectedTime = "1-2 minutes";
    } else {
      $message = "Traitement de votre requête en cours...";
      $expectedTime = "quelques secondes";
    }
    
    // Build processing message structure
    $processingMessage = [
      'type' => 'processing',
      'message' => $message,
      'query' => substr($query, 0, 100), // Truncate for display
      'cache_state' => $state,
      'expected_time' => $expectedTime,
      'timestamp' => time()
    ];
    
    // Send message to client
    $this->sendToClient($processingMessage);
    
    // Log processing start
    if ($this->logger !== null) {
      try {
        $this->logger->logSecurityEvent(
          "Query processing started (cache state: {$state})",
          'info',
          $processingMessage
        );
      } catch (\Exception $e) {
        error_log("ProgressResponseHandler: Failed to log processing message: " . $e->getMessage());
      }
    }
    
    if ($this->debug) {
      error_log("ProgressResponseHandler: Processing message sent (cache state: {$state})");
    }
  }

  /**
   * Send completion message
   * 
   * Sends a completion message when query processing finishes successfully.
   * Includes execution time for performance monitoring.
   * 
   * @param float $executionTime Total execution time in seconds
   * @return void
   */
  public function sendCompletionMessage(float $executionTime): void
  {
    // Build completion message structure
    $completionMessage = [
      'type' => 'completion',
      'message' => "Requête traitée avec succès",
      'execution_time' => round($executionTime, 2),
      'execution_time_formatted' => $this->formatExecutionTime($executionTime),
      'timestamp' => time()
    ];
    
    // Send message to client
    $this->sendToClient($completionMessage);
    
    // Log completion
    if ($this->logger !== null) {
      try {
        $this->logger->logSecurityEvent(
          "Query processing completed in {$executionTime}s",
          'info',
          $completionMessage
        );
      } catch (\Exception $e) {
        error_log("ProgressResponseHandler: Failed to log completion message: " . $e->getMessage());
      }
    }
    
    if ($this->debug) {
      error_log("ProgressResponseHandler: Completion message sent (execution time: {$executionTime}s)");
    }
  }

  /**
   * Check if progress update is needed based on elapsed time
   * 
   * Determines if enough time has passed since the last update to send a new one.
   * Uses the configured update interval (default: 5 seconds).
   * 
   * @param float $elapsedSeconds Total elapsed time since query started
   * @return bool True if update should be sent
   */
  public function shouldSendUpdate(float $elapsedSeconds): bool
  {
    $currentTime = microtime(true);
    $timeSinceLastUpdate = $currentTime - $this->lastUpdateTime;
    
    // Send update if interval has passed
    $shouldSend = $timeSinceLastUpdate >= $this->updateInterval;
    
    if ($this->debug && $shouldSend) {
      error_log("ProgressResponseHandler: Update interval reached ({$timeSinceLastUpdate}s >= {$this->updateInterval}s)");
    }
    
    return $shouldSend;
  }

  /**
   * Send data to client
   * 
   * Sends JSON-encoded data to the client via output buffer.
   * Flushes output to ensure immediate delivery.
   * 
   * @param array $data Data to send to client
   * @return void
   */
  private function sendToClient(array $data): void
  {
    // Encode as JSON
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
    // Send to client with proper headers
    if (!headers_sent()) {
      header('Content-Type: application/json');
      header('X-Progress-Update: true');
    }
    
    // Output JSON
    echo $json . "\n";
    
    // Flush output buffers to ensure immediate delivery
    if (ob_get_level() > 0) {
      ob_flush();
    }
    flush();
    
    if ($this->debug) {
      error_log("ProgressResponseHandler: Data sent to client: " . substr($json, 0, 200));
    }
  }

  /**
   * Format execution time for display
   * 
   * Converts execution time in seconds to human-readable format.
   * 
   * @param float $seconds Execution time in seconds
   * @return string Formatted time (e.g., "1m 30s", "45s")
   */
  private function formatExecutionTime(float $seconds): string
  {
    if ($seconds < 60) {
      return round($seconds, 1) . 's';
    }
    
    $minutes = floor($seconds / 60);
    $remainingSeconds = round($seconds % 60);
    
    return "{$minutes}m {$remainingSeconds}s";
  }

  /**
   * Calculate estimated time remaining
   * 
   * Estimates remaining execution time based on current progress.
   * 
   * @param float $elapsedSeconds Time elapsed so far
   * @param float $percentComplete Current completion percentage (0-100)
   * @return int|null Estimated seconds remaining, or null if cannot estimate
   */
  public function calculateEstimatedTimeRemaining(
    float $elapsedSeconds,
    float $percentComplete
  ): ?int {
    // Cannot estimate if no progress or complete
    if ($percentComplete <= 0 || $percentComplete >= 100) {
      return null;
    }
    
    // Calculate estimated total time based on current progress
    $estimatedTotalTime = $elapsedSeconds / ($percentComplete / 100);
    
    // Calculate remaining time
    $remainingTime = $estimatedTotalTime - $elapsedSeconds;
    
    // Return as integer seconds (minimum 1 second)
    return max(1, (int)round($remainingTime));
  }

  /**
   * Get update interval
   * 
   * Returns the configured update interval in seconds.
   * 
   * @return int Update interval in seconds
   */
  public function getUpdateInterval(): int
  {
    return $this->updateInterval;
  }

  /**
   * Set update interval
   * 
   * Updates the interval between progress updates.
   * 
   * @param int $seconds Update interval in seconds
   * @return void
   */
  public function setUpdateInterval(int $seconds): void
  {
    $this->updateInterval = max(1, $seconds); // Minimum 1 second
    
    if ($this->debug) {
      error_log("ProgressResponseHandler: Update interval set to {$this->updateInterval}s");
    }
  }

  /**
   * Reset last update time
   * 
   * Resets the last update time to current time.
   * Useful when starting a new query.
   * 
   * @return void
   */
  public function resetLastUpdateTime(): void
  {
    $this->lastUpdateTime = microtime(true);
    
    if ($this->debug) {
      error_log("ProgressResponseHandler: Last update time reset");
    }
  }

  /**
   * Get statistics
   * 
   * Returns statistics about progress updates.
   * 
   * @return array Statistics
   *   - update_interval: int - configured update interval
   *   - last_update_time: float - timestamp of last update
   *   - time_since_last_update: float - seconds since last update
   *   - debug_enabled: bool - debug mode status
   *   - logger_enabled: bool - logger availability
   */
  public function getStats(): array
  {
    $currentTime = microtime(true);
    $timeSinceLastUpdate = $currentTime - $this->lastUpdateTime;
    
    return [
      'update_interval' => $this->updateInterval,
      'last_update_time' => $this->lastUpdateTime,
      'time_since_last_update' => round($timeSinceLastUpdate, 2),
      'debug_enabled' => $this->debug,
      'logger_enabled' => $this->logger !== null
    ];
  }
}
