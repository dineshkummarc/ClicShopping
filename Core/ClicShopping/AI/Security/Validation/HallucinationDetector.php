<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\AI\Security\Validation;


use ClicShopping\AI\Security\SecurityLogger;
use ClicShopping\AI\Config\DomainConfig;
use ClicShopping\OM\Registry;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\Gpt;
/**
 * HallucinationDetector Class
 *
 * Security validator for hallucination detection and flagging.
 * Provides utility methods for logging flagged answers and creating
 * insufficient information responses.
 *
 * 🔧 ARCHITECTURE REORGANIZATION: Moved from SubSemanticExecutor to Security/Validation
 * 🔧 RENAMED: HallucinationDetectionHelper → HallucinationDetector (consistent naming)
 *
 * @see kiro_documentation/2025_12_27/hallucination_detection_system_design.md
 * @see kiro_documentation/2025_12_28/ARCHITECTURE_REORGANIZATION_PROPOSAL.md
 */

class HallucinationDetector
{
  private SecurityLogger $logger;
  private bool $debug;
  private mixed $language;
  /**
   * Constructor
   *
   * @param bool $debug Enable debug logging
   */
  public function __construct(bool $debug = false)
  {
    $this->logger = new SecurityLogger();
    $this->debug = $debug;

    // Load language definitions
    $this->language = Registry::get('Language');
    DomainConfig::loadLanguageFile('rag_out_of_context_detection');
  }

  /**
   * Log flagged answer for review
   *
   *
   * Logs answers that have been flagged or rejected due to low grounding scores.
   * This creates an audit trail for systematic review and improvement.
   *
   * @param string $query Original query
   * @param array $rawResult Raw result from orchestrator
   * @param array $groundingResult Grounding verification result
   * @param string $userId User ID
   * @param int $languageId Language ID
   * @return void
   */
  public function logFlaggedAnswer(
    string $query,
    array $rawResult,
    array $groundingResult,
    string $userId = 'system',
    int $languageId = 1
  ): void {
    $logData = [
      'timestamp' => date('Y-m-d H:i:s'),
      'query' => $query,
      'answer' => $rawResult['answer'] ?? '',
      'grounding_score' => $groundingResult['confidence'],
      'decision' => $groundingResult['decision'],
      'flagged_sentences' => $groundingResult['flagged_sentences'],
      'explanation' => $groundingResult['explanation'],
      'source_document_count' => $groundingResult['source_document_count'] ?? 0,
      'user_id' => $userId,
      'language_id' => $languageId,
    ];

    // Log to security logger
    $this->logger->logSecurityEvent(
      "Flagged answer for review: " . json_encode($logData, JSON_PRETTY_PRINT),
      'warning'
    );

    if ($this->debug) {
      $this->logger->logSecurityEvent(
        "Flagged answer details - Query: {$query}, Score: {$groundingResult['confidence']}, Decision: {$groundingResult['decision']}",
        'info'
      );
    }

    // TODO: Consider adding to database table for systematic review
    // e.g., INSERT INTO rag_flagged_answers (query, answer, grounding_score, ...)
    // This would enable:
    // - Dashboard for reviewing flagged answers
    // - Analytics on hallucination patterns
    // - Training data for improving detection
  }

