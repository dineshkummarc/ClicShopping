<?php
/**
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Tools\MCP\Classes\ClicShoppingAdmin\Transport;

use ClicShopping\Apps\Tools\MCP\Classes\ClicShoppingAdmin\Exceptions\McpConnectionException;
use ClicShopping\Apps\Tools\MCP\Classes\ClicShoppingAdmin\Exceptions\McpException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Class SseTransport
 *
 * This class provides a transport layer for the MCP (Model Context Protocol) using
 * Server-Sent Events (SSE) over HTTP. It handles the communication details, including
 * connection management, sending messages, and error handling.
 */
class SseTransport implements TransportInterface
{
  /**
   * @var array The configuration settings for the transport.
   */
  private array $config;

  /**
   * @var LoggerInterface The logger instance for logging events.
   */
  private LoggerInterface $logger;

  /**
   * @var bool The current connection status.
   */
  private bool $connected = false;

  /**
   * @var string The base URL for the MCP server.
   */
  private string $baseUrl;

  /**
   * @var array The HTTP headers to be sent with requests.
   */
  private array $headers;

  /**
   * @var array An associative array to store transport-level statistics.
   */
  private array $stats = [
    'messages_sent' => 0,
    'messages_received' => 0,
    'errors' => 0,
    'connection_time' => null,
    'last_activity' => null,
    'http_errors' => []
  ];

  /**
   * @var array A collection of recent error messages.
   */
  private array $recentErrors = [];

  /**
   * @var int The total number of messages received.
   */
  private int $totalMessages = 0;

  /**
   * @var int The number of connection attempts.
   */
  private int $connectionAttempts = 0;

  /**
   * @var callable|null The callback function to handle incoming messages.
   */
  private $messageCallback;

  /**
   * @var resource|null The cURL resource for the connection.
   */
  private $connection = null;

  /**
   * @var int|null The timestamp of the last ping received.
   */
  private ?int $lastPing = null;

  /**
   * SseTransport constructor.
   *
   * Initializes the transport with configuration and a logger, and performs a
   * preliminary configuration validation.
   *
   * @param array $config The configuration settings.
   * @param LoggerInterface|null $logger An optional PSR-3 logger instance.
   * @throws McpException If the configuration is invalid or incomplete.
   */
  public function __construct(array $config, ?LoggerInterface $logger = null)
  {
    $this->config = $config;
    $this->logger = $logger ?? new NullLogger();

    $this->validateConfig();
    $this->setupConnection();
  }

  /**
   * Validates the required configuration.
   *
   * @throws McpException If a required configuration key is missing or invalid.
   */
  private function validateConfig(): void
  {
    $required = ['server_host', 'server_port'];
    foreach ($required as $key) {
      if (empty($this->config[$key])) {
        throw new McpException("Missing required config: {$key}");
      }
    }

    if (!is_numeric($this->config['server_port'])) {
      throw new McpException('Port must be numeric');
    }
  }

  /**
   * Sets up the HTTP connection parameters.
   *
   * This method constructs the base URL and sets the default HTTP headers, including
   * authorization headers if a token is provided in the configuration.
   */
  private function setupConnection(): void
  {
    $protocol = $this->config['ssl'] ?? false ? 'https' : 'http';
    $this->baseUrl = "{$protocol}://{$this->config['server_host']}:{$this->config['server_port']}";

    $this->headers = [
      'Content-Type: application/json',
      'Accept: application/json',
      'User-Agent: ClicShopping-MCP-Client/1.0',
      'X-MCP-Protocol-Version: 2024-11-05'
    ];

    if (!empty($this->config['token'])) {
      $this->headers[] = 'Authorization: Bearer ' . $this->config['token'];
    }
  }

  /**
   * Sets transport options.
   *
   * @param array $options Configuration options for the transport.
   */
  public function setOptions(array $options): void
  {
    $this->config = array_merge($this->config, $options);
    $this->setupConnection();
  }

