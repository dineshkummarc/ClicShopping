<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\AI\Agents\Orchestrator;


use ClicShopping\OM\CLICSHOPPING;
use ClicShopping\OM\Registry;

use ClicShopping\AI\Security\SecurityLogger;
use ClicShopping\AI\Security\InputValidator;
use ClicShopping\AI\Security\DbSecurity;

/**
 * ValidationAgent Class
 * Specialized agent for proactive validation
 * Handles SQL validation, schema verification, performance analysis, and security checks
 */

class ValidationAgent
{
  private SecurityLogger $securityLogger;
  private DbSecurity $dbSecurity;
  private mixed $db;
  private bool $debug;
  private array $schemaCache = [];

  private int $maxRowsWarning = 10000;
  private float $securityScoreThreshold = 0.4;

  private array $stats = [
    'total_validations' => 0,
    'validations_passed' => 0,
    'validations_failed' => 0,
    'security_blocks' => 0,
    'performance_warnings' => 0,
  ];

  /**
   * Constructor
   *
   * @param string $userId User identifier
   */
  public function __construct(string $userId = 'system')
  {
    $this->securityLogger = new SecurityLogger();
    $this->dbSecurity = new DbSecurity();
    $this->db = Registry::get('Db');
    $this->debug = defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True';

    if ($this->debug) {
      $this->securityLogger->logSecurityEvent(
        "ValidationAgent initialized",
        'info'
      );
    }
  }

  /**
   * Validate SQL query before execution
   *
   * @param string $sql SQL query to validate
   * @param array $context Additional context
   * @return array Validation result
   */
  public function validateBeforeExecution(string $sql, array $context = []): array
  {
    $startTime = microtime(true);
    $this->stats['total_validations']++;

    $validation = [
      'is_valid' => true,
      'errors' => [],
      'warnings' => [],
      'suggestions' => [],
      'security_score' => 1.0,
      'performance_score' => 1.0,
      'can_execute' => true,
    ];

    try {
      if (!$this->isSqlQuery($sql)) {
        $this->securityLogger->logSecurityEvent(
          "Skipping validation for natural language query: " . substr($sql, 0, 50),
          'info'
        );
        
        $validation['validation_time'] = microtime(true) - $startTime;
        $this->stats['validations_passed']++;
        
        return $validation;
      }

      // 1. Validation syntaxique de base
      $syntaxValidation = $this->validateSyntax($sql);
      if (!$syntaxValidation['valid']) {
        $validation['is_valid'] = false;
        $validation['can_execute'] = false;
        $validation['errors'] = array_merge($validation['errors'], $syntaxValidation['issues']);
      }

      // 2. Validation de sécurité
      $securityValidation = $this->validateSecurity($sql, $context);
      $validation['security_score'] = $securityValidation['score'];

      if ($securityValidation['score'] < $this->securityScoreThreshold) {
        $validation['is_valid'] = false;
        $validation['can_execute'] = false;
        $validation['errors'][] = "Security score too low: " . $securityValidation['score'];
        $this->stats['security_blocks']++;

        $this->securityLogger->logSecurityEvent(
          "Query blocked by security validation: " . $sql,
          'warning',
          ['security_issues' => $securityValidation['issues']]
        );
      }

      if (!empty($securityValidation['issues'])) {
        $validation['warnings'] = array_merge($validation['warnings'], $securityValidation['issues']);
      }

      // 3. Validation du schéma (tables et colonnes existent-elles ?)
      if ($validation['can_execute']) {
        $schemaValidation = $this->validateSchema($sql);

        if (!$schemaValidation['valid']) {
          $validation['is_valid'] = false;
          $validation['errors'] = array_merge($validation['errors'], $schemaValidation['errors']);

          // Ne pas bloquer l'exécution, mais avertir
          if (!empty($schemaValidation['suggestions'])) {
            $validation['suggestions'] = array_merge(
              $validation['suggestions'],
              $schemaValidation['suggestions']
            );
          }
        }
      }

      // 4. Validation de performance (EXPLAIN)
      if ($validation['can_execute'] && stripos($sql, 'SELECT') === 0) {
        $performanceValidation = $this->validatePerformance($sql);
        $validation['performance_score'] = $performanceValidation['score'];

        if (!empty($performanceValidation['warnings'])) {
          $validation['warnings'] = array_merge(
            $validation['warnings'],
            $performanceValidation['warnings']
          );
          $this->stats['performance_warnings']++;
        }

        if (!empty($performanceValidation['suggestions'])) {
          $validation['suggestions'] = array_merge(
            $validation['suggestions'],
            $performanceValidation['suggestions']
          );
        }
      }

      // Validate by query type
      $typeValidation = $this->validateByType($sql);
      if (!empty($typeValidation['warnings'])) {
        $validation['warnings'] = array_merge($validation['warnings'], $typeValidation['warnings']);
      }

      // Update stats
      if ($validation['is_valid']) {
        $this->stats['validations_passed']++;
      } else {
        $this->stats['validations_failed']++;
      }

      $validation['validation_time'] = microtime(true) - $startTime;

      if ($this->debug) {
        $this->securityLogger->logSecurityEvent(
          "Validation completed: " . ($validation['is_valid'] ? 'PASSED' : 'FAILED') .
          " (security: {$validation['security_score']}, performance: {$validation['performance_score']})",
          $validation['is_valid'] ? 'info' : 'warning'
        );
      }

      return $validation;

    } catch (\Exception $e) {
      $this->securityLogger->logSecurityEvent(
        "Validation error: " . $e->getMessage(),
        'error'
      );

      return [
        'is_valid' => false,
        'can_execute' => false,
        'errors' => ['Validation failed: ' . $e->getMessage()],
        'warnings' => [],
        'suggestions' => [],
        'security_score' => 0.0,
        'performance_score' => 0.0,
      ];
    }
  }

