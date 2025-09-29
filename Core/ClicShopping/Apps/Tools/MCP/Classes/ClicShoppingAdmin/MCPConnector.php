<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Tools\MCP\Classes\ClicShoppingAdmin;

use ClicShopping\Apps\Tools\MCP\Classes\ClicShoppingAdmin\Exceptions\McpException;
use ClicShopping\Apps\Tools\MCP\Classes\ClicShoppingAdmin\Transport\SseTransport;
use Psr\Log\LoggerInterface;

/**
 * Class MCPConnector
 *
 * The `MCPConnector` class serves as a singleton-based client for interacting with the MCP (Management & Control Panel) server.
 * It manages the connection, configuration, and communication with the server using a specific protocol and transport layer.
 * This class is designed to be the primary interface for sending requests and retrieving data from the MCP service.
 */
class MCPConnector
{
  /**
   * @var McpProtocol The protocol handler for communication with the MCP server.
   */
  private McpProtocol $protocol;

  /**
   * @var array The current configuration settings for the MCP connection.
   */
  private array $config;

  /**
   * @var self|null The singleton instance of the MCPConnector class.
   */
  private static ?MCPConnector $instance = null;

  /**
   * MCPConnector constructor.
   *
   * Initializes the MCP connector with the provided configuration. It retrieves settings from the database,
   * initializes the SSE transport layer, and sets up the communication protocol.
   *
   * @param array $config An optional array of configuration settings.
   * @param LoggerInterface|null $logger An optional PSR-3 logger instance.
   * @throws McpConfigurationException if a critical configuration key is missing.
   */
  public function __construct(array $config = [], ?LoggerInterface $logger = null)
  {
    $this->config = self::getConfigDb();

    $transport = new SseTransport($this->config, $logger);

    $this->protocol = new McpProtocol($this->config, $transport, $logger);
  }

  /**
   * Retrieves the MCP configuration from the database.
   *
   * This method fetches configuration values defined as constants and returns them in an associative array.
   * It performs basic validation and throws an exception if a required setting, such as `server_host`, is missing.
   *
   * @return array The associative array of configuration settings.
   * @throws McpException if `server_host` is not defined.
   */
  public static function getConfigDb(): array
  {
    $config = [];

    $config['server_host'] = \defined('CLICSHOPPING_APP_MCP_MC_SERVER_HOST') ? CLICSHOPPING_APP_MCP_MC_SERVER_HOST : null;
    $config['server_port'] = \defined('CLICSHOPPING_APP_MCP_MC_SERVER_PORT') ? (int)CLICSHOPPING_APP_MCP_MC_SERVER_PORT : 3001;
    $config['ssl'] = \defined('CLICSHOPPING_APP_MCP_MC_SSL') ? filter_var(CLICSHOPPING_APP_MCP_MC_SSL, FILTER_VALIDATE_BOOLEAN) : false;
    $config['token'] = \defined('CLICSHOPPING_APP_MCP_MC_TOKEN') ? CLICSHOPPING_APP_MCP_MC_TOKEN : '';
    $config['status'] = \defined('CLICSHOPPING_APP_MCP_MC_STATUS') ? CLICSHOPPING_APP_MCP_MC_STATUS : '';

    //$config['mcp_endpoint'] = defined('CLICSHOPPING_APP_MCP_MC_ENDPOINT') ? CLICSHOPPING_APP_MCP_MC_ENDPOINT : '/api/ai/chat/process';

    if (!isset($config['mcp_endpoint'])) {
      $config['mcp_endpoint'] = '/api/ai/chat/process';
    }

    if (empty($config['server_host'])) {
      error_log('[MCPConnector] Missing required config key: server_host');
      throw new McpException('Missing required config: server_host');
    }

    return $config;
  }

  /**
   * Gets the singleton instance of the `MCPConnector`.
   *
   * If an instance does not yet exist, it creates a new one by fetching the configuration from the database.
   * This ensures that only one instance of the connector is used throughout the application's lifecycle.
   *
   * @return self The single instance of the `MCPConnector` class.
   */
  public static function getInstance(): self
  {
    if (self::$instance === null) {
      $config = self::getConfigDb();

      self::$instance = new self($config);
    }

    return self::$instance;
  }

