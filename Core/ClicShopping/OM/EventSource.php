<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\OM;

use Exception;
use ClicShopping\OM\HTTP;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;


/*
 // Configuration pour ClicShopping
$eventSource = new EventSource('https://api.example.com/events');
$eventSource->setHttpOptions([
    'cafile' => CLICSHOPPING::BASE_DIR . 'External/cacert.pem',
    'certificate' => '/path/to/client.pem' // si nécessaire
]);
 */

/**
 * Class EventSource
 *
 * This class implements a server-sent events (SSE) client that connects to an event source URL,
 * processes incoming events, and supports automatic reconnection with exponential backoff.
 */
class EventSource
{
  private string $url;
  private array $headers = [];
  private array $httpOptions = [];
  private bool $aborted = false;
  private bool $connected = false;

  // Callbacks
  private array $messageHandlers = [];
  private $onOpen = null;
  private $onError = null;
  private $onClose = null;

  // Configuration
  private int $reconnectDelay = 3000; // ms
  private int $maxReconnectAttempts = 5;
  private int $currentReconnectAttempt = 0;
  private bool $autoReconnect = true;

  private LoggerInterface $logger;
  private ?string $lastEventId = null;

  /**
   * EventSource constructor.
   *
   * @param string $url The URL of the event source to connect to.
   * @param LoggerInterface|null $logger Optional logger for debugging and error handling.
   */
  public function __construct(string $url, LoggerInterface $logger = null)
  {
    $this->url = $url;
    $this->logger = $logger ?? new NullLogger();

    $this->setDefaultHeaders();
    $this->setDefaultGuzzleOptions();
  }

  /**
   * Set default headers for the EventSource connection.
   * These headers are typically required for SSE connections.
   */
  private function setDefaultHeaders(): void
  {
    $this->headers = [
      'Accept' => 'text/event-stream',
      'Cache-Control' => 'no-cache',
      'Connection' => 'keep-alive'
    ];
  }

  /**
   * Set default Guzzle options for the EventSource connection.
   * This includes SSL certificate verification settings.
   */
  private function setDefaultGuzzleOptions(): void
  {
    $this->httpOptions = [
      'cafile' => CLICSHOPPING::BASE_DIR . 'External/cacert.pem'
    ];
  }

   /**
   * Setters for configuration options
   *
   * These methods allow you to customize the EventSource connection settings.
   */
  public function setHeaders(array $headers): self
  {
    $this->headers = array_merge($this->headers, $headers);
    return $this;
  }

  /**
   * Set custom HTTP options for the EventSource connection.
   * This can include SSL certificates, timeouts, etc.
   *
   * @param array $options Associative array of HTTP options.
   * @return self
   */
  public function setHttpOptions(array $options): self
  {
    $this->httpOptions = array_merge($this->httpOptions, $options);
    return $this;
  }

  /**
   * Set the URL for the EventSource connection.
   *
   * @param string $url The URL to connect to.
   * @return self
   */
  public function setReconnectDelay(int $milliseconds): self
  {
    $this->reconnectDelay = $milliseconds;
    return $this;
  }

  /**
   * Set the maximum number of reconnection attempts.
   *
   * @param int $attempts The maximum number of reconnection attempts.
   * @return self
   */
  public function setMaxReconnectAttempts(int $attempts): self
  {
    $this->maxReconnectAttempts = $attempts;
    return $this;
  }

  /**
   * Set whether to automatically reconnect on connection loss.
   *
   * @param bool $autoReconnect True to enable automatic reconnection, false to disable.
   * @return self
   */
  public function setAutoReconnect(bool $autoReconnect): self
  {
    $this->autoReconnect = $autoReconnect;
    return $this;
  }

  /**
   * Set the Last-Event-ID header for the EventSource connection.
   * This is used to resume the connection from the last event received.
   *
   * @param string $lastEventId The Last-Event-ID to set.
   * @return self
   */
  public function onMessage(callable $handler): self
  {
    $this->messageHandlers[] = $handler;
    return $this;
  }