  /**
   * Detect if input is SQL query or natural language
   *
   * @param string $input Text to analyze
   * @return bool True if SQL, false if natural language
   */
  private function isSqlQuery(string $input): bool
  {
    $input = trim($input);
    
    if (empty($input)) {
      return false;
    }
    
    $sqlKeywords = [
      'SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 
      'ALTER', 'TRUNCATE', 'REPLACE', 'SHOW', 'DESCRIBE', 'EXPLAIN'
    ];
    
    $firstWord = strtoupper(explode(' ', $input)[0]);
    
    if (in_array($firstWord, $sqlKeywords)) {
      return true;
    }
    
    if (preg_match('/^\s*(SELECT|INSERT|UPDATE|DELETE|CREATE|DROP|ALTER|TRUNCATE)\s+/i', $input)) {
      return true;
    }
    
    if (preg_match('/\b(FROM|WHERE|JOIN|GROUP BY|ORDER BY|HAVING|LIMIT)\b/i', $input)) {
      if (preg_match('/\b(SELECT|INSERT|UPDATE|DELETE)\b/i', $input)) {
        return true;
      }
    }
    
    return false;
  }

  /**
   * Validate SQL syntax
   * 
   * @param string $sql SQL query
   * @return array Validation result
   */
  private function validateSyntax(string $sql): array
  {
    return InputValidator::validateSqlQuery($sql);
  }

