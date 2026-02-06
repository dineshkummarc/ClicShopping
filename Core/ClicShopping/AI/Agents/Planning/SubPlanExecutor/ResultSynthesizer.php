<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\AI\Agents\Planning\SubPlanExecutor;


use ClicShopping\AI\Security\SecurityLogger;
use ClicShopping\AI\Agents\Planning\ExecutionPlan;
use ClicShopping\AI\Agents\Orchestrator\SubOrchestrator\ResultValidator;

/**
 * ResultSynthesizer Class
 *
 * Responsible for synthesizing and aggregating results from multiple steps.
 * Separated from PlanExecutor to follow Single Responsibility Principle.
 *
 * Responsibilities:
 * - Synthesize results from execution plan
 * - Aggregate step results
 * - Validate results before synthesis (Task 4.1)
 * - Format final result
 * - Extract entity metadata
 */

class ResultSynthesizer
{
  private SecurityLogger $logger;
  private bool $debug;
  private ResultValidator $validator;

  /**
   * Constructor
   *
   * @param bool $debug Enable debug logging
   */
  public function __construct(bool $debug = false)
  {
    $this->logger = new SecurityLogger();
    $this->debug = $debug;
    $this->validator = new ResultValidator($debug);

    if ($this->debug) {
      $this->logger->logSecurityEvent("ResultSynthesizer initialized with ResultValidator", 'info');
    }
  }

  /**
   * Synthesize results from execution plan
   *
   * TASK 4.1: Integrated ResultValidator to validate results before synthesis
   *
   * @param ExecutionPlan $plan Execution plan
   * @return array Synthesized result
   */
  public function synthesizeResults(ExecutionPlan $plan): array
  {
    if ($this->debug) {
      $this->logger->logSecurityEvent(
        "Synthesizing results from " . count($plan->getSteps()) . " steps",
        'info'
      );
    }

    // Get all step results
    $stepResults = $plan->getAllStepResults();

    // TASK 4.1: Validate step results before aggregation
    $validatedResults = $this->validateStepResults($stepResults);

    // Aggregate results
    $aggregated = $this->aggregateStepResults($validatedResults);

    // Extract entity metadata
    $entityMetadata = $this->extractEntityMetadata($validatedResults);

    // Format final result
    $finalResult = $this->formatFinalResult($aggregated, $entityMetadata);

    // TASK 4.1: Validate final result before returning
    $finalValidation = $this->validateFinalResult($finalResult);
    if (!$finalValidation['valid']) {
      // Log validation failure
      $this->logger->logSecurityEvent(
        "Final result validation failed: " . implode(', ', $finalValidation['errors']),
        'error',
        ['result_type' => $finalResult['type'] ?? 'unknown']
      );

      // Return error response instead of invalid result
      return [
        'success' => false,
        'type' => 'error',
        'text_response' => 'Result validation failed. Please try again.',
        'error' => 'validation_failed',
        'validation_errors' => $finalValidation['errors'],
        'data' => []
      ];
    }

    // Log validation success
    if ($this->debug) {
      $this->logger->logSecurityEvent(
        "Final result validation passed",
        'info',
        ['result_type' => $finalResult['type'] ?? 'unknown']
      );
    }

    return $finalResult;
  }

