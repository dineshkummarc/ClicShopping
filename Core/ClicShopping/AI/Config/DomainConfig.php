<?php
/**
 * Domain Configuration Utility for RAG System
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 *
 * This utility class provides centralized access to domain-specific configuration
 * for the multi-domain RAG system. It enables domain-aware language loading by
 * constructing paths with domain subdirectories (e.g., ClicShoppingAdmin/ecommerce).
 *
 * Key Features:
 * - Retrieves active domain identifier from configuration
 * - Constructs language paths with domain subdirectories
 * - Supports multiple domains: ecommerce, hr, finance, trading, etc.
 * - Maintains backward compatibility (empty activities = root directory)
 * - Used by all AI components for domain-specific prompt loading
 *
 * Usage Example:
 * ```php
 * use ClicShopping\AI\Config\DomainConfig;
 *
 * // Get active domain identifier
 * $domain = DomainConfig::getActivities(); // Returns 'ecommerce'
 *
 * // Get language path with domain subdirectory
 * $path = DomainConfig::getLanguagePath(); // Returns 'ClicShoppingAdmin/ecommerce'
 * $path = DomainConfig::getLanguagePath('Shop'); // Returns 'Shop/ecommerce'
 * ```
 *
 * Directory Structure:
 * ```
 * ClicShoppingAdmin/Core/languages/
 * ├-- english/
 * |   ├-- ecommerce/
 * |   |   ├-- rag_analytics_agent.txt
 * |   |   ├-- rag_semantic_agent.txt
 * |   |   +-- ...
 * |   ├-- hr/
 * |   |   ├-- rag_analytics_agent.txt
 * |   |   +-- ...
 * |   +-- finance/
 * |       +-- ...
 * +-- french/
 *     ├-- ecommerce/
 *     +-- ...
 * ```
 *
 * @created 2026-01-18
 * @see .kiro/specs/active/rag-multi-domain-evolution/requirements.md (Requirement 27)
 * @see .kiro/specs/active/rag-multi-domain-evolution/tasks.md (Task 3.1)
 */

namespace ClicShopping\AI\Config;

use ClicShopping\OM\Registry;

class DomainConfig
{
  private static ?DomainConfig $instance = null;
  private array $entityConfigCache = [];

  /**
   * Get singleton instance
   *
   * @return self
   */
  public static function getInstance(): self
  {
    if (self::$instance === null) {
      self::$instance = new self();
    }
    return self::$instance;
  }

  /**
   * Get the active domain identifier from configuration
   *
   * Retrieves the CLICSHOPPING_APP_CHATGPT_RA_ACTIVITIES configuration constant
   * which specifies the active domain (e.g., 'ecommerce', 'hr', 'finance', 'trading').
   *
   * This method is used by getLanguagePath() to construct domain-specific paths
   * and by other components that need to know the active domain context.
   *
   * Configuration:
   * - Constant: CLICSHOPPING_APP_CHATGPT_RA_ACTIVITIES
   * - Default: 'ecommerce' (if not defined)
   * - Possible values: 'ecommerce', 'hr', 'finance', 'trading', etc.
   * - Location: Core/config_clicshopping.php or database configuration
   *
   * @return string The active domain identifier (lowercase)
   *                Returns 'ecommerce' if not configured
   *
   * @example
   * ```php
   * $domain = DomainConfig::getActivities();
   * // Returns: 'ecommerce' (default)
   * // Or: 'hr', 'finance', 'trading' (if configured)
   * ```
   *
   * @since 1.0.0
   */
  public static function getActivities(): string
  {
    // Check if the configuration constant is defined
    if (defined('CLICSHOPPING_APP_CHATGPT_RA_ACTIVITIES')) {
      // Return the configured domain identifier (lowercase for consistency)
      return strtolower(CLICSHOPPING_APP_CHATGPT_RA_ACTIVITIES);
    }

    // Default to 'ecommerce' for backward compatibility
    return 'ecommerce';
  }

  /**
   * Get the domain subdirectory for language file loading
   *
   * Returns the domain subdirectory to be prepended to language file names
   * when loading domain-specific prompts and language files.
   *
   * Path Construction:
   * - If activities configured: {domain}
   * - If no activities: '' (empty string, root directory, backward compatible)
   *
   * Examples:
   * - Domain 'ecommerce': 'ecommerce'
   * - Domain 'hr': 'hr'
   * - Domain 'finance': 'finance'
   * - No domain: '' (empty string, fallback to root)
   *
   * The Language class will then load from:
   * ClicShoppingAdmin/Core/languages/english/ecommerce/rag_analytics_agent.txt
   *
   * @param string $site The site context ('ClicShoppingAdmin' or 'Shop')
   *                     This parameter is kept for backward compatibility but not used
   *                     since we only return the domain subdirectory
   *
   * @return string The domain subdirectory
   *                Format: '{domain}' or '' (if no domain)
   *
   * @example
   * ```php
   * // Admin context with ecommerce domain
   * $path = DomainConfig::getLanguagePath();
   * // Returns: 'ecommerce'
   *
   * // Shop context with hr domain
   * $path = DomainConfig::getLanguagePath('Shop');
   * // Returns: 'hr'
   *
   * // No domain configured (backward compatible)
   * $path = DomainConfig::getLanguagePath();
   * // Returns: '' (empty string)
   * ```
   *
   * @since 1.0.0
   */
  public static function getLanguagePath(string $site = 'ClicShoppingAdmin'): string
  {
    // Get the active domain identifier
    $activities = self::getActivities();

    // If activities is empty or not configured, return empty string (backward compatible)
    if (empty($activities)) {
      return '';
    }

    // Return just the domain subdirectory
    // Format: {domain}
    // Example: 'ecommerce'
    return $activities;
  }

  /**
   * Load a language file for the active domain
   *
   * This method simplifies the common pattern used throughout the codebase:
   * - Uses getLanguagePath() to get domain subdirectory
   * - Constructs the group path with domain prefix
   * - Loads language definitions
   *
   * @param string $textFile Language file name without extension (e.g., 'rag_semantic_search_orchestrator', 'entities')
   * @param string|null $language Language code (default: 'en')
   * @param string $site Site context (default: 'ClicShoppingAdmin')
   * @return mixed Returns result from loadDefinitions() or false on error
   *
   */
  public static function loadLanguageFile(string $textFile, ?string $language = 'en', string $site = 'ClicShoppingAdmin'): mixed
  {
    try {
      $CLICSHOPPING_language = Registry::get('Language');

      if (is_null($language)) {
        $language = $CLICSHOPPING_language->getCode();
      }

      $domainPath = self::getLanguagePath();

      $group = !empty($domainPath) ? $domainPath . '/' . $textFile : $textFile;

      return $CLICSHOPPING_language->loadDefinitions($group, $language, null, $site);
    } catch (\Exception $e) {
      // Log the error but don't throw to maintain backward compatibility
      error_log('DomainConfig::loadLanguageFile() error: ' . $e->getMessage());
      return false;
    }
  }

  /**
   * Clear entity configuration cache (useful when switching domains)
   *
   * @return void
   * @since 1.0.0
   */
  public function clearCache(): void
  {
    $this->entityConfigCache = [];
  }
}
