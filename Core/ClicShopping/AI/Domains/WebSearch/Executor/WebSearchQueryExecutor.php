<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\AI\Domains\WebSearch\Executor;

use ClicShopping\AI\Helper\AgentResponseHelper;
use ClicShopping\AI\Helper\ProductHelper;
use ClicShopping\AI\Security\SecurityLogger;
use ClicShopping\AI\Helper\Formatter\ResultFormatter;
use ClicShopping\AI\Agents\Memory\ConversationMemory;
use ClicShopping\AI\Domains\WebSearch\Tool\WebSearchTool;

/**
 * WebSearchQueryExecutor Class
 *
 * Responsibility: Execute web search queries using SERAPI.
 * This class is focused solely on web search execution and does not handle
 * other query types or orchestration logic.
 *
 * Extracted from HybridQueryProcessor as part of refactoring (Task 2.11.2)
 */
class WebSearchQueryExecutor
{
  private SecurityLogger $logger;
  private bool $debug;
  private ?ConversationMemory $conversationMemory;

  /**
   * Constructor
   *
   * @param bool $debug Enable debug logging
   * @param ConversationMemory|null $conversationMemory Optional conversation memory for context
   */
  public function __construct(bool $debug = false, ?ConversationMemory $conversationMemory = null)
  {
    $this->logger = new SecurityLogger();
    $this->debug = $debug;
    $this->conversationMemory = $conversationMemory;
    
    // TASK 2.18: Initialize ProductHelper with debug mode
    ProductHelper::initialize($debug);
  }

