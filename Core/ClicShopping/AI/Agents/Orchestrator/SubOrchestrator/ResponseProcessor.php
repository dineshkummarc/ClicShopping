<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\AI\Agents\Orchestrator\SubOrchestrator;

use AllowDynamicProperties;
use ClicShopping\AI\Security\SecurityLogger;

/**
 * ResponseProcessor Class
 *
 * Responsible for response formatting, extraction, and error handling.
 * Separated from OrchestratorAgent to follow Single Responsibility Principle.
 *
 * Responsibilities:
 * - Format responses for memory storage
 * - Extract final responses from execution results
 * - Build structured error responses
 * - Analyze errors and generate user-friendly messages
 *
 * TASK 2.1: Extracted from OrchestratorAgent (Phase 2 - Component Extraction)
 * Requirements: REQ-4.1, REQ-8.1
 */
#[AllowDynamicProperties]
class ResponseProcessor
{
  private SecurityLogger $securityLogger;
  private bool $debug;

  /**
   * Constructor
   *
   * @param bool $debug Enable debug logging
   */
  public function __construct(bool $debug = false)
  {
    $this->debug = $debug;
    $this->securityLogger = new SecurityLogger();

    if ($this->debug) {
      $this->securityLogger->logSecurityEvent("ResponseProcessor initialized", 'info');
    }
  }

  /**
   * Format response for memory storage
   *
   * Converts structured response arrays into human-readable text format
   * suitable for storage in conversation memory.
   *
   * @param array $response Response array with type and data
   * @return string Formatted response text
   */
  public function formatResponseForMemory(array $response): string
  {
    if ($response['type'] === 'analytics') {
      $data = $response['data'] ?? [];
      $formattedResponse = "Type: Analytics\n";
      $formattedResponse .= "Interpretation: " . ($data['interpretation'] ?? 'N/A') . "\n";

      // Include SQL query if available
      if (!empty($data['sql_query'])) {
        $formattedResponse .= "SQL Query: " . $data['sql_query'] . "\n";
      }

      // Include results if available
      if (!empty($data['results'])) {
        $resultCount = is_array($data['results']) ? count($data['results']) : 0;
        $formattedResponse .= "Results Count: " . $resultCount . "\n";

        // Include sample results for context
        if (is_array($data['results']) && !empty($data['results'])) {
          $sample = array_slice($data['results'], 0, 3); // First 3 results
          $formattedResponse .= "Sample Results: " . json_encode($sample, JSON_UNESCAPED_UNICODE) . "\n";
        }
      }

    } elseif ($response['type'] === 'semantic') {
      $data = $response['data'] ?? [];
      $formattedResponse = "Type: Semantic\n";
      $formattedResponse .= "Response: " . ($data['response'] ?? 'N/A') . "\n";

      // Include semantic context if available
      if (!empty($data['context'])) {
        $formattedResponse .= "Context: " . json_encode($data['context'], JSON_UNESCAPED_UNICODE) . "\n";
      }

    } elseif ($response['type'] === 'hybrid') {
      $data = $response['data'] ?? [];
      $formattedResponse = "Type: Hybrid\n";
      $formattedResponse .= "Summary: " . ($data['summary'] ?? 'N/A') . "\n";

      // Include analytics and semantic data
      if (!empty($data['analytics'])) {
        $formattedResponse .= "Analytics: " . json_encode($data['analytics'], JSON_UNESCAPED_UNICODE) . "\n";
      }
      if (!empty($data['semantic'])) {
        $formattedResponse .= "Semantic: " . json_encode($data['semantic'], JSON_UNESCAPED_UNICODE) . "\n";
      }
    } else {
      $formattedResponse = "Type: " . ($response['type'] ?? 'Unknown') . "\n";
      $formattedResponse .= "Data: " . json_encode($response['data'] ?? [], JSON_UNESCAPED_UNICODE) . "\n";
    }

    return $formattedResponse;
  }

