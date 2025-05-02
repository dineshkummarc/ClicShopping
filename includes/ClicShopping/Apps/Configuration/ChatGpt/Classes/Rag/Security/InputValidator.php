<?php
/**
 * Input Validator Class
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Configuration\ChatGpt\Classes\Rag\Security;

use ClicShopping\Apps\Configuration\ChatGpt\Classes\Rag\Security\SecurityLogger;

/**
 * Class InputValidator
 * Provides comprehensive input validation for database operations
 * Ensures data integrity and prevents injection attacks
 */
class InputValidator
{
  private static ?SecurityLogger $logger = null;

  /**
   * Returns a singleton instance of the SecurityLogger
   * Initializes the logger with specified parameters if not already created
   *
   * @param string $logLevel Minimum log level to record (debug, info, warning, error)
   * @param int $maxLogSize Maximum log file size in bytes before rotation
   * @param int $logRotations Number of log rotations to maintain
   * @return SecurityLogger Instance of SecurityLogger
   */
  private static function getLogger(): SecurityLogger
  {
    if (self::$logger === null) {
      self::$logger = new SecurityLogger();
    }
    return self::$logger;
  }

  /**
   * Logs security-related events
   * Delegates logging to the SecurityLogger instance
   *
   * @param string $message Security event message
   * @param string $level Log level (info, warning, error)
   * @return void
   */

  private static function logSecurityEvent(string $message, string $level = 'warning'): void
  {
    self::getLogger()->logSecurityEvent($message, $level);
  }

    /**
     * Validates SQL query string
     * Checks for potentially dangerous SQL patterns and injection attempts
     *
     * @param string $query SQL query to validate
     * @return array Validation result with 'valid' boolean and 'issues' array
     */
  public static function validateSqlQuery(string $query): array
  {
    $issues = [];

    // Define dangerous SQL patterns
    $dangerousPatterns = self::getDangerousPatterns();

    // Check for dangerous patterns
    foreach ($dangerousPatterns as $pattern) {
      if (preg_match($pattern, $query)) {
        $issues[] = "Potentially malicious SQL pattern detected: " . htmlspecialchars($pattern);
      }
    }

    // Check for balanced quotes
    if (!self::areQuotesBalanced($query, "'")) {
      $issues[] = "Unbalanced single quotes in query";
    }

    if (!self::areQuotesBalanced($query, "\"")) {
      $issues[] = "Unbalanced double quotes in query";
    }

    return [
      'valid' => empty($issues),
      'issues' => $issues
    ];
  }

  /**
   * Returns an array of dangerous SQL patterns.
   *
   * @return array
   */
  private static function getDangerousPatterns(): array
  {
    return [
      '/;\s*DROP\s+/i',           // Prevent DROP statements
      '/;\s*DELETE\s+/i',         // Prevent DELETE statements outside of proper context
      '/UNION\s+SELECT/i',        // Prevent UNION-based injections
      '/INTO\s+OUTFILE/i',        // Prevent file operations
      '/INFORMATION_SCHEMA/i',    // Prevent schema exploration
      '/SLEEP\s*\(/i',            // Prevent time-based attacks
      '/BENCHMARK\s*\(/i',        // Prevent benchmark-based attacks
      '/LOAD_FILE\s*\(/i',        // Prevent file loading
      '/--\s/',                   // SQL comment indicators
      '/\/\*.*\*\//i'             // Multi-line comment blocks
    ];
  }

  /**
   * Checks if quotes are balanced in a string.
   *
   * @param string $query
   * @param string $quoteType
   * @return bool
   */
  private static function areQuotesBalanced(string $query, string $quoteType): bool
  {
    $escapedQuoteCount = substr_count($query, "\\{$quoteType}");
    $totalQuoteCount = substr_count($query, $quoteType);

    return ($totalQuoteCount - $escapedQuoteCount) % 2 === 0;
  }

    /**
     * Sanitizes table and column names
     * Ensures identifiers conform to expected patterns
     * Prevents SQL injection via identifier manipulation
     *
     * @param string $identifier Table or column name to sanitize
     * @return string Sanitized identifier
     */
  public static function sanitizeIdentifier(string $identifier): string
  {
      // Only allow alphanumeric characters, underscores, and specific patterns
      if (!preg_match('/^[a-zA-Z0-9_]+$/', $identifier)) {
          // If invalid characters found, strip them and log the attempt
          $sanitized = preg_replace('/[^a-zA-Z0-9_]/', '', $identifier);
          self::logSecurityEvent("Invalid identifier sanitized: {$identifier} -> {$sanitized}");
          return $sanitized;
      }

      return $identifier;
  }

  /**
   * Validates and sanitizes input parameters
   * Applies type-specific validation rules
   * Provides safe default values for invalid inputs
   *
   * @param mixed $input Input value to validate
   * @param string $type Expected data type
   * @param mixed $default Default value if validation fails
   * @return mixed Validated and sanitized input
   */
  public static function validateParameter($input, string $type, $default = null)
  {
    switch ($type) {
      case 'int':
          if (!is_numeric($input)) {
              self::logSecurityEvent("Invalid integer parameter sanitized: " . var_export($input, true));
              return $default ?? 0;
          }
          return (int)$input;

      case 'float':
          if (!is_numeric($input)) {
              self::logSecurityEvent("Invalid float parameter sanitized: " . var_export($input, true));
              return $default ?? 0.0;
          }
          return (float)$input;

      case 'string':
          if (!is_string($input)) {
              self::logSecurityEvent("Invalid string parameter sanitized: " . var_export($input, true));
              return $default ?? '';
          }
          $input = trim($input); // pas de htmlspecialchars !
          //return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
          return $input;

      case 'bool':
          return (bool)$input;

      case 'array':
          if (!is_array($input)) {
              self::logSecurityEvent("Invalid array parameter sanitized: " . var_export($input, true));
              return $default ?? [];
          }
          return $input;

      default:
          self::logSecurityEvent("Unknown parameter type: {$type}");
          return $default;
    }
 }
}
