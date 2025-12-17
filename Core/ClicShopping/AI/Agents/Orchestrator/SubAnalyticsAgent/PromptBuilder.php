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

use ClicShopping\OM\Registry;
use ClicShopping\OM\Cache as OMCache;
use ClicShopping\AI\Infrastructure\Schema\SchemaRetriever;

/**
 * PromptBuilder
 * 
 * Centralizes all prompt construction logic for AnalyticsAgent
 * Handles system message loading, caching, and enrichment
 * 
 * Responsibilities:
 * - System message construction from language definitions
 * - Static caching of system message (shared across instances)
 * - Question enrichment with feedback context
 * - Question enrichment with last SQL query
 * - Template management and placeholder replacement
 * 
 * @package ClicShopping\AI\Agents\Orchestrator\SubAnalyticsAgent
 */
class PromptBuilder
{
  private mixed $language;
  private int $languageId;
  private bool $debug;
  private string $useCache;
  private ?SchemaRetriever $schemaRetriever = null;
  private string $currentQuery = '';
  private string $modelName = 'gpt-4o';
  
  // Static cache for system message (shared across instances)
  private static ?string $systemMessageCache = null;
  
  /**
   * Constructor
   * 
   * Initializes the PromptBuilder with language service and configuration
   * 
   * @param mixed $language Language service instance
   * @param int $languageId Language ID for placeholder replacement
   * @param bool $debug Debug mode flag for logging
   */
  public function __construct($language, int $languageId, bool $debug = false)
  {
    $this->language = $language;
    $this->languageId = $languageId;
    $this->debug = $debug;
    $this->useCache = defined('CLICSHOPPING_APP_CHATGPT_RA_CACHE_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_CACHE_RAG_MANAGER === 'True' ? 'True' : 'False';
    
    // Initialize SchemaRetriever if Schema RAG is enabled
    $useSchemaRAG = defined('CLICSHOPPING_APP_CHATGPT_RA_SCHEMA_RAG') && CLICSHOPPING_APP_CHATGPT_RA_SCHEMA_RAG === 'True';
    if ($useSchemaRAG) {
      $this->schemaRetriever = new SchemaRetriever($debug);
    }
  }
  
  /**
   * Get system message (with static and OM caching)
   * 
   * Loads system message once per PHP process using static cache
   * Also checks OM cache for persistence across requests
   * Subsequent calls return cached version
   * 
   * NOTE: When Schema RAG is enabled, caching is disabled because
   * the system message is query-specific
   * 
   * @param string $query User query (optional, for Schema RAG)
   * @param string $modelName Model name (optional, for Schema RAG)
   * @return string Complete system message
   */
  public function getSystemMessage(string $query = '', string $modelName = 'gpt-4o'): string
  {
    // Store query and model for buildSystemMessage()
    $this->currentQuery = $query;
    $this->modelName = $modelName;
    
    // If Schema RAG is enabled and we have a query, skip caching
    $useSchemaRAG = defined('CLICSHOPPING_APP_CHATGPT_RA_SCHEMA_RAG') && CLICSHOPPING_APP_CHATGPT_RA_SCHEMA_RAG === 'True';
    
    if ($useSchemaRAG && !empty($query)) {
      // Build query-specific system message (no caching)
      $systemMessage = $this->buildSystemMessage();
      
      if ($this->debug) {
        error_log("[PromptBuilder] Built query-specific system message with Schema RAG (" . strlen($systemMessage) . " chars)");
      }
      
      return $systemMessage;
    }
    
    // Standard caching flow (for full schema or when no query provided)
    
    // Check static cache first (fastest)
    if (self::$systemMessageCache !== null) {
      if ($this->debug) {
        error_log("[PromptBuilder] Using static cached system message");
      }
      return self::$systemMessageCache;
    }
    
    // Check OM cache if enabled
    if ($this->useCache === 'True') {
      $cacheKey = 'analytics_system_prompt_en_' . $this->languageId;
      $cache = new OMCache($cacheKey);
      $cached = $cache->get();
      
      if (!empty($cached)) {
        if ($this->debug) {
          error_log("[PromptBuilder] Using OM cached system message (length: " . strlen($cached) . ")");
        }
        
        // Store in static cache for subsequent calls
        self::$systemMessageCache = $cached;
        return $cached;
      }
    }
    
    // Build system message
    $systemMessage = $this->buildSystemMessage();
    
    // Store in static cache
    self::$systemMessageCache = $systemMessage;
    
    // Store in OM cache if enabled
    if ($this->useCache === 'True') {
      $cacheKey = 'analytics_system_prompt_en_' . $this->languageId;
      $cache = new OMCache($cacheKey);
      $cache->save($systemMessage);
    }
    
    if ($this->debug) {
      error_log("[PromptBuilder] Built new system message (" . strlen($systemMessage) . " chars)");
    }
    
    return $systemMessage;
  }
  
  /**
   * Build complete system message
   * 
   * Loads all language definitions and constructs the complete prompt
   * Combines multiple prompt components in the correct order
   * Replaces placeholders with actual values
   * 
   * @return string Complete system message with placeholders replaced
   */
  private function buildSystemMessage(): string
  {
    // Load language definitions from ClicShoppingAdmin/Core/languages/main.txt
    // This loads the AnalyticsAgent prompt definitions in English
    $this->language->loadDefinitions('rag_analyitcs_agent', 'en', null, 'ClicShoppingAdmin');
    
    // Get all prompt components
    $baseSystemMessage = $this->language->getDef('text_system_message');
    $orderCalculation = $this->language->getDef('text_order_calculation');
    $queryExamples = $this->language->getDef('text_query_examples');
    $sqlGenerationRules = $this->language->getDef('text_sql_generation_rules');
    $sqlFormatInstructions = $this->language->getDef('text_sql_format_instructions');
    $text_multi_query_warning = $this->language->getDef('text_multi_query_warning');
    $securityGuidelines = $this->language->getDef('text_security_guidelines');
    $entityMetadataGuidelines = $this->language->getDef('text_entity_metadata_guidelines');
    $multiTokenRules = $this->language->getDef('multi_token_rules');
    $responseFormat = $this->language->getDef('text_response_format');
    $text_rag_system_message_template = $this->language->getDef('text_rag_system_message_template');
    $text_rag_system_analytics_rules = $this->language->getDef('text_rag_system_analytics_rules');
    
    // Get table structure instructions (Schema RAG or full schema)
    $tableStructureInstructions = $this->getTableStructureInstructions();


    // Debug logging for loaded components
    if ($this->debug) {
      error_log("================================================================================");
      error_log("DEBUG PromptBuilder::buildSystemMessage()");
      error_log("================================================================================");
      error_log("text_system_message length: " . strlen($baseSystemMessage) . " chars");
      error_log("securityGuidelines length: " . strlen($securityGuidelines) . " chars");
      error_log("text_rag_system_analytics_rules length: " . strlen($text_rag_system_analytics_rules) . " chars");
      error_log("tableStructureInstructions length: " . strlen($tableStructureInstructions) . " chars");
      error_log("text_multi_query_warning length: " . strlen($text_multi_query_warning) . " chars");
      error_log("entityMetadataGuidelines length: " . strlen($entityMetadataGuidelines) . " chars");
      error_log("text_sql_generation_rules length: " . strlen($sqlGenerationRules) . " chars");
      error_log("text_order_calculation length: " . strlen($orderCalculation) . " chars");
      error_log("text_query_examples length: " . strlen($queryExamples) . " chars");
      error_log("sqlFormatInstructions length: " . strlen($sqlFormatInstructions) . " chars");
      error_log("text_response_format length: " . strlen($responseFormat) . " chars");
      error_log("text_multi_token_rules length: " . strlen($multiTokenRules) . " chars");
      error_log("Contains 'MULTI-TOKEN' in multiTokenRules: " . (strpos($multiTokenRules, 'MULTI-TOKEN') !== false ? 'YES' : 'NO'));
      error_log("First 200 chars of multiTokenRules: " . substr($multiTokenRules, 0, 200));
      error_log("================================================================================");
    }
    
    // Construct complete message in the correct order
    $completeSystemMessage = $baseSystemMessage . "\n\n" .            // 1. Role and essential ID rules
      $securityGuidelines . "\n\n" .                                 // 2. Security and prohibition rules
      $text_rag_system_analytics_rules . "\n\n" .                    // 3. Critical ambiguity rules
      $tableStructureInstructions . "\n\n" .                         // 4. Database schema (the playground)
      $entityMetadataGuidelines . "\n\n" .                           // 5. Schema metadata
      $sqlGenerationRules . "\n\n" .                                 // 6. SQL construction rules (JOINs, etc.)
      $orderCalculation . "\n\n" .                                   // 7. Specific calculation rules
      $queryExamples . "\n\n" .                                      // 8. Examples (Few-shot learning)
      $sqlFormatInstructions . "\n\n" .                              // 9. SQL code format
      $text_multi_query_warning. "\n\n" .                            // 3. Critical multi pattern rules
      $responseFormat . "\n\n" .                                     // 10. Response format
      $text_rag_system_message_template . "\n\n" .                   // 11. RAG context
      $multiTokenRules . "\n\n"                                      // 12. Parsing rules
    ;
    
    // Replace placeholders with actual values
    // The prompt contains {{language_id}} placeholders that need to be replaced
    $finalMessage = str_replace('{{language_id}}', (string)$this->languageId, $completeSystemMessage);
    
    if ($this->debug) {
      $placeholderCount = substr_count($completeSystemMessage, '{{language_id}}');
      error_log("Replaced {$placeholderCount} occurrences of {{language_id}} with {$this->languageId}");
      error_log("================================================================================");
      error_log("DEBUG PromptBuilder::buildSystemMessage() - FINAL");
      error_log("================================================================================");
      error_log("Language ID: " . $this->languageId);
      error_log("Final message length: " . strlen($finalMessage));
      error_log("Contains 'CRITICAL RULES': " . (strpos($finalMessage, 'CRITICAL RULES') !== false ? 'YES' : 'NO'));
      error_log("Contains 'products_quantity': " . (strpos($finalMessage, 'products_quantity') !== false ? 'YES' : 'NO'));
      error_log("First 500 chars: " . substr($finalMessage, 0, 500));
      error_log("================================================================================");
    }
    
    return $finalMessage;
  }
  
  /**
   * Enrich question with feedback context
   * 
   * Adds learning examples from previous corrections
   * Limits to top 3 most relevant examples to avoid prompt bloat
   * Only includes corrections with SQL queries
   * 
   * @param string $question Original question
   * @param array $feedbackContext Array of feedback items with correction data
   * @return string Enriched question with learning examples prepended
   */
  public function enrichWithFeedback(string $question, array $feedbackContext): string
  {
    if (empty($feedbackContext)) {
      return $question;
    }
    
    if ($this->debug) {
      error_log("\n--- Enriching question with " . count($feedbackContext) . " feedback items ---");
    }
    
    // Limit to top 3 most relevant examples to avoid prompt bloat
    $relevantFeedback = array_slice($feedbackContext, 0, 3);
    
    $learningExamples = [];
    
    foreach ($relevantFeedback as $feedback) {
      // Only use corrections with SQL queries
      if ($feedback['feedback_type'] === 'correction' && !empty($feedback['sql_query'])) {
        $example = "Previous example:\n";
        $example .= "Question: " . $feedback['original_query'] . "\n";
        
        if (!empty($feedback['corrected_response'])) {
          $example .= "Correct SQL: " . $feedback['corrected_response'] . "\n";
        } else {
          $example .= "SQL: " . $feedback['sql_query'] . "\n";
        }
        
        if (!empty($feedback['correction_comment'])) {
          $example .= "Note: " . $feedback['correction_comment'] . "\n";
        }
        
        $learningExamples[] = $example;
        
        if ($this->debug) {
          error_log("Added learning example from interaction: " . $feedback['interaction_id']);
        }
      }
    }
    
    if (empty($learningExamples)) {
      return $question;
    }
    
    // Prepend learning examples to the question
    $enrichedQuestion = "Learn from these previous corrections:\n\n";
    $enrichedQuestion .= implode("\n", $learningExamples);
    $enrichedQuestion .= "\nNow answer this question using the same approach:\n";
    $enrichedQuestion .= $question;
    
    if ($this->debug) {
      error_log("Question enriched with " . count($learningExamples) . " learning examples");
    }
    
    return $enrichedQuestion;
  }
  
  /**
   * Enrich question with last SQL query
   * 
   * Used for modification requests (e.g., "add column X", "change to Y")
   * Loads template from language definitions and replaces placeholders
   * 
   * @param string $question User question
   * @param string $lastSQL Last executed SQL query
   * @return string Enriched question with last SQL context
   */
  public function enrichWithLastSQL(string $question, string $lastSQL): string
  {
    // Load language definitions for the template
    $this->language->loadDefinitions('rag_analyitcs_agent', 'en', null, 'ClicShoppingAdmin');
    
    // Get the enrichment template and replace placeholders
    $array = [
      'last_sql' => $lastSQL,
      'question' => $question
    ];
    
    $enrichedQuestion = $this->language->getDef('text_enrich_with_last_sql', $array);
    
    if ($this->debug) {
      error_log("🔄 Question enriched with last SQL query");
      error_log("Last SQL: " . substr($lastSQL, 0, 100) . "...");
    }
    
    return $enrichedQuestion;
  }
  
  /**
   * Get table structure instructions (Schema RAG or full schema)
   * 
   * Decides whether to use Schema RAG (relevant tables only) or full schema
   * based on configuration and query context
   * 
   * @return string Table structure instructions
   */
  private function getTableStructureInstructions(): string
  {
    // Check if Schema RAG is enabled
    $useSchemaRAG = defined('CLICSHOPPING_APP_CHATGPT_RA_SCHEMA_RAG') && CLICSHOPPING_APP_CHATGPT_RA_SCHEMA_RAG === 'True';
    
    // Get max tables configuration
    $maxTables = defined('CLICSHOPPING_APP_CHATGPT_RA_SCHEMA_MAX_TABLES') ? (int)CLICSHOPPING_APP_CHATGPT_RA_SCHEMA_MAX_TABLES : 5;
    
    if ($useSchemaRAG && !empty($this->currentQuery) && $this->schemaRetriever !== null) {
      // Use Schema RAG (relevant tables only)
      try {
        $tableStructureInstructions = $this->schemaRetriever->getRelevantSchema(
          $this->currentQuery,
          $this->modelName,
          $maxTables
        );
        
        if ($this->debug) {
          $tokenCount = $this->estimateTokenCount($tableStructureInstructions);
          error_log("[PromptBuilder] Using Schema RAG: " . strlen($tableStructureInstructions) . " chars (~{$tokenCount} tokens)");
        }
        
        return $tableStructureInstructions;
      } catch (\Exception $e) {
        // Fallback to full schema if Schema RAG fails
        error_log("[PromptBuilder] Schema RAG failed: " . $e->getMessage());
        
        $fallbackEnabled = defined('CLICSHOPPING_APP_CHATGPT_RA_SCHEMA_FALLBACK_FULL') && CLICSHOPPING_APP_CHATGPT_RA_SCHEMA_FALLBACK_FULL === 'True';
        
        if (!$fallbackEnabled) {
          throw $e; // Re-throw if fallback is disabled
        }
        
        if ($this->debug) {
          error_log("[PromptBuilder] Falling back to full schema");
        }
      }
    }
    
    // Use full schema (default or fallback)
    $fullSchema = $this->language->getDef('text_table_structure_instructions');
    
    if ($this->debug && !$useSchemaRAG) {
      error_log("[PromptBuilder] Schema RAG disabled, using full schema");
    }
    
    return $fullSchema;
  }
  
  /**
   * Estimate token count for text
   * 
   * Rough estimate: 4 characters per token
   * 
   * @param string $text Text to estimate
   * @return int Estimated token count
   */
  private function estimateTokenCount(string $text): int
  {
    return (int)ceil(strlen($text) / 4);
  }
  
  /**
   * Clear static cache (for testing)
   * 
   * Clears the static system message cache
   * Used primarily for testing to ensure fresh builds
   * 
   * @return void
   */
  public static function clearCache(): void
  {
    self::$systemMessageCache = null;
  }
}
