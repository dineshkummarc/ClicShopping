<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\AI\Helper;

/**
 * LanguageHelper Class
 * 
 * Provides language-related utility functions for text processing.
 * 
 * @package ClicShopping\AI\Helper
 */
class LanguageHelper
{
  /**
   * Returns an array of common English stop words.
   * 
   * Stop words are common words that are typically filtered out
   * during text processing and keyword extraction because they
   * don't carry significant meaning.
   *
   * @return array List of stop words
   */
  public static function stopWord(): array
  {
    return [
      'the', 'a', 'an', 'and', 'or', 'with', 'without', 
      'for', 'by', 'on', 'in', 'at', 'to', 'of', 'from', 
      'me', 'you', 'he', 'she', 'it', 'we', 'they', 
      'this', 'that', 'these', 'those', 
      'can', 'give', 'have', 'be', 'do', 'go'
    ];
  }
}
