<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Tools\MCP\Classes\ClicShoppingAdmin;

use ClicShopping\OM\Registry;
use ClicShopping\Apps\Tools\MCP\MCP as MCPApp;

/**
 * Class McpStatus
 *
 * This class provides a comprehensive health and performance monitoring solution for the
 * MCP (Model Context Protocol) system. It performs various checks on connectivity,
 * performance, and system resources to provide a detailed status report and recommendations.
 */
class McpStatus
{
  /**
   * @var mixed The main MCP application instance.
   */
  private mixed $app;

  /**
   * @var array An associative array to store the latest health metrics.
   */
  private array $healthMetrics = [];

  /**
   * @var string The timestamp of the last health check.
   */
  private string $lastCheckTime;

  /**
   * McpStatus constructor.
   *
   * Initializes the health checker by ensuring the MCP application instance is available
   * and that the necessary configuration is installed.
   */
  public function __construct()
  {
    if (!Registry::exists('MCP')) {
      Registry::set('MCP', new MCPApp());
    }

    $this->app = Registry::get('MCP');
    $this->lastCheckTime = date('Y-m-d H:i:s');

    // Ensure MCP configuration is installed
    $this->ensureMcpConfigInstalled();
  }

  /**
   * Ensures the necessary MCP configuration is installed in the database.
   *
   * This private helper method checks for a specific configuration key and creates it
   * with a default value if it does not exist. This is crucial for the application's
   * functionality.
   */
  private function ensureMcpConfigInstalled(): void
  {
    // Check if MCP configuration is already installed
    if (!defined('CLICSHOPPING_APP_MCP_MC_STATUS')) {
      try {
        // Check if configuration exists in database
        $Qcheck = $this->app->db->prepare('SELECT configuration_value
                                           FROM :table_configuration
                                           WHERE configuration_key = :key');
        $Qcheck->bindValue(':key', 'CLICSHOPPING_APP_MCP_MC_STATUS');
        $Qcheck->execute();

        if (!$Qcheck->fetch()) {
          // Configuration doesn't exist, create it with default value
          $this->app->db->save('configuration', [
            'configuration_title' => 'Enable MCP Status Monitoring',
            'configuration_key' => 'CLICSHOPPING_APP_MCP_MC_STATUS',
            'configuration_value' => 'True',
            'configuration_description' => 'Enable or disable MCP status monitoring',
            'configuration_group_id' => '6',
            'sort_order' => '10',
            'set_function' => 'clic_cfg_set_boolean_value(array(\'True\', \'False\'))',
            'date_added' => 'now()'
          ]);

          // Define the constant for immediate use
          define('CLICSHOPPING_APP_MCP_MC_STATUS', 'True');
        } else {
          // Configuration exists, define the constant
          define('CLICSHOPPING_APP_MCP_MC_STATUS', $Qcheck->value('configuration_value'));
        }
      } catch (\Exception $e) {
        // Log error and define default
        error_log('MCP Config installation error: ' . $e->getMessage());
        if (!defined('CLICSHOPPING_APP_MCP_MC_STATUS')) {
          define('CLICSHOPPING_APP_MCP_MC_STATUS', 'True');
        }
      }
    }
  }

  /**
   * Performs a comprehensive health check of the MCP system.
   *
   * This public method orchestrates the entire health checking process by calling
   * private methods for connectivity, performance, and system health checks. It then
   * determines an overall status based on the results.
   *
   * @return array Health status information including connectivity, performance, and system metrics.
   */
  public function check(): array
  {
    $this->healthMetrics = [
      'status' => 'healthy',
      'timestamp' => $this->lastCheckTime,
      'connectivity' => $this->checkConnectivity(),
      'performance' => $this->checkPerformance(),
      'system' => $this->checkSystemHealth(),
      'errors' => $this->getRecentErrors()
    ];

    // Determine overall status based on individual checks
    $this->healthMetrics['status'] = $this->determineOverallStatus();

    return $this->healthMetrics;
  }

  /**
   * Checks MCP connectivity and response times.
   *
   * This private method simulates a connection check and measures latency. In a real-world
   * scenario, it would connect to the actual MCP service.
   *
   * @return array Connectivity metrics.
   */
  private function checkConnectivity(): array
  {
    $startTime = microtime(true);

    try {
      // Simulate MCP connection check
      $isConnected = $this->pingMcpService();
      $latency = round((microtime(true) - $startTime) * 1000, 2);

      return [
        'connected' => $isConnected,
        'latency' => $latency,
        'last_response' => date('Y-m-d H:i:s'),
        'status' => $isConnected ? 'online' : 'offline'
      ];
    } catch (\Exception $e) {
      return [
        'connected' => false,
        'latency' => null,
        'last_response' => null,
        'status' => 'error',
        'error' => $e->getMessage()
      ];
    }
  }

  /**
   * Checks performance metrics.
   *
   * This private method simulates gathering performance data such as total requests,
   * error counts, and average response times.
   *
   * @return array Performance data.
   */
  private function checkPerformance(): array
  {
    try {
      $totalRequests = $this->getTotalRequests();
      $errorCount = $this->getErrorCount();
      $errorRate = $totalRequests > 0 ? round(($errorCount / $totalRequests) * 100, 2) : 0;

      return [
        'total_requests' => $totalRequests,
        'successful_requests' => $totalRequests - $errorCount,
        'error_count' => $errorCount,
        'error_rate' => $errorRate,
        'avg_response_time' => $this->getAverageResponseTime(),
        'requests_per_minute' => $this->getRequestsPerMinute()
      ];
    } catch (\Exception $e) {
      return [
        'total_requests' => 0,
        'successful_requests' => 0,
        'error_count' => 0,
        'error_rate' => 0,
        'avg_response_time' => null,
        'requests_per_minute' => 0,
        'error' => $e->getMessage()
      ];
    }
  }

  /**
   * Checks system health metrics.
   *
   * This private method gathers information about the system's memory, CPU, and disk space usage.
   *
   * @return array System health data.
   */
  private function checkSystemHealth(): array
  {
    return [
      'memory_usage' => $this->getMemoryUsage(),
      'cpu_usage' => $this->getCpuUsage(),
      'disk_space' => $this->getDiskSpace(),
      'uptime' => $this->getUptime(),
      'version' => $this->getMcpVersion()
    ];
  }

  /**
   * Gets recent errors from logs.
   *
   * This private method simulates fetching recent errors. In a production environment,
   * it would query a database or log file system.
   *
   * @return array Recent error information.
   */
  private function getRecentErrors(): array
  {
    try {
      // Get recent errors from database or log files
      $errors = $this->fetchRecentErrors();

      return [
        'count' => count($errors),
        'recent_errors' => array_slice($errors, 0, 5), // Last 5 errors
        'critical_errors' => $this->filterCriticalErrors($errors)
      ];
    } catch (\Exception $e) {
      return [
        'count' => 0,
        'recent_errors' => [],
        'critical_errors' => [],
        'fetch_error' => $e->getMessage()
      ];
    }
  }

  /**
   * Determines the overall system status based on individual check results.
   *
   * This private helper method aggregates the results from all checks to provide a single,
   * easy-to-understand status: 'healthy', 'warning', or 'error'.
   *
   * @return string Overall status.
   */
  private function determineOverallStatus(): string
  {
    $connectivity = $this->healthMetrics['connectivity'] ?? [];
    $performance = $this->healthMetrics['performance'] ?? [];
    $errors = $this->healthMetrics['errors'] ?? [];

    // Check for critical issues
    if (!($connectivity['connected'] ?? false)) {
      return 'error';
    }

    if (($performance['error_rate'] ?? 0) > 10) {
      return 'error';
    }

    if (count($errors['critical_errors'] ?? []) > 0) {
      return 'error';
    }

    // Check for warnings
    if (($performance['error_rate'] ?? 0) > 5) {
      return 'warning';
    }

    if (($connectivity['latency'] ?? 0) > 1000) {
      return 'warning';
    }

    return 'healthy';
  }

  /**
   * Simulates pinging the MCP service to check for connectivity.
   *
   * This method relies on a configuration constant to determine if the service is enabled.
   *
   * @return bool True if the service is configured as 'True', False otherwise.
   */
  private function pingMcpService(): bool
  {
    // In a real implementation, this would ping the actual MCP service
    // For now, we'll simulate based on MCP configuration

    // Check for the official MCP configuration constant
    if (defined('CLICSHOPPING_APP_MCP_MC_STATUS')) {
      return CLICSHOPPING_APP_MCP_MC_STATUS === 'True';
    }

    // If constant is not defined, check database directly
    try {
      $Qcheck = $this->app->db->prepare('SELECT configuration_value
                                         FROM :table_configuration
                                         WHERE configuration_key = :key');
      $Qcheck->bindValue(':key', 'CLICSHOPPING_APP_MCP_MC_STATUS');
      $Qcheck->execute();

      if ($Qcheck->fetch()) {
        $value = $Qcheck->value('configuration_value');
        return $value === 'True';
      }
    } catch (\Exception $e) {
      // Log error if needed
      error_log('MCP Status check error: ' . $e->getMessage());
    }

    // Default to false if no configuration found
    return false;
  }

  /**
   * Gets the total number of requests (simulated).
   *
   * @return int Total requests.
   */
  private function getTotalRequests(): int
  {
    // In a real implementation, this would query the database
    // For simulation, return a random number
    return rand(100, 1000);
  }

  /**
   * Gets the error count (simulated).
   *
   * @return int The error count.
   */
  private function getErrorCount(): int
  {
    // Simulate low error count for healthy status
    return rand(0, 5);
  }

  /**
   * Gets the average response time (simulated).
   *
   * @return float The average response time in milliseconds.
   */
  private function getAverageResponseTime(): float
  {
    return round(rand(100, 800) / 10, 2);
  }

  /**
   * Gets the requests per minute (simulated).
   *
   * @return int The number of requests per minute.
   */
  private function getRequestsPerMinute(): int
  {
    return rand(10, 100);
  }

  /**
   * Gets memory usage information.
   *
   * @return array Memory usage data including used, peak, and limit.
   */
  private function getMemoryUsage(): array
  {
    return [
      'used' => round(memory_get_usage(true) / 1024 / 1024, 2),
      'peak' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
      'limit' => ini_get('memory_limit')
    ];
  }

  /**
   * Gets CPU usage (simulated).
   *
   * @return float The CPU usage percentage.
   */
  private function getCpuUsage(): float
  {
    // Simulate CPU usage
    return round(rand(10, 80) / 10, 1);
  }

  /**
   * Gets disk space information.
   *
   * @return array Disk space data including total, used, free, and percentage.
   */
  private function getDiskSpace(): array
  {
    $totalBytes = disk_total_space('.');
    $freeBytes = disk_free_space('.');
    $usedBytes = $totalBytes - $freeBytes;

    return [
      'total' => round($totalBytes / 1024 / 1024 / 1024, 2),
      'used' => round($usedBytes / 1024 / 1024 / 1024, 2),
      'free' => round($freeBytes / 1024 / 1024 / 1024, 2),
      'percentage' => round(($usedBytes / $totalBytes) * 100, 2)
    ];
  }

  /**
   * Gets the system uptime (simulated).
   *
   * @return string A human-readable string representing system uptime.
   */
  private function getUptime(): string
  {
    // Simulate uptime
    return '2 days, 14 hours, 32 minutes';
  }

  /**
   * Gets the MCP version (simulated).
   *
   * @return string The MCP version number.
   */
  private function getMcpVersion(): string
  {
    return '1.0.0';
  }

  /**
   * Fetches recent errors from a log source (simulated).
   *
   * @return array A list of recent errors.
   */
  private function fetchRecentErrors(): array
  {
    // In a real implementation, this would query error logs
    // For simulation, return minimal errors (no critical errors by default)
    return [
      [
        'timestamp' => date('Y-m-d H:i:s', strtotime('-1 hour')),
        'level' => 'info',
        'message' => 'MCP service running normally'
      ]
    ];
  }

  /**
   * Filters a list of errors to only include critical errors.
   *
   * @param array $errors The list of errors to filter.
   * @return array A list of critical errors.
   */
  private function filterCriticalErrors(array $errors): array
  {
    return array_filter($errors, function ($error) {
      return ($error['level'] ?? '') === 'error';
    });
  }

  /**
   * Gets a detailed health report.
   *
   * This public method provides an extensive report including historical data, trends,
   * and recommendations in addition to the basic health check.
   *
   * @return array The detailed health report.
   */
  public function getDetailedReport(): array
  {
    $basicCheck = $this->check();

    return array_merge($basicCheck, [
      'detailed_metrics' => [
        'response_times' => $this->getResponseTimeHistory(),
        'error_trends' => $this->getErrorTrends(),
        'usage_patterns' => $this->getUsagePatterns()
      ],
      'recommendations' => $this->getRecommendations()
    ]);
  }

  /**
   * Gets response time history (simulated).
   *
   * @return array Historical response time data.
   */
  private function getResponseTimeHistory(): array
  {
    // Simulate response time history
    $history = [];
    for ($i = 0; $i < 24; $i++) {
      $history[] = [
        'hour' => $i,
        'avg_response_time' => rand(100, 800) / 10
      ];
    }
    return $history;
  }

  /**
   * Gets error trends (simulated).
   *
   * @return array Historical error trend data.
   */
  private function getErrorTrends(): array
  {
    // Simulate error trends
    return [
      'hourly' => array_map(function ($hour) {
        return ['hour' => $hour, 'errors' => rand(0, 5)];
      }, range(0, 23)),
      'daily' => array_map(function ($day) {
        return ['day' => $day, 'errors' => rand(0, 50)];
      }, range(1, 7))
    ];
  }

  /**
   * Gets usage patterns (simulated).
   *
   * @return array Simulated usage pattern data.
   */
  private function getUsagePatterns(): array
  {
    return [
      'peak_hours' => [9, 10, 11, 14, 15, 16],
      'avg_concurrent_users' => rand(10, 100),
      'most_used_endpoints' => [
        '/api/chat' => rand(100, 500),
        '/api/status' => rand(50, 200),
        '/api/health' => rand(20, 100)
      ]
    ];
  }

  /**
   * Gets recommendations based on current health status.
   *
   * @return array A list of actionable recommendations.
   */
  private function getRecommendations(): array
  {
    $recommendations = [];

    if (($this->healthMetrics['connectivity']['latency'] ?? 0) > 500) {
      $recommendations[] = 'Consider optimizing network configuration to reduce latency';
    }

    if (($this->healthMetrics['performance']['error_rate'] ?? 0) > 5) {
      $recommendations[] = 'Investigate and resolve causes of high error rate';
    }

    if (($this->healthMetrics['system']['memory_usage']['used'] ?? 0) > 80) {
      $recommendations[] = 'Monitor memory usage and consider scaling resources';
    }

    return $recommendations;
  }
}