  /**
   * Validate query security
   * 
   * @param string $sql SQL query
   * @param array $context Additional context
   * @return array Security validation result
   */
  private function validateSecurity(string $sql, array $context): array
  {
    $issues = [];
    $score = 1.0;

    $injectionPatterns = [
      ['/--/', 0.3],
      ['/#/', 0.3],
      ['/\/\*.*?\*\//', 0.3],
      ['/\bunion\b.*\bselect\b/i', 0.4],
      ['/\bor\b\s+\d+\s*=\s*\d+/i', 0.4],     // OR 1=1 attacks (higher penalty)
      ['/\bsleep\s*\(/i', 0.4],               // Time-based attacks
      ['/\bbenchmark\s*\(/i', 0.4],           // Benchmark attacks
      ['/\bload_file\s*\(/i', 0.5],           // File reading (very dangerous)
      ['/\binto\s+outfile\b/i', 0.5],         // File writing (very dangerous)
      ['/\bexec\s*\(/i', 0.5],                // Code execution (very dangerous)
      ['/\bxp_/i', 0.4],                      // Extended stored procedures
    ];

    foreach ($injectionPatterns as $patternData) {
      $pattern = $patternData[0];
      $penalty = $patternData[1];
      
      if (preg_match($pattern, $sql)) {
        $issues[] = "Suspicious pattern detected: " . $pattern;
        $score -= $penalty;
      }
    }

    // Check destructive operations
    if (preg_match('/\b(DROP|TRUNCATE|DELETE|UPDATE)\b/i', $sql)) {
      $issues[] = "Destructive operation detected";
      $score -= 0.5;
    }

    // Check UPDATE/DELETE without WHERE
    if (preg_match('/\b(UPDATE|DELETE)\b/i', $sql) && !preg_match('/\bWHERE\b/i', $sql)) {
      $issues[] = "UPDATE/DELETE without WHERE clause";
      $score -= 0.4;
    }

    // Check SELECT * (warning only)
    if (preg_match('/SELECT\s+\*/i', $sql)) {
      $issues[] = "SELECT * detected (performance concern)";
      // Reduced penalty from 0.1 to 0.05 - common in analytics
      $score -= 0.05;
    }

    // Check multiple subqueries
    $subqueryCount = preg_match_all('/\(\s*SELECT\b/i', $sql);
    if ($subqueryCount > 5) { // Increased from 3 to 5
      $issues[] = "Multiple subqueries detected ({$subqueryCount})";
      // Reduced penalty from 0.1 to 0.05
      $score -= 0.05;
    }

    return [
      'score' => max(0.0, $score),
      'issues' => $issues,
    ];
  }

  /**
   * Validate that tables and columns exist
   */
  private function validateSchema(string $sql): array
  {
    $errors = [];
    $suggestions = [];

    try {
      // Extraire les tables
      $tables = $this->extractTables($sql);

      foreach ($tables as $table) {
        $tableName = $this->normalizeTableName($table);

        // Check if table exists
        if (!$this->tableExists($tableName)) {
          $errors[] = "Table does not exist: {$tableName}";

          // Suggest similar tables
          $similar = $this->findSimilarTables($tableName);
          if (!empty($similar)) {
            $suggestions[] = "Did you mean: " . implode(', ', $similar) . "?";
          }
        }
      }

      // Extraire les colonnes
      $columns = $this->extractColumns($sql);

      foreach ($columns as $column) {
        // Check if column exists in tables
        if (!$this->columnExistsInTables($column, $tables)) {
          $errors[] = "Column may not exist: {$column}";

          // Suggest similar columns
          $similar = $this->findSimilarColumns($column, $tables);
          if (!empty($similar)) {
            $suggestions[] = "Did you mean: " . implode(', ', $similar) . "?";
          }
        }
      }

      return [
        'valid' => empty($errors),
        'errors' => $errors,
        'suggestions' => $suggestions,
      ];

    } catch (\Exception $e) {
      if ($this->debug) {
        $this->securityLogger->logSecurityEvent(
          "Schema validation error: " . $e->getMessage(),
          'error'
        );
      }

      return [
        'valid' => true, // Ne pas bloquer en cas d'erreur de validation
        'errors' => [],
        'suggestions' => [],
      ];
    }
  }

