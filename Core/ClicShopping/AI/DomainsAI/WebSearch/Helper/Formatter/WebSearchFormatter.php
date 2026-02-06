<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\AI\DomainsAI\WebSearch\Helper\Formatter;



use ClicShopping\OM\Hash;
use ClicShopping\OM\Registry;
use ClicShopping\AI\Security\LlmGuardrails;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\Gpt;
use ClicShopping\AI\Config\DomainConfig;
use ClicShopping\AI\DomainsAI\Hybrid\Helper\Formatter\SubResultFormatters\AbstractFormatter;

/**
 * WebSearchFormatter - Formats web search query results
 * 
 * Handles formatting of external web search results including:
 * - External URLs with clickable links
 * - Price comparisons (internal vs external)
 * - Comparative tables
 * - Source attribution with web search icon
 */

class WebSearchFormatter extends AbstractFormatter
{
  /**
   * @var \ClicShopping\OM\Language Language instance for translations
   */
  private $language;
  
  /**
   * @var string Current language code
   */
  private string $languageCode;
  
  /**
   * Constructor
   * 
   * @param bool $debug Enable debug mode
   * @param bool $displaySql Display SQL queries
   */
  public function __construct(bool $debug = false, bool $displaySql = false)
  {
    parent::__construct($debug, $displaySql);
    
    // Initialize language
    $this->language = Registry::get('Language');
    $this->languageCode = $this->language->get('code');
  }
  
  /**
   * Check if this formatter can handle the given results
   * 
   * @param array $results Results to check
   * @return bool True if results are web_search type
   */
  public function canHandle(array $results): bool
  {
    $type = $results['type'] ?? '';
    return $type === 'web_search' || $type === 'web_search_results' || $type === 'web_search_response';
  }

  /**
   * Format web search results for display
   * 
   * @param array $results Web search results to format
   * @return array Formatted results with HTML content
   */
  public function format(array $results): array
  {
    // Load language definitions
    DomainConfig::loadLanguageFile('rag_web_search_formatter');
      
    $question = $results['question'] ?? $results['query'] ?? 'Unknown request';

    // ✅ TASK 5.3.2.1: Log response structure for debugging
    if ($this->debug) {
      error_log('[WebSearchFormatter] Formatting web search results\n');
      error_log('[WebSearchFormatter] Result keys: ' . implode(', ', array_keys($results)) . "\n");
      error_log('[WebSearchFormatter] Has text_response: ' . (isset($results['text_response']) ? 'YES' : 'NO') . "\n");
      if (isset($results['text_response'])) {
        $isHtml = (strpos($results['text_response'], '<') !== false);
        error_log('[WebSearchFormatter] text_response is HTML: ' . ($isHtml ? 'YES' : 'NO') . "\n");
        error_log('[WebSearchFormatter] text_response length: ' . strlen($results['text_response']) . "\n");
      }
    }

    $output = "<div class='web-search-results'>";
    $output .= "<h4>" . $this->language->getDef('text_rag_web_search_results_for') . " " . htmlspecialchars($question) . "</h4>";

    // Display source attribution
    if (isset($results['source_attribution'])) {
      $output .= $this->formatSourceAttribution($results['source_attribution']);
    }

    // Display interpretation/summary
    $interpretationText = '';
    $isHtmlContent = false;
    
    if (isset($results['text_response']) && !empty($results['text_response'])) {
      $interpretationText = $results['text_response'];
      // Check if text_response contains HTML (from ResultSynthesizer)
      $isHtmlContent = (strpos($interpretationText, '<div') !== false || strpos($interpretationText, '<p>') !== false);
    } elseif (isset($results['interpretation']) && $results['interpretation'] !== 'Array') {
      $interpretationText = $results['interpretation'];
    } elseif (isset($results['response']) && !empty($results['response'])) {
      $interpretationText = $results['response'];
    }

    if (!empty($interpretationText)) {
      // ✅ TASK 5.3.2.1: Don't double-encode HTML content from text_response
      if ($isHtmlContent) {
        // text_response already contains formatted HTML - use as-is
        $output .= "<div class='interpretation'>" . $interpretationText . "</div>";
      } else {
        // Plain text - apply HTML encoding
        $output .= "<div class='interpretation'><strong>" . $this->language->getDef('text_rag_web_search_summary') . "</strong> " 
                . Hash::displayDecryptedDataText($interpretationText) . "</div>";
      }
    }

    // Guardrails
    $output .= "<div class='mt-2'></div>";
    $lmGuardrails = LlmGuardrails::checkGuardrails($question, Hash::displayDecryptedDataText($interpretationText));

    if (is_array($lmGuardrails)) {
      $output .= $this->formatGuardrailsMetrics($lmGuardrails);
    } else {
      $output .= "<div class='alert alert-warning'>" . htmlspecialchars($lmGuardrails) . "</div>";
    }

    $output .= "<div class='mt-2'></div>";

    // Display price comparison if available
    if (isset($results['price_comparison']) && is_array($results['price_comparison'])) {
      $output .= $this->formatPriceComparison($results['price_comparison']);
    }

    // Display web search results with URLs
    if (isset($results['web_results']) && is_array($results['web_results']) && !empty($results['web_results'])) {
      $output .= $this->formatWebResults($results['web_results']);
    } elseif (isset($results['results']) && is_array($results['results']) && !empty($results['results'])) {
      $output .= $this->formatWebResults($results['results']);
    }

    // Display external sources/URLs
    if (isset($results['sources']) && is_array($results['sources']) && !empty($results['sources'])) {
      $output .= $this->formatExternalSources($results['sources']);
    } elseif (isset($results['urls']) && is_array($results['urls']) && !empty($results['urls'])) {
      $output .= $this->formatExternalUrls($results['urls']);
    }

    // Display comparative table if available
    if (isset($results['comparison_table']) && is_array($results['comparison_table'])) {
      $output .= $this->formatComparisonTable($results['comparison_table']);
    }

    $output .= "</div>";

    // Save audit data
    $auditExtra = [
      'web_results' => $results['web_results'] ?? [],
      'sources' => $results['sources'] ?? [],
      'price_comparison' => $results['price_comparison'] ?? [],
      'processing_chain' => $results['processing_chain'] ?? []
    ];
    Gpt::saveData($question, $output, $auditExtra);

    return [
      'type' => 'formatted_results',
      'content' => $output
    ];
  }