  /**
   * Set a callback to be called when the connection opens.
   *
   * @param callable $handler The callback to call on open.
   * @return self
   */
  public function onOpen(callable $handler): self
  {
    $this->onOpen = $handler;
    return $this;
  }

  /**
   * Set a callback to be called when an error occurs.
   *
   * @param callable $handler The callback to call on error.
   * @return self
   */
  public function onError(callable $handler): self
  {
    $this->onError = $handler;
    return $this;
  }

  /**
   * Set a callback to be called when the connection closes.
   *
   * @param callable $handler The callback to call on close.
   * @return self
   */
  public function onClose(callable $handler): self
  {
    $this->onClose = $handler;
    return $this;
  }

  /**
   * Connect to the EventSource URL and start processing events.
   *
   * This method initiates the connection and begins listening for events.
   * It will automatically handle reconnections if configured to do so.
   *
   * @throws Exception If the connection fails or is already connected.
   */
  public function connect(): void
  {
    if ($this->connected) {
      throw new Exception('EventSource is already connected');
    }

    $this->logger->info('Connecting to EventSource', ['url' => $this->url]);

    try {
      $this->performConnection();
    } catch (Exception $e) {
      $this->handleConnectionError($e);
    }
  }

  /**
   * Perform the actual connection to the EventSource URL.
   *
   * This method handles the HTTP request and processes incoming events.
   * It will also manage reconnections if configured to do so.
   *
   * @throws Exception If the connection fails or an error occurs during processing.
   */
  private function performConnection(): void
  {
    // Préparer les headers pour ClicShopping HTTP
    $headers = $this->headers;

    // Ajouter Last-Event-ID si disponible
    if ($this->lastEventId) {
      $headers['Last-Event-ID'] = $this->lastEventId;
    }

    // Convertir les headers au format attendu par ClicShopping HTTP
    $headerStrings = [];
    foreach ($headers as $name => $value) {
      $headerStrings[] = "{$name}: {$value}";
    }

    // Préparer les données pour la classe HTTP de ClicShopping
    $httpData = [
      'url' => $this->url,
      'method' => 'get',
      'header' => $headerStrings
    ];

    // Ajouter les options HTTP personnalisées
    if (isset($this->httpOptions['cafile'])) {
      $httpData['cafile'] = $this->httpOptions['cafile'];
    }
    if (isset($this->httpOptions['certificate'])) {
      $httpData['certificate'] = $this->httpOptions['certificate'];
    }

    $response = HTTP::getResponse($httpData);

    if ($response === false) {
      throw new Exception("HTTP request failed for URL: {$this->url}");
    }

    $this->connected = true;
    $this->currentReconnectAttempt = 0;

    if ($this->onOpen && is_callable($this->onOpen)) {
      ($this->onOpen)();
    }

    // Traitement du stream de données
    $this->processStreamData($response);

    $this->connected = false;

    if ($this->onClose && is_callable($this->onClose)) {
      ($this->onClose)();
    }

    // Reconnexion automatique si nécessaire
    if (!$this->aborted && $this->autoReconnect &&
      $this->currentReconnectAttempt < $this->maxReconnectAttempts) {

      $this->scheduleReconnect();
    }
  }

  /**
   * Process the incoming stream data from the EventSource.
   *
   * This method reads the response data in chunks, processes each event,
   * and handles reconnections if necessary.
   *
   * @param string $responseData The raw response data from the EventSource.
   */
  private function processStreamData(string $responseData): void
  {
    $buffer = '';
    $offset = 0;
    $dataLength = strlen($responseData);

    // Simulation du streaming en traitant les données par chunks
    while ($offset < $dataLength && !$this->aborted) {
      $chunkSize = min(1024, $dataLength - $offset);
      $chunk = substr($responseData, $offset, $chunkSize);
      $buffer .= $chunk;
      $offset += $chunkSize;

      // Traiter les événements complets (séparés par double retour ligne)
      while (($pos = strpos($buffer, "\n\n")) !== false) {
        $eventData = substr($buffer, 0, $pos);
        $buffer = substr($buffer, $pos + 2);

        if (!empty(trim($eventData))) {
          $this->processEventData($eventData);
        }
      }

      // Petite pause pour simuler le streaming et permettre l'interruption
      if (!$this->aborted) {
        usleep(10000); // 10ms
      }
    }

    // Traiter le reste du buffer s'il contient des données
    if (!empty(trim($buffer))) {
      $this->processEventData($buffer);
    }
  }

