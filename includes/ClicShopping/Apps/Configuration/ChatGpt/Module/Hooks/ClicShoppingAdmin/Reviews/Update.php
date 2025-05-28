<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Configuration\ChatGpt\Module\Hooks\ClicShoppingAdmin\Reviews;

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
    $this->app->loadDefinitions('Module/Hooks/ClicShoppingAdmin/Reviews/rag');
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

    if (isset($_GET['Update'], $_GET['Reviews'])) {
      if (isset($_GET['rID'])) {
        $rID = HTML::sanitize($_GET['rID']);
        $CLICSHOPPING_ProductsAdmin = Registry::get('ProductsAdmin');
        $CLICSHOPPING_Language = Registry::get('Language');
        $language_id = $CLICSHOPPING_Language->getId();

        $Qcheck = $this->app->db->prepare('select id
                                           from :table_products_embedding
                                           where entity_id = :entity_id
                                          ');
        $Qcheck->bindInt(':entity_id', $rID);
        $Qcheck->execute();

        $insert_embedding = false;

        if ($Qcheck->fetch() === false) {
          $insert_embedding = true;
        }

        $Qreviews = $this->app->db->prepare('select r.reviews_id,
                                                    r.products_id,
                                                    r.reviews_rating,
                                                    r.date_added,
                                                    r.status,
                                                    r.customers_tag,
                                                    rd.reviews_text,
                                                    rv.vote,
                                                    rv.sentiment
                                              from :table_reviews r,
                                                   :table_reviews_description rd,
                                                   :table_reviews_vote rv
                                              where r.reviews_id = rd.reviews_id
                                              and r.reviews_id = :reviews_id
                                              and r.reviews_id = rv.reviews_id
                                              ');
        $Qreviews->bindInt(':reviews_id', $rID);
        $Qreviews->execute();

        $reviews_array = $Qreviews->fetchAll();

        foreach ($reviews_array as $item) {
          $products_id = $item['products_id'];
          $reviews_text = $item['reviews_text'];
          $reviews_rating = $item['reviews_rating'];
          $date_added = $item['date_added'];
          $status = $item['status'];

          if ($status === 0) {
            $status = $this->app->getDef('text_status_active');
          } else {
            $status = $this->app->getDef('text_status_inactive');
          }

          $customers_tag = $item['customers_tag'];
          $vote = $item['vote'];
          $sentiment = $item['sentiment'];

          $products_name = $CLICSHOPPING_ProductsAdmin->getProductsName($products_id, $language_id);

          //********************
          // add embedding
          //********************
          $embedding_data = $this->app->getDef('text_reviews', ['products_name' => $products_name]) . "\n";

          if (!empty($products_id)) {
            $embedding_data .= $this->app->getDef('text_reviews_product_name', ['products_name' => $products_name]) . ': ' . HtmlOverrideCommon::cleanHtmlForEmbedding($products_name) . "\n";
          }

          if (!empty($reviews_text)) {
            $embedding_data .= $this->app->getDef('text_reviews_description', ['products_name' => $products_name]) . ': ' . HtmlOverrideCommon::cleanHtmlForEmbedding($reviews_text) . "\n";
          }

          if (!empty($reviews_rating)) {
            $embedding_data .= $this->app->getDef('text_reviews_rating', ['products_name' => $products_name]) . ': ' . (float)$reviews_rating . "\n";
          }

          if (!empty($date_added)) {
            $embedding_data .= $this->app->getDef('text_reviews_date_added', ['products_name' => $products_name]) . ': ' . HtmlOverrideCommon::cleanHtmlForEmbedding($date_added) . "\n";
          }

          if (!empty($status)) {
            $embedding_data .= $this->app->getDef('text_reviews_status', ['products_name' => $products_name]) . ': ' . HtmlOverrideCommon::cleanHtmlForEmbedding($status) . "\n";
          }

          if (!empty($customers_tag)) {
            $embedding_data .= $this->app->getDef('text_reviews_customer_tag', ['products_name' => $products_name]) . ': ' . HtmlOverrideCommon::cleanHtmlForEmbedding($customers_tag) . "\n";
          }

          if (!empty($vote)) {
            $embedding_data .= $this->app->getDef('text_reviews_customer_vote', ['products_name' => $products_name]) . ': ' . (int)$vote . "\n";
          }

          if (!empty($sentiment)) {
            $embedding_data .= $this->app->getDef('text_reviews_customer_sentiment', ['products_name' => $products_name]) . ': ' . (float)$sentiment . "\n";
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
              'type' => 'reviews',
              'sourcetype' => 'manual',
              'sourcename' => 'manual',
              'date_modified' => 'now()',
            ];

            $sql_data_array_embedding['vec_embedding'] = $new_embedding_literal;
            if ($insert_embedding === true) {
              $sql_data_array_embedding['entity_id'] = $item['reviews_id'];
              $sql_data_array_embedding['language_id'] = $item['language_id'];
              $this->app->db->save('reviews_embedding', $sql_data_array_embedding);
            } else {
              $update_sql_data = [
                'language_id' => $item['language_id'],
                'entity_id' => $item['reviews_id']
              ];
              $this->app->db->save('reviews_embedding', $sql_data_array_embedding, $update_sql_data);
            }
          }
        }
      }
    }
  }
}