<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\AI\Agents\Orchestrator\SubAnalyticsAgent;

use ClicShopping\AI\Security\SecurityLogger;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\Gpt;
use ClicShopping\OM\CLICSHOPPING;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Promise\Utils as PromiseUtils;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\TransferException;

/**
 * ParallelLLMExecutor Class
 *
 * Executes multiple LLM calls in parallel using Guzzle HTTP Client with Promises.
 * Significantly reduces total execution time when generating multiple SQL interpretations.
 *
 * Performance Impact:
 * - Sequential: 3 calls × 3s = 9s total
 * - Parallel: max(3s, 3s, 3s) = 3s total
 * - Gain: ~6s (66% faster)
 *
 * Technical Approach:
 * - Uses GuzzleHttp\Client::requestAsync() for non-blocking HTTP requests
 * - Uses GuzzleHttp\Promise\Utils::settle() to wait for all promises
 * - Follows same pattern as ClicShopping\OM\HTTP::getResponse()
 */
class ParallelLLMExecutor
{
  private SecurityLogger $logger;
  private bool $debug;
  private int $timeout;
  private int $maxConcurrent;
  private bool $parallelEnabled;

  /**
   * Constructor with configuration from constants
   * 
   * @param bool|null $debug Debug mode (null = use default)
   * @param int|null $timeout Timeout in seconds (null = use CLICSHOPPING_APP_CHATGPT_RA_PARALLEL_TIMEOUT)
   * @param int|null $maxConcurrent Max concurrent calls (null = use CLICSHOPPING_APP_CHATGPT_RA_PARALLEL_MAX_CONCURRENT)
   */
  public function __construct(?bool $debug = null, ?int $timeout = null, ?int $maxConcurrent = null)
  {
    $this->logger = new SecurityLogger();
    
    // Use configuration constants with fallback defaults
    $this->debug = $debug ?? false;
    
    $this->timeout = $timeout ?? (
      defined('CLICSHOPPING_APP_CHATGPT_RA_PARALLEL_TIMEOUT') 
        ? (int)CLICSHOPPING_APP_CHATGPT_RA_PARALLEL_TIMEOUT 
        : 30
    );
    
    $this->maxConcurrent = $maxConcurrent ?? (
      defined('CLICSHOPPING_APP_CHATGPT_RA_PARALLEL_MAX_CONCURRENT') 
        ? (int)CLICSHOPPING_APP_CHATGPT_RA_PARALLEL_MAX_CONCURRENT 
        : 5
    );
    
    // Use configuration constant for parallel enabled
    // Handle both boolean and string 'True'/'False' formats (DB compatibility)
    if (defined('CLICSHOPPING_APP_CHATGPT_RA_PARALLEL_ENABLED')) {
      $configValue = CLICSHOPPING_APP_CHATGPT_RA_PARALLEL_ENABLED;
      $this->parallelEnabled = ($configValue === true || $configValue === 'True' || $configValue === 'true' || $configValue === '1');
    } else {
      $this->parallelEnabled = true;
    }
    
    if ($this->debug) {
      $this->logger->logSecurityEvent(
        "ParallelLLMExecutor: Initialized with config - parallel_enabled: " . ($this->parallelEnabled ? 'true' : 'false') . 
        ", timeout: {$this->timeout}s, max_concurrent: {$this->maxConcurrent}",
        'info'
      );
    }
  }

  /**
   * Execute multiple LLM prompts in parallel using Guzzle async requests
   *
   * @param mixed $chat Chat instance (OpenAI, Ollama, etc.)
   * @param array $prompts Array of prompts to execute indexed by key
   * @return array Array of responses indexed by prompt key
   */
  public function executeParallel($chat, array $prompts): array
  {
    if (empty($prompts)) {
      return [];
    }

    $startTime = microtime(true);
    $promptCount = count($prompts);

    if ($this->debug) {
      $this->logger->logSecurityEvent(
        "ParallelLLMExecutor: Starting parallel execution of {$promptCount} prompts",
        'info'
      );
    }

    // Check if parallel execution is enabled
    if (!$this->parallelEnabled) {
      $this->logFallbackReason('parallel_disabled', 'Parallel execution is disabled via configuration');
      return $this->executeSequential($chat, $prompts, $startTime);
    }

    // Try parallel execution
    try {
      // Extract API configuration from chat instance
      $apiConfig = $this->extractApiConfig($chat);

      // Fall back if API config extraction fails (empty URL)
      if (empty($apiConfig['url'])) {
        $this->logFallbackReason('api_config_failed', 'API configuration extraction failed - empty URL');
        return $this->executeSequential($chat, $prompts, $startTime);
      }

      // Build request configurations for each prompt
      $requests = [];
      foreach ($prompts as $key => $prompt) {
        $requests[$key] = $this->buildRequestOptions($prompt, $apiConfig);
      }

      // Execute all requests in parallel
      $results = $this->executeGuzzleAsync($requests, $apiConfig);

      // Extract timing metadata (added by executeGuzzleAsync)
      $timingMetadata = $results['_timing'] ?? null;
      unset($results['_timing']); // Remove metadata from results

      $totalDuration = microtime(true) - $startTime;
      $this->logPerformanceMetrics($results, $totalDuration, $promptCount, $timingMetadata);

      return $results;

    } catch (\Exception $e) {
      $this->logFallbackReason('exception', 'Parallel execution failed: ' . $e->getMessage());
      return $this->executeSequential($chat, $prompts, $startTime);
    }
  }

