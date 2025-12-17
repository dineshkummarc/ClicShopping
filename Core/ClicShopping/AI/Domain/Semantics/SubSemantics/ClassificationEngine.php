<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\AI\Domain\Semantics\SubSemantics;

use ClicShopping\AI\Security\SecurityLogger;
use ClicShopping\AI\Domain\Patterns\AnalyticsPattern;
use ClicShopping\AI\Domain\Patterns\SemanticsPattern;
use ClicShopping\AI\Infrastructure\Orm\DoctrineOrm;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\Gpt;

/**
 * ClassificationEngine
 * 
 * Core classification logic for determining query types.
 * Uses pattern matching and scoring to classify queries as analytics or semantic.
 */
class ClassificationEngine
{
  private static ?SecurityLogger $logger = null;
  
  /**
   * Initialize logger
   */
  private static function initLogger(): void
  {
    if (self::$logger === null) {
      self::$logger = new SecurityLogger();
    }
  }
  
  /**
   * Classifies a query as 'analytics' or 'semantic'
   * 
   * @param string $query Query to classify
   * @param int $threshold Classification threshold (default: 2 for better field detection)
   * @return string 'analytics' or 'semantic'
   */
  public static function classifyQuery(string $query, int $threshold = 2): string
  {
    self::initLogger();

    $text_result = 'semantic';

    // Calculate score based on patterns
    $score = self::calculateScore($query);
    
    // Log classification
    if (self::$logger && defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True') {
      self::$logger->logStructured(
        'info',
        'Semantics',
        'calculateScore',
        [
          'query' => $query,
          'score' => $score,
          'threshold' => $threshold
        ]
      );
    }
    
    // Classify based on score
    if ($score >= $threshold) {
      $result = 'analytics';
    }
    
    // Check for enhanced semantic patterns
    if (SemanticsPattern::isEnhancedSemanticQuery($query)) {
      $result = $text_result;
    }
    
    // Check keywords as fallback
    if (AnalyticsPattern::hasAnalyticsKeywords($query)) {
      $result = 'analytics';
    }
    
    if (SemanticsPattern::hasSemanticKeywords($query)) {
      $result = $text_result;
    }


    error_log("\n ================== classifyQuery =================== \n");
    error_log($result . "\n");
    error_log("\n ================== classifyQuery ===================  \n");


    // Default to semantic for general questions
    return $result;
  }
  
  /**
   * Calculates classification score based on pattern matching
   * 
   * @param string $query Query to analyze
   * @return int Score (number of pattern categories matched)
   */
  public static function calculateScore(string $query): int
  {
    $patterns = AnalyticsPattern::getAnalyticsPatterns();
    //$weights

    $matchedCategories = [];
    
    foreach ($patterns as $category => $categoryPatterns) {
      foreach ($categoryPatterns as $pattern) {
        if (preg_match($pattern, $query)) {
          $matchedCategories[$category] = true;
          
          // Log pattern match
          if (self::$logger && defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && 
              CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True') {
            self::$logger->logSecurityEvent(
              "Pattern match detected: Category: $category | Pattern: $pattern",
              'info'
            );
          }
          
          break; // One match per category is enough
        }
      }
    }
    
    $score = count($matchedCategories);
    
    // FIX TASK 4.4: Boost score for database field queries
    // Detect queries asking for specific database fields (not general information)
    $fieldBoost = self::detectDatabaseFieldQuery($query);
    if ($fieldBoost > 0) {
      $score += $fieldBoost;
      
      if (self::$logger && defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && 
          CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True') {
        self::$logger->logSecurityEvent(
          "Database field query boost applied: +{$fieldBoost}",
          'info'
        );
      }
    }
    
    if (self::$logger && defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && 
        CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True') {
      self::$logger->logSecurityEvent(
        "Total score: $score",
        'info'
      );
    }
    
    return $score;
  }

