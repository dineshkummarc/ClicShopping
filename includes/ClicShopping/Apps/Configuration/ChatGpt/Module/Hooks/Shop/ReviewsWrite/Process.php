<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */
namespace ClicShopping\Apps\Configuration\ChatGpt\Module\Hooks\Shop\ReviewsWrite;

use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\NewVector;
use ClicShopping\OM\Registry;

use ClicShopping\Apps\Configuration\ChatGpt\ChatGpt as ChatGptApp;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\Gpt;
use ClicShopping\Sites\Common\HTMLOverrideCommon;

class Process implements \ClicShopping\OM\Modules\HooksInterface
{

 private mixed $app;
 private mixed $db;

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
    $this->db = Registry::get('Db');
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
    if (Gpt::checkGptStatus() === false) {
      return false;
    }

    if (CLICSHOPPING_APP_CHATGPT_CH_OPENAI_EMBEDDING == 'False') {
      return false;
    }

    $QreviewsCheck = $this->db->get('select r.reviews_id
                                      from :table_reviews r
                                      order by r.reviews_id desc
                                      limit 1
                                     ');
    $QreviewsCheck->execute();
    $review_id = $QreviewsCheck->valueInt('reviews_id');

    $QcheckEntity = $this->app->db->prepare('select id
                                       from :table_pages_manager_embedding
                                       where entity_id = :entity_id
                                      ');
    $QcheckEntity->bindInt(':entity_id', $review_id);
    $QcheckEntity->execute();

    $insert_embedding = false;

    if ($QcheckEntity->fetch() === false) {
      $insert_embedding = true;
    }

    $Qreviews = $this->db->prepare('select r.reviews_id,
                                           r.reviews_rating,
                                           r.date_added,
                                           r.status,
                                           r.customers_id,
                                           r.products_id,
                                           r.reviews_read,
                                           r.customers_tag,
                                           rd.reviews_text,
                                           rd.languages_id,
                                           rv.vote,
                                           rv.sentiment
                                    from :table_reviews r,
                                         :table_reviews_description rd,
                                         :table_reviews_vote rv
                                    where r.products_id = :products_id
                                    and r.reviews_id = rd.reviews_id
                                    order by r.reviews_id desc
                                    limit 1
                                    ');
    $Qreviews->execute();

    $review_array = $Qreviews->fetchAll();

    if (is_array($review_array)) {
      foreach ($review_array as $item) {
        $reviews_text = isset($item['reviews_text']) ? HtmlOverrideCommon::cleanHtmlForEmbedding($item['reviews_text']) : '';
        $reviews_rating = isset($item['reviews_rating']) ? (int)$item['reviews_rating'] : 0;
        $reviews_read = isset($item['reviews_read']) ? (int)$item['reviews_read'] : 0;
        $date_added = isset($item['date_added']) ? $item['date_added'] : '';
        $status = isset($item['status']) ? (int)$item['status'] : 0;
        $customers_id = isset($item['customers_id']) ? (int)$item['customers_id'] : 0;
        $customers_tag = isset($item['customers_tag']) ? HtmlOverrideCommon::cleanHtmlForEmbedding($item['customers_tag']) : '';
        $vote = isset($item['vote']) ? (int)$item['vote'] : 0;
        $sentiment = isset($item['sentiment']) ? (int)$item['sentiment'] : 0;
        $products_id = isset($item['products_id']) ? (int)$item['products_id'] : 0;

//********************
// add embedding
//********************
        $embedding_data = $this->app->getDef('text_reviews') . ' : ' . '\n';
        $embedding_data .= $this->app->getDef('text_reviews_description') . ' : ' . $reviews_text . '\n';
        $embedding_data .= $this->app->getDef('text_reviews_rating') . ' : ' . $reviews_rating . '\n';
        $embedding_data .= $this->app->getDef('text_reviews_read') . ' : ' . $reviews_read . '\n';
        $embedding_data .= $this->app->getDef('text_reviews_date_added') . ' : ' . $date_added . '\n';
        $embedding_data .= $this->app->getDef('text_reviews_status') . ' : ' . $status . '\n';
        $embedding_data .= $this->app->getDef('text_reviews_customer_id') . ' : ' . $customers_id . '\n';
        $embedding_data .= $this->app->getDef('text_reviews_customer_tag') . ' : ' . $customers_tag . '\n';
        $embedding_data .= $this->app->getDef('text_reviews_customer_vote') . ' : ' . $vote . '\n';
        $embedding_data .= $this->app->getDef('text_reviews_customer_sentiment') . ' : ' . $sentiment . '\n';
        $embedding_data .= $this->app->getDef('text_reviews_products_id') . ' : ' . $products_id . '\n';

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
            'type' => 'page_manager',
            'sourcetype' => 'manual',
            'sourcename' => 'manual',
            'date_modified' => 'now()'
          ];

          $sql_data_array_embedding['vec_embedding'] = $new_embedding_literal;

          if ($insert_embedding === true) {
            $sql_data_array_embedding['entity_id'] = $item['reviews_id'];
            $sql_data_array_embedding['language_id'] = $item['languages_id'];
            $this->app->db->save('reviews_embedding', $sql_data_array_embedding);
          } else {
            $update_sql_data = [
              'language_id' => $item['languages_id'],
              'entity_id' => $item['reviews_id']
            ];
            $this->app->db->save('reviews_embedding', $sql_data_array_embedding, $update_sql_data);
          }
        }
      }
    }
  }
}