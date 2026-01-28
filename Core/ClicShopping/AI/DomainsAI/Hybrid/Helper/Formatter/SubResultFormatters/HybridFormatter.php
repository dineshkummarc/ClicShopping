<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\AI\DomainsAI\Hybrid\Helper\Formatter\SubResultFormatters;

use AllowDynamicProperties;

use ClicShopping\OM\Hash;
use ClicShopping\OM\Registry;
use ClicShopping\AI\Security\LlmGuardrails;
use ClicShopping\AI\Config\DomainConfig;

/**
 * HybridFormatter - Formats hybrid query results
 * 
 * Hybrid queries combine multiple query types (analytics + semantic, web_search + analytics, etc.)
 * This formatter presents the synthesized response with source attribution from all sub-queries.
 * 
 * @package ClicShopping\AI\Helper\Formatter\SubResultFormatters
 * @since 2025-12-30
 * @version 2.0 - Internationalized 2025-12-30
 */
#[AllowDynamicProperties]
class HybridFormatter extends AbstractFormatter
{
  private $language;
  private string $languageCode;

  public function __construct(bool $debug = false, bool $displaySql = false)
  {
    parent::__construct($debug, $displaySql);
    
    // Initialize language support
    $this->language = Registry::get('Language');
    $this->languageCode = $this->language->get('code');
    
    // Load language definitions (null = use current user language)
    DomainConfig::loadLanguageFile('rag_hybrid_formatter', null);
  }

  public function canHandle(array $results): bool
  {
    $type = $results['type'] ?? '';
    return $type === 'hybrid';
  }

  public function format(array $results): array
  {
    $question = $results['query'] ?? $results['question'] ?? 'Unknown request';

    $output = "<div class='hybrid-results'>";
    $output .= "<h4>" . htmlspecialchars($this->language->getDef('results_for')) . " " . htmlspecialchars($question) . "</h4>";

    // Display source attribution (shows combined sources)
    if (isset($results['source_attribution'])) {
      $output .= $this->formatSourceAttribution($results['source_attribution']);
    }

    // Guardrails
    $output .= "<div class='mt-2'></div>";
    $responseContent = $results['response'] ?? $results['text_response'] ?? '';
    
    $lmGuardrails = LlmGuardrails::checkGuardrails(
      $question, 
      Hash::displayDecryptedDataText($responseContent)
    );

    if (is_array($lmGuardrails)) {
      $output .= $this->formatGuardrailsMetrics($lmGuardrails);
    } else {
      $output .= "<div class='alert alert-warning'>" . htmlspecialchars($lmGuardrails) . "</div>";
    }

    $output .= "<div class='mt-2'></div>";

    // Display the synthesized response
    if (!empty($responseContent)) {
      // Convert plain text to HTML with line breaks
      $formattedResponse = nl2br(Hash::displayDecryptedDataText($responseContent));
      $output .= "<div class='response'><strong>" . htmlspecialchars($this->language->getDef('response_label')) . "</strong><br>" . $formattedResponse . "</div>";
    }

    // ✅ TASK 5.4.2.1: Display sub-query results (especially web_search results)
    // Render actual results from sub-queries, not just metadata
    if (isset($results['sub_queries']) && is_array($results['sub_queries'])) {
      foreach ($results['sub_queries'] as $idx => $subQuery) {
        $subType = $subQuery['type'] ?? 'unknown';
        
        // Format web_search results with full HTML rendering
        if ($subType === 'web_search' && isset($subQuery['results']) && is_array($subQuery['results'])) {
          $output .= "<div class='mt-4'>";
          $output .= "<h5>" . htmlspecialchars($this->language->getDef('web_search_results_title')) . "</h5>";
          $output .= "<div class='web-search-results'>";
          
          foreach ($subQuery['results'] as $resultIdx => $result) {
            if (is_array($result)) {
              $title = $result['title'] ?? 'Untitled';
              $url = $result['url'] ?? '#';
              $snippet = $result['snippet'] ?? '';
              
              $output .= "<div class='search-result mb-3' style='border-left: 3px solid #17a2b8; padding-left: 15px;'>";
              $output .= "<h6><a href='" . htmlspecialchars($url) . "' target='_blank' rel='noopener noreferrer'>" . htmlspecialchars($title) . "</a></h6>";
              
              if (!empty($snippet)) {
                $output .= "<p style='color: #666; font-size: 0.9em;'>" . htmlspecialchars($snippet) . "</p>";
              }
              
              $output .= "<small style='color: #999;'>" . htmlspecialchars($url) . "</small>";
              $output .= "</div>";
            }
          }
          
          $output .= "</div>";
          $output .= "</div>";
        }
        
        // Format analytics results (if present)
        if ($subType === 'analytics' && isset($subQuery['results'])) {
          $output .= "<div class='mt-4'>";
          $output .= "<h5>" . htmlspecialchars($this->language->getDef('analytics_results_title')) . "</h5>";
          // Use existing analytics formatting logic
          $output .= $this->formatAnalyticsSubQuery($subQuery);
          $output .= "</div>";
        }
        
        // Format semantic results (if present)
        if ($subType === 'semantic' && isset($subQuery['results'])) {
          $output .= "<div class='mt-4'>";
          $output .= "<h5>" . htmlspecialchars($this->language->getDef('semantic_results_title')) . "</h5>";
          // Use existing semantic formatting logic
          $output .= $this->formatSemanticSubQuery($subQuery);
          $output .= "</div>";
        }
      }
    }

    // Display sub-query information (optional, for transparency)
    if (isset($results['sub_queries']) && is_array($results['sub_queries']) && count($results['sub_queries']) > 1) {
      $output .= "<div class='mt-3'>";
      $output .= "<details style='font-size: 0.9em; color: #666;'>";
      $output .= "<summary style='cursor: pointer;'><strong>" . htmlspecialchars($this->language->getDef('source_details_label')) . count($results['sub_queries']) . " " . htmlspecialchars($this->language->getDef('combined_queries_suffix')) . "</strong></summary>";
      $output .= "<ul style='margin-top: 10px;'>";
      
      foreach ($results['sub_queries'] as $idx => $subQuery) {
        $subType = $subQuery['type'] ?? 'unknown';
        $subIcon = $this->getIconForType($subType);
        $output .= "<li>{$subIcon} <strong>" . ucfirst($subType) . "</strong>";
        
        // Show brief info about each sub-query
        if (isset($subQuery['source_attribution']['primary_source'])) {
          $output .= " - " . htmlspecialchars($subQuery['source_attribution']['primary_source']);
        }
        
        $output .= "</li>";
      }
      
      $output .= "</ul>";
      $output .= "</details>";
      $output .= "</div>";
    }

    $output .= "</div>";

    return [
      'type' => 'formatted_results',
      'content' => $output
    ];
  }

