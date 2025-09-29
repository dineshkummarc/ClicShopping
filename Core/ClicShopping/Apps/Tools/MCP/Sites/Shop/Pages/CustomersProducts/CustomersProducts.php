<?php
/**
 * MCP (Multi-Channel Products) API endpoint for customer product management.
 *
 * This class acts as a central hub for handling API requests related to products,
 * including listings, single product details, search, and chat-based queries.
 * It routes requests to the appropriate methods within the `Products` and `Message` classes.
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */


/*
// Example CURL commands for testing the API endpoints
# Toutes les actions autorisées
curl "http://localhost/clicshopping_test/index.php?mcp&customersProducts&action=products&limit=5"
curl "http://localhost/clicshopping_test/index.php?mcp&customersProducts&action=product&id=5"
curl "http://localhost/clicshopping_test/index.php?mcp&customersProducts&action=search&query=lavette"
curl "http://localhost/clicshopping_test/index.php?mcp&customersProducts&action=stats"
curl "http://localhost/clicshopping_test/index.php?mcp&customersProducts&action=categories"
curl "http://localhost/clicshopping_test/index.php?mcp&customersProducts&action=recommendations"
*/
namespace ClicShopping\Apps\Tools\MCP\Sites\Shop\Pages\CustomersProducts;

use AllowDynamicProperties;
use ClicShopping\OM\HTML;
use ClicShopping\OM\Registry;
use ClicShopping\OM\HTTP;

use ClicShopping\Apps\Tools\MCP\MCP;
use ClicShopping\Apps\Tools\MCP\Classes\ClicShoppingAdmin\MCPConnector;
use ClicShopping\Apps\Tools\MCP\Classes\Shop\Products;
use ClicShopping\Apps\Tools\MCP\Classes\Shop\Message;

#[AllowDynamicProperties]
/**
 * This class serves as the main entry point for the MCP Products API.
 * It handles request routing for product-related queries and chat interactions.
 */
class CustomersProducts extends \ClicShopping\OM\PagesAbstract
{
  /** @var mixed The database connection instance. */
  public mixed $db;
  /** @var mixed The language instance. */
  public mixed $lang;
  /** @var mixed The MCP application instance. */
  public mixed $app;
  /** @var mixed The Products class instance for product-related logic. */
  public mixed $product;
  /** @var mixed The Message class instance for sending API responses. */
  public mixed $message;

  private mixed $mcpConnector;

  /**
   * Initializes the class by setting up dependencies, headers, and request routing.
   * It handles all incoming API requests (GET, POST, OPTIONS) and routes them to the appropriate handler.
   *
   * @return void
   */
  protected function init()
  {
    $this->db = Registry::get('Db');
    $this->lang = Registry::get('Language');

    // Set JSON content type
    header('Content-Type: application/json');

    // Enable CORS for MCP server
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

    if (!Registry::exists('MCP')) {
      Registry::set('MCP', new MCP());
    }
    $this->app = Registry::get('MCP');

    Registry::set('Products', new Products());
    $this->product = Registry::get('Products');

    if (!Registry::exists('Message')) {
      Registry::set('Message', new Message());
    }
    $this->message = Registry::get('Message');

    if (!Registry::exists('MCPConnector')) {
      Registry::set('MCPConnector', new MCPConnector());
    }
    $this->mcpConnector = Registry::get('MCPConnector');

    $result = $this->mcpConnector->checkSecurity();

    if (!$result) {
      $this->message->sendError('Unauthorized', 401);
      exit;
    }

    //Allow or not to display the json inside the browser
    // True = Production mode (only search allowed for browser access)
    // False = Test mode (all actions allowed for browser access)
    $isProductionMode = \defined('CLICSHOPPING_APP_MCP_MC_DISPLAY_BROWSER_JSON') && CLICSHOPPING_APP_MCP_MC_DISPLAY_BROWSER_JSON == 'True';

    // Always get the action parameter for processing
    $action = HTML::sanitize($_GET['action']) ?? 'products';

    if ($isProductionMode && $_SERVER['REQUEST_METHOD'] == 'GET') {
      // In production mode, only search is allowed for browser access
      if ($action !== 'search') {
        header('Content-Type: text/plain');
        http_response_code(403);
        echo 'Access to this API endpoint is restricted in production mode. Only search action is allowed for direct browser access.';
        exit;
      }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
      http_response_code(200);
      exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
      $this->handleChatRequest();
      return;
    }

    try {
      match($action) {
        'products' => $this->product->getProductsList(),
        'product' => $this->product->getProductDetail(),
        'categories' => $this->product->getCategories(),
        'search' => $this->product->handleSearchQuery(),
        'stats' => $this->product->getProductStats(),
        'recommendations' => $this->product->getProductRecommendations(),
        default => $this->message->sendError('Invalid action', 400)
      };
    } catch (\Exception $e) {
      error_log('MCP Products API Error: ' . $e->getMessage());
      $this->message->sendError($e->getMessage(), 500);
    }
  }