  /**
   * Execute prompts using Guzzle async requests
   * Core parallel execution implementation
   *
   * @param array $requests Array of HTTP request configurations indexed by key
   * @param array $apiConfig API configuration with url, headers, provider
   * @return array Results with success/failure status indexed by key
   */
  private function executeGuzzleAsync(array $requests, array $apiConfig): array
  {
    $client = $this->createHttpClient();
    $promises = [];
    $startTimes = [];

    // Record batch start time for parallel execution measurement
    $batchStartTime = microtime(true);

    // Create async requests for each prompt
    foreach ($requests as $key => $requestOptions) {
      $startTimes[$key] = microtime(true);

      if ($this->debug) {
        $this->logger->logSecurityEvent(
          "ParallelLLMExecutor: Creating async request for key '{$key}'",
          'info'
        );
      }

      $promises[$key] = $client->requestAsync(
        'POST',
        $apiConfig['url'],
        $requestOptions
      );
    }

    // Execute all requests in parallel and wait for completion
    // Utils::settle() handles both success and failure gracefully
    $settledResults = PromiseUtils::settle($promises)->wait();

    // Calculate total parallel execution time
    $batchEndTime = microtime(true);
    $batchDuration = $batchEndTime - $batchStartTime;

    // Process results
    $results = [];
    foreach ($settledResults as $key => $result) {
      $duration = microtime(true) - $startTimes[$key];

      $results[$key] = $this->processSettledResult(
        $result,
        $key,
        $duration,
        $apiConfig['provider'] ?? 'openai'
      );
    }

    // Add batch timing metadata to results for performance analysis
    $results['_timing'] = [
      'batch_start' => $batchStartTime,
      'batch_end' => $batchEndTime,
      'batch_duration' => $batchDuration,
      'individual_durations' => array_column($results, 'duration'),
    ];

    return $results;
  }

  /**
   * Process a settled promise result (fulfilled or rejected)
   *
   * @param array $result Settled promise result with 'state' and 'value'/'reason'
   * @param string $key The prompt key
   * @param float $duration Execution duration in seconds
   * @param string $provider API provider name
   * @return array Processed result with success, response, duration, http_code
   */
  private function processSettledResult(array $result, string $key, float $duration, string $provider): array
  {
    if ($result['state'] === 'fulfilled') {
      $response = $result['value'];
      $httpCode = $response->getStatusCode();
      $body = $response->getBody()->getContents();

      // Parse the response based on provider
      $parsedResponse = $this->parseResponse($body, $provider);

      if ($this->debug) {
        $this->logger->logSecurityEvent(
          "ParallelLLMExecutor: Request '{$key}' completed successfully in " . number_format($duration, 3) . "s (HTTP {$httpCode})",
          'info'
        );
      }

      return [
        'success' => true,
        'response' => $parsedResponse,
        'duration' => $duration,
        'http_code' => $httpCode,
      ];
    }

    // Handle rejection (timeout, network error, etc.)
    $reason = $result['reason'];
    
    // Use dedicated error detection methods
    $errorInfo = $this->detectErrorType($reason);
    
    // Log the error with detailed information
    $this->logError($key, $errorInfo, $duration);

    return [
      'success' => false,
      'error' => $errorInfo['message'],
      'error_type' => $errorInfo['type'],
      'duration' => $duration,
      'http_code' => $errorInfo['http_code'],
    ];
  }