  /**
   * Execute a web search query
   *
   * Uses WebSearchTool for web search with cache and SERAPI integration.
   * Detects price comparison queries and automatically performs price analysis.
   *
   * @param string $query Web search query
   * @param array $context Context information
   * @return array Result with web data and optional price comparison
   */
  public function execute(string $query, array $context = []): array
  {
    try {
      if ($this->debug) {
        $this->logger->logSecurityEvent(
          "WebSearchQueryExecutor: Executing web search query: {$query}",
          'info'
        );
      }

      // TASK 2.18: Resolve contextual references using ConversationMemory
      $resolvedQuery = $query;
      $contextUsed = null;
      $lastEntity = null;
      $isImplicitContext = false;
      
      if ($this->conversationMemory !== null) {
        try {
          $resolutionResult = $this->conversationMemory->resolveContextualReferences($query);
          
          if ($resolutionResult['has_references']) {
            // TASK 2.18: Check if this is an implicit contextual query
            $isImplicitContext = $resolutionResult['is_implicit_context'] ?? false;
            $lastEntity = $resolutionResult['last_entity'] ?? null;
            
            if (!empty($resolutionResult['resolved_query'])) {
              $resolvedQuery = $resolutionResult['resolved_query'];
              $contextUsed = $resolutionResult['context_used'];
            }
            
            if ($this->debug) {
              if ($isImplicitContext && $lastEntity !== null) {
                $this->logger->logSecurityEvent(
                  "TASK 2.18: Implicit contextual query detected with last entity: {$lastEntity['type']} (ID: {$lastEntity['id']})",
                  'info'
                );
              } else {
                $this->logger->logSecurityEvent(
                  "Contextual references resolved in web search: '{$query}' -> '{$resolvedQuery}'",
                  'info'
                );
              }
            }
          }
        } catch (\Exception $e) {
          $this->logger->logSecurityEvent(
            "Error resolving contextual references: " . $e->getMessage(),
            'warning'
          );
        }
      }

      // Try to use WebSearchTool
      try {
        $webSearchTool = new WebSearchTool();
        
        // Detect if this is a price comparison query
        $isPriceComparison = $this->isPriceComparisonQuery($resolvedQuery);
        
        if ($isPriceComparison) {
          if ($this->debug) {
            $this->logger->logSecurityEvent(
              "Detected price comparison query: {$resolvedQuery}",
              'info'
            );
          }
          
          // TASK 2.18: Step 1 - Try to find product in database from query
          // TASK 4.3.5: Pass language_id from context
          $languageId = $context['language_id'] ?? 1;
          $product = $webSearchTool->findProductInDatabase($resolvedQuery, $languageId);
          
          // TASK 2.18: Step 1.5 - If no product found and we have last entity, use it
          if ($product === null && $lastEntity !== null && $lastEntity['type'] === 'product') {
            if ($this->debug) {
              $this->logger->logSecurityEvent(
                "TASK 2.18: No product in query, using last entity from memory: product (ID: {$lastEntity['id']})",
                'info'
              );
            }
            
            // Get product details from database using entity ID via ProductHelper
            $product = ProductHelper::getProductById($lastEntity['id']);
            
            if ($product !== null && $this->debug) {
              $this->logger->logSecurityEvent(
                "TASK 2.18: Retrieved product from memory: {$product['name']} (ID: {$product['product_id']})",
                'info'
              );
            }
          }
          
          if ($product !== null) {
            if ($this->debug) {
              $this->logger->logSecurityEvent(
                "Product found for price comparison: {$product['name']} (ID: {$product['product_id']})",
                'info'
              );
            }
            
            // Step 2: Search web for competitor prices
            $searchQuery = $product['name'] . ' price';
            $searchResult = $webSearchTool->search($searchQuery, [
              'max_results' => 10,
              'engine' => 'google',
            ]);
            
            if ($searchResult['success'] && !empty($searchResult['items'])) {
              // Step 3: Compare prices
              $comparison = $webSearchTool->comparePrice($product, $searchResult);
              
              if ($comparison['success']) {
                // Store product entity in memory
                if ($this->conversationMemory !== null && isset($product['product_id'])) {
                  try {
                    $this->conversationMemory->setLastEntity((int)$product['product_id'], 'product');
                    
                    if ($this->debug) {
                      $this->logger->logSecurityEvent(
                        "Stored product entity in memory from web search: product (ID: {$product['product_id']})",
                        'info'
                      );
                    }
                  } catch (\Exception $e) {
                    $this->logger->logSecurityEvent(
                      "Error storing entity in memory: " . $e->getMessage(),
                      'warning'
                    );
                  }
                }
                
                // Format the comparison result for display
                $formattedComparison = $this->formatPriceComparison($comparison);
                
                // 🔧 TASK 4.3.7.2: Add entity metadata for EntityExtractor
                $response = AgentResponseHelper::createWebSearchResponse(
                  $query,
                  [
                    'formatted_text' => $formattedComparison,
                    'is_price_comparison' => true,
                    'product' => $product,
                    'comparison_data' => $comparison,
                  ],
                  true,
                  [
                    'cache_source' => $searchResult['cache_source'] ?? 'none',
                    'execution_time' => $searchResult['execution_time'] ?? 0,
                    'cached' => $searchResult['cached'] ?? false,
                    'context_used' => $contextUsed !== null || $lastEntity !== null,
                    'query_resolved' => $resolvedQuery !== $query,
                    'context_entity' => $lastEntity,
                    'context_entity_name' => $product['name'] ?? null,
                  ]
                );
                
                // 🔧 TASK 4.3.7.2: Add _step_entity_metadata for EntityExtractor
                if (isset($product['entity_id']) && isset($product['entity_type'])) {
                  $response['_step_entity_metadata'] = [
                    'entity_id' => $product['entity_id'],
                    'entity_type' => $product['entity_type'],
                    'source' => 'web_search_product_lookup',
                    'detection_method' => $product['detection_method'] ?? 'unknown'
                  ];
                  
                  // Also add to top level for backward compatibility
                  $response['entity_id'] = $product['entity_id'];
                  $response['entity_type'] = $product['entity_type'];
                  
                  if ($this->debug) {
                    $this->logger->logSecurityEvent(
                      "TASK 4.3.7.2: Added entity metadata to web search response - entity_type: {$product['entity_type']}, entity_id: {$product['entity_id']}",
                      'info'
                    );
                  }
                }
                
                return $response;
              }
            }
          } else {
            // Product not found - return clear error message to user
            $this->logger->logSecurityEvent(
              "⚠️ Product not found in database for price comparison: {$resolvedQuery}",
              'warning',
              ['query' => $query, 'resolved_query' => $resolvedQuery]
            );
            
            // Return helpful error message
            return AgentResponseHelper::createWebSearchResponse(
              $query,
              [
                'formatted_text' => "Je n'ai pas trouvé le produit '{$resolvedQuery}' dans notre base de données. Pouvez-vous vérifier le nom du produit ou essayer avec un nom différent?",
                'is_price_comparison' => true,
                'product_found' => false,
                'error' => 'product_not_found',
                'suggestion' => 'Vérifiez l\'orthographe du nom du produit ou essayez une recherche plus générale.'
              ],
              false,
              [
                'query_resolved' => $resolvedQuery !== $query,
                'context_used' => $contextUsed !== null || $lastEntity !== null,
                'error_type' => 'product_not_found'
              ]
            );
          }
        }
        
        // Standard web search
        // TASK 2.18: Try to find product in database first to store entity in memory
        $product = null;
        try {
          // TASK 4.3.5: Pass language_id from context
          $languageId = $context['language_id'] ?? 1;
          $product = $webSearchTool->findProductInDatabase($resolvedQuery, $languageId);
          
          // TASK 2.18: If no product found in query and we have last entity, use it
          if ($product === null && $lastEntity !== null && $lastEntity['type'] === 'product') {
            if ($this->debug) {
              $this->logger->logSecurityEvent(
                "TASK 2.18: No product in query for standard web search, using last entity: product (ID: {$lastEntity['id']})",
                'info'
              );
            }
            
            $product = ProductHelper::getProductById($lastEntity['id']);
            
            if ($product !== null && $this->debug) {
              $this->logger->logSecurityEvent(
                "TASK 2.18: Using product from memory for web search: {$product['name']} (ID: {$product['product_id']})",
                'info'
              );
            }
          }
          
          if ($product !== null && $this->conversationMemory !== null) {
            $this->conversationMemory->setLastEntity((int)$product['product_id'], 'product');
            
            if ($this->debug) {
              $this->logger->logSecurityEvent(
                "Stored product entity in memory from standard web search: product (ID: {$product['product_id']}, Name: {$product['name']})",
                'info'
              );
            }
          }
        } catch (\Exception $e) {
          if ($this->debug) {
            $this->logger->logSecurityEvent(
              "Could not find product in database for web search: " . $e->getMessage(),
              'info'
            );
          }
        }
        
        $searchResult = $webSearchTool->search($resolvedQuery, [
          'max_results' => 10,
          'engine' => 'google',
        ]);

        if ($searchResult['success']) {
          // Format results - map 'link' field to 'url' for consistency
          $items = $searchResult['items'] ?? [];
          $formattedResults = [];
          
          foreach ($items as $item) {
            $formattedResults[] = [
              'title' => $item['title'] ?? '',
              'snippet' => $item['snippet'] ?? '',
              'url' => $item['link'] ?? $item['url'] ?? '',
              'source' => $item['source'] ?? '',
            ];
          }

          // 🔧 TASK 4.3.7.2: Add entity metadata for EntityExtractor
          $response = AgentResponseHelper::createWebSearchResponse(
            $query,
            ['items' => $formattedResults],
            true,
            [
              'cache_source' => $searchResult['cache_source'] ?? 'none',
              'execution_time' => $searchResult['execution_time'] ?? 0,
              'cached' => $searchResult['cached'] ?? false,
              'context_used' => $contextUsed !== null || $lastEntity !== null,
              'query_resolved' => $resolvedQuery !== $query,
              'product_found' => $product !== null,
              'product_id' => $product['product_id'] ?? null,
              'context_entity' => $lastEntity,
              'context_entity_name' => $product['name'] ?? null,
            ]
          );
          
          // 🔧 TASK 4.3.7.2: Add _step_entity_metadata for EntityExtractor
          if ($product !== null && isset($product['entity_id']) && isset($product['entity_type'])) {
            $response['_step_entity_metadata'] = [
              'entity_id' => $product['entity_id'],
              'entity_type' => $product['entity_type'],
              'source' => 'web_search_product_lookup',
              'detection_method' => $product['detection_method'] ?? 'unknown'
            ];
            
            // Also add to top level for backward compatibility
            $response['entity_id'] = $product['entity_id'];
            $response['entity_type'] = $product['entity_type'];
            
            if ($this->debug) {
              $this->logger->logSecurityEvent(
                "TASK 4.3.7.2: Added entity metadata to standard web search response - entity_type: {$product['entity_type']}, entity_id: {$product['entity_id']}",
                'info'
              );
            }
          } else {
            // No product found - this is a general web search
            $response['_step_entity_metadata'] = [
              'entity_id' => 0,
              'entity_type' => 'general',
              'source' => 'web_search_no_product'
            ];
            $response['entity_id'] = 0;
            $response['entity_type'] = 'general';
            
            if ($this->debug) {
              $this->logger->logSecurityEvent(
                "TASK 4.3.7.2: No product found in web search - setting entity_type='general'",
                'info'
              );
            }
          }
          
          return $response;
        } else {
          throw new \Exception($searchResult['error'] ?? 'Web search failed');
        }

      } catch (\RuntimeException $e) {
        if ($this->debug) {
          $this->logger->logSecurityEvent(
            "WebSearchTool not available: " . $e->getMessage(),
            'warning'
          );
        }

        // User-friendly error message for SerpAPI issues
        return AgentResponseHelper::createErrorResponse(
          $query,
          'La recherche web n\'est pas disponible actuellement. Veuillez réessayer plus tard.',
          'web_search',
          [
            'error_type' => 'configuration_error',
            'component' => 'WebSearchQueryExecutor::execute',
            'technical_details' => $e->getMessage(), // Keep technical details in metadata for debugging
          ]
        );
      }

    } catch (\Exception $e) {
      $errorId = uniqid('web_', true);
      $this->logger->logSecurityEvent(
        "Error executing web search [ID: {$errorId}]: " . $e->getMessage(),
        'error'
      );

      return AgentResponseHelper::createErrorResponse(
        $query,
        'Unable to execute web search. Please try again.',
        'web_search',
        [
          'error_id' => $errorId,
          'error_type' => 'execution_error',
          'component' => 'WebSearchQueryExecutor::execute',
        ]
      );
    }
  }

