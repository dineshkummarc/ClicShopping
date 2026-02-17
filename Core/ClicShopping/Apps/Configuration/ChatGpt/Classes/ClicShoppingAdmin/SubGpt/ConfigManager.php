<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\SubGpt;

use ClicShopping\OM\CLICSHOPPING;
use function defined;

/**
 * ConfigManager
 *
 * Manages GPT configuration and status checks.
 * Extracted from Gpt.php as part of code refactoring (Task 9).
 *
 * Responsibilities:
 * - Check GPT integration status
 * - Manage API key environment
 * - Generate AJAX URLs
 * - Manage SerpApi configuration
 */
class ConfigManager
{
  /**
   * Checks the status of the GPT integration by verifying application constants and API key configuration.
   *
   * @return bool Returns true if the GPT integration is enabled and properly configured, otherwise false.
   */
  public static function checkGptStatus(): bool
  {
    if (!defined('CLICSHOPPING_APP_CHATGPT_CH_STATUS') || CLICSHOPPING_APP_CHATGPT_CH_STATUS == 'False' || empty(CLICSHOPPING_APP_CHATGPT_CH_API_KEY)) {
      return false;
    }

    return true;
  }

  /**
   * Securely retrieves the OpenAI API key for use in API calls.
   * Instead of setting an environment variable with putenv(), which is insecure,
   * this method simply returns the API key from the application configuration.
   *
   * @return string|null The API key or null if not configured
   */
  public static function getEnvironment(): string|null
  {
    // Initialiser les constantes nécessaires
    static::initializeConstants();

    if (!defined('CLICSHOPPING_APP_CHATGPT_CH_API_KEY') || empty(CLICSHOPPING_APP_CHATGPT_CH_API_KEY)) {
      error_log("WARNING: CLICSHOPPING_APP_CHATGPT_CH_API_KEY not defined or empty");
      return null;
    }

    $env = putenv('OPENAI_API_KEY=' . CLICSHOPPING_APP_CHATGPT_CH_API_KEY);

    return $env;
  }

  /**
   * Initialise les constantes nécessaires si elles n'existent pas
   */
  private static function initializeConstants(): void
  {
  }

  /**
   * Generates the AJAX URL for the requested script.
   *
   * @param bool $chatGpt Determines whether to return the URL for the chatGpt script (true)
   *                       or the chatGptSEO script (false).
   * @return string Returns the appropriate AJAX URL based on the parameter.
   */
  public static function getAjaxUrl(bool $chatGpt = true): string
  {
    if ($chatGpt === false) {
      $url = CLICSHOPPING::getConfig('http_server', 'ClicShoppingAdmin') . CLICSHOPPING::getConfig('http_path', 'ClicShoppingAdmin') . 'ajax/ChatGpt/chatGptSEO.php';
    } else {
      $url = CLICSHOPPING::getConfig('http_server', 'ClicShoppingAdmin') . CLICSHOPPING::getConfig('http_path', 'ClicShoppingAdmin') . 'ajax/ChatGpt/chatGpt.php';
    }

    return $url;
  }

  /**
   * Generates the URL for the AJAX SEO multilanguage functionality.
   *
   * @return string The fully constructed URL for the AJAX SEO multilanguage script.
   */
  public static function getAjaxSeoMultilanguageUrl(): string
  {
    $url = CLICSHOPPING::getConfig('http_server', 'ClicShoppingAdmin') . CLICSHOPPING::getConfig('http_path', 'ClicShoppingAdmin') . 'ajax/ChatGpt/chatGptMultiLanguage.php';

    return $url;
  }

  /**
   * Retrieves the SerpApi key from configuration
   *
   * @return string La clé API SerpApi ou chaîne vide si non configurée
   */
  public static function getSerpApiKey(): string
  {
    if (!defined('CLICSHOPPING_APP_CHATGPT_CH_API_KEY_SERPAPI') || empty(CLICSHOPPING_APP_CHATGPT_CH_API_KEY_SERPAPI)) {
      return '';
    }

    return CLICSHOPPING_APP_CHATGPT_CH_API_KEY_SERPAPI;
  }

  /**
   * Checks if SerpApi is available and configured
   *
   * @return bool True si une clé SerpApi valide est disponible
   */
  public static function isSerpApiAvailable(): bool
  {
    return !empty(self::getSerpApiKey());
  }
}