  /**
   * Detect error type from promise rejection reason
   * Categorizes errors into: timeout, network, http_error, api_error, unknown
   *
   * @param mixed $reason The rejection reason (usually an Exception)
   * @return array Error information with type, message, http_code
   */
  private function detectErrorType($reason): array
  {
    // Default error info
    $errorInfo = [
      'type' => 'unknown',
      'message' => 'Unknown error',
      'http_code' => 0,
      'is_timeout' => false,
      'is_network_error' => false,
      'is_http_error' => false,
    ];

    if (!($reason instanceof \Exception) && !($reason instanceof \Throwable)) {
      $errorInfo['message'] = \is_string($reason) ? $reason : 'Non-exception error';
      return $errorInfo;
    }

    $message = $reason->getMessage();
    $errorInfo['message'] = $message;

    // Check for timeout conditions
    if ($this->isTimeoutError($reason)) {
      $errorInfo['type'] = 'timeout';
      $errorInfo['is_timeout'] = true;
      $errorInfo['message'] = 'Request timeout: ' . $message;
      return $errorInfo;
    }

    // Check for network/connection errors
    if ($this->isNetworkError($reason)) {
      $errorInfo['type'] = 'network';
      $errorInfo['is_network_error'] = true;
      $errorInfo['message'] = 'Network error: ' . $message;
      return $errorInfo;
    }

    // Check for HTTP errors (4xx, 5xx responses)
    if ($reason instanceof RequestException && $reason->hasResponse()) {
      $response = $reason->getResponse();
      $httpCode = $response->getStatusCode();
      $errorInfo['http_code'] = $httpCode;
      $errorInfo['is_http_error'] = true;
      
      // Categorize HTTP errors
      if ($httpCode >= 400 && $httpCode < 500) {
        $errorInfo['type'] = 'client_error';
        $errorInfo['message'] = $this->parseHttpErrorMessage($response, $httpCode);
      } elseif ($httpCode >= 500) {
        $errorInfo['type'] = 'server_error';
        $errorInfo['message'] = "Server error (HTTP {$httpCode}): " . $message;
      } else {
        $errorInfo['type'] = 'http_error';
      }
      
      return $errorInfo;
    }

    // Generic request exception without response
    if ($reason instanceof RequestException) {
      $errorInfo['type'] = 'request_error';
      return $errorInfo;
    }

    // Generic transfer exception
    if ($reason instanceof TransferException) {
      $errorInfo['type'] = 'transfer_error';
      return $errorInfo;
    }

    return $errorInfo;
  }

  /**
   * Check if the error is a timeout condition
   * Detects timeout from Guzzle exceptions and error messages
   *
   * @param \Throwable $exception The exception to check
   * @return bool True if this is a timeout error
   */
  private function isTimeoutError(\Throwable $exception): bool
  {
    $message = \strtolower($exception->getMessage());
    
    // Check for common timeout indicators in message
    $timeoutIndicators = [
      'timeout',
      'timed out',
      'operation timed out',
      'connection timed out',
      'read timed out',
      'curl error 28',  // CURLE_OPERATION_TIMEDOUT
    ];
    
    foreach ($timeoutIndicators as $indicator) {
      if (\str_contains($message, $indicator)) {
        return true;
      }
    }
    
    // Check for ConnectException with timeout
    if ($exception instanceof ConnectException) {
      // ConnectException can be timeout during connection phase
      if (\str_contains($message, 'timeout') || \str_contains($message, 'timed out')) {
        return true;
      }
    }
    
    return false;
  }

  /**
   * Check if the error is a network/connection error
   * Detects network failures, DNS issues, connection refused, etc.
   *
   * @param \Throwable $exception The exception to check
   * @return bool True if this is a network error
   */
  private function isNetworkError(\Throwable $exception): bool
  {
    // ConnectException is specifically for connection failures
    if ($exception instanceof ConnectException) {
      return true;
    }
    
    $message = \strtolower($exception->getMessage());
    
    // Check for common network error indicators
    $networkIndicators = [
      'could not resolve host',
      'connection refused',
      'connection reset',
      'network is unreachable',
      'no route to host',
      'name or service not known',
      'curl error 6',   // CURLE_COULDNT_RESOLVE_HOST
      'curl error 7',   // CURLE_COULDNT_CONNECT
      'curl error 35',  // CURLE_SSL_CONNECT_ERROR
      'ssl certificate',
      'ssl handshake',
    ];
    
    foreach ($networkIndicators as $indicator) {
      if (\str_contains($message, $indicator)) {
        return true;
      }
    }
    
    return false;
  }

