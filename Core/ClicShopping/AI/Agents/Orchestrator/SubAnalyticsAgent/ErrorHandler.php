<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\AI\Agents\Orchestrator\SubAnalyticsAgent;

use ClicShopping\OM\CLICSHOPPING;

/**
 * ErrorHandler
 * 
 * Handles error recovery and user-friendly error messaging for AnalyticsAgent
 * Manages intelligent query correction and empty result handling
 * 
 * Responsibilities:
 * - Attempt intelligent correction of failed queries
 * - Generate user-friendly error suggestions
 * - Handle empty results gracefully
 * - Coordinate with CorrectionAgent for learning
 * 
 * @package ClicShopping\AI\Agents\Orchestrator\SubAnalyticsAgent
 */
class ErrorHandler
{
  private mixed $db;
  private mixed $correctionAgent;
  private mixed $queryExecutor;
  private array $correctionLog = [];
  private bool $debug;
  
  /**
   * Constructor
   * 
   * @param mixed $db Database connection
   * @param mixed $correctionAgent Correction agent for intelligent fixes
   * @param mixed $queryExecutor Query executor for deduplication
   * @param bool $debug Debug mode flag
   */
  public function __construct(
    $db,
    $correctionAgent,
    $queryExecutor,
    bool $debug = false
  ) {
    $this->db = $db;
    $this->correctionAgent = $correctionAgent;
    $this->queryExecutor = $queryExecutor;
    $this->debug = $debug;
  }
  
  /**
   * Attempt intelligent correction of a failed query
   * 
   * Uses CorrectionAgent to analyze the error and suggest a corrected query.
   * If correction succeeds, executes the corrected query and returns results.
   * Maintains correction log for learning purposes.
   * 
   * @param \Exception $originalError The exception that occurred
   * @param string $failedQuery The SQL query that failed
   * @param string $originalQuery The original SQL before modifications
   * @param string $userQuestion The user's natural language question
   * @return array Result with success flag and data or error information
   */
  public function attemptIntelligentCorrection(
    \Exception $originalError,
    string $failedQuery,
    string $originalQuery,
    string $userQuestion
  ): array {
    try {
      // Prepare error context for CorrectionAgent
      $errorContext = [
        'error_message' => $originalError->getMessage(),
        'failed_query' => $failedQuery,
        'original_query' => $userQuestion,
        'previous_corrections' => $this->correctionLog,
      ];

      // Ask CorrectionAgent to correct
      $correctionResult = $this->correctionAgent->attemptCorrection($errorContext);

      if (!$correctionResult['success']) {
        return [
          'success' => false,
          'error' => $correctionResult['error'] ?? 'Correction failed',
          'suggestions' => $correctionResult['suggestions'] ?? [],
        ];
      }

      // Get corrected query
      $correctedQuery = $correctionResult['corrected_query'];

      // Log the correction
      $this->correctionLog[] = [
        'original_error' => $originalError->getMessage(),
        'correction_method' => $correctionResult['correction_method'],
        'confidence' => $correctionResult['confidence'],
        'learned_from_history' => $correctionResult['learned_from_history'] ?? false,
      ];

      // Attempt to execute corrected query
      $query = $this->db->prepare($correctedQuery);

      if (!$query) {
        throw new \Exception("Failed to prepare corrected query");
      }

      $query->execute();
      $rows = $query->fetchAll(\PDO::FETCH_ASSOC);
      $queryResults = $this->queryExecutor->deduplicateRows($rows);

      // Success!
      return [
        'success' => true,
        'data' => [
          'original_query' => $originalQuery,
          'failed_query' => $failedQuery,
          'executed_query' => $correctedQuery,
          'results' => $queryResults,
          'count' => count($queryResults),
          'corrections' => $this->correctionLog,
          'correction_details' => [
            'method' => $correctionResult['correction_method'],
            'confidence' => $correctionResult['confidence'],
            'learned_from_history' => $correctionResult['learned_from_history'] ?? false,
            'similar_cases_found' => $correctionResult['similar_cases_found'] ?? 0,
          ],
          'message' => 'Query automatically corrected by AI',
        ],
      ];
    } catch (\Exception $e) {
      // Correction failed or corrected query also failed
      return [
        'success' => false,
        'error' => $e->getMessage(),
        'correction_attempted' => true,
      ];
    }
  }
  
  /**
   * Generate user-friendly error suggestion based on error type
   * 
   * Analyzes the database error message and returns a helpful suggestion
   * for the user. Handles common error types like unknown columns, syntax
   * errors, and missing tables.
   * 
   * @param string $errorMessage Error message from database
   * @param string $question Original question that generated the query
   * @return string User-friendly suggestion for fixing the error
   */
  public function generateErrorSuggestion(string $errorMessage, string $question): string
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
   * Generate user-friendly message for empty results
   * 
   * Creates a helpful message when a query returns no results. Analyzes
   * the SQL query to provide context-specific suggestions (e.g., check
   * spelling for product searches, try wider date range for time queries).
   * 
   * @param string $question The user's question
   * @param array $results The results array (with sql_query if available)
   * @param bool $debug Whether to include debug information
   * @return string User-friendly message explaining why no results were found
   */
  public function generateEmptyResultsMessage(string $question, array $results, bool $debug = false): string
  {
    // Base message
    $message = "Aucun résultat trouvé pour cette requête.";

    // Add helpful suggestions based on query type
    if (isset($results['sql_query'])) {
      $sql = $results['sql_query'];

      // Product search
      if (stripos($sql, 'products_name') !== false || stripos($sql, 'products_description') !== false) {
        $message .= " Suggestions : vérifiez l'orthographe du nom du produit, essayez un nom plus court (par exemple 'iPhone' au lieu de 'Apple iPhone 17 Pro'), ou consultez le catalogue complet.";
      }
      // Date/time queries
      elseif (stripos($sql, 'date') !== false || stripos($sql, 'datetime') !== false) {
        $message .= " Suggestions : vérifiez la période sélectionnée ou essayez une plage de dates plus large.";
      }
      // Category queries
      elseif (stripos($sql, 'categories') !== false) {
        $message .= " Suggestions : vérifiez le nom de la catégorie ou essayez une catégorie parente.";
      }
      // Customer/order queries
      elseif (stripos($sql, 'customers') !== false || stripos($sql, 'orders') !== false) {
        $message .= " Suggestions : vérifiez les critères de recherche (nom, email, numéro de commande) ou élargissez la période.";
      }
      // Generic suggestion
      else {
        $message .= " Suggestions : vérifiez les critères de recherche ou reformulez votre question.";
      }
    } else {
      // No SQL query available, generic message
      $message .= " Veuillez reformuler votre question ou vérifier les critères de recherche.";
    }

    // Add debug info if enabled
    if ($debug && isset($results['sql_query'])) {
      $message .= "\n\nRequête SQL exécutée : " . $results['sql_query'];
    }

    return $message;
  }
  
  /**
   * Get correction log
   * 
   * Returns the log of all corrections attempted during this session.
   * Useful for debugging and learning purposes.
   * 
   * @return array Array of correction log entries
   */
  public function getCorrectionLog(): array
  {
    return $this->correctionLog;
  }
}
