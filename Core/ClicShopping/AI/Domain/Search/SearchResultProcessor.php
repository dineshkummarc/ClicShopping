<?php
/**
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all rights reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 */

namespace ClicShopping\AI\Domain\Search;

use AllowDynamicProperties;
use ClicShopping\OM\Registry;

/**
 * Class SearchResultProcessor
 *
 * Processes and formats raw SerpApi search results for internal system use.
 * Responsibilities:
 * - HTML and special character cleaning
 * - Extraction of featured snippets and related questions
 * - Calculation of advanced relevance and quality scores
 * - Consistent normalization and structuring for downstream processing
 */
#[AllowDynamicProperties]
class SearchResultProcessor
{
  private mixed $db;
  private mixed $cache;

  public function __construct() {
    $this->db = Registry::get('Db');
  }

  /**
   * Processes raw SerpApi results and returns normalized structured data.
   *
   * @param array $rawResults Raw SerpApi results
   * @param string $query Original search query
   * @return array Structured and normalized search results
   */
  public function process(array $rawResults, string $query): array
  {
    $processed = [
      'success' => true,
      'query' => $query,
      'total_results' => $this->extractTotalResults($rawResults),
      'items' => [],
      'featured_snippet' => null,
      'metadata' => [
        'search_engine' => $rawResults['search_metadata']['engine'] ?? 'unknown',
        'search_time' => $rawResults['search_metadata']['total_time_taken'] ?? 0,
        'timestamp' => time(),
      ],
    ];

    $organicResults = $rawResults['organic_results'] ?? [];

    foreach ($organicResults as $result) {
      $processed['items'][] = $this->formatItem($result, $query);
    }

    $featuredSnippet = $this->extractFeaturedSnippet($rawResults);
    if ($featuredSnippet) {
      $processed['featured_snippet'] = $featuredSnippet;
    }

    $relatedQuestions = $this->extractRelatedQuestions($rawResults);
    if (!empty($relatedQuestions)) {
      $processed['related_questions'] = $relatedQuestions;
    }

    return $processed;
  }

  /**
   * Formats a single organic result into a standardized structure.
   *
   * @param array $result Raw search result item
   * @param string $query Original search query
   * @return array Normalized result item
   */
  private function formatItem(array $result, string $query): array
  {
    return [
      'position' => $result['position'] ?? 0,
      'title' => $this->cleanText($result['title'] ?? ''),
      'link' => $result['link'] ?? '',
      'snippet' => $this->cleanText($result['snippet'] ?? ''),
      'source' => $this->extractDomain($result['link'] ?? ''),
      'displayed_link' => $result['displayed_link'] ?? '',
      'date' => $result['date'] ?? null,
      'relevance_score' => $this->calculateRelevance($result, $query),
    ];
  }

  /**
   * Extracts the total number of results from SerpApi metadata.
   *
   * @param array $rawResults Raw SerpApi response
   * @return int Total number of results
   */
  private function extractTotalResults(array $rawResults): int
  {
    if (isset($rawResults['search_information']['total_results'])) {
      $total = $rawResults['search_information']['total_results'];
      if (is_string($total)) {
        $total = (int)preg_replace('/[^0-9]/', '', $total);
      }
      return (int)$total;
    }

    return 0;
  }

  /**
   * Extracts the featured snippet or answer box if present.
   *
   * @param array $rawResults Raw SerpApi response
   * @return array|null Structured featured snippet or null
   */
  private function extractFeaturedSnippet(array $rawResults): ?array
  {
    if (isset($rawResults['answer_box'])) {
      $answerBox = $rawResults['answer_box'];

      return [
        'type' => $answerBox['type'] ?? 'answer_box',
        'title' => $this->cleanText($answerBox['title'] ?? ''),
        'answer' => $this->cleanText($answerBox['answer'] ?? $answerBox['snippet'] ?? ''),
        'source' => $answerBox['link'] ?? '',
        'source_domain' => $this->extractDomain($answerBox['link'] ?? ''),
      ];
    }

    if (isset($rawResults['knowledge_graph'])) {
      $kg = $rawResults['knowledge_graph'];

      return [
        'type' => 'knowledge_graph',
        'title' => $this->cleanText($kg['title'] ?? ''),
        'answer' => $this->cleanText($kg['description'] ?? ''),
        'source' => $kg['source']['link'] ?? '',
        'source_domain' => $this->extractDomain($kg['source']['link'] ?? ''),
      ];
    }

    return null;
  }

