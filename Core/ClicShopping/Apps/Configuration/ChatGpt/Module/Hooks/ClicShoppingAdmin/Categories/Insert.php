<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Configuration\ChatGpt\Module\Hooks\ClicShoppingAdmin\Categories;

use AllowDynamicProperties;
use ClicShopping\OM\Registry;

use ClicShopping\Apps\Configuration\ChatGpt\ChatGpt as ChatGptApp;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\Gpt;
use ClicShopping\AI\Domain\Embedding\NewVector;
use ClicShopping\Sites\Common\HTMLOverrideCommon;
use ClicShopping\AI\Domain\Semantics\Semantics;

#[AllowDynamicProperties]
class Insert implements \ClicShopping\OM\Modules\HooksInterface
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
    $this->app->loadDefinitions('Module/Hooks/ClicShoppingAdmin/Categories/rag');
  }

  /**
   * Executes the necessary processes based on the provided GET and POST parameters related to category handling.
   *
   * Checks if GPT functionality is enabled and processes category-related inputs to update database records
   * such as descriptions, SEO data (title, description, keywords), and optionally images.
   *
   * @return bool Returns false if GPT functionality is disabled or not applicable; otherwise, performs the operations without returning a value.
   */
  public function execute()
  {
    if (Gpt::checkGptStatus() === false || CLICSHOPPING_APP_CHATGPT_RA_OPENAI_EMBEDDING == 'False' || CLICSHOPPING_APP_CHATGPT_RA_STATUS == 'False') {
      return false;
    }

    $embedding_enabled = \defined('CLICSHOPPING_APP_CHATGPT_RA_OPENAI_EMBEDDING') && CLICSHOPPING_APP_CHATGPT_RA_OPENAI_EMBEDDING == 'True' && \defined( 'CLICSHOPPING_APP_CHATGPT_RA_STATUS') && CLICSHOPPING_APP_CHATGPT_RA_STATUS == 'True';

    if (isset($_GET['Insert'], $_GET['Categories'])) {
      $translate_language = $this->app->getDef('text_seo_page_translate_language');

      $CLICSHOPPING_CategoriesAdmin = Registry::get('CategoriesAdmin');

      $Qcheck = $this->app->db->prepare('select categories_id
                                            from :table_categories
                                            order by categories_id desc
                                            limit 1
                                          ');
      $Qcheck->execute();

      if ($Qcheck->valueInt('categories_id') !== null) {
        $Qcategories = $this->app->db->prepare('select categories_id,
                                                       categories_name,
                                                       language_id
                                                 from :table_categories_description
                                                 where categories_id = :categories_id
                                                ');
        $Qcategories->bindInt(':categories_id', $Qcheck->valueInt('categories_id'));
        $Qcategories->execute();

        $categories_array = $Qcategories->fetchAll();

        if (is_array($categories_array)) {
          foreach ($categories_array as $item) {
            $categories_name = $CLICSHOPPING_CategoriesAdmin->getCategoryName($item['categories_id'], $item['language_id']);
            $language_name = $this->lang->getLanguagesName($item['language_id']);
            $language_code = $this->lang->getLanguageCodeById((int)$item['language_id']);

            $this->app->loadDefinitions('Module/Hooks/ClicShoppingAdmin/Categories/seo_chat_gpt', $language_code);
            $this->app->loadDefinitions('Module/Hooks/ClicShoppingAdmin/Categories/rag', $language_code);

            $categories_id = $item['categories_id'];
	    
            $update_sql_data = [
              'language_id' => $item['language_id'],
              'categories_id' => $categories_id
            ];

            //-------------------
            // categories description
            //-------------------
            $categories_description = '';

            if (isset($_POST['option_gpt_description'])) {
              $question_description = $this->app->getDef('text_categories_description', ['category_name' => $categories_name]);
              $categories_description = $translate_language . ' ' . $language_name . ' ' . $question_description;
              $categories_description = Gpt::getGptResponse($categories_description);

              if ($categories_description !== false) {
                $sql_data_array = [
                  'categories_description' => $categories_description ?? '',
                ];

                $this->app->db->save('categories_description', $sql_data_array, $update_sql_data);
              }
            }

            //-------------------
            // Seo Title
            //-------------------
            $seo_categories_title = '';
            if (isset($_POST['option_gpt_seo_title'])) {
              $question = $this->app->getDef('text_seo_page_title_question', ['category_name' => $categories_name]);

              $seo_categories_title = $translate_language . ' ' . $language_name . ' : ' . $question;
              $seo_categories_title = Gpt::getGptResponse($seo_categories_title);

              if ($seo_categories_title !== false) {
                $sql_data_array = [
                  'categories_head_title_tag' => strip_tags($seo_categories_title) ?? '',
                ];

                $this->app->db->save('categories_description', $sql_data_array, $update_sql_data);
              }
            }

            //-------------------
            // Seo description
            //-------------------
            $seo_categories_description = '';
	    
            if (isset($_POST['option_gpt_seo_title'])) {
              $question_summary_description = $this->app->getDef('text_seo_page_summary_description_question', ['category_name' => $categories_name]);

              $seo_categories_description = $translate_language . ' ' . $language_name . ' : ' . $question_summary_description;
              $seo_categories_description = Gpt::getGptResponse($seo_categories_description);

              if ($seo_categories_description !== false) {
                $sql_data_array = [
                  'categories_head_desc_tag' => strip_tags($seo_categories_description) ?? '',
                ];

                $this->app->db->save('categories_description', $sql_data_array, $update_sql_data);
              }
            }
            //-------------------
            // Seo keywords
            //-------------------
            $seo_categories_keywords = '';
	    
            if (isset($_POST['option_gpt_seo_keywords'])) {
              $question_keywords = $this->app->getDef('text_seo_page_keywords_question', ['category_name' => $categories_name]);

              $seo_categories_keywords = $translate_language . ' ' . $language_name . ' : ' . $question_keywords;
              $seo_categories_keywords = Gpt::getGptResponse($seo_categories_keywords);

              if ($seo_categories_keywords !== false) {
                $sql_data_array = [
                  'categories_head_keywords_tag' => strip_tags($seo_categories_keywords) ?? '',
                ];

                $this->app->db->save('categories_description', $sql_data_array, $update_sql_data);
              }
            }

              //********************
              // add embedding
              //********************

            if ($embedding_enabled) {
              $embedding_data =  "\n" . $this->app->getDef('text_category_embedded') . "\n";

              $embedding_data .= $this->app->getDef('text_category_name') . ' : ' . HTMLOverrideCommon::cleanHtmlForEmbedding($categories_name) . "\n";
              $embedding_data .= $this->app->getDef('text_category_id') . ' : ' . (int)$categories_id . "\n";

              if (!empty($categories_description)) {
                $categories_description = HTMLOverrideCommon::cleanHtmlForEmbedding($categories_description);
                $embedding_data .= $this->app->getDef('text_category_description', ['category_name' => $categories_name]) . ' : ' . $categories_description . "\n";;

                $taxonomy = $this->semantics->createTaxonomy($categories_description, $language_code, null);

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

                $embedding_data .= "\n" . $this->app->getDef('text_category_taxonomy') . " :\n";

                foreach ($tags as $key => $value) {
                  $embedding_data .= "[$key]: $value\n";
                }
              }

              if (!empty($seo_categories_title)) {
                $embedding_data .= $this->app->getDef('text_category_seo_title', ['category_name' => $categories_name]) . ' : ' .  HTMLOverrideCommon::cleanHtmlForSEO($seo_categories_title) . "\n";
              }

              if (!empty($seo_categories_description)) {
                $embedding_data .= $this->app->getDef('text_category_seo_description', ['category_name' => $categories_name]) . ': ' .  HTMLOverrideCommon::cleanHtmlForSEO($seo_categories_description) . "\n";
              }

              if (!empty($seo_categories_keywords)) {
                $embedding_data .= $this->app->getDef('text_category_seo_keywords', ['category_name' => $categories_name]) . ' : ' . HTMLOverrideCommon::cleanHtmlForSEO($seo_categories_keywords) . "\n";
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
                  'type' => 'categories',
                  'sourcetype' => 'manual',
                  'sourcename' => 'manual',
                  'date_modified' => 'now()',
                  'entity_id'=> (int)$item['categories_id'],
                  'language_id' => (int)$item['language_id']
                ];

              $sql_data_array_embedding['vec_embedding'] = $new_embedding_literal;

              // MetaData  creation 
              $metadata = [
                'category_name' => $categories_name,
                'content' => $categories_description,
                'language_id' => (int)$item['language_id'],
                'category_id' => (int)$categories_id,
                'type' => 'categories',
                'source' => [
                  'type' => 'manual',
                  'name' => 'manual'
                ],
                'entity_id' => (int)$categories_id,
                'chunk_number' => isset($item['chunknumber']) ? (int)$item['chunknumber'] : 1,
                'tags' => $taxonomy ? array_filter(array_map(fn($t) => trim(strip_tags($t)), explode("\n", $taxonomy))) : [],
                'last_modified' => date('c')
              ];

              // Ajouter le JSON au tableau d'insertion
                $sql_data_array_embedding['metadata'] = json_encode($metadata, JSON_THROW_ON_ERROR);

                $sql_data_array_embedding['entity_id'] = $categories_id;
                $sql_data_array_embedding['language_id'] =  $item['language_id'];

                $this->app->db->save('categories_embedding', $sql_data_array_embedding);
              }
            }
          }
        }
//-------------------
//image
//-------------------
/*
        if (isset($_POST['option_gpt_create_image'])) {
          $Qcategories = $this->app->db->prepare('select categories_name,
                                                           language_id
                                                    from :table_categories_description
                                                    where categories_id = :categories_id
                                                    and language_id = 1
                                                  ');
          $Qcategories->bindInt(':categories_id', $Qcheck->valueInt('categories_id'));
          $Qcategories->execute();

          $image = Gpt::createImageChatGpt($Qcategories->value('categories_name'), 'categories', '256x256');

          if (!empty($image) || $image !== false) {
            $sql_data_array = [
              'categories_image' => $image ?? '',
            ];

            $update_sql_data = ['categories_id' => $Qcheck->valueInt('categories_id')];

            $this->app->db->save('categories', $sql_data_array, $update_sql_data);
          }
}
*/
      }
    }
  }
}