  /**
   * Detects if query is asking for specific database field(s)
   * 
   * IMPROVED VERSION: Uses dynamic field discovery via DatabaseSchemaIntrospector
   * 
   * Intelligent detection based on:
   * 1. Query structure: "give me the [FIELD] of [ENTITY]"
   * 2. Dynamic database field detection (no hardcoded lists)
   * 3. Context: asking for data retrieval, not explanation
   * 
   * @param string $query Query to analyze
   * @return int Boost score (0 = no boost, 2 = strong boost)
   */
  private static function detectDatabaseFieldQuery(string $query): int
  {
    $boost = 0;
    
    // Pattern 1: "give me/show me/get/what is THE [FIELD] OF/FOR [entity]"
    // This structure indicates asking for a specific field value
    $fieldRequestPattern = '/\b(give|show|get|display|find|fetch|retrieve|what\s+is|what\'s|tell\s+me)\s+(?:me\s+)?(?:the\s+)?(\w+)\s+(?:of|for|from)\b/i';
    
    if (preg_match($fieldRequestPattern, $query, $matches)) {
      $potentialField = strtolower($matches[2] ?? '');
      
      // Use dynamic field detection (no hardcoded list)
      if (DoctrineOrm::isDatabaseField($potentialField)) {
        $boost = max($boost, 2); // Strong boost
      }
    }
    
    // Pattern 2: "give me/show me [FIELD] and [FIELD]" (without "of")
    // Example: "show me the weight and dimensions"
    $multiFieldPattern = '/\b(give|show|get|display)\s+(?:me\s+)?(?:the\s+)?(\w+)\s+and\s+(\w+)\b/i';
    if (preg_match($multiFieldPattern, $query, $matches)) {
      $field1 = strtolower($matches[2] ?? '');
      $field2 = strtolower($matches[3] ?? '');
      
      if (DoctrineOrm::isDatabaseField($field1) || 
          DoctrineOrm::isDatabaseField($field2)) {
        $boost = max($boost, 2); // Strong boost for multi-field queries
      }
    }
    
    // Pattern 3: Multiple fields with "and": "price and sku", "weight and height"
    if (preg_match('/\b(\w+)\s+and\s+(\w+)\b/i', $query, $matches)) {
      $field1 = strtolower($matches[1] ?? '');
      $field2 = strtolower($matches[2] ?? '');
      
      if (DoctrineOrm::isDatabaseField($field1) && 
          DoctrineOrm::isDatabaseField($field2)) {
        $boost = max($boost, 2); // Strong boost when both are fields
      }
    }
    
    // Pattern 4: Direct field mention with entity
    // Example: "product sku", "item reference", "order number", "order status"
    $directFieldPattern = '/\b(product|item|order|customer|category|supplier|manufacturer|review|sentiment)\s+(\w+)\b/i';
    if (preg_match($directFieldPattern, $query, $matches)) {
      $potentialField = strtolower($matches[2] ?? '');
      if (DoctrineOrm::isDatabaseField($potentialField)) {
        $boost = max($boost, 2); // Increase to strong boost for direct mentions
      }
    }
    
    // Pattern 5: Field mentioned anywhere in query (fallback)
    // Check if any database field is mentioned
    $words = preg_split('/\s+/', strtolower($query));
    foreach ($words as $word) {
      $word = trim($word, '.,!?;:');
      if (DoctrineOrm::isDatabaseField($word)) {
        $boost = max($boost, 1); // Weak boost if field is just mentioned
      }
    }
    
    return $boost;
  }

  /**
   * DEPRECATED: Use DoctrineOrm::isDatabaseField() instead
   * 
   * This method is kept for backward compatibility but delegates to DoctrineOrm.
   * 
   * @param string $word Word to check
   * @return bool True if it's a database field
   * @deprecated Use DoctrineOrm::isDatabaseField() instead
   */
  private static function isDatabaseField(string $word): bool
  {
    // Delegate to DoctrineOrm (proper place for DB operations)
    return DoctrineOrm::isDatabaseField($word);
  }

  /*
   * $weights
   *
  private static function calculateScore(string $text): int
  {
    $analyticsPatterns = self::analyticsPatterns();

    // More selective weights for analytics patterns
    $weights = [
      'performance' => 3,    // Strong analytics indicators
      'calculation' => 3,
      'comparison' => 2.5,
      'price' => 2,
      'quantity' => 2,
      'filters' => 1.5,
      'sorting' => 1.5,
      'time' => 1,
      'entity' => 0.5,      // Weak analytics indicators
      'category' => 0.5,
      'customer' => 0.5
    ];

    $score = 0;

    foreach ($analyticsPatterns as $category => $patterns) {
      foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text)) {
          self::logSecurityEvent("Pattern match detected: Category: $category | Pattern: $pattern", 'info');
          $score += $weights[$category] ?? 1;
        }
      }
    }

    return (int)$score;
  }

  */



