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

use ClicShopping\OM\Registry;
use ClicShopping\OM\CLICSHOPPING;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\Gpt;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\Rag\Cache;

/**
* AnalyticsAgent Class
*
* This class uses LLPhant to create an intelligent agent capable
* of interpreting complex business queries and generating SQL queries
* for e-commerce data analysis.
*/
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

/**
* Constructor for AnalyticsAgent
*
* @param int|null $languageId Language ID for filtering results
* @param bool $enablePromptCache Whether to enable local prompt caching
*/
  public function __construct(?int $languageId = null, bool $enablePromptCache = true)
  {
    $this->db = Registry::get('Db');
    $this->languageId = $languageId ?? Registry::get('Language')->getId();
    $this->chat = Gpt::getOpenAiGpt(null);
    $this->cache = new Cache($enablePromptCache);
    $this->enablePromptCache = $enablePromptCache;
    $this->setSystemMessage();
    $this->initializeTableRelationships();
    $this->buildDatabaseSchema();
  }

 /**
 * Configures the system message for the LLPhant agent with improved instructions
 */
  private function setSystemMessage(): void
  {
    $baseSystemMessage = CLICSHOPPING::getDef('text_system_message', ['language_id' => $this->languageId]);
    $sqlFormatInstructions = CLICSHOPPING::getDef('text_sql_format_instructions', ['language_id' => $this->languageId]);
    $tableStructureInstructions = CLICSHOPPING::getDef('text_table_structure_instructions');

    $this->chat->setSystemMessage($baseSystemMessage . $sqlFormatInstructions . $tableStructureInstructions);
  }

 /**
  * Initializes table relationships based on database schema
  * This helps in determining which tables are related and how
  */
  private function initializeTableRelationships(): void
  {
    try {
      // Récupérer toutes les tables de la base de données
      $query = $this->db->prepare("SHOW TABLES");
      $query->execute();
      $tables = $query->fetchAll(\PDO::FETCH_COLUMN);
      
      // Pour chaque table, analyser les colonnes pour détecter les relations potentielles
      foreach ($tables as $table) {
        $schema = $this->getTableSchema($table);
        
        foreach ($schema as $column => $type) {
          // Détecter les colonnes d'ID qui pourraient être des clés étrangères
          if (preg_match('/_id$/', $column) && strpos($type, 'int') !== false) {
            $relatedTable = str_replace('_id', '', $column);
            
            // Vérifier si la table liée existe
            if (in_array($relatedTable, $tables) || in_array('clic_' . $relatedTable, $tables)) {
              $actualTable = in_array('clic_' . $relatedTable, $tables) ? 'clic_' . $relatedTable : $relatedTable;
              $this->tableRelationships[$table][$column] = $actualTable;
            }
          }
        }
      }
      
      // Construire un dictionnaire de synonymes de colonnes basé sur les noms similaires
      $this->buildColumnSynonyms($tables);
      
    } catch (\Exception $e) {
      if (defined('CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER == 'True') {
        error_log("Error initializing table relationships: " . $e->getMessage());
      }
    }
  }

/**
 * Builds a comprehensive database schema for validation and correction
 */
  private function buildDatabaseSchema(): void
  {
    try {
      // Récupérer toutes les tables de la base de données
      $query = $this->db->prepare("SHOW TABLES");
      $query->execute();
      $tables = $query->fetchAll(\PDO::FETCH_COLUMN);
      
      $this->databaseSchema = [];
      
      foreach ($tables as $table) {
        // Récupérer les colonnes de chaque table
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
    } catch (\Exception $e) {
      // Journaliser l'erreur
      if (defined('CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER == 'True') {
        error_log("Error while building the database schema: " . $e->getMessage());
      }
    }
  }

 /**
  * Builds a dictionary of column synonyms based on similar column names across tables
  * 
  * @param array $tables List of database tables
  */
  private function buildColumnSynonyms(array $tables): void
  {
    $allColumns = [];
    
    // Collecter toutes les colonnes de toutes les tables
    foreach ($tables as $table) {
      $schema = $this->getTableSchema($table);
      foreach ($schema as $column => $type) {
        if (!isset($allColumns[$column])) {
          $allColumns[$column] = [];
        }
        $allColumns[$column][] = $table;
      }
    }
    
    // Identifier les synonymes potentiels basés sur des parties communes de noms
    foreach ($allColumns as $column => $tables) {
      // Extraire la partie significative du nom de colonne (sans préfixes/suffixes communs)
      $baseName = preg_replace('/^(.*?)_|_(.*?)$/', '', $column);
      
      if (strlen($baseName) >= 3) { // Ignorer les noms trop courts
        if (!isset($this->columnSynonyms[$baseName])) {
          $this->columnSynonyms[$baseName] = [];
        }
        $this->columnSynonyms[$baseName][] = $column;
      }
    }
  }

 /**
  * Extracts valid SQL queries from text that may contain explanatory content
  *
  * @param string $response Raw response from the model
  * @return array Array of extracted SQL queries
  */
  private function extractSqlQueries(string $response): array {
    $queries = [];
    
    // Rechercher les requêtes SQL commençant par SELECT, INSERT, UPDATE, DELETE, etc.
    $sqlPatterns = [
      '/\b(SELECT\s+.*?)(;|\Z)/is',
      '/\b(INSERT\s+.*?)(;|\Z)/is',
      '/\b(UPDATE\s+.*?)(;|\Z)/is',
      '/\b(DELETE\s+.*?)(;|\Z)/is',
      '/\b(CREATE\s+.*?)(;|\Z)/is',
      '/\b(ALTER\s+.*?)(;|\Z)/is',
      '/\b(DROP\s+.*?)(;|\Z)/is'
    ];
    
    foreach ($sqlPatterns as $pattern) {
      if (preg_match_all($pattern, $response, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
          $queries[] = trim($match[1]);
        }
      }
    }
    
    // Si aucune requête n'a été trouvée, vérifier si la réponse entière est une requête SQL
    if (empty($queries) && preg_match('/^\s*(SELECT|INSERT|UPDATE|DELETE|CREATE|ALTER|DROP)\s+/i', trim($response))) {
      $queries[] = trim($response);
    }
    
    return $queries;
  }

 /**
  * Resolves placeholders in SQL queries with their actual values
  *
  * @param string $sqlQuery SQL query with placeholders
  * @return string SQL query with resolved placeholders
  */
  private function resolvePlaceholders(string $sqlQuery): string {
    // Détecter les placeholders au format [nom_placeholder]
    preg_match_all('/\[([^\]]+)\]/', $sqlQuery, $matches);
    
    if (empty($matches[1])) {
      return $sqlQuery;
    }
    
    $placeholders = array_unique($matches[1]);
    $resolvedQuery = $sqlQuery;
    
    foreach ($placeholders as $placeholder) {
      $value = $this->getPlaceholderValue($placeholder);
      $resolvedQuery = str_replace("[$placeholder]", $value, $resolvedQuery);
    }
    
    return $resolvedQuery;
  }

 /**
  * Gets the value for a specific placeholder
  *
  * @param string $placeholder Placeholder name
  * @return string Value to replace the placeholder
  */
  private function getPlaceholderValue(string $placeholder): string {
    // Mapper les placeholders courants à leurs valeurs
    $placeholderMap = [
      'language_id' => $this->languageId,
      // Ajouter d'autres mappings selon les besoins
    ];
    
    if (isset($placeholderMap[$placeholder])) {
      return $placeholderMap[$placeholder];
    }
    
    // Journaliser les placeholders inconnus
    if (defined('CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER == 'True') {
      error_log("Placeholder unknown: [$placeholder]");
    }
    
    // Valeur par défaut pour les placeholders inconnus
    return '1'; // Valeur sécuritaire par défaut
  }

 /**
  * Validates SQL syntax and identifies potential issues
  *
  * @param string $sqlQuery SQL query to validate
  * @return array Validation results with issues
  */
  private function validateSqlSyntax(string $sqlQuery): array {
    $issues = [];
    
    // Vérifier l'équilibre des parenthèses
    $openParenCount = substr_count($sqlQuery, '(');
    $closeParenCount = substr_count($sqlQuery, ')');
    if ($openParenCount !== $closeParenCount) {
      $issues[] = CLICSHOPPING::getDef('text_parentheses_mismatch_error', ['openParenCount' => $openParenCount, 'closeParenCount' => $closeParenCount]);
    }
    
    // Vérifier les clauses essentielles pour SELECT
    if (stripos($sqlQuery, 'SELECT') === 0) {
      if (stripos($sqlQuery, 'FROM') === false) {
        $issues[] = CLICSHOPPING::getDef('text_select_without_from_error');
      }
    }
    
    // Vérifier les alias de colonnes invalides
    if (preg_match('/\bAS\s+\w+\.\w+/i', $sqlQuery)) {
      $issues[] = CLICSHOPPING::getDef('text_invalid_column_alias_error');
    }
    
    // Vérifier les placeholders non résolus
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
  *
  * @param string $sqlQuery SQL query to correct
  * @param array $detectedIssues Issues detected in the query
  * @return string Corrected SQL query
  */
  private function applyConservativeCorrections(string $sqlQuery, array $detectedIssues): string {
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
      } else {
        // Journaliser les corrections de faible confiance sans les appliquer
        $this->correctionLog[] = [
          'issue' => $issue,
          'correction' => CLICSHOPPING::getDef('text_no_applied_confidence'),
          'confidence' => $correction['confidence']
        ];
      }
    }
    
    return $correctedQuery;
  }

 /**
  * Determines the appropriate correction for a specific issue
  *
  * @param string $sqlQuery SQL query with issues
  * @param string $issue Description of the issue
  * @return array Correction details with confidence level
  */
  private function determineCorrection(string $sqlQuery, string $issue): array {
    // Initialiser avec des valeurs par défaut
    $correction = [
      'query' => $sqlQuery,
      'description' => CLICSHOPPING::getDef('text_no_correction_applied'),
      'confidence' => 0.0
    ];
    
    // Corriger les déséquilibres de parenthèses
    if (strpos($issue, ' Parentheses mismatch') === 0) {
      preg_match('/(\d+) opening vs (\d+) closing/', $issue, $matches);
      $openCount = (int)$matches[1];
      $closeCount = (int)$matches[2];
      
      if ($openCount > $closeCount) {
        // Ajouter les parenthèses fermantes manquantes
        $correctedQuery = $sqlQuery . str_repeat(')', $openCount - $closeCount);
        $correction = [
          'query' => $correctedQuery,
          'description' => "Added : " . ($openCount - $closeCount) . " closing parenthesiss",
          'confidence' => 0.9
        ];
      } elseif ($closeCount > $openCount) {
        // Supprimer les parenthèses fermantes excédentaires
        $correctedQuery = $sqlQuery;
        for ($i = 0; $i < $closeCount - $openCount; $i++) {
          $pos = strrpos($correctedQuery, ')');
          if ($pos !== false) {
            $correctedQuery = substr($correctedQuery, 0, $pos) . substr($correctedQuery, $pos + 1);
          }
        }
        $correction = [
          'query' => $correctedQuery,
          'description' => "Remove : " . ($closeCount - $openCount) . " excess closing parenthesis",
          'confidence' => 0.8
        ];
      }
    }
    
    // Corriger les alias de colonnes invalides
    elseif (strpos($issue, 'Alias de colonne invalide') === 0) {
      $correctedQuery = preg_replace_callback('/\bAS\s+(\w+)\.(\w+)/i', function($matches) {
        return 'AS ' . $matches[2]; // Utiliser uniquement la partie après le point
      }, $sqlQuery);
      
      $correction = [
        'query' => $correctedQuery,
        'description' => CLICSHOPPING::getDef('text_correction_invalid_column_aliases'),
        'confidence' => 0.95
      ];
    }
    
    // Corriger les placeholders non résolus
    elseif (strpos($issue, 'Placeholders non résolus') === 0) {
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
  * Attempts to recover from SQL execution errors
  *
  * @param \Exception $error The exception that occurred
  * @param string $failedQuery The query that failed
  * @param string $originalQuery The original query before corrections
  * @return array Recovery result with success status and data
  */
  private function attemptErrorRecovery(\Exception $error, string $failedQuery, string $originalQuery): array {
    $errorMessage = $error->getMessage();
    
    // Initialiser le résultat
    $result = [
      'success' => false,
      'data' => [
        'error' => $errorMessage,
        'original_query' => $originalQuery,
        'failed_query' => $failedQuery
      ]
    ];
    
    // Traiter les erreurs de colonne inconnue
    if (strpos($errorMessage, 'Unknown column') !== false) {
      preg_match("/Unknown column '([^']+)'/", $errorMessage, $matches);
      
      if (!empty($matches[1])) {
        $unknownColumn = $matches[1];
        $correctedQuery = $this->correctUnknownColumn($failedQuery, $unknownColumn);
        
        if ($correctedQuery !== $failedQuery) {
          try {
            $query = $this->db->prepare($correctedQuery);
            $query->execute();
            $queryResults = $query->fetchAll();
            
            $result = [
              'success' => true,
              'data' => [
                'original_query' => $originalQuery,
                'failed_query' => $failedQuery,
                'executed_query' => $correctedQuery,
                'results' => $queryResults,
                'count' => count($queryResults),
                'corrections' => $this->correctionLog,
                'recovery' => "Unknown column '$unknownColumn' corrected"
              ]
            ];
          } catch (\Exception $recoveryError) {
            // La récupération a échoué
            $result['data']['recovery_error'] = $recoveryError->getMessage();
          }
        }
      }
    }
    
    // Traiter les erreurs de syntaxe
    elseif (strpos($errorMessage, 'syntax error') !== false) {
      // Tentative de correction de syntaxe plus agressive
      $correctedQuery = $this->correctSyntaxError($failedQuery, $errorMessage);
      
      if ($correctedQuery !== $failedQuery) {
        try {
          $query = $this->db->prepare($correctedQuery);
          $query->execute();
          $queryResults = $query->fetchAll();
          
          $result = [
            'success' => true,
            'data' => [
              'original_query' => $originalQuery,
              'failed_query' => $failedQuery,
              'executed_query' => $correctedQuery,
              'results' => $queryResults,
              'count' => count($queryResults),
              'corrections' => $this->correctionLog,
              'recovery' => CLICSHOPPING::getDef('text_syntax error corrected')
            ]
          ];
        } catch (\Exception $recoveryError) {
          // La récupération a échoué
          $result['data']['recovery_error'] = $recoveryError->getMessage();
        }
      }
    }
    
    return $result;
  }

 /**
  * Corrects unknown column errors in SQL queries
  *
  * @param string $sqlQuery SQL query with unknown column
  * @param string $unknownColumn The unknown column name
  * @return string Corrected SQL query
  */
  private function correctUnknownColumn(string $sqlQuery, string $unknownColumn): string {
    // Vérifier si la colonne contient un point (alias.colonne)
    if (strpos($unknownColumn, '.') !== false) {
      list($alias, $column) = explode('.', $unknownColumn);
      
      // Chercher une colonne similaire dans les tables du schéma
      $similarColumn = $this->findSimilarColumn($column);
      
      if ($similarColumn !== $column) {
        $correctedQuery = str_replace($unknownColumn, "$alias.$similarColumn", $sqlQuery);
        $this->correctionLog[] = "Column '$unknownColumn' corrected in '$alias.$similarColumn'";
        return $correctedQuery;
      }
      
      // Si aucune colonne similaire n'est trouvée, essayer de corriger l'alias
      $tables = $this->extractTablesFromQuery($sqlQuery);
      $correctAlias = $this->findCorrectAlias($alias, array_keys($tables));
      
      if ($correctAlias !== $alias) {
        $correctedQuery = str_replace("$alias.$column", "$correctAlias.$column", $sqlQuery);
        $this->correctionLog[] = "Alias '$alias' corrected in '$correctAlias'";
        return $correctedQuery;
      }
    } else {
      // Colonne sans alias
      $similarColumn = $this->findSimilarColumn($unknownColumn);
      
      if ($similarColumn !== $unknownColumn) {
        // Trouver les tables qui contiennent cette colonne
        $tables = $this->findTablesWithColumn($similarColumn);
        
        if (!empty($tables)) {
          // Extraire les alias de tables de la requête
          $queryTables = $this->extractTablesFromQuery($sqlQuery);
          
          // Chercher une table commune
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
        
        // Si aucune table commune n'est trouvée, simplement remplacer le nom de colonne
        $correctedQuery = str_replace($unknownColumn, $similarColumn, $sqlQuery);
        $this->correctionLog[] = "Column '$unknownColumn' corrected in '$similarColumn'";
        return $correctedQuery;
      }
    }
    
    // Si aucune correction n'est possible, retourner la requête originale
    return $sqlQuery;
  }

 /**
  * Corrects syntax errors in SQL queries
  *
  * @param string $sqlQuery SQL query with syntax error
  * @param string $errorMessage Error message from database
  * @return string Corrected SQL query
  */
  private function correctSyntaxError(string $sqlQuery, string $errorMessage): string {
    // Extraire la partie problématique de la requête
    preg_match("/near '([^']+)'/", $errorMessage, $matches);
    
    if (empty($matches[1])) {
      return $sqlQuery;
    }
    
    $problematicPart = $matches[1];
    $correctedQuery = $sqlQuery;
    
    // Corriger les erreurs courantes de syntaxe
    
    // 1. Corriger les virgules consécutives
    $correctedQuery = preg_replace('/,\s*,/', ',', $correctedQuery);
    
    // 2. Corriger les opérateurs de comparaison mal formés
    $correctedQuery = preg_replace('/\s+=\s+=\s+/', ' = ', $correctedQuery);
    
    // 3. Corriger les clauses WHERE mal formées
    if (strpos($problematicPart, 'WHERE') === 0) {
      $correctedQuery = preg_replace('/\bWHERE\s+AND\b/i', 'WHERE', $correctedQuery);
      $correctedQuery = preg_replace('/\bWHERE\s+OR\b/i', 'WHERE', $correctedQuery);
    }
    
    // 4. Corriger les clauses GROUP BY mal formées
    if (strpos($problematicPart, 'GROUP BY') === 0) {
      $correctedQuery = preg_replace('/\bGROUP\s+BY\s+,/i', 'GROUP BY', $correctedQuery);
    }
    
    // 5. Corriger les clauses ORDER BY mal formées
    if (strpos($problematicPart, 'ORDER BY') === 0) {
      $correctedQuery = preg_replace('/\bORDER\s+BY\s+,/i', 'ORDER BY', $correctedQuery);
    }
    
    // 6. Corriger les placeholders non résolus
    if (strpos($problematicPart, '[') !== false) {
      $correctedQuery = $this->resolvePlaceholders($correctedQuery);
    }
    
    // Si la requête n'a pas été modifiée, essayer une approche plus générique
    if ($correctedQuery === $sqlQuery) {
      // Supprimer la partie problématique et tout ce qui suit
      $pos = strpos($sqlQuery, $problematicPart);
      if ($pos !== false) {
        // Trouver la clause précédente
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
        
        // Trouver la clause suivante
        $nextClausePos = PHP_INT_MAX;
        
        foreach ($previousClauses as $clause) {
          $clausePos = stripos($sqlQuery, $clause, $pos + strlen($problematicPart));
          if ($clausePos !== false && $clausePos < $nextClausePos) {
            $nextClausePos = $clausePos;
          }
        }
        
        if ($nextClausePos !== PHP_INT_MAX) {
          // Remplacer la partie problématique jusqu'à la clause suivante
          $correctedQuery = substr($sqlQuery, 0, $pos) . substr($sqlQuery, $nextClausePos);
          $this->correctionLog[] = CLICSHOPPING::getDef('text_problematic_part_removed', ['problematicPart' => $problematicPart]);
        } else {
          // Supprimer la partie problématique jusqu'à la fin
          $correctedQuery = substr($sqlQuery, 0, $pos);
          $this->correctionLog[] = CLICSHOPPING::getDef('text_problematic_part_everything_following_removed', ['problematicPart' => $problematicPart]);
        }
      }
    }
    
    return $correctedQuery;
  }

 /**
  * Finds a similar column name in the database schema
  *
  * @param string $column Column name to find similar for
  * @return string Similar column name or original if none found
  */
  private function findSimilarColumn(string $column): string {
    // Vérifier si la colonne existe déjà dans l'index
    if (isset($this->columnIndex[$column])) {
      return $column;
    }
    
    // Recherche par similarité textuelle
    $bestMatch = '';
    $maxSimilarity = 0;
    
    foreach (array_keys($this->columnIndex) as $existingColumn) {
      $similarity = similar_text($column, $existingColumn, $percent);
      
      if ($percent > $maxSimilarity) {
        $maxSimilarity = $percent;
        $bestMatch = $existingColumn;
      }
    }
    
    // Retourner la colonne la plus similaire si la similarité est suffisante
    if ($maxSimilarity > 70) {
      return $bestMatch;
    }
    
    // Recherche par préfixe/suffixe commun
    foreach (array_keys($this->columnIndex) as $existingColumn) {
      // Vérifier si la colonne existante contient la colonne recherchée
      if (strpos($existingColumn, $column) !== false) {
        return $existingColumn;
      }
      
      // Vérifier si la colonne recherchée contient la colonne existante
      if (strpos($column, $existingColumn) !== false && strlen($existingColumn) > 3) {
        return $existingColumn;
      }
    }
    
    // Si aucune colonne similaire n'est trouvée, retourner la colonne originale
    return $column;
  }

 /**
  * Finds tables that contain a specific column
  *
  * @param string $column Column name to search for
  * @return array Tables containing the column
  */
  private function findTablesWithColumn(string $column): array {
    if (isset($this->columnIndex[$column])) {
      return $this->columnIndex[$column];
    }
    
    return [];
  }

 /**
  * Finds the correct alias for a table in a query
  *
  * @param string $alias Alias to correct
  * @param array $availableAliases Available aliases in the query
  * @return string Corrected alias or original if none found
  */
  private function findCorrectAlias(string $alias, array $availableAliases): string {
    // Si l'alias est déjà dans la liste, le retourner tel quel
    if (in_array($alias, $availableAliases)) {
      return $alias;
    }
    
    // Recherche par similarité textuelle
    $bestMatch = '';
    $maxSimilarity = 0;
    
    foreach ($availableAliases as $availableAlias) {
      $similarity = similar_text($alias, $availableAlias, $percent);
      
      if ($percent > $maxSimilarity) {
        $maxSimilarity = $percent;
        $bestMatch = $availableAlias;
      }
    }
    
    // Retourner l'alias le plus similaire si la similarité est suffisante
    if ($maxSimilarity > 70) {
      return $bestMatch;
    }
    
    // Si aucun alias similaire n'est trouvé, retourner l'alias original
    return $alias;
  }

 /**
  * Extracts tables and their aliases from a SQL query
  *
  * @param string $sqlQuery SQL query to analyze
  * @return array Associative array of aliases and table names
  */
  private function extractTablesFromQuery(string $sqlQuery): array {
    $tables = [];
    
    // Extraire la table principale dans FROM
    if (preg_match('/FROM\s+(\w+)(?:\s+(?:AS\s+)?(\w+))?/i', $sqlQuery, $matches)) {
      $tableName = $matches[1];
      $alias = !empty($matches[2]) ? $matches[2] : $tableName;
      $tables[$alias] = $tableName;
    }
    
    // Extraire les tables dans les JOINs
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
  *
  * @param string $errorMessage Error message from database
  * @param string $question Original question
  * @return string Suggestion for fixing the error
  */
  private function generateErrorSuggestion(string $errorMessage, string $question): string {
    // Suggestions basées sur le type d'erreur
    if (strpos($errorMessage, 'Unknown column') !== false) {
      return CLICSHOPPING::getDef('text_colum_reference_does_not_exist');
    }
    
    if (strpos($errorMessage, 'syntax error') !== false) {
      return CLICSHOPPING::getDef('text_sql_query_generated_error');
    }
    
    if (strpos($errorMessage, 'Table') !== false && strpos($errorMessage, 'doesn\'t exist') !== false) {
      return CLICSHOPPING::getDef('text_table_referenced_does_not_exist');
    }
    
    // Suggestion générique
    return CLICSHOPPING::getDef('text_error_executing_query');
  }

/**
 * Executes the generated SQL query and returns the results.
 *
 * @param string $question The business question in natural language.
 * @return array The query results.
 */
  public function executeQuery(string $question): array
  {
    try {
      // Tentative principale
      return $this->executeQueryWithRecovery($question);
    } catch (\Exception $e) {
      // Journaliser l'erreur
      if (defined('CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER == 'True') {
        error_log("Erreur d'exécution de requête: " . $e->getMessage());
      }
      
      // Réponse de fallback en cas d'échec complet
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
   * Cleans the SQL response by removing formatting tags and other unwanted elements.
   *
   * @param string $response Raw response from the model.
   * @return string Cleaned SQL query.
   */
  private function cleanSqlResponse(string $response): string
  {
    $cleaned = preg_replace('/```sql\s*|\s*```/', '', $response);
    $cleaned = strip_tags($cleaned);
    $cleaned = trim($cleaned);

    return $cleaned;
  }

  /**
 * Executes a query with recovery mechanisms in case of failure
 *
 * @param string $question The business question in natural language
 * @return array The query results
 */
  private function executeQueryWithRecovery(string $question): array
  {
    try {
      // Vérifier si la question est dans le cache
      $cachedSql = null;
      if ($this->cache->isPromptInCache($question)) {
        $cachedSql = $this->cache->getCachedResponse($question);
      }
      
      // Génération et nettoyage de la requête SQL
      if ($cachedSql !== null) {
        $sqlQueries = [$cachedSql];
        
        if (defined('CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER == 'True') {
          error_log("Using cached SQL for question: " . substr($question, 0, 50) . "...");
        }
      } else {
        $rawResponse = $this->chat->generateText($question);
        
        // Extraire les requêtes SQL du texte
        $sqlQueries = $this->extractSqlQueries($rawResponse);
        
        if (empty($sqlQueries)) {
          // Si aucune requête SQL n'est trouvée, utiliser le nettoyage traditionnel
          $sqlQueries = [$this->cleanSqlResponse($rawResponse)];
        }
        
        // Mettre en cache la première requête SQL
        if (!empty($sqlQueries[0])) {
          $this->cache->cacheResponse($question, $sqlQueries[0]);
        }
      }
      
      if (empty($sqlQueries[0])) {
        throw new \Exception(CLICSHOPPING::getDef('text_no_valid_sql_query_could_extracted'));
      }
      
      $results = [];
      
      foreach ($sqlQueries as $sqlQuery) {
        // Résolution des placeholders
        $resolvedQuery = $this->resolvePlaceholders($sqlQuery);
        
        // Validation syntaxique
        $validation = $this->validateSqlSyntax($resolvedQuery);
        
        if (!$validation['is_valid']) {
          // Tentative de correction
          $correctedQuery = $this->applyConservativeCorrections($resolvedQuery, $validation['issues']);
          
          // Nouvelle validation après correction
          $revalidation = $this->validateSqlSyntax($correctedQuery);
          
          if (!$revalidation['is_valid']) {
            // Si toujours invalide, utiliser la requête originale
            $finalQuery = $sqlQuery;
            $this->correctionLog[] = CLICSHOPPING::getDef('text_correction_cancelled');
          } else {
            $finalQuery = $correctedQuery;
          }
        } else {
          $finalQuery = $resolvedQuery;
        }
        
        // Exécution de la requête
        try {
          $query = $this->db->prepare($finalQuery);
          $query->execute();
          $queryResults = $query->fetchAll();
          
          $results[] = [
            'original_query' => $sqlQuery,
            'executed_query' => $finalQuery,
            'results' => $queryResults,
            'count' => count($queryResults),
            'corrections' => $this->correctionLog
          ];
        } catch (\Exception $e) {
          // Tentative de récupération spécifique à l'erreur
          $recoveryResult = $this->attemptErrorRecovery($e, $finalQuery, $sqlQuery);
          
          if ($recoveryResult['success']) {
            $results[] = $recoveryResult['data'];
          } else {
            throw new \Exception("Execution failed after recovery attempt:" . $e->getMessage());
          }
        }
      }
      
      // Si une seule requête a été exécutée, retourner un format compatible avec l'existant
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
      
      // Sinon, retourner les résultats multiples
      return [
        'type' => 'analytics_results',
        'query' => $question,
        'multi_query_results' => $results,
        'count' => count($results)
      ];
    } catch (\Exception $e) {
      // Remonter l'exception pour la gestion d'erreurs de niveau supérieur
      throw $e;
    }
  }

/**
 * Récupère le schéma d'une table (colonnes et types)
 * 
 * @param string $tableName Nom de la table
 * @return array Schéma de la table [colonne => type]
 */
  private function getTableSchema(string $tableName): array
  {
    // Utiliser le cache si disponible
    if (isset($this->tableSchemaCache[$tableName])) {
      return $this->tableSchemaCache[$tableName];
    }
    
    $schema = [];
    
    try {
      // Requête pour obtenir les informations sur les colonnes
      $query = $this->db->prepare("DESCRIBE " . $tableName);
      $query->execute();
      $columns = $query->fetchAll(\PDO::FETCH_ASSOC);
      
      foreach ($columns as $column) {
        $schema[$column['Field']] = $column['Type'];
      }
      
      // Mettre en cache le schéma
      $this->tableSchemaCache[$tableName] = $schema;
    } catch (\Exception $e) {
      // En cas d'erreur, retourner un schéma vide
      if (defined('CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER == 'True') {
        error_log("Error getting schema for table $tableName: " . $e->getMessage());
      }
      $schema = [];
    }
    
    return $schema;
  }

/**
* Processes a complete business query (SQL generation + execution + interpretation)
*
* @param string $question The business question in natural language
* @param bool $includeSQL Whether to include the SQL query in the response
* @return array Complete results with interpretation
*/
  public function processBusinessQuery(string $question, bool $includeSQL = true): array
  {
    try {
      $results = $this->executeQuery($question);

      if ($results['type'] === 'error') {
        return $results;
      }

      // Adapter pour gérer les résultats multiples
      if (isset($results['multi_query_results'])) {
        $allResults = [];
        foreach ($results['multi_query_results'] as $result) {
          $allResults = array_merge($allResults, $result['results']);
        }
        $interpretation = $this->interpretResults($question, $allResults);
      } else {
        $interpretation = $this->interpretResults($question, $results['results']);
      }

      // Préparer la réponse
      $response = [
        'type' => 'analytics_response',
        'question' => $question,
        'interpretation' => $interpretation,
        'count' => $results['count']
      ];
      
      // Ajouter les détails de requête selon le type de résultat
      if (isset($results['multi_query_results'])) {
        $response['multi_query_results'] = $results['multi_query_results'];
      } else {
        $response['sql_query'] = $results['sql_query'];
        $response['original_sql_query'] = $results['original_sql_query'] ?? $results['sql_query'];
        $response['results'] = $results['results'];
        
        // Ajouter les informations de correction si disponibles
        if (isset($results['corrections']) && !empty($results['corrections'])) {
          $response['corrections'] = $results['corrections'];
        }
      }

      return $response;
    } catch (\Exception $e) {
      // Log de l'erreur pour débogage
      if (defined('CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER == 'True') {
        error_log("Analytics Processing Error: " . $e->getMessage());
      }

      return [
        'type' => 'error',
        'message' => $e->getMessage(),
        'question' => $question,
        'suggestion' => $this->generateErrorSuggestion($e->getMessage(), $question)
      ];
    }
  }

 /**
  * Interprets the results of a query in natural language.
  *
  * @param string $question The original question.
  * @param array $results The query results.
  * @return string The interpretation in natural language.
  */
  private function interpretResults(string $question, array $results): string
  {
    // Créer une clé de cache spécifique pour l'interprétation
    $interpretCacheKey = "interpret_" . $this->cache->generateCacheKey($question . json_encode($results));
    
    // Vérifier si l'interprétation est dans le cache
    if ($this->enablePromptCache && isset($this->promptCache[$interpretCacheKey])) {
      if (defined('CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER == 'True') {
        error_log("Using cached interpretation for question: " . substr($question, 0, 50) . "...");
      }
      
      // Mettre à jour le timestamp pour indiquer que cette entrée est toujours utilisée
      $this->promptCache[$interpretCacheKey]['last_used'] = time();
      
      return $this->promptCache[$interpretCacheKey]['response'];
    }
    
    $array = [
      'question' => $question,
      'results' => json_encode($results, JSON_PRETTY_PRINT)
    ];

    $prompt = CLICSHOPPING::getDef('text_interpret_results', $array);

    $interpretation = $this->chat->generateText($prompt);
    
    // Mettre en cache l'interprétation
    if ($this->enablePromptCache) {
      $this->promptCache[$interpretCacheKey] = [
        'prompt' => $prompt,
        'response' => $interpretation,
        'created' => time(),
        'last_used' => time()
      ];
      
      // Sauvegarder le cache
      $this->cache->savePromptCache();
    }

    return $interpretation;
  }

  /**
   * Checks if a query is of analytical type
   *
   * @param string $query Query to check
   * @return bool True if the query is analytical, false otherwise
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
   * Retrieves the categories of an analytical query
   *
   * @param string $query Query to check
   * @return array Categories of the query
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
}
