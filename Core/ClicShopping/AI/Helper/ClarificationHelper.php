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

use ClicShopping\AI\Security\SecurityLogger;
use ClicShopping\AI\Domain\Patterns\AmbiguityPattern;
use ClicShopping\AI\Domain\Semantics\Semantics;

/**
 * ClarificationHelper Class
 *
 * Helper class for detecting ambiguous queries and generating clarification questions
 * 
 * Responsibilities:
 * - Detect ambiguity in user queries
 * - Generate appropriate clarification questions
 * - Suggest options for disambiguation
 * 
 * @package ClicShopping\AI\Helper
 * @since 2025-11-14
 */
class ClarificationHelper
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
   * Detect if a query is ambiguous
   *
   * @param array $intent Intent analysis result
   * @param string $query Original query
   * @param array $context Conversation context
   * @return array Ambiguity detection result
   */
  public function detectAmbiguity(array $intent, string $query, array $context = []): array
  {
    $isAmbiguous = false;
    $ambiguityType = null;
    $missingInfo = [];
    $suggestions = [];

    // Check 0: Very short or vague queries (NEW - Test 5.5)
    $shortQueryCheck = $this->detectShortOrVagueQuery($query);
    if ($shortQueryCheck['is_ambiguous']) {
      return $shortQueryCheck;
    }

    // Check 1: Very low confidence (< 0.5)
    if (isset($intent['confidence']) && $intent['confidence'] < 0.5) {
      $isAmbiguous = true;
      $ambiguityType = 'low_confidence';
      $missingInfo[] = 'classification_uncertain';

      if ($this->debug) {
        $this->logger->logSecurityEvent(
          "Ambiguity detected: Low confidence (" . $intent['confidence'] . ")",
          'info'
        );
      }
    }

    // Check 2: Multiple possible entities without clear context
    if (isset($intent['metadata']['entities']) && count($intent['metadata']['entities']) > 1) {
      $hasContext = false;
      foreach ($intent['metadata']['entities'] as $entity) {
        if (isset($entity['from_context']) && $entity['from_context'] === true) {
          $hasContext = true;
          break;
        }
      }

      if (!$hasContext) {
        $isAmbiguous = true;
        $ambiguityType = 'multiple_entities';
        $missingInfo[] = 'entity_selection';
        $suggestions = array_map(
          fn($entity) => $entity['name'] ?? $entity['id'] ?? 'Unknown',
          $intent['metadata']['entities']
        );

        if ($this->debug) {
          $this->logger->logSecurityEvent(
            "Ambiguity detected: Multiple entities without context",
            'info'
          );
        }
      }
    }

    // Check 3: Missing parameters for analytics query
    if ($intent['type'] === 'analytics') {
      $missingParams = $this->detectMissingParameters($query, $intent);
      if (!empty($missingParams)) {
        $isAmbiguous = true;
        $ambiguityType = 'missing_parameters';
        $missingInfo = $missingParams;

        if ($this->debug) {
          $this->logger->logSecurityEvent(
            "Ambiguity detected: Missing parameters - " . implode(', ', $missingParams),
            'info'
          );
        }
      }
    }

    // Check 4: Unresolved contextual reference
    if ($this->hasUnresolvedReference($query, $context)) {
      $isAmbiguous = true;
      $ambiguityType = 'unresolved_reference';
      $missingInfo[] = 'contextual_reference';

      if ($this->debug) {
        $this->logger->logSecurityEvent(
          "Ambiguity detected: Unresolved contextual reference",
          'info'
        );
      }
    }

    return [
      'is_ambiguous' => $isAmbiguous,
      'ambiguity_type' => $ambiguityType,
      'missing_info' => $missingInfo,
      'suggestions' => $suggestions,
      'confidence' => $intent['confidence'] ?? 0,
    ];
  }

  /**
   * Generate a clarification question based on ambiguity type
   *
   * @param array $ambiguityResult Result from detectAmbiguity()
   * @param string $query Original query
   * @param array $intent Intent analysis
   * @return array Clarification question with options
   */
  public function generateClarificationQuestion(array $ambiguityResult, string $query, array $intent): array
  {
    $ambiguityType = $ambiguityResult['ambiguity_type'];
    $missingInfo = $ambiguityResult['missing_info'];
    $suggestions = $ambiguityResult['suggestions'];

    $question = '';
    $options = [];

    switch ($ambiguityType) {
      case 'low_confidence':
        $question = "I'm not sure I understand your question. Could you rephrase it or specify what you're looking for?";
        $options = [
          "Search for general information",
          "Analyze data",
          "Get help"
        ];
        break;

      case 'multiple_entities':
        $question = "Multiple items match your search. Which one are you interested in?";
        $options = $suggestions;
        break;

      case 'missing_parameters':
        $question = $this->generateMissingParameterQuestion($missingInfo);
        $options = $this->generateParameterOptions($missingInfo);
        break;

      case 'unresolved_reference':
        $question = "I'm not sure what you're referring to. Could you clarify?";
        $options = [
          "Specify the item",
          "Restart conversation"
        ];
        break;

      default:
        $question = "Could you please clarify your request?";
        $options = [];
    }

    if ($this->debug) {
      $this->logger->logSecurityEvent(
        "Generated clarification question: {$question}",
        'info'
      );
    }

    return [
      'question' => $question,
      'options' => $options,
      'ambiguity_type' => $ambiguityType,
      'original_query' => $query,
    ];
  }

  /**
   * Detect missing parameters in a query
   * 
   * Uses AmbiguityPattern for centralized keyword management (ENGLISH ONLY)
   * All queries should be translated to English before calling this method
   *
   * @param string $query Query to analyze (in English)
   * @param array $intent Intent analysis
   * @return array List of missing parameters
   */
  private function detectMissingParameters(string $query, array $intent): array
  {
    $missing = [];
    $parameterKeywords = AmbiguityPattern::getMissingParameterKeywords();

    // Check for price query without product
    $pricePattern = '/\b(' . implode('|', $parameterKeywords['price_without_product']['keywords']) . ')\b/i';
    if (preg_match($pricePattern, $query)) {
      if (!isset($intent['metadata']['entities']) || empty($intent['metadata']['entities'])) {
        $missing[] = $parameterKeywords['price_without_product']['missing'];
      }
    }

    // Check for stock query without product
    $stockPattern = '/\b(' . implode('|', $parameterKeywords['stock_without_product']['keywords']) . ')\b/i';
    if (preg_match($stockPattern, $query)) {
      if (!isset($intent['metadata']['entities']) || empty($intent['metadata']['entities'])) {
        $missing[] = $parameterKeywords['stock_without_product']['missing'];
      }
    }

    // Check for time-based query without time range
    $salesPattern = '/\b(' . implode('|', $parameterKeywords['sales_without_time']['keywords']) . ')\b/i';
    $timePattern = '/\b(' . implode('|', $parameterKeywords['sales_without_time']['time_keywords']) . ')\b/i';
    if (preg_match($salesPattern, $query)) {
      if (!preg_match($timePattern, $query)) {
        $missing[] = $parameterKeywords['sales_without_time']['missing'];
      }
    }

    return $missing;
  }

  /**
   * Check if query has unresolved contextual reference
   * 
   * Uses AmbiguityPattern for centralized pronoun list (ENGLISH ONLY)
   * All queries should be translated to English before calling this method
   *
   * @param string $query Query to check (in English)
   * @param array $context Conversation context
   * @return bool True if has unresolved reference
   */
  private function hasUnresolvedReference(string $query, array $context): bool
  {
    $pronouns = AmbiguityPattern::getContextualPronouns();
    
    foreach ($pronouns as $pronoun) {
      if (preg_match('/\b' . preg_quote($pronoun, '/') . '\b/i', $query)) {
        // Check if we have context
        if (empty($context) || !isset($context['last_entity_id'])) {
          return true;
        }
      }
    }

    return false;
  }

  /**
   * Generate question for missing parameters
   * 
   * Uses AmbiguityPattern for centralized questions (ENGLISH ONLY)
   *
   * @param array $missingInfo Missing parameters
   * @return string Question
   */
  private function generateMissingParameterQuestion(array $missingInfo): string
  {
    $questions = AmbiguityPattern::getClarificationQuestions();

    foreach ($missingInfo as $missing) {
      if (isset($questions[$missing])) {
        return $questions[$missing];
      }
    }

    return $questions['default'];
  }

  /**
   * Generate options for missing parameters
   * 
   * Uses AmbiguityPattern for centralized options (ENGLISH ONLY)
   *
   * @param array $missingInfo Missing parameters
   * @return array Options
   */
  private function generateParameterOptions(array $missingInfo): array
  {
    $allOptions = AmbiguityPattern::getClarificationOptions();

    foreach ($missingInfo as $missing) {
      if (isset($allOptions[$missing])) {
        return $allOptions[$missing];
      }
    }

    return [];
  }

  /**
   * Detect if a query is too short or vague
   * 
   * NEW: Test 5.5 - Détection d'ambiguïté
   * Detects queries like "ça", "quoi", "ok" that are too vague to process
   * Uses AmbiguityPattern for centralized pattern management
   *
   * @param string $query Query to check
   * @return array Ambiguity detection result
   */
  private function detectShortOrVagueQuery(string $query): array
  {
    $query = trim($query);
    $queryLower = mb_strtolower($query, 'UTF-8');
    $queryLength = mb_strlen($query, 'UTF-8');

    // Get patterns from AmbiguityPattern
    $minLength = AmbiguityPattern::getMinimumQueryLength();
    $vagueWords = AmbiguityPattern::getVagueWords();
    
    // Flatten vague words from both languages
    $allVagueWords = [];
    foreach ($vagueWords as $language => $categories) {
      foreach ($categories as $category => $words) {
        $allVagueWords = array_merge($allVagueWords, $words);
      }
    }
    $allVagueWords = array_unique($allVagueWords);

    // Check 1: Very short queries
    if ($queryLength < $minLength) {
      if ($this->debug) {
        $this->logger->logSecurityEvent(
          "Query too short: '{$query}' ({$queryLength} chars)",
          'info'
        );
      }

      return [
        'is_ambiguous' => true,
        'ambiguity_type' => 'too_short',
        'missing_info' => ['query_content'],
        'suggestions' => $this->generateVagueSuggestions(),
        'confidence' => 0.0
      ];
    }

    // Check 2: Single vague word
    $words = preg_split('/\s+/', $queryLower);
    if (count($words) === 1 && in_array($words[0], $allVagueWords)) {
      if ($this->debug) {
        $this->logger->logSecurityEvent(
          "Single vague word detected: '{$query}'",
          'info'
        );
      }

      return [
        'is_ambiguous' => true,
        'ambiguity_type' => 'vague_word',
        'missing_info' => ['specific_intent'],
        'suggestions' => $this->generateVagueSuggestions(),
        'confidence' => 0.0
      ];
    }

    // Check 3: Query starts with vague word and is short (< 15 chars)
    if ($queryLength < 15) {
      foreach ($allVagueWords as $vagueWord) {
        if (strpos($queryLower, $vagueWord) === 0) {
          if ($this->debug) {
            $this->logger->logSecurityEvent(
              "Query starts with vague word: '{$query}'",
              'info'
            );
          }

          return [
            'is_ambiguous' => true,
            'ambiguity_type' => 'vague_start',
            'missing_info' => ['context_details'],
            'suggestions' => $this->generateVagueSuggestions(),
            'confidence' => 0.1
          ];
        }
      }
    }

    // Check 4: Only punctuation or special characters
    if (preg_match('/^[\s\p{P}\p{S}]+$/u', $query)) {
      if ($this->debug) {
        $this->logger->logSecurityEvent(
          "Query contains only punctuation/special chars: '{$query}'",
          'info'
        );
      }

      return [
        'is_ambiguous' => true,
        'ambiguity_type' => 'no_content',
        'missing_info' => ['meaningful_content'],
        'suggestions' => $this->generateVagueSuggestions(),
        'confidence' => 0.0
      ];
    }

    // Query is clear enough
    return [
      'is_ambiguous' => false,
      'ambiguity_type' => null,
      'missing_info' => [],
      'suggestions' => [],
      'confidence' => 1.0
    ];
  }

  /**
   * Generate helpful suggestions for vague queries
   * Uses AmbiguityPattern for centralized suggestions
   * 
   * @return array List of suggested questions
   */
  private function generateVagueSuggestions(): array
  {
    $suggestions = AmbiguityPattern::getSuggestedQuestions();
    
    // Return French suggestions by default
    return $suggestions['french'] ?? [];
  }
}
