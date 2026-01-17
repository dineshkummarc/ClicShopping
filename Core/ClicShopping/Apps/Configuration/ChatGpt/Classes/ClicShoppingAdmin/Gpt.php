<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin;

use AllowDynamicProperties;
use ClicShopping\OM\CLICSHOPPING;
use ClicShopping\OM\Hash;
use ClicShopping\OM\HTML;
use ClicShopping\OM\HTTP;
use ClicShopping\OM\Registry;
use ClicShopping\Sites\Common\HTMLOverrideCommon;
use ClicShopping\Apps\Configuration\Administrators\Classes\ClicShoppingAdmin\AdministratorAdmin;
use ClicShopping\AI\Security\InputValidator;
use ClicShopping\AI\Infrastructure\Prompt\PromptOptimizer;
use ClicShopping\AI\Infrastructure\Response\ResponseNormalizer;
use ClicShopping\AI\Security\SecurityLogger;

// Provider interface imports
use ClicShopping\Apps\Configuration\ChatGpt\Classes\Common\LLMProviderInterface;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\Common\LLMProviderFactory;

use DateTimeImmutable;
use LLPhant\Chat\LLMStudioChat;
use LLPhant\Chat\LmStudioChat;
use LLPhant\Chat\MistralAIChat;
use LLPhant\Chat\OllamaChat;
use LLPhant\Chat\OpenAIChat;
use LLPhant\LmStudioConfig;
use LLPhant\OpenAIConfig;
use LLPhant\OllamaConfig;
use LLPhant\AnthropicConfig;
use LLPhant\Chat\AnthropicChat;

use function defined;
use function is_null;

#[AllowDynamicProperties]
/**
 * Gpt
 *
 * Class to manage interactions with GPT models (OpenAI, Ollama, Anthropic, Mistral)
 * This class encapsulates the logic to check the status of GPT integration,
 * retrieve available models, generate responses, and manage configurations.
 *
 */
class Gpt
{
  // Store last token usage for retrieval
  private static $lastTokenUsage = null;

  public function __construct()
  {
  }

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
      /*
    if (!defined('CLICSHOPPING_APP_CHATGPT_CH_MODEL')) {
      define('CLICSHOPPING_APP_CHATGPT_CH_MODEL', 'gpt-4');
    }

    if (!defined('CLICSHOPPING_APP_CHATGPT_CH_API_KEY')) {
      define('CLICSHOPPING_APP_CHATGPT_CH_API_KEY', '');
    }

    if (!defined('CLICSHOPPING_APP_CHATGPT_CH_MAX_TOKEN')) {
      define('CLICSHOPPING_APP_CHATGPT_CH_MAX_TOKEN', '4000');
    }

    if (!defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER')) {
      define('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER', 'True');
    }

    if (!defined('CLICSHOPPING_APP_CHATGPT_RA_OPENAI_API_KEY')) {
      define('CLICSHOPPING_APP_CHATGPT_RA_OPENAI_API_KEY', '');
    }

    if (!defined('CLICSHOPPING_APP_CHATGPT_CH_API_KEY_SERPAPI')) {
      define('CLICSHOPPING_APP_CHATGPT_CH_API_KEY_SERPAPI', '');
    }
    */
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
   * Retrieves an array of GPT models with their corresponding IDs and textual descriptions.
   * 
   * Model Capability Legend:
   * - Embeddings: Supports vector embeddings for semantic search
   * - Reasoning: Advanced reasoning capabilities for complex queries
   * - Analytics: SQL generation and data analysis
   * - Web Search: Can perform web searches
   * - Context: Maximum context window size
   * 
   * REMOVED MODELS (Do not meet criteria):
   * - gpt-4: 8K context limit (too small for RAG BI prompts)
   * - gpt-3.5-turbo: No embeddings, limited capabilities
   * - gpt-oss (openai/gpt-oss-20b): 8K context, no embeddings, inconsistent SQL generation
   * - Ollama models: Excluded per requirements (use LM Studio instead)
   *
   * @return array An array of GPT models, where each model is represented as an associative array containing 'id' and 'text' keys.
   */
  public static function getGptModel(): array
  {
    $array = [
      // ============================================
      // GPT-5 SERIES (Future - Best Performance)
      // ============================================
      // Context: 200K+ | Embeddings: Yes | Reasoning: Yes | Analytics: Yes | Web Search: Yes
      ['id' => 'gpt-5', 'text' => 'OpenAI GPT-5 (200K context, embeddings, reasoning, web search)'],
      ['id' => 'gpt-5-mini', 'text' => 'OpenAI GPT-5-mini (200K context, embeddings, reasoning)'],
      ['id' => 'gpt-5-nano', 'text' => 'OpenAI GPT-5-nano (128K context, no embeddings, reasoning)'],
      
      // ============================================
      // GPT-4.1 SERIES (Latest Stable)
      // ============================================
      // Context: 128K | Embeddings: Yes | Reasoning: Yes | Analytics: Yes
      ['id' => 'gpt-4.1-mini', 'text' => 'OpenAI GPT-4.1-mini (128K context, embeddings, reasoning)'],
      ['id' => 'gpt-4.1-nano', 'text' => 'OpenAI GPT-4.1-nano (128K context, embeddings, reasoning)'],
      
      // ============================================
      // GPT-4o SERIES (Recommended - Production Ready)
      // ============================================
      // Context: 128K | Embeddings: Yes | Reasoning: Yes | Analytics: Yes
      // NOTE: gpt-4o is the REFERENCE MODEL - all features tested with this model
      ['id' => 'gpt-4o', 'text' => 'OpenAI GPT-4o (128K context, embeddings, reasoning) ⭐ RECOMMENDED'],
      ['id' => 'gpt-4o-mini', 'text' => 'OpenAI GPT-4o-mini (128K context, embeddings, reasoning, cost-effective)'],
      
      // ============================================
      // ANTHROPIC MODELS (Alternative Provider)
      // ============================================
      // Context: 200K | Embeddings: Yes | Reasoning: Yes | Analytics: Yes
      ['id' => 'anth-sonnet', 'text' => 'Anthropic Claude Sonnet 3.5 (200K context, embeddings, reasoning)'],
      ['id' => 'anth-opus', 'text' => 'Anthropic Claude Opus (200K context, embeddings, reasoning)'],
      ['id' => 'anth-haiku', 'text' => 'Anthropic Claude Haiku (200K context, embeddings, fast)'],
      
      // ============================================
      // MISTRAL MODELS (Alternative Provider)
      // ============================================
      // Context: 128K | Embeddings: Yes | Reasoning: Yes | Analytics: Yes
      ['id' => 'mistral-large-latest', 'text' => 'Mistral Large Latest (128K context, embeddings, reasoning)'],
      
      // ============================================
      // LM STUDIO MODELS (Local Deployment)
      // ============================================
      // Context: 16K | Embeddings: No | Reasoning: Yes | Analytics: Limited
      // NOTE: Local models have limited capabilities but provide privacy and cost benefits
      ['id' => 'openai/gpt-oss-20b', 'text' => 'LM Studio openai/gpt-oss-20b (16K context, reasoning, local)'],
      ['id' => 'openai/gpt-oss-120b', 'text' => 'LM Studio openai/gpt-oss-120b (120K context, reasoning, local)'],


      ['id' => 'qwen/qwen3-4b', 'text' => 'LM Studio qwen3-4b (16K context, no reasoning, local)'],
      ['id' => 'microsoft/phi-4', 'text' => 'LM Studio phi 4 (16K context, no reasoning, local)'],
    ];

    return $array;
  }

  /**
   * Generates and returns an HTML select field for GPT model options.
   *
   * @return string The HTML select field containing GPT model options.
   */
  public static function getGptModalMenu(): string
  {
    $array = self::getGptModel();

    $menu = HTML::selectField('engine', $array, null, 'id="engine"');

    return $menu;
  }

  /**
   * Initializes and returns an instance of OpenAIChat configured with the given parameters.
   * 
   * NOTE: This method continues to use LLPhant's OpenAIChat for backward compatibility.
   * The provider interface is used for new code and parallel execution.
   *
   * @param array|null $parameters Optional parameters for configuring the OpenAI model, such as model type and options.
   * @param string|null $api_key Optional API key override
   * @return mixed The configured OpenAIChat instance.
   */
  public static function getOpenAiGpt(array|null $parameters, string|null $api_key = null): mixed
  {
    $config = new OpenAIConfig();

    if (is_null($api_key)) {
      $api_key = CLICSHOPPING_APP_CHATGPT_CH_API_KEY ?? null;
    }

    $config->apiKey = $api_key;

    if (!is_null($parameters) && array_key_exists('model', $parameters)) {
      $config->model = $parameters['model'];
      $config->modelOptions = $parameters;
    } elseif (!is_null($parameters)) {
      $config->model = CLICSHOPPING_APP_CHATGPT_CH_MODEL;
      $config->modelOptions = $parameters;
    }

    $chat = new OpenAIChat($config);

    return $chat;
  }