  /**
   * Sends a request to the MCP server.
   *
   * This method acts as a proxy to the underlying protocol handler, sending a request with a specified method
   * and parameters to the MCP service.
   *
   * @param string $method The name of the method to call on the server.
   * @param array $params An associative array of parameters to pass with the request.
   * @return array The result returned by the server.
   */
  public function request(string $method, array $params = []): array
  {
    $result = $this->protocol->sendRequest($method, $params);

    return $result;
  }

  /**
   * Retrieves connection statistics from the protocol handler.
   *
   * This can be used for monitoring or debugging purposes to get information about the connection.
   *
   * @return array An array containing connection statistics.
   */
  public function getStats(): array
  {
    return $this->protocol->getStats();
  }

  /**
   * Gets the current configuration settings.
   *
   * @return array The associative array of the current configuration.
   */
  public function getConfig(): array
  {
    return $this->config;
  }

  /**
   * Updates the connector's configuration with new values.
   *
   * Merges the provided array of new settings with the existing configuration and updates the transport layer.
   * It casts `server_port` to an integer and `ssl` to a boolean for proper type handling.
   *
   * @param array $newConfig An associative array of new configuration settings to apply.
   * @throws McpConfigurationException if the new configuration is invalid.
   */
  public function updateConfig(array $newConfig): void
  {
    $newConfig['server_port'] = (int)($newConfig['server_port'] ?? $this->config['server_port'] ?? 3001);
    $newConfig['ssl'] = filter_var($newConfig['ssl'] ?? $this->config['ssl'] ?? false, FILTER_VALIDATE_BOOLEAN);

    $this->config = array_merge($this->config, $newConfig);
    $this->protocol->getTransport()->setOptions($this->config);
  }


  /**
   * Retrieves the current MCP session token.
   *
   * This method reads the token from the `CLICSHOPPING_APP_MCP_MC_TOKEN` constant.
   *
   * @return string The MCP session token.
   * @throws \RuntimeException if the token constant is not defined.
   */
  public function getSessionToken(): string
  {
    if (defined('CLICSHOPPING_APP_MCP_MC_TOKEN')) {
      return CLICSHOPPING_APP_MCP_MC_TOKEN;
    }

    throw new \RuntimeException('MCP Token is not configured');
  }

  /**
   * Validates a token using a custom decryption method.
   *
   * @param string|null $token The authentication token.
   * @return bool True if the token is valid, otherwise false.
   */
  protected function validateToken(?string $token): bool
  {
    if (empty($token)) {
        return false;
    }

    try {
        // Comparer le token reçu avec le token configuré
        $configuredToken = $this->getSessionToken();
        
        // Validation simple par comparaison directe
        if ($token !== $configuredToken) {
            return false;
        }

        // Optionnel : validation de format si vous utilisez des tokens structurés
        if (strlen($token) < 8) {
            return false; // Token trop court
        }

        return true;
    } catch (\Exception $e) {
        error_log("Token validation failed: " . $e->getMessage());
        return false;
    }
  }

  /**
   * Checks if the app mcp status is enabled and if the MCP connection is valid.
   *
   * @return bool
   */
  /**
   * @return bool True if checks pass, false otherwise.
   */
  public function checkSecurity(): bool
  {
    // Check 1: STATUS activé
    if (!\defined('CLICSHOPPING_APP_MCP_MC_STATUS') || CLICSHOPPING_APP_MCP_MC_STATUS === 'False') {
        return false;
    }

    // Check 2: TOKEN configuré
    if (!\defined('CLICSHOPPING_APP_MCP_MC_TOKEN') || empty(CLICSHOPPING_APP_MCP_MC_TOKEN)) {
        return false;
    }

    // Check 3: Validation du token (corrigée)
    try {
        $token = $this->getSessionToken();
        if (!$this->validateToken($token)) {
            return false;
        }
    } catch (\RuntimeException $e) {
        error_log('MCP Token error: ' . $e->getMessage());
        return false;
    }

    return true;
  }

  /**
   * Destructor for the `MCPConnector` class.
   *
   * Ensures that the connection to the MCP server is properly closed when the object is destroyed, preventing
   * open connections and resource leaks.
   */
  public function __destruct()
  {
    if (isset($this->protocol)) {
      $this->protocol->disconnect();
    }
  }
}