  /**
   * Extracts “People Also Ask” related questions.
   *
   * @param array $rawResults Raw SerpApi response
   * @return array Related questions list
   */
  private function extractRelatedQuestions(array $rawResults): array
  {
    $questions = [];

    if (isset($rawResults['related_questions'])) {
      foreach ($rawResults['related_questions'] as $rq) {
        $questions[] = [
          'question' => $this->cleanText($rq['question'] ?? ''),
          'answer' => $this->cleanText($rq['snippet'] ?? ''),
          'source' => $rq['link'] ?? '',
        ];
      }
    }

    return $questions;
  }

  /**
   * Cleans text by removing HTML tags, special characters, and invisible symbols.
   *
   * @param string $text Input text
   * @return string Cleaned text
   */
  private function cleanText(string $text): string
  {
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[\x00-\x1F\x7F]/u', '', $text);
    $text = preg_replace('/\s+/', ' ', $text);
    $text = trim($text);

    if (strlen($text) > 5000) {
      $text = substr($text, 0, 5000) . '...';
    }

    return $text;
  }

  /**
   * Extracts the domain name from a given URL.
   *
   * @param string $url Full URL
   * @return string Domain name
   */
  private function extractDomain(string $url): string
  {
    if (empty($url)) {
      return 'unknown';
    }

    $parsed = parse_url($url);
    $host = $parsed['host'] ?? 'unknown';
    $host = preg_replace('/^www\./', '', $host);

    return $host;
  }

  /**
   * Calculates an advanced relevance score.
   *
   * Factors:
   * - Exact and partial term matches
   * - Word order consistency
   * - Search result position
   * - Content freshness
   * - Domain authority
   * - Spam keyword penalty
   *
   * @param array $result Raw search result
   * @param string $query Original query
   * @return float Score between 0.0 and 1.0
   */
  private function calculateRelevance(array $result, string $query): float
  {
    $score = 0.3;

    $queryTerms = $this->extractQueryTerms($query);
    $title = mb_strtolower($result['title'] ?? '', 'UTF-8');
    $snippet = mb_strtolower($result['snippet'] ?? '', 'UTF-8');

    $score += $this->calculateTermMatches($queryTerms, $title, 0.15);
    $score += $this->calculateTermMatches($queryTerms, $snippet, 0.08);

    if (stripos($title, $query) !== false) {
      $score += 0.2;
    } elseif (stripos($snippet, $query) !== false) {
      $score += 0.1;
    }

    $orderScore = $this->calculateTermOrder($queryTerms, $title . ' ' . $snippet);
    $score += $orderScore * 0.1;

    $position = $result['position'] ?? 10;
    $score += max(0, (10 - $position) * 0.03);

    $score += $this->calculateDomainAuthority($result['link'] ?? '');

    if (!empty($result['date'])) {
      $score += $this->calculateFreshnessBonus($result['date']);
    }

    if (strlen($snippet) < 50) {
      $score -= 0.1;
    }

    $score -= $this->detectSpamKeywords($title . ' ' . $snippet);

    return max(0.0, min($score, 1.0));
  }