  /**
   * Process a single event from the EventSource.
   *
   * This method parses the event data, calls the appropriate handlers,
   * and handles any errors that occur during processing.
   *
   * @param string $eventData The raw event data to process.
   */
  private function processEventData(string $eventData): void
  {
    try {
      $event = new Event($eventData);

      // Sauvegarder l'ID pour la reconnexion
      if ($event->hasId()) {
        $this->lastEventId = $event->getId();
      }

      // Appeler tous les handlers
      foreach ($this->messageHandlers as $handler) {
        if (is_callable($handler)) {
          $handler($event);
        }
      }

    } catch (Exception $e) {
      $this->logger->warning('Failed to process event', [
        'error' => $e->getMessage(),
        'eventData' => $eventData
      ]);

      if ($this->onError && is_callable($this->onError)) {
        ($this->onError)($e);
      }
    }
  }

  /**
   * Handle connection errors and manage reconnection logic.
   *
   * This method logs the error, calls the onError callback if set,
   * and schedules a reconnection if autoReconnect is enabled.
   *
   * @param Exception $e The exception that occurred during the connection.
   */
  private function handleConnectionError(Exception $e): void
  {
    $this->connected = false;
    $this->logger->error('EventSource connection error', [
      'error' => $e->getMessage(),
      'attempt' => $this->currentReconnectAttempt
    ]);

    if ($this->onError && is_callable($this->onError)) {
      ($this->onError)($e);
    }

    if (!$this->aborted && $this->autoReconnect &&
      $this->currentReconnectAttempt < $this->maxReconnectAttempts) {

      $this->scheduleReconnect();
    } else {
      throw $e;
    }
  }

  /**
   * Schedule a reconnection attempt with exponential backoff.
   *
   * This method increases the reconnect attempt count, calculates the delay,
   * and attempts to reconnect after the specified delay.
   */
  private function scheduleReconnect(): void
  {
    $this->currentReconnectAttempt++;
    $delay = $this->reconnectDelay * $this->currentReconnectAttempt; // Backoff exponentiel

    $this->logger->info('Scheduling reconnect', [
      'attempt' => $this->currentReconnectAttempt,
      'delay' => $delay
    ]);

    usleep($delay * 1000); // Convertir ms en microseconds

    if (!$this->aborted) {
      try {
        $this->performConnection();
      } catch (Exception $e) {
        $this->handleConnectionError($e);
      }
    }
  }

  /**
   * Abort the EventSource connection.
   *
   * This method stops the connection and prevents any further events from being processed.
   * It can be called to gracefully shut down the connection.
   */
  public function abort(): void
  {
    $this->aborted = true;
    $this->autoReconnect = false;
    $this->logger->info('EventSource connection aborted');
  }

  /**
   * Check if the EventSource is currently connected.
   *
   * @return bool True if connected, false otherwise.
   */
  public function isConnected(): bool
  {
    return $this->connected;
  }

  /**
   * Check if the EventSource connection has been aborted.
   *
   * @return bool True if the connection has been aborted, false otherwise.
   */
  public function isAborted(): bool
  {
    return $this->aborted;
  }

  /**
   * Get the last event ID received from the EventSource.
   *
   * This ID can be used to resume the connection from the last event received.
   *
   * @return string|null The last event ID, or null if not set.
   */
  public function getLastEventId(): ?string
  {
    return $this->lastEventId;
  }

  /**
   * Get the current reconnect attempt count.
   *
   * This method returns the number of reconnection attempts made so far.
   *
   * @return int The current reconnect attempt count.
   */
  public function getReconnectAttempt(): int
  {
    return $this->currentReconnectAttempt;
  }
}