  /**
   * Format price comparison data
   * 
   * @param array $priceComparison Price comparison data
   * @return string Formatted HTML
   */
  private function formatPriceComparison(array $priceComparison): string
  {
    $output = "<div class='price-comparison alert alert-info'>";
    $output .= "<h5>💰 " . $this->language->getDef('text_rag_web_search_price_comparison') . "</h5>";

    // Internal price
    if (isset($priceComparison['internal_price'])) {
      $internalPrice = $priceComparison['internal_price'];
      $currency = $priceComparison['currency'] ?? '€';
      $output .= "<div class='internal-price'>";
      $output .= "<strong>" . $this->language->getDef('text_rag_web_search_our_price') . "</strong> " . number_format((float)$internalPrice, 2, ',', ' ') . " {$currency}";
      $output .= "</div>";
    }

    // External prices
    if (isset($priceComparison['external_prices']) && is_array($priceComparison['external_prices'])) {
      $output .= "<div class='external-prices' style='margin-top: 10px;'>";
      $output .= "<strong>" . $this->language->getDef('text_rag_web_search_competitor_prices') . "</strong>";
      $output .= "<ul>";
      
      foreach ($priceComparison['external_prices'] as $competitor) {
        $name = $competitor['name'] ?? 'Unknown';
        $price = $competitor['price'] ?? 0;
        $url = $competitor['url'] ?? '';
        $currency = $competitor['currency'] ?? '€';
        
        $output .= "<li>";
        $output .= htmlspecialchars($name) . " : " . number_format((float)$price, 2, ',', ' ') . " {$currency}";
        
        if (!empty($url)) {
          $output .= " <a href='" . htmlspecialchars($url) . "' target='_blank' rel='noopener noreferrer'>🔗 " . $this->language->getDef('text_rag_web_search_see') . "</a>";
        }
        
        // Show percentage difference if internal price exists
        if (isset($priceComparison['internal_price'])) {
          $diff = (($price - $priceComparison['internal_price']) / $priceComparison['internal_price']) * 100;
          $diffFormatted = number_format($diff, 1);
          
          if ($diff > 0) {
            $output .= " <span class='text-success'>(+{$diffFormatted}%)</span>";
          } elseif ($diff < 0) {
            $output .= " <span class='text-danger'>({$diffFormatted}%)</span>";
          }
        }
        
        $output .= "</li>";
      }
      
      $output .= "</ul>";
      $output .= "</div>";
    }

    // Recommendation
    if (isset($priceComparison['recommendation'])) {
      $output .= "<div class='recommendation' style='margin-top: 10px; padding: 8px; background-color: #f8f9fa; border-radius: 4px;'>";
      $output .= "<strong>💡 " . $this->language->getDef('text_rag_web_search_recommendation') . "</strong> " . htmlspecialchars($priceComparison['recommendation']);
      $output .= "</div>";
    }

    $output .= "</div>";

    return $output;
  }

