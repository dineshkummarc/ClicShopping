<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Configuration\ChatGpt\Classes\Rag;

use AllowDynamicProperties;
use ClicShopping\OM\Registry;
use ClicShopping\OM\CLICSHOPPING;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\Gpt;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\Security\InputValidator;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\Security\SecurityLogger;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\Security\RateLimit;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\Security\DbSecurity;


use ClicShopping\Apps\Configuration\ChatGpt\Classes\Rag\Cache;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\Rag\Semantics;

/**
 * Class AnalyticsAgent
 * Handles database analytics and query processing with AI assistance
 * Manages table relationships, schema validation, and query optimization
 * Implements comprehensive security measures
 */
#[AllowDynamicProperties]
class AnalyticsAgent
{
  private mixed $chat;
  private mixed $db;
  private int $languageId;
  private array $tableSchemaCache = [];
  private array $tableRelationships = [];
  private array $columnSynonyms = [];
  private array $correctionLog = [];
  private array $databaseSchema = [];
  private array $columnIndex = [];
  private mixed $cache;
  private bool $enablePromptCache;
  private bool $debug = false;
  private SecurityLogger $securityLogger;
  private RateLimit $rateLimit;
  private string $userId;
  private DbSecurity $dbSecurity;

  /**
   * Constructor for AnalyticsAgent
   * Initializes database connection, language settings, and AI chat interface
   * Sets up schema caching, table relationships, and security components
   *
   * @param int|null $languageId Language ID for filtering results
   * @param bool $enablePromptCache Whether to enable local prompt caching
   * @param string $userId User identifier for rate limiting and auditing
   */
  public function __construct(?int $languageId = null, bool $enablePromptCache = true, string $userId = 'system')
  {
    $this->db = Registry::get('Db');

    if (strpos(CLICSHOPPING_APP_CHATGPT_CH_MODEL, 'gpt') === 0) {
      $this->chat = Gpt::getOpenAiGpt(null);
    } elseif (strpos(CLICSHOPPING_APP_CHATGPT_CH_MODEL, 'anth') === 0) {
      $this->chat = Gpt::getAnthropicChat(CLICSHOPPING_APP_CHATGPT_CH_MODEL);
    } elseif (strpos(CLICSHOPPING_APP_CHATGPT_CH_MODEL, 'mistral') === 0) {
      $this->chat = Gpt::getMistralChat(CLICSHOPPING_APP_CHATGPT_CH_MODEL);
    } else {
      $this->chat = Gpt::getOllamaChat(CLICSHOPPING_APP_CHATGPT_CH_MODEL);
    }

    $this->userId = $userId;

    if (is_null($languageId)){
      $this->languageId = Registry::get('Language')->getId();
    } else {
      $this->languageId = $languageId;
    }

    // Initialize security components
    $this->securityLogger = new SecurityLogger();
    $this->rateLimit = new RateLimit('analytics_agent', 50, 60); // 50 requests per minute
    $this->dbSecurity = new DbSecurity();

    $this->cache = new Cache($enablePromptCache);
    $this->debug = defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True';

    $this->enablePromptCache = $enablePromptCache;

    // Log initialization
    $this->securityLogger->logSecurityEvent("AnalyticsAgent initialized for user {$this->userId}", 'info');

    $this->setSystemMessage();

    $this->maxRowsForInterpretation = defined('CLICSHOPPING_APP_CHATGPT_RA_MAX_ROWS_PROMPT') ? (int)CLICSHOPPING_APP_CHATGPT_RA_MAX_ROWS_PROMPT : 100;

    try {
      $this->initializeTableRelationships();
      $this->buildDatabaseSchema();
    } catch (\Exception $e) {
      $this->securityLogger->logSecurityEvent("Error during AnalyticsAgent initialization: " . $e->getMessage(), 'error');

      if ($this->debug) {
        $this->securityLogger->logSecurityEvent("Error during AnalyticsAgent initialization: " . $e->getMessage(), 'error');
      }
    }
  }

  /**
   * Configures the system message for the LLPhant agent with improved instructions
   * Combines base system message, SQL formatting instructions, and table structure guidelines
   * Includes security guidelines for query generation
   *
   * @return void
   */
  private function setSystemMessage(): void
  {
    $baseSystemMessage = CLICSHOPPING::getDef('text_system_message', ['language_id' => $this->languageId]);
    $sqlFormatInstructions = CLICSHOPPING::getDef('text_sql_format_instructions', ['language_id' => $this->languageId]);
    $tableStructureInstructions = CLICSHOPPING::getDef('text_table_structure_instructions');

    // Add security guidelines
    $securityGuidelines =  CLICSHOPPING::getDef('text_security_guidelines');

    $this->chat->setSystemMessage($baseSystemMessage . $sqlFormatInstructions . $tableStructureInstructions . $securityGuidelines);
  }

  /**
   * Initializes table relationships based on database schema
   * Analyzes table structures to identify foreign key relationships
   * Builds relationship mappings and column synonyms dictionary
   * Implements security checks and error handling
   *
   * @return void
   * @throws \Exception When database queries fail
   */
  private function initializeTableRelationships(): void
  {
    try {
      // Check rate limiting
      if (!$this->rateLimit->checkLimit($this->userId)) {
        $this->securityLogger->logSecurityEvent(
          "Rate limit exceeded for user {$this->userId} during table relationship initialization",
          'warning'
        );
        throw new \Exception("Rate limit exceeded for user {$this->userId}");
      }

      // Retrieve all tables from the database
      $query = $this->db->prepare("SHOW TABLES");
      $query->execute();
      $tables = $query->fetchAll(\PDO::FETCH_COLUMN);

      // For each table, analyze the columns to detect potential relationships
      foreach ($tables as $table) {
        // Validate table name
        $safeTable = InputValidator::sanitizeIdentifier($table);
        if ($safeTable !== $table) {
          $this->securityLogger->logSecurityEvent(
            "Suspicious table name sanitized: {$table} -> {$safeTable}",
            'warning'
          );
          $table = $safeTable;
        }


        $schema = $this->getTableSchema($table);

        foreach ($schema as $column => $type) {
          // Validate column name
          $safeColumn = InputValidator::sanitizeIdentifier($column);

          if ($safeColumn !== $column) {
            $this->securityLogger->logSecurityEvent(
              "Suspicious column name sanitized: {$column} -> {$safeColumn}",
              'warning'
            );

            $column = $safeColumn;
          }

          // Detect ID columns that could be foreign keys
          if (preg_match('/_id$/', $column) && strpos($type, 'int') !== false) {
            $relatedTable = str_replace('_id', '', $column);

            // Validate related table name
            $safeRelatedTable = InputValidator::sanitizeIdentifier($relatedTable);
            if ($safeRelatedTable !== $relatedTable) {
              $this->securityLogger->logSecurityEvent(
                "Suspicious related table name sanitized: {$relatedTable} -> {$safeRelatedTable}",
                'warning'
              );

              $relatedTable = $safeRelatedTable;
            }

            $prefix = CLICSHOPPING::getConfig('prefix_table'); // '_clic'
            // Check if the related table exists
            if (in_array($relatedTable, $tables) || in_array($prefix . $relatedTable, $tables)) {
              $actualTable = in_array($prefix . $relatedTable, $tables) ? $prefix . $relatedTable : $relatedTable;
              $this->tableRelationships[$table][$column] = $actualTable;
            }
          }
        }
      }

      // Build a dictionary of column synonyms based on similar names
      $this->buildColumnSynonyms($tables);
    } catch (\Exception $e) {
      $this->securityLogger->logSecurityEvent(
        "Error initializing table relationships: " . $e->getMessage(),
        'error'
      );

      if ($this->debug) {
        $this->securityLogger->logSecurityEvent("Error initializing table relationships: " . $e->getMessage(), 'error');
      }

      // Re-throw for higher-level handling
      throw $e;
    }
  }

