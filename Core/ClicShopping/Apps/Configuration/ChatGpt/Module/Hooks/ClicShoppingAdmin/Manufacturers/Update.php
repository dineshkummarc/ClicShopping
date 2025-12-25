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

use AllowDynamicProperties;
use ClicShopping\OM\Registry;
use ClicShopping\OM\HTML;

use ClicShopping\Apps\Configuration\ChatGpt\ChatGpt as ChatGptApp;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\Gpt;
use ClicShopping\Sites\Common\HTMLOverrideCommon;
use ClicShopping\AI\Domain\Embedding\NewVector;
use ClicShopping\AI\Domain\Semantics\Semantics;

#[AllowDynamicProperties]
class Update implements \ClicShopping\OM\Modules\HooksInterface
{
  public mixed $app;
  public mixed $lang;
  public mixed $semantics;

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
    $this->lang = Registry::get('Language');

    if (!Registry::exists('Semantics')) {
      Registry::set('Semantics', new Semantics());
    }

    $this->semantics = Registry::get('Semantics');
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
    if (Gpt::checkGptStatus() === false || CLICSHOPPING_APP_CHATGPT_RA_OPENAI_EMBEDDING == 'False' || CLICSHOPPING_APP_CHATGPT_RA_STATUS == 'False') {
      return false;
    }

    $embedding_enabled = \defined('CLICSHOPPING_APP_CHATGPT_RA_OPENAI_EMBEDDING') && CLICSHOPPING_APP_CHATGPT_RA_OPENAI_EMBEDDING == 'True' && \defined('CLICSHOPPING_APP_CHATGPT_RA_STATUS') && CLICSHOPPING_APP_CHATGPT_RA_STATUS == 'True';

    if (isset($_GET['Update'], $_GET['Manufacturers'])) {
      if (isset($_GET['mID'])) {
        $mID = HTML::sanitize($_GET['mID']);

        $Qcheck = $this->app->db->prepare('select id
                                           from :table_manufacturers_embedding
                                           where entity_id = :entity_id
                                          ');

        $Qcheck->bindInt(':entity_id', $mID);
        $Qcheck->execute();

        $total = $Qcheck->rowCount();

        $insert_embedding = false;

        if ($total === 0) {
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
            $language_code = $this->lang->getLanguageCodeById((int)$item['languages_id']);

            $this->app->loadDefinitions('Module/Hooks/ClicShoppingAdmin/Manufacturers/seo_chat_gpt', $language_code);
            $this->app->loadDefinitions('Module/Hooks/ClicShoppingAdmin/Manufacturers/rag', $language_code);

            $languages_id = $item['languages_id'];
            $manufacturers_id = $item['manufacturers_id'];
            $manufacturers_name = $item['manufacturers_name'];
            $manufacturers_description = $item['manufacturer_description'];
            $seo_manufacturer_title = $item['manufacturer_seo_title'];
            $seo_manufacturer_description = $item['manufacturer_seo_description'];
            $seo_manufacturer_keywords = $item['manufacturer_seo_keyword'];

//********************
// add embedding
//********************

            if ($embedding_enabled) {
              $embedding_data = "\n" . $this->app->getDef('text_manufacturer_embedded') . "\n";

              $embedding_data .= $this->app->getDef('text_manufacturer_name') . ' : ' . HTMLOverrideCommon::cleanHtmlForEmbedding($manufacturers_name) . "\n";
              $embedding_data .= $this->app->getDef('text_manufacturer_id') . ' : ' . (int)$manufacturers_id . "\n";

              if (!empty($manufacturers_description)) {
                $embedding_data .= $this->app->getDef('text_manufacturer_description') . ' : ' . HtmlOverrideCommon::cleanHtmlForEmbedding($manufacturers_description) . "\n";
                $taxonomy = $this->semantics->createTaxonomy(HtmlOverrideCommon::cleanHtmlForEmbedding($manufacturers_description), $language_code, null);

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

                $embedding_data .= "\n" . $this->app->getDef('text_manufacturer_taxonomy') . " :\n";

                foreach ($tags as $key => $value) {
                  $embedding_data .= "[$key]: $value\n";
                }
              }

              if (!empty($seo_manufacturer_title)) {
                $embedding_data .= $this->app->getDef('text_manufacturer_seo_title') . ' : ' . HTMLOverrideCommon::cleanHtmlForEmbedding($seo_manufacturer_title) . "\n";
              }

              if (!empty($seo_manufacturer_description)) {
                $embedding_data .= $this->app->getDef('text_manufacturer_seo_description') . ': ' . HTMLOverrideCommon::cleanHtmlForEmbedding($seo_manufacturer_description) . "\n";
              }

              if (!empty($seo_manufacturer_keywords)) {
                $embedding_data .= $this->app->getDef('text_manufacturer_seo_keywords') . ' : ' . HTMLOverrideCommon::cleanHtmlForEmbedding($seo_manufacturer_keywords) . "\n";
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
                  'type' => 'manufacturers',
                  'sourcetype' => 'manual',
                  'sourcename' => 'manual',
                  'date_modified' => 'now()'
                ];

                $sql_data_array_embedding['vec_embedding'] = $new_embedding_literal;

                // MetaData  creation 
                $metadata = [
                  'brand_name' => $manufacturers_name,
                  'content' => $manufacturers_description,
                  'language_id' => (int)$languages_id,
                  'manufacturer_id' => (int)$manufacturers_id,
                  'type' => 'manufacturers',
                  'source' => [
                    'type' => 'manual',
                    'name' => 'manual'
                  ],
                'entity_id' => (int)$manufacturers_id,
                'chunk_number' => isset($item['chunknumber']) ? (int)$item['chunknumber'] : 1,
                'tags' => $taxonomy ? array_filter(array_map(fn($t) => trim(strip_tags($t)), explode("\n", $taxonomy))) : [],
                'ldate_modified' => 'now()'
              ];

                // Ajouter le JSON au tableau d'insertion
                $sql_data_array_embedding['metadata'] = json_encode($metadata, JSON_THROW_ON_ERROR);

                if ($insert_embedding === true) {
                  $sql_data_array_embedding['entity_id'] = (int)$manufacturers_id;
                  $sql_data_array_embedding['language_id'] = (int)$languages_id;

                  $this->app->db->save('manufacturers_embedding', $sql_data_array_embedding);
                } else {
                  $sql_data_array_embedding['date_modified'] = 'now()';

                  $update_sql_data = [
                    'language_id' => (int)$languages_id,
                    'entity_id' => (int)$manufacturers_id
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
}