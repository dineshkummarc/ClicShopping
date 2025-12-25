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
    $this->lang = Registry::get('Language');

    if (!Registry::exists('Semantics')) {
      Registry::set('Semantics', new Semantics());
    }

    $this->semantics = Registry::get('Semantics');
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

    $embedding_enabled = \defined('CLICSHOPPING_APP_CHATGPT_RA_OPENAI_EMBEDDING') && CLICSHOPPING_APP_CHATGPT_RA_OPENAI_EMBEDDING == 'True' && \defined( 'CLICSHOPPING_APP_CHATGPT_RA_STATUS') && CLICSHOPPING_APP_CHATGPT_RA_STATUS == 'True';

    if (isset($_GET['Update'], $_GET['Suppliers'])) {
      if (isset($_GET['mID'])) {
        $mID = HTML::sanitize($_GET['mID']);

        $Qcheck = $this->app->db->prepare('select id
                                           from :table_suppliers_embedding
                                           where entity_id = :entity_id
                                          ');
        $Qcheck->bindInt(':entity_id', $mID);
        $Qcheck->execute();

        $insert_embedding = false;

        if ($Qcheck->fetch() === false) {
          $insert_embedding = true;
        }

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
                                            where suppliers_id = :suppliers_id
                                            order by suppliers_id desc
                                            limit 1
                                          ');
        $Qcheck->bindInt(':suppliers_id', $mID);
        $Qcheck->execute();

        $supplier_name = $Qcheck->value('suppliers_name');
        $suppliers_id = $Qcheck->valueInt('suppliers_id');
        $date_added = $Qcheck->value('date_added');
        $suppliers_country_id = $Qcheck->valueInt('suppliers_country_id');
        $suppliers_status = $Qcheck->valueInt('suppliers_status');
        $suppliers_manager = $Qcheck->value('suppliers_manager');
        $suppliers_phone = $Qcheck->value('suppliers_phone');
        $suppliers_email_address = $Qcheck->value('suppliers_email_address');
        $suppliers_address = $Qcheck->value('suppliers_address');
        $suppliers_suburb = $Qcheck->value('suppliers_suburb');
        $suppliers_postcode = $Qcheck->value('suppliers_postcode');
        $suppliers_city = $Qcheck->value('suppliers_city');
        $suppliers_notes = $Qcheck->value('suppliers_notes');
        $suppliers_states = $Qcheck->value('suppliers_states');

        if ($suppliers_status == 0) {
          $suppliers_status = $this->app->getDef('text_status_active');
        } else {
          $suppliers_status = $this->app->getDef('text_status_inactive');
        }

        if ($suppliers_id !== null) {
          //********************
           // add embedding (WITHOUT taxonomy in content)
          //********************
           if ($embedding_enabled) {
              $embedding_data = $this->app->getDef('text_supplier_name') . ' : ' . HTMLOverrideCommon::cleanHtmlForEmbedding($supplier_name) . "\n";
              $embedding_data .= $this->app->getDef('text_supplier_id') . ' : ' . $suppliers_id . "\n";

              if (!empty($date_added)) {
                $embedding_data .= $this->app->getDef('text_supplier_date_added', ['supplier_name' => $supplier_name]) . ' : ' . HTMLOverrideCommon::cleanHtmlForEmbedding($date_added) . "\n";
              }

              if (!empty($suppliers_manager)) {
                $embedding_data .= $this->app->getDef('text_suppliers_manager', ['supplier_name' => $supplier_name]) . ' : ' . HTMLOverrideCommon::cleanHtmlForEmbedding($suppliers_manager) . "\n";
              }

              if (!empty($suppliers_phone)) {
                $embedding_data .= $this->app->getDef('text_suppliers_phone', ['supplier_name' => $supplier_name]) . ' : ' . HTMLOverrideCommon::cleanHtmlForEmbedding($suppliers_phone) . "\n";
              }

              if (!empty($suppliers_email_address)) {
                $embedding_data .= $this->app->getDef('text_suppliers_email_address', ['supplier_name' => $supplier_name]) . ' : ' . HTMLOverrideCommon::cleanHtmlForEmbedding($suppliers_email_address) . "\n";
              }

              if (!empty($suppliers_address)) {
                $embedding_data .= $this->app->getDef('text_suppliers_address', ['supplier_name' => $supplier_name]) . ' : ' . HTMLOverrideCommon::cleanHtmlForEmbedding($suppliers_address) . "\n";
              }

              if (!empty($suppliers_suburb)) {
                $embedding_data .= $this->app->getDef('text_supplier_suburb', ['supplier_name' => $supplier_name]) . ' : ' . HTMLOverrideCommon::cleanHtmlForEmbedding($suppliers_suburb) . "\n";
              }

              if (!empty($suppliers_postcode)) {
                $embedding_data .= $this->app->getDef('text_suppliers_postcode', ['supplier_name' => $supplier_name]) . ' : ' . HTMLOverrideCommon::cleanHtmlForEmbedding($suppliers_postcode) . "\n";
              }

               if (!empty($suppliers_states)) {
                 $embedding_data .= $this->app->getDef('text_suppliers_states', ['supplier_name' => $supplier_name]) . ' : ' . HTMLOverrideCommon::cleanHtmlForEmbedding($suppliers_states) . "\n";
               }

               if (!empty($suppliers_city)) {
                 $embedding_data .= $this->app->getDef('text_supplier_city', ['supplier_name' => $supplier_name]) . ' : ' . HTMLOverrideCommon::cleanHtmlForEmbedding($suppliers_city) . "\n";
               }

               if (!empty($suppliers_country_id)) {
                 $embedding_data .= $this->app->getDef('text_supplier_country_id', ['supplier_name' => $supplier_name]) . ' : ' . HTMLOverrideCommon::cleanHtmlForEmbedding($suppliers_country_id) . "\n";
               }

               if (!empty($suppliers_notes)) {
                 $embedding_data .= $this->app->getDef('text_suppliers_notes', ['supplier_name' => $supplier_name]) . ' : ' . HTMLOverrideCommon::cleanHtmlForEmbedding($suppliers_notes) . "\n";
               }

              $taxonomy = $this->semantics->createTaxonomy(HTMLOverrideCommon::cleanHtmlForEmbedding($embedding_data), null);

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

              $embedding_data .= "\n" . $this->app->getDef('text_supplier_taxonomy') . " :\n";

              foreach ($tags as $key => $value) {
                $embedding_data .= "[$key]: $value\n";
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
                  'type' => 'suppliers',
                  'sourcetype' => 'manual',
                  'sourcename' => 'manual',
                  'date_modified' => 'now()',
                  'entity_id' => $suppliers_id
                ];

                $sql_data_array_embedding['vec_embedding'] = $new_embedding_literal;

               // MetaData  creation 
                $metadata = [
                  'supplier_name' => HTMLOverrideCommon::cleanHtmlForEmbedding($supplier_name),
                  'content' => HTMLOverrideCommon::cleanHtmlForEmbedding($supplier_name),
                  'supplier_id' => (int)$suppliers_id,
                  'type' => 'suppliers',
                  'source' => [
                    'type' => 'manual',
                    'name' => 'manual'
                  ],
                  'entity_id' => (int)$suppliers_id,
                'chunk_number' => isset($item['chunknumber']) ? (int)$item['chunknumber'] : 1,
                'tags' => $taxonomy ? array_filter(array_map(fn($t) => trim(strip_tags($t)), explode("\n", $taxonomy))) : [],
                  'date_modified' => 'now()'
              ];

               // Ajouter le JSON au tableau d'insertion
              $sql_data_array_embedding['metadata'] = json_encode($metadata, JSON_THROW_ON_ERROR);

                if ($insert_embedding === true) {
                  $sql_data_array_embedding['entity_id'] = (int)$suppliers_id;

                  $this->app->db->save('suppliers_embedding', $sql_data_array_embedding);
                } else {
                  $sql_data_array_embedding['date_modified'] = 'now()';

                  $update_sql_data = ['entity_id' => $suppliers_id];

                  $this->app->db->save('suppliers_embedding', $sql_data_array_embedding, $update_sql_data);
                }

             }
          }
        }
      }
    }
  }
}