  /**
   * Parse HTTP error message from response body
   * Extracts meaningful error message from API error responses
   *
   * @param \Psr\Http\Message\ResponseInterface $response The HTTP response
   * @param int $httpCode The HTTP status code
   * @return string Formatted error message
   */
  private function parseHttpErrorMessage($response, int $httpCode): string
  {
    try {
      $body = $response->getBody()->getContents();
      $data = \json_decode($body, true);
      
      if (\json_last_error() === JSON_ERROR_NONE && isset($data['error'])) {
        // OpenAI/Anthropic error format
        if (\is_array($data['error'])) {
          $errorMsg = $data['error']['message'] ?? \json_encode($data['error']);
          $errorType = $data['error']['type'] ?? 'api_error';
          return "API error (HTTP {$httpCode}, {$errorType}): {$errorMsg}";
        }
        return "API error (HTTP {$httpCode}): " . $data['error'];
      }
      
      // Ollama error format
      if (\json_last_error() === JSON_ERROR_NONE && isset($data['message'])) {
        return "API error (HTTP {$httpCode}): " . $data['message'];
      }
      
    } catch (\Exception $e) {
      // Ignore parsing errors
    }
    
    // Default message based on HTTP code
    return match ($httpCode) {
      400 => "Bad request (HTTP 400): Invalid request format",
      401 => "Unauthorized (HTTP 401): Invalid or missing API key",
      403 => "Forbidden (HTTP 403): Access denied",
      404 => "Not found (HTTP 404): Invalid endpoint or model",
      429 => "Rate limited (HTTP 429): Too many requests",
      500 => "Internal server error (HTTP 500)",
      502 => "Bad gateway (HTTP 502): Service temporarily unavailable",
      503 => "Service unavailable (HTTP 503): API overloaded",
      default => "HTTP error {$httpCode}",
    };
  }

  /**
   * Log detailed error information for a failed request
   * Includes prompt key, error type, message, duration, and HTTP status code
   *
   * @param string $key The prompt key that failed
   * @param array $errorInfo Error information from detectErrorType()
   * @param float $duration Execution duration in seconds
   */
  private function logError(string $key, array $errorInfo, float $duration): void
  {
    $logLevel = $this->getLogLevelForError($errorInfo['type']);
    
    $logMessage = \sprintf(
      "ParallelLLMExecutor: Request '%s' failed [%s] - %s (HTTP %d, duration: %.3fs)",
      $key,
      $errorInfo['type'],
      $errorInfo['message'],
      $errorInfo['http_code'],
      $duration
    );
    
    $this->logger->logSecurityEvent($logMessage, $logLevel, [
      'prompt_key' => $key,
      'error_type' => $errorInfo['type'],
      'error_message' => $errorInfo['message'],
      'http_code' => $errorInfo['http_code'],
      'duration' => $duration,
      'is_timeout' => $errorInfo['is_timeout'] ?? false,
      'is_network_error' => $errorInfo['is_network_error'] ?? false,
      'is_http_error' => $errorInfo['is_http_error'] ?? false,
    ]);
  }

  /**
   * Determine appropriate log level based on error type
   * Timeouts and rate limits are warnings, others are errors
   *
   * @param string $errorType The error type from detectErrorType()
   * @return string Log level (error, warning, info)
   */
  private function getLogLevelForError(string $errorType): string
  {
    return match ($errorType) {
      'timeout' => 'warning',      // Timeouts are expected under load
      'client_error' => 'warning', // 4xx errors may be recoverable
      'network' => 'error',        // Network issues need attention
      'server_error' => 'error',   // 5xx errors indicate API problems
      default => 'error',
    };
  }

  /**
   * Create Guzzle HTTP client for async requests
   * Follows same pattern as ClicShopping\OM\HTTP::getResponse()
   * Note: HTTP class only supports sync requests, we need async for parallel execution
   *
   * @return GuzzleClient
   */
  private function createHttpClient(): GuzzleClient
  {
    $options = [
      'timeout' => $this->timeout,
      'connect_timeout' => 10,
    ];

    // Add SSL certificate verification (same as HTTP::getResponse())
    $caFile = CLICSHOPPING::BASE_DIR . 'External/cacert.pem';
    if (is_file($caFile)) {
      $options['verify'] = $caFile;
    }

    return new GuzzleClient($options);
  }

  /**
   * Build HTTP request options for Guzzle
   *
   * @param string $prompt The prompt text
   * @param array $config API configuration
   * @return array Guzzle request options
   */
  private function buildRequestOptions(string $prompt, array $config): array
  {
    $provider = $config['provider'] ?? 'openai';

    // Build request body based on provider
    $body = $this->buildRequestBody($prompt, $config, $provider);

    $options = [
      RequestOptions::HEADERS => array_merge(
        ['Content-Type' => 'application/json'],
        $config['headers'] ?? []
      ),
      RequestOptions::JSON => $body,
      RequestOptions::TIMEOUT => $this->timeout,
      RequestOptions::CONNECT_TIMEOUT => 10,
    ];

    return $options;
  }

  /**
   * Build request body based on provider
   * Delegates to Gpt::buildApiRequestBody() for centralized logic
   *
   * @param string $prompt The prompt text
   * @param array $config API configuration
   * @param string $provider Provider name
   * @return array Request body
   */
  private function buildRequestBody(string $prompt, array $config, string $provider): array
  {
    // Delegate to Gpt class for centralized request body building
    return Gpt::buildApiRequestBody(
      $prompt,
      $provider,
      $config['model'] ?? null,
      $config['temperature'] ?? null,
      $config['max_tokens'] ?? null
    );
  }

