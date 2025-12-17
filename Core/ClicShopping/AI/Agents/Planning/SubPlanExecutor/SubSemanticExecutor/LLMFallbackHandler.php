<?php
/**
 * LLMFallbackHandler
 * 
 * Handles LLM fallback when no documents are found in vector stores.
 * Queries the LLM directly with the user's question.
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 */

namespace ClicShopping\AI\Agents\Planning\SubPlanExecutor\SubSemanticExecutor;

use AllowDynamicProperties;
use ClicShopping\AI\Security\SecurityLogger;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\Gpt;

/**
 * LLMFallbackHandler Class
 *
 * Provides LLM fallback functionality for semantic queries when vector stores
 * don't contain relevant information.
 */
#[AllowDynamicProperties]
class LLMFallbackHandler
{
  private SecurityLogger $logger;
  private bool $debug;
  private string $userId;
  private int $languageId;

  /**
   * Constructor
   *
   * @param string $userId User ID
   * @param int $languageId Language ID
   * @param bool $debug Enable debug logging
   */
  public function __construct(string $userId = 'system', int $languageId = 1, bool $debug = false)
  {
    $this->logger = new SecurityLogger();
    $this->userId = $userId;
    $this->languageId = $languageId;
    $this->debug = $debug;

    if ($this->debug) {
      $this->logger->logSecurityEvent("LLMFallbackHandler initialized", 'info');
    }
  }

  /**
   * Query LLM with original question
   *
   * @param string $query User query
   * @param array $context Context information
   * @return array LLM response
   */
  public function queryLLM(string $query, array $context = []): array
  {
    $startTime = microtime(true);

    try {
      if ($this->debug) {
        $this->logger->logSecurityEvent("LLMFallbackHandler: Querying LLM with: \"{$query}\"", 'info');
      }

      // Initialize Gpt if needed
      $parameters = [];
      if (defined('CLICSHOPPING_APP_CHATGPT_CH_MODEL')) {
        $parameters['model'] = CLICSHOPPING_APP_CHATGPT_CH_MODEL;
      }

      Gpt::getOpenAiGpt($parameters);

      // Format prompt for LLM
      $prompt = $this->formatPromptForLLM($query, $context);

      // Call LLM API - getOpenAIChat returns OpenAIChat object
      $chatInstance = Gpt::getOpenAIChat($prompt);
      
      if ($chatInstance === false) {
        throw new \Exception("Failed to initialize OpenAI Chat");
      }
      
      // Generate the actual text response using generateText()
      $response = $chatInstance->generateText($prompt);

      $executionTime = microtime(true) - $startTime;

      if ($this->debug) {
        $this->logger->logSecurityEvent(
          "LLM response received in {$executionTime}s (length: " . strlen($response) . ")",
          'info'
        );
      }

      // Validate response is not empty
      if (empty($response) || strlen(trim($response)) < 10) {
        // Log technical details separately
        $this->logger->logSecurityEvent(
          "LLM returned empty or too short response (length: " . strlen($response) . ")",
          'warning'
        );
        
        $errorMessage = "Le système n'a pas pu générer une réponse appropriée";
        
        return [
          'success' => false,
          'error' => 'empty_response',
          'answer' => $errorMessage,
          'response' => $errorMessage,  // 🔧 TASK 2.17.2: Add 'response' for consistency
          'text_response' => $errorMessage,
          'documents' => [],
          'audit_metadata' => [
            'source' => 'llm',
            'fallback' => true,
            'error' => 'empty_response',
            'response_length' => strlen($response),
            'execution_time' => $executionTime
          ]
        ];
      }

      // Format and return response
      return $this->formatLLMResponse($response);

    } catch (\Exception $e) {
      $executionTime = microtime(true) - $startTime;
      
      // Log technical details separately
      $this->logger->logSecurityEvent(
        "LLM query failed - Technical details: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString(),
        'error'
      );

      // Return user-friendly error message
      $errorMessage = "Je ne peux pas répondre à cette question pour le moment";
      
      return [
        'success' => false,
        'error' => 'llm_failure',
        'answer' => $errorMessage,
        'response' => $errorMessage,  // 🔧 TASK 2.17.2: Add 'response' for consistency
        'text_response' => $errorMessage,
        'documents' => [],
        'audit_metadata' => [
          'source' => 'llm',
          'fallback' => true,
          'error' => 'llm_failure',
          'error_message' => $e->getMessage(),
          'execution_time' => $executionTime
        ]
      ];
    }
  }

  /**
   * Format prompt for LLM
   *
   * @param string $query User query
   * @param array $context Context information
   * @return string Formatted prompt
   */
  private function formatPromptForLLM(string $query, array $context): string
  {
    // Simple prompt format - can be enhanced later
    $prompt = "Please answer the following question:\n\n";
    $prompt .= "Question: {$query}\n\n";
    
    // Add context if available
    if (!empty($context['conversation_history'])) {
      $prompt .= "Context from previous conversation:\n";
      foreach ($context['conversation_history'] as $item) {
        if (isset($item['role']) && isset($item['content'])) {
          $prompt .= "{$item['role']}: {$item['content']}\n";
        }
      }
      $prompt .= "\n";
    }

    $prompt .= "Please provide a clear and concise answer.";

    return $prompt;
  }

  /**
   * Format LLM response
   *
   * @param string $response Raw LLM response
   * @return array Formatted response
   */
  private function formatLLMResponse(string $response): array
  {
    // Clean and format the response
    $cleanedResponse = trim($response);

    return [
      'success' => true,
      'answer' => $cleanedResponse,
      'response' => $cleanedResponse,  // 🔧 TASK 2.17.2: Add 'response' for consistency
      'text_response' => $cleanedResponse,
      'documents' => [], // LLM doesn't return documents
      'audit_metadata' => [
        'source' => 'llm',
        'fallback' => true,
        'response_length' => strlen($cleanedResponse),
        'tables_searched' => 0, // No vector stores searched
        'final_results_count' => 0
      ]
    ];
  }
}