  /**
   * Builds a comprehensive database schema for validation and correction
   * Creates detailed mapping of table structures including column properties
   * Generates an inverse index for quick column lookups
   * Implements security checks and error handling
   *
   * @return void
   * @throws \Exception When schema building encounters errors
   */
  private function buildDatabaseSchema(): void
  {
    try {
      // Check rate limiting
      if (!$this->rateLimit->checkLimit($this->userId)) {
        $this->securityLogger->logSecurityEvent(
          "Rate limit exceeded for user {$this->userId} during database schema building",
          'warning'
        );
        throw new \Exception("Rate limit exceeded for user {$this->userId}");
      }

      $query = $this->db->prepare("SHOW TABLES");
      $query->execute();
      $tables = $query->fetchAll(\PDO::FETCH_COLUMN);

      $this->databaseSchema = [];

      foreach ($tables as $table) {
        // Validate table name
        $safeTable = InputValidator::sanitizeIdentifier($table);

        if ($safeTable !== $table) {
          $this->securityLogger->logSecurityEvent(
            "Suspicious table name sanitized in buildDatabaseSchema: {$table} -> {$safeTable}",
            'warning'
          );

          $table = $safeTable;
        }

        // Retrieve columns for each table
        $columnsQuery = $this->db->prepare("DESCRIBE " . $table);
        $columnsQuery->execute();
        $columns = $columnsQuery->fetchAll(\PDO::FETCH_ASSOC);

        $this->databaseSchema[$table] = [];

        foreach ($columns as $column) {
          $this->databaseSchema[$table][$column['Field']] = [
            'type' => $column['Type'],
            'null' => $column['Null'],
            'key' => $column['Key'],
            'default' => $column['Default'],
            'extra' => $column['Extra']
          ];
        }
      }

      // Construire un index inversé pour rechercher rapidement les colonnes
      $this->columnIndex = [];

      foreach ($this->databaseSchema as $table => $columns) {
        foreach (array_keys($columns) as $column) {
          if (!isset($this->columnIndex[$column])) {
            $this->columnIndex[$column] = [];
          }
          $this->columnIndex[$column][] = $table;
        }
      }

      $this->securityLogger->logSecurityEvent(
        "Database schema built successfully: " . count($this->databaseSchema) . " tables indexed",
        'info'
      );

    } catch (\Exception $e) {
      $this->securityLogger->logSecurityEvent(
        "Error building database schema: " . $e->getMessage(),
        'error'
      );

      if ($this->debug) {
        $this->securityLogger->logSecurityEvent("Error while building the database schema: " . $e->getMessage(), 'error');
      }

      // Re-throw for higher-level handling
      throw $e;
    }
  }

  /**
   * Builds column synonyms dictionary by analyzing table schemas
   * Identifies columns with similar names across different tables
   * Groups related columns based on common naming patterns
   *
   * @param array $tables List of database tables to analyze
   * @return void
   */
  private function buildColumnSynonyms(array $tables): void
  {
    $allColumns = [];

    // Collect all columns from all tables
    foreach ($tables as $table) {
      $schema = $this->getTableSchema($table);

      foreach ($schema as $column => $type) {
        if (!isset($allColumns[$column])) {
          $allColumns[$column] = [];
        }

        $allColumns[$column][] = $table;
      }
    }

    // Identify potential synonyms based on common name parts
    foreach ($allColumns as $column => $tables) {
      // Extract the significant part of the column name (without common prefixes/suffixes)
      $baseName = preg_replace('/^(.*?)_|_(.*?)$/', '', $column);

      if (strlen($baseName) >= 3) { // ignore names that are too short
        if (!isset($this->columnSynonyms[$baseName])) {
          $this->columnSynonyms[$baseName] = [];
        }

        $this->columnSynonyms[$baseName][] = $column;
      }
    }
  }

  /**
   * Extracts SQL queries from a response string
   * Uses regex patterns to identify and validate SQL queries
   * Handles potential security issues and logs suspicious patterns
   *
   * @param string $response The response string containing SQL queries
   * @param bool $AllowSqlPattern Whether to allow specific SQL patterns (default: false)
   * @return array Array of extracted SQL queries
   */
  private function extractSqlQueries(string $response, bool $AllowSqlPattern = false): array
  {
    $queries = [];

    $safeResponse = InputValidator::validateParameter($response, 'string');
    if ($safeResponse !== $response) {
      $this->securityLogger->logSecurityEvent(
        "Response sanitized in extractSqlQueries",
        'warning'
      );
      $response = $safeResponse;
    }

    $array = [
      '/\\b(SELECT\\s+.*?)(;|\\Z)/is',
      '/\\b(INSERT\\s+.*?)(;|\\Z)/is',
      '/\\b(UPDATE\\s+.*?)(;|\\Z)/is',
      '/\\b(DELETE\\s+.*?)(;|\\Z)/is',
      '/\\b(CREATE\\s+.*?)(;|\\Z)/is',
      '/\\b(ALTER\\s+.*?)(;|\\Z)/is',
      '/\\b(DROP\\s+.*?)(;|\\Z)/is'
    ];

    $sqlPatterns = $AllowSqlPattern === false  ? ['/\\b(SELECT\\s+.*?)(;|\\Z)/is'] : $array;

    foreach ($sqlPatterns as $pattern) {
      if (preg_match_all($pattern, $response, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
          $query = trim($match[1]);

          if (preg_match('/(--|#|\/\*|\bunion\b|\bsleep\b|\bbenchmark\b|\bxp_|;)/i', $query)) {
            $this->securityLogger->logSecurityEvent(
              "Rejected query due to suspicious SQL pattern",
              'warning',
              ['query' => $query]
            );
            continue;
          }

          $validation = InputValidator::validateSqlQuery($query);
          if (!$validation['valid']) {
            $this->securityLogger->logSecurityEvent(
              "Potentially malicious SQL pattern detected in extracted query: " . implode(', ', $validation['issues']),
              'warning',
              ['query' => $query]
            );
            continue;
          }

          $queries[] = $query;
        }
      }
    }

    if (empty($queries)) {
      $fullPattern = $AllowSqlPattern === false
        ? '/^\s*(SELECT)\s+/i'
        : '/^\s*(SELECT|INSERT|UPDATE|DELETE|CREATE|ALTER|DROP)\s+/i';

      if (preg_match($fullPattern, trim($response))) {
        $query = trim($response);

        if (preg_match('/(--|#|\/\*|\bunion\b|\bsleep\b|\bbenchmark\b|\bxp_|;)/i', $query)) {
          $this->securityLogger->logSecurityEvent(
            "Rejected full response due to suspicious SQL pattern",
            'warning',
            ['query' => $query]
          );
        } else {
          $validation = InputValidator::validateSqlQuery($query);
          if ($validation['valid']) {
            $queries[] = $query;
          } else {
            $this->securityLogger->logSecurityEvent(
              "Potentially malicious SQL pattern detected in full response: " . implode(', ', $validation['issues']),
              'warning',
              ['query' => $query]
            );
          }
        }
      }
    }

    return $queries;
  }

