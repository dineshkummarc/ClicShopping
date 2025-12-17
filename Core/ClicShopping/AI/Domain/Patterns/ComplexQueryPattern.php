<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\AI\Domain\Patterns;

/**
 * ComplexQueryPattern Class
 *
 * Centralized patterns for complex query detection.
 * All patterns are in English (queries are pre-translated).
 *
 * PRIORITY 2 FIX: Extracted from ComplexQueryHandler to Pattern directory
 * for better organization and maintainability.
 */
class ComplexQueryPattern
{
  /**
   * Get query connectors that indicate multiple queries
   * 
   * PRIORITY 2 FIX: Separated into strong and weak connectors
   * REFACTORING 2025-12-14: Added missing connectors from QuerySplitter
   * 
   * @return array Associative array with 'strong' and 'weak' connectors
   */
  public static function getConnectors(): array
  {
    return [
      // Strong connectors - clearly indicate multiple distinct queries
      'strong' => [
        'then', 'also', 'additionally', 'moreover', 'furthermore',
        'as well as', 'along with', 'together with', 'and also', 'plus',
        'puis', 'également', 'en plus', 'de plus', 'ainsi que',
        'aussi bien que', 'avec', 'ensemble avec'
      ],
      
      // Weak connectors - may be part of single query
      // Only count these if followed by action verbs
      'weak' => [
        'and', 'et'
      ]
    ];
  }
  
  /**
   * Get action verbs that indicate new query after weak connector
   * 
   * REFACTORING 2025-12-14: Added missing verbs from QuerySplitter
   * 
   * @return array List of action verbs
   */
  public static function getActionVerbs(): array
  {
    return [
      // English
      'give', 'show', 'list', 'get', 'find', 'tell', 'explain', 
      'compare', 'calculate', 'analyze', 'create', 'generate',
      'search', 'display', 'describe',
      
      // French
      'donne', 'montre', 'liste', 'trouve', 'explique', 
      'compare', 'calcule', 'analyse', 'crée', 'génère',
      'cherche', 'affiche', 'décris'
    ];
  }
  
  /**
   * Get hybrid query patterns
   * 
   * Patterns that indicate queries combining multiple types
   * (analytics + semantic + web search)
   * 
   * @return array Associative array of pattern types and keywords
   */
  public static function getHybridPatterns(): array
  {
    return [
      'compare' => [
        'compare', 'comparison', 'vs', 'versus', 'against'
      ],
      
      'competitor' => [
        'competitor', 'competitors', 'competition',
        'amazon', 'ebay', 'marketplace'
      ],
      
      'external' => [
        'external price', 'external review',
        'trend', 'trends', 'market trend', 'market trends'
      ],
      
      'report' => [
        'report', 'reports', 'analysis', 'analyze',
        'create report', 'generate report', 'make report',
        'build report', 'analysis report', 'create analysis',
        'generate analysis', 'comprehensive report', 'detailed report'
      ],
    ];
  }
  
  /**
   * Get temporal keywords for comparison detection
   * 
   * Used to detect temporal comparisons that should NOT be split
   * (e.g., "May vs February", "Q1 vs Q2")
   * 
   * @return array List of temporal keywords
   */
  public static function getTemporalKeywords(): array
  {
    return [
      // Months
      'january', 'february', 'march', 'april', 'may', 'june',
      'july', 'august', 'september', 'october', 'november', 'december',
      
      // French months
      'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
      'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre',
      
      // Quarters
      'q1', 'q2', 'q3', 'q4', 'quarter', 'trimestre',
      
      // Time periods
      'week', 'month', 'year', 'semaine', 'mois', 'année'
    ];
  }
  
  /**
   * Get contextual dependency patterns
   * 
   * Patterns that indicate queries with contextual dependencies
   * 
   * @return array List of dependency patterns
   */
  public static function getContextualDependencyPatterns(): array
  {
    return [
      'by country', 'by category', 'by product', 'by customer',
      'for each', 'in each', 'according to', 'per', '\bby\b',
      
      // French
      'par pays', 'par catégorie', 'par produit', 'par client',
      'pour chaque', 'dans chaque', 'selon', '\bpar\b'
    ];
  }
  
  /**
   * Get complexity scoring weights
   * 
   * Weights for different complexity indicators
   * 
   * @return array Associative array of weights
   */
  public static function getComplexityWeights(): array
  {
    return [
      'strong_connector' => 45,      // Single strong connector
      'multiple_connectors' => 30,   // Per additional connector
      'hybrid_pattern' => 20,        // Per hybrid pattern
      'web_search' => 25,            // Web search requirement
      'contextual_dependency' => 15, // Contextual dependencies
      'minimum_threshold' => 40      // Minimum score to be complex
    ];
  }
  
  /**
   * Check if query is a temporal comparison
   * 
   * Temporal comparisons should NOT be split into multiple queries
   * (e.g., "May vs February", "Q1 vs Q2")
   * 
   * @param string $query_lower Query in lowercase
   * @return bool True if temporal comparison
   */
  public static function isTemporalComparison(string $query_lower): bool
  {
    $temporalKeywords = self::getTemporalKeywords();
    
    // Build regex pattern for all temporal keywords
    $temporal_pattern = '/\b(' . implode('|', array_map('preg_quote', $temporalKeywords)) . ')\b/iu';
    
    // Check "vs" / "versus" + any temporal keyword
    if (preg_match('/\b(vs|versus)\b/i', $query_lower) && preg_match($temporal_pattern, $query_lower)) {
      return true;
    }
    
    // Check "compare"/"comparison" + 2+ temporal keywords
    if (preg_match('/\b(compare|comparison|comparer|comparaison)\b/iu', $query_lower)) {
      preg_match_all($temporal_pattern, $query_lower, $matches);
      if (count(array_unique($matches[0])) >= 2) {
        return true;
      }
    }
    
    return false;
  }
  