  /**
   * Gets fallback classification when GPT classification fails
   * 
   * 🔧 TASK 4.3 (2025-12-11): Enhanced fallback logic with explicit logging
   * 
   * FALLBACK STRATEGY:
   * 1. Check enhanced semantic patterns first (highest priority)
   * 2. Check analytics keywords (medium priority)
   * 3. Default to semantic (safest fallback)
   * 
   * RATIONALE:
   * - Semantic is safer default than analytics
   * - RAG search is more forgiving than SQL generation
   * - Analytics requires precise patterns to avoid false positives
   * 
   * @param string $text Text to classify
   * @return string 'analytics' or 'semantic'
   */
  public static function getFallbackClassification(string $text): string
  {
    self::initLogger();
    
    // Check enhanced semantic patterns first
    if (SemanticsPattern::isEnhancedSemanticQuery($text)) {
      if (self::$logger) {
        self::$logger->logStructured(
          'info',
          'ClassificationEngine',
          'fallback_classification',
          [
            'result' => 'semantic',
            'reason' => 'enhanced_pattern_match',
            'query' => $text
          ]
        );
      }
      return 'semantic';
    }
    
    // Check analytics keywords
    if (AnalyticsPattern::hasAnalyticsKeywords($text)) {
      if (self::$logger) {
        self::$logger->logStructured(
          'info',
          'ClassificationEngine',
          'fallback_classification',
          [
            'result' => 'analytics',
            'reason' => 'keyword_match',
            'query' => $text
          ]
        );
      }
      return 'analytics';
    }
    
    // 🔧 TASK 4.3: Default to semantic for general questions (safer fallback)
    if (self::$logger) {
      self::$logger->logStructured(
        'info',
        'ClassificationEngine',
        'fallback_classification',
        [
          'result' => 'semantic',
          'reason' => 'default_fallback',
          'query' => $text,
          'note' => 'No patterns matched, defaulting to semantic (safer than analytics)'
        ]
      );
    }
    
    return 'semantic';
  }
  
