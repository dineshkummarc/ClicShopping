<?php
/**
 * EntityDetectionPattern
 *
 * Pattern class for detecting e-commerce entities in queries.
 * Extracted from QueryAnalyzer to follow pattern separation principle.
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 * REFACTORING: Extracted from QueryAnalyzer (2026-01-05)
 * TASK: Session 15 - Pattern extraction cleanup
 * RESTRUCTURATION: Relocated to Ecommerce domain (2026-01-09)
 * TASK: patterns-restructuration - Task 7.1
 */

namespace ClicShopping\AI\Domain\Patterns\Ecommerce;

use AllowDynamicProperties;
use ClicShopping\AI\Domain\Patterns\Common\EntityKeywordsPattern;

#[AllowDynamicProperties]
class EntityDetectionPattern
{
  /**
   * Get entity detection patterns
   *
   * Returns patterns for detecting e-commerce entities (products, categories, etc.)
   * in user queries.
   *
   * Uses centralized EntityKeywordsPattern from Common domain to avoid duplication.
   *
   * @return array Entity patterns mapped by entity type
   */
  public static function getPatterns(): array
  {
    return EntityKeywordsPattern::getPatterns();
  }

  /**
   * Detect entities in message
   *
   * Identifies e-commerce entities using pattern matching.
   *
   * @param string $message Message text
   * @return array Detected entity types (unique list)
   */
  public static function detect(string $message): array
  {
    $message = mb_strtolower($message);
    $entities = [];
    $patterns = self::getPatterns();

    foreach ($patterns as $entityType => $keywords) {
      foreach ($keywords as $keyword) {
        // Escape keyword for regex and match as whole word
        $keywordEscaped = preg_quote($keyword, '/');
        if (preg_match('/\b' . $keywordEscaped . '\b/i', $message)) {
          $entities[] = $entityType;
          break; // Stop after first match for this entity type
        }
      }
    }

    return array_unique($entities);
  }

  /**
   * Get metadata about this pattern
   *
   * @return array Pattern metadata
   */
  public static function getMetadata(): array
  {
    return [
      'name' => 'Entity Detection Pattern',
      'description' => 'Detects e-commerce entities (products, categories, customers, etc.) in queries',
      'entity_types' => array_keys(self::getPatterns()),
      'total_keywords' => array_sum(array_map('count', self::getPatterns())),
      'usage' => 'QueryAnalyzer::extractEntitiesFromMessage()',
      'domain' => 'Ecommerce',
      'uses_common' => 'EntityKeywordsPattern',
    ];
  }
}
