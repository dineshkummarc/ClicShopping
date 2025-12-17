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

class LanguageHelper
{
/**
   * Returns an array of common English stop words.
   *
   * @return array List of stop words
   */
  public static function stopWord() :array
  {
    $array = [ 'the', 'a', 'an', 'and', 'or', 'with', 'without', 'for', 'by', 'on', 'in', 'at', 'to', 'of', 'from', 'me', 'you', 'he', 'she', 'it', 'we', 'they', 'this', 'that', 'these', 'those', 'can', 'give', 'have', 'be', 'do', 'go' ];

    return $array;
  }
}