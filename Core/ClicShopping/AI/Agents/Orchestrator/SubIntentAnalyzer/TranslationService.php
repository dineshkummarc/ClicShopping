<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\AI\Agents\Orchestrator\SubIntentAnalyzer;

use ClicShopping\AI\Domain\Patterns\LanguagePattern;
use ClicShopping\AI\Domain\Semantics\Semantics;
use ClicShopping\AI\Security\SecurityLogger;
use ClicShopping\Sites\Common\HTMLOverrideCommon;

/**
 * TranslationService
 *
 * Handles query translation and language detection.
 * Extracted from IntentAnalyzer to follow Single Responsibility Principle.
 *
 * Responsibilities:
 * - Detect if query is in English
 * - Translate non-English queries to English
 * - Clean translation output (remove GPT prefixes)
 * - Handle translation errors gracefully
 *
 * @package ClicShopping\AI\Agents\Orchestrator\SubIntentAnalyzer
 * @since 2025-12-14
 */
class TranslationService
{
  private SecurityLogger $logger;
  private bool $debug;

  /**
   * Constructor
   *
   * @param bool $debug Enable debug logging
   */
  public function __construct(bool $debug = false)
  {
    $this->logger = new SecurityLogger();
    $this->debug = $debug;
  }

  /**
   * Translate query to English if needed
   *
   * @param string $query Query to translate
   * @return array Translation result with:
   *   - 'translated_query' (string): Translated query (or original if already English)
   *   - 'original_query' (string): Original query
   *   - 'was_translated' (bool): Whether translation was performed
   *   - 'is_english' (bool): Whether query was detected as English
   *   - 'translation_time_ms' (float): Translation time in milliseconds
   */
  public function translateIfNeeded(string $query): array
  {
    $startTime = microtime(true);
    $isEnglish = $this->isEnglish($query);

    if ($isEnglish) {
      // Query is already in English, skip translation
      $translationTime = (microtime(true) - $startTime) * 1000;

      $this->logger->logStructured(
        'info',
        'TranslationService',
        'translation_skipped',
        [
          'original_query' => $query,
          'reason' => 'Query detected as English',
          'translation_time_ms' => round($translationTime, 2)
        ]
      );

      if ($this->debug) {
        error_log("⏭️ Translation SKIPPED - Query is already in English");
        error_log("Using original query: '{$query}'");
      }

      return [
        'translated_query' => $query,
        'original_query' => $query,
        'was_translated' => false,
        'is_english' => true,
        'translation_time_ms' => $translationTime,
      ];
    }

    // Query is not in English, translate it
    $translatedQuery = Semantics::translateToEnglish($query, 80);
    $translationTime = (microtime(true) - $startTime) * 1000;

    $this->logger->logStructured(
      'info',
      'TranslationService',
      'translation_performed',
      [
        'original_query' => $query,
        'translated_query' => $translatedQuery,
        'translation_time_ms' => round($translationTime, 2),
        'translation_empty' => empty(trim($translatedQuery))
      ]
    );

    if ($this->debug) {
      error_log("🌐 Translation PERFORMED - Query translated to English");
      error_log("Original query: '{$query}'");
      error_log("Translated query: '{$translatedQuery}'");
    }

    // If translation is empty, use original query
    if (empty(trim($translatedQuery))) {
      $translatedQuery = $query;

      $this->logger->logStructured(
        'warning',
        'TranslationService',
        'translation_fallback',
        [
          'original_query' => $query,
          'reason' => 'Translation returned empty string',
          'fallback_action' => 'Using original query'
        ]
      );

      if ($this->debug) {
        error_log("⚠️ Translation empty, using original query");
      }
    }

    // Clean translation
    $cleanTranslatedQuery = $this->extractCleanTranslation($translatedQuery);

    return [
      'translated_query' => $cleanTranslatedQuery,
      'original_query' => $query,
      'was_translated' => true,
      'is_english' => false,
      'translation_time_ms' => $translationTime,
    ];
  }

  /**
   * Detect if query is in English
   *
   * Uses multiple heuristics:
   * 1. French-specific characters (éèêëàâäùûüôöîïç)
   * 2. Common French words (le, la, les, pour, dans, etc.)
   * 3. Common English words (the, a, an, of, in, etc.)
   * 4. Character set analysis (ASCII vs non-ASCII ratio)
   *
   * @param string $query Query to analyze
   * @return bool True if query appears to be in English
   */
  public function isEnglish(string $query): bool
  {
    $queryLower = strtolower(trim($query));

    // Empty query → assume English (safe default)
    if (empty($queryLower)) {
      return true;
    }

    // 1. Check for French-specific characters (strong indicator)
    $frenchCharPattern = LanguagePattern::getFrenchCharacterPattern();
    if (preg_match($frenchCharPattern, $queryLower)) {
      return false;
    }

    // 2. Check for common French words (strong indicators)
    $frenchWords = LanguagePattern::getFrenchWordPatterns();

    foreach ($frenchWords as $pattern) {
      if (preg_match('/' . $pattern . '/u', $queryLower)) {
        return false;
      }
    }

    // 3. Check for common English words (positive indicators)
    $englishWords = LanguagePattern::getEnglishWordPatterns();

    $englishMatches = 0;
    foreach ($englishWords as $pattern) {
      if (preg_match('/' . $pattern . '/u', $queryLower)) {
        $englishMatches++;
        if ($englishMatches >= 2) {
          // Found 2+ English words and no French indicators → likely English
          return true;
        }
      }
    }

    // 4. If we found 1 English word and no French indicators, likely English
    if ($englishMatches >= 1) {
      return true;
    }

    // 5. Check character set - if mostly ASCII (no accents), likely English
    $nonAsciiCount = preg_match_all('/[^\x00-\x7F]/u', $query);
    $totalChars = mb_strlen($query, 'UTF-8');

    if ($totalChars > 0) {
      $nonAsciiRatio = $nonAsciiCount / $totalChars;

      // If less than 5% non-ASCII characters, likely English
      if ($nonAsciiRatio < 0.05) {
        return true;
      }
    }

    // Default: assume not English (safer to translate than to skip)
    return false;
  }

  /**
   * Extract clean translation by removing GPT prefixes
   *
   * Removes common GPT response prefixes like:
   * - "Translation: ..."
   * - "The translation is: ..."
   * - "English: ..."
   *
   * @param string $translatedQuery Raw translation from GPT
   * @return string Clean translation
   */
  public function extractCleanTranslation(string $translatedQuery): string
  {
    // Get centralized translation prefix patterns
    $patterns = LanguagePattern::getTranslationPrefixPatterns();
    
    // Pattern 1: Extract text between quotes after "is:"
    if (preg_match($patterns['quoted_after_is'], $translatedQuery, $matches)) {
      return $matches[1] ?? $matches[2];
    }

    // Pattern 2: Only if entire sentence is quoted AND starts with GPT prefix
    if (preg_match($patterns['full_quoted_prefix'], $translatedQuery, $matches)) {
      return $matches[2];
    }

    // Pattern 3: Remove common GPT prefixes only
    $clean = $translatedQuery;
    $prefixPatterns = [
      $patterns['simple_prefix_1'],
      $patterns['simple_prefix_2'],
      $patterns['simple_prefix_3']
    ];

    foreach ($prefixPatterns as $prefix) {
      $clean = preg_replace($prefix, '', $clean);
    }

    $clean = trim($clean, " \t\n\r\0\x0B");
    $clean = HTMLOverrideCommon::cleanHtmlForEmbedding($clean);

    // Remove extra whitespace only (not internal quotes)
    return $clean;
  }
}