  /**
   * Resolves placeholders in SQL queries with their actual values
   * Replaces [placeholder] syntax with corresponding values
   * Handles common placeholders like language_id
   *
   * @param string $sqlQuery SQL query with placeholders
   * @return string SQL query with resolved placeholders
   */
  private function resolvePlaceholders(string $sqlQuery): string
  {
    // Validate input
    $safeSqlQuery = InputValidator::validateParameter($sqlQuery, 'string');

    if ($safeSqlQuery !== $sqlQuery) {
      $this->securityLogger->logSecurityEvent(
        "SQL query sanitized in resolvePlaceholders",
        'warning'
      );

      $sqlQuery = $safeSqlQuery;
    }

    // Detect placeholders in the format [placeholder_name]
    preg_match_all('/\[([^\]]+)\]/', $sqlQuery, $matches);

    if (empty($matches[1])) {
      return $sqlQuery;
    }

    $placeholders = array_unique($matches[1]);
    $resolvedQuery = $sqlQuery;

    foreach ($placeholders as $placeholder) {
      $value = $this->getPlaceholderValue($placeholder);

      if ($value === null) {
        $this->securityLogger->logSecurityEvent(
          "Unknown placeholder encountered: [{$placeholder}]",
          'warning'
        );
        $value = "'UNKNOWN_PLACEHOLDER_{$placeholder}'"; // Descriptive default value
      }

      $resolvedQuery = str_replace("[$placeholder]", $value, $resolvedQuery);
    }

    return $resolvedQuery;
  }

  /**
   * Gets the value for a specific placeholder
   * Maps common placeholders to their corresponding values
   * Provides fallback value for unknown placeholders
   * Logs unknown placeholders when debug mode is enabled
   *
   * @param string $placeholder Name of the placeholder to resolve
   * @return string Value to replace the placeholder
   */
  private function getPlaceholderValue(string $placeholder): string
  {
    // Map common placeholders to their values
    $placeholderMap = [
      'language_id' => $this->languageId,
      // Add other mappings as needed
    ];

    if (isset($placeholderMap[$placeholder])) {
      return $placeholderMap[$placeholder];
    }

   // Log unknown placeholders
    $this->securityLogger->logSecurityEvent(
      "Unknown placeholder encountered: [{$placeholder}]",
      'info'
    );

    if ($this->debug == 'True') {
      $this->securityLogger->logSecurityEvent("Placeholder unknown: [$placeholder]", 'error');
    }
    
    // Valeur par défaut pour les placeholders inconnus
    return '1'; // Valeur sécuritaire par défaut
  }

  /**
   * Validates SQL syntax and identifies potential issues
   * Checks for:
   * - Balanced parentheses
   * - Required SQL clauses
   * - Valid column aliases
   * - Unresolved placeholders
   *
   * @param string $sqlQuery SQL query to validate
   * @return array Validation results with 'is_valid' boolean and array of 'issues'
   */
  private function validateSqlSyntax(string $sqlQuery): array {
    $issues = [];
    
    // Check the balance of parentheses
    $openParenCount = substr_count($sqlQuery, '(');
    $closeParenCount = substr_count($sqlQuery, ')');

    if ($openParenCount !== $closeParenCount) {
      $issues[] = CLICSHOPPING::getDef('text_parentheses_mismatch_error', ['openParenCount' => $openParenCount, 'closeParenCount' => $closeParenCount]);
    }
    
    // Check essential clauses for SELECT
    if (stripos($sqlQuery, 'SELECT') === 0) {
      if (stripos($sqlQuery, 'FROM') === false) {
        $issues[] = CLICSHOPPING::getDef('text_select_without_from_error');
      }
    }
    
    // Check for invalid column aliases
    if (preg_match('/\bAS\s+\w+\.\w+/i', $sqlQuery)) {
      $issues[] = CLICSHOPPING::getDef('text_invalid_column_alias_error');
    }
    
   // Check for unresolved placeholders
    if (preg_match('/\[[^\]]+\]/', $sqlQuery)) {
      $issues[] =  CLICSHOPPING::getDef('text_unresolved_placeholders_error');;
    }
    
    return [
      'is_valid' => empty($issues),
      'issues' => $issues
    ];
  }

  /**
   * Applies conservative corrections to SQL queries based on detected issues
   * Only applies corrections with high confidence (>=0.8)
   * Maintains correction log for tracking changes
   *
   * @param string $sqlQuery SQL query to correct
   * @param array $detectedIssues Array of issues found in the query
   * @return string Corrected SQL query
   */
  private function applyConservativeCorrections(string $sqlQuery, array $detectedIssues): string {
    // Validate input
    $safeSqlQuery = InputValidator::validateParameter($sqlQuery, 'string');

    if ($safeSqlQuery !== $sqlQuery) {
      $this->securityLogger->logSecurityEvent(
        "SQL query sanitized in applyConservativeCorrections",
        'warning'
      );
      $sqlQuery = $safeSqlQuery;
    }

    $correctedQuery = $sqlQuery;
    $this->correctionLog = [];

    foreach ($detectedIssues as $issue) {
      $correction = $this->determineCorrection($sqlQuery, $issue);

      if ($correction['confidence'] >= 0.8) { // Seuil de confiance élevé
        $correctedQuery = $correction['query'];
        $this->correctionLog[] = [
          'issue' => $issue,
          'correction' => $correction['description'],
          'confidence' => $correction['confidence']
        ];

        $this->securityLogger->logSecurityEvent(
          "Applied SQL correction: " . $correction['description'],
          'info',
          ['original' => $sqlQuery, 'corrected' => $correction['query']]
        );
      } else {
        // Journaliser les corrections de faible confiance sans les appliquer
        $this->correctionLog[] = [
          'issue' => $issue,
          'correction' => CLICSHOPPING::getDef('text_no_applied_confidence'),
          'confidence' => $correction['confidence']
        ];

        $this->securityLogger->logSecurityEvent(
          "Low confidence correction not applied: " . $correction['description'],
          'info',
          ['confidence' => $correction['confidence']]
        );
      }
    }

    return $correctedQuery;
  }

  /**
   * Determines the appropriate correction for a specific issue
   * Handles different types of issues:
   * - Parentheses mismatches
   * - Invalid column aliases
   * - Unresolved placeholders
   * Returns correction details with confidence level
   *
   * @param string $sqlQuery SQL query with issues
   * @param string $issue Description of the issue to correct
   * @return array Correction details including:
   *               - query: corrected query string
   *               - description: explanation of correction
   *               - confidence: confidence level (0.0-1.0)
   */
  private function determineCorrection(string $sqlQuery, string $issue): array
  {
    // Initialize with default values
    $correction = [
      'query' => $sqlQuery,
      'description' => CLICSHOPPING::getDef('text_no_correction_applied'),
      'confidence' => 0.0
    ];

    // Correct unbalanced parentheses
    if (strpos($issue, ' Parentheses mismatch') === 0) {
      preg_match('/(\d+) opening vs (\d+) closing/', $issue, $matches);
      $openCount = (int)$matches[1];
      $closeCount = (int)$matches[2];

      if ($openCount > $closeCount) {
        // Add missing closing parentheses
        $correctedQuery = $sqlQuery . str_repeat(')', $openCount - $closeCount);
        $correction = [
          'query' => $correctedQuery,
          'description' => "Added: " . ($openCount - $closeCount) . " closing parenthesis",
          'confidence' => 0.9
        ];
      } elseif ($closeCount > $openCount) {
        // Remove excess closing parentheses
        $correctedQuery = $sqlQuery;

        for ($i = 0; $i < $closeCount - $openCount; $i++) {
          $pos = strrpos($correctedQuery, ')');
          if ($pos !== false) {
            $correctedQuery = substr($correctedQuery, 0, $pos) . substr($correctedQuery, $pos + 1);
          }
        }
        $correction = [
          'query' => $correctedQuery,
          'description' => "Removed: " . ($closeCount - $openCount) . " excess closing parenthesis",
          'confidence' => 0.8
        ];
      }
    } // Fix invalid column aliases
    elseif (strpos($issue, 'Invalid column alias') === 0) {
      $correctedQuery = preg_replace_callback('/\bAS\s+(\w+)\.(\w+)/i', function ($matches) {
        return 'AS ' . $matches[2]; // Use only the part after the dot
      }, $sqlQuery);

      $correction = [
        'query' => $correctedQuery,
        'description' => CLICSHOPPING::getDef('text_correction_invalid_column_aliases'),
        'confidence' => 0.95
      ];
    } // Fix unresolved placeholders
    elseif (strpos($issue, 'Unresolved placeholders') === 0) {
      $correctedQuery = $this->resolvePlaceholders($sqlQuery);

      $correction = [
        'query' => $correctedQuery,
        'description' => CLICSHOPPING::getDef('text_resolution_placeholders'),
        'confidence' => 0.9
      ];
    }

    return $correction;
  }