  /**
   * Establishes a connection to the MCP server.
   *
   * This method performs a health check using cURL to verify connectivity before
   * marking the transport as connected.
   *
   * @return bool True if the connection is successful.
   * @throws McpConnectionException If the connection fails for any reason.
   */
  final public function connect(): bool
  {
    if ($this->connected) {
      return true;
    }

    // Test de connexion avec un health check
    $healthUrl = $this->baseUrl . ($this->config['health_endpoint'] ?? '/health');

    $this->logger->info('Testing connection to MCP server', ['url' => $healthUrl]);

    $ch = curl_init();
    curl_setopt_array($ch, [
      CURLOPT_URL => $healthUrl,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => $this->config['connection_timeout'] ?? 10,
      CURLOPT_CONNECTTIMEOUT => 5,
      CURLOPT_HTTPHEADER => $this->headers,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_MAXREDIRS => 3,
      CURLOPT_SSL_VERIFYPEER => $this->config['ssl_verify'] ?? true,
      CURLOPT_SSL_VERIFYHOST => 2, // Fix security issue - always verify host
      CURLOPT_USERAGENT => 'ClicShopping-MCP-Client/1.0'
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);

    if ($error) {
      $this->logger->error('cURL connection error', ['error' => $error, 'url' => $healthUrl]);
      throw new McpConnectionException("Connection failed: {$error}");
    }

    // Accepter les codes 200, 404 (si /health n'existe pas), ou 405 (méthode non supportée)
    $acceptableCodes = [200, 404, 405];

    if (!in_array($httpCode, $acceptableCodes)) {
      $this->logger->error('HTTP connection test failed', [
        'http_code' => $httpCode,
        'response' => $response,
        'url' => $healthUrl
      ]);

      throw new McpConnectionException("HTTP connection test failed with code: {$httpCode}");
    }

    $this->connected = true;
    $this->stats['connection_time'] = time();
    $this->stats['last_activity'] = time();

    $this->logger->info('HTTP transport connected successfully', [
      'url' => $this->baseUrl,
      'http_code' => $httpCode,
      'connect_time' => $info['connect_time'] ?? 0
    ]);

    return true;
  }

  /**
   * Sends a message to the MCP server.
   *
   * This method uses cURL to send a POST request with the message payload. It handles
   * various network and HTTP errors and returns the decoded JSON response.
   *
   * @param array $message The message payload to send.
   * @return array|null The decoded JSON response from the server, or null if an error occurs.
   * @throws McpConnectionException If the request fails.
   * @throws McpException If the server returns invalid JSON.
   */
  final public function send(array $message): ?array
  {
    $endpoint = '/api/ai/chat/process';
    $this->baseUrl = "http://localhost:3001";
    $url = $this->baseUrl . $endpoint;

    $ch = curl_init();
    $jsonData = json_encode($message);

    curl_setopt_array($ch, [
      CURLOPT_URL => $url,
      CURLOPT_POST => true,
      CURLOPT_POSTFIELDS => $jsonData,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => $this->config['timeout'] ?? 30,
      CURLOPT_CONNECTTIMEOUT => $this->config['connection_timeout'] ?? 10,
      CURLOPT_HTTPHEADER => array_merge($this->headers, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $this->config['token'],
        'Content-Length: ' . strlen($jsonData)
      ]),
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_MAXREDIRS => 3,
      CURLOPT_SSL_VERIFYPEER => $this->config['ssl_verify'] ?? true,
      CURLOPT_SSL_VERIFYHOST => 2,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1
    ]);

    $response = curl_exec($ch);

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    $info = curl_getinfo($ch);

    curl_close($ch);

    $this->stats['messages_sent']++;
    $this->stats['last_activity'] = time();


    if ($response === false) {
      $this->stats['errors']++;
      $this->logger->error('Curl execution failed', [
        'error' => $error,
        'url' => $url,
        'method' => $message['method'] ?? 'unknown'
      ]);
      throw new \Exception('Curl error: ' . $error);
    }

    if ($error) {
      $this->stats['errors']++;
      $this->stats['http_errors'][] = [
        'time' => time(),
        'error' => $error,
        'url' => $url
      ];

      $this->logger->error('cURL request error', [
        'error' => $error,
        'url' => $url,
        'method' => $message['method'] ?? 'unknown'
      ]);

      throw new McpConnectionException("Request failed: {$error}");
    }

    if ($httpCode < 200 || $httpCode >= 300) {
      $this->stats['errors']++;
      $this->stats['http_errors'][] = [
        'time' => time(),
        'code' => $httpCode,
        'response' => $response,
        'url' => $url
      ];

      $this->logger->error('MCP chat request failed', [
        'code' => $httpCode,
        'response' => substr($response, 0, 1000),
        'url' => $url,
        'method' => $message['method'] ?? 'unknown',
        'id' => $message['id'] ?? 'notification',
        'request_payload' => $jsonData
      ]);

      throw new McpConnectionException("HTTP error: {$httpCode}");
    }

    if (isset($message['id'])) {
      return [];
    }

    $decoded = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
      $this->stats['errors']++;
      $this->logger->error('Invalid JSON response', [
        'response' => substr($response, 0, 500),
        'json_error' => json_last_error_msg(),
        'method' => $message['method'] ?? 'unknown'
      ]);
      throw new McpException('Invalid JSON response from server');
    }

