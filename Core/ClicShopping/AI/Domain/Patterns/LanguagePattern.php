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
 * LanguagePattern
 *
 * Centralized patterns for language detection and translation cleaning.
 * 
 * Provides patterns for:
 * - Detecting French vs English queries
 * - Cleaning GPT translation output
 * - Language-specific character detection
 * 
 * REFACTORING 2025-12-14: Extracted from TranslationService for centralization
 * 
 * @package ClicShopping\AI\Domain\Patterns
 * @since 2025-12-14
 */
class LanguagePattern
{
  /**
   * Get French word patterns for language detection
   * 
   * Returns regex patterns for common French words that indicate
   * a query is in French.
   * 
   * These patterns are used to detect if a query is in French
   * before translation to English.
   * 
   * REFACTORING 2025-12-14: Extracted from TranslationService::isEnglish()
   * 
   * @return array<string> Array of regex patterns
   */
  public static function getFrenchWordPatterns(): array
  {
    return [
      // Articles
      '\ble\b', '\bla\b', '\bles\b', '\bdes\b', '\bdu\b', '\bde\b', '\bun\b', '\bune\b',
      
      // Prepositions
      '\bpour\b', '\bdans\b', '\bsur\b', '\bavec\b', '\bpar\b', '\bsans\b',
      
      // Pronouns
      '\bje\b', '\btu\b', '\bil\b', '\belle\b', '\bnous\b', '\bvous\b', '\bils\b', '\belles\b',
      
      // Common verbs
      '\best\b', '\bsont\b', '\bai\b', '\bas\b', '\bavez\b', '\bont\b',
      
      // Question words
      '\bquel\b', '\bquelle\b', '\bquels\b', '\bquelles\b', '\bcomment\b', '\bpourquoi\b',
      
      // Other common words
      '\bou\b', '\bet\b', '\bmais\b', '\bdonc\b', '\bcar\b', '\bni\b', '\bor\b',
      '\bplus\b', '\bmoins\b', '\btrès\b', '\btrop\b', '\bassez\b',
    ];
  }
  
  /**
   * Get English word patterns for language detection
   * 
   * Returns regex patterns for common English words that indicate
   * a query is in English.
   * 
   * These patterns are used to detect if a query is already in English
   * to skip unnecessary translation.
   * 
   * REFACTORING 2025-12-14: Extracted from TranslationService::isEnglish()
   * 
   * @return array<string> Array of regex patterns
   */
  public static function getEnglishWordPatterns(): array
  {
    return [
      // Articles
      '\bthe\b', '\ba\b', '\ban\b',
      
      // Prepositions
      '\bof\b', '\bin\b', '\bto\b', '\bfor\b', '\bwith\b', '\bon\b', '\bat\b', '\bfrom\b', '\bby\b',
      
      // Pronouns
      '\bi\b', '\byou\b', '\bhe\b', '\bshe\b', '\bit\b', '\bwe\b', '\bthey\b',
      
      // Common verbs
      '\bis\b', '\bare\b', '\bwas\b', '\bwere\b', '\bhas\b', '\bhave\b', '\bhad\b',
      
      // Question words
      '\bwhat\b', '\bwhere\b', '\bwhen\b', '\bwhy\b', '\bhow\b', '\bwhich\b', '\bwho\b',
      
      // Other common words
      '\band\b', '\bor\b', '\bbut\b', '\bnot\b', '\bcan\b', '\bwill\b', '\bwould\b',
      '\bthis\b', '\bthat\b', '\bthese\b', '\bthose\b', '\ball\b', '\bsome\b', '\bany\b',
    ];
  }
  
  /**
   * Get French character pattern for language detection
   * 
   * Returns regex pattern for French-specific characters (accents).
   * 
   * This is a strong indicator that a query is in French.
   * 
   * REFACTORING 2025-12-14: Extracted from TranslationService::isEnglish()
   * 
   * @return string Regex pattern
   */
  public static function getFrenchCharacterPattern(): string
  {
    return '/[éèêëàâäùûüôöîïç]/u';
  }
  
