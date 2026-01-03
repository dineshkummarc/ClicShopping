<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\AI\Agents\Orchestrator\SubHybridQueryProcessor;

use ClicShopping\AI\Helper\Formatter\ResultFormatter;
use ClicShopping\AI\Helper\AgentResponseHelper;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\Gpt;

/**
 * ResultSynthesizer - Synthesizes results from multiple sub-queries
 *
 * Responsibilities:
 * - Determine synthesis type based on query classification
 * - Extract from embedding results for semantic queries (no LLM generation)
 * - Format SQL results in tables for analytics queries
 * - Present external sources with citations for web_search queries
 * - Combine results with source attribution for hybrid queries using LLM
 * - Aggregate entity information from sub-queries
 *
 * Requirements:
 * - REQ-4.1: Determine synthesis type based on query classification
 * - REQ-4.2: Semantic synthesis (extract from embeddings only)
 * - REQ-4.3: Analytics synthesis (format SQL results)
 * - REQ-4.4: Web_search synthesis (present sources with citations)
 * - REQ-4.5: Hybrid synthesis (combine with LLM)
 * - REQ-4.6: Aggregate entity information from sub-queries
 *
 * @package ClicShopping\AI\Agents\Orchestrator\SubHybridQueryProcessor
 * @since 2025-12-14
 */
class ResultSynthesizer extends BaseQueryProcessor
{
  /**
   * @var PromptValidator Validator for LLM prompts
   */
  private PromptValidator $promptValidator;

  /**
   * Constructor
   *
   * @param bool $debug Enable debug logging
   */
  public function __construct(bool $debug = false)
  {
    parent::__construct($debug, 'ResultSynthesizer');
    $this->promptValidator = new PromptValidator($debug);
  }

  /**
   * Process: Synthesize results from multiple sub-queries
   */
  public function process($input, array $context = []): array
  {
    if (!$this->validate($input)) {
      return $this->handleError("Invalid input for synthesis", null, ['type' => 'error', 'message' => 'Invalid input for result synthesis']);
    }

    $subQueryResults = $input;
    $originalQuery = $context['original_query'] ?? '';

    try {
      if ($this->debug) $this->logInfo("Synthesizing results for query: {$originalQuery}", ['count' => count($subQueryResults)]);

      // Determine synthesis type
      $synthesisType = $this->determineSynthesisType($subQueryResults);
      if ($this->debug) $this->logInfo("Synthesis type determined: {$synthesisType}");

      // Extract data from results
      list($combinedText, $combinedData, $sources) = $this->extractResultData($subQueryResults);

      // Synthesize based on type
      $textResponse = $this->synthesizeByType($synthesisType, $subQueryResults, $combinedText, $combinedData, $sources, $originalQuery);

      // Aggregate entity from sub-queries
      $aggregatedEntity = $this->aggregateEntityFromSubQueries($subQueryResults);

      // For single result, return it directly with proper format
      if (count($subQueryResults) === 1) {
        return $this->formatSingleResult($subQueryResults[0], $synthesisType, $aggregatedEntity);
      }

      // For multiple results, use hybrid response helper
      return $this->formatHybridResult($originalQuery, $subQueryResults, $textResponse, $synthesisType, $aggregatedEntity, $context);

    } catch (\Exception $e) {
      return $this->handleError("Error synthesizing results", $e, ['type' => 'error', 'message' => 'Failed to synthesize results: ' . $e->getMessage()]);
    }
  }

  /**
   * Extract data from sub-query results
   * 
   * TASK 5.3.1.1: Added deduplication to prevent duplicate HTML in web search results
   */
  private function extractResultData(array $subQueryResults): array
  {
    $combinedText = [];
    $combinedData = [];
    $sources = [];
    $addedContentHashes = []; // Track added content to prevent duplicates

    foreach ($subQueryResults as $result) {
      // Extract text_response if available (preferred for synthesis)
      if (isset($result['text_response']) && !empty($result['text_response'])) {
        $hash = md5($result['text_response']);
        if (!isset($addedContentHashes[$hash])) {
          $combinedText[] = $result['text_response'];
          $addedContentHashes[$hash] = true;
          
          if ($this->debug) {
            $this->logInfo("Added text_response to combined text", ['hash' => substr($hash, 0, 8), 'length' => strlen($result['text_response'])]);
          }
        } else {
          if ($this->debug) {
            $this->logInfo("Skipped duplicate text_response", ['hash' => substr($hash, 0, 8)]);
          }
        }
      }
      
      // Extract result data
      if (isset($result['result'])) {
        if (is_string($result['result'])) {
          // Check if this content is already added (by hash)
          $hash = md5($result['result']);
          if (!isset($addedContentHashes[$hash])) {
            $combinedText[] = $result['result'];
            $addedContentHashes[$hash] = true;
            
            if ($this->debug) {
              $this->logInfo("Added result string to combined text", ['hash' => substr($hash, 0, 8), 'length' => strlen($result['result'])]);
            }
          } else {
            if ($this->debug) {
              $this->logInfo("Skipped duplicate result string", ['hash' => substr($hash, 0, 8)]);
            }
          }
        } elseif (is_array($result['result'])) {
          $combinedData[] = $result['result'];
        }
      }
      
      // Extract sources
      if (isset($result['sources'])) {
        $sources = array_merge($sources, $result['sources']);
      }
    }

    return [$combinedText, $combinedData, $sources];
  }