  /**
   * Format guardrails metrics for hybrid responses
   *
   * @param array $guardrails Guardrails evaluation results
   * @return string Formatted HTML output (empty if no warnings)
   */
  private function formatGuardrailsMetrics(array $guardrails): string
  {
    // Only display if there are warnings
    // For now, we'll keep it simple and not display metrics for hybrid queries
    // unless there's a specific issue
    return '';
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

  /**
   * Format analytics sub-query results
   *
   * @param array $subQuery Analytics sub-query data
   * @return string Formatted HTML
   */
  private function formatAnalyticsSubQuery(array $subQuery): string
  {
    $output = '';
    
    if (isset($subQuery['results']) && is_array($subQuery['results'])) {
      $output .= "<div class='analytics-results'>";
      
      foreach ($subQuery['results'] as $result) {
        if (isset($result['rows']) && is_array($result['rows'])) {
          $output .= "<table class='table table-sm table-bordered'>";
          
          // Table header
          if (!empty($result['rows'])) {
            $firstRow = $result['rows'][0];
            if (is_array($firstRow)) {
              $output .= "<thead><tr>";
              foreach (array_keys($firstRow) as $column) {
                $output .= "<th>" . htmlspecialchars($column) . "</th>";
              }
              $output .= "</tr></thead>";
              
              // Table body
              $output .= "<tbody>";
              foreach ($result['rows'] as $row) {
                $output .= "<tr>";
                foreach ($row as $value) {
                  $output .= "<td>" . htmlspecialchars($value) . "</td>";
                }
                $output .= "</tr>";
              }
              $output .= "</tbody>";
            }
          }
          
          $output .= "</table>";
        }
      }
      
      $output .= "</div>";
    }
    
    return $output;
  }

  /**
   * Format semantic sub-query results
   *
   * @param array $subQuery Semantic sub-query data
   * @return string Formatted HTML
   */
  private function formatSemanticSubQuery(array $subQuery): string
  {
    $output = '';
    
    if (isset($subQuery['results']) && is_array($subQuery['results'])) {
      $output .= "<div class='semantic-results'>";
      
      foreach ($subQuery['results'] as $idx => $result) {
        if (is_array($result)) {
          $content = $result['content'] ?? $result['text'] ?? '';
          $source = $result['source'] ?? $result['document_name'] ?? 'Unknown source';
          
          $output .= "<div class='semantic-result mb-3' style='border-left: 3px solid #28a745; padding-left: 15px;'>";
          $output .= "<h6>" . htmlspecialchars($source) . "</h6>";
          
          if (!empty($content)) {
            $output .= "<p>" . htmlspecialchars(substr($content, 0, 300)) . "...</p>";
          }
          
          $output .= "</div>";
        }
      }
      
      $output .= "</div>";
    }
    
    return $output;
  }
}