  /**
   * Create "insufficient information" response
   *
   * Creates a user-friendly response when an answer is rejected due to
   * low grounding score. The message is localized based on language ID.
   *
   * **IMPORTANT**: Returns success=true to pass validation, but with error flag
   *
   * @param array $groundingResult Grounding verification result
   * @param int $languageId Language ID (optional, for debug logging only)
   * @return array Formatted response
   */
  public function createInsufficientInformationResponse(array $groundingResult, int $languageId = 1): array {
    // Get localized messages from language files using instance language object
    $message = $this->language->getDef('text_rag_insufficient_information');
    $sourceDetails = $this->language->getDef('text_rag_source_insufficient_information');

    if ($this->debug) {
      $this->logger->logSecurityEvent(
        "Creating insufficient information response - Language ID: {$languageId}",
        'info'
      );
    }

    // 🔧 FIX: Return success=true to pass validation, but include error metadata
    // 🔧 FIX 2025-12-27: Use 'llm' as source_type (valid for ResultValidator)
    return [
      'type' => 'semantic',
      'success' => true,  // Changed from false to pass validation
      'text_response' => $message,
      'response' => $message,
      'error' => 'insufficient_information',
      'hallucination_detected' => true,  // Flag for frontend
      'grounding_score' => $groundingResult['confidence'],
      'grounding_decision' => 'REJECT',
      'grounding_metadata' => [
        'sentence_count' => $groundingResult['sentence_count'],
        'flagged_sentences' => $groundingResult['flagged_sentences'],
        'explanation' => $groundingResult['explanation'],
      ],
      'sources' => [],
      'source_attribution' => [
        'source_type' => 'llm',  // 🔧 Changed from 'Insufficient Information' to 'llm' (valid source type)
        'source_icon' => '⚠️',
        'source_details' => $sourceDetails,  // 🔧 Use translated message
        'grounding_score' => $groundingResult['confidence'],
        'rejection_reason' => 'insufficient_information',  // Add explicit rejection reason
      ],
    ];
  }

  /**
   * Format grounding metadata for response
   *
   * Extracts relevant grounding information for inclusion in response.
   *
   * @param array $groundingResult Grounding verification result
   * @return array Formatted metadata
   */
  public function formatGroundingMetadata(array $groundingResult): array
  {
    return [
      'sentence_count' => $groundingResult['sentence_count'] ?? 0,
      'flagged_sentences' => $groundingResult['flagged_sentences'] ?? [],
      'explanation' => $groundingResult['explanation'] ?? '',
      'processing_time_ms' => $groundingResult['processing_time_ms'] ?? 0,
    ];
  }

  /**
   * Should reject answer based on grounding result
   *
   * Determines if an answer should be rejected based on the grounding
   * verification result and configured threshold.
   *
   * 🔧 REGRESSION FIX 2025-12-28: Never reject general knowledge queries
   *
   * @param array $groundingResult Grounding verification result
   * @param float $threshold Rejection threshold (default: 0.70)
   * @return bool True if answer should be rejected
   */
  public function shouldRejectAnswer(array $groundingResult, float $threshold = 0.70): bool
  {
    // 🔧 REGRESSION FIX: Never reject general knowledge queries (no documents available)
    if (isset($groundingResult['general_knowledge']) && $groundingResult['general_knowledge'] === true) {
      return false;
    }
    
    // 🔧 REGRESSION FIX: Never reject if verification was skipped (no documents)
    if (isset($groundingResult['skipped']) && $groundingResult['skipped'] === true) {
      return false;
    }
    
    return ($groundingResult['decision'] ?? 'ACCEPT') === 'REJECT' ||
           ($groundingResult['confidence'] ?? 1.0) < $threshold;
  }

  /**
   * Should flag answer for review
   *
   * Determines if an answer should be flagged for review based on the
   * grounding verification result.
   *
   * @param array $groundingResult Grounding verification result
   * @return bool True if answer should be flagged
   */
  public function shouldFlagAnswer(array $groundingResult): bool
  {
    return ($groundingResult['decision'] ?? 'ACCEPT') === 'FLAG';
  }

