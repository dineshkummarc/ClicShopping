<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Configuration\ChatGpt\Module\Hooks\ClicShoppingAdmin\Orders;

use ClicShopping\OM\Hash;
use ClicShopping\OM\Registry;
use ClicShopping\OM\HTML;

use ClicShopping\Apps\Configuration\ChatGpt\ChatGpt as ChatGptApp;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\Gpt;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\NewVector;
use ClicShopping\Sites\Common\HTMLOverrideCommon;

class Update implements \ClicShopping\OM\Modules\HooksInterface
{
  public mixed $app;

  /**
   * Class constructor.
   *
   * Initializes the ChatGptApp instance in the Registry if it doesn't already exist,
   * and loads the necessary definitions for the application.
   *
   * @return void
   */
  public function __construct()
  {
    if (!Registry::exists('ChatGpt')) {
      Registry::set('ChatGpt', new ChatGptApp());
    }

    $this->app = Registry::get('ChatGpt');

    $this->app->loadDefinitions('Module/Hooks/ClicShoppingAdmin/Orders/rag');
  }

  /**
   * Checks if the embedding already exists for the given order ID.
   *
   * @param int $order_id The order ID.
   *
   * @return bool True if the embedding exists, false otherwise.
   */
 
 private function embeddingExists(int $order_id): bool
  {
    $Qcheck = $this->app->db->prepare('SELECT id FROM :table_orders_embedding WHERE entity_id = :entity_id');
    $Qcheck->bindInt(':entity_id', $order_id);
    $Qcheck->execute();

    return $Qcheck->fetch() !== false;
  }
  
  /**
   * Retrieves the order details from the database.
   *
   * @param int $order_id The order ID.
   *
   * @return array The order details.
   */
  private function getOrderDetails(int $order_id): array
  {
    $Q = $this->app->db->prepare(' SELECT * FROM :table_orders WHERE orders_id = :orders_id');
    $Q->bindInt(':orders_id', $order_id);
    $Q->execute();

    return $Q->fetch();
  }