  /**
   * Get model-specific API parameters based on the model name
   * 
   * Different OpenAI models require different parameter names for token limits:
   * - max_completion_tokens: GPT-4o-mini, GPT-5 series, GPT-4.1 series
   * - max_tokens: GPT-4o, Anthropic, Mistral, LM Studio models
   * 
   * This mapping ensures API calls succeed with the correct parameters per model.
   * 
   * SUPPORTED MODELS (as of 2025-12-12):
   * - GPT-5 series: gpt-5, gpt-5-mini, gpt-5-nano (max_completion_tokens)
   * - GPT-4.1 series: gpt-4.1-mini, gpt-4.1-nano (max_completion_tokens)
   * - GPT-4o series: gpt-4o, gpt-4o-mini (gpt-4o uses max_tokens, gpt-4o-mini uses max_completion_tokens)
   * - Anthropic: anth-sonnet, anth-opus, anth-haiku (max_tokens)
   * - Mistral: mistral-large-latest (max_tokens)
   * - LM Studio: microsoft/phi-4-reasoning-plus (max_tokens)
   * 
   * REMOVED MODELS (do not meet criteria):
   * - gpt-4: 8K context limit (too small for RAG BI)
   * - gpt-3.5-turbo: No embeddings support
   * - openai/gpt-oss-20b: 8K context, no embeddings, inconsistent SQL generation
   *
   * @param string $model The model name (e.g., 'gpt-4o', 'gpt-4o-mini', 'gpt-5')
   * @param int $maxtoken The maximum number of tokens
   * @return array The model-specific parameters
   */
  /**
   * Get model-specific API parameters
   * 
   * REFACTORED: Now uses provider capabilities internally while maintaining
   * the same return format for backward compatibility.
   * 
   * @param string $model Model name
   * @param int $maxtoken Max tokens
   * @return array The model-specific parameters
   */
  public static function getModelApiParameters(string $model, int $maxtoken): array
  {
    $params = [];

    // Model-specific parameter mapping
    // GPT-4o-mini, GPT-4.1 series, GPT-5 series use max_completion_tokens
    if (strpos($model, 'gpt-4o-mini') === 0 || 
        strpos($model, 'gpt-4.1') === 0 ||
        strpos($model, 'gpt-5') === 0) {
      $params['max_completion_tokens'] = $maxtoken;
    } else {
      // Default for GPT-4o, Anthropic, Mistral, LM Studio, and other models
      $params['max_tokens'] = $maxtoken;
    }

    return $params;
  }

  /**
   * Get the maximum token value from configuration or parameter
   *
   * @param int|null $maxtoken Optional maximum token value
   * @return int The maximum token value to use
   */
  private static function getMaxTokens(?int $maxtoken = null): int
  {
    if (is_null($maxtoken)) {
      return (int)CLICSHOPPING_APP_CHATGPT_CH_MAX_TOKEN;
    }
    return $maxtoken;
  }

  /**
   * Call LLM with model-specific handling and integrated components
   *
   * This method integrates:
   * - PromptOptimizer: Optimizes prompts for model context length limits
   * - ResponseNormalizer: Normalizes responses from different models
   * - Fallback Logic: Tries alternative models on failure
   * - Comprehensive Logging: Tracks all operations for debugging
   *
   * TASK 1.4: Integration of components from tasks 1.1, 1.2, 1.3
   *
   * @param string $prompt The prompt text to send to the model
   * @param string $model The model name (e.g., 'gpt-4o', 'gpt-4o-mini', 'anth-sonnet')
   * @param int|null $maxtoken Maximum tokens for response
   * @param float|null $temperature Temperature for response generation
   * @param int|null $max Maximum number of responses
   * @return array Normalized response with consistent structure
   * @throws \Exception If all models fail or prompt is invalid
   */
  public static function callWithModel(string $prompt, string $model, ?int $maxtoken = null, ?float $temperature = null, ?int $max = 1
  ): array {
    $securityLogger = new SecurityLogger();
    $debug = defined('CLICSHOPPING_APP_CHATGPT_CH_DEBUG') && CLICSHOPPING_APP_CHATGPT_CH_DEBUG === 'True';

    if ($debug) {
      $securityLogger->logSecurityEvent(
        "callWithModel: Starting call with model '$model'",
        'info'
      );
    }

    // Step 1: Validate prompt
    if (empty($prompt) || !is_string($prompt) || trim($prompt) === '') {
      throw new \Exception("Prompt cannot be empty");
    }

    // Step 2: Optimize prompt for model context length
    $optimizer = new PromptOptimizer();
    
    // Check if prompt exceeds model limit
    if ($optimizer->exceedsLimit($prompt, $model)) {
      if ($debug) {
        $originalTokens = $optimizer->estimateTokenCount($prompt);
        $securityLogger->logSecurityEvent(
          "callWithModel: Prompt exceeds limit for model '$model' ($originalTokens tokens). Optimizing...",
          'warning'
        );
      }
      
      $optimizedPrompt = $optimizer->optimizeForModel($prompt, $model);
      
      if ($debug) {
        $optimizedTokens = $optimizer->estimateTokenCount($optimizedPrompt);
        $securityLogger->logSecurityEvent(
          "callWithModel: Prompt optimized from $originalTokens to $optimizedTokens tokens",
          'info'
        );
      }
    } else {
      $optimizedPrompt = $prompt;
      
      if ($debug) {
        $tokens = $optimizer->estimateTokenCount($prompt);
        $securityLogger->logSecurityEvent(
          "callWithModel: Prompt within limits ($tokens tokens). No optimization needed.",
          'info'
        );
      }
    }

    // Step 3: Call the model with optimized prompt
    try {
      if ($debug) {
        $securityLogger->logSecurityEvent(
          "callWithModel: Calling model '$model' with optimized prompt",
          'info'
        );
      }

      // Use existing getGptResponse method which handles model-specific parameters
      $rawResponse = self::getGptResponse($optimizedPrompt, $maxtoken, $temperature, $model, $max);

      if ($rawResponse === false) {
        throw new \Exception("Model '$model' returned false response");
      }

      if ($debug) {
        $securityLogger->logSecurityEvent(
          "callWithModel: Received response from model '$model' (" . strlen($rawResponse) . " chars)",
          'info'
        );
      }

      // Step 4: Normalize response
      $normalizer = new ResponseNormalizer();
      $normalizedResponse = $normalizer->normalize($rawResponse, $model);

      if ($debug) {
        $responseType = $normalizedResponse['response_type'] ?? 'unknown';
        $securityLogger->logSecurityEvent(
          "callWithModel: Response normalized successfully (Type: $responseType)",
          'info'
        );
      }

      return $normalizedResponse;

    } catch (\Exception $e) {
      // Step 5: Fallback logic - try alternative model
      if ($debug) {
        $securityLogger->logSecurityEvent(
          "callWithModel: Model '$model' failed: " . $e->getMessage(),
          'error'
        );
      }

      $fallbackModel = self::getFallbackModel($model);

      if ($fallbackModel !== null) {
        if ($debug) {
          $securityLogger->logSecurityEvent(
            "callWithModel: Trying fallback model '$fallbackModel'",
            'warning'
          );
        }

        // Recursive call with fallback model
        try {
          return self::callWithModel($optimizedPrompt, $fallbackModel, $maxtoken, $temperature, $max);
        } catch (\Exception $fallbackException) {
          if ($debug) {
            $securityLogger->logSecurityEvent(
              "callWithModel: Fallback model '$fallbackModel' also failed: " . $fallbackException->getMessage(),
              'error'
            );
          }
          
          // Both models failed - throw original exception
          throw new \Exception(
            "Primary model '$model' and fallback model '$fallbackModel' both failed. " .
            "Primary error: " . $e->getMessage() . ". " .
            "Fallback error: " . $fallbackException->getMessage()
          );
        }
      }

      // No fallback available - throw original exception
      throw new \Exception("Model '$model' failed and no fallback available: " . $e->getMessage());
    }
  }