  /**
   * Estimates the confidence score for a corrected SQL query
   * Compares the original and corrected queries to determine confidence
   * Uses length and number of corrections as indicators
   *
   * @param string $originalQuery Original SQL query before corrections
   * @param string $correctedQuery Corrected SQL query after modifications
   * @return int Confidence score (0-100)
   */
  private function estimateConfidenceScore(string $originalQuery, string $correctedQuery): int
  {
   // Compare the length and the number of corrections made
    $originalLength = strlen($originalQuery);
    $correctedLength = strlen($correctedQuery);

    $diff = abs($originalLength - $correctedLength);

    // Little difference big confidence
    if ($diff < 10) {
      return 95;
    }
    if ($diff < 50) {
      return 85;
    }

    // If big difference, medium confidence
    return 70;
  }

  /**
   * Attempts to recover from SQL execution errors
   * Handles specific error types:
   * - Unknown column errors
   * - Syntax errors
   * Implements recovery strategies and returns execution results
   *
   * @param \Exception $error The exception that occurred
   * @param string $failedQuery The query that failed
   * @param string $originalQuery The original query before corrections
   * @return array Recovery result containing:
   *               - success: boolean indicating recovery success
   *               - data: array with error details, queries, and results
   */
  private function attemptErrorRecovery(\Exception $error, string $failedQuery, string $originalQuery): array
  {
    $errorMessage = $error->getMessage();

    $this->securityLogger->logSecurityEvent(
      "Attempting to recover from SQL error: " . $errorMessage,
      'info',
      ['failed_query' => $failedQuery]
    );

    $result = [
      'success' => false,
      'data' => [
        'error' => $errorMessage,
        'original_query' => $originalQuery,
        'failed_query' => $failedQuery
      ]
    ];

    if (preg_match('/Unknown column|Unknown table/i', $errorMessage) || preg_match('/syntax error|You have an error in your SQL syntax/i', $errorMessage)) {
      return $this->attemptRecoveryWithRollback($failedQuery, $originalQuery);
    } elseif (strpos($errorMessage, 'is not in GROUP BY clause') !== false) {
      // Spécial traitement GROUP BY
      $correctedQuery = $this->correctGroupByError($failedQuery, $errorMessage);

      if ($correctedQuery !== $failedQuery) {
        return $this->retryCorrectedQuery($correctedQuery, $originalQuery, $failedQuery, "Group By clause corrected");
      }
    }

    return $result;
  }

/**
   * Attempts to recover from SQL execution errors with rollback
   * Handles specific error types and applies multiple correction attempts
   * Returns execution results or error details
   *
   * @param string $failedQuery The query that failed
   * @param string $originalQuery The original query before corrections
   * @return array Recovery result containing:
   *               - success: boolean indicating recovery success
   *               - data: array with error details, queries, and results
   */
  private function attemptRecoveryWithRollback(string $failedQuery, string $originalQuery): array
  {
    $this->securityLogger->logSecurityEvent(
      "First correction attempt",
      'info',
      ['failed_query' => $failedQuery]
    );

    $firstAttempt = $this->retryCorrectedQuery($failedQuery, $originalQuery, $failedQuery, "First correction attempt");

    if ($firstAttempt['success']) {
      return $firstAttempt;
    }

    $this->securityLogger->logSecurityEvent(
      "First correction failed, trying a second correction attempt",
      'warning'
    );

    // Deuxième tentative : peut-être re-corriger encore une fois
    $secondCorrection = $this->correctSyntaxError($failedQuery, "second_attempt");

    if ($secondCorrection !== $failedQuery) {
      $secondAttempt = $this->retryCorrectedQuery($secondCorrection, $originalQuery, $failedQuery, "Second correction attempt");

      if ($secondAttempt['success']) {
        return $secondAttempt;
      }
    }

    $this->securityLogger->logSecurityEvent(
      "Both correction attempts failed. Rolling back.",
      'error'
    );

    return [
      'success' => false,
      'data' => [
        'error' => "Automatic correction failed after two attempts. Please revise your query.",
        'original_query' => $originalQuery,
        'failed_query' => $failedQuery,
        'recovery' => 'rollback',
        'confidence_score' => 0.0
      ]
    ];
  }


  /**
   * Corrects syntax errors in SQL queries
   * Handles specific error messages and applies corrections
   * Returns the corrected SQL query
   *
   * @param string $query The SQL query to correct
   * @param string $errorMessage The error message from the failed query execution
   * @return string The corrected SQL query
   */
  private function correctGroupByError(string $query, string $errorMessage): string
  {
    // Extraire la colonne manquante à ajouter au GROUP BY
    if (preg_match("/Unknown column '([^']+)' in 'group statement'/i", $errorMessage, $matches) ||
      preg_match("/.*?column '([^']+)' in 'group by clause'/i", $errorMessage, $matches)) {

      $missingColumn = $matches[1] ?? '';

      if (!empty($missingColumn)) {
        $this->correctionLog[] = "Added missing column to GROUP BY: {$missingColumn}";

        // Ajouter automatiquement la colonne au GROUP BY
        $query = preg_replace_callback('/GROUP BY (.+?)\s+ORDER BY/si', function ($match) use ($missingColumn) {
          $currentGroupBy = trim($match[1]);

          // Éviter d'ajouter 2 fois la même colonne
          if (stripos($currentGroupBy, $missingColumn) === false) {
            $newGroupBy = $currentGroupBy . ', ' . $missingColumn;
            return 'GROUP BY ' . $newGroupBy . ' ORDER BY';
          }

          return $match[0]; // Rien à faire si déjà présent
        }, $query);

        return $query;
      }
    }

    return $query;
  }

/**
   * Retries the SQL query with the corrected version
   * Logs the recovery attempt and its outcome
   * Returns the result of the executed query or error details
   *
   * @param string $correctedQuery The corrected SQL query to execute
   * @param string $originalQuery The original SQL query before corrections
   * @param string $failedQuery The failed SQL query that triggered recovery
   * @param string $recoveryMessage Message describing the recovery action taken
   * @return array Result of the executed query or error details
   */
  private function retryCorrectedQuery(string $correctedQuery, string $originalQuery, string $failedQuery, string $recoveryMessage): array
  {
    try {
      $secureResult = $this->dbSecurity->executeSecureQuery($correctedQuery, [], $this->userId);

      if ($secureResult['success']) {
        $this->securityLogger->logSecurityEvent(
          "Successfully recovered: {$recoveryMessage}",
          'info',
          ['corrected_query' => $correctedQuery]
        );

        return [
          'success' => true,
          'data' => [
            'original_query' => $originalQuery,
            'failed_query' => $failedQuery,
            'executed_query' => $correctedQuery,
            'results' => $secureResult['data'] ?? [],
            'count' => $secureResult['row_count'] ?? 0,
            'corrections' => $this->correctionLog,
            'recovery' => $recoveryMessage,
            'confidence_score' => $this->estimateConfidenceScore($originalQuery, $correctedQuery),
            'message' => 'The request has been corrected automatically'
          ]
        ];
      } else {
        $this->securityLogger->logSecurityEvent(
          "Recovery attempt failed: " . ($secureResult['error'] ?? 'Unknown error'),
          'warning'
        );

        return [
          'success' => false,
          'data' => [
            'error' => $secureResult['error'] ?? 'Unknown error during recovery',
            'original_query' => $originalQuery,
            'failed_query' => $failedQuery
          ]
        ];
      }
    } catch (\Exception $recoveryError) {
      $this->securityLogger->logSecurityEvent(
        "Recovery attempt failed: " . $recoveryError->getMessage(),
        'warning'
      );

      return [
        'success' => false,
        'data' => [
          'error' => $recoveryError->getMessage(),
          'original_query' => $originalQuery,
          'failed_query' => $failedQuery
        ]
      ];
    }
  }