  /**
   * Extracts significant query terms excluding common stopwords.
   *
   * @param string $query Input query
   * @return array Filtered list of terms
   */
  private function extractQueryTerms(string $query): array
  {
    $stopwords = ['the','a','an','and','or','but','in','on','at','to','for'];

    // Convert to lowercase, split on non-alphanumeric characters
    $terms = preg_split('/\W+/u', mb_strtolower($query, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY);

    $terms = array_filter($terms, function($term) use ($stopwords) {
      return strlen($term) > 2 && !in_array($term, $stopwords);
    });

    return array_values($terms);
  }


  /**
   * Calculates term match score.
   *
   * @param array $terms Query terms
   * @param string $text Text to analyze
   * @param float $weight Weight per match
   * @return float Matching score
   */
  private function calculateTermMatches(array $terms, string $text, float $weight): float
  {
    $score = 0.0;
    $foundTerms = 0;

    foreach ($terms as $term) {
      if (stripos($text, $term) !== false) {
        $foundTerms++;
        $score += $weight;
      }
    }

    if ($foundTerms === count($terms) && count($terms) > 1) {
      $score += 0.1;
    }

    return $score;
  }

  /**
   * Determines if the word order in the text matches the query term order.
   *
   * @param array $terms Query terms
   * @param string $text Text to analyze
   * @return float Score between 0 and 1
   */
  private function calculateTermOrder(array $terms, string $text): float
  {
    if (count($terms) < 2) {
      return 0.0;
    }

    $positions = [];
    foreach ($terms as $term) {
      $pos = stripos($text, $term);
      if ($pos !== false) {
        $positions[] = $pos;
      }
    }

    if (count($positions) !== count($terms)) {
      return 0.0;
    }

    $positionsCount = count($positions);
    for ($i = 1; $i < $positionsCount; $i++) {
      if ($positions[$i] < $positions[$i - 1]) {
        return 0.3;
      }
    }

    return 1.0;
  }


  /**
   * Extract the domain information
   * @return array
   */
  private function loadCustomAuthoritySites(): array
  {
    /*  between 0 and 0.15
    $highAuthority = [ 'wikipedia.org' => 0.15,
    'github.com' => 0.12,
    'stackoverflow.com' => 0.12,
    'reddit.com' => 0.08,
    'medium.com' => 0.08,
    'forbes.com' => 0.10,
    'techcrunch.com' => 0.10,
    'shopify.com' => 0.12,
    'bigcommerce.com' => 0.12,
    'woocommerce.com' => 0.12,
    ];
    */
    if (!isset($this->cache['custom_authority'])) {
      $rows = $this->db->get('rag_websearch', ['site_domain', 'authority_score'], ['status' => 1]);
      $rows = $rows->fetchAll();

      $this->cache['custom_authority'] = array_column($rows, 'authority_score', 'site_domain');
    }
    return $this->cache['custom_authority'];
  }

  /**
   * Computes a domain authority bonus based on trusted sites.
   *
   * @param string $url Result URL
   * @return float Bonus between 0 and 0.15
   */
  private function calculateDomainAuthority(string $url): float
  {
    $customAuthority = $this->loadCustomAuthoritySites();
    $domain = $this->extractDomain($url);

    if (isset($customAuthority[$domain])) {
      return $customAuthority[$domain];
    }

    foreach ($customAuthority as $authDomain => $bonus) {
      if (strpos($domain, $authDomain) !== false) {
        return $bonus * 0.8;
      }
    }

    if (preg_match('/\.(edu|gov)$/', $domain)) {
      return 0.08;
    }

    return 0.0;
  }

  /**
   * Calculates a freshness bonus based on publication date.
   *
   * @param string $dateString Date string
   * @return float Bonus between 0 and 0.1
   */
  private function calculateFreshnessBonus(string $dateString): float
  {
    try {
      $date = new \DateTime($dateString);
      $now = new \DateTime();
      $diff = $now->diff($date);
      $daysDiff = (int)$diff->format('%a');

      if ($daysDiff <= 7) {
        return 0.1;
      } elseif ($daysDiff <= 30) {
        return 0.07;
      } elseif ($daysDiff <= 90) {
        return 0.04;
      } elseif ($daysDiff <= 365) {
        return 0.02;
      }

      return 0.0;

    } catch (\Exception $e) {
      return 0.0;
    }
  }

  /**
   * Detects aggressive marketing or spam keywords and applies penalties.
   *
   * @param string $text Text to analyze
   * @return float Penalty between 0 and 0.3
   */
  private function detectSpamKeywords(string $text): float
  {
    $spamKeywords = [
      'click here','buy now','limited offer','act now',
      'free trial','100% free','risk free','no obligation',
      'guarantee','amazing','incredible','revolutionary',
      'secret','miracle','breakthrough','exclusive'
    ];

    $pattern = '/' . implode('|', array_map('preg_quote', $spamKeywords)) . '/i';
    preg_match_all($pattern, $text, $matches);
    $spamCount = count($matches[0]);

    if ($spamCount >= 5) return 0.3;
    if ($spamCount >= 3) return 0.2;
    if ($spamCount >= 1) return 0.1;
    return 0.0;
  }


  /**
   * Computes an overall quality score for processed results.
   * Used by WebSearchTool to determine RAG storage eligibility.
   *
   * @param array $processedResults Processed search results
   * @return float Quality score between 0 and 1
   */
  public function calculateQualityScore(array $processedResults): float
  {
    $score = 0.5;

    if (!empty($processedResults['featured_snippet'])) {
      $score += 0.2;
    }

    $relevantCount = 0;
    foreach ($processedResults['items'] ?? [] as $item) {
      if (($item['relevance_score'] ?? 0) > 0.7) {
        $relevantCount++;
      }
    }
    $score += min($relevantCount * 0.1, 0.3);

    $domains = [];
    foreach ($processedResults['items'] ?? [] as $item) {
      $domains[] = $item['source'];
    }
    $uniqueDomains = count(array_unique($domains));
    if ($uniqueDomains >= 3) {
      $score += 0.1;
    }

    if (!empty($processedResults['related_questions'])) {
      $score += 0.1;
    }

    return min($score, 1.0);
  }
}