  /**
   * Detect out-of-context queries using pure LLM
   *
   *
   * This method uses LLM to determine if a query is out-of-context for the
   * configured domain. It does NOT use pattern matching.
   *
   * **Domain-Aware Behavior:**
   * - When domain is configured (e.g., 'Ecommerce'): Uses domain-specific context from language files
   * - When no domain configured: Uses generic business context
   *
   * **ONLY business-related questions are accepted:**
   * - Domain-specific operations (when domain is configured)
   * - Marketing (campaigns, promotions, advertising, SEO)
   * - Innovation (new products, market trends, competitive analysis)
   * - Prospection (lead generation, customer acquisition, market research)
   * - Business strategy (growth, expansion, partnerships)
   *
   * **Action Logic:**
   * - **REJECT**: Sports, entertainment, personal questions, general knowledge NOT related to business
   * - **REDIRECT to WEB_SEARCH**: Business/marketing/innovation questions requiring external data
   * - **ALLOW**: Business queries using internal database
   *
   * Examples of queries that should be REJECTED:
   * - Sports: "What is the winner for the next world cup championship"
   * - Entertainment: "Who won the Oscar?", "What's the latest movie?"
   * - General knowledge: "What is the capital of France?", "Where is Paris?" (NOT business related)
   * - Personal: "Give me personal advice"
   *
   * Examples of queries that should be REDIRECTED to WEB_SEARCH:
   * - Competitor analysis: "competitor prices on marketplace"
   * - Market research: "what are the latest industry trends?"
   * - Marketing: "how to improve our marketing campaigns?"
   * - Prospection: "best practices for customer acquisition"
   * - Innovation: "emerging technologies in the industry"
   *
   * Examples of queries that should be ALLOWED (in-context):
   * - Data search: "show records", "find items"
   * - Analytics: "total revenue 2025", "how many transactions?"
   * - Business metrics: "best performing items", "statistics"
   *
   * @param string $query User query to analyze
   * @return array Detection result with:
   *   - 'is_out_of_context' (bool): True if query is out-of-context
   *   - 'context_relevance' (float): 0.0-1.0 (0.0 = completely irrelevant, 1.0 = highly relevant)
   *   - 'detected_category' (string): Category of out-of-context query (sports, entertainment, general_knowledge, news, other)
   *   - 'confidence' (float): 0.0-1.0 confidence in detection
   *   - 'explanation' (string): Human-readable explanation
   *   - 'suggested_action' (string): 'reject', 'redirect_to_web_search', or 'allow'
   *   - 'detection_method' (string): Always 'llm' (pure LLM mode)
   */
  public function detectOutOfContext(string $query): array
  {
    if ($this->debug) {
      $this->logger->logSecurityEvent(
        "Detecting out-of-context query: {$query}",
        'info'
      );
    }

    try {
      // Build LLM prompt for out-of-context detection
      $prompt = $this->buildOutOfContextDetectionPrompt($query);

      // Call LLM for detection (pure LLM, no patterns)
      $response = Gpt::getGptResponse(
        $prompt,
        300, // max_tokens
        0.0  // temperature (deterministic)
      );

      // Clean and parse JSON response
      $cleanedResponse = $this->cleanJsonResponse($response);
      $result = json_decode($cleanedResponse, true);

      if (json_last_error() !== JSON_ERROR_NONE) {
        $this->logger->logSecurityEvent(
          "Failed to parse LLM response for out-of-context detection: " . json_last_error_msg(),
          'warning'
        );

        // Fallback: assume in-context (safe default)
        return $this->getDefaultInContextResult();
      }

      // Validate and sanitize result
      $result = $this->validateOutOfContextResult($result);

      // Log detection result
      $this->logger->logStructured(
        $result['is_out_of_context'] ? 'warning' : 'info',
        'HallucinationDetector',
        'out_of_context_detection',
        [
          'query' => $query,
          'is_out_of_context' => $result['is_out_of_context'],
          'context_relevance' => $result['context_relevance'],
          'detected_category' => $result['detected_category'],
          'confidence' => $result['confidence'],
          'suggested_action' => $result['suggested_action'],
        ]
      );

      if ($this->debug) {
        $this->logger->logSecurityEvent(
          "Out-of-context detection result: " . json_encode($result, JSON_PRETTY_PRINT),
          'info'
        );
      }

      return $result;

    } catch (\Exception $e) {
      $this->logger->logSecurityEvent(
        "Exception in out-of-context detection: " . $e->getMessage(),
        'error'
      );

      // Fallback: assume in-context (safe default)
      return $this->getDefaultInContextResult();
    }
  }