  /**
   * Format single result with entity metadata
   */
  private function formatSingleResult(array $result, string $synthesisType, ?array $aggregatedEntity): array
  {
    // Ensure standardized format
    if (!isset($result['source_attribution'])) {
      $result['source_attribution'] = [
        'primary_source' => ucfirst($synthesisType),
        'icon' => $this->getIconForType($synthesisType),
        'details' => [],
        'confidence' => 0.7,
      ];
    }

    if (!isset($result['metadata'])) {
      $result['metadata'] = ['timestamp' => date('c'), 'execution_time' => 0];
    }

    // Add aggregated entity
    if ($aggregatedEntity !== null) {
      $result['_step_entity_metadata'] = $aggregatedEntity;
      $result['entity_id'] = $aggregatedEntity['entity_id'];
      $result['entity_type'] = $aggregatedEntity['entity_type'];
      if ($this->debug) $this->logInfo("Added entity to single result", ['type' => $aggregatedEntity['entity_type'], 'id' => $aggregatedEntity['entity_id']]);
    }

    return $result;
  }

  /**
   * Format hybrid result with entity metadata
   */
  private function formatHybridResult(string $originalQuery, array $subQueryResults, string $textResponse, string $synthesisType, ?array $aggregatedEntity, array $context): array
  {
    $hybridResponse = AgentResponseHelper::createHybridResponse(
      $originalQuery,
      $subQueryResults,
      $textResponse,
      [
        'execution_time' => microtime(true) - ($context['start_time'] ?? microtime(true)),
        'synthesis_type' => $synthesisType,
      ]
    );

    // Add aggregated entity
    if ($aggregatedEntity !== null) {
      $hybridResponse['_step_entity_metadata'] = $aggregatedEntity;
      $hybridResponse['entity_id'] = $aggregatedEntity['entity_id'];
      $hybridResponse['entity_type'] = $aggregatedEntity['entity_type'];
      if ($this->debug) $this->logInfo("Added entity to hybrid result", ['type' => $aggregatedEntity['entity_type'], 'id' => $aggregatedEntity['entity_id']]);
    }

    return $hybridResponse;
  }

  /**
   * Validate input
   */
  public function validate($input): bool
  {
    return is_array($input) && !empty($input);
  }

  /**
   * Determine synthesis type based on sub-query results
   */
  private function determineSynthesisType(array $subQueryResults): string
  {
    if (count($subQueryResults) === 1) return $subQueryResults[0]['type'] ?? 'semantic';
    $types = array_unique(array_column($subQueryResults, 'type'));
    return count($types) === 1 ? $types[0] : 'hybrid';
  }

  /**
   * Synthesize results based on type
   */
  private function synthesizeByType(string $synthesisType, array $subQueryResults, array $combinedText, array $combinedData, array $sources, string $originalQuery): string
  {
    return match($synthesisType) {
      'semantic' => $this->synthesizeSemantic($subQueryResults, $combinedText),
      'analytics' => $this->synthesizeAnalytics($combinedData),
      'web_search' => $this->synthesizeWebSearch($subQueryResults, $combinedText, $sources),
      'hybrid' => $this->synthesizeHybrid($combinedText, $originalQuery),
      default => implode("\n\n", $combinedText)
    };
  }

  /**
   * Synthesize semantic results (extract from embeddings only, no LLM generation)
   * REQ-4.2: Extract from embedding results only
   */
  private function synthesizeSemantic(array $subQueryResults, array $combinedText): string
  {
    // Extract content from embedding results
    $semanticTexts = [];
    foreach ($subQueryResults as $result) {
      if (isset($result['result']) && is_array($result['result'])) {
        foreach ($result['result'] as $embeddingResult) {
          if (isset($embeddingResult['content'])) {
            $score = isset($embeddingResult['score']) ? round($embeddingResult['score'] * 100, 2) : 0;
            $semanticTexts[] = $embeddingResult['content'] . "\n(Relevance: {$score}%)";
          }
        }
      }
    }

    if (!empty($semanticTexts)) {
      if ($this->debug) {
        $this->logInfo("Semantic synthesis: Extracted text from embeddings without LLM generation");
      }
      return implode("\n\n", $semanticTexts);
    }

    return !empty($combinedText) ? implode("\n\n", $combinedText) : "No semantic results found in the knowledge base.";
  }

