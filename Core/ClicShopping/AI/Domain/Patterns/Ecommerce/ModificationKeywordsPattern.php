<?php
/**
 * ModificationKeywordsPattern - Centralized modification keywords
 * 
 * Provides keywords for detecting query modifications (add, remove, change, etc.)
 * Used by AnalyticsAgent and other components to detect modification requests.
 * 
 * TASK 5.1.1: Extracted from AnalyticsAgent::getModificationKeywords()
 * RESTRUCTURATION: Relocated to Ecommerce domain (2026-01-09)
 * TASK: patterns-restructuration - Task 7.2
 * 
 * @package ClicShopping\AI\Domain\Patterns\Ecommerce
 * @since 2025-12-28
 */

namespace ClicShopping\AI\Domain\Patterns\Ecommerce;

use AllowDynamicProperties;

#[AllowDynamicProperties]
class ModificationKeywordsPattern
{
  /**
   * Get modification keywords for detecting query modifications
   * 
   * Keywords are in ENGLISH because queries are translated before processing.
   * Used to detect when a user wants to modify a previous query.
   * 
   * ENGLISH ONLY: All keywords are in English as per system design.
   * Queries must be translated to English before using these keywords.
   * 
   * @return array<string> List of modification keywords
   */
  public static function getModificationKeywords(): array
  {
    return [
      // Addition keywords
      'add', 'adds', 'adding', 'include', 'includes', 'including',
      'with', 'also', 'and also', 'as well',
      
      // Modification keywords
      'modify', 'modifies', 'modifying', 'change', 'changes', 'changing',
      'update', 'updates', 'updating', 'alter', 'alters', 'altering',
      
      // Removal keywords
      'remove', 'removes', 'removing', 'delete', 'deletes', 'deleting',
      'drop', 'drops', 'dropping', 'exclude', 'excludes', 'excluding',
      
      // Replacement keywords
      'replace', 'replaces', 'replacing', 'substitute', 'substitutes'
    ];
  }

  /**
   * Get all modification keywords (alias for getModificationKeywords)
   * 
   * TASK 7.1.2.2: Added as standardized method name for pattern classes
   * 
   * @return array<string> List of all modification keywords
   */
  public static function getAllKeywords(): array
  {
    return self::getModificationKeywords();
  }

  /**
   * Check if query contains modification keywords
   * 
   * @param string $query Query to check (MUST BE IN ENGLISH)
   * @return bool True if modification keyword found
   */
  public static function isModificationRequest(string $query): bool
  {
    $keywords = self::getModificationKeywords();
    $queryLower = mb_strtolower($query);

    foreach ($keywords as $keyword) {
      if (strpos($queryLower, $keyword) !== false) {
        return true;
      }
    }

    return false;
  }

  /**
   * Get the first modification keyword found in query
   * 
   * @param string $query Query to check (MUST BE IN ENGLISH)
   * @return string|null First keyword found, or null if none
   */
  public static function getModificationKeyword(string $query): ?string
  {
    $keywords = self::getModificationKeywords();
    $queryLower = mb_strtolower($query);

    foreach ($keywords as $keyword) {
      if (strpos($queryLower, $keyword) !== false) {
        return $keyword;
      }
    }

    return null;
  }
}