  /**
   * Build LLM prompt for out-of-context detection
   *
   * Creates a prompt that asks the LLM to determine if a query is relevant
   * to the configured domain context (or generic context if no domain).
   *
   * - Uses DomainConfig::getActiveDomain() to check active domain
   * - Loads domain-specific prompt from language files when domain is active
   * - Uses generic prompt when no domain is configured
   *
   * @param string $query User query
   * @return string LLM prompt
   */
  private function buildOutOfContextDetectionPrompt(string $query): string
  {
    $domain = DomainConfig::getActivities();
    
    // Get prompt template from language file with query parameter
    // The language file path is already domain-aware via DomainConfig::loadLanguageFile()
    $prompt = $this->language->getDef('text_out_of_context_detection_prompt', ['query' => $query]);
    
    if ($this->debug) {
      $this->logger->logSecurityEvent(
        "Using out-of-context detection prompt from language file (domain: {$domain})",
        'info'
      );
    }
    
    return $prompt;
  }

  /**
   * Clean JSON response by removing markdown code blocks
   *
   * @param string $response Raw LLM response
   * @return string Cleaned JSON string
   */
  private function cleanJsonResponse(string $response): string
  {
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
   * Validate and sanitize out-of-context detection result
   *
   * - Uses DomainConfig::getActiveDomain() for default category
   * - Falls back to 'generic' when no domain is configured
   *
   * @param array|null $result Parsed JSON from LLM
   * @return array Validated result
   */
  private function validateOutOfContextResult(?array $result): array
  {
    $default = $this->getDefaultInContextResult();

    if (!is_array($result)) {
      return $default;
    }

    // Validate is_out_of_context (bool)
    $result['is_out_of_context'] = isset($result['is_out_of_context']) 
      ? (bool)$result['is_out_of_context'] 
      : false;

    // Validate context_relevance (0.0-1.0)
    $result['context_relevance'] = isset($result['context_relevance']) && is_numeric($result['context_relevance'])
      ? max(0.0, min(1.0, (float)$result['context_relevance']))
      : 1.0;

    // Validate detected_category
    $activeDomain = DomainConfig::getActivities();
    $defaultCategory = !empty($activeDomain) ? $activeDomain : 'generic';
    
    $validCategories = ['sports', 'entertainment', 'general_knowledge', 'news', 'other', $defaultCategory];
    $result['detected_category'] = isset($result['detected_category']) && in_array($result['detected_category'], $validCategories, true)
      ? $result['detected_category']
      : $defaultCategory;

    // Validate confidence (0.0-1.0)
    $result['confidence'] = isset($result['confidence']) && is_numeric($result['confidence'])
      ? max(0.0, min(1.0, (float)$result['confidence']))
      : 0.5;

    // Validate explanation
    $result['explanation'] = isset($result['explanation']) && is_string($result['explanation'])
      ? trim($result['explanation'])
      : 'No explanation provided';

    // Validate suggested_action
    $validActions = ['reject', 'redirect_to_web_search', 'allow'];
    $result['suggested_action'] = isset($result['suggested_action']) && in_array($result['suggested_action'], $validActions, true)
      ? $result['suggested_action']
      : 'allow';

    // Add detection method
    $result['detection_method'] = 'llm';

    return $result;
  }

  /**
   * Get default in-context result (safe fallback)
   *
   * - Uses DomainConfig::getActiveDomain() for category
   * - Falls back to 'generic' when no domain is configured
   *
   * @return array Default result assuming query is in-context
   */
  private function getDefaultInContextResult(): array
  {
    $activeDomain = DomainConfig::getActivities();
    $defaultCategory = !empty($activeDomain) ? $activeDomain : 'generic';
    
    return [
      'is_out_of_context' => false,
      'context_relevance' => 1.0,
      'detected_category' => $defaultCategory,
      'confidence' => 0.5,
      'explanation' => 'Assumed in-context (detection failed)',
      'suggested_action' => 'allow',
      'detection_method' => 'llm',
    ];
  }

  /**
   * Detect revenue bias hallucination
   *
   * but original query does NOT (hallucination pattern)
   *
   * @param string $originalQuery Original user query
   * @param string $translatedQuery Translated query from UnifiedQueryAnalyzer
   * @return array Detection result with hallucination flag and keywords
   */
  public function detectRevenueBias(string $originalQuery, string $translatedQuery): array
  {
    $originalQueryLower = strtolower($originalQuery);
    $translatedQueryLower = strtolower($translatedQuery);
    
    $hallucinationDetected = false;
    $hallucinationKeywords = [];
    
    // Check for revenue bias hallucination
    // Include French equivalents: chiffre d'affaires, CA, revenu
    if (str_contains($translatedQueryLower, 'revenue') 
        && !str_contains($originalQueryLower, 'revenue')
        && !str_contains($originalQueryLower, 'chiffre')
        && !str_contains($originalQueryLower, 'affaires')
        && !str_contains($originalQueryLower, 'revenu')
        && !str_contains($originalQueryLower, ' ca ')) {
      $hallucinationDetected = true;
      $hallucinationKeywords[] = 'revenue';
    }
    
    // Check for month/monthly (English and French)
    if ((str_contains($translatedQueryLower, 'month') || str_contains($translatedQueryLower, 'monthly')) 
        && !str_contains($originalQueryLower, 'month') 
        && !str_contains($originalQueryLower, 'mois')
        && !str_contains($originalQueryLower, 'mensuel')) {
      $hallucinationDetected = true;
      $hallucinationKeywords[] = 'month';
    }
    
    // Check for quarter/quarterly (English and French)
    if ((str_contains($translatedQueryLower, 'quarter') || str_contains($translatedQueryLower, 'quarterly')) 
        && !str_contains($originalQueryLower, 'quarter') 
        && !str_contains($originalQueryLower, 'trimestre')
        && !str_contains($originalQueryLower, 'trimestriel')) {
      $hallucinationDetected = true;
      $hallucinationKeywords[] = 'quarter';
    }
    
    // Check for semester/semestre
    if ((str_contains($translatedQueryLower, 'semester') || str_contains($translatedQueryLower, 'semestre')) 
        && !str_contains($originalQueryLower, 'semester') 
        && !str_contains($originalQueryLower, 'semestre')) {
      $hallucinationDetected = true;
      $hallucinationKeywords[] = 'semester';
    }
    
    // Check for year/annual (only if combined with revenue)
    if (str_contains($translatedQueryLower, 'revenue') 
        && (str_contains($translatedQueryLower, 'year') || str_contains($translatedQueryLower, 'annual'))
        && !str_contains($originalQueryLower, 'year') 
        && !str_contains($originalQueryLower, 'année')
        && !str_contains($originalQueryLower, 'annuel')
        && !str_contains($originalQueryLower, 'an ')) {
      $hallucinationDetected = true;
      $hallucinationKeywords[] = 'year';
    }
    
    $result = [
      'hallucination_detected' => $hallucinationDetected,
      'hallucination_keywords' => $hallucinationKeywords,
      'original_query' => $originalQuery,
      'translated_query' => $translatedQuery,
      'suggested_action' => $hallucinationDetected ? 'use_original_query' : 'allow',
      'confidence' => $hallucinationDetected ? 0.95 : 0.0,
    ];
    
    if ($this->debug && $hallucinationDetected) {
      $this->logger->logSecurityEvent(
        "Revenue bias hallucination detected: '$originalQuery' → '$translatedQuery' (keywords: " . implode(', ', $hallucinationKeywords) . ")",
        'warning'
      );
    }
    
    return $result;
  }
}
