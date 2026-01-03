<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\AI\Domain\Patterns;

/**
 * QuerySplitterPatterns - Regex patterns for query splitting
 *
 * NOTE: These patterns are for FUTURE USE only.
 * Current implementation uses Pure LLM mode (no pattern matching).
 * Patterns are preserved here for potential future hybrid approaches.
 *
 * @package ClicShopping\AI\Domain\Patterns
 * @since 2025-12-30
 * @moved 2025-12-31 from SubHybridQueryProcessor\Patterns to Domain\Patterns
 */
class QuerySplitterPatterns
{
  /**
   * Pattern for detecting report/analysis queries
   * 
   * Matches queries like:
   * - "create a report for iPhone"
   * - "generate analysis of sales"
   * - "make a detailed summary about products"
   *
   * @var string
   */
  public const REPORT_QUERY_PATTERN = '/\b(create|generate|make|build)\s+(?:(?:a|an)\s+)?(?:(?:analysis|detailed|comprehensive)\s+)?(report|analysis|summary)\s+(?:for|of|on|about)\s+(.+)/i';

  /**
   * Delimiter patterns for splitting queries
   *
   * @var array<string, string> Array of delimiter types and their patterns
   */
  public const DELIMITER_PATTERNS = [
    'comma' => ',',
    'and_then' => '/\s+and\s+then\s+/i',
    'period' => '/\.\s+/',  // Period followed by space (sentence boundary)
    'and' => '/\band\b/i',
    'question' => '?',
    'semicolon' => ';'
  ];

  /**
   * Pattern for detecting sequential dependencies
   * 
   * Matches phrases like:
   * - "and then"
   * - "after that"
   * - "next"
   *
   * @var string
   */
  public const SEQUENTIAL_DEPENDENCY_PATTERN = '/\b(and\s+then|after\s+that|next|then)\b/i';

  /**
   * Pattern for simple split on connectors (fallback)
   *
   * @var string
   */
  public const SIMPLE_SPLIT_PATTERN = '/\b(and|then|also)\b/i';

  /**
   * Get all patterns as an array
   *
   * @return array<string, mixed> All patterns
   */
  public static function getAllPatterns(): array
  {
    return [
      'report_query' => self::REPORT_QUERY_PATTERN,
      'delimiters' => self::DELIMITER_PATTERNS,
      'sequential_dependency' => self::SEQUENTIAL_DEPENDENCY_PATTERN,
      'simple_split' => self::SIMPLE_SPLIT_PATTERN,
    ];
  }

  /**
   * Check if a pattern matches a query
   *
   * @param string $pattern Pattern to test
   * @param string $query Query to test against
   * @return bool True if pattern matches
   */
  public static function matches(string $pattern, string $query): bool
  {
    return preg_match($pattern, $query) === 1;
  }

  /**
   * Extract matches from a query using a pattern
   *
   * @param string $pattern Pattern to use
   * @param string $query Query to extract from
   * @return array|null Matches array or null if no match
   */
  public static function extract(string $pattern, string $query): ?array
  {
    if (preg_match($pattern, $query, $matches)) {
      return $matches;
    }
    return null;
  }
}
