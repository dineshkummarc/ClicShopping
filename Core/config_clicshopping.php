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

// RAG Cache Configuration (2026-01-09)
// ============================================================================
// Enable/Disable RAG caching globally
// When enabled, RAG results are cached to improve performance
if (!defined('CLICSHOPPING_APP_CHATGPT_RA_CACHE_RAG_MANAGER')) define('CLICSHOPPING_APP_CHATGPT_RA_CACHE_RAG_MANAGER', 'True');

// Note: CACHE_TTL is loaded from TechnicalConfig (see bottom of this file)

// ============================================================================
// Semantic Search Cache Configuration (2026-01-09)
// ============================================================================
// Enable/Disable caching for semantic queries specifically
// 
// Semantic queries search knowledge base content (policies, FAQs, documentation)
// which may need fresh information more frequently than analytics data.
// 
// When 'True': Cache semantic query results (faster, but may serve stale content)
// When 'False': Always fetch fresh results (slower, but always up-to-date)
// 
// Note: This setting only applies when CACHE_RAG_MANAGER is 'True'
// If CACHE_RAG_MANAGER is 'False', no caching occurs regardless of this setting
// 
// Recommendation:
// - Set 'True' for stable knowledge bases (policies rarely change)
// - Set 'False' for dynamic content (frequently updated FAQs)
if (!defined('CLICSHOPPING_APP_CHATGPT_RA_CACHE_SEMANTIC_QUERIES')) define('CLICSHOPPING_APP_CHATGPT_RA_CACHE_SEMANTIC_QUERIES', 'True');

// ============================================================================
// Display Options Configuration
// ============================================================================
// Display memory context in chat responses
if (!defined('CLICSHOPPING_APP_CHATGPT_RA_DISPLAY_MEMORY_CONTEXT')) define('CLICSHOPPING_APP_CHATGPT_RA_DISPLAY_MEMORY_CONTEXT', 'True');

// ============================================================================
// Quality Control Configuration
// ============================================================================
// Enable/Disable LLPhant LLMReranker for improved document relevance
// When enabled, search results are reordered by contextual relevance using LLM
// This significantly improves result quality but adds ~2-3 seconds to queries
if (!defined('CLICSHOPPING_APP_CHATGPT_RA_USE_RERANKING')) define('CLICSHOPPING_APP_CHATGPT_RA_USE_RERANKING', 'True');

// ============================================================================
// Reasoning Agent Configuration
// ============================================================================
// Reasoning mode: 'chain_of_thought', 'tree_of_thought', or 'self_consistency'
// Default: 'chain_of_thought' (sequential step-by-step reasoning)
// Admin can change this to adjust reasoning strategy
if (!defined('CLICSHOPPING_APP_CHATGPT_RA_REASONING_MODE')) define('CLICSHOPPING_APP_CHATGPT_RA_REASONING_MODE', 'chain_of_thought');

// ============================================================================
// Security Control Configuration
// ============================================================================
// Enable LLM-based security (PRIMARY)
// When enabled, uses SemanticSecurityAnalyzer for threat detection
// Default: true (PURE LLM MODE)
if (!defined('CLICSHOPPING_APP_CHATGPT_RA_USE_LLM_PRIMARY_SECURITY')) define('CLICSHOPPING_APP_CHATGPT_RA_USE_LLM_PRIMARY_SECURITY', true);

// Enable email alerts for security events
// Default: false (disabled until configured)
if (!defined('CLICSHOPPING_APP_CHATGPT_RA_SECURITY_ALERTS_ENABLED')) define('CLICSHOPPING_APP_CHATGPT_RA_SECURITY_ALERTS_ENABLED', false);

// Email address to receive security alerts
// Default: empty (must be configured)
if (!defined('CLICSHOPPING_APP_CHATGPT_RA_SECURITY_ALERT_EMAIL')) define('CLICSHOPPING_APP_CHATGPT_RA_SECURITY_ALERT_EMAIL', '');

// ============================================================================
// TECHNICAL CONFIGURATION
// ============================================================================
// Load all technical constants from TechnicalConfig class
// Only loaded if RAG is enabled
if (defined('CLICSHOPPING_APP_CHATGPT_RA_STATUS') && CLICSHOPPING_APP_CHATGPT_RA_STATUS === 'True') {
  require_once(__DIR__ . '/ClicShopping/AI/Config/TechnicalConfig.php');
}