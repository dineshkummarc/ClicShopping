<?php
/**
 * Model Context Limits Configuration for Schema RAG
 * 
 * This file defines context window sizes for different LLM models.
 * Values are conservative, leaving room for the response.
 * 
 * Format: 'model_name' => context_limit_in_tokens
 * 
 * Rules:
 * - Use conservative limits (actual limit - buffer for response)
 * - Support partial matching (e.g., "qwen3-4b" matches "qwen/qwen3-4b-instruct")
 * - Keep updated as new models are released
 * - Default limit is used for unknown models
 */

return [
  // Default limit for unknown models (conservative)
  'default' => 3500,
  
  // Qwen Models
  'qwen/qwen3-4b' => 3500,      // 4K context - 500 for response
  'qwen3-4b' => 3500,
  'qwen/qwen3-8b' => 7500,      // 8K context - 500 for response
  'qwen3-8b' => 7500,
  'qwen/qwen3-14b' => 15000,    // 16K context - 1K for response
  'qwen3-14b' => 15000,
  'qwen/qwen3-32b' => 31000,    // 32K context - 1K for response
  'qwen3-32b' => 31000,
  
  // Microsoft Phi Models
  'microsoft/phi-4' => 15000,    // 16K context - 1K for response
  'phi-4' => 15000,
  'microsoft/phi-3' => 7500,     // 8K context - 500 for response
  'phi-3' => 7500,
  
  // OpenAI Models
  'gpt-4o' => 120000,            // 128K context - 8K for response
  'gpt-4o-mini' => 120000,       // 128K context - 8K for response
  'gpt-4-turbo' => 120000,       // 128K context - 8K for response
  'gpt-4' => 7500,               // 8K context - 500 for response
  'gpt-3.5-turbo' => 15000,      // 16K context - 1K for response
  
  // Anthropic Claude Models
  'claude-3-opus' => 190000,     // 200K context - 10K for response
  'claude-3-sonnet' => 190000,   // 200K context - 10K for response
  'claude-3-haiku' => 190000,    // 200K context - 10K for response
  'claude-2' => 95000,           // 100K context - 5K for response
  
  // Meta Llama Models
  'llama-3.1-8b' => 120000,      // 128K context - 8K for response
  'llama-3.1-70b' => 120000,     // 128K context - 8K for response
  'llama-3-8b' => 7500,          // 8K context - 500 for response
  'llama-3-70b' => 7500,         // 8K context - 500 for response
  
  // Mistral Models
  'mistral-large' => 31000,      // 32K context - 1K for response
  'mistral-medium' => 31000,     // 32K context - 1K for response
  'mistral-small' => 31000,      // 32K context - 1K for response
  'mistral-7b' => 7500,          // 8K context - 500 for response
  
  // Google Gemini Models
  'gemini-pro' => 31000,         // 32K context - 1K for response
  'gemini-1.5-pro' => 1000000,   // 1M context - 50K for response (experimental)
  
  // Ollama Local Models (typically smaller context)
  'ollama/llama2' => 3500,       // 4K context - 500 for response
  'ollama/mistral' => 7500,      // 8K context - 500 for response
  'ollama/codellama' => 15000,   // 16K context - 1K for response
];
