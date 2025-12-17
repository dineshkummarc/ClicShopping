<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Configuration\ChatGpt\Module\Hooks\ClicShoppingAdmin\PageManager;

use AllowDynamicProperties;
use ClicShopping\OM\Registry;
use ClicShopping\OM\HTML;

use ClicShopping\Apps\Configuration\ChatGpt\ChatGpt as ChatGptApp;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\Gpt;
use ClicShopping\AI\Domain\Embedding\NewVector;
use ClicShopping\Sites\Common\HTMLOverrideCommon;
use ClicShopping\AI\Domain\Semantics\Semantics;

#[AllowDynamicProperties]
class Save implements \ClicShopping\OM\Modules\HooksInterface
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

    if (isset($_GET['Save'], $_GET['PageManager'])) {
      if (isset($_POST['pages_id'])) {
        $pages_id = HTML::sanitize($_POST['pages_id']);
      } else {
        $QpageManager = $this->app->db->prepare('select pages_id
                                                 from :table_pages_manager
                                                 order by DESC
                                                 limit 1
                                               ');
        $QpageManager->execute();
        $pages_id = $QpageManager->valueInt('pages_id');
      }

      $Qcheck = $this->app->db->prepare('select id
                                        from :table_pages_manager_embedding
                                        where entity_id = :entity_id
                                        ');
      $Qcheck->bindInt(':entity_id', $pages_id);
      $Qcheck->execute();

      $insert_embedding = false;

      if ($Qcheck->fetch() === false) {
        $insert_embedding = true;
      }

      $QpageManager = $this->app->db->prepare('select pm.pages_id,
                                                      pm.page_type,       
                                                      pmd.pages_title,
                                                      pmd.pages_html_text,
                                                      pmd.page_manager_head_title_tag,
                                                      pmd.page_manager_head_desc_tag,
                                                      pmd.page_manager_head_keywords_tag,
                                                      pmd.language_id
                                               from  :table_pages_manager pm,
                                                     :table_pages_manager_description pmd
                                               where pm.pages_id = :pages_id
                                               and pm.pages_id = pmd.pages_id 
                                               and page_type = 4
                                              ');
      $QpageManager->bindInt(':pages_id', $pages_id);
      $QpageManager->execute();

      $page_manager_array = $QpageManager->fetchAll();

      if (is_array($page_manager_array)) {
        foreach ($page_manager_array as $item) {
          $language_code = $this->lang->getLanguageCodeById((int)$item['language_id']);
          $page_manager_id = (int)$item['pages_id'];
          $this->app->loadDefinitions('Module/Hooks/ClicShoppingAdmin/PageManager/seo_chat_gpt', $language_code);
          $this->app->loadDefinitions('Module/Hooks/ClicShoppingAdmin/PageManager/rag', $language_code);

          if ($item['page_type'] === 4) {
            $page_manager_name = isset($item['pages_title']) ? HtmlOverrideCommon::cleanHtmlForEmbedding($item['pages_title']) : '';
            $page_manager_description = isset($item['pages_html_text']) ? HtmlOverrideCommon::cleanHtmlForEmbedding($item['pages_html_text']) : '';
            $seo_page_manager_title = isset($item['page_manager_head_title_tag']) ? HtmlOverrideCommon::cleanHtmlForEmbedding($item['page_manager_head_title_tag']) : '';
            $seo_page_manager_description = isset($item['page_manager_head_desc_tag']) ? HtmlOverrideCommon::cleanHtmlForEmbedding($item['page_manager_head_desc_tag']) : '';
            $seo_page_manager_keywords = isset($item['page_manager_head_keywords_tag']) ? HtmlOverrideCommon::cleanHtmlForEmbedding($item['page_manager_head_keywords_tag']) : '';

            //********************
            // add embedding
            //********************

            if ($embedding_enabled) {
              $embedding_data = "\n" . $this->app->getDef('text_page_manager_name', ['page_title' => $page_manager_name]) . "\n";

              $embedding_data .= "\n" . $this->app->getDef('text_page_manager_id', ['page_id' => $page_manager_id]) . "\n";

              if (!empty($seo_page_manager_title)) {
                $embedding_data .= $this->app->getDef('text_page_manager_seo_title', ['page_seo_title' => $page_manager_name]) . ' : ' . $seo_page_manager_title . "\n";
              }

              if (!empty($seo_page_manager_description)) {
                $embedding_data .= $this->app->getDef('text_page_manager_seo_description', ['page_seo_description' => $page_manager_name]) . ' : ' . $seo_page_manager_description . "\n";
              }

              if (!empty($seo_page_manager_keywords)) {
                $embedding_data .= $this->app->getDef('text_page_manager_seo_keywords', ['page_seo_keywords' => $page_manager_name]) . ' : ' . $seo_page_manager_keywords . "\n";
              }

              if (!empty($page_manager_description)) {
                $embedding_data .= $this->app->getDef('text_page_manager_description', ['page_description' => $page_manager_name]) . ' : ' . HtmlOverrideCommon::cleanHtmlForEmbedding($page_manager_description) . "\n";
                $taxonomy = $this->semantics->createTaxonomy(HtmlOverrideCommon::cleanHtmlForEmbedding($page_manager_description), $language_code, null);

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

                $embedding_data .= "\n" . $this->app->getDef('text_page_manager_taxonomy') . " :\n";

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
                  'type' => 'pages_manager',
                  'sourcetype' => 'manual',
                  'sourcename' => 'manual',
                  'date_modified' => 'now()',
                ];

              $sql_data_array_embedding['vec_embedding'] = $new_embedding_literal;

              // MetaData  creation
              $metadata = [
                'brand_name' => $page_manager_name,
                'content' => $page_manager_description,
                'language_id' => (int)$item['language_id'],
                'pages_id' => (int)$item['pages_id'],
                'type' => 'pages_manager',
                'source' => [
                  'type' => 'manual',
                  'name' => 'manual'
                ],
                'entity_id' => (int)$item['pages_id'],
                'chunk_number' => isset($item['chunknumber']) ? (int)$item['chunknumber'] : 1,
                'tags' => $taxonomy ? array_filter(array_map(fn($t) => trim(strip_tags($t)), explode("\n", $taxonomy))) : [],
                'last_modified' => date('c')
              ];

              // Ajouter le JSON au tableau d'insertion
              $sql_data_array_embedding['metadata'] = json_encode($metadata, JSON_THROW_ON_ERROR);

              if ($insert_embedding === true) {
                $sql_data_array_embedding['entity_id'] = (int)$item['pages_id'];
                $sql_data_array_embedding['language_id'] = (int)$item['language_id'];

                $this->app->db->save('pages_manager_embedding', $sql_data_array_embedding);
              } else {
                $update_sql_data = [
                  'language_id' => $item['language_id'],
                  'entity_id' => $item['pages_id']
                ];

                  $this->app->db->save('pages_manager_embedding', $sql_data_array_embedding, $update_sql_data);
                }
              }
            }
          }
        }
      }
    }
  }