  /**
   * Synthesize analytics results (format SQL results in tables)
   * REQ-4.3: Format SQL results in tables with actual data
   */
  private function synthesizeAnalytics(array $combinedData): string
  {
    if (!empty($combinedData)) {
      if ($this->debug) $this->logInfo("Analytics synthesis: Formatted SQL results in tables");
      return ResultFormatter::formatAnalyticsAsText($combinedData);
    }
    return "No analytics data available.";
  }

  /**
   * Synthesize web search results (present sources with citations)
   * REQ-4.4: Present external sources with citations
   */
  private function synthesizeWebSearch(array $subQueryResults, array $combinedText, array $sources): string
  {
    // Extract sources from result data
    $webSources = [];
    foreach ($subQueryResults as $result) {
      if (isset($result['result']) && is_array($result['result'])) {
        foreach ($result['result'] as $item) {
          if (isset($item['url']) && !empty($item['url'])) {
            $webSources[] = [
              'title' => $item['title'] ?? 'Source',
              'url' => $item['url'],
              'snippet' => $item['snippet'] ?? '',
            ];
          }
        }
      }
    }

    if (!empty($combinedText) || !empty($webSources)) {
      if ($this->debug) $this->logInfo("Web search synthesis: Formatted sources", ['count' => count($webSources)]);
      return ResultFormatter::formatWebSearchAsText($combinedText, $webSources);
    }
    return "No web search results found.";
  }

  /**
   * Synthesize hybrid results (combine without LLM)
   * REQ-4.5: Combine results with source attribution
   * 
   * TASK 5.2.1.1: Changed to direct concatenation instead of LLM synthesis
   * The sub-queries already have formatted text_response fields, so we just
   * need to combine them with clear section headers. No LLM call needed.
   */
  private function synthesizeHybrid(array $combinedText, string $originalQuery): string
  {
    if (empty($combinedText)) return "Results processed successfully.";

    // ✅ Simply concatenate the formatted text responses with separators
    // Each text_response is already formatted by ResultFormatter
    if ($this->debug) {
      $this->logInfo("Hybrid synthesis: Combining " . count($combinedText) . " formatted responses");
    }

    return implode("\n\n---\n\n", $combinedText);
  }

  /**
   * Aggregate entity information from sub-queries
   * REQ-4.6: Priority order: analytics > web_search > semantic
   */
  private function aggregateEntityFromSubQueries(array $subQueryResults): ?array
  {
    $priorityOrder = ['analytics', 'web_search', 'semantic'];

    foreach ($priorityOrder as $priorityType) {
      foreach ($subQueryResults as $result) {
        if (($result['type'] ?? '') !== $priorityType) continue;

        // Check for _step_entity_metadata
        if (isset($result['_step_entity_metadata'])) {
          $entityMeta = $result['_step_entity_metadata'];
          if (isset($entityMeta['entity_id']) && $entityMeta['entity_id'] > 0 && isset($entityMeta['entity_type'])) {
            if ($this->debug) $this->logInfo("Found entity in {$priorityType}", ['type' => $entityMeta['entity_type'], 'id' => $entityMeta['entity_id']]);
            return [
              'entity_id' => $entityMeta['entity_id'],
              'entity_type' => $entityMeta['entity_type'],
              'source' => 'hybrid_aggregated_from_' . $priorityType,
              'original_source' => $entityMeta['source'] ?? 'unknown'
            ];
          }
        }

        // Check for entity at top level
        if (isset($result['entity_id']) && $result['entity_id'] > 0 && isset($result['entity_type'])) {
          if ($this->debug) $this->logInfo("Found entity at top level in {$priorityType}", ['type' => $result['entity_type'], 'id' => $result['entity_id']]);
          return [
            'entity_id' => $result['entity_id'],
            'entity_type' => $result['entity_type'],
            'source' => 'hybrid_aggregated_from_' . $priorityType,
            'original_source' => 'top_level'
          ];
        }
      }
    }

    if ($this->debug) $this->logInfo("No entity found in any sub-query");
    return null;
  }

  /**
   * Get icon for query type
   *
   * @param string $type Query type
   * @return string Icon emoji
   */
  private function getIconForType(string $type): string
  {
    $icons = [
      'analytics' => '📊',
      'semantic' => '📚',
      'web_search' => '🌐',
      'hybrid' => '🔀',
    ];

    return $icons[$type] ?? '🤖';
  }
}