  /**
   * Validate performance with EXPLAIN
   */
  private function validatePerformance(string $sql): array
  {
    $warnings = [];
    $suggestions = [];
    $score = 1.0;

    try {
      // Exécuter EXPLAIN
      $explainSql = "EXPLAIN " . $sql;
      $stmt = $this->db->prepare($explainSql);
      $stmt->execute();
      $explainResult = $stmt->fetchAll(\PDO::FETCH_ASSOC);

      foreach ($explainResult as $row) {
        // Check scan type
        $type = $row['type'] ?? '';

        if ($type === 'ALL') {
          $warnings[] = "Full table scan detected on table: " . ($row['table'] ?? 'unknown');
          $suggestions[] = "Consider adding an index on the WHERE/JOIN columns";
          // Reduced penalty from 0.2 to 0.05 - common in analytics
          $score -= 0.05;
        }

        // Check index usage
        $possibleKeys = $row['possible_keys'] ?? null;
        $key = $row['key'] ?? null;

        if ($possibleKeys && !$key) {
          $warnings[] = "Indexes available but not used on table: " . ($row['table'] ?? 'unknown');
          // Reduced penalty from 0.1 to 0.03
          $score -= 0.03;
        }

        // Check rows examined
        $rows = (int)($row['rows'] ?? 0);

        if ($rows > $this->maxRowsWarning) {
          $warnings[] = "Large number of rows to examine: {$rows}";
          $suggestions[] = "Consider adding WHERE clause to limit rows";
          // Reduced penalty from 0.15 to 0.05
          $score -= 0.05;
        }

        // Check Extra field
        $extra = $row['Extra'] ?? '';

        if (stripos($extra, 'Using filesort') !== false) {
          $warnings[] = "Filesort detected (slow ORDER BY)";
          $suggestions[] = "Consider adding an index on ORDER BY columns";
          // Reduced penalty from 0.1 to 0.03
          $score -= 0.03;
        }

        if (stripos($extra, 'Using temporary') !== false) {
          $warnings[] = "Temporary table creation detected";
          $suggestions[] = "Query might benefit from optimization";
          // Reduced penalty from 0.1 to 0.03
          $score -= 0.03;
        }
      }

      return [
        'score' => max(0.0, $score),
        'warnings' => $warnings,
        'suggestions' => $suggestions,
        'explain_result' => $explainResult,
      ];

    } catch (\Exception $e) {
      if ($this->debug) {
        $this->securityLogger->logSecurityEvent(
          "Performance validation error: " . $e->getMessage(),
          'error'
        );
      }

      return [
        'score' => 0.8, // Score par défaut si EXPLAIN échoue
        'warnings' => ['Could not analyze performance'],
        'suggestions' => [],
      ];
    }
  }

  /**
   * Validate by query type
   */
  private function validateByType(string $sql): array
  {
    $warnings = [];

    // SELECT avec LIMIT
    if (stripos($sql, 'SELECT') === 0 && stripos($sql, 'LIMIT') === false) {
      $warnings[] = "SELECT without LIMIT - could return many rows";
    }

    // JOIN sans ON
    if (preg_match('/\bJOIN\b/i', $sql) && !preg_match('/\bON\b/i', $sql)) {
      $warnings[] = "JOIN without ON clause detected";
    }

    // GROUP BY sans agrégation
    if (preg_match('/\bGROUP\s+BY\b/i', $sql) &&
      !preg_match('/\b(COUNT|SUM|AVG|MIN|MAX)\s*\(/i', $sql)) {
      $warnings[] = "GROUP BY without aggregate function";
    }

    return [
      'warnings' => $warnings,
    ];
  }

  /**
   * Extract table names from query
   */
  private function extractTables(string $sql): array
  {
    $tables = [];

    // FROM clause
    if (preg_match('/\bFROM\s+([^\s,;]+)/i', $sql, $matches)) {
      $tables[] = trim($matches[1], '`"\'');
    }

    // JOIN clauses
    preg_match_all('/\bJOIN\s+([^\s,;]+)/i', $sql, $matches);
    foreach ($matches[1] as $table) {
      $tables[] = trim($table, '`"\'');
    }

    return array_unique($tables);
  }

  /**
   * Extract column names from query
   */
  private function extractColumns(string $sql): array
  {
    $columns = [];

    // SELECT columns (simple extraction)
    if (preg_match('/SELECT\s+(.*?)\s+FROM/is', $sql, $matches)) {
      $selectPart = $matches[1];

      // Ignorer SELECT *
      if (trim($selectPart) === '*') {
        return [];
      }

      $parts = explode(',', $selectPart);
      foreach ($parts as $part) {
        $part = trim($part);

        // Extraire le nom de colonne (ignorer les alias)
        if (preg_match('/([a-z_][a-z0-9_]*)\s+AS\s+/i', $part, $m)) {
          $columns[] = $m[1];
        } elseif (preg_match('/([a-z_][a-z0-9_]*)/i', $part, $m)) {
          $columns[] = $m[1];
        }
      }
    }

    return array_unique($columns);
  }