  /**
   * Validate step results before aggregation
   *
   * TASK 4.1: Validate each step result according to its type
   *
   * @param array $stepResults Array of step results
   * @return array Validated step results (invalid results are filtered out)
   */
  private function validateStepResults(array $stepResults): array
  {
    $validatedResults = [];
    $validationFailures = 0;

    foreach ($stepResults as $stepId => $result) {
      if (!is_array($result)) {
        continue;
      }

      $type = $result['type'] ?? 'unknown';
      $isValid = false;

      // Validate based on type
      switch ($type) {
        case 'semantic':
        case 'semantic_results':
          $isValid = $this->validator->validateSemanticResult($result);
          break;

        case 'analytics':
        case 'analytics_response':
          $isValid = $this->validator->validateAnalyticsResult($result);
          break;

        case 'web_search':
        case 'web_search_response':
        case 'web':
          $isValid = $this->validator->validateWebResult($result);
          break;

        case 'hybrid':
        case 'mixed':
          $isValid = $this->validator->validateHybridResult($result);
          break;

        default:
          // Unknown type - allow it through but log warning
          $isValid = true;
          if ($this->debug) {
            $this->logger->logSecurityEvent(
              "Unknown result type '{$type}' in step {$stepId} - skipping validation",
              'warning'
            );
          }
      }

      if ($isValid) {
        $validatedResults[$stepId] = $result;
      } else {
        $validationFailures++;
        $this->logger->logSecurityEvent(
          "Step {$stepId} validation failed for type '{$type}'",
          'warning',
          ['step_id' => $stepId, 'type' => $type]
        );
      }
    }

    // Log validation summary
    if ($this->debug) {
      $this->logger->logSecurityEvent(
        "Step validation complete: " . count($validatedResults) . " valid, {$validationFailures} failed",
        'info'
      );
    }

    return $validatedResults;
  }

  /**
   * Validate final result before returning
   *
   * TASK 4.1: Validate the final synthesized result
   * 
   * Validation Rules by Result Type:
   * 
   * 1. analytics_response:
   *    - MUST have: text_response OR response field (non-empty)
   *    - MUST have: source_attribution field
   *    - MUST have: data OR interpretation (empty data is valid if interpretation exists)
   * 
   * 2. semantic_results:
   *    - MUST have: text_response OR response field (non-empty)
   *    - MUST have: source_attribution field
   *    - MUST have: sources OR data (non-empty)
   * 
   * 3. mixed (hybrid queries):
   *    - MUST have: text_response OR response field (non-empty)
   *    - MUST have: source_attribution field
   *    - MUST have: data OR sources (at least one non-empty)
   * 
   * 4. web_search_response:
   *    - MUST have: text_response OR response field (non-empty)
   *    - MUST have: source_attribution field
   *    - MUST have: sources (non-empty)
   *
   * @param array $finalResult Final synthesized result
   * @return array Validation result with 'valid' boolean and 'errors' array
   */
  private function validateFinalResult(array $finalResult): array
  {
    $errors = [];

    // Check if result is empty
    if (empty($finalResult)) {
      $errors[] = "Final result is empty";
      return ['valid' => false, 'errors' => $errors];
    }

    // Check for text_response or response field
    $hasTextResponse = isset($finalResult['text_response']) && !empty($finalResult['text_response']);
    $hasResponse = isset($finalResult['response']) && !empty($finalResult['response']);

    if (!$hasTextResponse && !$hasResponse) {
      // TASK 2.3: Improved error message with field status
      $textResponseStatus = isset($finalResult['text_response']) ? 'empty' : 'not set';
      $responseStatus = isset($finalResult['response']) ? 'empty' : 'not set';
      $errors[] = "Missing text_response or response field (text_response: {$textResponseStatus}, response: {$responseStatus})";
    }

    // Check for source attribution
    if (!isset($finalResult['source_attribution']) || empty($finalResult['source_attribution'])) {
      // TASK 2.3: Improved error message with field status
      $attributionStatus = isset($finalResult['source_attribution']) ? 'empty' : 'not set';
      $errors[] = "Missing source_attribution field (field {$attributionStatus})";
    }

    // Type-specific validation
    $type = $finalResult['type'] ?? 'unknown';
    switch ($type) {
      case 'analytics_response':
        // BUG FIX 2025-12-10: Empty data is valid if there's an interpretation/text_response
        // "No results found" is a valid analytics response
        $hasData = !empty($finalResult['results']) || !empty($finalResult['data']);
        $hasInterpretation = !empty($finalResult['interpretation']) || !empty($finalResult['text_response']);
        
        if (!$hasData && !$hasInterpretation) {
          $errors[] = "Analytics result missing data and interpretation";
        }
        break;

      case 'semantic_results':
        // 🔧 TASK 4.4: Allow LLM fallback responses (they have text_response but no sources/data)
        // LLM fallback is valid if it has a text_response, even without sources
        $hasTextResponse = !empty($finalResult['text_response']) || !empty($finalResult['response']);
        $hasSources = !empty($finalResult['sources']) || !empty($finalResult['data']);
        
        // Semantic results MUST have sources OR data (not just text_response alone)
        if (!$hasSources) {
          $sourcesStatus = isset($finalResult['sources']) ? 'empty' : 'not set';
          $dataStatus = isset($finalResult['data']) ? 'empty' : 'not set';
          $errors[] = "Semantic result missing sources and data (sources: {$sourcesStatus}, data: {$dataStatus})";
        }
        break;

      case 'mixed':
        // Mixed results should have data from multiple sources
        // TASK 2.3: Improved error message with field status
        $hasData = !empty($finalResult['data']);
        $hasSources = !empty($finalResult['sources']);
        
        if (!$hasData && !$hasSources) {
          $dataStatus = isset($finalResult['data']) ? 'empty' : 'not set';
          $sourcesStatus = isset($finalResult['sources']) ? 'empty' : 'not set';
          $errors[] = "Mixed result missing data and sources (data: {$dataStatus}, sources: {$sourcesStatus})";
        }
        break;
    }

    $isValid = empty($errors);

    return [
      'valid' => $isValid,
      'errors' => $errors
    ];
  }