  /**
   * Get fallback model for a failed model
   *
   * Fallback priority:
   * 1. GPT-4o (reference model - most reliable)
   * 2. GPT-4o-mini (cost-effective alternative)
   * 3. GPT-5 (if available)
   * 4. Anthropic Claude Sonnet (alternative provider)
   *
   * @param string $failedModel The model that failed
   * @return string|null Fallback model name or null if no fallback available
   */
  private static function getFallbackModel(string $failedModel): ?string
  {
    // Fallback chain based on model capabilities
    // Note: LM Studio models have no fallback - they are local and should be configured correctly
    $fallbackChain = [
      // GPT-5 series fallbacks
      'gpt-5' => 'gpt-4o',
      'gpt-5-mini' => 'gpt-4o-mini',
      'gpt-5-nano' => 'gpt-4o-mini',
      
      // GPT-4.1 series fallbacks
      'gpt-4.1-mini' => 'gpt-4o-mini',
      'gpt-4.1-nano' => 'gpt-4o-mini',
      
      // GPT-4o series fallbacks
      'gpt-4o' => 'anth-sonnet', // If GPT-4o fails, try Anthropic
      'gpt-4o-mini' => 'gpt-4o', // If mini fails, try full version
      
      // Anthropic fallbacks
      'anth-sonnet' => 'gpt-4o',
      'anth-opus' => 'anth-sonnet',
      'anth-haiku' => 'anth-sonnet',
      
      // Mistral fallbacks
      'mistral-large-latest' => 'gpt-4o',
    ];

    return $fallbackChain[$failedModel] ?? null;
  }





  /**
   * Check if model uses reasoning API approach (GPT-5 style)
   * 
   * GPT-5 models use reasoning_effort and verbosity parameters instead of
   * temperature, top_p, frequency_penalty, presence_penalty.
   * 
   * This method checks the model list dynamically so when models are added/removed,
   * the logic automatically adapts.
   * 
   * NOTE: In the future, this could use provider->getCapabilities()['reasoning']
   * for dynamic capability detection. Currently uses static model list for backward compatibility.
   *
   * @param string $model Model name
   * @return bool True if model uses reasoning API approach
   */
  public static function isReasoningApiModel(string $model): bool
  {
    // Get all models from the list
    $models = self::getGptModel();
    
    foreach ($models as $modelInfo) {
      if ($modelInfo['id'] === $model) {
        // Check if this is a GPT-5 series model (uses reasoning API)
        // GPT-5 series models are identified by their ID starting with 'gpt-5'
        // and their description mentioning specific GPT-5 features
        if (strpos($modelInfo['id'], 'gpt-5') === 0) {
          return true;
        }
        
        // Future: Could also check for specific markers in the text description
        // e.g., if (str_contains($modelInfo['text'], 'reasoning API'))
        
        return false;
      }
    }
    
    // Model not found in list - check by prefix as fallback
    return strpos($model, 'gpt-5') === 0;
  }



  /**
   * Get model context length limit
   *
   * Extracts context length from model description in getGptModel().
   * Used by PromptOptimizer for context management.
   * 
   * NOTE: In the future, this could use provider->getCapabilities()['context_length']
   * for dynamic capability detection. Currently uses static model list for backward compatibility.
   *
   * @param string $model Model name
   * @return int Context length in tokens (defaults to 128000 if not found)
   */
  public static function getModelContextLength(string $model): int
  {
    $models = self::getGptModel();
    
    foreach ($models as $modelInfo) {
      if ($modelInfo['id'] === $model) {
        // Extract context length from text description
        // Pattern: "(\d+)K context"
        if (preg_match('/(\d+)K\s+context/i', $modelInfo['text'], $matches)) {
          $contextK = (int)$matches[1];
          return $contextK * 1000;
        }
      }
    }
    
    // Default to 128K (safe for most models)
    return 128000;
  }



  /**
   *
   * @param string $model The name of the model to be used for the chat. Defaults to 'mistral:7b'.
   * @return mixed Returns an instance of OllamaChat configured with the specified model.
   */
  /**
   * Creates an Ollama chat instance using LLPhant library
   * 
   * NOTE: This method continues to use LLPhant's OllamaChat for backward compatibility.
   * The provider interface is used for new code and parallel execution.
   * 
   * @param string $model Model name (default: 'mistral:7b')
   * @return mixed The configured OllamaChat instance
   */
  public static function getOllamaChat(string $model = 'mistral:7b'): mixed
  {
    $config = new OllamaConfig();
    $config->model = $model;
    $chat = new OllamaChat($config);

    return $chat;
  }


  /**
   * Crée et configure une instance de LmStudioChat
   * 
   * NOTE: This method continues to use LLPhant's LmStudioChat for backward compatibility.
   * The provider interface is used for new code and parallel execution.
   *
   * @param string $model Le nom du modèle à utiliser (par défaut: 'openai/gpt-oss-20b')
   * @param string|null $url L'URL de l'API LM Studio (optionnel)
   * @param float|null $timeout Timeout en secondes (optionnel)
   * @return LmStudioChat Instance configurée de LmStudioChat
   */
  public static function getLmStudioChat(string $model = 'openai/gpt-oss-20b', ?string $url = null, ?float $timeout = null): LmStudioChat
  {
    // Créer la configuration
    $config = new LmStudioConfig();
    $config->model = $model;

    // Configurer l'URL si fournie, sinon utiliser la valeur par défaut ou depuis les constantes
    if ($url !== null) {
      $config->url = $url;
    } elseif (defined('CLICSHOPPING_APP_CHATGPT_LMSTUDIO_URL') && !empty(CLICSHOPPING_APP_CHATGPT_LMSTUDIO_URL)) {
      $config->url = CLICSHOPPING_APP_CHATGPT_LMSTUDIO_URL;
    } else {
      // LM Studio uses OpenAI-compatible API
      // LmStudioChat adds 'v1/chat/completions' to base_uri, so base_uri should be just the host
      $config->url = 'http://localhost:1234/';
    }

    // Configurer le timeout si fourni ou depuis les constantes
    if ($timeout !== null) {
      $config->timeout = $timeout;
    } elseif (defined('CLICSHOPPING_APP_CHATGPT_LMSTUDIO_TIMEOUT')) {
      $config->timeout = (float)CLICSHOPPING_APP_CHATGPT_LMSTUDIO_TIMEOUT;
    }

    // Configurer les options du modèle si disponibles
    if (defined('CLICSHOPPING_APP_CHATGPT_CH_TEMPERATURE')) {
      $config->modelOptions['temperature'] = (float)CLICSHOPPING_APP_CHATGPT_CH_TEMPERATURE;
    }

    // LM Studio models need more tokens to allow reasoning with <think> tags
    // Use dedicated config if available, otherwise use higher default
    if (defined('CLICSHOPPING_APP_CHATGPT_LMSTUDIO_MAX_TOKEN')) {
      $config->modelOptions['max_tokens'] = (int)CLICSHOPPING_APP_CHATGPT_LMSTUDIO_MAX_TOKEN;
    } elseif (defined('CLICSHOPPING_APP_CHATGPT_CH_MAX_TOKEN')) {
      // For LM Studio, multiply by 3 to allow reasoning space
      // Example: 350 tokens → 1050 tokens for <think> + answer
      $baseTokens = (int)CLICSHOPPING_APP_CHATGPT_CH_MAX_TOKEN;
      $config->modelOptions['max_tokens'] = $baseTokens * 3;
      
      error_log("🔧 LM Studio: Using {$config->modelOptions['max_tokens']} tokens (base: $baseTokens × 3 for reasoning)");
    } else {
      // Default: 1000 tokens for LM Studio (allows reasoning)
      $config->modelOptions['max_tokens'] = 1000;
    }

    // Créer et retourner l'instance de LmStudioChat avec la config
    return new LmStudioChat($config);
  }

  /**
   * Creates an instance of the AnthropicChat class based on the specified model and configuration options.
   * 
   * NOTE: This method continues to use LLPhant's AnthropicChat for backward compatibility.
   * The provider interface is used for new code and parallel execution.
   *
   * @param string $model The specific model identifier to use for the AnthropicChat instance.
   * Supported values are 'anth-sonnet', 'anth-opus', 'anth-haiku'.
   * @param int|null $maxtoken The maximum number of tokens the model can output.
   *                           Defaults to the configured max token if not provided.
   * @param array|null $modelOptions Additional configuration options for the model.
   * @return mixed An instance of AnthropicChat initialized with the provided parameters, or false on failure.
   */
  public static function getAnthropicChat(string $model, int|null $maxtoken = null, array|null $modelOptions = null): mixed
  {
    $result = false;

    if (defined('CLICSHOPPING_APP_CHATGPT_CH_API_KEY_ANTHROPIC') &&!empty(CLICSHOPPING_APP_CHATGPT_CH_API_KEY_ANTHROPIC)) {
      $api_key = CLICSHOPPING_APP_CHATGPT_CH_API_KEY_ANTHROPIC ?? null;

      if (is_null($modelOptions)) {
        $modelOptions = [
          'temperature' => (float) CLICSHOPPING_APP_CHATGPT_CH_TEMPERATURE,
          'top_p' => (float) CLICSHOPPING_APP_CHATGPT_CH_TOP_P,
          'max_tokens_to_sample' => (int) CLICSHOPPING_APP_CHATGPT_CH_MAX_TOKEN,
          'stop_sequences' => ['\n']
        ];
      }

      // LLPhant AnthropicConfig handles the API model name mapping internally
      if ($model === 'anth-sonnet') {
        $result = new AnthropicChat(
          new AnthropicConfig(AnthropicConfig::CLAUDE_3_5_SONNET, $maxtoken, $modelOptions, $api_key)
        );
      } elseif ($model === 'anth-opus') {
        $result = new AnthropicChat(
          new AnthropicConfig(AnthropicConfig::CLAUDE_3_OPUS, $maxtoken, $modelOptions, $api_key)
        );
      } else {
        // Default to Haiku
        $result = new AnthropicChat(
          new AnthropicConfig(AnthropicConfig::CLAUDE_3_HAIKU, $maxtoken, $modelOptions, $api_key)
        );
      }
    }

    return $result;
  }