  /**
   * Get translation prefix patterns for cleaning GPT output
   * 
   * Returns regex patterns for common GPT translation prefixes
   * that should be removed from translated text.
   * 
   * GPT often adds prefixes like "Translation: ..." or "English: ..."
   * which should be stripped to get the clean translation.
   * 
   * REFACTORING 2025-12-14: Extracted from TranslationService::extractCleanTranslation()
   * 
   * @return array<string, string> Array of pattern types and regex patterns
   */
  public static function getTranslationPrefixPatterns(): array
  {
    return [
      'quoted_after_is' => '/is:\s*"([^"]+)"|is:\s*\'([^\']+)\'/',
      'full_quoted_prefix' => '/^(Translation:|Translated:|English:|Result:|Answer:|Response:)\s*"([^"]+)"$/i',
      'simple_prefix_1' => '/^(Translation:|Translated:|English:|Result:|Answer:|Response:)\s*/i',
      'simple_prefix_2' => '/^(The translation is|This translates to|In English):\s*/i',
      'simple_prefix_3' => '/^(Here is the translation|The English version is):\s*/i',
    ];
  }
  
  /**
   * Quick language detection for French/English only (PHASE 14 - HYBRID MODE)
   * 
   * This is a fast path for the most common languages (FR/EN).
   * Returns 'en', 'fr', or null if uncertain.
   * 
   * When null is returned, the caller should use GPT for language detection
   * to support 50+ languages.
   * 
   * This method is optimized for speed and accuracy for FR/EN queries,
   * which represent 90%+ of all queries in the system.
   * 
   * Detection Strategy:
   * 1. Check for French-specific characters (é, è, à, ç, etc.) → FR
   * 2. Check for strong French word indicators → FR
   * 3. Check for strong English word indicators → EN
   * 4. If uncertain, return null → Use GPT
   * 
   * @param string $query Query to analyze
   * @return string|null 'en', 'fr', or null if uncertain
   * 
   * @since 2025-12-14 Phase 14 - Hybrid Mode
   */
  public static function detectLanguageQuick(string $query): ?string
  {
    $query = strtolower(trim($query));
    
    // Empty query → default to English
    if (empty($query)) {
      return 'en';
    }
    
    // 1. Check for French-specific characters (strong indicator)
    if (preg_match(self::getFrenchCharacterPattern(), $query)) {
      return 'fr';
    }
    
    // 2. Check for strong French word indicators
    $strongFrenchWords = [
      'combien', 'quels', 'quelle', 'quelles', 'pourquoi', 'comment', 'où',
      'est-ce', 'qu\'est-ce', 'quel est', 'quelle est',
    ];
    
    foreach ($strongFrenchWords as $word) {
      if (stripos($query, $word) !== false) {
        return 'fr';
      }
    }
    
    // 3. Check for strong English phrase indicators
    $strongEnglishPhrases = [
      'how many', 'how much', 'what are', 'what is', 'show me', 'give me',
      'tell me', 'can you', 'could you', 'would you', 'do you',
    ];
    
    foreach ($strongEnglishPhrases as $phrase) {
      if (stripos($query, $phrase) !== false) {
        return 'en';
      }
    }
    
    // 4. Count French vs English word matches
    $frenchPatterns = self::getFrenchWordPatterns();
    $englishPatterns = self::getEnglishWordPatterns();
    
    $frenchMatches = 0;
    $englishMatches = 0;
    
    foreach ($frenchPatterns as $pattern) {
      if (preg_match('/' . $pattern . '/i', $query)) {
        $frenchMatches++;
      }
    }
    
    foreach ($englishPatterns as $pattern) {
      if (preg_match('/' . $pattern . '/i', $query)) {
        $englishMatches++;
      }
    }
    
    // If we have clear winner (2+ matches difference), return it
    if ($frenchMatches >= 2 && $frenchMatches > $englishMatches + 1) {
      return 'fr';
    }
    
    if ($englishMatches >= 2 && $englishMatches > $frenchMatches + 1) {
      return 'en';
    }
    
    // 5. If uncertain, return null (caller should use GPT)
    return null;
  }
}