  /**
   * Get validation metrics
   *
   * TASK 4.1: Expose validation metrics for monitoring
   *
   * @return array Validation metrics
   */
  public function getValidationMetrics(): array
  {
    return $this->validator->getValidationMetrics();
  }

  /**
   * Aggregate step results
   *
   * @param array $stepResults Array of step results
   * @return array Aggregated result
   */
  public function aggregateStepResults(array $stepResults): array
  {
    $aggregated = [
      'text_responses' => [],
      'data' => [],
      'sources' => [],
      'calculations' => [],
      'web_results' => [],
      'analytics_results' => [],
      'semantic_results' => [],
      'source_attributions' => [], // 🆕 Collect source attributions
    ];

    foreach ($stepResults as $stepId => $result) {
      if (!is_array($result)) {
        continue;
      }

      $type = $result['type'] ?? 'unknown';

      // TASK 2.4: Debug logging for step result structure
      if ($this->debug) {
        $this->logger->logSecurityEvent(
          "Step {$stepId} result structure",
          'info',
          [
            'type' => $type,
            'has_text_response' => isset($result['text_response']) && !empty($result['text_response']),
            'has_interpretation' => isset($result['interpretation']) && !empty($result['interpretation']),
            'has_source_attribution' => isset($result['source_attribution']),
            'has_results' => isset($result['results']),
            'has_data' => isset($result['data']),
            'result_keys' => array_keys($result),
          ]
        );
      }

      // 🆕 Always log step result keys for debugging
      error_log("ResultSynthesizer: Step {$stepId} keys: " . implode(', ', array_keys($result)));
      error_log("ResultSynthesizer: Step {$stepId} has source_attribution: " . (isset($result['source_attribution']) ? 'YES' : 'NO'));

      // Aggregate text responses
      if (isset($result['text_response']) && !empty($result['text_response'])) {
        $aggregated['text_responses'][] = $result['text_response'];
      } elseif (isset($result['interpretation']) && !empty($result['interpretation'])) {
        // 🔧 FIX: Also collect 'interpretation' field for analytics results
        $aggregated['text_responses'][] = $result['interpretation'];
      } elseif (isset($result['result']['interpretation']) && !empty($result['result']['interpretation'])) {
        // TASK 1.2: Also check result.interpretation for new structure
        $aggregated['text_responses'][] = $result['result']['interpretation'];
      }

      // 🆕 Collect source attribution if present
      if (isset($result['source_attribution'])) {
        $aggregated['source_attributions'][] = $result['source_attribution'];
        
        error_log("ResultSynthesizer: ✓ Collected source_attribution from step {$stepId}: " . 
          ($result['source_attribution']['source_type'] ?? 'unknown'));
        
        if ($this->debug) {
          $this->logger->logSecurityEvent(
            "ResultSynthesizer: Collected source_attribution from step {$stepId}: " . 
            ($result['source_attribution']['source_type'] ?? 'unknown'),
            'info'
          );
        }
      } else {
        error_log("ResultSynthesizer: ✗ No source_attribution in step {$stepId} (type: {$type})");
        
        if ($this->debug) {
          $this->logger->logSecurityEvent(
            "ResultSynthesizer: No source_attribution in step {$stepId} (type: {$type})",
            'warning'
          );
        }
      }

      // Aggregate by type
      switch ($type) {
        case 'analytics':
        case 'analytics_response':
          // Handle analytics result structures
          if (isset($result['results'])) {
            // Standard structure: results at root level
            $aggregated['analytics_results'][] = $result;
            $aggregated['data'] = array_merge($aggregated['data'], (array)$result['results']);
          } elseif (isset($result['result']['rows'])) {
            // Nested structure: results in result.rows
            $aggregated['analytics_results'][] = $result;
            $aggregated['data'] = array_merge($aggregated['data'], (array)$result['result']['rows']);
          } elseif (isset($result['result'])) {
            // Fallback: collect entire result object
            $aggregated['analytics_results'][] = $result;
            if (is_array($result['result'])) {
              $aggregated['data'] = array_merge($aggregated['data'], [$result['result']]);
            }
          }
          break;

        case 'semantic':
        case 'semantic_results':
          // 🔧 TASK 2.17.2: Always collect semantic results (even if response is empty)
          // This ensures we preserve the result structure for later processing
          $aggregated['semantic_results'][] = $result;
          
          // Collect sources if available
          if (isset($result['audit_metadata']['sources'])) {
            $aggregated['sources'] = array_merge($aggregated['sources'], (array)$result['audit_metadata']['sources']);
          }
          
          // Also collect from 'sources' field directly
          if (isset($result['sources']) && is_array($result['sources'])) {
            $aggregated['sources'] = array_merge($aggregated['sources'], $result['sources']);
          }
          
          // Collect from 'results' field (documents)
          if (isset($result['results']) && is_array($result['results'])) {
            $aggregated['data'] = array_merge($aggregated['data'], $result['results']);
          }
          break;

        case 'calculator':
          if (isset($result['result'])) {
            $aggregated['calculations'][] = $result['result'];
          }
          break;

        case 'web_search':
        case 'web_search_response':
        case 'web':
          // Web search results have 'result' (singular) not 'results' (plural)
          if (isset($result['result'])) {
            $aggregated['web_results'][] = $result;
            
            // Extract items from result if available
            if (isset($result['result']['items']) && is_array($result['result']['items'])) {
              $aggregated['data'] = array_merge($aggregated['data'], $result['result']['items']);
            }
            
            // Extract formatted text if available (for price comparisons)
            if (isset($result['result']['formatted_text']) && !empty($result['result']['formatted_text'])) {
              $aggregated['text_responses'][] = $result['result']['formatted_text'];
            }
          }
          
          // Also check for 'results' (plural) from PlanExecutor
          if (isset($result['results']) && is_array($result['results'])) {
            $aggregated['web_results'][] = $result;
            $aggregated['data'] = array_merge($aggregated['data'], $result['results']);
          }
          
          // Extract text_response if available
          if (isset($result['text_response']) && !empty($result['text_response'])) {
            $aggregated['text_responses'][] = $result['text_response'];
          }
          break;
      }
    }

    if ($this->debug) {
      $this->logger->logSecurityEvent(
        "Aggregated results - analytics: " . count($aggregated['analytics_results']) . 
        ", semantic: " . count($aggregated['semantic_results']),
        'info'
      );
    }

    return $aggregated;
  }