  /**
   * Extract final response from execution result
   *
   * Extracts the human-readable response text from various execution result structures.
   * Handles analytics, semantic, and hybrid response types.
   *
   * TASK 2.17.1: Improved response extraction to prevent JSON fallback
   *
   * @param mixed $executionResult Execution result (string or array)
   * @return string Final response text
   */
  public function extractFinalResponse($executionResult): string
  {
    // If it's already a string, return it
    if (is_string($executionResult)) {
      return $executionResult;
    }

    // If it's an array, search for the response
    if (is_array($executionResult)) {
      // TASK 2.17.1: Check for text_response first (used by wrapped responses)
      if (isset($executionResult['text_response']) && !empty($executionResult['text_response'])) {
        // Check if text_response is NOT the JSON fallback
        if (strpos($executionResult['text_response'], 'Résultat:') === false) {
          return $executionResult['text_response'];
        }
      }
      
      // TASK 2.17.1: Check for response field (highest priority)
      if (isset($executionResult['response']) && !empty($executionResult['response'])) {
        return $executionResult['response'];
      }

      // TASK 2.17.1: Check for interpretation field
      if (isset($executionResult['interpretation']) && !empty($executionResult['interpretation'])) {
        return $executionResult['interpretation'];
      }
      
      // TASK 2.17.1: Check for data array (wrapped response structure)
      if (isset($executionResult['data']) && is_array($executionResult['data'])) {
        // Try to extract response from data
        if (isset($executionResult['data']['response']) && !empty($executionResult['data']['response'])) {
          return $executionResult['data']['response'];
        }
        if (isset($executionResult['data']['interpretation']) && !empty($executionResult['data']['interpretation'])) {
          return $executionResult['data']['interpretation'];
        }
      }
      
      // Case 1: Semantic result
      if (isset($executionResult['type']) && $executionResult['type'] === 'semantic_results') {
        // Already checked response above, return default
        return 'Response not available';
      }
      
      // TASK 2.17.1: Check for semantic type (from SemanticExecutor)
      if (isset($executionResult['type']) && $executionResult['type'] === 'semantic') {
        // Already checked response above, check answer field
        if (isset($executionResult['answer']) && !empty($executionResult['answer'])) {
          return $executionResult['answer'];
        }
        return 'Response not available';
      }

      // Case 2: Analytics result
      if (isset($executionResult['type']) && $executionResult['type'] === 'analytics_results') {
        // Already checked interpretation above, return default
        return 'Analysis not available';
      }

      // TASK 2.17.1: Log when falling back to JSON (this should NOT happen for semantic queries)
      if ($this->debug) {
        $this->securityLogger->logSecurityEvent(
          "⚠️ TASK 2.17.1: extractFinalResponse falling back to JSON - this indicates a problem!",
          'warning'
        );
        $this->securityLogger->logSecurityEvent(
          "   executionResult keys: " . implode(', ', array_keys($executionResult)),
          'warning'
        );
        $this->securityLogger->logSecurityEvent(
          "   executionResult type: " . ($executionResult['type'] ?? 'NOT SET'),
          'warning'
        );
      }

      // Case 5: Fallback - return a clean message instead of JSON dump
      // This prevents JSON parsing errors in the frontend
      if ($this->debug) {
        $this->securityLogger->logSecurityEvent(
          "⚠️ extractFinalResponse: No valid response field found, returning fallback message",
          'warning'
        );
      }
      return 'Unable to extract response from result. Please try rephrasing your question.';
    }

    // Final fallback
    return 'Response not available';
  }

  /**
   * Build error response with context
   *
   * Creates a structured error response with user-friendly message,
   * error details, suggestions, and retry capability.
   *
   * @param string $message Error message
   * @param array $context Error context (query, intent, etc.)
   * @return array Error response array
   */
  public function buildErrorResponse(string $message, array $context = []): array
  {
    // Analyze error type and create explicit message
    $errorInfo = $this->analyzeError($message, $context);

    return [
      'success' => false,
      'type' => 'error',
      'error' => $errorInfo['user_message'],
      'error_details' => $errorInfo['details'],
      'suggestions' => $errorInfo['suggestions'],
      'can_retry' => $errorInfo['can_retry'],
      'timestamp' => date('Y-m-d H:i:s'),
    ];
  }

