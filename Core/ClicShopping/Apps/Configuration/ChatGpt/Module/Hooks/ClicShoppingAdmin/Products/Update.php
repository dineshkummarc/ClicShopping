<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Configuration\ChatGpt\Module\Hooks\ClicShoppingAdmin\Products;

use AllowDynamicProperties;
use ClicShopping\OM\Registry;
use ClicShopping\OM\HTML;

use ClicShopping\Apps\Configuration\ChatGpt\ChatGpt as ChatGptApp;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\Gpt;
use ClicShopping\AI\Domain\Embedding\NewVector;
use ClicShopping\Sites\Common\HTMLOverrideCommon;
use ClicShopping\AI\Domain\Semantics\Semantics;

#[AllowDynamicProperties]
/**
 * Class Update
 * @package ClicShopping\Apps\Configuration\ChatGpt\Module\Hooks\ClicShoppingAdmin\Products
 *
 * This class handles the insertion of product data into the database.
 * It generates SEO metadata, summaries, and translations based on product information,
 * and also creates product-related images if specified.
 */
class Update implements \ClicShopping\OM\Modules\HooksInterface
{
  public mixed $app;
  public mixed $lang;
  public mixed $semantics;
  /**
   * Class constructor.
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
    $this->lang = Registry::get('Language');

    if (!Registry::exists('Semantics')) {
      Registry::set('Semantics', new Semantics());
    }
    $this->semantics = Registry::get('Semantics');
  }

  /**
   * Executes the necessary processes based on the provided GET and POST parameters related to category handling.
   *
   * Checks if GPT functionality is enabled and processes category-related inputs to update database records
   * such as descriptions, SEO data (title, description, keywords),
   *
   * @return bool Returns false if GPT functionality is disabled or not applicable; otherwise, performs the operations without returning a value.
   */
  public function execute()
  {
    if (Gpt::checkGptStatus() === false || CLICSHOPPING_APP_CHATGPT_RA_OPENAI_EMBEDDING == 'False' || CLICSHOPPING_APP_CHATGPT_RA_STATUS == 'False') {
      return false;
    }

    $embedding_enabled = \defined('CLICSHOPPING_APP_CHATGPT_RA_OPENAI_EMBEDDING') && CLICSHOPPING_APP_CHATGPT_RA_OPENAI_EMBEDDING == 'True' && \defined( 'CLICSHOPPING_APP_CHATGPT_RA_STATUS') && CLICSHOPPING_APP_CHATGPT_RA_STATUS == 'True';
    
    if (isset($_GET['Update'], $_GET['Products'])) {
      if (isset($_GET['pID'])) {
        $pID = HTML::sanitize($_GET['pID']);

        $Qcheck = $this->app->db->prepare('select id
                                           from :table_products_embedding
                                           where entity_id = :entity_id
                                          ');
        $Qcheck->bindInt(':entity_id', $pID);
        $Qcheck->execute();

        $insert_embedding = false;

        if ($Qcheck->fetch() === false) {
          $insert_embedding = true;
        }

        $Qproducts = $this->app->db->prepare('select p.products_id,
                                                     p.products_model,
                                                     p.manufacturers_id,
                                                     p.products_ean,
                                                     p.products_sku,
                                                     p.products_date_added,
                                                     p.products_status,
                                                     p.products_ordered,
                                                     p.products_quantity,
                                                     p.products_quantity_alert,
                                                     pd.products_name,
                                                     pd.products_description,
                                                     pd.products_head_title_tag,
                                                     pd.products_head_desc_tag,
                                                     pd.products_head_keywords_tag,
                                                     pd.products_head_tag,
                                                     pd.products_description_summary,
                                                     pd.language_id
                                                from :table_products p,
                                                     :table_products_description pd
                                                where p.products_id = :products_id
                                                and p.products_id = pd.products_id
                                              ');
        $Qproducts->bindInt(':products_id', $pID);
        $Qproducts->execute();

        $products_array = $Qproducts->fetchAll();

        $Qcategories = $this->app->db->get('products_to_categories', ['categories_id'], ['products_id' => $pID]);

        if (is_array($products_array)) {
          foreach ($products_array as $item) {
            $products_id = $item['products_id'];
            $products_name = $item['products_name'];
            $products_model = $item['products_model'];
            $products_ean = $item['products_ean'];
            $products_sku = $item['products_sku'];
            $products_date_added = $item['products_date_added'];
            $products_status = $item['products_status'];
            $products_ordered = $item['products_ordered'];
            $products_quantity = $item['products_quantity']; //product stock
            $products_stock_reorder_level = (int)STOCK_REORDER_LEVEL; //alert stock  fixfor all  products
            $products_quantity_alert = $item['products_quantity_alert']; // alert stock fix
            $manufacturer_name = HTML::sanitize($_POST['manufacturers_name']);
            $products_description = $item['products_description'];
            $seo_product_title = $item['products_head_title_tag'];
            $seo_product_description = $item['products_head_desc_tag'];
            $seo_product_keywords = $item['products_head_keywords_tag'];
            $seo_product_tag = $item['products_head_tag'];
            $products_description_summary = $item['products_head_tag'];

            $language_code = $this->lang->getLanguageCodeById((int)$item['language_id']);

            $this->app->loadDefinitions('Module/Hooks/ClicShoppingAdmin/Products/seo_chat_gpt', $language_code);
            $this->app->loadDefinitions('Module/Hooks/ClicShoppingAdmin/Products/rag', $language_code);

            $Qcategories = $this->app->db->get('categories_description', 'categories_name', ['categories_id' => $Qcategories->valueInt('categories_id'), 'language_id' => $item['language_id']]);
            $categories_name = $Qcategories->value('categories_name');

            //********************
            // add embedding
            //********************
            if ($embedding_enabled) {
              $embedding_data = $this->app->getDef('text_product_name') . ': ' . HtmlOverrideCommon::cleanHtmlForEmbedding($products_name) . "\n";
              $embedding_data .= $this->app->getDef('text_product_id') . ': ' . $products_id . "\n";

              if (!empty($products_model)) {
                $embedding_data .= $this->app->getDef('text_product_model') . ': ' . HtmlOverrideCommon::cleanHtmlForEmbedding($products_model) . "\n";
              }

              if (!empty($categories_name)) {
                $embedding_data .= $this->app->getDef('text_categories_name') . ': ' . HtmlOverrideCommon::cleanHtmlForEmbedding($categories_name) . "\n";
              }

              if (!empty($manufacturer_name)) {
                $embedding_data .= $this->app->getDef('text_product_brand_name') . ': ' . HtmlOverrideCommon::cleanHtmlForEmbedding($manufacturer_name) . "\n";
              }

              if (!empty($products_ean)) {
                $embedding_data .= $this->app->getDef('text_product_ean') . ': ' . HtmlOverrideCommon::cleanHtmlForEmbedding($products_ean) . "\n";
              }

              if (!empty($products_sku)) {
                $embedding_data .= $this->app->getDef('text_product_sku') . ': ' . HtmlOverrideCommon::cleanHtmlForEmbedding($products_sku) . "\n";
              }

              if (!empty($products_date_added)) {
                $embedding_data .= $this->app->getDef('text_product_date_added') . ': ' . HtmlOverrideCommon::cleanHtmlForEmbedding($products_date_added) . "\n";
              }

              if (!empty($products_status)) {
                if ($products_status === 1) {
                  $products_status = $this->app->getDef('text_product_enable');
                } else {
                  $products_status = $this->app->getDef('text_product_disable');
                }

                $embedding_data .= $this->app->getDef('text_product_status') . ': ' . $products_status . "\n";
              }

              if (!empty($products_ordered)) {
                $embedding_data .= $this->app->getDef('text_product_ordered') . ': ' . HtmlOverrideCommon::cleanHtmlForEmbedding($products_ordered) . "\n";
              }

              if (!empty($products_stock_reorder_level)) {
                $embedding_data .= $this->app->getDef('text_product_stock_reorder') . ': ' . HtmlOverrideCommon::cleanHtmlForEmbedding($products_stock_reorder_level) . "\n";
              }

              if (!empty($products_quantity)) {
                $embedding_data .= $this->app->getDef('text_product_stock') . ': ' . HtmlOverrideCommon::cleanHtmlForEmbedding($products_quantity) . "\n";
              }

              if (!empty($products_quantity_alert)) {
                $embedding_data .= $this->app->getDef('text_product_stock_alert') . ': ' . HtmlOverrideCommon::cleanHtmlForEmbedding($products_quantity_alert) . "\n";
              }

              if (!empty($products_description)) {
                $embedding_data .= $this->app->getDef('text_product_description') . ': ' . HtmlOverrideCommon::cleanHtmlForEmbedding($products_description) . "\n";
              }

              if (!empty($products_description_summary)) {
                $embedding_data .= $this->app->getDef('text_product_description_summary') . ': ' . HtmlOverrideCommon::cleanHtmlForEmbedding($products_description_summary) . "\n";
              }

              if (!empty($seo_product_title)) {
                $embedding_data .= $this->app->getDef('text_product_seo_title') . ': ' . HtmlOverrideCommon::cleanHtmlForSEO($seo_product_title) . "\n";
              }

              if (!empty($seo_product_description)) {
                $embedding_data .= $this->app->getDef('text_product_seo_description') . ': ' . HtmlOverrideCommon::cleanHtmlForEmbedding($seo_product_description) . "\n";
              }

              if (!empty($seo_product_keywords)) {
                $embedding_data .= $this->app->getDef('text_product_seo_keywords') . ': ' . HtmlOverrideCommon::cleanHtmlForSEO($seo_product_keywords) . "\n";
              }

              if (!empty($seo_product_tag)) {
                $embedding_data .= $this->app->getDef('text_product_seo_tag') . ': ' . HtmlOverrideCommon::cleanHtmlForSEO($seo_product_tag) . "\n";
              }

              if (!empty($products_description)) {
	              $embedding_data .= $this->app->getDef('text_product_description') . ': ' . HtmlOverrideCommon::cleanHtmlForEmbedding($products_description) . "\n";
                $taxonomy = $this->semantics->createTaxonomy(HtmlOverrideCommon::cleanHtmlForEmbedding($products_description), $language_code, null);

                if (!empty($taxonomy)) {
                  $lines = array_filter(array_map('trim', explode("\n", $taxonomy)));
                  $tags = [];

                  foreach ($lines as $line) {
                    if (preg_match('/^\[([^\]]+)\]:\s*(.+)$/', $line, $matches)) {
                      $tags[$matches[1]] = trim($matches[2]);
                    }
                  }
                } else {
                  $tags = [];
                }

                $embedding_data .= "\n" . $this->app->getDef('text_product_taxonomy') . " :\n";

                foreach ($tags as $key => $value) {
                  $embedding_data .= "[$key]: $value\n";
                }
              }
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
                'type' => 'products',
                'sourcetype' => 'manual',
                'sourcename' => 'manual',
                'date_modified' => 'now()',
              ];

              $sql_data_array_embedding['vec_embedding'] = $new_embedding_literal;
              // MetaData  creation 
              $metadata = [
                'product_name' => $products_name,
                'content' => $products_description,
                'language_id' => (int)$item['language_id'],
                'product_id' => (int)$item['products_id'],
                'type' => 'products',
                'source' => [
                  'type' => 'manual',
                  'name' => 'manual'
                ],
                'entity_id' => (int)$item['products_id'],
                'chunk_number' => isset($item['chunknumber']) ? (int)$item['chunknumber'] : 1,
                'tags' => $taxonomy ? array_filter(array_map(fn($t) => trim(strip_tags($t)), explode("\n", $taxonomy))) : [],
                'last_modified' => date('c')
              ];

              // Ajouter le JSON au tableau d'insertion
              $sql_data_array_embedding['metadata'] = json_encode($metadata, JSON_THROW_ON_ERROR);

              if ($insert_embedding === true) {
                $sql_data_array_embedding['entity_id'] = (int)$item['products_id'];
                $sql_data_array_embedding['language_id'] = (int)$item['language_id'];

                $this->app->db->save('products_embedding', $sql_data_array_embedding);
              } else {
                $update_sql_data = [
                  'language_id' => $item['language_id'],
                  'entity_id' => (int)$item['products_id']
                ];
                $this->app->db->save('products_embedding', $sql_data_array_embedding, $update_sql_data);
              }
            }
          }
        }
      }
    }
  }
}