  /**
   * Retrieves the products associated with the order.
   *
   * @param int $order_id The order ID.
   *
   * @return array The products associated with the order.
   */
  private function getOrderProducts(int $order_id): array
  {
    $Q = $this->app->db->prepare('
    SELECT * FROM :table_orders_products WHERE orders_id = :orders_id');
    $Q->bindInt(':orders_id', $order_id);
    $Q->execute();

    return $Q->fetchAll();
  }

  /**
   * Retrieves the product attributes associated with the order.
   *
   * @param int $order_id The order ID.
   *
   * @return array The product attributes associated with the order.
   */
  private function getOrderProductAttributes(int $order_id): array
  {
    $Q = $this->app->db->prepare('SELECT * 
                                  FROM :table_orders_products_attributes opa
                                  JOIN :table_orders_products op ON opa.orders_id = op.orders_id
                                  WHERE op.orders_id = :orders_id
                                ');
    $Q->bindInt(':orders_id', $order_id);
    $Q->execute();

    return $Q->fetchAll();
  }

  /**
   * Retrieves the order status history associated with the order.
   *
   * @param int $order_id The order ID.
   *
   * @return array The order status history associated with the order.
   */
  private function getOrderStatusHistory(int $order_id): array
  {
    $Q = $this->app->db->prepare('SELECT * FROM :table_orders_status_history WHERE orders_id = :orders_id');
    $Q->bindInt(':orders_id', $order_id);
    $Q->execute();

    return $Q->fetchAll();
  }

  /**
   * Retrieves the order totals associated with the order.
   *
   * @param int $order_id The order ID.
   *
   * @return array The order totals associated with the order.
   */
  private function getOrderTotals(int $order_id): array
  {
    $Q = $this->app->db->prepare('SELECT title, 
                                         value,
                                         class
                                  FROM :table_orders_total 
                                  WHERE orders_id = :orders_id');
    $Q->bindInt(':orders_id', $order_id);
    $Q->execute();

    return $Q->fetchAll();
  }

  /**
   * Builds the embedding data for the order.
   *
   * This method constructs the embedding data string using the provided order details,
   * products, attributes, status history, and totals.
   *
   * @param int $order_id The order ID.
   * @param array $order The order details.
   * @param array $products The products associated with the order.
   * @param array $attributes The product attributes associated with the order.
   * @param array $statusHistory The order status history associated with the order.
   * @param array $totals The order totals associated with the order.
   *
   * @return string The constructed embedding data string.
   */
  private function buildEmbeddingData(int $order_id, array $order, array $products, array $attributes, array $statusHistory, array $totals): string
  {
    $customers_city = Hash::displayDecryptedDataText($order['customers_city']);
    $customers_name = Hash::displayDecryptedDataText($order['customers_name']);
    $customers_company = Hash::displayDecryptedDataText($order['customers_company']);

    $data = $this->app->getDef('text_order_information') . "\n";
    $data .=  $this->app->getDef('text_order_information_order_id') . ' : ' . $order_id . "\n";
    $data .= $this->app->getDef('text_order_customer_name') . ' : ' . $customers_name . "\n";

    if (!empty($customers_company)) {
      $data .= $this->app->getDef('text_order_company') . ' : ' . $customers_company . "\n";
    }

    $data .= $this->app->getDef('text_order_customer_city') . ' : ' . $customers_city . "\n";
    $data .= $this->app->getDef('text_order_customer_country') . ' : ' . $order['customers_country'] . "\n";
    $data .= $this->app->getDef('text_order_customer_payment_method') . ' : ' .$order['payment_method'] . "\n";
    $data .= $this->app->getDef('text_order_customer_status') . ' : ' . $order['orders_status'] . "\n";
    $data .= $this->app->getDef('text_order_customer_date_purchased') . ' : ' . $order['date_purchased'] . "\n";
    $data .= $this->app->getDef('text_order_customer_delivery_city') . ' : ' . Hash::displayDecryptedDataText($order['delivery_city']) . "\n";
    $data .= $this->app->getDef('text_order_customer_delivery_country') . ' : ' . $order['delivery_country'] . "\n";
    $data .= $this->app->getDef('text_order_customer_currency') . ' : ' . $order['currency'] . "\n";

    $data .= "\n" . $this->app->getDef('text_order_products_details') . "\n";
    foreach ($products as $product) {
      $data .= $this->app->getDef('text_order_products_name') . ' : ' . $product['products_name'] . "\n";
      $data .= $this->app->getDef('text_order_products_model') . ' : ' . $product['products_model'] . "\n";
      $data .= $this->app->getDef('text_order_products_price') . ' : ' . $product['products_price'] . "\n";
      $data .= $this->app->getDef('text_order_products_qty') . ' : ' . $product['products_quantity'] . "\n";
      $data .= $this->app->getDef('text_order_products_taxe') . ' : ' . $product['products_tax'] . "\n";
      $data .=  $this->app->getDef('text_order_products_final_price') . ' : ' . $product['final_price'] . "\n";
    }

    if (!empty($attributes) && is_array($attributes)) {
      $data .= "\n" . $this->app->getDef('text_products_attributes_details') . "\n";

      foreach ($attributes as $attribute) {
        $data .= $this->app->getDef('text_products_attributes_products_reference')  . ' : ' . $attribute['products_attributes_reference'] . "\n";
        $data .= $this->app->getDef('text_products_attributes_products_option') . ' : ' . $attribute['products_options'] . "\n";
        $data .= $this->app->getDef('text_products_attributes_products_value')  . ' : ' . $attribute['products_options_values'] . "\n";
        $data .= $this->app->getDef('text_products_attributes_products_value')  . ' : ' . $attribute['options_values_price'] . "\n";
      }
    }

    if (!empty($statusHistory) && is_array($statusHistory)) {
      $data .= "\n" . $this->app->getDef('text_products_order_history') . "\n";

      foreach ($statusHistory as $status) {
        if(!empty($status['date_added'])) {
          $data .= $this->app->getDef('text_products_order_history_date') . ' : ' . $status['date_added'] . "\n";
        }
        if (!empty($status['orders_status_id'])) {
          $data .= $this->app->getDef('text_products_order_history_status') . ' : ' . $status['orders_status_id'] . "\n";
        }
        if (!empty($status['comments'])) {
          $data .= $this->app->getDef('text_products_order_history_comment') . ' : ' . HTMLOverrideCommon::cleanHtmlForEmbedding($status['comments']) . "\n";
        }
        if (!empty($status['orders_tracking_number'])){
          $data .= $this->app->getDef('text_products_order_history_tracking') . ' : ' . $status['orders_tracking_number'] . "\n";
        }
        if (!empty($status['orders_tracking_number'])) {
          $data .= $this->app->getDef('text_products_order_history_admin') . ' : ' . $status['admin_user_name'] . "\n";
        }
      }
    }

    if (!empty($totals) && is_array($totals)) {
      $data .= "\n" . $this->app->getDef('text_orders_total') . "\n";
      foreach ($totals as $total) {
        $data .= $this->app->getDef('text_orders_total_title') . ' : ' . $total['title'] . "\n";
        $data .= $this->app->getDef('text_orders_total_value') . ' : ' . $total['value'] . "\n";
      }
    }

    return $data;
  }

  /**
   * Saves the embedding data to the database.
   *
   * This method saves the embedding data and vector to the database. If the embedding
   * already exists, it updates the existing record.
   *
   * @param int $order_id The order ID.
   * @param string $embeddingData The embedding data.
   * @param array $embeddingVector The embedding vector.
   * @param bool $isNew Indicates if this is a new embedding.
   *
   * @return void
   * @throws \JsonException
   */
  private function saveEmbedding(int $order_id, string $embeddingData, array $embeddingVector, bool $isNew): void
  {
    $sql_data_array_embedding = [
      'content' => $embeddingData,
      'type' => 'orders',
      'sourcetype' => 'manual',
      'sourcename' => 'manual',
      'date_modified' => 'now()',
      'vec_embedding' => json_encode($embeddingVector, JSON_THROW_ON_ERROR)
    ];

    if ($isNew) {
      $sql_data_array_embedding['entity_id'] = (int)$order_id;
      $this->app->db->save('orders_embedding', $sql_data_array_embedding);
    } else {
      $update_sql_data = ['entity_id' => (int)$order_id];
      $this->app->db->save('orders_embedding', $sql_data_array_embedding, $update_sql_data);
    }
  }
  
  /**
   * Executes the embedding process for order updates.
   *
   * This method checks the GPT status and whether the embedding feature is enabled.
   * If the conditions are met, it retrieves order details, products, attributes,
   * status history, totals, and returns. It then builds the embedding data and
   * saves it to the database.
   *
   * @return bool Returns false if conditions are not met, otherwise true.
   */
  public function execute()
  {
    if (Gpt::checkGptStatus() === false || CLICSHOPPING_APP_CHATGPT_RA_OPENAI_EMBEDDING == 'False' || CLICSHOPPING_APP_CHATGPT_RA_STATUS == 'False') {
      return false;
    }

    if (!isset($_GET['Update'], $_GET['Orders'], $_GET['oID'])) {
      return false;
    }

    $order_id = HTML::sanitize($_GET['oID']);

    $insert_embedding = !$this->embeddingExists($order_id);

    $orderData = $this->getOrderDetails($order_id);
    $products = $this->getOrderProducts($order_id);
    $attributes = $this->getOrderProductAttributes($order_id);
    $statusHistory = $this->getOrderStatusHistory($order_id);
    $totals = $this->getOrderTotals($order_id);

    $embeddingData = $this->buildEmbeddingData($order_id, $orderData, $products, $attributes, $statusHistory, $totals);

    $embeddedDocuments = NewVector::createEmbedding(null, $embeddingData);
    $embeddingVector = $embeddedDocuments[0]->embedding ?? null;

    if (!empty($embeddingVector)) {
      $this->saveEmbedding($orderData['orders_id'], $embeddingData, $embeddingVector, $insert_embedding);
    }
  }
}