  /**
   * Analyze error and generate user-friendly message
   *
   * Matches error patterns and provides appropriate user messages,
   * details, suggestions, and retry capability.
   *
   * @param string $message Error message
   * @param array $context Error context
   * @return array Error analysis with user_message, details, suggestions, can_retry
   */
  private function analyzeError(string $message, array $context = []): array
  {
    // Known error patterns
    $errorPatterns = [
      // Database errors
      '/database|connection|mysql|pdo/i' => [
        'user_message' => "Sorry, I couldn't access the data. A connection error occurred.",
        'details' => 'Database connection error',
        'suggestions' => [
          'Please try again in a few moments',
          'If the issue persists, contact technical support'
        ],
        'can_retry' => true
      ],

      // Timeout errors
      '/timeout|timed out|time limit/i' => [
        'user_message' => "The request took too long to execute.",
        'details' => 'Execution time exceeded',
        'suggestions' => [
          'Try simplifying your query',
          'Reduce the requested time range',
          'Be more specific in your query'
        ],
        'can_retry' => true
      ],

      // SQL validation errors
      '/sql|syntax|query/i' => [
        'user_message' => "I couldn't understand your data request.",
        'details' => 'Query generation error',
        'suggestions' => [
          'Rephrase your question more simply',
          'Example: "What are today\'s sales?"',
          'Avoid overly technical terms'
        ],
        'can_retry' => true
      ],

      // Missing data errors
      '/not found|no data|empty/i' => [
        'user_message' => "No data was found for your request.",
        'details' => 'No matching results',
        'suggestions' => [
          'Verify that the data exists in the system',
          'Try broadening your search',
          'Adjust the requested time period'
        ],
        'can_retry' => false
      ],

      // Permission errors
      '/permission|access|denied|forbidden/i' => [
        'user_message' => "You do not have the necessary permissions to access this data.",
        'details' => 'Access denied',
        'suggestions' => [
          'Contact your administrator to obtain permissions',
          'Try querying public data instead'
        ],
        'can_retry' => false
      ],

      // GPT/LLM service errors
      '/gpt|openai|api|llm/i' => [
        'user_message' => "The AI service is temporarily unavailable.",
        'details' => 'AI service error',
        'suggestions' => [
          'Try again in a few moments',
          'The service should be restored shortly'
        ],
        'can_retry' => true
      ],
    ];

    // Match error pattern
    foreach ($errorPatterns as $pattern => $errorInfo) {
      if (preg_match($pattern, $message)) {
        return $errorInfo;
      }
    }

    // Generic fallback
    return [
      'user_message' => "An unexpected error occurred while processing your request.",
      'details' => $message,
      'suggestions' => [
        'Retry your request',
        'Rephrase your question differently',
        'If the problem persists, contact support'
      ],
      'can_retry' => true
    ];
  }

  /**
   * Build complete orchestration response from execution result
   * 
   * Handles the complex logic of extracting and formatting responses,
   * especially for semantic queries which require special handling.
   * 
   * @param array $executionResult Execution result from plan executor
   * @param array $intent Intent analysis result
   * @param string $query Original user query
   * @param float $startTime Start time for execution time calculation
   * @param int $entityId Extracted entity ID
   * @param string $entityType Extracted entity type
   * @param object $responseProcessor LLM response processor instance
   * @return array Complete response structure
   */
  public function buildOrchestrationResponse(
    array $executionResult,
    array $intent,
    string $query,
    float $startTime,
    int $entityId,
    string $entityType,
    $responseProcessor
  ): array {
    // Extract final response
    $finalResponse = $this->extractFinalResponse($executionResult['result']);
    
    // Get raw result for processing
    $rawResult = $executionResult['result'] ?? [];
    
    // Process response through LLM formatter
    // Fix: Ensure intentType is never null
    $intentType = $intent['type'] ?? $intent['query_type'] ?? 'semantic';
    $dataForFormatter = $responseProcessor->processResponse($rawResult, $query, $intentType);
    
    // Extract actual answer with priority logic
    $actualAnswer = $this->extractActualAnswer($rawResult, $finalResponse);
    
    // If finalResponse is empty, use actualAnswer
    if (empty($finalResponse) && !empty($actualAnswer)) {
      $finalResponse = $actualAnswer;
    }
    
    // For semantic queries, ensure data includes response field
    if ($intent['type'] === 'semantic' && is_array($dataForFormatter)) {
      $dataForFormatter = $this->enrichSemanticResponse($dataForFormatter, $actualAnswer, $rawResult);
    }

    // Build final response structure
    return [
      'success' => true,
      'type' => $intent['type'],
      'data' => $dataForFormatter,
      'text_response' => $finalResponse,
      'intent' => $intent,
      'execution_time' => microtime(true) - $startTime,
      'entity_id' => $entityId,
      'entity_type' => $entityType,
      'agent_used' => 'orchestrator',
    ];
  }

