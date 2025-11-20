<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Configuration\ChatGpt\Module\Hooks\ClicShoppingAdmin\ReturnOrders;

use AllowDynamicProperties;
use ClicShopping\OM\Registry;
use ClicShopping\OM\HTML;
use ClicShopping\Sites\Common\HTMLOverrideCommon;

use ClicShopping\Apps\Configuration\ChatGpt\ChatGpt as ChatGptApp;
use ClicShopping\AI\Tools\Semantics\Semantics;

#[AllowDynamicProperties]
class Save implements \ClicShopping\OM\Modules\HooksInterface
{
  public mixed $app;
  public mixed $lang;
  public mixed $semantics;
  protected mixed $vector;

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

    Registry::set('Semantics', new Semantics());
    $this->semantics = Registry::get('Semantics');
    $this->vector = Registry::get('Vector');
    
    $this->app->loadDefinitions('Module/Hooks/ClicShoppingAdmin/ReturnOrders/rag');
  }

  /**
   * Generates an embedding string for a given return order ID.
   *
   * @param int $return_id The ID of the return order.
   * @return string The generated embedding string.
   */
  private function returnOrders(int $return_id): string
  {
    $QreturnOrders = $this->app->db->prepare('select r.return_id,
                                                     r.return_ref,
                                                     r.order_id,
                                                     r.product_id,
                                                     r.product_model,
                                                     r.product_name,
                                                     r.quantity,
                                                     r.return_reason_id,
                                                     r.return_status_id,
                                                     r.comment,
                                                     r.date_added,
                                                     o.date_purchased as date_ordered
                                              from :table_return_orders r,
                                                   :table_orders o
                                              where r.return_id = :return_id
                                              and o.orders_id = r.order_id
                                             ');

    $QreturnOrders->bindInt(':return_id', $return_id);
    $QreturnOrders->execute();

    $item = $QreturnOrders->fetch();
    $embedding_data = $this->app->getDef('text_orders_products_return') . "\n";
    $embedding_data .=   $this->app->getDef('text_orders_products_return_id') . ' : ' . $item['return_id'] . "\n";
    $embedding_data .= $this->app->getDef('text_orders_products_return_ref') . ' : ' . $item['return_ref'] . "\n";
    $embedding_data .= $this->app->getDef('text_orders_products_return_product_id') . ' : ' . $item['products_id'] . "\n";
    $embedding_data .= $this->app->getDef('text_orders_products_return_order_id') . ' : ' . $item['order_id'] . "\n";
    $embedding_data .= $this->app->getDef('text_orders_products_return_model') . ' : ' . HTMLOverrideCommon::cleanHtmlForEmbedding($item['product_model']) . "\n";
    $embedding_data .= $this->app->getDef('text_orders_products_return_name') . ' : ' . HTMLOverrideCommon::cleanHtmlForEmbedding($item['product_name']) . "\n";
    $embedding_data .= $this->app->getDef('text_orders_products_return_qty') . ' : ' . $item['quantity'] . "\n";
    $embedding_data .= $this->app->getDef('text_orders_products_reason_id') . ' : ' . $item['return_reason_id'] . "\n";
    $embedding_data .= $this->app->getDef('text_orders_products_status_id') . ' : ' . $item['return_status_id'] . "\n";
    $embedding_data .= $this->app->getDef('text_orders_products_comment') . ' : ' . HTMLOverrideCommon::cleanHtmlForEmbedding($item['comment']) . "\n";
    $embedding_data .= $this->app->getDef('text_orders_products_date_added') . ' : ' . $item['date_added'] . "\n";
    $embedding_data .= $this->app->getDef('text_orders_products_date_ordered') . ' : ' . $item['date_ordered'] . "\n";

    return $embedding_data;
  }

  /**
   * Generates an embedding string for the return order history.
   *
   * @param int $return_id The ID of the return order.
   * @return string The generated embedding string.
   */
  private function returnOrdersHistory(int $return_id): string
  {
    $QreturnOrders = $this->app->db->prepare('select rh.comment,
                                                    rh.date_added,
                                                    rh.return_status_id,
                                                    rh.admin_user_name,
                                                    r.return_id      
                                             from :table_return_orders r,
                                                  :table_return_orders_history rh
                                             where r.return_id = :return_id
                                             and r.return_id = rh.return_id
                                             ');

    $QreturnOrders->bindInt(':return_id', $return_id);
    $QreturnOrders->execute();

    $return_orders_array = $QreturnOrders->fetchAll();
    $embedding_data =  "\n" . $this->app->getDef('text_orders_products_return_history') . "\n";

    foreach ($return_orders_array as $item) {
      $embedding_data .= $this->app->getDef('text_orders_products_return_history_comment') . ' : ' . HTMLOverrideCommon::cleanHtmlForEmbedding($item['comment']) . "\n";
      $embedding_data .= $this->app->getDef('text_orders_products_return_history_date_added') . ' : ' . $item['date_added'] . "\n";
    }

    return $embedding_data;
  }

  /**
   * Processes the execution related to product data management and delete in the database.
   * This includes generating products_embedding, based on product information.
   *
   * @return void
   * @throws \JsonException
   */
  public function execute()
  {
    if (Gpt::checkGptStatus() === false || CLICSHOPPING_APP_CHATGPT_RA_OPENAI_EMBEDDING == 'False' || CLICSHOPPING_APP_CHATGPT_RA_STATUS == 'False') {
      return false;
    }

    $embedding_enabled = \defined('CLICSHOPPING_APP_CHATGPT_RA_OPENAI_EMBEDDING') && CLICSHOPPING_APP_CHATGPT_RA_OPENAI_EMBEDDING == 'True' && \defined( 'CLICSHOPPING_APP_CHATGPT_RA_STATUS') && CLICSHOPPING_APP_CHATGPT_RA_STATUS == 'True';

    if (isset($_POST['rId'], $_GET['ReturnOrders'], $_GET['Save'])) {
      $return_id = HTML::sanitize($_POST['rId']);

      $Qcheck = $this->app->db->prepare('select id
                                         from :table_return_orders_embedding
                                         where entity_id = :entity_id
                                        ');
      $Qcheck->bindInt(':entity_id', $return_id);
      $Qcheck->execute();

        //********************
        // add embedding
        //********************
       if ($embedding_enabled) {
      $insert_embedding = false;

      if ($Qcheck->fetch() === false) {
        $insert_embedding = true;
      }

      $embedding_data = $this->returnOrders($return_id);
      $embedding_data .= $this->returnOrdersHistory($return_id);

      $embeddedDocuments = $this->vector->createEmbedding(null, $embedding_data);

      $embeddings = [];

      foreach ($embeddedDocuments as $embeddedDocument) {
        if (is_array($embeddedDocument->embedding)) {
          $embeddings[] = $embeddedDocument->embedding;
        }
      }

      if (!empty($embeddings)) {
        $flattened_embedding = $embeddings[0];
        $new_embedding_literal = json_encode($flattened_embedding, JSON_THROW_ON_ERROR);

        $sql_data_array_embedding = [
          'content' => $embedding_data,
          'type' => 'return_orders',
          'sourcetype' => 'manual',
          'sourcename' => 'manual',
          'date_modified' => 'now()'
        ];

        $sql_data_array_embedding['vec_embedding'] = $new_embedding_literal;

        if ($insert_embedding === true) {
          $sql_data_array_embedding['entity_id'] = $return_id;

          $this->app->db->save('return_orders_embedding', $sql_data_array_embedding);
        } else {
          $update_sql_data = ['entity_id' => $return_id];

          $this->app->db->save('return_orders_embedding', $sql_data_array_embedding, $update_sql_data);
        }
       }
      }
    }
  }
}