  /**
   * Handles chat requests for administrators (RAG BI).
   *
   * @param string $message The chat message from the user.
   * @param mixed $context The context of the chat session.
   * @return void
   */
  private function handleAdminChat(string $message, mixed $context): void
  {
    try {
      // Check if the user is an admin.
      if (!$this->isAdmin($context)) {
        $this->message->sendError('Admin access required', 403);
        return;
      }

      // Redirect to RAG BI for admin queries.
      $this->redirectToRagBI($message, $context);
    } catch (\Exception $e) {
      error_log('Admin chat error: ' . $e->getMessage());
      $this->message->sendError('Admin chat error: ' . $e->getMessage(), 500);
    }
  }

  /**
   * Handles chat requests for customers (product-related only).
   *
   * @param string $message The chat message from the customer.
   * @param mixed $context The context of the chat session.
   * @return void
   */
  private function handleClientChat(string $message, mixed $context): void
  {
    try {
      // Analyze the product search intent.
      $intent = $this->product->analyzeProductIntent($message);

      // Execute the product search based on the detected intent.
      $results = $this->product->executeProductSearch($intent, $context);

      // Generate the response message for the client.
      $response = $this->generateProductResponse($results, $intent);

      // Return the JSON response.
      $this->message->sendSuccess([
        'response' => $response,
        'type' => 'product_info',
        'confidence' => $intent['confidence'],
        'products' => $results['products'],
        'metadata' => [
          'search_time' => microtime(true),
          'result_count' => count($results['products']),
          'user_mode' => 'client',
          'intent' => $intent['type']
        ]
      ]);
    } catch (\Exception $e) {
      error_log('Client chat error: ' . $e->getMessage());
      $this->message->sendError('Client chat error: ' . $e->getMessage(), 500);
    }
  }

  /**
   * Handles the main chat request, decoding the JSON input and routing to
   * either the admin or client chat handler.
   *
   * @return void
   */
  private function handleChatRequest(): void
  {
    try {
      $input = json_decode(file_get_contents('php://input'), true);
      if (!$input) { $this->message->sendError('Invalid JSON input', 400); return; }

      $message = $input['message'] ?? '';
      $context = $input['context'] ?? [];

      if (empty($message)) {
        $this->message->sendError('Message is required', 400); return;
      }

      ($context['user_type'] ?? 'client') === 'admin'
        ? $this->handleAdminChat($message, $context)
        : $this->handleClientChat($message, $context);

    } catch (\Exception $e) {
      error_log('MCP Chat Error: ' . $e->getMessage());
      $this->message->sendError('Chat processing error: ' . $e->getMessage(), 500);
    }
  }

