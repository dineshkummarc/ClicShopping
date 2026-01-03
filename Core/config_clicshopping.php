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
// RAG Cache Configuration (Task 4.4 - Fix cache activation)
// ============================================================================
// Enable/Disable RAG caching globally
// When enabled, RAG results are cached to improve performance
if (!defined('CLICSHOPPING_APP_CHATGPT_RA_CACHE_RAG_MANAGER')) define('CLICSHOPPING_APP_CHATGPT_RA_CACHE_RAG_MANAGER', 'True');

// ============================================================================
// RAG Hybrid Query Pre-Filter (2026-01-02 - Optional Pattern-Based Detection)
// ============================================================================
// Enable/Disable HybridPreFilter for hybrid query detection
// When 'True': Uses pattern-based pre-filter (HybridPreFilter) before LLM classification
//              - Fast (~1ms) and accurate (90%) for hybrid queries with conjunctions
//              - Operates AFTER translation (English-only keywords)
//              - Falls back to LLM if patterns don't match
// When 'False': Pure LLM mode - always uses LLM for hybrid detection
//              - Slower (~600ms) but more flexible
//              - Lower accuracy (60-70%) for hybrid queries
// 
// RECOMMENDATION: Keep 'True' (default) for best hybrid detection accuracy
// Set to 'False' only if you want pure LLM mode for all classification
// 
// Performance Comparison:
// - Pattern Pre-Filter: ~1ms latency, 90% accuracy, $0 cost
// - Pure LLM:          ~600ms latency, 60-70% accuracy, ~$0.0001 cost
// 
// See: kiro_documentation/2026_01_02/hybrid_prefilter_pure_llm_analysis.md
if (!defined('USE_HYBRID_PRE_FILTER')) define('USE_HYBRID_PRE_FILTER', 'False');

// ============================================================================
// RAG Timeout Configuration (Test 5.6 - Timeout de Requête)
// ============================================================================
// Maximum execution time for RAG queries (in seconds)
// Prevents long-running queries from blocking the system
// Default: 30 seconds
// ⚠️ TEMPORARY: Increased to 60 seconds while testing Unified Analyzer activation
// Once analytics queries work (SQL instead of web search), reduce back to 30
if (!defined('CLICSHOPPING_APP_CHATGPT_RA_MAX_EXECUTION_TIME')) define('CLICSHOPPING_APP_CHATGPT_RA_MAX_EXECUTION_TIME', 60);

// Enable timeout handling
// When enabled, queries exceeding the max execution time will be gracefully terminated
if (!defined('CLICSHOPPING_APP_CHATGPT_RA_ENABLE_TIMEOUT')) define('CLICSHOPPING_APP_CHATGPT_RA_ENABLE_TIMEOUT', 'True');

// ============================================================================
// Prompt Length Configuration (2025-12-30 - Configurable Limit)
// ============================================================================
// Maximum prompt length in characters
// Modern LLMs support up to 128K tokens (~400K+ characters)
// Default: 100,000 characters (~25K tokens)
// 
// Recommended values by LLM:
// - GPT-4/GPT-4o:        100,000 chars (~25K tokens)
// - Claude 3:            150,000 chars (~37K tokens)
// - GPT-3.5-turbo:        50,000 chars (~12K tokens)
// - Local models (Llama): 25,000 chars (~6K tokens)
if (!defined('CLICSHOPPING_APP_CHATGPT_MAX_PROMPT_LENGTH')) define('CLICSHOPPING_APP_CHATGPT_MAX_PROMPT_LENGTH', 100000);

// ============================================================================
// RAG Similarity Score Configuration (Task 4.4 - Multilingual support)
// ============================================================================
// Minimum similarity score for RAG Knowledge Base search (0.0-1.0)
// Lowered to 0.25 for multilingual support (French/English embeddings)
// Higher values (0.7) prevent French embeddings from matching
if (!defined('CLICSHOPPING_APP_CHATGPT_RA_MIN_SIMILARITY_SCORE')) define('CLICSHOPPING_APP_CHATGPT_RA_MIN_SIMILARITY_SCORE', '0.25');

// Minimum similarity score for Conversation Memory search (0.0-1.0)
// HIGHER threshold (0.85) to prevent false matches from conversation memory
// Conversation memory should only match VERY similar queries (near-exact repeats)
// This prevents "où est Paris?" from matching "refund policy" (similarity 0.63)
if (!defined('CLICSHOPPING_APP_CHATGPT_RA_MEMORY_MIN_SCORE')) define('CLICSHOPPING_APP_CHATGPT_RA_MEMORY_MIN_SCORE', '0.85');

// RAG Max Results Configuration
// Balance between quality and quantity - 5 results per vector store
if (!defined('CLICSHOPPING_APP_CHATGPT_RA_MAX_RESULTS_PER_STORE')) define('CLICSHOPPING_APP_CHATGPT_RA_MAX_RESULTS_PER_STORE', '5');

// ============================================================================
// RAG Reranking Configuration (Task 2.14.3 - LLPhant Integration)
// ============================================================================
// Enable/Disable LLPhant LLMReranker for improved document relevance
// When enabled, search results are reordered by contextual relevance using LLM
// This significantly improves result quality but adds ~2-3 seconds to queries
// 🧪 TESTING: Reranker with new fixes (entity_type mapping + score sorting)
// Previous issue: Reranker prioritized Orders over PageManager
// New fixes should help: sorted results + proper entity_type filtering
if (!defined('CLICSHOPPING_APP_CHATGPT_RA_USE_RERANKING')) define('CLICSHOPPING_APP_CHATGPT_RA_USE_RERANKING', 'True');

// Number of documents to return after reranking
// The system will initially retrieve (limit * 3) documents,
// then rerank them and return the top N most relevant
if (!defined('CLICSHOPPING_APP_CHATGPT_RA_RERANKING_OUTPUT')) define('CLICSHOPPING_APP_CHATGPT_RA_RERANKING_OUTPUT', '5');

if (!defined('CLICSHOPPING_APP_CHATGPT_RA_ENABLE_LLM_FALLBACK')) define('CLICSHOPPING_APP_CHATGPT_RA_ENABLE_LLM_FALLBACK', 'True');

// ============================================================================
// Schema RAG Configuration (2025-12-22 - Dynamic Table Selection)
// ============================================================================
// Enable/Disable Schema RAG for analytics queries
// When enabled, uses intelligent table selection based on query context
// When disabled, uses full schema (slower, more tokens)
if (!defined('CLICSHOPPING_APP_CHATGPT_RA_SCHEMA_RAG')) define('CLICSHOPPING_APP_CHATGPT_RA_SCHEMA_RAG', 'True');

// Enable/Disable Embeddings for Schema RAG
// When 'False': Pure LLM mode - uses only column keyword matching (no embeddings)
// When 'True': Hybrid mode - 30% embeddings + 70% column matching
// Default: 'False' (Pure LLM mode for testing without embeddings)
// ⚠️ PURE LLM MODE: Set to 'False' to test without embeddings
if (!defined('CLICSHOPPING_APP_CHATGPT_RA_SCHEMA_USE_EMBEDDINGS')) define('CLICSHOPPING_APP_CHATGPT_RA_SCHEMA_USE_EMBEDDINGS', 'False');

// Maximum number of tables to include in schema context
// Lower values = faster queries, less context
// Higher values = more context, slower queries
if (!defined('CLICSHOPPING_APP_CHATGPT_RA_SCHEMA_MAX_TABLES')) define('CLICSHOPPING_APP_CHATGPT_RA_SCHEMA_MAX_TABLES', '5');
