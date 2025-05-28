<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Configuration\ChatGpt\Module\Hooks\ClicShoppingAdmin\Manufacturers;

use ClicShopping\OM\Registry;
use ClicShopping\OM\HTML;

use ClicShopping\Apps\Configuration\ChatGpt\ChatGpt as ChatGptApp;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\Gpt;
use ClicShopping\Sites\Common\HTMLOverrideCommon;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\NewVector;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\Rag\Semantics;

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

    $this->app->loadDefinitions('Module/Hooks/ClicShoppingAdmin/Manufacturer/seo_chat_gpt');
    $this->app->loadDefinitions('Module/Hooks/ClicShoppingAdmin/Manufacturer/rag');
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
    if (Gpt::checkGptStatus() === false || CLICSHOPPING_APP_CHATGPT_CH_OPENAI_EMBEDDING == 'False') {
      return false;
    }

    if (isset($_GET['Update'], $_GET['Manufacturers'])) {
      if (isset($_GET['mID'])){
        $mID = HTML::sanitize($_GET['mID']);

        $Qcheck = $this->app->db->prepare('select id
                                           from :table_manufacturers_embedding
                                           where entity_id = :entity_id
                                          ');
        $Qcheck->bindInt(':entity_id', $mID);
        $Qcheck->execute();

        $insert_embedding = false;

        if ($Qcheck->fetch() === false) {
          $insert_embedding = true;
        }

        $Qmanufacturers = $this->app->db->prepare('select m.manufacturers_id,
                                                         m.manufacturers_name,
                                                         mi.manufacturer_description,
                                                         mi.manufacturer_seo_title,
                                                         mi.manufacturer_seo_description,
                                                         mi.manufacturer_seo_keyword,
                                                         mi.languages_id
                                                   from :table_manufacturers m,
                                                        :table_manufacturers_info mi
                                                   where m.manufacturers_id = :manufacturers_id
                                                   and m.manufacturers_id = mi.manufacturers_id        
                                                  ');
        $Qmanufacturers->bindInt(':manufacturers_id', $mID);
        $Qmanufacturers->execute();

        $manufacturers_array = $Qmanufacturers->fetchAll();

        if (is_array($manufacturers_array)) {
          foreach ($manufacturers_array as $item) {
            $manufacturers_name = $item['manufacturers_name'];
            $manufacturers_description = $item['manufacturer_description'];
            $seo_manufacturer_title = $item['manufacturer_seo_title'];
            $seo_manufacturer_description = $item['manufacturer_seo_description'];
            $seo_manufacturer_keywords = $item['manufacturer_seo_keywords'];

//********************
// add embedding
//********************
            $embedding_data =  "\n" . $this->app->getDef('text_manufacturer_embedded') . "\n";

            $embedding_data .= $this->app->getDef('text_manufacturer_name') . ' : ' . HtmlOverrideCommon::cleanHtmlForEmbedding($manufacturers_name) . "\n";

            if (!empty($manufacturers_description)) {
              $embedding_data .= $this->app->getDef('text_manufacturer_description') . ' : ' . HtmlOverrideCommon::cleanHtmlForEmbedding($manufacturers_description) . "\n";
              $embedding_data .= $this->app->getDef('text_manufacturer_taxonomy') . ' : ' . "\n" . Semantics::createTaxonomy($manufacturers_description) . "\n";
            }

            if (!empty($seo_manufacturer_title)) {
              $embedding_data .= $this->app->getDef('text_manufacturer_seo_title') . ' : ' .  HtmlOverrideCommon::cleanHtmlForEmbedding($seo_manufacturer_title) . "\n";
            }

            if (!empty($seo_manufacturer_description)) {
              $embedding_data .= $this->app->getDef('text_manufacturer_seo_description') . ': ' . HtmlOverrideCommon::cleanHtmlForEmbedding($seo_manufacturer_description) . "\n";
            }

            if (!empty($seo_manufacturer_keywords)) {
              $embedding_data .= $this->app->getDef('text_manufacturer_seo_keywords') . ' : ' .  HtmlOverrideCommon::cleanHtmlForEmbedding($seo_manufacturer_keywords) . "\n";
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

              $sql_data_array_embedding= [
                'content' => $embedding_data,
                'type' => 'manufacturers',
                'sourcetype' => 'manual',
                'sourcename' => 'manual',
                'date_modified' => 'now()'
              ];

              $sql_data_array_embedding['vec_embedding'] = $new_embedding_literal;

              if ($insert_embedding === true) {
                $sql_data_array_embedding['entity_id'] = $item['manufacturers_id'];
                $sql_data_array_embedding['language_id'] =  $item['languages_id'];
		
                $this->app->db->save('manufacturers_embedding', $sql_data_array_embedding);
              } else {
                $update_sql_data = [
                  'language_id' => $item['language_id'],
                  'entity_id' => $item['manufacturers_id']
                ];

                $this->app->db->save('manufacturers_embedding', $sql_data_array_embedding, $update_sql_data);
              }
            }
          }
        }
      }
    }
  }
}