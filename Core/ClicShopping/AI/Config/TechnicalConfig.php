<?php
/**
 * Technical Configuration Constants for RAG System
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 *
 * This file defines all technical RAG configuration constants.
 * These are implementation details that rarely need to be changed.
 * Admin-level controls remain in Core/config_clicshopping.php
 *
 * @created 2026-01-09
 * @see .kiro/specs/active/technical-config-migration.md
 */

// ============================================================================
// LIMITS & THRESHOLDS (7 constants)
// ============================================================================

if (!defined('CLICSHOPPING_APP_CHATGPT_RA_MAX_PROMPT_LENGTH'))
  define('CLICSHOPPING_APP_CHATGPT_RA_MAX_PROMPT_LENGTH', 100000);

if (!defined('CLICSHOPPING_APP_CHATGPT_RA_SQL_SAFETY_LIMIT'))
  define('CLICSHOPPING_APP_CHATGPT_RA_SQL_SAFETY_LIMIT', 10000);

if (!defined('CLICSHOPPING_APP_CHATGPT_RA_MIN_SIMILARITY_SCORE'))
  define('CLICSHOPPING_APP_CHATGPT_RA_MIN_SIMILARITY_SCORE', 0.25);

if (!defined('CLICSHOPPING_APP_CHATGPT_RA_MEMORY_MIN_SCORE'))
  define('CLICSHOPPING_APP_CHATGPT_RA_MEMORY_MIN_SCORE', 0.85);

if (!defined('CLICSHOPPING_APP_CHATGPT_RA_MAX_RESULTS_PER_STORE'))
  define('CLICSHOPPING_APP_CHATGPT_RA_MAX_RESULTS_PER_STORE', 5);

if (!defined('CLICSHOPPING_APP_CHATGPT_RA_RERANKING_OUTPUT'))
  define('CLICSHOPPING_APP_CHATGPT_RA_RERANKING_OUTPUT', 5);

// ============================================================================
// CACHE TTL (1 constant)
// ============================================================================

if (!defined('CLICSHOPPING_APP_CHATGPT_RA_CACHE_TTL'))
  define('CLICSHOPPING_APP_CHATGPT_RA_CACHE_TTL', 2592000); // 30 days

// ============================================================================
// FALLBACK (2 constants)
// ============================================================================

if (!defined('CLICSHOPPING_APP_CHATGPT_RA_ENABLE_WEB_FALLBACK'))
  define('CLICSHOPPING_APP_CHATGPT_RA_ENABLE_WEB_FALLBACK', 'True');

if (!defined('CLICSHOPPING_APP_CHATGPT_RA_ENABLE_LLM_FALLBACK'))
  define('CLICSHOPPING_APP_CHATGPT_RA_ENABLE_LLM_FALLBACK', 'True');

// ============================================================================
// SCHEMA RAG (3 constants)
// ============================================================================

if (!defined('CLICSHOPPING_APP_CHATGPT_RA_SCHEMA_RAG'))
  define('CLICSHOPPING_APP_CHATGPT_RA_SCHEMA_RAG', 'True');

if (!defined('CLICSHOPPING_APP_CHATGPT_RA_SCHEMA_USE_EMBEDDINGS'))
  define('CLICSHOPPING_APP_CHATGPT_RA_SCHEMA_USE_EMBEDDINGS', 'False');

if (!defined('CLICSHOPPING_APP_CHATGPT_RA_SCHEMA_MAX_TABLES'))
  define('CLICSHOPPING_APP_CHATGPT_RA_SCHEMA_MAX_TABLES', 5);

// ============================================================================
// REASONING AGENT (3 constants)
// ============================================================================

if (!defined('CLICSHOPPING_APP_CHATGPT_RA_MAX_REASONING_STEPS'))
  define('CLICSHOPPING_APP_CHATGPT_RA_MAX_REASONING_STEPS', 10);