  /**
   * Extract actual answer from raw result with priority logic
   * 
   * @param array $rawResult Raw execution result
   * @param string $finalResponse Final response from extractFinalResponse
   * @return string Extracted actual answer
   */
  private function extractActualAnswer(array $rawResult, string $finalResponse): string
  {
    // Priority 1: Check if rawResult has 'response' field (from SemanticQueryExecutor)
    if (isset($rawResult['response']) && !empty($rawResult['response'])) {
      return $rawResult['response'];
    }
    
    // Priority 2: Check if rawResult has 'interpretation' field
    if (isset($rawResult['interpretation']) && !empty($rawResult['interpretation'])) {
      return $rawResult['interpretation'];
    }
    
    // Priority 3: Check if rawResult has 'data' array with response/interpretation
    if (isset($rawResult['data']) && is_array($rawResult['data'])) {
      if (isset($rawResult['data']['response']) && !empty($rawResult['data']['response'])) {
        return $rawResult['data']['response'];
      }
      if (isset($rawResult['data']['interpretation']) && !empty($rawResult['data']['interpretation'])) {
        return $rawResult['data']['interpretation'];
      }
    }
    
    // Priority 4: Check if rawResult has 'text_response' field (but not if it's the JSON fallback)
    if (isset($rawResult['text_response']) && !empty($rawResult['text_response'])) {
      if (strpos($rawResult['text_response'], 'Résultat:') === false) {
        return $rawResult['text_response'];
      }
    }
    
    // Priority 5: Use finalResponse if available (but not if it's the JSON fallback)
    if (!empty($finalResponse) && strpos($finalResponse, 'Résultat:') === false) {
      return $finalResponse;
    }
    
    return '';
  }

  /**
   * Enrich semantic response data with required fields
   * 
   * @param array $dataForFormatter Data to enrich
   * @param string $actualAnswer Extracted actual answer
   * @param array $rawResult Raw execution result
   * @return array Enriched data
   */
  private function enrichSemanticResponse(array $dataForFormatter, string $actualAnswer, array $rawResult): array
  {
    // Ensure 'response' field exists in data for chatGpt.php compatibility
    if (!isset($dataForFormatter['response']) || empty($dataForFormatter['response'])) {
      $dataForFormatter['response'] = $actualAnswer;
    }
    
    // Also ensure 'interpretation' field as fallback
    if (!isset($dataForFormatter['interpretation']) || empty($dataForFormatter['interpretation'])) {
      $dataForFormatter['interpretation'] = $actualAnswer;
    }
    
    // Preserve source_attribution if present in rawResult
    if (isset($rawResult['source_attribution']) && !isset($dataForFormatter['source_attribution'])) {
      $dataForFormatter['source_attribution'] = $rawResult['source_attribution'];
    }
    
    // Preserve results array if present
    if (isset($rawResult['result']) && !isset($dataForFormatter['results'])) {
      $dataForFormatter['results'] = $rawResult['result'];
    }
    
    return $dataForFormatter;
  }
}
