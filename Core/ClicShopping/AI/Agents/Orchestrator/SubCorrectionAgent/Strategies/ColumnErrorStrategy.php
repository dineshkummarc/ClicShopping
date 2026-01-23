<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\AI\Agents\Orchestrator\SubCorrectionAgent\Strategies;

use ClicShopping\AI\Agents\Orchestrator\CorrectionAgent;

/**
 * ColumnErrorStrategy Class
 * Handles unknown column errors by finding similar column names in schema
 * 
 * This strategy attempts to correct column name typos by:
 * - Extracting the unknown column name from error details
 * - Finding similar column names in the database schema
 * - Replacing the unknown column with the most similar match
 */
class ColumnErrorStrategy implements CorrectionStrategyInterface
{
  /**
   * Correct column error
   *
   * @param array $errorContext Error context containing failed_query
   * @param array $errorAnalysis Error analysis with column_name in details
   * @param array $similarCases Similar historical cases
   * @return array Correction result with query, method, confidence, suggestions
   */
  public function correct(array $errorContext, array $errorAnalysis, array $similarCases): array
  {
    $query = $errorContext['failed_query'];
    $unknownColumn = $errorAnalysis['details']['column_name'] ?? '';

    if (empty($unknownColumn)) {
      // Cannot correct without knowing which column is unknown
      return [
        'query' => $query,
        'method' => 'column_correction_failed',
        'confidence' => 0.0,
        'suggestions' => ['Unable to identify unknown column name'],
      ];
    }

    // Find similar column in schema
    $similarColumn = $this->findSimilarColumnInSchema($unknownColumn);

    if ($similarColumn && $similarColumn !== $unknownColumn) {
      $corrected = str_replace($unknownColumn, $similarColumn, $query);

      return [
        'query' => $corrected,
        'method' => 'column_name_correction',
        'confidence' => 0.8,
        'suggestions' => ["Column '$unknownColumn' replaced with '$similarColumn'"],
      ];
    }

    // No similar column found
    return [
      'query' => $query,
      'method' => 'column_correction_failed',
      'confidence' => 0.0,
      'suggestions' => [
        "Column '$unknownColumn' not found in schema",
        "Check column name spelling",
        "Verify table aliases are correct"
      ],
    ];
  }

  /**
   * Find similar column in schema
   * 
   * This method searches the database schema for columns with similar names
   * using string similarity algorithms (e.g., Levenshtein distance).
   * 
   * @param string $columnName Column name to search for
   * @return string|null Similar column name or null if not found
   */
  private function findSimilarColumnInSchema(string $columnName): ?string
  {
    // TODO: Implement schema lookup with similarity matching
    // This would require:
    // 1. Query INFORMATION_SCHEMA to get all column names
    // 2. Calculate similarity scores (Levenshtein distance)
    // 3. Return the most similar column above a threshold
    
    // For now, return null (no match found)
    return null;
  }

  /**
   * Get error type this strategy handles
   *
   * @return string Error type identifier
   */
  public function getErrorType(): string
  {
    return 'unknown_column';
  }

  /**
   * Get confidence level of this strategy
   *
   * @return float Confidence level (0.0 to 1.0)
   */
  public function getConfidenceLevel(): float
  {
    return 0.75;
  }
}
