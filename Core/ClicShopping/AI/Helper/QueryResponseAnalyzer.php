<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\AI\Helper;

/**
 * QueryResponseAnalyzer Helper Class
 *
 * Provides utility methods for analyzing query results and determining
 * response metadata (primary type, confidence, agent used, etc.).
 *
 * PRIORITY 1 FIX: Created to properly set agent_used and intent fields
 * in complex query responses.
 */
class QueryResponseAnalyzer
{
  /**
   * Determine primary type from sub-results
   * 
   * Analyzes sub-query results to determine the dominant query type.
   * Used to set the correct agent_used and intent.type in the response.
   * 
   * @param array $subResults Array of sub-query results
   * @return string Primary type ('semantic', 'analytics', 'web_search', or 'hybrid')
   */
  public static function determinePrimaryType(array $subResults): string
  {
    if (empty($subResults)) {
      return 'semantic'; // Default fallback
    }
    
    // Count occurrences of each type
    $typeCounts = [];
    foreach ($subResults as $result) {
      $type = $result['type'] ?? 'unknown';
      $typeCounts[$type] = ($typeCounts[$type] ?? 0) + 1;
    }
    
    // If multiple types, return 'hybrid'
    if (count($typeCounts) > 1) {
      return 'hybrid';
    }
    
    // Return the most common type
    arsort($typeCounts);
    $primaryType = array_key_first($typeCounts);
    
    return $primaryType === 'unknown' ? 'semantic' : $primaryType;
  }
  
  /**
   * Calculate average confidence from sub-results
   * 
   * Computes the average confidence score from all sub-query results.
   * Used to set the intent.confidence in the response.
   * 
   * @param array $subResults Array of sub-query results
   * @return float Average confidence (0.0 to 1.0)
   */
  public static function calculateAverageConfidence(array $subResults): float
  {
    if (empty($subResults)) {
      return 0.5; // Default confidence
    }
    
    $totalConfidence = 0.0;
    $count = 0;
    
    foreach ($subResults as $result) {
      // Try to extract confidence from result
      $confidence = null;
      
      if (isset($result['confidence'])) {
        $confidence = $result['confidence'];
      } elseif (isset($result['result']['confidence'])) {
        $confidence = $result['result']['confidence'];
      } elseif (isset($result['result']['result']['confidence'])) {
        $confidence = $result['result']['result']['confidence'];
      }
      
      // If we found a confidence value, add it
      if ($confidence !== null && is_numeric($confidence)) {
        $totalConfidence += floatval($confidence);
        $count++;
      }
    }
    
    // Return average, or default if no confidence values found
    return $count > 0 ? ($totalConfidence / $count) : 0.8;
  }
  
  /**
   * Get agent name from query type
   * 
   * Maps query type to the appropriate agent name for response metadata.
   * 
   * @param string $type Query type ('semantic', 'analytics', 'web_search', 'hybrid')
   * @return string Agent name
   */
  public static function getAgentNameFromType(string $type): string
  {
    return match($type) {
      'semantic' => 'SemanticSearchOrchestrator',
      'analytics' => 'AnalyticsAgent',
      'web_search' => 'WebSearchAgent',
      'hybrid' => 'HybridQueryProcessor',
      default => 'OrchestratorAgent'
    };
  }
  
  /**
   * Check if results contain multiple types (hybrid)
   * 
   * Determines if the sub-results contain multiple different query types.
   * 
   * @param array $subResults Array of sub-query results
   * @return bool True if multiple types detected
   */
  public static function isHybridResult(array $subResults): bool
  {
    if (empty($subResults)) {
      return false;
    }
    
    $types = array_unique(array_column($subResults, 'type'));
    return count($types) > 1;
  }
  
  /**
   * Extract intent metadata from sub-results
   * 
   * Creates a complete intent array from sub-query results.
   * 
   * @param array $subResults Array of sub-query results
   * @param string $translatedQuery Translated query text
   * @return array Intent metadata
   */
  public static function extractIntentMetadata(array $subResults, string $translatedQuery): array
  {
    $primaryType = self::determinePrimaryType($subResults);
    $avgConfidence = self::calculateAverageConfidence($subResults);
    $isHybrid = self::isHybridResult($subResults);
    
    return [
      'type' => $primaryType,
      'confidence' => $avgConfidence,
      'is_hybrid' => $isHybrid,
      'translated_query' => $translatedQuery,
      'sub_query_count' => count($subResults)
    ];
  }
}