  /**
   * Corrects unknown column errors in SQL queries
   * Handles both aliased (table.column) and non-aliased column references
   * Attempts to find similar column names and correct table aliases
   *
   * @param string $sqlQuery Original SQL query containing unknown column
   * @param string $unknownColumn Name of the unknown column to correct
   * @return string Corrected SQL query or original if no correction possible
   */
  private function correctUnknownColumn(string $sqlQuery, string $unknownColumn): string {
    // Check if the column contains a dot (alias.column)
    if (strpos($unknownColumn, '.') !== false) {
      list($alias, $column) = explode('.', $unknownColumn);

      // Look for a similar column in the schema's tables
      $similarColumn = $this->findSimilarColumn($column);

      if ($similarColumn !== $column) {
        $correctedQuery = str_replace($unknownColumn, "$alias.$similarColumn", $sqlQuery);
        $this->correctionLog[] = "Column '$unknownColumn' corrected in '$alias.$similarColumn'";
        return $correctedQuery;
      }

      // If no similar column is found, try to correct the alias
      $tables = $this->extractTablesFromQuery($sqlQuery);
      $correctAlias = $this->findCorrectAlias($alias, array_keys($tables));

      if ($correctAlias !== $alias) {
        $correctedQuery = str_replace("$alias.$column", "$correctAlias.$column", $sqlQuery);
        $this->correctionLog[] = "Alias '$alias' corrected in '$correctAlias'";
        return $correctedQuery;
      }
    } else {
      // Column without alias
      $similarColumn = $this->findSimilarColumn($unknownColumn);

      if ($similarColumn !== $unknownColumn) {
        // Find the tables that contain this column
        $tables = $this->findTablesWithColumn($similarColumn);

        if (!empty($tables)) {
          // Extract table aliases from the query
          $queryTables = $this->extractTablesFromQuery($sqlQuery);

          // Look for a common table
          $commonTables = array_intersect($tables, array_values($queryTables));

          if (!empty($commonTables)) {
            $table = reset($commonTables);
            $alias = array_search($table, $queryTables);

            if ($alias) {
              $correctedQuery = str_replace($unknownColumn, "$alias.$similarColumn", $sqlQuery);
              $this->correctionLog[] = "Column '$unknownColumn' corrected in '$alias.$similarColumn'";
              return $correctedQuery;
            }
          }
        }

        // If no common table is found, simply replace the column name
        $correctedQuery = str_replace($unknownColumn, $similarColumn, $sqlQuery);
        $this->correctionLog[] = "Column '$unknownColumn' corrected in '$similarColumn'";
        return $correctedQuery;
      }
    }

    // If no correction is possible, return the original query
    return $sqlQuery;
  }

  /**
   * Corrects syntax errors in SQL queries
   * Handles common syntax issues including:
   * - Consecutive commas
   * - Malformed comparison operators
   * - Invalid WHERE/GROUP BY/ORDER BY clauses
   * - Unresolved placeholders
   *
   * @param string $sqlQuery SQL query with syntax error
   * @param string $errorMessage Error message from database
   * @return string Corrected SQL query
   */
  private function correctSyntaxError(string $sqlQuery, string $errorMessage): string {
    // Extract the problematic part of the query
    preg_match("/near '([^']+)'/", $errorMessage, $matches);

    if (empty($matches[1])) {
      return $sqlQuery;
    }

    $problematicPart = $matches[1];
    $correctedQuery = $sqlQuery;

    // Correct common syntax errors

    // 1. Correct consecutive commas
    $correctedQuery = preg_replace('/,\s*,/', ',', $correctedQuery);

    // 2. Correct malformed comparison operators
    $correctedQuery = preg_replace('/\s+=\s+=\s+/', ' = ', $correctedQuery);

    // 3. Correct malformed WHERE clauses
    if (strpos($problematicPart, 'WHERE') === 0) {
      $correctedQuery = preg_replace('/\bWHERE\s+AND\b/i', 'WHERE', $correctedQuery);
      $correctedQuery = preg_replace('/\bWHERE\s+OR\b/i', 'WHERE', $correctedQuery);
    }

    // 4. Correct malformed GROUP BY clauses
    if (strpos($problematicPart, 'GROUP BY') === 0) {
      $correctedQuery = preg_replace('/\bGROUP\s+BY\s+,/i', 'GROUP BY', $correctedQuery);
    }

    // 5. Correct malformed ORDER BY clauses
    if (strpos($problematicPart, 'ORDER BY') === 0) {
      $correctedQuery = preg_replace('/\bORDER\s+BY\s+,/i', 'ORDER BY', $correctedQuery);
    }

    // 6. Correct unresolved placeholders
    if (strpos($problematicPart, '[') !== false) {
      $correctedQuery = $this->resolvePlaceholders($correctedQuery);
    }

    // If the query has not been modified, try a more generic approach
    if ($correctedQuery === $sqlQuery) {
      // Remove the problematic part and everything following it
      $pos = strpos($sqlQuery, $problematicPart);
      if ($pos !== false) {
        // Find the previous clause
        $previousClauses = ['SELECT', 'FROM', 'WHERE', 'GROUP BY', 'HAVING', 'ORDER BY', 'LIMIT'];
        $lastClausePos = 0;
        $lastClause = '';

        foreach ($previousClauses as $clause) {
          $clausePos = stripos($sqlQuery, $clause, 0);
          if ($clausePos !== false && $clausePos < $pos && $clausePos > $lastClausePos) {
            $lastClausePos = $clausePos;
            $lastClause = $clause;
          }
        }

        // Find the next clause
        $nextClausePos = PHP_INT_MAX;

        foreach ($previousClauses as $clause) {
          $clausePos = stripos($sqlQuery, $clause, $pos + strlen($problematicPart));
          if ($clausePos !== false && $clausePos < $nextClausePos) {
            $nextClausePos = $clausePos;
          }
        }

        if ($nextClausePos !== PHP_INT_MAX) {
          // Replace the problematic part up to the next clause
          $correctedQuery = substr($sqlQuery, 0, $pos) . substr($sqlQuery, $nextClausePos);
          $this->correctionLog[] = CLICSHOPPING::getDef('text_problematic_part_removed', ['problematicPart' => $problematicPart]);
        } else {
          // Remove the problematic part and everything following it
          $correctedQuery = substr($sqlQuery, 0, $pos);
          $this->correctionLog[] = CLICSHOPPING::getDef('text_problematic_part_everything_following_removed', ['problematicPart' => $problematicPart]);
        }
      }
    }

    return $correctedQuery;
  }