  /**
   * Normalise un nom de table
   */
  private function normalizeTableName(string $table): string
  {
    // Enlever les alias
    if (preg_match('/^([^\s]+)/', $table, $matches)) {
      $table = $matches[1];
    }

    // Ajouter le préfixe si nécessaire
    $prefix = CLICSHOPPING::getConfig('db_prefix');
    if (!empty($prefix) && strpos($table, $prefix) !== 0) {
      $table = $prefix . $table;
    }

    return trim($table, '`"\'');
  }

  /**
   * Check if table exists
   */
  private function tableExists(string $tableName): bool
  {
    if (isset($this->schemaCache['tables'][$tableName])) {
      return $this->schemaCache['tables'][$tableName];
    }

    try {
      $stmt = $this->db->prepare(
        "SELECT COUNT(*) 
         FROM information_schema.tables 
         WHERE table_schema = DATABASE() 
         AND table_name = ?"
      );
      $stmt->execute([$tableName]);
      $exists = (int)$stmt->fetchColumn() > 0;

      $this->schemaCache['tables'][$tableName] = $exists;

      return $exists;

    } catch (\Exception $e) {
      return false;
    }
  }

  /**
   * Trouve des tables similaires
   */
  private function findSimilarTables(string $tableName): array
  {
    try {
      $stmt = $this->db->prepare(
        "SELECT table_name 
         FROM information_schema.tables 
         WHERE table_schema = DATABASE()"
      );
      $stmt->execute();
      $allTables = $stmt->fetchAll(\PDO::FETCH_COLUMN);

      $similar = [];
      foreach ($allTables as $table) {
        similar_text(strtolower($tableName), strtolower($table), $percent);
        if ($percent > 60) {
          $similar[] = $table;
        }
      }

      return array_slice($similar, 0, 3);

    } catch (\Exception $e) {
      return [];
    }
  }

  /**
   * Check if column exists in tables
   */
  private function columnExistsInTables(string $column, array $tables): bool
  {
    foreach ($tables as $table) {
      $tableName = $this->normalizeTableName($table);

      if ($this->columnExistsInTable($column, $tableName)) {
        return true;
      }
    }

    return false;
  }

  /**
   * Check if column exists in table
   */
  private function columnExistsInTable(string $column, string $table): bool
  {
    $cacheKey = "{$table}.{$column}";

    if (isset($this->schemaCache['columns'][$cacheKey])) {
      return $this->schemaCache['columns'][$cacheKey];
    }

    try {
      $stmt = $this->db->prepare(
        "SELECT COUNT(*) 
        FROM information_schema.columns 
        WHERE table_schema = DATABASE() 
        AND table_name = ? 
        AND column_name = ?"
      );
      $stmt->execute([$table, $column]);
      $exists = (int)$stmt->fetchColumn() > 0;

      $this->schemaCache['columns'][$cacheKey] = $exists;

      return $exists;

    } catch (\Exception $e) {
      return false;
    }
  }

  /**
   * Trouve des colonnes similaires
   */
  private function findSimilarColumns(string $column, array $tables): array
  {
    $similar = [];

    foreach ($tables as $table) {
      $tableName = $this->normalizeTableName($table);

      try {
        $stmt = $this->db->prepare(
          "SELECT column_name 
           FROM information_schema.columns 
           WHERE table_schema = DATABASE() 
           AND table_name = ?"
        );
        $stmt->execute([$tableName]);
        $columns = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($columns as $col) {
          similar_text(strtolower($column), strtolower($col), $percent);
          if ($percent > 60) {
            $similar[] = "{$tableName}.{$col}";
          }
        }

      } catch (\Exception $e) {
        continue;
      }
    }

    return array_slice($similar, 0, 3);
  }

  /**
   * statistics
   */
  public function getStats(): array
  {
    $total = $this->stats['validations_passed'] + $this->stats['validations_failed'];
    $successRate = $total > 0
      ? round(($this->stats['validations_passed'] / $total) * 100, 2)
      : 0;

    return array_merge($this->stats, [
      'success_rate' => $successRate . '%',
    ]);
  }
}