  /**
   * Parse LLM API response
   * Extracts generated text from JSON response based on provider format
   *
   * Response formats by provider:
   * - OpenAI-compatible (openai, lmstudio, mistral): { "choices": [{ "message": { "content": "..." } }] }
   * - Ollama: { "response": "..." }
   * - Anthropic: { "content": [{ "text": "..." }] }
   *
   * Note: LM Studio uses OpenAI-compatible format, NOT Ollama format
   * Both are local providers but have different API implementations
   *
   * @param string $response Raw HTTP response body
   * @param string $provider API provider (openai, ollama, anthropic, lmstudio, mistral)
   * @return string Extracted text or empty string on failure
   */
  private function parseResponse(string $response, string $provider): string
  {
    if (empty($response)) {
      $this->logger->logSecurityEvent(
        "ParallelLLMExecutor: Empty response received from provider '{$provider}'",
        'warning'
      );
      return '';
    }

    $data = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
      $this->logger->logSecurityEvent(
        "ParallelLLMExecutor: Failed to parse JSON response from provider '{$provider}': " . json_last_error_msg(),
        'error'
      );
      return '';
    }

    // Check for API error responses
    if (isset($data['error'])) {
      $errorMessage = is_array($data['error']) 
        ? ($data['error']['message'] ?? json_encode($data['error']))
        : $data['error'];
      $this->logger->logSecurityEvent(
        "ParallelLLMExecutor: API error from provider '{$provider}': {$errorMessage}",
        'error'
      );
      return '';
    }