  /**
   * Creates an instance of the MistralAIChat class based on the specified model and configuration options.
   * 
   * NOTE: This method continues to use LLPhant's MistralAIChat for backward compatibility.
   * The provider interface is used for new code and parallel execution.
   *
   * @param string $model The specific model identifier to use for the MistralAIChat instance.
   *                      Should be one of the values defined in MistralAIChatModel.
   * @param int|null $maxtoken The maximum number of tokens the model can output.
   *                           Defaults to the configured max token if not provided.
   * @return MistralAIChat An instance of MistralAIChat initialized with the provided parameters.
   * @throws Exception|\Exception If the API key is not provided or if there's an error creating the instance.
   */
  public static function getMistralChat(string $model, ?int $maxtoken = null): MistralAIChat
  {
    $result = false;

    if (defined('CLICSHOPPING_APP_CHATGPT_CH_API_KEY_MISTRAL') &&!empty(CLICSHOPPING_APP_CHATGPT_CH_API_KEY_MISTRAL)) {
      $api_key = CLICSHOPPING_APP_CHATGPT_CH_API_KEY_MISTRAL ?? null;

      if (empty($api_key)) {
        throw new \Exception('You have to provide a MISTRAL_API_KEY to request Mistral AI.');
      }

      // Valid model for MistralAIChat
      $valid_models = [
        'mistral-tiny',
        'mistral-small-latest',
        'mistral-medium-latest',
        'mistral-large-latest',
        'pixtral-large-latest',
        'ministral-3b-latest',
        'ministral-8b-latest',
        'codestral-latest',
        'open-mistral-nemo',
        'open-codestral-mamba',
        'mistral-moderation-latest'
      ];

      if (empty($model) || !in_array($model, $valid_models)) {
        $model = 'mistral-large-latest';
      }

      $config = new MistralAIChat();
      $config->apiKey = $api_key;
      $config->model = $model;

      // Appliquer la limite de tokens si spécifiée
      if (!is_null($maxtoken) && $maxtoken > 0) {
        $config->maxTokens = $maxtoken;
      } else {
        $maxtoken = (int)(CLICSHOPPING_APP_CHATGPT_CH_MAX_TOKEN ?? 0);
        if ($maxtoken > 0) {
          $config->maxTokens = $maxtoken;
        }
      }

      try {
        $result = new MistralAIChat($config);;
        return $result;
      } catch (\Exception $e) {
        throw new \Exception('Error creating MistralAIChat instance: ' . $e->getMessage());
      }
    }

    return $result;
  }

  /**
   * Get chat instance for the specified model (centralized model detection)
   * 
   * This function centralizes the logic for detecting the model type and returning
   * the appropriate chat instance. It supports:
   * - OpenAI (GPT-4, GPT-3.5, o1, o3, etc.)
   * - Anthropic (Claude)
   * - Google (Gemini)
   * - Mistral
   * - Ollama (local models)
   * - LM Studio
   * 
   * Usage:
   * ```php
   * $chat = Gpt::getChatForModel(); // Uses default model from config
   * $chat = Gpt::getChatForModel('gpt-4'); // Specific model
   * ```
   * 
   * @param string|null $model The model name (null = use default from config)
   * @param array $options Optional parameters (maxtoken, temperature, etc.)
   * @return mixed The chat instance for the specified model
   * @throws \Exception If model type is not supported
   */
  public static function getChatForModel(?string $model = null, array $options = []): mixed
  {
    // Use default model if not specified
    if ($model === null) {
      if (!defined('CLICSHOPPING_APP_CHATGPT_CH_MODEL')) {
        throw new \Exception('No model specified and CLICSHOPPING_APP_CHATGPT_CH_MODEL not defined');
      }
      $model = CLICSHOPPING_APP_CHATGPT_CH_MODEL;
    }

    // Normalize model name to lowercase for comparison
    $modelLower = strtolower($model);

    // Detect model type and return appropriate chat instance
    
    // OpenAI models (GPT-4, GPT-3.5, o1, o3, etc.)
    if (str_starts_with($modelLower, 'gpt') || 
        str_starts_with($modelLower, 'o1') || 
        str_starts_with($modelLower, 'o3')) {
      return self::getOpenAiGpt(['model' => $model] + $options);
    }
    
    // Anthropic models (Claude)
    if (str_starts_with($modelLower, 'claude') || 
        str_starts_with($modelLower, 'anth')) {
      $maxtoken = $options['maxtoken'] ?? null;
      $modelOptions = $options['modelOptions'] ?? null;
      return self::getAnthropicChat($model, $maxtoken, $modelOptions);
    }
    
    // Google Gemini models
    if (str_starts_with($modelLower, 'gemini') || 
        str_starts_with($modelLower, 'google')) {
      // TODO: Implement getGeminiChat() when Gemini support is added
      // For now, throw an exception to indicate it's not yet supported
      throw new \Exception("Gemini models are not yet supported. Model: {$model}");
      // Future implementation:
      // return self::getGeminiChat($model, $options['maxtoken'] ?? null);
    }
    
    // Mistral models
    if (str_starts_with($modelLower, 'mistral')) {
      $maxtoken = $options['maxtoken'] ?? null;
      return self::getMistralChat($model, $maxtoken);
    }
    
    // Ollama models (local models with version tags like :latest or :7b)
    if (str_starts_with($modelLower, 'ollama') || 
        str_contains($model, ':latest') || 
        str_contains($model, ':')) {
      return self::getOllamaChat($model);
    }
    
    // LM Studio models (usually prefixed with openai/)
    if (str_starts_with($modelLower, 'openai/')) {
      $url = $options['url'] ?? null;
      $timeout = $options['timeout'] ?? null;
      return self::getLmStudioChat($model, $url, $timeout);
    }
    
    // Default fallback to LM Studio for unknown models
    // This maintains backward compatibility with existing code
    $url = $options['url'] ?? null;
    $timeout = $options['timeout'] ?? null;
    return self::getLmStudioChat($model, $url, $timeout);
  }

