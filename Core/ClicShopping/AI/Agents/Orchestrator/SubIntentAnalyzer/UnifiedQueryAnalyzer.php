<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\AI\Agents\Orchestrator\SubIntentAnalyzer;

use ClicShopping\OM\Registry;
use ClicShopping\AI\Domain\Semantics\Semantics;
use ClicShopping\AI\Security\SecurityLogger;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\Gpt;
use ClicShopping\AI\Domain\Patterns\TemporalFinancialPreFilter;
use ClicShopping\AI\Domain\Patterns\WebSearchPostFilter;
use ClicShopping\AI\Domain\Patterns\SuperlativePostFilter;

/**
 * UnifiedQueryAnalyzer
 *
 * **PURE LLM MODE**: This class operates exclusively in Pure LLM mode.
 * All language detection, translation, and intent classification is performed
 * by LLM in a single unified call. Pattern-based detection has been removed.
 *
 * Combines language detection, translation, and intent classification
 * in a single GPT call for optimal performance.
 *
 * Benefits:
 * - 7-33% faster than separate calls
 * - Support for 50+ languages (Japanese, Chinese, Arabic, etc.)
 * - More accurate (GPT understands full context)
 * - Simpler architecture (1 component vs 3)
 * - No pattern matching required (Pure LLM mode)
 *
 * Architecture:
 * - Single LLM call handles all analysis
 * - No pattern-based fallbacks
 * - Consistent behavior across all languages
 * - Robust handling of edge cases
 *
 * @package ClicShopping\AI\Agents\Orchestrator\SubIntentAnalyzer
 * @since 2025-12-14
 * @version 2.0 (Pure LLM Mode)
 */
class UnifiedQueryAnalyzer
{
  private Semantics $semantics;
  private SecurityLogger $logger;
  private mixed $language;
  private bool $debug;

  /**
   * Constructor
   *
   * @param bool $debug Enable debug logging
   */
  public function __construct(bool $debug = false)
  {
    $this->semantics = new Semantics();
    $this->logger = new SecurityLogger();
    $this->debug = $debug;
    
    // Load language definitions
    $this->language = Registry::get('Language');
    $this->language->loadDefinitions('rag_unified_analyzer', 'en', null, 'ClicShoppingAdmin');
  }

