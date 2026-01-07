<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\AI\Helper\Formatter;

/**
 * WebSearchResultFormatter
 *
 * Formats web search results as beautiful Bootstrap HTML.
 * Provides consistent, accessible, and visually appealing display
 * for web search results across the application.
 *
 * Features:
 * - Bootstrap card-based layout
 * - Favicon display from source domains
 * - Clickable titles and sources (open in new tab)
 * - Position badges
 * - Price display (if available)
 * - Responsive design
 * - Accessibility compliant
 * - XSS protection
 *
 * @package ClicShopping\AI\Helper\Formatter
 * @since 2025-12-28
 */
class WebSearchResultFormatter
{
  /**
   * Format web search results as beautiful Bootstrap HTML
   *
   * @param string $query Search query
   * @param array $results Array of search result items
   * @param int $maxResults Maximum number of results to display (default: 10)
   * @return string Formatted HTML
   */
  public static function formatAsHtml(string $query, array $results, int $maxResults = 10): string
  {
    if (empty($results)) {
      return self::formatEmptyResults($query);
    }

    // 🎨 Add container with max-width and proper spacing
    $html = '<div class="web-search-results" style="max-width: 100%; overflow-x: hidden; padding: 0.5rem;">';
    $html .= self::formatHeader($query, count($results));
    
    foreach (array_slice($results, 0, $maxResults) as $index => $result) {
      $html .= self::formatResultCard($result, $index + 1);
    }
    
    $html .= '</div>';

    return $html;
  }

  /**
   * Format header with search query and result count
   *
   * @param string $query Search query
   * @param int $count Number of results
   * @return string HTML header
   */
  private static function formatHeader(string $query, int $count): string
  {
    $html = '<div class="alert alert-info mb-3" role="alert">';
    $html .= '<i class="bi bi-globe me-2"></i>';
    $html .= '<strong>Résultats de recherche web</strong> pour : <em>' . htmlspecialchars($query) . '</em>';
    $html .= '<span class="badge bg-primary ms-2">' . $count . ' résultat' . ($count > 1 ? 's' : '') . '</span>';
    $html .= '</div>';
    
    return $html;
  }

  /**
   * Format a single result as a Bootstrap card
   *
   * @param array $result Result item
   * @param int $position Position number (1-based)
   * @return string HTML card
   */
  private static function formatResultCard(array $result, int $position): string
  {
    $title = htmlspecialchars($result['title'] ?? 'Sans titre');
    $snippet = htmlspecialchars($result['snippet'] ?? '');
    $link = $result['url'] ?? $result['link'] ?? '';
    $source = $result['source'] ?? self::extractDomain($link);
    $price = $result['price'] ?? null;
    
    // 🎨 Add word-wrap and max-width to prevent overflow
    $html = '<div class="card mb-3 shadow-sm hover-shadow" style="word-wrap: break-word; overflow-wrap: break-word;">';
    $html .= '<div class="card-body" style="padding: 1rem;">';
    
    // Header with position, favicon, and title
    $html .= self::formatCardHeader($position, $source, $title, $link);
    
    // Source domain
    if (!empty($source)) {
      $html .= self::formatSource($source, $link);
    }
    
    // Snippet
    if (!empty($snippet)) {
      $html .= '<p class="card-text" style="word-wrap: break-word; overflow-wrap: break-word;">' . $snippet . '</p>';
    }
    
    // Price if available
    if (!empty($price)) {
      $html .= self::formatPrice($price);
    }
    
    $html .= '</div>'; // card-body
    $html .= '</div>'; // card
    
    return $html;
  }