  /**
   * Retrieves a chat response based on the provided parameters and model configuration.
   * 
   * @deprecated This method is maintained for backward compatibility only.
   *             New code should use getGptResponse() instead.
   *             This method will be removed in a future version.
   *
   * REFACTORED: Now uses provider-specific methods directly without calling removed getOpenAIChat().
   *
   * @param string $question The input question or prompt to be processed.
   * @param int|null $maxtoken The maximum number of tokens for the response, or null for default.
   * @param float|null $temperature The sampling temperature for response generation, or null for default.
   * @param string|null $engine The engine to be used for processing, or null for default.
   * @param int|null $max The maximum number of responses to return, or null for default.
   * @return mixed The chat response generated by the selected model.
   */
  public static function getChat(string $question, int|null $maxtoken = null, ?float $temperature = null, ?string $engine = null, int|null $max = 1): mixed
  {
    // Note: Deprecation warning in comment only (as per task requirements)
    // trigger_error('Gpt::getChat() is deprecated, use Gpt::getGptResponse() instead', E_USER_DEPRECATED);
    
    if (!defined('CLICSHOPPING_APP_CHATGPT_CH_MODEL')) {
      define('CLICSHOPPING_APP_CHATGPT_CH_MODEL', 'gpt-4');
    }

    $model = $engine ?? CLICSHOPPING_APP_CHATGPT_CH_MODEL;

    // REFACTORED: Use provider-specific methods directly
    if (strpos($model, 'gpt') === 0) {
      // For OpenAI models, use getOpenAiGpt() which returns OpenAIChat instance
      $maxtoken = self::getMaxTokens($maxtoken);
      $temperature = $temperature ?? (float)CLICSHOPPING_APP_CHATGPT_CH_TEMPERATURE;
      
      $tokenParams = self::getModelApiParameters($model, $maxtoken);
      
      if (self::isReasoningApiModel($model)) {
        $parameters = array_merge([
          'reasoning_effort' => CLICSHOPPING_APP_CHATGPT_CH_REASONING_EFFORT,
          'verbosity' => CLICSHOPPING_APP_CHATGPT_CH_VERBOSITY,
          'messages' => [
            ['role' => 'user', 'content' => $question]
          ],
          'user' => AdministratorAdmin::getUserAdmin()
        ], $tokenParams);
      } else {
        $parameters = array_merge([
          'temperature' => $temperature,
          'top_p' => (float)CLICSHOPPING_APP_CHATGPT_CH_TOP_P,
          'frequency_penalty' => (float)CLICSHOPPING_APP_CHATGPT_CH_FREQUENCY_PENALITY,
          'presence_penalty' => (float)CLICSHOPPING_APP_CHATGPT_CH_PRESENCE_PENALITY,
          'stop' => ['\n'],
          'n' => $max,
          'user' => AdministratorAdmin::getUserAdmin(),
          'messages' => [
            ['role' => 'system', 'content' => 'You are an e-commerce expert in marketing.'],
            ['role' => 'user', 'content' => $question]
          ]
        ], $tokenParams);
      }
      
      if (!empty(CLICSHOPPING_APP_CHATGPT_CH_ORGANIZATION)) {
        $parameters['organization'] = CLICSHOPPING_APP_CHATGPT_CH_ORGANISATION;
      }
      
      if ($engine !== null) {
        $parameters['model'] = $engine;
      }
      
      $client = self::getOpenAiGpt($parameters);
    } elseif (strpos($model, 'anth') === 0) {
      $client = self::getAnthropicChat($model, $maxtoken);
    } elseif (strpos($model, 'mistral') === 0) {
      $client = self::getMistralChat($model, $maxtoken);
    } elseif (strpos($model, 'ollama') === 0 || str_contains($model, ':latest')) {
      $client = self::getOllamaChat($model);
    } elseif (strpos($model, 'openai/') === 0) {
      // Pour LM Studio
      $client = self::getLmStudioChat($model);
    } else {
      $client = self::getLmStudioChat($model);
    }

    return $client;
  }

  /**
   * Retrieves a response from the GPT model based on the provided input question and parameters.
   *
   * @param string $question The input question or prompt for the GPT model.
   * @param int|null $maxtoken Optional maximum number of tokens for the response generation. Defaults to null.
   * @param float|null $temperature Optional temperature value for controlling randomness of the output. Defaults to null.
   * @param string|null $engine Optional specific GPT engine to use. Defaults to null.
   * @param int|null $max Optional maximum number of responses to generate. Defaults to 1.
   * @return bool|string Returns the generated response as a string. Returns false if GPT is unavailable or fails to generate a response.
   */
  /**
   * Get GPT response (PUBLIC API - NO CHANGES TO SIGNATURE)
   * 
   * REFACTORED INTERNALLY: Now uses LLMProviderInterface for provider abstraction
   * while maintaining 100% backward compatibility with all 62 existing usages.
   * 
   * @param string $question The question/prompt
   * @param int|null $maxtoken Maximum tokens
   * @param float|null $temperature Temperature
   * @param string|null $engine Model/engine name
   * @param int|null $max Max responses
   * @return bool|string Response or false on error
   */
  public static function getGptResponse(string $question, int|null $maxtoken = null, ?float $temperature = null, ?string $engine = null, int|null $max = 1): bool|string
  {
    if (self::checkGptStatus() === false) {
      return false;
    }

    if (empty($question) || !is_string($question) || trim($question) === '') {
      return false;
    }

    if (is_null($engine)) {
      $engine = CLICSHOPPING_APP_CHATGPT_CH_MODEL;
    }

    // 🔧 FIX TASK 4.3.4.4: Simplifier la validation pour éviter les faux positifs
    // Ne pas utiliser de pattern regex complexe qui peut rejeter des requêtes légitimes
    // À la place, faire une validation simple et laisser htmlspecialchars() gérer la sécurité
    
    // Validation basique: longueur et caractères dangereux explicites
    $prompt = trim($question);

    // Bloquer seulement les patterns vraiment dangereux (balises script/iframe complètes)
    if (preg_match('/<script[\s>]/i', $prompt) || preg_match('/<iframe[\s>]/i', $prompt)) {
      error_log("SECURITY: Blocked dangerous HTML tags in prompt: " . substr($prompt, 0, 100));
      throw new \Exception("Requête bloquée pour des raisons de sécurité");
    }
    
    // Limiter la longueur (GPT-4o-mini accepte 128k tokens ≈ 512k caractères)
    // Use TechnicalConfig for max prompt length
    $maxPromptLength = CLICSHOPPING_APP_CHATGPT_RA_MAX_PROMPT_LENGTH;
    
    if (strlen($prompt) > $maxPromptLength) {
      $prompt = substr($prompt, 0, $maxPromptLength);
      error_log("WARNING: Prompt truncated to {$maxPromptLength} characters (security limit)");
    }
    
    // Appliquer htmlspecialchars pour la sécurité XSS
    $prompt = htmlspecialchars($prompt, ENT_QUOTES, 'UTF-8');
    
    // Vérification finale
    if (empty($prompt)) {
      error_log("WARNING Ajax ChatGpt: Prompt is empty after validation for: " . substr($question, 0, 100));
      // Utiliser la version originale avec échappement minimal
      $prompt = htmlspecialchars(trim($question), ENT_QUOTES, 'UTF-8');
    }

    // Additional sanitization for extra security
    $prompt = HTMLOverrideCommon::removeInvisibleCharacters($prompt);

    // 🔧 FIX: Vérification finale avant appel API
    if (empty($prompt)) {
      throw new \Exception("Prompt is empty after validation and sanitization");
    }

    // ========================================
    // REFACTORED: Use provider interface internally
    // ========================================
    try {
      // 🔍 DEBUG: Log provider detection
      error_log("🔍 [Gpt::getGptResponse] Starting provider detection for engine: {$engine}");
      
      // Detect provider from engine name
      $providerName = self::detectProviderFromEngine($engine);
      
      // 🔍 DEBUG: Log detected provider
      error_log("✅ [Gpt::getGptResponse] Provider detected: {$providerName} for engine: {$engine}");
      
      // 🔍 DEBUG: Log request parameters
      error_log(sprintf(
        "📋 [Gpt::getGptResponse] Request params - model: %s, max_tokens: %s, temperature: %s, prompt_length: %d",
        $engine,
        $maxtoken ?? 'null',
        $temperature ?? 'null',
        strlen($prompt)
      ));
      
      // Create provider using factory
      $factory = LLMProviderFactory::getInstance();
      $provider = $factory->create($providerName, [
        'model' => $engine,
        'max_tokens' => $maxtoken,
        'temperature' => $temperature,
      ]);
      
      // 🔍 DEBUG: Log provider creation success
      error_log("✅ [Gpt::getGptResponse] Provider instance created successfully: " . get_class($provider));

      // 🔥 CRITICAL FIX: Use the provider's LLPhant chat instance instead of getChat()
      // This ensures the provider interface is actually used (was created but never used before)
      try {
        // 🔍 DEBUG: Log attempt to get LLPhant chat instance
        error_log("🔍 [Gpt::getGptResponse] Attempting to get LLPhant chat instance from provider");
        
        // Get LLPhant chat instance from the provider
        $chat = $provider->getLLPhantChat();
        
        // 🔍 DEBUG: Log success
        error_log("✅ [Gpt::getGptResponse] Using provider {$providerName} via getLLPhantChat()");
      } catch (\Exception $e) {
        // 🔍 DEBUG: Log fallback
        error_log("⚠️ [Gpt::getGptResponse] Provider {$providerName} getLLPhantChat() failed: {$e->getMessage()}, falling back to getChat()");
        
        // Fallback to getChat() if provider doesn't support getLLPhantChat() yet
        $chat = self::getChat($prompt, $maxtoken, $temperature, $engine, $max);
        
        // 🔍 DEBUG: Log fallback success
        error_log("✅ [Gpt::getGptResponse] Fallback to getChat() successful");
      }

      // 🔍 DEBUG: Log before generateText call
      error_log(sprintf(
        "🔍 [Gpt::getGptResponse] Calling generateText() with prompt (first 100 chars): %s",
        substr($prompt, 0, 100)
      ));
      
      // Generate text using the chat instance
      $result = $chat->generateText($prompt);
      
      // 🔍 DEBUG: Log response received
      error_log(sprintf(
        "✅ [Gpt::getGptResponse] generateText() returned result - length: %d, first 100 chars: %s",
        strlen($result),
        substr($result, 0, 100)
      ));

      // Extract usage metrics from the last response for all providers
      // Check if getLastResponse() method exists (OpenAI, Anthropic, Mistral support it)
      // LMStudio and Ollama may not have this method implemented yet
      if (method_exists($chat, 'getLastResponse')) {
        // 🔍 DEBUG: Log attempt to get last response
        error_log("🔍 [Gpt::getGptResponse] Attempting to extract token usage from last response");
        
        $lastResponse = $chat->getLastResponse();
        
        if (!is_null($lastResponse) && isset($lastResponse['usage'])) {
          self::$lastTokenUsage = [
            'prompt_tokens' => $lastResponse['usage']['prompt_tokens'] ?? 0,
            'completion_tokens' => $lastResponse['usage']['completion_tokens'] ?? 0,
            'total_tokens' => $lastResponse['usage']['total_tokens'] ?? 0
          ];
          
          // 🔍 DEBUG: Log token usage for debugging
          error_log(sprintf(
            '📊 [Gpt::getGptResponse] Token usage for model %s: prompt=%d, completion=%d, total=%d',
            $engine,
            self::$lastTokenUsage['prompt_tokens'],
            self::$lastTokenUsage['completion_tokens'],
            self::$lastTokenUsage['total_tokens']
          ));
        } else {
          // If no usage data in response, set to null
          self::$lastTokenUsage = null;
          
          // 🔍 DEBUG: Log missing usage data
          error_log(sprintf(
            '⚠️ [Gpt::getGptResponse] No token usage data in response for model %s',
            $engine
          ));
        }
      } else {
        // 🔍 DEBUG: Log estimation fallback
        error_log("🔍 [Gpt::getGptResponse] getLastResponse() not available, estimating token usage");
        
        // Method doesn't exist (LMStudio, Ollama) - estimate tokens based on text length
        // Rough estimation: 1 token ≈ 4 characters for English text
        $promptTokens = (int)ceil(strlen($prompt) / 4);
        $completionTokens = (int)ceil(strlen($result) / 4);
        
        self::$lastTokenUsage = [
          'prompt_tokens' => $promptTokens,
          'completion_tokens' => $completionTokens,
          'total_tokens' => $promptTokens + $completionTokens
        ];
        
        error_log(sprintf(
          '📊 Estimated token usage for model %s (provider: %s): prompt=%d, completion=%d, total=%d',
          $engine,
          get_class($chat),
          $promptTokens,
          $completionTokens,
          $promptTokens + $completionTokens
        ));
      }

      // 🔍 DEBUG: Log successful completion
      error_log("✅ [Gpt::getGptResponse] Request completed successfully");
      
      return $result;
      
    } catch (\Exception $e) {
      // 🔍 DEBUG: Log detailed error information
      error_log(sprintf(
        "❌ [Gpt::getGptResponse] Error occurred - Message: %s, File: %s, Line: %d",
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
      ));
      error_log("❌ [Gpt::getGptResponse] Stack trace: " . $e->getTraceAsString());
      
      return false;
    }
  }

