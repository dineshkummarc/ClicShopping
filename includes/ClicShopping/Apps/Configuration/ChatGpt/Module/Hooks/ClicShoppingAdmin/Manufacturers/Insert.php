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

use ClicShopping\Apps\Configuration\ChatGpt\ChatGpt as ChatGptApp;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\Gpt;
use ClicShopping\Sites\Common\HTMLOverrideCommon;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\NewVector;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\Rag\Semantics;

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

    $this->app->loadDefinitions('Module/Hooks/ClicShoppingAdmin/Manufacturer/seo_chat_gpt');
    $this->app->loadDefinitions('Module/Hooks/ClicShoppingAdmin/Manufacturer/rag');
  }

  /**
   * Processes the execution related to manufacturers data management and updates in the database.
   * This includes generating SEO metadata (e.g., titles, descriptions, tags, keywords),
   * summaries, and translations based on manufacturer information, as well as optional
   *
   * @return void
   */
  public function execute()
  {
    $CLICSHOPPING_Language = Registry::get('Language');

    if (Gpt::checkGptStatus() === false || CLICSHOPPING_APP_CHATGPT_RA_OPENAI_EMBEDDING == 'False' || CLICSHOPPING_APP_CHATGPT_RA_STATUS == 'False') {
      return false;
    }

    if (isset($_GET['Insert'], $_GET['Manufacturers'])) {
      $translate_language = $this->app->getDef('text_seo_page_translate_language');

      $Qcheck = $this->app->db->prepare('select manufacturers_id,
                                                manufacturers_name,
                                                suppliers_id
                                          from :table_manufacturers
                                          order by manufacturers_id desc
                                          limit 1
                                        ');
      $Qcheck->execute();

      $manufacturers_name = $Qcheck->value('manufacturers_name');
      $manufacturers_id = $Qcheck->valueInt('manufacturers_id');
      $suppliers_id = $Qcheck->valueInt('suppliers_id');

      if ($manufacturers_id !== null) {
        $Qmanufacturers = $this->app->db->prepare('select manufacturers_id,
                                                          languages_id
                                                from :table_manufacturers_info
                                                where manufacturers_id = :manufacturers_id
                                              ');
        $Qmanufacturers->bindInt(':manufacturers_id', $manufacturers_id);
        $Qmanufacturers->execute();

        $manufacturers_array = $Qmanufacturers->fetchAll();

        if (is_array($manufacturers_array)) {
          foreach ($manufacturers_array as $item) {
            $language_name = $CLICSHOPPING_Language->getLanguagesName($item['languages_id']);

            $update_sql_data = [
              'languages_id' => $item['languages_id'],
              'manufacturers_id' => $item['manufacturers_id']
            ];

            //-------------------
            // Manufacturer description
            //-------------------
            if (isset($_POST['option_gpt_description'])) {
              $question_summary_description = $this->app->getDef('text_seo_page_summary_description_question', ['brand_name' => $manufacturers_name]);

              $manufacturers_description = $translate_language . ' ' . $language_name . ' : ' . $question_summary_description;
              $manufacturers_description = Gpt::getGptResponse($manufacturers_description);

              if ($manufacturers_description !== false) {
                $sql_data_array = [
                  'manufacturer_description' => $manufacturers_description ?? '',
                ];

                $this->app->db->save('manufacturers_info', $sql_data_array, $update_sql_data);
              }
            }

            ////-------------------
            // Seo Title
            //-------------------
            if (isset($_POST['option_gpt_seo_title'])) {
              $question = $this->app->getDef('text_seo_page_title_question', ['brand_name' => $manufacturers_name]);

              $seo_manufacturer_title = $translate_language . ' ' . $language_name . ' : ' . $question;
              $seo_manufacturer_title = Gpt::getGptResponse($seo_manufacturer_title);

              if ($seo_manufacturer_title !== false) {
                $sql_data_array = [
                  'manufacturer_seo_title' => strip_tags($seo_manufacturer_title) ?? '',
                ];

                $this->app->db->save('manufacturers_info', $sql_data_array, $update_sql_data);
              }
            }
            //-------------------
            // Seo description
            //-------------------
            if (isset($_POST['option_gpt_seo_description'])) {
              $question_summary_description = $this->app->getDef('text_seo_page_summary_description_question', ['brand_name' => $manufacturers_name]);

              $seo_manufacturer_description = $translate_language . ' ' . $language_name . ' : ' . $question_summary_description;
              $seo_manufacturer_description = Gpt::getGptResponse($seo_manufacturer_description);

              if ($seo_manufacturer_description !== false) {
                $sql_data_array = [
                  'manufacturer_seo_description' => strip_tags($seo_manufacturer_description) ?? '',
                ];

                $this->app->db->save('manufacturers_info', $sql_data_array, $update_sql_data);
              }
            }
            //-------------------
            // Seo keywords
            //-------------------
            if (isset($_POST['option_gpt_seo_keywords'])) {
              $question_keywords = $this->app->getDef('text_seo_page_keywords_question', ['brand_name' => $manufacturers_name]);

              $seo_manufacturer_keywords = $translate_language . ' ' . $language_name . ' : ' . $question_keywords;
              $seo_manufacturer_keywords = Gpt::getGptResponse($seo_manufacturer_keywords);

              if ($seo_manufacturer_keywords !== false) {
                $sql_data_array = [
                  'manufacturer_seo_keyword' => strip_tags($seo_manufacturer_keywords) ?? '',
                ];

                $this->app->db->save('manufacturers_info', $sql_data_array, $update_sql_data);
              }
            }

            //********************
            // add embedding
            //********************
            if (\defined('CLICSHOPPING_APP_CHATGPT_RA_OPENAI_EMBEDDING') && CLICSHOPPING_APP_CHATGPT_RA_OPENAI_EMBEDDING == 'True' && CLICSHOPPING_APP_CHATGPT_RA_STATUS == 'True') {
              $embedding_data =  "\n" . $this->app->getDef('text_manufacturer_embedded') . "\n";

              $embedding_data .= $this->app->getDef('text_manufacturer_name') . ' : ' . HtmlOverrideCommon::cleanHtmlForEmbedding($manufacturers_name) . "\n";

              if (!empty($manufacturers_description)) {
                $embedding_data .= $this->app->getDef('text_manufacturer_description') . ' : ' . HtmlOverrideCommon::cleanHtmlForEmbedding($manufacturers_description) . "\n";
                $embedding_data .= $this->app->getDef('text_manufacturer_taxonomy') . ' : ' . "\n" . Semantics::createTaxonomy($manufacturers_description) . "\n";
              }

              if (!empty($seo_manufacturer_title)) {
                $embedding_data .= $this->app->getDef('text_manufacturer_seo_title') . ' : ' . HtmlOverrideCommon::cleanHtmlForEmbedding($seo_manufacturer_title) . "\n";
              }

              if (!empty($seo_manufacturer_description)) {
                $embedding_data .= $this->app->getDef('text_manufacturer_seo_description') . ': ' . HtmlOverrideCommon::cleanHtmlForEmbedding($seo_manufacturer_description) . "\n";
              }

              if (!empty($seo_manufacturer_keywords)) {
                $embedding_data .= $this->app->getDef('text_manufacturer_seo_keywords') . ' : ' . HtmlOverrideCommon::cleanHtmlForEmbedding($seo_manufacturer_keywords) . "\n";
              }

              if (!empty($suppliers_id)) {
                $embedding_data .= $this->app->getDef('text_manufacturer_suppliers_id') . ' : ' . $suppliers_id . "\n";
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
                  'date_modified' => 'now()',
                  'entity_id' => $item['manufacturers_id'],
                  'language_id' => $item['languages_id']
                ];

                $sql_data_array_embedding['vec_embedding'] = $new_embedding_literal;

                $update_sql_data = [
                  'language_id' => $item['languages_id'],
                  'entity_id' => $item['manufacturers_id']
                ];

                $sql_data_array = array_merge($sql_data_array_embedding, $update_sql_data);

                $this->app->db->save('manufacturers_embedding', $sql_data_array);
              }
            }
          }
        }
      }
//-------------------
//image
//-------------------
/*
      if (isset($_POST['option_gpt_create_image'])) {
        $image = Gpt::createImageChatGpt($manufacturers_name, 'manufacturers');

        if (!empty($image) || $image !== false) {
          $sql_data_array = [
            'manufacturers_image' => $image ?? '',
          ];

          $update_sql_data = [
            'manufacturers_id' => $Qcheck->valueInt('manufacturers_id')
          ];

          $this->app->db->save('manufacturers', $sql_data_array, $update_sql_data);
        }
      }
*/
    }
  }
}