  /**
   * Format final result
   * 
   * This method combines aggregated sub-query results into a single coherent response.
   * 
   * Key Features:
   * 1. Text Response Fallback: If no sub-queries provide text responses, generates
   *    a fallback based on available data, sources, or query types. This ensures
   *    hybrid queries always have a text response for validation.
   * 
   * 2. Source Attribution Merging: When multiple sub-queries have source attributions,
   *    creates a "Mixed" attribution that includes all source types and counts.
   * 
   * 3. Type Determination: Determines the primary result type based on the mix of
   *    sub-query types (analytics_response, semantic_results, mixed, web_search_response).
   *
   * @param array $aggregated Aggregated results
   * @param array $entityMetadata Entity metadata
   * @return array Final result
   */
  public function formatFinalResult(array $aggregated, array $entityMetadata): array
  {
    // Combine text responses
    $textResponse = implode("\n\n", array_filter($aggregated['text_responses']));

    // TASK 2.1: Add fallback text response generation if empty
    // This is critical for hybrid query validation - ensures every result has a text response
    // even when sub-queries don't provide interpretations or text_response fields.
    // Fallback priority: data count > sources count > generic success message
    if (empty($textResponse)) {
      // Generate fallback based on available data
      if (!empty($aggregated['data'])) {
        $dataCount = count($aggregated['data']);
        $textResponse = "Retrieved {$dataCount} result(s) successfully.";
      } elseif (!empty($aggregated['sources'])) {
        $sourcesCount = count($aggregated['sources']);
        $textResponse = "Found {$sourcesCount} relevant source(s).";
      } elseif (!empty($aggregated['analytics_results']) || !empty($aggregated['semantic_results'])) {
        $textResponse = "Query executed successfully.";
      }
      
      if ($this->debug && !empty($textResponse)) {
        $this->logger->logSecurityEvent(
          "TASK 2.1: Generated fallback text_response (original was empty)",
          'info'
        );
      }
    }

    // Determine primary result type
    $primaryType = 'mixed';
    
    // 🔧 FIX: Check for web_results first (highest priority for display)
    if (!empty($aggregated['web_results'])) {
      $primaryType = 'web_search_response';
    } elseif (!empty($aggregated['analytics_results']) && empty($aggregated['semantic_results'])) {
      $primaryType = 'analytics_response';
    } elseif (!empty($aggregated['semantic_results']) && empty($aggregated['analytics_results'])) {
      $primaryType = 'semantic_results';
    }

    $finalResult = [
      'type' => $primaryType,
      'text_response' => $textResponse,
      'data' => $aggregated['data'],
      'sources' => $aggregated['sources'],
    ];

    // 🔧 TASK 2.17.2: Add 'response' field for semantic queries (critical for OrchestratorAgent extraction)
    // This ensures extractFinalResponse() can find the answer
    if (!empty($textResponse)) {
      $finalResult['response'] = $textResponse;
    }

    // 🆕 Add source attribution (merge if multiple, or use single)
    // For hybrid queries with multiple sub-queries, this creates a "Mixed" attribution
    // that preserves information about all data sources used. This is required for
    // validation and provides transparency to users about where data originated.
    if (!empty($aggregated['source_attributions'])) {
      if (count($aggregated['source_attributions']) === 1) {
        // Single source - use as-is
        $finalResult['source_attribution'] = $aggregated['source_attributions'][0];
      } else {
        // Multiple sources - create merged attribution with all source types
        $sourceTypes = array_unique(array_column($aggregated['source_attributions'], 'source_type'));
        $finalResult['source_attribution'] = [
          'source_type' => 'Mixed',
          'source_icon' => '🔀',
          'source_details' => 'Information combined from multiple sources',
          'sources' => $sourceTypes,
          'source_count' => count($aggregated['source_attributions']),
        ];
      }
    }

    // Add analytics-specific fields if present
    if (!empty($aggregated['analytics_results'])) {
      $firstAnalytics = $aggregated['analytics_results'][0];
      $finalResult['question'] = $firstAnalytics['question'] ?? '';
      $finalResult['interpretation'] = $firstAnalytics['interpretation'] ?? '';
      $finalResult['results'] = $firstAnalytics['results'] ?? [];
      $finalResult['sql_query'] = $firstAnalytics['sql_query'] ?? '';
      
      // 🆕 Preserve source attribution from analytics result if not already set
      if (!isset($finalResult['source_attribution']) && isset($firstAnalytics['source_attribution'])) {
        $finalResult['source_attribution'] = $firstAnalytics['source_attribution'];
      }
    }

    // Add semantic-specific fields if present
    if (!empty($aggregated['semantic_results'])) {
      $firstSemantic = $aggregated['semantic_results'][0];
      
      // 🔧 TASK 2.17.2: Preserve 'response' field from semantic result (highest priority)
      if (isset($firstSemantic['response']) && !empty($firstSemantic['response'])) {
        $finalResult['response'] = $firstSemantic['response'];
      }
      
      $finalResult['audit_metadata'] = $firstSemantic['audit_metadata'] ?? [];
      
      // 🆕 Preserve source attribution from semantic result if not already set
      if (!isset($finalResult['source_attribution']) && isset($firstSemantic['source_attribution'])) {
        $finalResult['source_attribution'] = $firstSemantic['source_attribution'];
      }
    }

    // Add entity metadata if present
    if (!empty($entityMetadata['entity_id'])) {
      $finalResult['entity_id'] = $entityMetadata['entity_id'];
      $finalResult['entity_type'] = $entityMetadata['entity_type'];
      
      $finalResult['_entity_metadata'] = [
        'entity_id' => $entityMetadata['entity_id'],
        'entity_type' => $entityMetadata['entity_type'],
      ];

      if ($this->debug) {
        $this->logger->logSecurityEvent(
          "Final result includes entity metadata: {$entityMetadata['entity_type']} #{$entityMetadata['entity_id']}",
          'info'
        );
      }
    }

    // Add calculations if present
    if (!empty($aggregated['calculations'])) {
      $finalResult['calculations'] = $aggregated['calculations'];
    }

    // Add web results if present
    if (!empty($aggregated['web_results'])) {
      $finalResult['web_results'] = $aggregated['web_results'];
      
      // 🔧 FIX: Generate text_response for web search results if not already present
      if (empty($textResponse)) {
        $firstWebResult = $aggregated['web_results'][0];
        
        // Check if this is a price comparison
        if (isset($firstWebResult['result']['is_price_comparison']) && $firstWebResult['result']['is_price_comparison']) {
          // Price comparison - use formatted text
          if (isset($firstWebResult['result']['formatted_text'])) {
            $finalResult['text_response'] = $firstWebResult['result']['formatted_text'];
            $finalResult['response'] = $firstWebResult['result']['formatted_text'];
          }
        } else {
          // Standard web search - format items using WebSearchResultFormatter
          if (isset($firstWebResult['result']['items']) && is_array($firstWebResult['result']['items'])) {
            $items = $firstWebResult['result']['items'];
            $query = $firstWebResult['query'] ?? 'votre recherche';
            
            $formattedText = \ClicShopping\AI\Helper\Formatter\WebSearchResultFormatter::formatAsHtml($query, $items);
            
            $finalResult['text_response'] = $formattedText;
            $finalResult['response'] = $formattedText;
          }
        }
        
        // Update primary type to web_search_response
        $finalResult['type'] = 'web_search_response';
      }
    }

    // 🆕 Debug: Log if source_attribution is in final result
    if ($this->debug) {
      // TASK 2.5: Debug logging for final result structure before validation
      $this->logger->logSecurityEvent(
        "Final result structure before validation",
        'info',
        [
          'type' => $finalResult['type'] ?? 'unknown',
          'has_text_response' => isset($finalResult['text_response']) && !empty($finalResult['text_response']),
          'has_response' => isset($finalResult['response']) && !empty($finalResult['response']),
          'has_source_attribution' => isset($finalResult['source_attribution']),
          'has_data' => isset($finalResult['data']) && !empty($finalResult['data']),
          'has_sources' => isset($finalResult['sources']) && !empty($finalResult['sources']),
          'text_response_length' => isset($finalResult['text_response']) ? strlen($finalResult['text_response']) : 0,
          'data_count' => isset($finalResult['data']) ? count($finalResult['data']) : 0,
          'sources_count' => isset($finalResult['sources']) ? count($finalResult['sources']) : 0,
        ]
      );
      
      // Original debug logging
      $this->logger->logSecurityEvent(
        "ResultSynthesizer: Final result has source_attribution: " . 
        (isset($finalResult['source_attribution']) ? 'YES (' . ($finalResult['source_attribution']['source_type'] ?? 'unknown') . ')' : 'NO'),
        'info'
      );
      $this->logger->logSecurityEvent(
        "ResultSynthesizer: Collected " . count($aggregated['source_attributions']) . " source attributions",
        'info'
      );
    }

    return $finalResult;
  }