    switch ($provider) {
      case 'ollama':
        // Ollama uses its own format: { "response": "..." }
        // Different from LM Studio which uses OpenAI-compatible format
        if (isset($data['response'])) {
          return $data['response'];
        }
        $this->logger->logSecurityEvent(
          "ParallelLLMExecutor: Ollama response missing 'response' field",
          'warning'
        );
        return '';

      case 'anthropic':
        // Anthropic format: { "content": [{ "type": "text", "text": "..." }] }
        if (isset($data['content']) && is_array($data['content'])) {
          foreach ($data['content'] as $block) {
            if (isset($block['type']) && $block['type'] === 'text' && isset($block['text'])) {
              return $block['text'];
            }
            // Fallback for simpler format
            if (isset($block['text'])) {
              return $block['text'];
            }
          }
        }
        $this->logger->logSecurityEvent(
          "ParallelLLMExecutor: Anthropic response missing 'content[].text' field",
          'warning'
        );
        return '';

      case 'lmstudio':
      case 'mistral':
      case 'openai':
      default:
        // OpenAI-compatible format: { "choices": [{ "message": { "content": "..." } }] }
        // LM Studio uses this format (NOT Ollama format) despite being a local provider
        if (isset($data['choices']) && is_array($data['choices']) && !empty($data['choices'])) {
          $firstChoice = $data['choices'][0];
          if (isset($firstChoice['message']['content'])) {
            return $firstChoice['message']['content'];
          }
          // Fallback for completion format (older API)
          if (isset($firstChoice['text'])) {
            return $firstChoice['text'];
          }
        }
        $this->logger->logSecurityEvent(
          "ParallelLLMExecutor: OpenAI-compatible response missing 'choices[0].message.content' field for provider '{$provider}'",
          'warning'
        );
        return '';
    }
  }

  /**
   * Extract API configuration from chat instance
   * Supports OpenAI, Ollama, LM Studio, Anthropic, MistralAI
   * 
   * Extraction priority:
   * 1. Detect provider from chat instance class name
   * 2. Try to extract model from chat instance properties
   * 3. Fallback to CLICSHOPPING_APP_CHATGPT_* constants
   *
   * @param mixed $chat Chat instance
   * @return array API configuration (url, headers, model, provider, temperature, max_tokens)
   */
  private function extractApiConfig($chat): array
  {
    // Detect provider from chat instance class name
    $provider = $this->detectProvider($chat);
    
    // Try to extract model from chat instance config
    $extractedModel = $this->extractModelFromChat($chat);

    // Use Gpt::buildConfigForProvider() with detected provider and optional model
    return Gpt::buildConfigForProvider($provider, $extractedModel);
  }
  
  /**
   * Detect provider from chat instance class name
   * Maps LLPhant chat class names to provider identifiers
   *
   * @param mixed $chat Chat instance
   * @return string Provider name (openai, ollama, lmstudio, anthropic, mistral)
   */
  private function detectProvider($chat): string
  {
    $className = \strtolower(\get_class($chat));
    
    // Check for provider indicators in class name
    if (\str_contains($className, 'ollama')) {
      return 'ollama';
    }
    
    if (\str_contains($className, 'lmstudio') || \str_contains($className, 'lm_studio')) {
      return 'lmstudio';
    }
    
    if (\str_contains($className, 'anthropic') || \str_contains($className, 'claude')) {
      return 'anthropic';
    }
    
    if (\str_contains($className, 'mistral')) {
      return 'mistral';
    }
    
    // Default to OpenAI (most common)
    return 'openai';
  }

  /**
   * Extract model name from chat instance if possible
   * Uses reflection to safely access protected properties
   * 
   * Note: setAccessible() is deprecated in PHP 8.1+ and removed in PHP 9.0
   * In PHP 8.1+, Reflection can access private/protected properties directly
   * 
   * @param mixed $chat Chat instance
   * @return string|null Extracted model name or null
   */
  private function extractModelFromChat($chat): ?string
  {
    try {
      // Use reflection to safely access config property (may be protected)
      // PHP 8.1+: setAccessible() is no longer needed - properties are accessible by default
      $reflection = new \ReflectionClass($chat);
      
      // Try to get config property
      if ($reflection->hasProperty('config')) {
        $configProperty = $reflection->getProperty('config');
        $config = $configProperty->getValue($chat);
        
        if (\is_object($config)) {
          // Try to get model from config
          $configReflection = new \ReflectionClass($config);
          if ($configReflection->hasProperty('model')) {
            $modelProperty = $configReflection->getProperty('model');
            $model = $modelProperty->getValue($config);
            if (!empty($model)) {
              return $model;
            }
          }
        }
      }
      
      // Try direct model property
      if ($reflection->hasProperty('model')) {
        $modelProperty = $reflection->getProperty('model');
        return $modelProperty->getValue($chat);
      }
    } catch (\ReflectionException $e) {
      // Silently fail and return null
      if ($this->debug) {
        $this->logger->logSecurityEvent(
          "ParallelLLMExecutor: Could not extract model from chat instance: " . $e->getMessage(),
          'warning'
        );
      }
    }

    return null;
  }

  /**
   * Log the reason for falling back to sequential execution
   * Provides detailed logging for debugging and monitoring
   *
   * @param string $reason Reason code (parallel_disabled, api_config_failed, exception)
   * @param string $message Detailed message explaining the fallback
   */
  private function logFallbackReason(string $reason, string $message): void
  {
    $logLevel = match ($reason) {
      'parallel_disabled' => 'info',      // Expected behavior when disabled
      'api_config_failed' => 'warning',   // Configuration issue
      'exception' => 'warning',           // Runtime error
      default => 'warning',
    };

    $this->logger->logSecurityEvent(
      "ParallelLLMExecutor: Falling back to sequential execution - {$message}",
      $logLevel,
      [
        'fallback_reason' => $reason,
        'parallel_enabled' => $this->parallelEnabled,
      ]
    );
  }

  /**
   * Execute prompts sequentially (fallback method)
   * Uses Gpt::getGptResponse() for consistent behavior with the rest of the system
   *
   * @param mixed $chat Chat instance (kept for interface compatibility)
   * @param array $prompts Array of prompts
   * @param float $startTime Start time for total duration calculation
   * @return array Results indexed by prompt key
   */
  private function executeSequential($chat, array $prompts, float $startTime): array
  {
    if ($this->debug) {
      $this->logger->logSecurityEvent(
        "ParallelLLMExecutor: Using sequential execution fallback via Gpt::getGptResponse()",
        'info'
      );
    }

    $results = [];

    foreach ($prompts as $key => $prompt) {
      try {
        $promptStartTime = microtime(true);
        
        // Use Gpt::getGptResponse() for consistent behavior with the rest of the system
        // This handles all provider detection, model selection, and response parsing
        $response = Gpt::getGptResponse($prompt);
        
        $promptDuration = microtime(true) - $promptStartTime;

        if ($response === false) {
          $results[$key] = [
            'success' => false,
            'error' => 'Gpt::getGptResponse() returned false',
            'duration' => $promptDuration,
            'http_code' => 0,
          ];
        } else {
          $results[$key] = [
            'success' => true,
            'response' => $response,
            'duration' => $promptDuration,
            'http_code' => 200,
          ];
        }

        if ($this->debug) {
          $this->logger->logSecurityEvent(
            "ParallelLLMExecutor: Sequential prompt '{$key}' completed in " . number_format($promptDuration, 3) . "s",
            'info'
          );
        }

      } catch (\Exception $e) {
        $this->logger->logSecurityEvent(
          "ParallelLLMExecutor: Error executing prompt '{$key}': " . $e->getMessage(),
          'error'
        );

        $results[$key] = [
          'success' => false,
          'error' => $e->getMessage(),
          'duration' => 0,
          'http_code' => 0,
        ];
      }
    }

    $totalDuration = microtime(true) - $startTime;
    $this->logPerformanceMetrics($results, $totalDuration, count($prompts), null);

    return $results;
  }

  /**
   * Log performance metrics for the execution
   * Calculates theoretical sequential time vs actual parallel time
   * Includes performance gain percentage and detailed timing breakdown
   *
   * @param array $results Execution results
   * @param float $totalDuration Total execution time (wall clock)
   * @param int $promptCount Number of prompts
   * @param array|null $timingMetadata Optional timing metadata from executeGuzzleAsync
   */
  private function logPerformanceMetrics(array $results, float $totalDuration, int $promptCount, ?array $timingMetadata = null): void
  {
    if (!$this->debug) {
      return;
    }

    $partialResultSummary = $this->analyzePartialResults($results);
    
    // Calculate individual durations (excluding metadata entries)
    $individualDurations = [];
    foreach ($results as $key => $result) {
      if (isset($result['duration'])) {
        $individualDurations[$key] = $result['duration'];
      }
    }
    
    // Calculate theoretical sequential time (sum of all individual durations)
    $sequentialEstimate = array_sum($individualDurations);
    
    // Calculate actual parallel time
    // Use batch_duration from timing metadata if available (more accurate for parallel)
    // Otherwise use totalDuration (includes overhead)
    $actualParallelTime = $timingMetadata['batch_duration'] ?? $totalDuration;
    
    // Calculate time saved and performance gain
    $timeSaved = $sequentialEstimate - $actualParallelTime;
    $performanceGain = $sequentialEstimate > 0 ? ($timeSaved / $sequentialEstimate) * 100 : 0;
    
    // Calculate max individual duration (theoretical minimum for parallel execution)
    $maxIndividualDuration = !empty($individualDurations) ? max($individualDurations) : 0;
    
    // Calculate parallel overhead (actual parallel time - max individual time)
    $parallelOverhead = $actualParallelTime - $maxIndividualDuration;

    // Log summary metrics
    $this->logger->logSecurityEvent(
      "ParallelLLMExecutor: Performance metrics",
      'info',
      [
        'total_prompts' => $promptCount,
        'successful' => $partialResultSummary['successful'],
        'failed' => $partialResultSummary['failed'],
        'partial_success' => $partialResultSummary['is_partial'],
        'error_types' => $partialResultSummary['error_types'],
        
        // Timing metrics
        'actual_parallel_time' => number_format($actualParallelTime, 3) . 's',
        'theoretical_sequential_time' => number_format($sequentialEstimate, 3) . 's',
        'max_individual_duration' => number_format($maxIndividualDuration, 3) . 's',
        'parallel_overhead' => number_format($parallelOverhead, 3) . 's',
        
        // Performance gains
        'time_saved' => number_format($timeSaved, 3) . 's',
        'performance_gain_percentage' => number_format($performanceGain, 1) . '%',
        
        // Individual durations for detailed analysis
        'individual_durations' => array_map(fn($d) => number_format($d, 3) . 's', $individualDurations),
        
        // Configuration
        'parallel_enabled' => $this->parallelEnabled,
        'execution_mode' => $this->parallelEnabled ? 'parallel' : 'sequential',
      ]
    );
    
    // Log detailed phase timing if available
    if ($timingMetadata !== null) {
      $this->logDetailedPhaseTiming($timingMetadata, $totalDuration);
    }
  }

  /**
   * Log detailed timing for each phase of execution
   * Provides granular breakdown of where time is spent
   *
   * @param array $timingMetadata Timing metadata from executeGuzzleAsync
   * @param float $totalDuration Total wall clock time
   */
  private function logDetailedPhaseTiming(array $timingMetadata, float $totalDuration): void
  {
    if (!$this->debug) {
      return;
    }

    $batchDuration = $timingMetadata['batch_duration'] ?? 0;
    $setupOverhead = $totalDuration - $batchDuration;
    
    $this->logger->logSecurityEvent(
      "ParallelLLMExecutor: Detailed phase timing",
      'info',
      [
        'phase_breakdown' => [
          'setup_and_config' => number_format($setupOverhead, 3) . 's',
          'parallel_execution' => number_format($batchDuration, 3) . 's',
          'total_wall_clock' => number_format($totalDuration, 3) . 's',
        ],
        'overhead_percentage' => number_format(($setupOverhead / $totalDuration) * 100, 1) . '%',
        'execution_percentage' => number_format(($batchDuration / $totalDuration) * 100, 1) . '%',
      ]
    );
  }

  /**
   * Analyze partial results to provide summary information
   * Counts successes, failures, and categorizes error types
   *
   * @param array $results Execution results from executeGuzzleAsync or executeSequential
   * @return array Summary with successful, failed, is_partial, error_types, failed_keys
   */
  private function analyzePartialResults(array $results): array
  {
    $successful = 0;
    $failed = 0;
    $errorTypes = [];
    $failedKeys = [];

    foreach ($results as $key => $result) {
      if ($result['success'] ?? false) {
        $successful++;
      } else {
        $failed++;
        $failedKeys[] = $key;
        
        // Track error types for analysis
        $errorType = $result['error_type'] ?? 'unknown';
        if (!isset($errorTypes[$errorType])) {
          $errorTypes[$errorType] = 0;
        }
        $errorTypes[$errorType]++;
      }
    }

    $total = $successful + $failed;
    $isPartial = $successful > 0 && $failed > 0;

    return [
      'total' => $total,
      'successful' => $successful,
      'failed' => $failed,
      'is_partial' => $isPartial,
      'all_failed' => $successful === 0 && $failed > 0,
      'all_succeeded' => $failed === 0 && $successful > 0,
      'error_types' => $errorTypes,
      'failed_keys' => $failedKeys,
    ];
  }

  /**
   * Get partial results from execution results
   * Returns only successful results, filtering out failures
   * Useful when you want to continue with available data despite some failures
   *
   * @param array $results Full execution results
   * @return array Only successful results indexed by key
   */
  public function getSuccessfulResults(array $results): array
  {
    return \array_filter($results, fn($r) => $r['success'] ?? false);
  }

  /**
   * Get failed results from execution results
   * Returns only failed results with error details
   * Useful for error reporting and retry logic
   *
   * @param array $results Full execution results
   * @return array Only failed results indexed by key
   */
  public function getFailedResults(array $results): array
  {
    return \array_filter($results, fn($r) => !($r['success'] ?? false));
  }

  /**
   * Check if results contain partial success (some succeeded, some failed)
   *
   * @param array $results Execution results
   * @return bool True if results are partial (mixed success/failure)
   */
  public function hasPartialResults(array $results): bool
  {
    $summary = $this->analyzePartialResults($results);
    return $summary['is_partial'];
  }

  /**
   * Execute multiple SQL generation prompts in parallel
   *
   * Specialized method for generating multiple SQL interpretations.
   * Returns an array of SQL queries indexed by interpretation type.
   * Continues processing after individual failures (partial results).
   *
   * @param mixed $chat Chat instance
   * @param array $interpretationPrompts Array of [type => prompt]
   * @return array Array of [type => ['sql' => query, 'duration' => time, 'error' => message, 'error_type' => type]]
   */
  public function generateMultipleSQLQueries($chat, array $interpretationPrompts): array
  {
    $results = $this->executeParallel($chat, $interpretationPrompts);

    $sqlQueries = [];
    foreach ($results as $type => $result) {
      if ($result['success']) {
        $sqlQueries[$type] = [
          'sql' => $result['response'],
          'duration' => $result['duration'],
          'success' => true,
        ];
      } else {
        // Include detailed error information for failed calls
        $sqlQueries[$type] = [
          'sql' => null,
          'error' => $result['error'] ?? 'Unknown error',
          'error_type' => $result['error_type'] ?? 'unknown',
          'http_code' => $result['http_code'] ?? 0,
          'duration' => $result['duration'] ?? 0,
          'success' => false,
        ];
      }
    }

    // Log partial result summary if there were failures
    if ($this->debug) {
      $summary = $this->analyzePartialResults($results);
      if ($summary['failed'] > 0) {
        $this->logger->logSecurityEvent(
          "ParallelLLMExecutor: SQL generation completed with partial results",
          $summary['all_failed'] ? 'error' : 'warning',
          [
            'total' => $summary['total'],
            'successful' => $summary['successful'],
            'failed' => $summary['failed'],
            'failed_types' => $summary['failed_keys'],
            'error_types' => $summary['error_types'],
          ]
        );
      }
    }

    return $sqlQueries;
  }

  /**
   * Get execution statistics
   *
   * @return array Statistics including parallel_enabled flag
   */
  public function getStatistics(): array
  {
    return [
      'timeout' => $this->timeout,
      'max_concurrent' => $this->maxConcurrent,
      'parallel_enabled' => $this->parallelEnabled,
    ];
  }

  /**
   * Enable or disable parallel execution
   *
   * @param bool $enabled Enable flag
   */
  public function setParallelEnabled(bool $enabled): void
  {
    $this->parallelEnabled = $enabled;

    if ($this->debug) {
      $status = $enabled ? 'enabled' : 'disabled';
      $this->logger->logSecurityEvent(
        "ParallelLLMExecutor: Parallel execution {$status}",
        'info'
      );
    }
  }
}