if (!defined('CLICSHOPPING_APP_CHATGPT_RA_CONSISTENCY_PATHS'))
  define('CLICSHOPPING_APP_CHATGPT_RA_CONSISTENCY_PATHS', 3);

if (!defined('CLICSHOPPING_APP_CHATGPT_RA_TREE_PATHS'))
  define('CLICSHOPPING_APP_CHATGPT_RA_TREE_PATHS', 3);

// ============================================================================
// SECURITY TECHNICAL (6 constants)
// ============================================================================

if (!defined('CLICSHOPPING_APP_CHATGPT_RA_SECURITY_THREAT_THRESHOLD'))
  define('CLICSHOPPING_APP_CHATGPT_RA_SECURITY_THREAT_THRESHOLD', 0.7);

if (!defined('CLICSHOPPING_APP_CHATGPT_RA_SECURITY_PATTERN_FALLBACK'))
  define('CLICSHOPPING_APP_CHATGPT_RA_SECURITY_PATTERN_FALLBACK', false);

if (!defined('CLICSHOPPING_APP_CHATGPT_RA_SECURITY_LLM_TIMEOUT'))
  define('CLICSHOPPING_APP_CHATGPT_RA_SECURITY_LLM_TIMEOUT', 5000);

if (!defined('CLICSHOPPING_APP_CHATGPT_RA_SECURITY_LOG_ALL_QUERIES'))
  define('CLICSHOPPING_APP_CHATGPT_RA_SECURITY_LOG_ALL_QUERIES', false);

if (!defined('CLICSHOPPING_APP_CHATGPT_RA_SECURITY_LOG_BLOCKED_ONLY'))
  define('CLICSHOPPING_APP_CHATGPT_RA_SECURITY_LOG_BLOCKED_ONLY', true);

if (!defined('CLICSHOPPING_APP_CHATGPT_RA_SECURITY_RESPONSE_VALIDATION'))
  define('CLICSHOPPING_APP_CHATGPT_RA_SECURITY_RESPONSE_VALIDATION', true);

// ============================================================================
// SECURITY ALERTING (5 constants)
// ============================================================================

if (!defined('CLICSHOPPING_APP_CHATGPT_RA_SECURITY_ALERT_THRESHOLD'))
  define('CLICSHOPPING_APP_CHATGPT_RA_SECURITY_ALERT_THRESHOLD', 10);

if (!defined('CLICSHOPPING_APP_CHATGPT_RA_SECURITY_HIGH_THREAT_THRESHOLD'))
  define('CLICSHOPPING_APP_CHATGPT_RA_SECURITY_HIGH_THREAT_THRESHOLD', 20);

if (!defined('CLICSHOPPING_APP_CHATGPT_RA_SECURITY_ALERT_COOLDOWN'))
  define('CLICSHOPPING_APP_CHATGPT_RA_SECURITY_ALERT_COOLDOWN', 60);

if (!defined('CLICSHOPPING_APP_CHATGPT_RA_SECURITY_ALERT_DIGEST_MODE'))
  define('CLICSHOPPING_APP_CHATGPT_RA_SECURITY_ALERT_DIGEST_MODE', true);

if (!defined('CLICSHOPPING_APP_CHATGPT_RA_SECURITY_FAILURE_ALERTS'))
  define('CLICSHOPPING_APP_CHATGPT_RA_SECURITY_FAILURE_ALERTS', true);

// ============================================================================
// Calculator Tool Configuration (2026-01-09)
// ============================================================================
// Enable/Disable CalculatorTool (admin-level control)
// This is the only Calculator setting that should be in global config
// Technical settings (history size, validation, timeouts, cache TTL) are
// defined as class constants in CalculatorTool.php

if (!defined('CLICSHOPPING_APP_CHATGPT_CALCULATOR_ENABLED'))
  define('CLICSHOPPING_APP_CHATGPT_CALCULATOR_ENABLED', 'True');