  /**
   * Finds a similar column name in the database schema
   * Uses text similarity matching and common prefix/suffix analysis
   * Returns original column name if no similar column found
   * Requires similarity threshold > 70% for matches
   *
   * @param string $column Column name to find similar for
   * @return string Similar column name or original if none found
   */
  private function findSimilarColumn(string $column): string {
    // Check if the column already exists in the index
    if (isset($this->columnIndex[$column])) {
      return $column;
    }

    // Search by textual similarity
    $bestMatch = '';
    $maxSimilarity = 0;

    foreach (array_keys($this->columnIndex) as $existingColumn) {
      $similarity = similar_text($column, $existingColumn, $percent);

      if ($percent > $maxSimilarity) {
        $maxSimilarity = $percent;
        $bestMatch = $existingColumn;
      }
    }

    // Return the most similar column if the similarity is sufficient
    if ($maxSimilarity > 70) {
      return $bestMatch;
    }

    // Search by common prefix/suffix
    foreach (array_keys($this->columnIndex) as $existingColumn) {
      // Check if the existing column contains the searched column
      if (strpos($existingColumn, $column) !== false) {
        return $existingColumn;
      }

      // Check if the searched column contains the existing column
      if (strpos($column, $existingColumn) !== false && strlen($existingColumn) > 3) {
        return $existingColumn;
      }
    }

    // If no similar column is found, return the original column
    return $column;
  }

  /**
   * Finds tables that contain a specific column
   * Uses columnIndex to lookup tables containing the column
   * Returns empty array if column not found in any table
   *
   * @param string $column Column name to search for
   * @return array Array of table names containing the column
   */
  private function findTablesWithColumn(string $column): array
  {
    if (isset($this->columnIndex[$column])) {
      return $this->columnIndex[$column];
    }

    return [];
  }

  /**
   * Finds the correct alias for a table in a query
   * Uses text similarity matching to find the best match
   * Requires similarity threshold > 70% for matches
   *
   * @param string $alias Alias to correct
   * @param array $availableAliases List of valid aliases in the query
   * @return string Corrected alias or original if no match found
   */
  private function findCorrectAlias(string $alias, array $availableAliases): string
  {
    // If the alias is already in the list, return it as is
    if (in_array($alias, $availableAliases)) {
      return $alias;
    }

    // Search by textual similarity
    $bestMatch = '';
    $maxSimilarity = 0;

    foreach ($availableAliases as $availableAlias) {
      $similarity = similar_text($alias, $availableAlias, $percent);

      if ($percent > $maxSimilarity) {
        $maxSimilarity = $percent;
        $bestMatch = $availableAlias;
      }
    }

    // Return the most similar alias if the similarity is sufficient
    if ($maxSimilarity > 70) {
      return $bestMatch;
    }

    // If no similar alias is found, return the original alias
    return $alias;
  }

  /**
   * Extracts tables and their aliases from a SQL query
   * Parses FROM clause and JOIN statements
   * Handles AS keyword in alias definitions
   *
   * @param string $sqlQuery SQL query to analyze
   * @return array Associative array where keys are aliases and values are table names
   */
  private function extractTablesFromQuery(string $sqlQuery): array
  {
    $tables = [];

    // Extract the main table in FROM
    if (preg_match('/FROM\s+(\w+)(?:\s+(?:AS\s+)?(\w+))?/i', $sqlQuery, $matches)) {
      $tableName = $matches[1];
      $alias = !empty($matches[2]) ? $matches[2] : $tableName;
      $tables[$alias] = $tableName;
    }

    // Extract tables in JOINs
    preg_match_all('/JOIN\s+(\w+)(?:\s+(?:AS\s+)?(\w+))?/i', $sqlQuery, $joinMatches, PREG_SET_ORDER);

    foreach ($joinMatches as $match) {
      $tableName = $match[1];
      $alias = !empty($match[2]) ? $match[2] : $tableName;
      $tables[$alias] = $tableName;
    }

    return $tables;
  }

  /**
   * Generates a suggestion for fixing an error
   * Provides context-specific suggestions based on error type
   * Includes suggestions for:
   * - Unknown column errors
   * - Syntax errors
   * - Missing table errors
   *
   * @param string $errorMessage Error message from database
   * @param string $question Original question that generated the query
   * @return string User-friendly suggestion for fixing the error
   */
  private function generateErrorSuggestion(string $errorMessage, string $question): string
  {
    // Suggestions based on the type of error
    if (strpos($errorMessage, 'Unknown column') !== false) {
      return CLICSHOPPING::getDef('text_colum_reference_does_not_exist');
    }

    if (strpos($errorMessage, 'syntax error') !== false) {
      return CLICSHOPPING::getDef('text_sql_query_generated_error');
    }

    if (strpos($errorMessage, 'Table') !== false && strpos($errorMessage, 'doesn\'t exist') !== false) {
      return CLICSHOPPING::getDef('text_table_referenced_does_not_exist');
    }

    // Generic suggestion
    return CLICSHOPPING::getDef('text_error_executing_query');
  }

  /**
   * Executes the generated SQL query and handles errors
   * Implements error recovery mechanisms
   * Logs errors when debug mode is enabled
   * Provides fallback responses on complete failure
   *
   * @param string $question The business question in natural language
   * @return array Results array containing:
   *               - type: 'success' or 'error'
   *               - message: Result message or error description
   *               - query: Original question
   *               - suggestion: Error fix suggestion if applicable
   *               - recovery_attempted: Boolean indicating if recovery was attempted
   */
  public function executeQuery(string $question): array
  {
    // Check rate limiting
    if (!$this->rateLimit->checkLimit($this->userId)) {
      $this->securityLogger->logSecurityEvent(
        "Rate limit exceeded for user {$this->userId} in executeQuery",
        'warning'
      );

      return [
        'type' => 'error',
        'message' => 'Rate limit exceeded. Please try again later.',
        'query' => $question,
        'error_code' => 'RATE_LIMIT_EXCEEDED'
      ];
    }

    // Validate input
    $safeQuestion = InputValidator::validateParameter($question, 'string');

    if ($safeQuestion !== $question) {
      $this->securityLogger->logSecurityEvent(
        "Question sanitized in executeQuery",
        'warning'
      );
      $question = $safeQuestion;
    }

    try {
      // Main attempt
      return $this->processAnalyticsQuery($question);
    } catch (\Exception $e) {
      // Log the error
      if ($this->debug == 'True') {
        $this->securityLogger->logSecurityEvent("Query execution error: " . $e->getMessage(), 'error');
      }

      // Fallback response in case of complete failure
      return [
        'type' => 'error',
        'message' => $e->getMessage(),
        'query' => $question,
        'suggestion' => $this->generateErrorSuggestion($e->getMessage(), $question),
        'recovery_attempted' => true
      ];
    }
  }

