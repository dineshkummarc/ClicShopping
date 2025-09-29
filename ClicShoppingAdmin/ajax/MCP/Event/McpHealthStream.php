<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */


/**
 * MCP Health Check Stream
 *
 * This script provides a Server-Sent Events (SSE) stream for real-time health checks
 * of the MCP (Modular Content Platform) system. It continuously sends health status updates
 * every 3 seconds.
 *
 * Usage:
 * - Access this script via a web browser or an SSE-capable client.
 * - The client will receive JSON-encoded health data as events.
 *
 * Note:
 * - Ensure that the server supports SSE and that output buffering is disabled.
 * - Adjust the sleep duration as needed for your application's requirements.
 */
use ClicShopping\Apps\Tools\MCP\Classes\ClicShoppingAdmin\McpHealth;
use ClicShopping\OM\CLICSHOPPING;


// Include the necessary core files
define('CLICSHOPPING_BASE_DIR', realpath(__DIR__ . '/../../../../Core/ClicShopping/') . '/');


require_once(CLICSHOPPING_BASE_DIR . 'OM/CLICSHOPPING.php');
spl_autoload_register('ClicShopping\OM\CLICSHOPPING::autoload');

// Initialize the application environment
CLICSHOPPING::initialize();
CLICSHOPPING::loadSite('ClicShoppingAdmin');

// Set the appropriate headers for Server-Sent Events
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

// Disable output buffering to allow real-time streaming
if (ob_get_level() > 0) {
  ob_end_clean();
}

/**
 * Sends a single Server-Sent Event.
 *
 * @param string $event The event name.
 * @param array $data The data to be sent, will be JSON-encoded.
 * @return void
 */
function sendEvent(string $event, array $data): void
{
  echo "event: " . $event . "\n";
  echo "data: " . json_encode($data, JSON_UNESCAPED_SLASHES) . "\n\n";
  flush();
}

try {
  // Get the MCP Health service
  $mcpHealth = McpHealth::getInstance();
  $counter = 0;

  // Loop to continuously send health data
  while (true) {
    try {
      $healthData = $mcpHealth->check();
      // Ensure the data is an array before sending
      if (!is_array($healthData)) {
        $healthData = ['status' => 'error', 'message' => 'Invalid data from health check.'];
      }
    } catch (Exception $e) {
      // If an error occurs, send a structured error message
      $healthData = ['status' => 'error', 'message' => 'Health check failed: ' . $e->getMessage()];
    }

    sendEvent('healthcheck', $healthData);
    sleep(3);
  }
} catch (Exception $e) {
  // Log and send any errors that occur
  sendEvent('error', ['message' => $e->getMessage()]);
  exit;
}