  /**
   * Detect if query is asking for price comparison
   *
   * PATTERN BYPASS: Respects USE_PATTERN_BASED_DETECTION flag
   * - Pure LLM mode: Returns false (price comparison disabled)
   * - Pattern mode: Uses HybridPattern for detection
   *
   * @param string $query Query to analyze
   * @return bool True if price comparison query
   */
  private function isPriceComparisonQuery(string $query): bool
  {
    // TASK 2.9.8.6.1: Check if pattern-based detection is enabled
    if (!defined('USE_PATTERN_BASED_DETECTION') || USE_PATTERN_BASED_DETECTION === 'False') {
      // Pure LLM mode: Price comparison detection disabled
      // LLM will handle price comparison queries through standard web search
      if ($this->debug) {
        $this->logger->logSecurityEvent(
          "Price comparison detection bypassed (Pure LLM mode)",
          'info'
        );
      }
      return false;
    }

    // Pattern mode: Use HybridPattern for detection
    // @deprecated Pattern-based detection removed in Pure LLM mode
    // This code is never executed (USE_PATTERN_BASED_DETECTION removed in task 5.1.6)
    // TODO: Remove this dead code block in Q2 2026
    $priceComparisonPatterns = HybridPattern::priceComparisonPatterns();

    foreach ($priceComparisonPatterns as $pattern) {
      if (preg_match($pattern, $query)) {
        return true;
      }
    }

    return false;
  }

  /**
   * Format price comparison result for display
   * Delegates to ResultFormatter (Task 2.11.3)
   *
   * @param array $comparison Comparison data from comparePrice()
   * @return string Formatted text for display
   */
  private function formatPriceComparison(array $comparison): string
  {
    return ResultFormatter::formatPriceComparisonAsText($comparison);
  }
}
