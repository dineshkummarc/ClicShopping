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
use ClicShopping\AI\DomainsAI\DomainRegistry;
use ClicShopping\AI\DomainsAI\Semantic\Agent\SemanticAgent;
use ClicShopping\AI\Security\SecurityLogger;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\Gpt;
use ClicShopping\AI\DomainsAI\WebSearch\Patterns\WebSearchPostFilter;
use ClicShopping\AI\DomainsAI\Analytics\Patterns\SuperlativePostFilter;
use ClicShopping\AI\DomainsAI\Analytics\Patterns\MultiTemporalPostFilter;
use ClicShopping\AI\DomainsAI\Analytics\Patterns\TimeRangePattern;
use ClicShopping\AI\DomainsAI\Analytics\Patterns\TemporalPeriodMappingPattern;
use ClicShopping\AI\Config\DomainConfig;

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
  private SemanticAgent $semantics;
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
    $this->semantics = new SemanticAgent();
    $this->logger = new SecurityLogger();
    $this->debug = $debug;
    
    // Load language definitions
    $this->language = Registry::get('Language');
    DomainConfig::loadLanguageFile('rag_unified_analyzer');
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
   * - Temporal metadata extraction via LLM (periods, connectors, base metric, time range)
   *
   * This method combines multiple operations in a single GPT call:
   * 1. Language detection (ISO 639-1 code)
   * 2. Translation to English
   * 3. Intent classification (analytics/semantic/hybrid)
   * 4. Entity type detection (product, order, customer, etc.)
   * 5. Time constraint detection (comparison, relative_period, specific_date, none)
   * 6. Status keywords extraction (active, pending, etc.)
   * 7. Sub-query decomposition for complex queries
   * 8. Temporal metadata extraction (periods, connectors, base metric, time range)
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
   *   - 'is_multi_temporal' (bool): Whether query contains multiple temporal aggregations
   *   - 'temporal_periods' (array): List of temporal periods (month, quarter, semester, etc.)
   *   - 'temporal_connectors' (array): List of temporal connectors (then, and, etc.)
   *   - 'base_metric' (string|null): Base metric for temporal queries (revenue, sales, etc.)
   *   - 'time_range' (string|null): Time range for temporal queries (year 2025, etc.)
   *   - 'temporal_period_count' (int): Number of temporal periods detected
   */
  public function analyzeQuery(string $query): array
  {
    $startTime = microtime(true);

    if ($this->debug) {
      error_log("[info] " . $this->language->getDef('debug_analysis_start'));
      error_log(sprintf($this->language->getDef('debug_input_query'), $query));
    }

    try {
      //  CRITICAL: Pre-translate query to English using Semantics
      // This ensures the query is in English BEFORE sending to LLM for classification
      // The LLM classification prompt works best with English input
      $originalQuery = $query;
      $preTranslatedQuery = $this->semantics->translateToEnglish($query);

      // Use pre-translated query if translation was successful
      if (!empty($preTranslatedQuery) && $preTranslatedQuery !== $query) {
        $query = $preTranslatedQuery;
        if ($this->debug) {
          error_log("📝 Pre-translated query: {$query}");
        }
      }
      
      // Build unified prompt with (potentially pre-translated) query
      $prompt = $this->buildUnifiedPrompt($query);
      
      // 🔍 DEBUG: Log prompt loading verification
      if ($this->debug) {
        error_log("\n🔍 [UnifiedQueryAnalyzer] Prompt Loading Verification:");
        error_log("  Prompt length: " . strlen($prompt) . " characters");
        error_log("  Prompt preview (first 200 chars): " . substr($prompt, 0, 200) . "...");
        error_log("  Query in prompt: " . (strpos($prompt, $query) !== false ? 'YES' : 'NO'));
      }

      // Single GPT call for everything
      // Use Gpt::getGptResponse() instead of non-existent complete() method
      
      // 🔍 DEBUG: Log before GPT call
      if ($this->debug) {
        error_log("\n🔍 [UnifiedQueryAnalyzer] Calling Gpt::getGptResponse():");
        error_log("  Max tokens: 300");
        error_log("  Temperature: 0.0");
        error_log("  Timestamp: " . date('Y-m-d H:i:s'));
      }
      
      $response = Gpt::getGptResponse(
        $prompt,
        300, // max_tokens
        0.0  // temperature (deterministic for consistency)
      );

      // 🔍 DEBUG: Log GPT response
      if ($this->debug) {
        error_log("\n🔍 [UnifiedQueryAnalyzer] GPT Response Received:");
        error_log("  Response length: " . strlen($response) . " characters");
        error_log("  Response type: " . gettype($response));
        error_log("  Response preview (first 500 chars): " . substr($response, 0, 500));
        error_log("  Full response: " . $response);
        error_log(sprintf($this->language->getDef('debug_gpt_response'), $response));
      }

      // Clean response (remove markdown code blocks if present)
      $cleanedResponse = $this->cleanJsonResponse($response);
      
      // 🔍 DEBUG: Log JSON cleaning
      if ($this->debug) {
        error_log("\n🔍 [UnifiedQueryAnalyzer] JSON Response Cleaning:");
        error_log("  Original response length: " . strlen($response));
        error_log("  Cleaned response length: " . strlen($cleanedResponse));
        error_log("  Was cleaned: " . ($response !== $cleanedResponse ? 'YES' : 'NO'));
        error_log("  Cleaned response: " . $cleanedResponse);
      }

      // Parse JSON response
      $analysis = json_decode($cleanedResponse, true);
      
      // 🔍 DEBUG: Log JSON parsing
      if ($this->debug) {
        error_log("\n🔍 [UnifiedQueryAnalyzer] JSON Parsing:");
        error_log("  JSON decode success: " . (json_last_error() === JSON_ERROR_NONE ? 'YES' : 'NO'));
        if (json_last_error() !== JSON_ERROR_NONE) {
          error_log("  JSON error: " . json_last_error_msg());
          error_log("  JSON error code: " . json_last_error());
        } else {
          error_log("  Parsed array keys: " . implode(', ', array_keys($analysis ?? [])));
          error_log("  Intent type in response: " . ($analysis['intent_type'] ?? 'NOT SET'));
          error_log("  Language in response: " . ($analysis['language'] ?? 'NOT SET'));
          error_log("  Confidence in response: " . ($analysis['confidence'] ?? 'NOT SET'));
        }
      }

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
      
      // 🔍 DEBUG: Log intent_type detection after validation
      if ($this->debug) {
        error_log("\n🔍 [UnifiedQueryAnalyzer] Intent Type Detection (After Validation):");
        error_log("  Detected intent_type: " . ($analysis['intent_type'] ?? 'NOT SET'));
        error_log("  Confidence: " . ($analysis['confidence'] ?? 'NOT SET'));
        error_log("  Detection method: " . ($analysis['detection_method'] ?? 'NOT SET'));
        error_log("  Language: " . ($analysis['language'] ?? 'NOT SET'));
        error_log("  Translated query: " . ($analysis['translated_query'] ?? 'NOT SET'));
        error_log("  Entity types: " . implode(', ', $analysis['entity_type'] ?? []));
        error_log("  Time constraint: " . ($analysis['time_constraint'] ?? 'NOT SET'));
      }

      // ⚠️ CRITICAL: Apply AnalyticsPatterns post-filter (EXCEPTION to Pure LLM)
      // This pattern-based post-filter overrides LLM classification for temporal financial queries
      // where LLM is inconsistent (hallucinations, wrong intent, low confidence)
      // Pattern is called on translated query (English) for deterministic results
      // TASK 2026-01-23: Load AnalyticsPatterns dynamically from active domain
      $originalIntentType = $analysis['intent_type'];
      $originalConfidence = $analysis['confidence'];
      
      // Load AnalyticsPatterns from active domain (domain-agnostic approach)
      $domainApp = DomainRegistry::getInstance()->getActiveApp();
      if ($domainApp && method_exists($domainApp, 'getAnalyticsPatternsClass')) {
        $analyticsPatternsClass = $domainApp->getAnalyticsPatternsClass();
        
        if ($analyticsPatternsClass && class_exists($analyticsPatternsClass)) {
          $analysis = $analyticsPatternsClass::postFilter(
            $analysis['translated_query'],
            $analysis
          );
        }
      }
      
      // Log when pattern overrides LLM classification
      if ($analysis['intent_type'] !== $originalIntentType || $analysis['confidence'] !== $originalConfidence) {
        // 🔍 DEBUG: Enhanced logging for pattern override
        if ($this->debug) {
          error_log("\n🔍 [UnifiedQueryAnalyzer] TemporalFinancialPreFilter Override:");
          error_log("  BEFORE - Intent: {$originalIntentType}, Confidence: {$originalConfidence}");
          error_log("  AFTER  - Intent: {$analysis['intent_type']}, Confidence: {$analysis['confidence']}");
          error_log("  Override reason: " . ($analysis['override_reason'] ?? 'unknown'));
          error_log("  Detection method: " . ($analysis['detection_method'] ?? 'unknown'));
        }
        
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
            'detection_method' => $analysis['detection_method'] ?? 'unknown',
            'domain' => $domainApp ? $domainApp->getDomainId() : 'none'
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
        // 🔍 DEBUG: Enhanced logging for pattern override
        if ($this->debug) {
          error_log("\n🔍 [UnifiedQueryAnalyzer] WebSearchPostFilter Override:");
          error_log("  BEFORE - Intent: {$originalIntentType}, Confidence: {$originalConfidence}");
          error_log("  AFTER  - Intent: {$analysis['intent_type']}, Confidence: {$analysis['confidence']}");
          error_log("  Override reason: " . ($analysis['override_reason'] ?? 'unknown'));
          error_log("  Detection method: " . ($analysis['detection_method'] ?? 'unknown'));
        }
        
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
        // 🔍 DEBUG: Enhanced logging for pattern override
        if ($this->debug) {
          error_log("\n🔍 [UnifiedQueryAnalyzer] SuperlativePostFilter Override:");
          error_log("  BEFORE - Intent: {$originalIntentType}, Confidence: {$originalConfidence}");
          error_log("  AFTER  - Intent: {$analysis['intent_type']}, Confidence: {$analysis['confidence']}");
          error_log("  Override reason: " . ($analysis['override_reason'] ?? 'unknown'));
          error_log("  Detection method: " . ($analysis['detection_method'] ?? 'unknown'));
        }
        
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
      
      //  CRITICAL: Apply MultiTemporalPostFilter post-filter (EXCEPTION to Pure LLM)
      // This pattern-based post-filter overrides LLM classification for multi-temporal queries
      // where LLM is inconsistent (classifies as analytics instead of hybrid)
      // Pattern is called on translated query (English) for deterministic results
      $originalIntentType = $analysis['intent_type'];
      $originalConfidence = $analysis['confidence'];
      
      $analysis = MultiTemporalPostFilter::postFilter(
        $analysis['translated_query'],
        $analysis
      );
      
      // Log when pattern overrides LLM classification
      if ($analysis['intent_type'] !== $originalIntentType || $analysis['confidence'] !== $originalConfidence) {
        // 🔍 DEBUG: Enhanced logging for pattern override
        if ($this->debug) {
          error_log("\n🔍 [UnifiedQueryAnalyzer] MultiTemporalPostFilter Override:");
          error_log("  BEFORE - Intent: {$originalIntentType}, Confidence: {$originalConfidence}");
          error_log("  AFTER  - Intent: {$analysis['intent_type']}, Confidence: {$analysis['confidence']}");
          error_log("  Override reason: " . ($analysis['override_reason'] ?? 'unknown'));
          error_log("  Detection method: " . ($analysis['detection_method'] ?? 'unknown'));
          error_log("  Temporal periods: " . implode(', ', $analysis['temporal_periods'] ?? []));
          error_log("  Temporal connectors: " . implode(', ', $analysis['temporal_connectors'] ?? []));
        }
        
        $this->logger->logStructured(
          'info',
          'UnifiedQueryAnalyzer',
          'multi_temporal_pattern_override',
          [
            'query' => $query,
            'translated_query' => $analysis['translated_query'],
            'original_intent' => $originalIntentType,
            'original_confidence' => $originalConfidence,
            'overridden_intent' => $analysis['intent_type'],
            'overridden_confidence' => $analysis['confidence'],
            'override_reason' => $analysis['override_reason'] ?? 'unknown',
            'detection_method' => $analysis['detection_method'] ?? 'unknown',
            'temporal_periods' => $analysis['temporal_periods'] ?? [],
            'temporal_connectors' => $analysis['temporal_connectors'] ?? []
          ]
        );
        
        if ($this->debug) {
          error_log("🔧 MultiTemporal " . $this->language->getDef('debug_pattern_override'));
          error_log("  Original: {$originalIntentType} (confidence: {$originalConfidence})");
          error_log("  Overridden: {$analysis['intent_type']} (confidence: {$analysis['confidence']})");
          error_log("  Reason: " . ($analysis['override_reason'] ?? 'unknown'));
          error_log("  Temporal Periods: " . implode(', ', $analysis['temporal_periods'] ?? []));
        }
      }
      
      // ⚠️ CRITICAL: Extract temporal metadata for ALL queries (not just overridden ones)
      // This ensures temporal metadata is always available for downstream processing
      // even when the LLM correctly classifies the query as hybrid
      $analysis = $this->extractTemporalMetadata($analysis);

      // Add metadata
      // Use $originalQuery (before pre-translation) as the true original
      $analysis['original_query'] = $originalQuery;
      $analysis['was_translated'] = ($analysis['language'] !== 'en') || ($originalQuery !== $query);
      $analysis['analysis_time_ms'] = (microtime(true) - $startTime) * 1000;
      
      // 🔍 DEBUG: Final analysis summary
      if ($this->debug) {
        error_log("\n🔍 [UnifiedQueryAnalyzer] FINAL ANALYSIS SUMMARY:");
        error_log("  ========================================");
        error_log("  Original query: " . $originalQuery);
        error_log("  Translated query: " . $analysis['translated_query']);
        error_log("  Language: " . $analysis['language']);
        error_log("  Was translated: " . ($analysis['was_translated'] ? 'YES' : 'NO'));
        error_log("  ========================================");
        error_log("  🎯 FINAL INTENT TYPE: " . $analysis['intent_type']);
        error_log("  🎯 FINAL CONFIDENCE: " . $analysis['confidence']);
        error_log("  🎯 DETECTION METHOD: " . ($analysis['detection_method'] ?? 'unknown'));
        error_log("  ========================================");
        error_log("  Entity types: " . implode(', ', $analysis['entity_type'] ?? []));
        error_log("  Time constraint: " . $analysis['time_constraint']);
        error_log("  Status keywords: " . implode(', ', $analysis['status_keywords'] ?? []));
        error_log("  Sub-queries count: " . count($analysis['sub_queries'] ?? []));
        error_log("  Analysis time: " . round($analysis['analysis_time_ms'], 2) . " ms");
        error_log("  ========================================\n");
      }

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
          'analysis_time_ms' => round($analysis['analysis_time_ms'], 2),
          'is_multi_temporal' => $analysis['is_multi_temporal'] ?? false,
          'temporal_periods' => $analysis['temporal_periods'] ?? [],
          'temporal_connectors' => $analysis['temporal_connectors'] ?? [],
          'base_metric' => $analysis['base_metric'] ?? null,
          'time_range' => $analysis['time_range'] ?? null,
          'temporal_period_count' => $analysis['temporal_period_count'] ?? 0
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
        
        // Log temporal metadata
        if (!empty($analysis['is_multi_temporal']) && $analysis['is_multi_temporal']) {
          error_log("  🕐 Multi-Temporal Query Detected:");
          error_log("    Temporal Periods: " . implode(', ', $analysis['temporal_periods'] ?? []));
          error_log("    Temporal Connectors: " . implode(', ', $analysis['temporal_connectors'] ?? []));
          error_log("    Base Metric: " . ($analysis['base_metric'] ?? 'none'));
          error_log("    Time Range: " . ($analysis['time_range'] ?? 'none'));
          error_log("    Period Count: " . ($analysis['temporal_period_count'] ?? 0));
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
        error_log("[error] " . $this->language->getDef('error_analysis_exception') . ": " . $e->getMessage());
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
        'is_multi_temporal' => false,
        'temporal_periods' => [],
        'temporal_connectors' => [],
        'base_metric' => null,
        'time_range' => null,
        'temporal_period_count' => 0,
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
    // Build prompt by concatenating sections from language file
    // This approach allows better maintainability and avoids placeholder issues
    
    $prompt = '';
    $prompt .= $this->language->getDef('unified_analyzer_prompt_header') . "\n\n";
    $prompt .= $this->language->getDef('unified_analyzer_prompt_anti_hallucination') . "\n\n";
    $prompt .= $this->language->getDef('unified_analyzer_prompt_multi_temporal') . "\n\n";
    $prompt .= $this->language->getDef('unified_analyzer_prompt_compound') . "\n\n";
    $prompt .= $this->language->getDef('unified_analyzer_prompt_basic_analytics') . "\n\n";
    $prompt .= $this->language->getDef('unified_analyzer_prompt_classification') . "\n\n";
    $prompt .= $this->language->getDef('unified_analyzer_prompt_output_format') . "\n\n";
    $prompt .= $this->language->getDef('unified_analyzer_prompt_query_section') . "\n";
    $prompt .= $query . "\n\n";  // Insert the actual query here
    $prompt .= $this->language->getDef('unified_analyzer_prompt_final_instructions') . "\n";
    
    if ($this->debug) {
      error_log("UnifiedQueryAnalyzer: Built prompt from language file sections");
      error_log("UnifiedQueryAnalyzer: Query to analyze: {$query}");
      error_log("UnifiedQueryAnalyzer: Total prompt length: " . strlen($prompt) . " characters");
      error_log("UnifiedQueryAnalyzer: Prompt contains query: " . (strpos($prompt, $query) !== false ? 'YES' : 'NO'));
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
      'is_multi_temporal' => false,
      'temporal_periods' => [],
      'temporal_connectors' => [],
      'base_metric' => null,
      'time_range' => null,
      'temporal_period_count' => 0,
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
  
  /**
   * Extract temporal metadata from analysis result
   *
   * This method ensures temporal metadata is always present in the analysis result,
   * even when the MultiTemporalPostFilter doesn't override the LLM classification.
   * It uses the MultiTemporalPostFilter's detection methods to extract:
   * - temporal_periods: List of temporal periods (month, quarter, semester, etc.)
   * - temporal_connectors: List of temporal connectors (then, and, etc.)
   * - base_metric: Base metric for temporal queries (revenue, sales, etc.)
   * - time_range: Time range for temporal queries (year 2025, etc.)
   * - is_multi_temporal: Whether query contains multiple temporal aggregations
   * - temporal_period_count: Number of temporal periods detected
   *
   * @param array $analysis The analysis result from LLM and post-filters
   * @return array Analysis result with temporal metadata fields populated
   */
  private function extractTemporalMetadata(array $analysis): array
  {
    // If temporal metadata already exists (from MultiTemporalPostFilter override), return as-is
    if (isset($analysis['is_multi_temporal']) && $analysis['is_multi_temporal'] === true) {
      // Ensure all fields are present
      $analysis['temporal_periods'] = $analysis['temporal_periods'] ?? [];
      $analysis['temporal_connectors'] = $analysis['temporal_connectors'] ?? [];
      
      // Load AnalyticsPatterns dynamically from active domain
      $domainApp = DomainRegistry::getInstance()->getActiveApp();
      if ($domainApp && method_exists($domainApp, 'getAnalyticsPatternsClass')) {
        $analyticsPatternsClass = $domainApp->getAnalyticsPatternsClass();
        if ($analyticsPatternsClass && class_exists($analyticsPatternsClass) && method_exists($analyticsPatternsClass, 'extractBaseMetric')) {
          $analysis['base_metric'] = $analysis['base_metric'] ?? $analyticsPatternsClass::extractBaseMetric($analysis['translated_query'] ?? '');
        } else {
          $analysis['base_metric'] = $analysis['base_metric'] ?? null;
        }
      } else {
        $analysis['base_metric'] = $analysis['base_metric'] ?? null;
      }
      
      $analysis['time_range'] = $analysis['time_range'] ?? TimeRangePattern::extractTimeRange($analysis['translated_query'] ?? '');
      $analysis['temporal_period_count'] = count($analysis['temporal_periods']);
      return $analysis;
    }
    
    // Extract temporal metadata from translated query
    $translatedQuery = $analysis['translated_query'] ?? '';
    
    // Use MultiTemporalPostFilter's detection methods
    $temporalPeriods = MultiTemporalPostFilter::getDetectedTemporalPeriods($translatedQuery);
    $temporalConnectors = MultiTemporalPostFilter::getDetectedTemporalConnectors($translatedQuery);
    
    // Determine if this is a multi-temporal query
    $isMultiTemporal = count($temporalPeriods) >= 2 && !empty($temporalConnectors);
    
    // Extract base metric and time range using pattern classes
    // Load AnalyticsPatterns dynamically from active domain
    $baseMetric = null;
    $domainApp = DomainRegistry::getInstance()->getActiveApp();
    if ($domainApp && method_exists($domainApp, 'getAnalyticsPatternsClass')) {
      $analyticsPatternsClass = $domainApp->getAnalyticsPatternsClass();
      if ($analyticsPatternsClass && class_exists($analyticsPatternsClass) && method_exists($analyticsPatternsClass, 'extractBaseMetric')) {
        $baseMetric = $analyticsPatternsClass::extractBaseMetric($translatedQuery);
      }
    }
    
    $timeRange = TimeRangePattern::extractTimeRange($translatedQuery);
    
    // Populate temporal metadata fields
    $analysis['is_multi_temporal'] = $isMultiTemporal;
    $analysis['temporal_periods'] = $temporalPeriods;
    $analysis['temporal_connectors'] = $temporalConnectors;
    $analysis['base_metric'] = $baseMetric;
    $analysis['time_range'] = $timeRange;
    $analysis['temporal_period_count'] = count($temporalPeriods);
    
    // Log temporal metadata extraction
    if ($this->debug && ($isMultiTemporal || !empty($temporalPeriods))) {
      error_log("🕐 Temporal Metadata Extracted:");
      error_log("  Is Multi-Temporal: " . ($isMultiTemporal ? 'yes' : 'no'));
      error_log("  Periods: " . implode(', ', $temporalPeriods));
      error_log("  Connectors: " . implode(', ', $temporalConnectors));
      error_log("  Base Metric: " . ($baseMetric ?? 'none'));
      error_log("  Time Range: " . ($timeRange ?? 'none'));
    }
    
    return $analysis;
  }

  /**
   * Handle unrecognized temporal periods
   *
   * **Requirement 8.3**: Fall back to LLM interpretation for unrecognized temporal periods
   *
   * When a temporal period is detected but not in the standard list (month, quarter, 
   * semester, year, week, day), this method attempts to interpret it using LLM.
   *
   * Examples of unrecognized periods that might be interpreted:
   * - "biweekly" → every 2 weeks
   * - "fortnightly" → every 2 weeks
   * - "bimonthly" → every 2 months
   * - "trimester" → every 3 months (similar to quarter)
   * - "fiscal year" → year (with fiscal calendar)
   * - "rolling 12 months" → custom period
   *
   * @param string $unrecognizedPeriod The unrecognized temporal period string
   * @param string $query The full query for context
   * @return array Interpretation result with structure:
   *   - recognized: bool (true if LLM could interpret)
   *   - standard_period: string|null (mapped to standard period if possible)
   *   - custom_period: array|null (custom period definition if not standard)
   *   - interpretation: string (human-readable interpretation)
   *   - confidence: float (0.0-1.0)
   *   - needs_clarification: bool (true if user should confirm)
   *   - clarification_message: string|null (message to show user)
   */
  public function handleUnrecognizedTemporalPeriod(string $unrecognizedPeriod, string $query): array
  {
    $defaultResult = [
      'recognized' => false,
      'standard_period' => null,
      'custom_period' => null,
      'interpretation' => "Could not interpret temporal period: {$unrecognizedPeriod}",
      'confidence' => 0.0,
      'needs_clarification' => true,
      'clarification_message' => "I'm not sure what '{$unrecognizedPeriod}' means. Could you specify a standard time period like month, quarter, semester, or year?",
    ];

    // First, try pattern-based mapping for common variations
    $patternMapping = TemporalPeriodMappingPattern::mapPeriod($unrecognizedPeriod);
    if ($patternMapping['recognized']) {
      $this->logger->logStructured(
        'info',
        'UnifiedQueryAnalyzer',
        'unrecognized_period_mapped_by_pattern',
        [
          'original' => $unrecognizedPeriod,
          'mapped_to' => $patternMapping['standard_period'] ?? $patternMapping['custom_period'],
          'confidence' => $patternMapping['confidence'],
        ]
      );
      return $patternMapping;
    }

    // Fall back to LLM interpretation
    try {
      $llmInterpretation = $this->interpretTemporalPeriodWithLLM($unrecognizedPeriod, $query);
      
      if ($llmInterpretation['recognized']) {
        $this->logger->logStructured(
          'info',
          'UnifiedQueryAnalyzer',
          'unrecognized_period_interpreted_by_llm',
          [
            'original' => $unrecognizedPeriod,
            'interpretation' => $llmInterpretation['interpretation'],
            'standard_period' => $llmInterpretation['standard_period'],
            'confidence' => $llmInterpretation['confidence'],
          ]
        );
        return $llmInterpretation;
      }
    } catch (\Exception $e) {
      $this->logger->logStructured(
        'warning',
        'UnifiedQueryAnalyzer',
        'llm_interpretation_failed',
        [
          'period' => $unrecognizedPeriod,
          'error' => $e->getMessage(),
        ]
      );
    }

    // Log unrecognized period for future enhancement
    $this->logger->logStructured(
      'warning',
      'UnifiedQueryAnalyzer',
      'unrecognized_temporal_period',
      [
        'period' => $unrecognizedPeriod,
        'query' => $query,
        'action' => 'requesting_clarification',
      ]
    );

    return $defaultResult;
  }

  /**
   * Interpret unrecognized temporal period using LLM
   *
   * @param string $period The unrecognized period
   * @param string $query The full query for context
   * @return array Interpretation result
   */
  private function interpretTemporalPeriodWithLLM(string $period, string $query): array
  {
    $prompt = <<<PROMPT
You are a temporal period interpreter. Given an unrecognized temporal period and the query context, 
determine what standard time period it maps to.

Unrecognized period: "{$period}"
Query context: "{$query}"

Standard periods available:
- day: Daily aggregation
- week: Weekly aggregation
- month: Monthly aggregation
- quarter: Quarterly aggregation (3 months)
- semester: Semi-annual aggregation (6 months)
- year: Yearly aggregation
- custom: For non-standard periods (specify interval)

Respond in JSON format:
{
  "recognized": true/false,
  "standard_period": "day|week|month|quarter|semester|year|custom|null",
  "custom_period": {"type": "months|weeks|days", "interval": number} or null,
  "interpretation": "Human-readable interpretation",
  "confidence": 0.0-1.0,
  "needs_clarification": true/false,
  "clarification_message": "Message to ask user" or null
}
PROMPT;

    try {
      $response = Gpt::getGptResponse($prompt, 200, 0.0);
      $cleanedResponse = $this->cleanJsonResponse($response);
      $result = json_decode($cleanedResponse, true);

      if (json_last_error() === JSON_ERROR_NONE && isset($result['recognized'])) {
        return [
          'recognized' => (bool)($result['recognized'] ?? false),
          'standard_period' => $result['standard_period'] ?? null,
          'custom_period' => $result['custom_period'] ?? null,
          'interpretation' => $result['interpretation'] ?? "Interpreted as: {$result['standard_period']}",
          'confidence' => (float)($result['confidence'] ?? 0.7),
          'needs_clarification' => (bool)($result['needs_clarification'] ?? false),
          'clarification_message' => $result['clarification_message'] ?? null,
        ];
      }
    } catch (\Exception $e) {
      if ($this->debug) {
        error_log("LLM temporal interpretation failed: " . $e->getMessage());
      }
    }

    return [
      'recognized' => false,
      'standard_period' => null,
      'custom_period' => null,
      'interpretation' => null,
      'confidence' => 0.0,
      'needs_clarification' => true,
      'clarification_message' => "Could not interpret '{$period}'. Please specify a standard time period.",
    ];
  }
}
