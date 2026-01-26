<?php
/**
 * Process Hook for Orders (Shop)
 * 
 * Generates and STORES order insights after order creation using LLM.
 * This hook is triggered automatically after an order is placed in the Shop.
 * 
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 * 
 * Triggered by: Hooks::call('Orders', 'Process') in Shop after order creation
 * 
 * The insights are stored in rag_order_insights table for later display in admin.
 * 
 * Requirements: 3.1, 3.3
 */

namespace ClicShopping\Apps\AI\Ecommerce\Module\Hooks\Shop\Orders;

use ClicShopping\OM\Registry;
use ClicShopping\Sites\Common\HTMLOverrideCommon;

use ClicShopping\Apps\AI\Ecommerce\Ecommerce as EcommerceApp;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\Gpt;
use ClicShopping\AI\DomainsAI\CoreAI\Embedding\NewVector;

/**
 * Process Hook (Shop)
 * 
 * Generates order insights after order creation and stores them in database.
 * This allows the admin to view pre-generated insights without waiting for LLM.
 */
class Process implements \ClicShopping\OM\Modules\HooksInterface
{
  public mixed $app;
  public mixed $language;
  private bool $debug = false;
  
  public function __construct()
  {
    if (!Registry::exists('Ecommerce')) {
      Registry::set('Ecommerce', new EcommerceApp());
    }
    
    $this->app = Registry::get('Ecommerce');
    $this->language = Registry::get('Language');
    
    // Load language definitions for this hook
    $this->app->loadDefinitions('Module/Hooks/Shop/Orders/process');
    
    // Enable debug mode if constant is set
    if (\defined('CLICSHOPPING_APP_ECOMMERCE_EC_DEBUG') && CLICSHOPPING_APP_ECOMMERCE_EC_DEBUG === 'True') {
      $this->debug = true;
    }
  }
  
  /**
   * Execute method - Generates and stores insights after order creation
   * 
   * This is called automatically after an order is placed in the Shop.
   * It generates insights asynchronously and stores them for later viewing.
   * 
   * @param array|null $parameters Parameters passed from the hook call, including 'order_id'
   */
  public function execute(?array $parameters = null)
  {

    if (Gpt::checkGptStatus() === false || CLICSHOPPING_APP_CHATGPT_RA_STATUS  == 'False' ||
      CLICSHOPPING_APP_CHATGPT_RA_OPENAI_EMBEDDING == 'False' || CLICSHOPPING_APP_ECOMMERCE_EC_STATUS == 'False' ||
      CLICSHOPPING_APP_ECOMMERCE_EC_INSIGHT == 'False'
    ) {
      return false;
    }


    $startTime = microtime(true);
    
    try {
      // Get order ID from parameters (passed by Order::process())
      $orderId = $parameters['order_id'] ?? null;
      
      // Fallback to session for test environment
      if (empty($orderId) && isset($_SESSION['last_order_id'])) {
        $orderId = (int)$_SESSION['last_order_id'];
      }
      
      if ($this->debug) {
        error_log("\n[DEBUG] [Process Hook Shop] Generating insights for order #{$orderId}");
      }

      $orderData = $this->fetchOrderData((int)$orderId);
      
      if (empty($orderData)) {
        error_log("[ERROR] [Process Hook Shop] Order #{$orderId} not found");
        return;
      }
      
      // Generate insights for different types
      $insightTypes = ['summary', 'recommendations'];
      $allInsights = [];
      
      foreach ($insightTypes as $insightType) {
        try {
          if ($this->debug) {
            error_log("[PROC] [Process Hook Shop] Generating '{$insightType}' insights...");
          }
          
          $insights = $this->generateInsights($orderData, $insightType);
          
          if ($insights['success']) {
            // Store insights in database
            $insightId = $this->storeInsights($orderId, $insights, $insightType);
            $allInsights[$insightType] = $insights;
            $allInsights[$insightType]['insight_id'] = $insightId;
            
            if ($this->debug) {
              error_log("[OK] [Process Hook Shop] Stored '{$insightType}' insights for order #{$orderId} (ID: {$insightId})");
            }
          } else {
            error_log("[WARN] [Process Hook Shop] Failed to generate '{$insightType}' insights: " . ($insights['error'] ?? 'Unknown error'));
          }
        } catch (\Exception $e) {
          error_log("[ERROR] [Process Hook Shop] Error generating '{$insightType}': " . $e->getMessage());
        }
      }
      
      // Generate embeddings if enabled and insights were generated
      if (\defined('CLICSHOPPING_APP_CHATGPT_RA_OPENAI_EMBEDDING') && CLICSHOPPING_APP_CHATGPT_RA_OPENAI_EMBEDDING == 'True' && !empty($allInsights)) {
        
        try {
          if ($this->debug) {
            error_log("[PROC] [Process Hook Shop] Generating embeddings for insights...");
          }
          
          $this->generateAndSaveEmbeddings($orderId, $allInsights);
          
          if ($this->debug) {
            error_log("[OK] [Process Hook Shop] Embeddings generated successfully");
          }
        } catch (\Exception $e) {
          error_log("[ERROR] [Process Hook Shop] Error generating embeddings: " . $e->getMessage());
        }
      }
      
      $executionTime = round((microtime(true) - $startTime) * 1000, 2);
      
      if ($this->debug) {
        error_log("[OK] [Process Hook Shop] Total execution time: {$executionTime}ms");
      }
      
    } catch (\Exception $e) {
      error_log("[ERROR] [Process Hook Shop] Error: " . $e->getMessage());
    }
  }
  