  /**
   * Detect strong connectors in query
   * 
   * @param string $query_lower Query in lowercase
   * @return int Number of strong connectors found
   */
  public static function detectStrongConnectors(string $query_lower): int
  {
    $connectors = self::getConnectors();
    $count = 0;
    
    foreach ($connectors['strong'] as $connector) {
      $pattern = '/\s+' . preg_quote($connector, '/') . '\s+/iu';
      $count += preg_match_all($pattern, ' ' . $query_lower . ' ');
    }
    
    return $count;
  }
  
  /**
   * Detect weak connectors followed by action verbs
   * 
   * @param string $query_lower Query in lowercase
   * @return int Number of weak connectors with action verbs found
   */
  public static function detectWeakConnectorsWithActions(string $query_lower): int
  {
    $connectors = self::getConnectors();
    $actionVerbs = self::getActionVerbs();
    $count = 0;
    
    // Build pattern for action verbs
    $verbPattern = implode('|', array_map('preg_quote', $actionVerbs));
    
    foreach ($connectors['weak'] as $connector) {
      // Check for connector followed by action verb
      $pattern = '/\s+' . preg_quote($connector, '/') . '\s+(' . $verbPattern . ')\b/iu';
      $count += preg_match_all($pattern, ' ' . $query_lower . ' ');
    }
    
    return $count;
  }
  
  /**
   * Detect all connectors (strong + weak with actions)
   * 
   * @param string $query_lower Query in lowercase
   * @return int Total number of connectors found
   */
  public static function detectAllConnectors(string $query_lower): int
  {
    // Check for temporal comparison first
    if (self::isTemporalComparison($query_lower)) {
      return 0; // Don't split temporal comparisons
    }
    
    $strongCount = self::detectStrongConnectors($query_lower);
    $weakCount = self::detectWeakConnectorsWithActions($query_lower);
    
    return $strongCount + $weakCount;
  }
  
  /**
   * Detect hybrid patterns in query
   * 
   * @param string $query_lower Query in lowercase
   * @return array List of detected pattern types
   */
  public static function detectHybridPatterns(string $query_lower): array
  {
    $patterns = self::getHybridPatterns();
    $detected = [];
    
    foreach ($patterns as $pattern_type => $keywords) {
      foreach ($keywords as $keyword) {
        if (stripos($query_lower, $keyword) !== false) {
          $detected[] = $pattern_type;
          break; // One pattern of this type is enough
        }
      }
    }
    
    return array_unique($detected);
  }
  
  /**
   * Check if query requires web search
   * 
   * @param string $query_lower Query in lowercase
   * @return bool True if web search required
   */
  public static function requiresWebSearch(string $query_lower): bool
  {
    $patterns = self::getHybridPatterns();
    
    // Check competitor patterns
    foreach ($patterns['competitor'] as $keyword) {
      if (stripos($query_lower, $keyword) !== false) {
        return true;
      }
    }
    
    // Check external patterns
    foreach ($patterns['external'] as $keyword) {
      if (stripos($query_lower, $keyword) !== false) {
        return true;
      }
    }
    
    return false;
  }
  
  /**
   * Check if query has contextual dependencies
   * 
   * @param string $query_lower Query in lowercase
   * @return bool True if contextual dependencies detected
   */
  public static function hasContextualDependencies(string $query_lower): bool
  {
    $patterns = self::getContextualDependencyPatterns();
    
    foreach ($patterns as $pattern) {
      if (preg_match('/' . $pattern . '/iu', $query_lower)) {
        return true;
      }
    }
    
    return false;
  }
  
  /**
   * Get report/analysis patterns
   * 
   * Patterns that indicate report generation queries.
   * These patterns are used to detect queries that require splitting into
   * analytics + semantic + optional web_search sub-queries.
   * 
   * REFACTORING 2025-12-14: Extracted from QuerySplitter for centralization
   * FIX 2025-12-14: Added summary to comprehensive/detailed pattern
   * 
   * @return array List of regex patterns
   */
  public static function getReportPatterns(): array
  {
    return [
      // Create/generate report (English)
      '/\b(create|generate|make|build)\s+(a\s+)?(report|analysis|summary)\b/i',
      
      // Report/analysis for/of/on (English)
      '/\b(analysis|report)\s+(for|of|on)\b/i',
      
      // Comprehensive/detailed report (English) - FIX: Added summary
      '/\b(comprehensive|detailed|full)\s+(report|analysis|summary)\b/i',
      
      // French equivalents
      '/\b(créer|générer|faire|construire)\s+(un\s+)?(rapport|analyse|résumé)\b/i',
      '/\b(analyse|rapport)\s+(pour|de|sur)\b/i',
      '/\b(complet|détaillé)\s+(rapport|analyse|résumé)\b/i',
    ];
  }
}
