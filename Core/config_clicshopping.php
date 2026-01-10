<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

define('MODE_B2B_B2C', 'True'); // true ou false
define('MODE_DEMO', 'False'); // only demo mode
define('DEBUG_MODE', 'False'); // only for development
// ============================================================================
// TECHNICAL CONFIGURATION
// ============================================================================
// Load all technical constants from TechnicalConfig class
// Only loaded if RAG is enabled
if (defined('CLICSHOPPING_APP_CHATGPT_RA_STATUS') && CLICSHOPPING_APP_CHATGPT_RA_STATUS === 'True') {
  require_once(__DIR__ . '/ClicShopping/AI/Config/TechnicalConfig.php');
}