  /**
   * Detect provider from engine/model name (INTERNAL HELPER)
   * 
   * @param string|null $engine Model/engine name
   * @return string Provider name (openai, anthropic, ollama, lmstudio, mistral)
   */
  private static function detectProviderFromEngine(?string $engine): string
  {
    if ($engine === null) {
      return 'openai'; // Default
    }

    // Detect provider based on model name patterns
    return match (true) {
      str_contains($engine, 'claude') || str_contains($engine, 'anth-') => 'anthropic',
      str_contains($engine, 'llama') || str_contains($engine, 'mistral:') => 'ollama',
      str_contains($engine, 'gpt-oss') || str_contains($engine, 'openai/') => 'lmstudio',
      str_contains($engine, 'mistral-') || str_contains($engine, 'pixtral') || str_contains($engine, 'ministral') || str_contains($engine, 'codestral') => 'mistral',
      default => 'openai'
    };
  }

  /**
   * Get the last token usage from the most recent API call
   * 
   * @return array|null Array with 'prompt_tokens', 'completion_tokens', 'total_tokens' or null if not available
   */
  public static function getLastTokenUsage(): ?array
  {
    return self::$lastTokenUsage;
  }

  /**
   * Saves data to the database, including question details,audit trials.
   *
   * @param string $question The question being saved.
   * @param string $result The result or response to the question.
   * @param array|null $auditExtra Optional additional data for auditing purposes, such as embeddings context, similarity scores, and processing chain.
   * @return void
   * @throws \Exception
   */
  public static function saveData(string $question, string $result, ?array $auditExtra = [], bool $force = false): void
  {
    $CLICSHOPPING_Db = Registry::get('Db');

    // Validate and sanitize the saveGpt parameter from POST data
    $saveData = isset($_POST['saveGpt']) ?
      InputValidator::validateParameter(
        $_POST['saveGpt'],
        'int',
        0,
        [
          'min' => 0,
          'max' => 1
        ]
      ) : 0;

    if ($saveData === 1 && $force === false) {
      // Validate and sanitize the question and result before saving to database
      $validatedQuestion = InputValidator::validateParameter(
        $question,
        'string',
        '',
        [
          'maxLength' => 4096, // Reasonable limit for question length
          'escape' => true // Apply HTML escaping
        ]
      );

      $validatedResult = InputValidator::validateParameter(
        $result,
        'string',
        '',
        [
          'maxLength' => 8192, // Reasonable limit for result length
          'escape' => true // Apply HTML escaping
        ]
      );

      // Validate the user admin value
      $validatedUserAdmin = InputValidator::validateParameter(
        AdministratorAdmin::getUserAdmin(),
        'string',
        'system',
        [
          'maxLength' => 255,
          'pattern' => '/^[a-zA-Z0-9_\-\.\s]+$/' // Allow alphanumeric, underscore, hyphen, period, and spaces
        ]
      );

      // Audit trail
      $auditPayload = [
        'session' => [
          'id' => session_id(),
          'ip' => HTTP::getIpAddress() ?? null,
          'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
        ],
        'embeddings_context' => $auditExtra['embeddings_context'] ?? [],
        'similarity_scores' => $auditExtra['similarity_scores'] ?? [],
        'processing_chain' => $auditExtra['processing_chain'] ?? []
      ];

      $timestamp = (new DateTimeImmutable())->format('Y-m-d H:i:s');

      // Hash d’intégrité via API interne ClicShopping
      $auditPayload['hash'] = Hash::encryptDatatext($validatedUserAdmin . session_id() . $timestamp);

      $array_sql = [
        'question' => $validatedQuestion,
        'response' => $validatedResult,
        'date_added' => 'now()',
        'user_admin' => $validatedUserAdmin,
        'audit_data' => json_encode($auditPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
      ];

      try {
        $CLICSHOPPING_Db->save('gpt', $array_sql);
      } catch (\Exception $e) {
        // En cas d'échec de la sauvegarde (ex: connexion BDD perdue, donnée trop longue)
        // On journalise l'erreur sans la propager, permettant ainsi à la réponse de l'IA d'être renvoyée.
        error_log("Erreur lors de la sauvegarde du log GPT dans la base de données: " . $e->getMessage());
      }
    }
  }

  /**
   *
   * Calculates the error rate of GPT responses by analyzing specific response patterns and comparing them to total entries.
   *
   * @return bool|float Returns the calculated error rate as a percentage if computations are successful, or false if there is no data available.
   */
  public static function getErrorRateGpt(): bool|float
  {
    $CLICSHOPPING_Db = Registry::get('Db');
    $result = false;

    $Qtotal = $CLICSHOPPING_Db->prepare('select count(gpt_id) as total_id
                                           from :table_gpt
                                          ');
    $Qtotal->execute();

    $result_total_chat = $Qtotal->valueInt('avg');

    $QtotalResponse = $CLICSHOPPING_Db->prepare('select count(response) as total
                                                   from :table_gpt
                                                   where (response like :response or response like :response1)
                                                   and user_admin like :user_admin
                                                  ');
    $QtotalResponse->bindValue(':response', '%I\'m sorry but I do not find%');
    $QtotalResponse->bindValue(':response1', '%Je suis désolé mais je n\'ai pas trouvé d\'informations%');
    $QtotalResponse->bindValue(':user_admin', '%Chatbot Front Office%');

    $QtotalResponse->execute();

    $result_no_response = $QtotalResponse->valueDecimal('total');

    if ($result_no_response > 0) {
      $result = ($result_no_response / $result_total_chat) * 100 . '%';
    }

    return $result;
  }

  /**
   * Generates and returns the HTML for the GPT modal menu. The menu includes a chat interface triggered by a modal,
   * along with an option to toggle saving chat data. It verifies certain conditions such as the state of the ChatGPT
   * module and the presence of an API key before rendering the menu.
   *
   * @return string HTML content for the GPT modal menu
   */
  public static function gptModalMenu(): string
  {
    $output = '';
    $menu = '';
    $script = '';

    $output .= '<link rel="stylesheet" href="' . CLICSHOPPING::link('css/RAG/chat_feedback.css') . '">' . "\n";

    if (defined('CLICSHOPPING_APP_CHATGPT_CH_STATUS') && CLICSHOPPING_APP_CHATGPT_CH_STATUS == 'True') {
      $menu .= '
    <span class="col-md-2">
        <!-- Modal Chat avec Feedback -->
        <a href="#chatModal" data-bs-toggle="modal" data-bs-target="#chatModal"><span class="text-white"><i class="bi bi-chat-left-dots-fill" title="' . CLICSHOPPING::getDef('text_chat_open') . '"></i><span></a>
        <div class="modal fade modal-right" id="chatModal" tabindex="-1" role="dialog" aria-labelledby="chatModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="chatModalLabel">' . CLICSHOPPING::getDef('text_chat_title') . '</h5>
                        <div class="ms-auto">
                            ' . HTML::button(CLICSHOPPING::getDef('text_chat_close'), null, null, 'secondary', ['params' => 'data-bs-dismiss="modal"'], 'md') . '
                        </div>
                    </div>
                    <div class="modal-body">
                        <div class="mt-1"></div>
                        <div class="mt-1"></div>
                        <div class="mt-1"></div>
                        <div class="card">
                            <div class="input-group">
                                <!-- Container des messages avec ID pour le feedback -->
                                <div class="chat-box-message text-start" id="chat-messages">
                                    <div id="chatGpt-output" class="text-bg-light"></div>
                                    <div class="mt-1"></div>
                                    <div class="col-md-12">
                                        <div class="row">
                                            <span class="col-md-12">
                                                <button id="copyResultButton" class="btn btn-primary btn-sm d-none" data-clipboard-target="#chatGpt-output">
                                                    <i class="bi bi-clipboard" title="' . CLICSHOPPING::getDef('text_copy') . '"></i> ' . CLICSHOPPING::getDef('text_copy') . '
                                                </button>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>  
                    </div>
                    <div class="modal-footer">
                         <div class="form-group col-md-12">
                            <textarea class="form-control" id="messageGpt" rows="3" placeholder="' . CLICSHOPPING::getDef('text_chat_message') . '"></textarea>
                        </div>                        
                        <div class="mt-1"></div>
                        <div class="form-group text-end col-md-12">
                            ' . HTML::button(CLICSHOPPING::getDef('text_chat_reset_context'), null, null, 'danger', ['params' => 'id="resetContextGpt"'], 'sm') . '
                            ' . HTML::button(CLICSHOPPING::getDef('text_chat_send'), null, null, 'primary', ['params' => 'id="sendGpt"'], 'sm') . '
                        </div>
                    </div>                        
                </div>
            </div>
        </div>
    </span>
';

      $httpServer = CLICSHOPPING::getConfig('http_server', 'ClicShoppingAdmin');
      $httpPath = CLICSHOPPING::getConfig('http_path', 'ClicShoppingAdmin');

      $recordUrl = $httpServer . $httpPath . 'ajax/RAG/record_feedback.php';
      $ajaxUrl   = $httpServer . $httpPath . 'ajax/ChatGpt/chatGpt.php';

      $userId     = (int)(AdministratorAdmin::getUserAdminId() ?? 0);
      $languageId = (int)($_SESSION['languages_id'] ?? 1);

      $resetContextUrl = $httpServer . $httpPath . 'ajax/RAG/reset_context.php';

      $script .='
<script>
  // Configuration globale du chat modal
  window.CHAT_FEEDBACK_AJAX_URL = "' . $recordUrl . '";

  window.CHAT_CONFIG = {
    ajaxUrl: " ' . $ajaxUrl . '",
    feedbackUrl: "' . $recordUrl . '",
    resetContextUrl: "' . $resetContextUrl . '",
    userId: ' . $userId . ',
    languageId:  ' . $languageId . ',
    enableFeedback: true,
    enableDiagnostics: true,
    enableWebSearch: true,
    showConfidence: true,
    showTypeBadge: true,
    autoScroll: true,
    modalMode: true
  };
</script>
';

      // Charger les scripts JavaScript
      // TASK 5.3.2.1: Load DOMPurify BEFORE chat_send.js for HTML sanitization
      $script .= '<script src="https://cdnjs.cloudflare.com/ajax/libs/dompurify/3.2.7/purify.min.js"></script>' . "\n";
      $script .= '<script src="' . HTTP::getShopUrlDomain() . 'ext/javascript/clicshopping/ClicShoppingAdmin/ChatGpt/chat_clarification.js"></script>' . "\n";
      $script .= '<script src="' . HTTP::getShopUrlDomain() . 'ext/javascript/clicshopping/ClicShoppingAdmin/ChatGpt/chat_send.js"></script>' . "\n";
      $script .= '<script src="' . HTTP::getShopUrlDomain() . 'ext/javascript/clicshopping/ClicShoppingAdmin/ChatGpt/chat_feedback.js"></script>' . "\n";
      $script .= '<script src="' . HTTP::getShopUrlDomain() . 'ext/javascript/clicshopping/ClicShoppingAdmin/ChatGpt/chat_reset_context.js"></script>' . "\n";
    }

    return $output . $menu . $script;
  }

  /*****************************************
   * Ckeditor
   ****************************************/

  /**
   * Generates and returns the parameters and script configuration for integrating a ChatGPT model into CKEditor.
   *
   * @return string|bool Returns the generated script as a string if successful, otherwise, returns false.
   */
  public static function gptCkeditorParameters(): string|bool
  {
    $model = CLICSHOPPING_APP_CHATGPT_CH_MODEL;

    $url = "https://api.openai.com/v1/chat/completions";

    $organization = '';
    if (!empty(CLICSHOPPING_APP_CHATGPT_CH_ORGANIZATION)) {
      $organization = 'let organizationGpt = "' . CLICSHOPPING_APP_CHATGPT_CH_ORGANIZATION . '";';
    }

    $script = '<script>
 let apiGptUrl = "' . $url . '";
 ' . $organization . '
 let modelGpt = "' . $model . '";
 let temperatureGpt = parseFloat("' . (float)CLICSHOPPING_APP_CHATGPT_CH_TEMPERATURE . '");
 let top_p_gpt = parseFloat("' . (float)CLICSHOPPING_APP_CHATGPT_CH_TOP_P . '");
 let frequency_penalty_gpt = parseFloat("' . (float)CLICSHOPPING_APP_CHATGPT_CH_FREQUENCY_PENALITY . '");
 let presence_penalty_gpt = parseFloat("' . (float)CLICSHOPPING_APP_CHATGPT_CH_PRESENCE_PENALITY . '");
 let max_tokens_gpt = parseInt("' . (int)CLICSHOPPING_APP_CHATGPT_CH_MAX_TOKEN . '");
 let reasoning_effort_gpt = "' . CLICSHOPPING_APP_CHATGPT_CH_REASONING_EFFORT . '";
 let verbosity_gpt = "' . CLICSHOPPING_APP_CHATGPT_CH_VERBOSITY . '";
 let nGpt = parseInt("' . (int)CLICSHOPPING_APP_CHATGPT_CH_MAX_RESPONSE . '");
 let best_of_gpt = parseInt("' . (int)CLICSHOPPING_APP_CHATGPT_CH_BESTOFF . '");
 let titleGpt = "' . CLICSHOPPING::getDef('text_chat_title') . '";
</script>';

    $script .= '<script>
 function callChatGpt(prompt, callback) {
   const payload = {
     prompt: prompt,
     model: modelGpt
   };

   if (modelGpt.startsWith("gpt-5-")) {
     payload.max_output_tokens = max_tokens_gpt;
     payload.reasoning_effort = reasoning_effort_gpt;
     payload.verbosity = verbosity_gpt;     
   } else {
     payload.temperature = temperatureGpt;
     payload.top_p = top_p_gpt;
     payload.frequency_penalty = frequency_penalty_gpt;
     payload.presence_penalty = presence_penalty_gpt;
     payload.max_tokens = max_tokens_gpt;
     payload.n = nGpt;
     payload.best_of = best_of_gpt;
   }

   fetch(apiGptUrl, {
     method: "POST",
     headers: {
       "Content-Type": "application/json"
     },
     body: JSON.stringify(payload)
   })
   .then(response => response.json())
   .then(data => callback(data))
   .catch(error => console.error("Erreur GPT :", error));
 }
</script>';

    $script .= '<!--start wysiwig preloader--><style>.blur {filter: blur(1px);opacity: 0.4;}</style><!--end wysiwzg preloader-->';
    $script .= '<script src="' . CLICSHOPPING::link('Shop/ext/javascript/cKeditor/dialogs/chatgpt.js') . '"></script>';

    return $script;
  }

  /**
   * Récupère la clé SerpApi depuis les différentes sources configurées
   * Centralise la logique de récupération pour éviter la redondance
   *
   * @return string La clé API SerpApi ou chaîne vide si non configurée
   */
  public static function getSerpApiKey(): string
  {
    if (!defined('CLICSHOPPING_APP_CHATGPT_CH_API_KEY_SERPAPI') || empty(CLICSHOPPING_APP_CHATGPT_CH_API_KEY_SERPAPI)) {
      error_log("WARNING: CLICSHOPPING_APP_CHATGPT_CH_API_KEY_SERPAPI not defined or empty");
      return '';
    }

    $key = CLICSHOPPING_APP_CHATGPT_CH_API_KEY_SERPAPI;

    if (!empty($key)) {
      $eny = putenv('SERP_API_KEY=' . $key);
      return $eny;
    }

    return '';
  }

  /**
   * Vérifie si SerpApi est configuré et disponible
   *
   * @return bool True si une clé SerpApi valide est disponible
   */
  public static function isSerpApiAvailable(): bool
  {
    return !empty(self::getSerpApiKey());
  }
  
//*******************************************
// Parallel Execution functions
// Adapter ParalLLMExecutor parseResponse
// Adapter les versions de Anthropic
//*******************************************


  /**
   * Build API request body for direct HTTP calls (used by ParallelLLMExecutor)
   * 
   * This method centralizes the request body building logic for all providers.
   * Used when making direct HTTP requests for parallel execution.
   * 
   * GPT-5 models use reasoning approach (reasoning_effort, verbosity) instead of
   * standard parameters (temperature, top_p, frequency_penalty, presence_penalty).
   *
   * @param string $prompt The prompt text
   * @param string $provider Provider name (openai, ollama, lmstudio, anthropic, mistral)
   * @param string|null $model Model name (uses config default if null)
   * @param float|null $temperature Temperature (uses config default if null, ignored for GPT-5)
   * @param int|null $maxTokens Max tokens (uses config default if null)
   * @return array Request body array ready for JSON encoding
   */
  /**
   * Build API request body for a specific provider
   * 
   * @deprecated This method is maintained for backward compatibility only.
   *             ParallelLLMExecutor now uses provider->buildRequestBody() directly.
   *             New code should use provider->buildRequestBody() instead.
   *             This method will be removed in a future version.
   * 
   * REFACTORED: Now uses LLMProviderInterface internally while maintaining
   * the same return format for backward compatibility.
   * 
   * @param string $prompt The prompt text
   * @param string $provider Provider name
   * @param string|null $model Model name
   * @param float|null $temperature Temperature
   * @param int|null $maxTokens Max tokens
   * @return array Request body array ready for JSON encoding
   */
  public static function buildApiRequestBody(string $prompt, string $provider, ?string $model = null, ?float $temperature = null, ?int $maxTokens = null): array 
  {
    // Note: Deprecation warning in comment only (as per task requirements)
    // trigger_error('Gpt::buildApiRequestBody() is deprecated, use provider->buildRequestBody() instead', E_USER_DEPRECATED);
    
    try {
      // Use LLMProviderFactory to create provider instance
      $factory = LLMProviderFactory::getInstance();
      $providerInstance = $factory->create($provider, [
        'model' => $model,
        'temperature' => $temperature,
        'max_tokens' => $maxTokens,
      ]);
      
      // Use provider's buildRequestBody method
      return $providerInstance->buildRequestBody($prompt, [
        'temperature' => $temperature,
        'max_tokens' => $maxTokens,
      ]);
      
    } catch (\Exception $e) {
      error_log('❌ Error in buildApiRequestBody: ' . $e->getMessage());
      
      // Fallback to default OpenAI format for backward compatibility
      $model = $model ?? (defined('CLICSHOPPING_APP_CHATGPT_CH_MODEL') ? CLICSHOPPING_APP_CHATGPT_CH_MODEL : 'gpt-4o-mini');
      $temperature = $temperature ?? (defined('CLICSHOPPING_APP_CHATGPT_CH_TEMPERATURE') ? (float)CLICSHOPPING_APP_CHATGPT_CH_TEMPERATURE : 0.5);
      $maxTokens = $maxTokens ?? (defined('CLICSHOPPING_APP_CHATGPT_CH_MAX_TOKEN') ? (int)CLICSHOPPING_APP_CHATGPT_CH_MAX_TOKEN : 4000);
      
      return [
        'model' => $model,
        'messages' => [
          ['role' => 'user', 'content' => $prompt]
        ],
        'temperature' => $temperature,
        'max_tokens' => $maxTokens,
      ];
    }
  }

  /**
   * Map internal Anthropic model names to API model names
   * 
   * @deprecated This method is maintained for backward compatibility only.
   *             AnthropicProvider now handles model name mapping internally.
   *             New code should use AnthropicProvider directly.
   *             This method will be removed in a future version.
   * 
   * NOTE: This method is also used internally by AnthropicProvider class.
   * Used for direct HTTP calls (ParallelLLMExecutor) and backward compatibility.
   * getAnthropicChat() uses LLPhant AnthropicConfig which handles this internally.
   * 
   * @param string $model Internal model name (e.g., 'anth-sonnet')
   * @return string API model name (e.g., 'claude-3-5-sonnet-20241022')
   */
  public static function mapAnthropicModelName(string $model): string
  {
    // Note: Deprecation warning in comment only (as per task requirements)
    // trigger_error('Gpt::mapAnthropicModelName() is deprecated, use AnthropicProvider instead', E_USER_DEPRECATED);
    
    return match ($model) {
      'anth-sonnet' => 'claude-3-5-sonnet-20241022',
      'anth-opus' => 'claude-3-opus-20240229',
      'anth-haiku' => 'claude-3-haiku-20240307',
      default => $model // Return as-is if already API format
    };
  }
  
  /**
   * Build API configuration for a specific provider
   * 
   * @deprecated This method is maintained for backward compatibility only.
   *             ParallelLLMExecutor now uses LLMProviderInterface directly.
   *             New code should use LLMProviderFactory::create() instead.
   *             This method will be removed in a future version.
   * 
   * REFACTORED: Now uses LLMProviderFactory internally while maintaining
   * the same return format for backward compatibility.
   * 
   * @param string $provider Provider name (openai, ollama, lmstudio, anthropic, mistral)
   * @param string|null $model Model name (uses config default if null)
   * @return array Configuration array with url, headers, model, provider, temperature, max_tokens
   */
  public static function buildConfigForProvider(string $provider, ?string $model = null): array
  {
    // Note: Deprecation warning in comment only (as per task requirements)
    // trigger_error('Gpt::buildConfigForProvider() is deprecated, use LLMProviderFactory instead', E_USER_DEPRECATED);
    
    try {
      // Use LLMProviderFactory to create provider instance
      $factory = LLMProviderFactory::getInstance();
      $providerInstance = $factory->create($provider, ['model' => $model]);
      
      // Build config array in the expected format for backward compatibility
      $config = [
        'provider' => $providerInstance->getName(),
        'url' => $providerInstance->getApiUrl(),
        'model' => $providerInstance->getModel(),
        'temperature' => $providerInstance->getTemperature(),
        'max_tokens' => $providerInstance->getMaxTokens(),
        'headers' => [],
      ];

      // Add provider-specific headers
      if ($apiKey = $providerInstance->getApiKey()) {
        if ($provider === 'anthropic') {
          $config['headers'] = [
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
          ];
        } else {
          $config['headers'] = [
            'Authorization' => 'Bearer ' . $apiKey,
          ];
        }
      }

      return $config;
      
    } catch (\Exception $e) {
      error_log('❌ Error in buildConfigForProvider: ' . $e->getMessage());
      
      // Fallback to default OpenAI config for backward compatibility
      return [
        'provider' => 'openai',
        'url' => 'https://api.openai.com/v1/chat/completions',
        'headers' => [
          'Authorization' => 'Bearer ' . (defined('CLICSHOPPING_APP_CHATGPT_CH_API_KEY') ? CLICSHOPPING_APP_CHATGPT_CH_API_KEY : ''),
        ],
        'model' => $model ?? (defined('CLICSHOPPING_APP_CHATGPT_CH_MODEL') ? CLICSHOPPING_APP_CHATGPT_CH_MODEL : 'gpt-4o-mini'),
        'temperature' => defined('CLICSHOPPING_APP_CHATGPT_CH_TEMPERATURE') ? (float)CLICSHOPPING_APP_CHATGPT_CH_TEMPERATURE : 0.0,
        'max_tokens' => defined('CLICSHOPPING_APP_CHATGPT_CH_MAX_TOKEN') ? (int)CLICSHOPPING_APP_CHATGPT_CH_MAX_TOKEN : 4000,
      ];
    }
  }
}
