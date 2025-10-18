<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Configuration\ChatGpt\Module\Hooks\ClicShoppingAdmin\Suppliers;

use ClicShopping\OM\Registry;

use ClicShopping\Apps\Configuration\ChatGpt\ChatGpt as ChatGptApp;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\Gpt;
use ClicShopping\Sites\Common\HTMLOverrideCommon;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\NewVector;

class Insert implements \ClicShopping\OM\Modules\HooksInterface
{
  public mixed $app;
  /**
   * Constructor method for initializing the ChatGpt application.
   * It ensures that the ChatGpt instance is registered in the Registry.
   * Loads the necessary definitions for the specified module hook.
   *
   * @return void
   */
  public function __construct()
  {
    if (!Registry::exists('ChatGpt')) {
      Registry::set('ChatGpt', new ChatGptApp());
    }

    $this->app = Registry::get('ChatGpt');

    $this->app->loadDefinitions('Module/Hooks/ClicShoppingAdmin/Supplier/rag');
  }

  /**
   * Processes the execution related to Suppliers_id data management and updates in the database.
   * This includes generating SEO metadata (e.g., titles, descriptions ...),
   * summaries, and translations based on supplier information, as well as optional
   *
   * @return void
   */
  public function execute()
  {
    if (Gpt::checkGptStatus() === false || CLICSHOPPING_APP_CHATGPT_RA_OPENAI_EMBEDDING == 'False' || CLICSHOPPING_APP_CHATGPT_RA_STATUS == 'False') {
      return false;
    }

    if (isset($_GET['Insert'], $_GET['Suppliers'])) {
      $Qcheck = $this->app->db->prepare('select suppliers_id,
                                                suppliers_name,
                                                date_added,
                                                suppliers_city,
                                                suppliers_country_id,
                                                suppliers_status,
                                                suppliers_image, 
                                                date_added,
                                                last_modified,
                                                suppliers_manager,
                                                suppliers_phone,
                                                suppliers_email_address,
                                                suppliers_fax,
                                                suppliers_address,
                                                suppliers_suburb,
                                                suppliers_postcode,
                                                suppliers_city,
                                                suppliers_states,
                                                suppliers_country_id,
                                                suppliers_notes,
                                                suppliers_status      
                                          from :table_suppliers
                                          order by suppliers_id desc
                                          limit 1
                                        ');
      $Qcheck->execute();

      $supplier_name = $Qcheck->value('suppliers_name');
      $suppliers_id = $Qcheck->valueInt('suppliers_id');
      $date_added = $Qcheck->valueInt('date_added');
      $suppliers_country_id = $Qcheck->valueInt('suppliers_country_id');
      $suppliers_status = $Qcheck->valueInt('suppliers_status');

      if ($suppliers_status == 0) {
        $suppliers_status = $this->app->getDef('text_status_active');
      } else {
        $suppliers_status = $this->app->getDef('text_status_inactive');
      }

      $suppliers_city = $Qcheck->value('suppliers_city');
      $suppliers_notes = $Qcheck->value('suppliers_notes');
      $suppliers_states = $Qcheck->value('suppliers_states');

      if ($suppliers_id !== null) {
        //********************
        // add embedding
        //********************
        if (\defined('CLICSHOPPING_APP_CHATGPT_RA_OPENAI_EMBEDDING') && CLICSHOPPING_APP_CHATGPT_RA_OPENAI_EMBEDDING == 'True' && CLICSHOPPING_APP_CHATGPT_RA_STATUS == 'True') {
          $embedding_data = $this->app->getDef('text_supplier_name') . ' : ' . HtmlOverrideCommon::cleanHtmlForEmbedding($supplier_name) . "\n";
          $embedding_data .= $this->app->getDef('text_supplier_id') . ' : ' . $suppliers_id . "\n";

          if (!empty($date_added)) {
            $embedding_data .= $this->app->getDef('text_supplier_date_added', ['supplier_name' => $supplier_name]) . ' : ' . HtmlOverrideCommon::cleanHtmlForEmbedding($date_added) . "\n";
          }

          if (!empty($suppliers_status)) {
            $embedding_data .= $this->app->getDef('text_supplier_status', ['supplier_name' => $supplier_name]) . ' : ' . HtmlOverrideCommon::cleanHtmlForEmbedding($suppliers_status) . "\n";
          }

          if (!empty($suppliers_states)) {
            $embedding_data .= $this->app->getDef('text_suppliers_states', ['supplier_name' => $supplier_name]) . ' : ' . HtmlOverrideCommon::cleanHtmlForEmbedding($suppliers_states) . "\n";
          }

          if (!empty($suppliers_city)) {
            $embedding_data .= $this->app->getDef('text_supplier_city', ['supplier_name' => $supplier_name]) . ' : ' . HtmlOverrideCommon::cleanHtmlForEmbedding($suppliers_city) . "\n";
          }

          if (!empty($suppliers_country_id)) {
            $embedding_data .= $this->app->getDef('text_supplier_country_id', ['supplier_name' => $supplier_name]) . ' : ' . HtmlOverrideCommon::cleanHtmlForEmbedding($suppliers_country_id) . "\n";
          }

          if (!empty($suppliers_notes)) {
            $embedding_data .= $this->app->getDef('text_suppliers_notes', ['supplier_name' => $supplier_name]) . ' : ' . HtmlOverrideCommon::cleanHtmlForEmbedding($suppliers_notes) . "\n";
          }

          $embeddedDocuments = NewVector::createEmbedding(null, $embedding_data);

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
              'type' => 'suppliers',
              'sourcetype' => 'manual',
              'sourcename' => 'manual',
              'date_modified' => 'now()',
              'entity_id' => $suppliers_id
            ];

            $sql_data_array_embedding['vec_embedding'] = $new_embedding_literal;

            $sql_data_array = array_merge($sql_data_array_embedding);

            $this->app->db->save('suppliers_embedding', $sql_data_array);
          }
        }
      }
    }
  }
}