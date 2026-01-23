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

/**
 * FormatterRouter - Intelligent routing system to select the appropriate formatter
 * based on query complexity and result type
 */
#[AllowDynamicProperties]
class FormatterRouter
{
  private array $formatters = [];
  private bool $debug;

  public function __construct(bool $debug = false)
  {
    $this->debug = $debug;
  }

  /**
   * Register a formatter
   */
  public function registerFormatter(AbstractFormatter $formatter, int $priority = 50): void
  {
    $this->formatters[] = [
      'formatter' => $formatter,
      'priority' => $priority,
      'class' => get_class($formatter)
    ];

    // Sort by priority (higher priority first)
    usort($this->formatters, fn($a, $b) => $b['priority'] <=> $a['priority']);
  }

  /**
   * Route to the appropriate formatter based on results
   */
  public function route(array $results): ?AbstractFormatter
  {
    if (empty($results)) {
      return null;
    }

    // Analyze result complexity
    $complexity = $this->analyzeComplexity($results);

    if ($this->debug) {
      error_log('FormatterRouter: Analyzing results\n');
      error_log('  Type: ' . ($results['type'] ?? 'NONE') . "\n");
      error_log('  Complexity: ' . $complexity['level'] . "\n");
      error_log('  Score: ' . $complexity['score'] . "\n");
    }

    // Find the best formatter
    foreach ($this->formatters as $entry) {
      $formatter = $entry['formatter'];

      if ($formatter->canHandle($results)) {
        if ($this->debug) {
          error_log('  ✓ Selected formatter: ' . $entry['class'] . ' (priority: ' . $entry['priority'] . ')\n' . "\n");
        }
        return $formatter;
      }
    }

    if ($this->debug) {
      error_log('  ✗ No formatter found for type: ' . ($results['type'] ?? 'NONE') . "\n");
    }

    return null;
  }

  /**
   * Analyze the complexity of the results
   * Returns: ['level' => 'simple|medium|complex', 'score' => int, 'factors' => array]
   */
  public function analyzeComplexity(array $results): array
  {
    $score = 0;
    $factors = [];

    // Factor 1: Result type
    $type = $results['type'] ?? '';
    if (in_array($type, ['complex_query', 'hybrid'])) {
      $score += 30;
      $factors[] = 'complex_type';
    } elseif (in_array($type, ['analytics_results', 'analytics_response'])) {
      $score += 10;
      $factors[] = 'analytics_type';
    }

    // Factor 2: Multiple sub-results
    if (!empty($results['sub_results'])) {
      $subCount = count($results['sub_results']);
      $score += min($subCount * 10, 30);
      $factors[] = "sub_results_count:{$subCount}";
    }

    // Factor 3: Multiple data sources
    if (!empty($results['data']) && is_array($results['data'])) {
      if (isset($results['data'][0]['sub_query'])) {
        $score += 20;
        $factors[] = 'multiple_data_sources';
      }
    }

    // Factor 4: Has SQL query (indicates analytics)
    if (!empty($results['sql_query'])) {
      $score += 5;
      $factors[] = 'has_sql';
    }

    // Factor 5: Has web search results
    if (!empty($results['web_results']) || !empty($results['sources'])) {
      $score += 15;
      $factors[] = 'has_web_search';
    }

    // Factor 6: Large result set
    if (!empty($results['results']) && is_array($results['results'])) {
      $resultCount = count($results['results']);
      if ($resultCount > 10) {
        $score += 10;
        $factors[] = "large_result_set:{$resultCount}";
      }
    }

    // Determine complexity level
    $level = 'simple';
    if ($score >= 50) {
      $level = 'complex';
    } elseif ($score >= 20) {
      $level = 'medium';
    }

    return [
      'level' => $level,
      'score' => $score,
      'factors' => $factors
    ];
  }

  /**
   * Get all registered formatters
   */
  public function getFormatters(): array
  {
    return $this->formatters;
  }

  /**
   * Get complexity analysis for debugging
   */
  public function getComplexityReport(array $results): string
  {
    $complexity = $this->analyzeComplexity($results);

    $report = "Complexity Analysis:\n";
    $report .= "  Level: {$complexity['level']}\n";
    $report .= "  Score: {$complexity['score']}\n";
    $report .= "  Factors:\n";

    foreach ($complexity['factors'] as $factor) {
      $report .= "    - {$factor}\n";
    }

    return $report;
  }
}