  /**
   * Extract entity metadata from results
   *
   * @param array $stepResults Array of step results
   * @return array Entity metadata
   */
  public function extractEntityMetadata(array $stepResults): array
  {
    $metadata = [
      'entity_id' => null,
      'entity_type' => null,
    ];

    // Priority order: _step_entity_metadata > direct entity_id > _entity_metadata
    foreach ($stepResults as $result) {
      if (!is_array($result)) {
        continue;
      }

      // Check _step_entity_metadata (highest priority)
      if (isset($result['_step_entity_metadata']['entity_id']) && $result['_step_entity_metadata']['entity_id'] > 0) {
        $metadata['entity_id'] = $result['_step_entity_metadata']['entity_id'];
        $metadata['entity_type'] = $result['_step_entity_metadata']['entity_type'] ?? 'unknown';
        
        if ($this->debug) {
          $this->logger->logSecurityEvent(
            "Found entity in _step_entity_metadata: {$metadata['entity_type']} #{$metadata['entity_id']}",
            'info'
          );
        }
        break;
      }

      // Check direct entity_id
      if (isset($result['entity_id']) && $result['entity_id'] > 0) {
        $metadata['entity_id'] = $result['entity_id'];
        $metadata['entity_type'] = $result['entity_type'] ?? 'unknown';
        
        if ($this->debug) {
          $this->logger->logSecurityEvent(
            "Found entity in direct fields: {$metadata['entity_type']} #{$metadata['entity_id']}",
            'info'
          );
        }
        break;
      }

      // Check _entity_metadata
      if (isset($result['_entity_metadata']['entity_id']) && $result['_entity_metadata']['entity_id'] > 0) {
        $metadata['entity_id'] = $result['_entity_metadata']['entity_id'];
        $metadata['entity_type'] = $result['_entity_metadata']['entity_type'] ?? 'unknown';
        
        if ($this->debug) {
          $this->logger->logSecurityEvent(
            "Found entity in _entity_metadata: {$metadata['entity_type']} #{$metadata['entity_id']}",
            'info'
          );
        }
        break;
      }
    }

    if ($this->debug && $metadata['entity_id'] === null) {
      $this->logger->logSecurityEvent(
        "No entity metadata found in " . count($stepResults) . " step results",
        'info'
      );
    }

    return $metadata;
  }
}