  /**
   * Format card header with position badge, favicon, and title
   *
   * @param int $position Position number
   * @param string $source Source domain
   * @param string $title Result title
   * @param string $link Result URL
   * @return string HTML header
   */
  private static function formatCardHeader(int $position, string $source, string $title, string $link): string
  {
    $html = '<div class="d-flex align-items-start mb-2" style="flex-wrap: wrap;">';
    
    // Position badge
    $html .= '<span class="badge bg-secondary me-2" style="flex-shrink: 0;">' . $position . '</span>';
    
    // Favicon
    if (!empty($source)) {
      $faviconUrl = 'https://www.google.com/s2/favicons?domain=' . urlencode($source) . '&sz=32';
      $html .= '<img src="' . $faviconUrl . '" alt="' . htmlspecialchars($source) . '" class="me-2" style="width: 20px; height: 20px; flex-shrink: 0;" onerror="this.style.display=\'none\'">';
    }
    
    // Title as clickable link with word-wrap
    $html .= '<h5 class="card-title mb-1 flex-grow-1" style="word-wrap: break-word; overflow-wrap: break-word; min-width: 0;">';
    if (!empty($link)) {
      $html .= '<a href="' . htmlspecialchars($link) . '" target="_blank" rel="noopener noreferrer" class="text-decoration-none text-primary" style="word-wrap: break-word; overflow-wrap: break-word;">';
      $html .= $title;
      $html .= ' <i class="bi bi-box-arrow-up-right text-muted" style="font-size: 0.75rem;"></i>';
      $html .= '</a>';
    } else {
      $html .= $title;
    }
    $html .= '</h5>';
    
    $html .= '</div>';
    
    return $html;
  }

  /**
   * Format source domain as clickable link
   *
   * @param string $source Source domain
   * @param string $link Full URL
   * @return string HTML source
   */
  private static function formatSource(string $source, string $link): string
  {
    $html = '<p class="text-muted small mb-2">';
    $html .= '<i class="bi bi-link-45deg me-1"></i>';
    
    if (!empty($link)) {
      $html .= '<a href="' . htmlspecialchars($link) . '" target="_blank" rel="noopener noreferrer" class="text-muted text-decoration-none">';
      $html .= $source;
      $html .= '</a>';
    } else {
      $html .= $source;
    }
    
    $html .= '</p>';
    
    return $html;
  }

  /**
   * Format price as success alert
   *
   * @param string $price Price string
   * @return string HTML price alert
   */
  private static function formatPrice(string $price): string
  {
    $html = '<div class="alert alert-success py-2 mb-0 mt-2">';
    $html .= '<i class="bi bi-tag me-2"></i>';
    $html .= '<strong>Prix :</strong> ' . htmlspecialchars($price);
    $html .= '</div>';
    
    return $html;
  }

  /**
   * Format empty results message
   *
   * @param string $query Search query
   * @return string HTML empty message
   */
  private static function formatEmptyResults(string $query): string
  {
    $html = '<div class="alert alert-warning" role="alert">';
    $html .= '<i class="bi bi-exclamation-triangle me-2"></i>';
    $html .= '<strong>Aucun résultat trouvé</strong> pour : <em>' . htmlspecialchars($query) . '</em>';
    $html .= '</div>';
    
    return $html;
  }

  /**
   * Extract domain from URL
   *
   * @param string $url URL to extract domain from
   * @return string Domain name
   */
  private static function extractDomain(string $url): string
  {
    if (empty($url)) {
      return 'unknown';
    }

    $parsed = parse_url($url);
    $host = $parsed['host'] ?? 'unknown';
    
    // Remove 'www.' prefix
    $host = preg_replace('/^www\./', '', $host);

    return $host;
  }

  /**
   * Format web search results as plain text (fallback)
   *
   * @param string $query Search query
   * @param array $results Array of search result items
   * @param int $maxResults Maximum number of results to display (default: 10)
   * @return string Formatted plain text
   */
  public static function formatAsText(string $query, array $results, int $maxResults = 10): string
  {
    if (empty($results)) {
      return "Aucun résultat trouvé pour : {$query}";
    }

    $text = "Résultats de recherche web pour : {$query}\n\n";
    $text .= "Trouvé " . count($results) . " résultat" . (count($results) > 1 ? 's' : '') . " :\n\n";

    foreach (array_slice($results, 0, $maxResults) as $index => $result) {
      $position = $index + 1;
      $title = $result['title'] ?? 'Sans titre';
      $snippet = $result['snippet'] ?? '';
      $link = $result['url'] ?? $result['link'] ?? '';
      $source = $result['source'] ?? self::extractDomain($link);
      
      $text .= "{$position}. {$title}\n";
      
      if (!empty($snippet)) {
        $text .= "   {$snippet}\n";
      }
      
      if (!empty($source)) {
        $text .= "   Source: {$source}\n";
      }
      
      if (!empty($link)) {
        $text .= "   URL: {$link}\n";
      }
      
      if (!empty($result['price'])) {
        $text .= "   Prix: {$result['price']}\n";
      }
      
      $text .= "\n";
    }

    return $text;
  }
}