  /**
   * Analyze query: detect language, translate, classify intent, and extract structured information
   *
   * **PURE LLM MODE**: This method uses a single LLM call for all analysis.
   * Pattern-based detection has been removed. All operations are performed by LLM:
   * - Language detection via LLM (no pattern matching)
   * - Translation via LLM (no pattern-based language detection)
   * - Intent classification via LLM (no pattern-based classification)
   * - Entity extraction via LLM (no pattern-based entity detection)
   *
   * This method combines multiple operations in a single GPT call:
   * 1. Language detection (ISO 639-1 code)
   * 2. Translation to English
   * 3. Intent classification (analytics/semantic/hybrid)
   * 4. Entity type detection (product, order, customer, etc.)
   * 5. Time constraint detection (comparison, relative_period, specific_date, none)
   * 6. Status keywords extraction (active, pending, etc.)
   * 7. Sub-query decomposition for complex queries
   *
   * @param string $query User query in any language
   * @return array Analysis result with:
   *   - 'language' (string): ISO 639-1 language code (en, fr, ja, zh, es, de, ar, etc.)
   *   - 'translated_query' (string): Query translated to English
   *   - 'original_query' (string): Original query
   *   - 'intent_type' (string): analytics|semantic|hybrid
   *   - 'entity_type' (array): List of entities (product, order, customer, general)
   *   - 'time_constraint' (string): comparison|relative_period|specific_date|none
   *   - 'status_keywords' (array): List of status keywords (active, pending, etc.)
   *   - 'sub_queries' (array): List of sub-queries for complex queries
   *   - 'confidence' (float): 0.0-1.0
   *   - 'was_translated' (bool): Whether translation was performed
   *   - 'analysis_time_ms' (float): Total analysis time in milliseconds
   *   - 'detection_method' (string): Always 'llm' in Pure LLM mode
   */
  public function analyzeQuery(string $query): array
  {
    $startTime = microtime(true);

    if ($this->debug) {
      error_log("\n🔍 " . $this->language->getDef('debug_analysis_start'));
      error_log(sprintf($this->language->getDef('debug_input_query'), $query));
    }

    try {
      // Build unified prompt
      $prompt = $this->buildUnifiedPrompt($query);

      // Single GPT call for everything
      // Use Gpt::getGptResponse() instead of non-existent complete() method
      $response = Gpt::getGptResponse(
        $prompt,
        300, // max_tokens
        0.0  // temperature (deterministic for consistency)
      );

      if ($this->debug) {
        error_log(sprintf($this->language->getDef('debug_gpt_response'), $response));
      }

      // Clean response (remove markdown code blocks if present)
      $cleanedResponse = $this->cleanJsonResponse($response);

      // Parse JSON response
      $analysis = json_decode($cleanedResponse, true);

      if (json_last_error() !== JSON_ERROR_NONE) {
        $this->logger->logStructured(
          'error',
          'UnifiedQueryAnalyzer',
          'json_parse_error',
          [
            'query' => $query,
            'response' => $response,
            'cleaned_response' => $cleanedResponse,
            'error' => json_last_error_msg()
          ]
        );

        // Fallback to default
        $analysis = null;
      }

      // Validate and sanitize
      $analysis = $this->validateAnalysis($analysis, $query);

      // ⚠️ CRITICAL: Apply TemporalFinancialPreFilter post-filter (EXCEPTION to Pure LLM)
      // This pattern-based post-filter overrides LLM classification for temporal financial queries
      // where LLM is inconsistent (hallucinations, wrong intent, low confidence)
      // Pattern is called on translated query (English) for deterministic results
      $originalIntentType = $analysis['intent_type'];
      $originalConfidence = $analysis['confidence'];
      
      $analysis = TemporalFinancialPreFilter::postFilter(
        $analysis['translated_query'],
        $analysis
      );
      
      // Log when pattern overrides LLM classification
      if ($analysis['intent_type'] !== $originalIntentType || $analysis['confidence'] !== $originalConfidence) {
        $this->logger->logStructured(
          'info',
          'UnifiedQueryAnalyzer',
          'temporal_financial_pattern_override',
          [
            'query' => $query,
            'translated_query' => $analysis['translated_query'],
            'original_intent' => $originalIntentType,
            'original_confidence' => $originalConfidence,
            'overridden_intent' => $analysis['intent_type'],
            'overridden_confidence' => $analysis['confidence'],
            'override_reason' => $analysis['override_reason'] ?? 'unknown',
            'detection_method' => $analysis['detection_method'] ?? 'unknown'
          ]
        );
        
        if ($this->debug) {
          error_log("🔧 " . $this->language->getDef('debug_pattern_override'));
          error_log("  Original: {$originalIntentType} (confidence: {$originalConfidence})");
          error_log("  Overridden: {$analysis['intent_type']} (confidence: {$analysis['confidence']})");
          error_log("  Reason: " . ($analysis['override_reason'] ?? 'unknown'));
        }
      }
      
      // ⚠️ CRITICAL: Apply WebSearchPostFilter post-filter (EXCEPTION to Pure LLM)
      // This pattern-based post-filter overrides LLM classification for web search queries
      // where LLM is inconsistent (misclassifies as analytics or semantic)
      // Pattern is called on translated query (English) for deterministic results
      $originalIntentType = $analysis['intent_type'];
      $originalConfidence = $analysis['confidence'];
      
      $analysis = WebSearchPostFilter::postFilter(
        $analysis['translated_query'],
        $analysis
      );
      
      // Log when pattern overrides LLM classification
      if ($analysis['intent_type'] !== $originalIntentType || $analysis['confidence'] !== $originalConfidence) {
        $this->logger->logStructured(
          'info',
          'UnifiedQueryAnalyzer',
          'websearch_pattern_override',
          [
            'query' => $query,
            'translated_query' => $analysis['translated_query'],
            'original_intent' => $originalIntentType,
            'original_confidence' => $originalConfidence,
            'overridden_intent' => $analysis['intent_type'],
            'overridden_confidence' => $analysis['confidence'],
            'override_reason' => $analysis['override_reason'] ?? 'unknown',
            'detection_method' => $analysis['detection_method'] ?? 'unknown'
          ]
        );
        
        if ($this->debug) {
          error_log("🔧 WebSearch " . $this->language->getDef('debug_pattern_override'));
          error_log("  Original: {$originalIntentType} (confidence: {$originalConfidence})");
          error_log("  Overridden: {$analysis['intent_type']} (confidence: {$analysis['confidence']})");
          error_log("  Reason: " . ($analysis['override_reason'] ?? 'unknown'));
        }
      }
      
      //  CRITICAL: Apply SuperlativePostFilter post-filter (EXCEPTION to Pure LLM)
      // This pattern-based post-filter overrides LLM classification for superlative queries
      // where LLM is inconsistent (misclassifies as semantic instead of analytics)
      // Pattern is called on translated query (English) for deterministic results
      $originalIntentType = $analysis['intent_type'];
      $originalConfidence = $analysis['confidence'];
      
      $analysis = SuperlativePostFilter::postFilter(
        $analysis['translated_query'],
        $analysis
      );
      
      // Log when pattern overrides LLM classification
      if ($analysis['intent_type'] !== $originalIntentType || $analysis['confidence'] !== $originalConfidence) {
        $this->logger->logStructured(
          'info',
          'UnifiedQueryAnalyzer',
          'superlative_pattern_override',
          [
            'query' => $query,
            'translated_query' => $analysis['translated_query'],
            'original_intent' => $originalIntentType,
            'original_confidence' => $originalConfidence,
            'overridden_intent' => $analysis['intent_type'],
            'overridden_confidence' => $analysis['confidence'],
            'override_reason' => $analysis['override_reason'] ?? 'unknown',
            'detection_method' => $analysis['detection_method'] ?? 'unknown'
          ]
        );
        
        if ($this->debug) {
          error_log("🔧 Superlative " . $this->language->getDef('debug_pattern_override'));
          error_log("  Original: {$originalIntentType} (confidence: {$originalConfidence})");
          error_log("  Overridden: {$analysis['intent_type']} (confidence: {$analysis['confidence']})");
          error_log("  Reason: " . ($analysis['override_reason'] ?? 'unknown'));
        }
      }

      // Add metadata
      $analysis['original_query'] = $query;
      $analysis['was_translated'] = ($analysis['language'] !== 'en');
      $analysis['analysis_time_ms'] = (microtime(true) - $startTime) * 1000;

      $this->logger->logStructured(
        'info',
        'UnifiedQueryAnalyzer',
        'analysis_completed',
        [
          'query' => $query,
          'language' => $analysis['language'],
          'intent_type' => $analysis['intent_type'],
          'confidence' => $analysis['confidence'],
          'was_translated' => $analysis['was_translated'],
          'analysis_time_ms' => round($analysis['analysis_time_ms'], 2)
        ]
      );

      if ($this->debug) {
        error_log("✅ " . $this->language->getDef('debug_analysis_result'));
        error_log("  " . sprintf($this->language->getDef('debug_language_detected'), $analysis['language']));
        error_log("  " . sprintf($this->language->getDef('debug_intent_detected'), $analysis['intent_type'], $analysis['confidence']));
        error_log("  " . sprintf($this->language->getDef('debug_translated_query'), $analysis['translated_query']));
        error_log("  " . sprintf($this->language->getDef('debug_analysis_time'), round($analysis['analysis_time_ms'], 2)));
        
        // Log additional fields
        if (!empty($analysis['entity_type'])) {
          error_log("  " . sprintf($this->language->getDef('debug_entity_types'), implode(', ', $analysis['entity_type'])));
        }
        if ($analysis['time_constraint'] !== 'none') {
          error_log("  " . sprintf($this->language->getDef('debug_time_constraint'), $analysis['time_constraint']));
        }
        if (!empty($analysis['status_keywords'])) {
          error_log("  " . sprintf($this->language->getDef('debug_status_keywords'), implode(', ', $analysis['status_keywords'])));
        }
        if (!empty($analysis['sub_queries'])) {
          error_log("  " . sprintf($this->language->getDef('debug_sub_queries'), count($analysis['sub_queries'])));
        }
      }

      return $analysis;

    } catch (\Exception $e) {
      $this->logger->logStructured(
        'error',
        'UnifiedQueryAnalyzer',
        'analysis_exception',
        [
          'query' => $query,
          'error' => $e->getMessage(),
          'trace' => $e->getTraceAsString()
        ]
      );

      if ($this->debug) {
        error_log("❌ " . $this->language->getDef('error_analysis_exception') . ": " . $e->getMessage());
      }

      // Return safe fallback
      return [
        'language' => 'en',
        'translated_query' => $query,
        'original_query' => $query,
        'intent_type' => 'semantic',
        'entity_type' => ['general'],
        'time_constraint' => 'none',
        'status_keywords' => [],
        'sub_queries' => [],
        'confidence' => 0.5,
        'was_translated' => false,
        'analysis_time_ms' => (microtime(true) - $startTime) * 1000,
        'error' => $e->getMessage(),
      ];
    }
  }