    $this->stats['messages_received']++;

    $this->logger->error('MCP chat request failed', [
      'method' => $message['method'] ?? 'unknown',
      'id' => $message['id'] ?? 'notification',
      'url' => $url,
      'http_code' => $httpCode,
      'response' => substr($response, 0, 1000),
      'request_payload' => $jsonData,
    ]);

    return $decoded;
  }

  /**
   * Closes the connection to the MCP server.
   */
  final public function disconnect(): void
  {
    if ($this->connected) {
      $this->logger->info('Disconnecting HTTP transport');
      $this->connected = false;
    }
  }

  /**
   * Gets the transport statistics.
   *
   * @return array An associative array of transport statistics.
   */
  final public function getStats(): array
  {
    return array_merge($this->stats, [
      'base_url' => $this->baseUrl,
      'recent_errors' => $this->recentErrors,
      'connected' => $this->connected,
      'last_ping' => $this->lastPing,
      'total_messages' => $this->totalMessages,
      'connection_attempts' => $this->connectionAttempts
    ]);
  }

  /**
   * Checks if the transport is currently connected.
   *
   * @return bool True if connected, false otherwise.
   */
  final public function isConnected(): bool
  {
    return $this->connected;
  }

  /**
   * Handles an incoming Server-Sent Event (SSE) message.
   *
   * @param string $data The raw message data from the SSE stream.
   */
  final protected function handleMessage(string $data): void
  {
    $this->totalMessages++;

    if ($data === 'ping') {
      $this->lastPing = time();
      return;
    }

    try {
      $message = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
      if (isset($message['error'])) {
        $this->recentErrors[] = $message['error'];
        if (count($this->recentErrors) > 10) {
          array_shift($this->recentErrors);
        }
      }

      if (isset($this->messageCallback)) {
        call_user_func($this->messageCallback, $message);
      }
    } catch (\JsonException $e) {
      $this->recentErrors[] = 'Invalid JSON: ' . $e->getMessage();
    }
  }

  /**
   * Sets the callback function for message handling.
   *
   * @param callable $callback The callback function.
   */
  final public function onMessage(callable $callback): void
  {
    $this->messageCallback = $callback;
  }

  /**
   * Closes the underlying cURL connection.
   */
  final public function close(): void
  {
    if ($this->connected && $this->connection) {
      fclose($this->connection);
      $this->connected = false;
    }
  }

  /**
   * Destructor to ensure the connection is closed.
   */
  public function __destruct()
  {
    $this->close();
  }
}