  /**
   * Classifies query using GPT with improved prompt and JSON response
   * 
   * 🔧 TASK 4.5.4 (2025-12-11): Updated to use new classification prompt with JSON response
   * 
   * IMPROVEMENTS:
   * - Loads prompt from language file (rag_classification.txt)
   * - Returns structured array with type, confidence, reasoning
   * - Supports 4 categories: analytics, semantic, hybrid, web_search
   * - Validates confidence scores (0.0-1.0)
   * - Fallback to old prompt if new prompt fails
   * 
   * @param string $text Text to classify
   * @return array ['type' => string, 'confidence' => float, 'reasoning' => string, 'sub_types' => array]
   */
  public static function checkSemantics(string $text): array
  {
    self::initLogger();
    
    try {
      // Load language definitions for classification prompt
      $CLICSHOPPING_Language = \ClicShopping\OM\Registry::get('Language');
      $CLICSHOPPING_Language->loadDefinitions('rag_classification', 'en', null, 'ClicShoppingAdmin');
      
      // Load new classification prompt from language file
      $promptTemplate = \ClicShopping\OM\CLICSHOPPING::getDef('text_rag_classification');
      
      if (!$promptTemplate || $promptTemplate === 'text_rag_classification') {
        // Language definition not found, use fallback
        throw new \Exception('Classification prompt not found in language file');
      }
      
      // Replace {{QUERY}} placeholder with actual query
      $prompt = str_replace('{{QUERY}}', $text, $promptTemplate);
      
      // Get GPT response (expecting JSON)
      $response = Gpt::getGptResponse($prompt, 200); // Increased max tokens for JSON response
      
      // Log raw response for debugging
      if (self::$logger && defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && 
          CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True') {
        self::$logger->logStructured(
          'info',
          'ClassificationEngine',
          'checkSemantics_raw_response',
          [
            'query' => $text,
            'response' => $response
          ]
        );
      }
      
      // Try to parse JSON response
      $result = json_decode(trim($response), true);
      
      // If JSON parsing fails, try to extract classification from markdown/text response
      if (json_last_error() !== JSON_ERROR_NONE) {
        // Try multiple patterns to extract type and confidence from markdown response
        $type = null;
        $confidence = 0.5;
        $reasoning = '';
        $sub_types = [];
        
        // Pattern 1: **Classification: TYPE (confidence: X.X)**
        if (preg_match('/\*\*Classification:\s*(\w+)\s*\(confidence:\s*([\d.]+)\)\*\*/i', $response, $matches)) {
          $type = strtolower(trim($matches[1]));
          $confidence = (float)$matches[2];
        }
        // Pattern 2: **Classification**: TYPE (confidence: X.X)
        elseif (preg_match('/\*\*Classification\*\*:\s*(\w+)\s*\(confidence:\s*([\d.]+)\)/i', $response, $matches)) {
          $type = strtolower(trim($matches[1]));
          $confidence = (float)$matches[2];
        }
        // Pattern 3: TYPE (confidence: X.X) at start of line
        elseif (preg_match('/^[\s\-\*]*(\w+)\s*\(confidence:\s*([\d.]+)\)/im', $response, $matches)) {
          $type = strtolower(trim($matches[1]));
          $confidence = (float)$matches[2];
        }
        // Pattern 4: **Intent Type:** TYPE followed by **Confidence:** X.X
        elseif (preg_match('/\*\*Intent Type:\*\*\s*(\w+)/i', $response, $typeMatches) &&
                preg_match('/\*\*Confidence:\*\*\s*([\d.]+)/i', $response, $confMatches)) {
          $type = strtolower(trim($typeMatches[1]));
          $confidence = (float)$confMatches[1];
        }
        
        if (!$type) {
          throw new \Exception('Could not extract classification from response');
        }
        
        // Extract reasoning (text after "Reason:")
        if (preg_match('/\*\*Reason[^:]*:\*\*\s*(.+?)(?:\n\n|$)/s', $response, $reasonMatches)) {
          $reasoning = trim($reasonMatches[1]);
        } elseif (preg_match('/Reason:\s*(.+?)(?:\n\n|$)/s', $response, $reasonMatches)) {
          $reasoning = trim($reasonMatches[1]);
        }
        
        // Extract sub_types for hybrid queries
        if ($type === 'hybrid' && preg_match('/\(([^)]+)\s*\+\s*([^)]+)\)/i', $response, $subMatches)) {
          $sub_types = [trim($subMatches[1]), trim($subMatches[2])];
        }
        
        $result = [
          'type' => $type,
          'confidence' => $confidence,
          'reasoning' => $reasoning,
          'sub_types' => $sub_types
        ];
      }
      
      // Validate response structure
      if (!isset($result['type']) || !isset($result['confidence'])) {
        throw new \Exception('Missing required fields in JSON response');
      }
      
      // Validate type (must be one of 4 categories)
      $validTypes = ['analytics', 'semantic', 'hybrid', 'web_search'];
      if (!in_array($result['type'], $validTypes)) {
        throw new \Exception('Invalid type: ' . $result['type']);
      }
      
      // Validate confidence (must be between 0.0 and 1.0)
      $confidence = (float)$result['confidence'];
      if ($confidence < 0.0 || $confidence > 1.0) {
        throw new \Exception('Invalid confidence: ' . $confidence);
      }
      
      // Ensure sub_types exists (empty array if not hybrid)
      if (!isset($result['sub_types'])) {
        $result['sub_types'] = [];
      }
      
      // Log successful classification
      if (self::$logger && defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && 
          CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER === 'True') {
        self::$logger->logStructured(
          'info',
          'ClassificationEngine',
          'checkSemantics_success',
          [
            'query' => $text,
            'type' => $result['type'],
            'confidence' => $confidence,
            'reasoning' => $result['reasoning'] ?? 'N/A',
            'sub_types' => $result['sub_types']
          ]
        );
      }
      
      return [
        'type' => $result['type'],
        'confidence' => $confidence,
        'reasoning' => $result['reasoning'] ?? '',
        'sub_types' => $result['sub_types']
      ];
      
    } catch (\Exception $e) {
      // Log error
      if (self::$logger) {
        self::$logger->logStructured(
          'warning',
          'ClassificationEngine',
          'checkSemantics_fallback',
          [
            'query' => $text,
            'error' => $e->getMessage(),
            'fallback' => 'using old prompt'
          ]
        );
      }
      
      // Fallback to old prompt (simple text response)
      $definition = "An 'analytics' question seeks quantitative data, calculations, comparisons (e.g., trend, growth, total, average, ratio, sales, revenue, profit, stock, price range). A 'semantic' question seeks information, definitions, procedures, or general knowledge (e.g., how-to, policy, location, description, meaning).";
      $prompt = "Based on these definitions, determine if the following query is 'analytics' or 'semantic'. Respond with ONLY 'analytics' or 'semantic'.\nDefinitions: {$definition}\nQuery: {$text}\nAnswer:";

      $response = Gpt::getGptResponse($prompt, 20);
      $type = trim(strtolower($response));
      
      // Validate old prompt response
      if (!in_array($type, ['analytics', 'semantic'])) {
        $type = self::getFallbackClassification($text);
      }
      
      // Return in new format with default values
      return [
        'type' => $type,
        'confidence' => 0.5, // Default medium confidence
        'reasoning' => 'Fallback classification (old prompt)',
        'sub_types' => []
      ];
    }
  }
}
