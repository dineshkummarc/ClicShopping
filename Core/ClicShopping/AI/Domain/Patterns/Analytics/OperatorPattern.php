<?php
/**
 * OperatorPattern
 *
 * Pattern class for mapping natural language operators to SQL operators.
 * Extracted from QueryAnalyzer to follow pattern separation principle.
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 * REFACTORING: Extracted from QueryAnalyzer (2026-01-05)
 * TASK: Session 15 - Pattern extraction cleanup
 */

namespace ClicShopping\AI\Domain\Patterns\Analytics;

class OperatorPattern
{
  /**
   * Get operator mapping
   *
   * Maps natural language operators to standard SQL operators.
   * Supports synonyms like "greater than", "more than", "over", etc.
   *
   * @return array Operator mapping (synonym => standard operator)
   */
  public static function getOperatorMap(): array
  {
    return [
      // Greater than synonyms
      'greater than' => '>',
      'more than' => '>',
      'over' => '>',
      'above' => '>',
      '>' => '>',

      // Less than synonyms
      'less than' => '<',
      'lower than' => '<',
      'under' => '<',
      'below' => '<',
      '<' => '<',

      // Equal to synonyms
      'equal to' => '=',
      'is' => '=',
      '=' => '='
    ];
  }

  /**
   * Get operator regex pattern
   *
   * Returns a regex pattern that matches all operator synonyms.
   * Used for extracting operators from queries.
   *
   * @return string Regex pattern for operator matching
   */
  public static function getOperatorRegexPattern(): string
  {
    $operators = array_keys(self::getOperatorMap());
    $escapedOperators = array_map(fn($k) => preg_quote($k, '/'), $operators);
    return implode('|', $escapedOperators);
  }

  /**
   * Translate operator synonym to standard operator
   *
   * Converts natural language operator to SQL operator (>, <, =).
   *
   * @param string $operator Natural language operator
   * @return string Standard SQL operator (>, <, =)
   */
  public static function translate(string $operator): string
  {
    $map = self::getOperatorMap();
    return $map[$operator] ?? '='; // Default to '=' if not found
  }

  /**
   * Get reverse operator map (for text formatting)
   *
   * Maps SQL operators to human-readable text.
   * Used for formatting filters in prompts.
   *
   * @return array Reverse mapping (operator => text)
   */
  public static function getReverseMap(): array
  {
    return [
      '>' => 'greater than',
      '<' => 'less than',
      '=' => 'equal to'
    ];
  }

  /**
   * Get metadata about this pattern
   *
   * @return array Pattern metadata
   */
  public static function getMetadata(): array
  {
    return [
      'name' => 'Operator Pattern',
      'description' => 'Maps natural language operators to SQL operators (>, <, =)',
      'operator_count' => count(self::getOperatorMap()),
      'supported_operators' => ['>', '<', '='],
      'synonym_count' => [
        '>' => 5,
        '<' => 5,
        '=' => 3
      ],
      'usage' => 'QueryAnalyzer::extractQueryCriteria(), QueryAnalyzer::formatStructuredFiltersToText()',
      'examples' => [
        'price greater than 100',
        'stock less than 10',
        'rating equal to 5'
      ]
    ];
  }
}