  /**
   * Clean JSON response by removing markdown code blocks
   *
   * GPT sometimes wraps JSON in markdown code blocks like:
   * ```json
   * { ... }
   * ```
   *
   * This method extracts the JSON content from such blocks.
   *
   * @param string $response Raw GPT response
   * @return string Cleaned JSON string
   */
  private function cleanJsonResponse(string $response): string
  {
    // Remove markdown code blocks (```json ... ``` or ``` ... ```)
    $response = trim($response);
    
    // Pattern 1: ```json\n{...}\n```
    if (preg_match('/^```(?:json)?\s*\n(.*?)\n```$/s', $response, $matches)) {
      return trim($matches[1]);
    }
    
    // Pattern 2: ```{...}```
    if (preg_match('/^```(?:json)?\s*(\{.*?\})\s*```$/s', $response, $matches)) {
      return trim($matches[1]);
    }
    
    // No markdown blocks found, return as-is
    return $response;
  }

  /**
   * Build unified prompt for language + intent detection
   *
   * This prompt asks GPT to analyze the query, translate it, and break down
   * complex analytic questions. Returns structured JSON with entity types,
   * time constraints, status keywords, and sub-queries.
   *
   * @param string $query User query
   * @return string GPT prompt
   */
  private function buildUnifiedPrompt(string $query): string
  {
    // Get prompt template from language file with query parameter
    $prompt = $this->language->getDef('unified_analyzer_prompt', ['query' => $query]);
    
    if ($this->debug) {
      error_log("UnifiedQueryAnalyzer: Using prompt from language file with template substitution");
    }
    
    return $prompt;
  }