  /**
   * Format web search results with snippets and URLs
   * 
   * @param array $webResults Web search results
   * @return string Formatted HTML
   */
  private function formatWebResults(array $webResults): string
  {
    $output = "<div class='web-results'>";
    $output .= "<h5>🌐 " . $this->language->getDef('text_rag_web_search_external_results') . "</h5>";

    foreach ($webResults as $index => $result) {
      $title = $result['title'] ?? $this->language->getDef('text_rag_web_search_result') . " " . ($index + 1);
      $snippet = $result['snippet'] ?? $result['description'] ?? '';
      $url = $result['url'] ?? $result['link'] ?? '';
      
      $output .= "<div class='web-result-item' style='margin-bottom: 15px; padding: 10px; border-left: 3px solid #17a2b8; background-color: #f8f9fa;'>";
      
      // Title with link
      if (!empty($url)) {
        $output .= "<div class='result-title'>";
        $output .= "<a href='" . htmlspecialchars($url) . "' target='_blank' rel='noopener noreferrer' style='font-weight: bold; color: #007bff;'>";
        $output .= htmlspecialchars($title);
        $output .= " 🔗</a>";
        $output .= "</div>";
      } else {
        $output .= "<div class='result-title' style='font-weight: bold;'>" . htmlspecialchars($title) . "</div>";
      }
      
      // Snippet
      if (!empty($snippet)) {
        $output .= "<div class='result-snippet' style='margin-top: 5px; color: #666;'>";
        $output .= htmlspecialchars($snippet);
        $output .= "</div>";
      }
      
      // URL display
      if (!empty($url)) {
        $output .= "<div class='result-url' style='margin-top: 5px; font-size: 0.85em; color: #28a745;'>";
        $output .= htmlspecialchars($url);
        $output .= "</div>";
      }
      
      $output .= "</div>";
    }

    $output .= "</div>";

    return $output;
  }

  /**
   * Format external sources list
   * 
   * @param array $sources External sources
   * @return string Formatted HTML
   */
  private function formatExternalSources(array $sources): string
  {
    $output = "<div class='external-sources' style='margin-top: 15px;'>";
    $output .= "<h6>📚 " . $this->language->getDef('text_rag_web_search_external_sources') . "</h6>";
    $output .= "<ul>";

    foreach ($sources as $source) {
      if (is_string($source)) {
        $output .= "<li>" . htmlspecialchars($source) . "</li>";
      } elseif (is_array($source)) {
        $name = $source['name'] ?? $source['title'] ?? 'Source';
        $url = $source['url'] ?? $source['link'] ?? '';
        
        $output .= "<li>";
        if (!empty($url)) {
          $output .= "<a href='" . htmlspecialchars($url) . "' target='_blank' rel='noopener noreferrer'>";
          $output .= htmlspecialchars($name) . " 🔗</a>";
        } else {
          $output .= htmlspecialchars($name);
        }
        $output .= "</li>";
      }
    }

    $output .= "</ul>";
    $output .= "</div>";

    return $output;
  }

  /**
   * Format external URLs list
   * 
   * @param array $urls External URLs
   * @return string Formatted HTML
   */
  private function formatExternalUrls(array $urls): string
  {
    $output = "<div class='external-urls' style='margin-top: 15px;'>";
    $output .= "<h6>🔗 " . $this->language->getDef('text_rag_web_search_external_links') . "</h6>";
    $output .= "<ul>";

    foreach ($urls as $url) {
      if (is_string($url)) {
        $output .= "<li><a href='" . htmlspecialchars($url) . "' target='_blank' rel='noopener noreferrer'>";
        $output .= htmlspecialchars($url) . " 🔗</a></li>";
      }
    }

    $output .= "</ul>";
    $output .= "</div>";

    return $output;
  }

  /**
   * Format comparison table
   * 
   * @param array $comparisonTable Comparison table data
   * @return string Formatted HTML
   */
  private function formatComparisonTable(array $comparisonTable): string
  {
    if (empty($comparisonTable)) {
      return '';
    }

    $output = "<div class='comparison-table' style='margin-top: 15px;'>";
    $output .= "<h5>📊 " . $this->language->getDef('text_rag_web_search_comparison_table') . "</h5>";
    
    // Use inherited method from AbstractFormatter
    $output .= $this->generateTable($comparisonTable, 'table table-bordered table-striped\n');
    
    $output .= "</div>";

    return $output;
  }

  /**
   * Format guardrails metrics
   * 
   * @param array $guardrails Guardrails data
   * @return string Formatted HTML
   */
  private function formatGuardrailsMetrics(array $guardrails): string
  {
    $output = "<div class='guardrails-metrics'>";
    // Add guardrails display logic here
    // This can be expanded based on the actual guardrails structure
    $output .= "</div>";
    return $output;
  }
}