  /**
   * Validates SQL syntax using SqlSecurity class
   * Logs security events for invalid syntax
   *
   * @param array $validation
   * @param string $query
   * @return bool True if valid, false otherwise
   */
  private function isSqlSyntaxValid(array $validation, string $query): bool
  {
    if (!$validation['valid']) {
      $this->securityLogger->logSecurityEvent(
        "Rejected query due to invalid SQL syntax (parse failure)",
        'warning',
        ['query' => $query]
      );
      return false;
    }
    return true;
  }

/**
   * Deduplicates rows in a result set
   * Uses a hash function to identify unique rows
   * Returns an array of unique rows
   *
   * @param array $rows Array of rows to deduplicate
   * @return array Array of unique rows
   */
  private function deduplicateRows(array $rows): array
  {
    $seen = [];
    $unique = [];

    foreach ($rows as $r) {
      $h = md5(json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
      if (!isset($seen[$h])) {
        $seen[$h] = true;
        $unique[] = $r;
      }
    }

    return $unique;
  }

  /**
   * Executes a query with error recovery mechanisms
   * Implements caching, query generation, validation, and error handling
   * Supports multiple query execution and result aggregation
   *
   * @param string $question The business question to process
   * @return array Results containing:
   *               - type: 'analytics_results'
   *               - query: Original question
   *               - sql_query: Executed SQL query
   *               - original_sql_query: Pre-correction SQL query
   *               - corrections: Array of applied corrections
   *               - results: Query results
   *               - count: Number of results
   * @throws \Exception When query execution fails after recovery attempts
   */
  private function processAnalyticsQuery(string $question): array
  {
    // Check rate limiting
    if (!$this->rateLimit->checkLimit($this->userId)) {
      $this->securityLogger->logSecurityEvent(
        "Rate limit exceeded for user {$this->userId} in processAnalyticsQuery",
        'warning'
      );

      return [
        'success' => false,
        'error' => 'Rate limit exceeded. Please try again later.',
        'error_code' => 'RATE_LIMIT_EXCEEDED'
      ];
    }

    try {
      // Check if the question is in the cache
      $cachedSql = null;
      if ($this->cache->isPromptInCache($question)) {
        $cachedSql = $this->cache->getCachedResponse($question);
      }

      // SQL query generation and cleaning
      if ($cachedSql !== null) {
        $sqlQueries = [$cachedSql];

        if ($this->debug == 'True') {
          $this->securityLogger->logSecurityEvent("Using cached SQL for question: " . substr($question, 0, 50) . "...", 'info');
        }
      } else {
        $rawResponse = $this->chat->generateText($question);

        // Extract SQL queries from the text
        $sqlQueries = $this->extractSqlQueries($rawResponse);

        if (empty($sqlQueries)) {
          // If no SQL query is found, use the traditional cleaning
          $sqlQueries = [$this->cleanSqlResponse($rawResponse)];
        }

        // Cache the first SQL query
        if (!empty($sqlQueries[0])) {
          $this->cache->cacheResponse($question, $sqlQueries[0]);
        }
      }

      if (empty($sqlQueries[0])) {
        throw new \Exception(CLICSHOPPING::getDef('text_no_valid_sql_query_could_extracted'));
      }

      $results = [];
      $this->correctionLog = [];

      foreach ($sqlQueries as $sqlQuery) {
        // Resolve placeholders
        $resolvedQuery = $this->resolvePlaceholders($sqlQuery);

        // Syntax validation
        $validation = InputValidator::validateSqlQuery($resolvedQuery);

        if (!$this->isSqlSyntaxValid($validation, $resolvedQuery)) {
          continue;
        }

        if (!$validation['valid']) {
          // Attempt correction
          $correctedQuery = $this->applyConservativeCorrections($resolvedQuery, $validation['issues']);

          // Re-validation after correction
          $revalidation = InputValidator::validateSqlQuery($correctedQuery);

          if (!$this->isSqlSyntaxValid($revalidation, $correctedQuery)) {
            continue;
          }

          if (!$revalidation['valid']) {
            // If still invalid, use the original query
            $finalQuery = $sqlQuery;
            $this->correctionLog[] = CLICSHOPPING::getDef('text_correction_cancelled');
          } else {
            $finalQuery = $correctedQuery;
          }
        } else {
          $finalQuery = $resolvedQuery;
        }

        // Execute the query
        try {
          // ① inject DISTINCT to remove duplication
          if (preg_match('/^\s*SELECT\s+/i', $finalQuery)) {
            $finalQuery = preg_replace(
              '/^\s*SELECT\s+/i',
              'SELECT DISTINCT ',
              $finalQuery,
              1
            );
          }

          $query = $this->db->prepare($finalQuery);

          if (!$query) {
            throw new \Exception("Failed to prepare query.");
          }

          if ($this->debug === 'True') {
            $this->logExplainPlan($finalQuery);
          }

          $query->execute();
          // ② fetch associative only
          $rows = $query->fetchAll(\PDO::FETCH_ASSOC);

          $queryResults = $this->deduplicateRows($rows);

          $results[] = [
            'original_query' => $sqlQuery,
            'executed_query' => $finalQuery,
            'results' => $queryResults,
            'count' => count($queryResults),
            'corrections' => $this->correctionLog
          ];
        } catch (\Exception $e) {
          // Try to recover from error
          $recoveryResult = $this->attemptErrorRecovery($e, $finalQuery, $sqlQuery);

          if ($recoveryResult['success']) {
            $results[] = $recoveryResult['data'];

            $this->securityLogger->logSecurityEvent(
              "Analytics query recovered successfully for user {$this->userId}",
              'info',
              ['original_error' => $e->getMessage()]
            );
          } else {
            throw new \Exception("Execution failed after recovery attempt:" . $e->getMessage());
          }
        }
      }

      // If only one query was executed, return a format compatible with the existing one
      if (count($results) === 1) {
        return [
          'type' => 'analytics_results',
          'query' => $question,
          'sql_query' => $results[0]['executed_query'],
          'original_sql_query' => $results[0]['original_query'],
          'corrections' => $results[0]['corrections'],
          'results' => $results[0]['results'],
          'count' => $results[0]['count']
        ];
      }

      // Otherwise, return multiple results
      return [
        'type' => 'analytics_results',
        'query' => $question,
        'multi_query_results' => $results,
        'count' => count($results)
      ];
    } catch (\Exception $e) {
      // Propagate the exception for higher-level error handling
      throw $e;
    }
  }

  /**
   * Logs the EXPLAIN plan for a SQL query
   * Uses error_log for debugging purposes
   *
   * @param string $sql SQL query to explain
   */
  private function logExplainPlan(string $sql): void
  {
    try {
      $stmt = $this->db->prepare('EXPLAIN ' . $sql);
      $stmt->execute();
      $plan = $stmt->fetchAll(\PDO::FETCH_ASSOC);
      $this->securityLogger->logSecurityEvent("EXPLAIN PLAN for SQL:\n" . $sql, 'info');

      foreach ($plan as $row) {
        $this->securityLogger->logSecurityEvent(print_r($row, true), 'info');
      }
    } catch (\Exception $e) {
      $this->securityLogger->logSecurityEvent("Failed to EXPLAIN query: " . $e->getMessage(), 'error');
    }
  }

  /**
   * Gets the schema for a specific table
   * Uses caching to improve performance
   * Handles table name validation and error logging
   *
   * @param string $table Table name to get schema for
   * @return array Associative array where keys are column names and values are column types
   */
  private function getTableSchema(string $table): array
  {
    // Validate table name
    $safeTable = InputValidator::sanitizeIdentifier($table);

    if ($safeTable !== $table) {
      $this->securityLogger->logSecurityEvent(
        "Suspicious table name sanitized in getTableSchema: {$table} -> {$safeTable}",
        'warning'
      );
      $table = $safeTable;
    }

    // Check cache first
    if (isset($this->tableSchemaCache[$table])) {
      return $this->tableSchemaCache[$table];
    }

    try {
      // Retrieve the table schema
      $query = $this->db->prepare("DESCRIBE " . $table);
      $query->execute();
      $columns = $query->fetchAll(\PDO::FETCH_ASSOC);

      $schema = [];
      foreach ($columns as $column) {
        $schema[$column['Field']] = $column['Type'];
      }

      // Cache the schema
      $this->tableSchemaCache[$table] = $schema;

      return $schema;
    } catch (\Exception $e) {
      $this->securityLogger->logSecurityEvent(
        "Error getting schema for table {$table}: " . $e->getMessage(),
        'error'
      );

      if ($this->debug) {
        $this->securityLogger->logSecurityEvent("Error getting schema for table {$table}: " . $e->getMessage(), 'error');
      }

      return [];
    }
  }

  /**
   * Processes a complete business query including SQL generation, execution, and interpretation
   * Handles multiple query results and provides natural language interpretation
   * Includes error handling and recovery mechanisms
   *
   * @param string $question The business question in natural language
   * @param bool $includeSQL Whether to include SQL queries in the response (default: true)
   * @return array Response containing:
   *               - type: 'analytics_response' or 'error'
   *               - question: Original question
   *               - interpretation: Natural language interpretation of results
   *               - count: Number of results
   *               - sql_query: Executed SQL (if includeSQL is true)
   *               - results: Query results
   *               - corrections: Any applied corrections
   */
  public function processBusinessQuery(string $question, bool $includeSQL = true): array
  {
    try {
      $results = $this->executeQuery($question);

      if ($results['type'] === 'error') {
        return $results;
      }

      // Ajuster pour gérer plusieurs jeux de résultats
      if (isset($results['multi_query_results'])) {
        $allResults = [];
        foreach ($results['multi_query_results'] as $queryBlock) {
          foreach ($queryBlock['results'] as $item) {
            $allResults[] = $item;
          }
        }
        $interpretation = $this->interpretResults($question, $allResults);
      } else {
        $interpretation = $this->interpretResults($question, $results['results']);
      }

      // Construction de la réponse de base
      $response = [
        'type'           => 'analytics_response',
        'question'       => $question,
        'interpretation' => $interpretation,
        'count'          => $results['count']
      ];

      // Si plusieurs requêtes ont été executées, on les renvoie
      if (isset($results['multi_query_results'])) {
        if ($includeSQL) {
          $response['multi_query_results'] = $results['multi_query_results'];
        }
      } else {
        // On ajoute les résultats (toujours)
        $response['results'] = $results['results'];

        // On ajoute les SQL uniquement si demandé
        if ($includeSQL) {
          $response['sql_query']           = $results['sql_query'];
          $response['original_sql_query']  = $results['original_sql_query'] ?? $results['sql_query'];

          if (!empty($results['corrections'])) {
            $response['corrections'] = $results['corrections'];
          }
        }
      }

      return $response;
    } catch (\Exception $e) {
      // Log pour le mode debug
      if ($this->debug == 'True') {
        $this->securityLogger->logSecurityEvent(
          "Analytics Processing Error: " . $e->getMessage(),
          'error'
        );
      }

      return [
        'type'       => 'error',
        'message'    => 'Error processing business query: ' . $e->getMessage(),
        'question'   => $question,
        'suggestion'=> $this->generateErrorSuggestion($e->getMessage(), $question)
      ];
    }
  }

  /**
   * Interprets the results of a SQL query
   * Generates a natural language interpretation of the results
   * Uses caching to improve performance
   *
   * @param string $question The business question in natural language
   * @param array $results The results of the SQL query
   * @return string Natural language interpretation of the results
   */
  private function interpretResults(string $question, array $results): string
  {

    // Check if the number of rows exceeds the configured limit
    if (count($results) > $this->maxRowsForInterpretation) {
      // Generate a message indicating the result set is too large
      $array = [
        'maxRows' => $this->maxRowsForInterpretation,
        'count' => count($results)
      ];

      return CLICSHOPPING::getDef('text_error_context_sql_number_request', $array);
    }


    $cleanResults = $this->sanitizeResultsForPrompt($results);
    $safeQuestion = InputValidator::validateParameter($question, 'string');

    if ($safeQuestion !== $question) {
      $this->securityLogger->logSecurityEvent(
        "Question sanitized in generateSqlQuery",
        'warning'
      );
      $question = $safeQuestion;
    }

    $interpretCacheKey = "interpret_" . $this->cache->generateCacheKey($question . json_encode($cleanResults));

    // Check the cache with expiration logic
    if ($this->enablePromptCache && isset($this->promptCache[$interpretCacheKey])) {
      $cacheItem = $this->promptCache[$interpretCacheKey];

      if (time() < $cacheItem['ttl']) {
        if ($this->debug == 'True') {
          $this->securityLogger->logSecurityEvent("Using cached interpretation for question: " . substr($question, 0, 50) . "...", 'info');
        }

        $this->promptCache[$interpretCacheKey]['last_used'] = time();

        return $cacheItem['response'];
      } else {
        // Remove expired cache
        unset($this->promptCache[$interpretCacheKey]);
      }
    }

    $array = [
      'question' => $question,
      'results' => json_encode($cleanResults, JSON_PRETTY_PRINT)
    ];

    $prompt = CLICSHOPPING::getDef('text_interpret_results', $array);

    $interpretation = $this->chat->generateText($prompt);

    // Create the cache with expiration
    if ($this->enablePromptCache) {
      $this->promptCache[$interpretCacheKey] = [
        'prompt' => $prompt,
        'response' => $interpretation,
        'created' => time(),
        'last_used' => time(),
        'ttl' => time() + 3600 // Cache expires in 1 hour
      ];

      $this->cache->savePromptCache();
    }

    return $interpretation;
  }

  /**
   * Determines if a query is analytical in nature
   * Uses semantic analysis to classify query type
   * Checks against predefined analytical patterns
   *
   * @param string $query Query to analyze
   * @return bool True if query is analytical, false otherwise
   */
  public function isAnalyticsQuery(string $query): bool
  {
    $classifyQuery = Semantics::classifyQuery($query);

    if ($classifyQuery === 'analytics') {
      return true; // on retourne true si on match
    }

    return false; // aucun match → pas analytique
  }

  /**
   * Identifies the analytical categories of a query
   * Matches query against predefined pattern categories
   * Supports multiple category classification
   *
   * @param string $query Query to analyze
   * @return array List of matched analytical categories
   *               Returns empty array if no categories match
   */
  public function getAnalyticsCategories(string $query): array
  {
    $analyticsPatterns = Semantics::analyticsPatterns();
    $matchedCategories = [];

    foreach ($analyticsPatterns as $category => $patterns) {
      foreach ($patterns as $pattern) {
        if (preg_match($pattern, $query)) {
          $matchedCategories[] = $category;
          break; // éviter les doublons
        }
      }
    }

    return array_unique($matchedCategories);
  }
  
  /**
   * Cleans the SQL response by removing formatting tags
   * Removes SQL code block markers
   * Strips HTML tags
   * Trims whitespace
   *
   * @param string $response Raw response from the model
   * @return string Cleaned SQL query ready for execution
   */
  private function cleanSqlResponse(string $response): string
  {
    $cleaned = preg_replace('/```sql\s*|\s*```/', '', $response);
    $cleaned = strip_tags($cleaned);
    $cleaned = trim($cleaned);

    return $cleaned;
  }

/**
 * Sanitizes results for inclusion in a prompt
 * Handles nested arrays, objects, and various data types
 * Implements error handling and logging
 *
 * @param array $results Results to sanitize
 * @return array Sanitized results
 */
  private function sanitizeResultsForPrompt(array $results): array
  {
    $cleanedResults = [];

    foreach ($results as $rowKey => $row) {
      if (!is_array($row)) {
        // Simple encoding for scalar values
        $cleanedResults[$rowKey] = htmlspecialchars((string)$row, ENT_QUOTES, 'UTF-8');
        continue;
      }

      $cleanedRow = [];
      foreach ($row as $key => $value) {
        // Clean each cell value
        if (is_array($value)) {
          $cleanedRow[$key] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } elseif (is_object($value)) {
          $cleanedRow[$key] = '[object]';
        } else {
          $cleanedRow[$key] = htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
        }
      }
      $cleanedResults[$rowKey] = $cleanedRow;
    }

    return $cleanedResults;
  }
}