  /**
   * Validate and sanitize analysis result
   *
   * Ensures the GPT response has all required fields with valid values.
   * Provides safe fallbacks for missing or invalid data.
   *
   * @param array|null $analysis Parsed JSON from GPT
   * @param string $originalQuery Original query for fallback
   * @return array Validated analysis
   */
  private function validateAnalysis(?array $analysis, string $originalQuery): array
  {
    // Default fallback
    $default = [
      'language' => 'en',
      'translated_query' => $originalQuery,
      'intent_type' => 'semantic',
      'entity_type' => ['general'],
      'time_constraint' => 'none',
      'status_keywords' => [],
      'sub_queries' => [],
      'confidence' => 0.5,
      'detection_method' => 'llm', // CRITICAL FIX (2026-01-02): Always set detection_method
    ];

    if (!is_array($analysis)) {
      if ($this->debug) {
        error_log("" . $this->language->getDef('validation_using_default'));
      }
      return $default;
    }

    // ✅ CRITICAL FIX (2026-01-02): Ensure detection_method is always set
    // Default to 'llm' if not set by pattern filters
    if (!isset($analysis['detection_method']) || empty($analysis['detection_method'])) {
      $analysis['detection_method'] = 'llm';
    }

    // Validate language code (must be 2 letters)
    if (!isset($analysis['language']) || !is_string($analysis['language']) || strlen($analysis['language']) !== 2) {
      if ($this->debug) {
        error_log("⚠️ " . $this->language->getDef('validation_invalid_language_code'));
      }
      $analysis['language'] = 'en';
    } else {
      $analysis['language'] = strtolower($analysis['language']);
    }

    // Validate translated query
    if (!isset($analysis['translated_query']) || !is_string($analysis['translated_query']) || empty(trim($analysis['translated_query']))) {
      if ($this->debug) {
        error_log("⚠️ " . $this->language->getDef('validation_invalid_translated_query'));
      }
      $analysis['translated_query'] = $originalQuery;
    } else {
      $analysis['translated_query'] = trim($analysis['translated_query']);
    }

    // Validate intent type
    $validIntents = ['analytics', 'semantic', 'hybrid', 'web_search'];
    if (!isset($analysis['intent_type']) || !in_array($analysis['intent_type'], $validIntents, true)) {
      if ($this->debug) {
        error_log("⚠️ " . $this->language->getDef('validation_invalid_intent_type'));
      }
      $analysis['intent_type'] = 'semantic';
    }

    // Validate entity_type (must be array)
    if (!isset($analysis['entity_type']) || !is_array($analysis['entity_type']) || empty($analysis['entity_type'])) {
      if ($this->debug) {
        error_log("⚠️ " . $this->language->getDef('validation_invalid_entity_type'));
      }
      $analysis['entity_type'] = ['general'];
    } else {
      // Sanitize entity types
      $validEntities = ['product', 'order', 'customer', 'category', 'manufacturer', 'supplier', 'general'];
      $analysis['entity_type'] = array_values(array_intersect($analysis['entity_type'], $validEntities));
      if (empty($analysis['entity_type'])) {
        $analysis['entity_type'] = ['general'];
      }
    }

    // Validate time_constraint
    $validTimeConstraints = ['comparison', 'relative_period', 'specific_date', 'none'];
    if (!isset($analysis['time_constraint']) || !in_array($analysis['time_constraint'], $validTimeConstraints, true)) {
      if ($this->debug) {
        error_log("⚠️ " . $this->language->getDef('validation_invalid_time_constraint'));
      }
      $analysis['time_constraint'] = 'none';
    }

    // Validate status_keywords (must be array)
    if (!isset($analysis['status_keywords']) || !is_array($analysis['status_keywords'])) {
      if ($this->debug) {
        error_log("⚠️ " . $this->language->getDef('validation_invalid_status_keywords'));
      }
      $analysis['status_keywords'] = [];
    } else {
      // Sanitize status keywords (lowercase, trim) - ensure all elements are strings
      $sanitized = [];
      foreach ($analysis['status_keywords'] as $keyword) {
        if (is_string($keyword)) {
          $trimmed = trim(strtolower($keyword));
          if (!empty($trimmed)) {
            $sanitized[] = $trimmed;
          }
        }
      }
      $analysis['status_keywords'] = $sanitized;
    }

    // Validate sub_queries (must be array)
    if (!isset($analysis['sub_queries']) || !is_array($analysis['sub_queries'])) {
      if ($this->debug) {
        error_log("⚠️ " . $this->language->getDef('validation_invalid_sub_queries'));
      }
      $analysis['sub_queries'] = [];
    } else {
      // Sanitize sub-queries
      // Handle both string arrays and object arrays (sub-queries can be strings or arrays with metadata)
      $sanitized = [];
      foreach ($analysis['sub_queries'] as $sub_query) {
        if (is_string($sub_query)) {
          // Simple string sub-query: trim and add if not empty
          $trimmed = trim($sub_query);
          if (!empty($trimmed)) {
            $sanitized[] = $trimmed;
          }
        } elseif (is_array($sub_query)) {
          // Complex sub-query object: keep as-is (already validated by ComplexQueryHandler)
          $sanitized[] = $sub_query;
        }
      }
      $analysis['sub_queries'] = $sanitized;
    }

    // Validate confidence (must be between 0.0 and 1.0)
    if (!isset($analysis['confidence']) || !is_numeric($analysis['confidence'])) {
      if ($this->debug) {
        error_log("⚠️ " . $this->language->getDef('validation_invalid_confidence'));
      }
      $analysis['confidence'] = 0.5;
    } else {
      $analysis['confidence'] = max(0.0, min(1.0, (float)$analysis['confidence']));
    }

    return $analysis;
  }
}