  /**
   * Generates a formatted text response for the customer based on product search results.
   *
   * @param array $results The search results containing product data.
   * @param array $intent The detected user intent and filters.
   * @return string The formatted response message.
   */
  private function generateProductResponse(array $results, array $intent): string
  {
    if (empty($results['products'])) {
      return "🔍 No products found for your search.";
    }

    $response = "🛍️ **I found " . count($results['products']) . " product(s):**\n\n";

    // Display applied filters and detected intent
    if (!empty($intent['filters'])) {
      $response .= "**Applied Filters:** ";
      $filters = [];
      foreach ($intent['filters'] as $key => $value) {
        $filters[] = "$key: $value";
      }
      $response .= implode(", ", $filters) . "\n";
    }
    if (!empty($intent['type'])) {
      $response .= "**Detected Intent:** " . $intent['type'] . "\n\n";
    }

    foreach ($results['products'] as $i => $p) {
      $response .= "**" . ($i + 1) . ". " . ($p['products_name'] ?? '') . "**\n";
      $response .= "💰 Price: " . ($p['products_price'] ?? 'N/A') . "€\n";
      $response .= "📦 Stock: " . ($p['products_quantity'] ?? 'N/A') . " units\n";

      $ean = $p['products_ean'] ?? '';
      $sku = $p['products_sku'] ?? '';
      $mpn = $p['products_mpn'] ?? '';
      $isbn = $p['products_isbn'] ?? '';
      $upc = $p['products_upc'] ?? '';
      $jan = $p['products_jan'] ?? '';
      $weight = $p['products_weight'] ?? '';

      if ($ean || $sku || $mpn || $isbn || $upc || $jan) {
        $response .= "📑 References: ";
        $refs = [];
        if ($ean) $refs[] = "EAN: $ean";
        if ($sku) $refs[] = "SKU: $sku";
        if ($mpn) $refs[] = "MPN: $mpn";
        if ($isbn) $refs[] = "ISBN: $isbn";
        if ($upc) $refs[] = "UPC: $upc";
        if ($jan) $refs[] = "JAN: $jan";
        $response .= implode(", ", $refs) . "\n";
      }

      if ($weight) {
        $response .= "⚖️ Weight: $weight kg\n";
      }

      $response .= !empty($p['category_name']) ? "🏷️ Category: " . $p['category_name'] . "\n" : "";
      $response .= "\n";
    }

    return $response;
  }

  /**
   * Checks if the user is an administrator based on the context array.
   *
   * @param array $context The context containing user information.
   * @return bool True if the user is an admin, otherwise false.
   */
  private function isAdmin(array $context): bool
  {
    return isset($context['user_type']) && $context['user_type'] === 'admin';
  }

  /**
   * Redirects to the RAG BI service for administrators.
   *
   * @param string $message The chat message.
   * @param mixed $context The chat context.
   * @return void
   */
  private function redirectToRagBI(string $message, mixed $context): void
  {
    // Build the RAG BI URL
    $ragBiUrl = $this->app->getRagBIEndpoint();

    // Prepare data for RAG BI
    $ragBiData = [
      'message' => $message,
      'context' => $context,
      'admin_mode' => true
    ];

    // Call RAG BI
    $response = $this->callRagBI($ragBiUrl, $ragBiData);

    if ($response) {
      $this->message->sendSuccess([
        'response' => $response['response'],
        'type' => 'admin-rag',
        'confidence' => $response['confidence'] ?? 0.8,
        'metadata' => [
          'admin_mode' => true,
          'rag_bi_used' => true,
          'query_time' => microtime(true)
        ]
      ]);
    } else {
      $this->message->sendError('MCP CustomersProducts service unavailable', 503);
    }
  }

  /**
   * Calls the RAG BI service via an HTTP request.
   *
   * @param string $url The URL of the RAG BI service.
   * @param array $data The data to send in the request body.
   * @return array|null The decoded JSON response from the service, or null on failure.
   */
  private function callRagBI(string $url, array $data): ?array
  {
    try {
      $response = HTTP::getResponse([
        'url' => $url,
        'headers' => [
          'Content-Type' => 'application/json',
          'Accept' => 'application/json'
        ],
        'parameters' => json_encode($data),
        'method' => 'post'
      ]);

      if ($response->getStatusCode() === 200) {
        return json_decode($response->getBody()->getContents(), true);
      }

      return null;
    } catch (\Exception $e) {
      error_log('MCP CustomersProducts API error: ' . $e->getMessage());
      $this->message->sendError($e->getMessage(), 500);
    }

    return null;
  }
}