  /**
   * Fetch order data from database
   */
  private function fetchOrderData(int $orderId): array
  {
    $db = Registry::get('Db');
    
    $query = $db->prepare('
      SELECT o.*, 
             os.orders_status_name,
             c.customers_firstname,
             c.customers_lastname,
             c.customers_email_address
      FROM :table_orders o
      LEFT JOIN :table_orders_status os ON o.orders_status = os.orders_status_id
      LEFT JOIN :table_customers c ON o.customers_id = c.customers_id
      WHERE o.orders_id = :orders_id
    ');
    $query->bindInt(':orders_id', $orderId);
    $query->execute();
    
    $orderData = $query->fetch();
    
    if (!$orderData) {
      return [];
    }
    
    // Fetch order total
    $totalQuery = $db->prepare('
      SELECT value 
      FROM :table_orders_total 
      WHERE orders_id = :orders_id 
      AND class = :class
    ');
    $totalQuery->bindInt(':orders_id', $orderId);
    $totalQuery->bindValue(':class', 'TO');
    $totalQuery->execute();
    
    $totalRow = $totalQuery->fetch();
    $orderData['order_total'] = $totalRow ? $totalRow['value'] : 0;
    
    // Fetch order products - FILTER BY CURRENT LANGUAGE to avoid duplicates
    $languageId = $this->language->getId();
    
    $productsQuery = $db->prepare('
      SELECT op.*, 
             p.products_model,
             pd.products_name
      FROM :table_orders_products op
      LEFT JOIN :table_products p ON op.products_id = p.products_id
      LEFT JOIN :table_products_description pd ON op.products_id = pd.products_id 
        AND pd.language_id = :language_id
      WHERE op.orders_id = :orders_id
    ');
    $productsQuery->bindInt(':orders_id', $orderId);
    $productsQuery->bindInt(':language_id', $languageId);
    $productsQuery->execute();
    
    $orderData['products'] = $productsQuery->fetchAll();
    
    return $orderData;
  }
  
  /**
   * Generate insights using LLM
   */
  private function generateInsights(array $orderData, string $insightType): array
  {
    $startTime = microtime(true);
    
    // Format order data
    $formattedOrderData = $this->formatOrderDataForPrompt($orderData);
    
    // Get prompt from language definitions with order_data parameter
    $promptKey = "llm_prompt_insights_{$insightType}";
    $prompt = $this->app->getDef($promptKey, ['order_data' => $formattedOrderData]);
    
    if (empty($prompt)) {
      throw new \Exception("Prompt '{$promptKey}' not found in language definitions");
    }
    
    // Call LLM
    $response = Gpt::getGptResponse($prompt, 500, 0.3);
    
    if ($response === false || empty($response)) {
      return [
        'success' => false,
        'error' => 'LLM returned empty response'
      ];
    }
    
    $executionTime = round((microtime(true) - $startTime) * 1000, 2);
    
    // Parse response
    $result = $this->parseInsightsResponse($response, $insightType);
    $result['execution_time_ms'] = $executionTime;
    
    return $result;
  }
  
  /**
   * Store insights in database
   */
  private function storeInsights(int $orderId, array $insights, string $insightType): int
  {
    $db = Registry::get('Db');
    $language = Registry::get('Language');
    
    // Get current language ID
    $languageId = $language->getId();
    
    // Check if insights already exist for this order and type
    $checkQuery = $db->prepare('
      SELECT insight_id 
      FROM :table_rag_order_insights 
      WHERE orders_id = :orders_id 
      AND insight_type = :insight_type
      AND language_id = :language_id
    ');
    $checkQuery->bindInt(':orders_id', $orderId);
    $checkQuery->bindValue(':insight_type', $insightType);
    $checkQuery->bindInt(':language_id', $languageId);
    $checkQuery->execute();
    
    $existing = $checkQuery->fetch();

    if ($existing) {
      $array_update = [
        'insights' => json_encode($insights['insights'] ?? []),
        'confidence' => $insights['confidence'] ?? 0.8,
        'recommendations' => json_encode($insights['recommendations'] ?? []),
        'summary' => $insights['summary'] ?? '',
        'raw_response' => $insights['raw_response'] ?? '',
        'execution_time_ms' => $insights['execution_time_ms'] ?? 0
      ];

      $db->save('rag_order_insights', $array_update, ['insight_id' => $existing['insight_id']]);
      
      if ($this->debug) {
        error_log("[OK] [Process Hook Shop] Updated insights for language_id: {$languageId}");
      }
      
      return (int)$existing['insight_id'];
    } else {
      $array_insert = [
        'orders_id' => $orderId,
        'insight_type' => $insightType,
        'insights' => json_encode($insights['insights'] ?? []),
        'confidence' => $insights['confidence'] ?? 0.8,
        'recommendations' => json_encode($insights['recommendations'] ?? []),
        'summary' => $insights['summary'] ?? '',
        'raw_response' => $insights['raw_response'] ?? '',
        'execution_time_ms' => $insights['execution_time_ms'] ?? 0,
        'language_id' => $languageId
      ];

      $db->save('rag_order_insights', $array_insert);
      
      if ($this->debug) {
        error_log("[OK] [Process Hook Shop] Stored insights for language_id: {$languageId}");
      }
      
      return (int)$db->lastInsertId();
    }
  }
  
  /**
   * Format order data for prompt
   */
  private function formatOrderDataForPrompt(array $orderData): string
  {
    $formatted = "Order ID: " . ($orderData['orders_id'] ?? 'N/A') . "\n";
    $formatted .= "Order Date: " . ($orderData['date_purchased'] ?? 'N/A') . "\n";
    $formatted .= "Order Status: " . ($orderData['orders_status_name'] ?? 'N/A') . "\n";
    $formatted .= "Currency: " . ($orderData['currency'] ?? 'N/A') . "\n";
    $formatted .= "Order Total: " . number_format((float)($orderData['order_total'] ?? 0), 2) . " " . ($orderData['currency'] ?? '') . "\n";
    $formatted .= "\nProducts Ordered:\n";
    
    $totalItems = 0;
    $subtotal = 0;
    
    if (!empty($orderData['products'])) {
      foreach ($orderData['products'] as $product) {
        $quantity = (int)($product['products_quantity'] ?? 1);
        $price = (float)($product['final_price'] ?? 0);
        $lineTotal = $quantity * $price;
        
        $formatted .= "- Product: " . ($product['products_name'] ?? 'Unknown') . "\n";
        $formatted .= "  Quantity: " . $quantity . "\n";
        $formatted .= "  Unit Price: " . number_format($price, 2) . " " . ($orderData['currency'] ?? '') . "\n";
        $formatted .= "  Line Total: " . number_format($lineTotal, 2) . " " . ($orderData['currency'] ?? '') . "\n";
        
        // Add product attributes if any
        if (!empty($product['products_options'])) {
          $formatted .= "  Options: " . $product['products_options'] . "\n";
        }
        
        $totalItems += $quantity;
        $subtotal += $lineTotal;
      }
    }
    
    $formatted .= "\nOrder Summary:\n";
    $formatted .= "Total Items: " . $totalItems . "\n";
    $formatted .= "Subtotal: " . number_format($subtotal, 2) . " " . ($orderData['currency'] ?? '') . "\n";
    $formatted .= "Final Total: " . number_format((float)($orderData['order_total'] ?? 0), 2) . " " . ($orderData['currency'] ?? '') . "\n";
    
    $formatted .= "\nNote: Customer personal information is encrypted for GDPR compliance and should not be analyzed.\n";
    
    return $formatted;
  }
  
  /**
   * Parse LLM response
   */
  private function parseInsightsResponse(string $response, string $insightType): array
  {
    $cleaned = HTMLOverrideCommon::cleanJsonResponse($response);
    $parsed = json_decode($cleaned, true);
    
    if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
      return [
        'success' => true,
        'insight_type' => $insightType,
        'insights' => $parsed['insights'] ?? [],
        'confidence' => $parsed['confidence'] ?? 0.8,
        'recommendations' => $parsed['recommendations'] ?? [],
        'summary' => $parsed['summary'] ?? '',
        'raw_response' => $response
      ];
    }
    
    return [
      'success' => true,
      'insight_type' => $insightType,
      'insights' => [$response],
      'confidence' => 0.7,
      'recommendations' => [],
      'summary' => substr($response, 0, 200),
      'raw_response' => $response
    ];
  }
  
  /**
   * Check if embedding already exists for this order
   */
  private function embeddingExists(int $orderId): bool
  {
    $db = Registry::get('Db');
    
    $checkQuery = $db->prepare('
      SELECT id 
      FROM :table_rag_order_insights_embedding
      WHERE entity_id = :entity_id
    ');
    $checkQuery->bindInt(':entity_id', $orderId);
    $checkQuery->execute();
    
    return $checkQuery->fetch() !== false;
  }
  
  /**
   * Build embedding content from insights
   * 
   * Combines all insights into a structured format for embedding generation
   * Uses multilingual atomic keys from language definitions
   */
  private function buildEmbeddingContent(int $orderId, array $allInsights): string
  {
    $language = Registry::get('Language');
    $languageCode = $language->getCode();
    
    // Use language definitions for atomic keys
    $content = "[{$this->app->getDef('embedding_key_domain')}]: {$this->app->getDef('embedding_value_domain')}\n";
    $content .= "[{$this->app->getDef('embedding_key_entity')}]: {$this->app->getDef('embedding_value_entity')}\n";
    $content .= "[{$this->app->getDef('embedding_key_order_id')}]: {$orderId}\n";
    $content .= "[{$this->app->getDef('embedding_key_language')}]: {$languageCode}\n\n";
    
    // Add summary insights
    if (isset($allInsights['summary'])) {
      $summary = $allInsights['summary'];
      $content .= "[{$this->app->getDef('embedding_key_summary')}]:\n";
      $content .= $summary['summary'] ?? '';
      $content .= "\n\n";
      
      if (!empty($summary['insights'])) {
        $content .= "[{$this->app->getDef('embedding_key_key_insights')}]:\n";
        $insights = is_array($summary['insights']) ? $summary['insights'] : json_decode($summary['insights'], true);
        if (is_array($insights)) {
          foreach ($insights as $idx => $insight) {
            $num = $idx + 1;
            $content .= "- {$this->app->getDef('embedding_key_insight')} {$num}: {$insight}\n";
          }
        }
        $content .= "\n";
      }
    }
    
    // Add recommendations
    if (isset($allInsights['recommendations'])) {
      $recommendations = $allInsights['recommendations'];
      $content .= "[{$this->app->getDef('embedding_key_recommendations')}]:\n";
      
      if (!empty($recommendations['recommendations'])) {
        $recs = is_array($recommendations['recommendations']) ? $recommendations['recommendations'] : json_decode($recommendations['recommendations'], true);
        if (is_array($recs)) {
          foreach ($recs as $idx => $rec) {
            $num = $idx + 1;
            $content .= "- {$this->app->getDef('embedding_key_recommendation')} {$num}: {$rec}\n";
          }
        }
      }
      
      if (!empty($recommendations['summary'])) {
        $content .= "\n" . $recommendations['summary'];
      }
    }
    
    return $content;
  }
  
  /**
   * Generate and save embeddings for insights
   */
  private function generateAndSaveEmbeddings(int $orderId, array $allInsights): void
  {
    $db = Registry::get('Db');
    $language = Registry::get('Language');
    $languageId = $language->getId();
    
    // Check if embedding already exists
    $isUpdate = $this->embeddingExists($orderId);
    
    // Build embedding content
    $embeddingContent = $this->buildEmbeddingContent($orderId, $allInsights);
    
    if ($this->debug) {
      error_log("📝 [Process Hook Shop] Embedding content length: " . strlen($embeddingContent) . " chars");
    }
    
    // Generate embeddings
    $embeddedDocuments = NewVector::createEmbedding(null, $embeddingContent);
    
    if (empty($embeddedDocuments)) {
      throw new \Exception("Failed to generate embeddings - empty result");
    }
    
    // Extract tags from content
    $tags = [];
    if (preg_match_all('/^\[([^\]]+)\]:/m', $embeddingContent, $matches)) {
      $tags = array_unique($matches[1]);
    }
    
    // Prepare metadata
    $baseMetadata = [
      'order_name' => "Order #{$orderId} Insights",
      'content' => $embeddingContent,
      'type' => 'order_insights',
      'tags' => $tags,
      'source' => ['type' => 'automated', 'name' => 'insights_agent']
    ];
    
    // Save embeddings using centralized method with language_id
    $result = NewVector::saveEmbeddingsWithChunks(
      $embeddedDocuments,
      'rag_order_insights_embedding',
      $orderId,
      $languageId, // Pass language_id for multilingual support
      $baseMetadata,
      $db,
      $isUpdate
    );
    
    if (!$result['success']) {
      throw new \Exception("Failed to save embeddings: " . ($result['error'] ?? 'Unknown error'));
    }
    
    if ($this->debug) {
      error_log("[OK] [Process Hook Shop] Saved {$result['chunks_saved']} embedding chunk(s) for order {$orderId} (language_id: {$languageId})");
    }
  }
}
