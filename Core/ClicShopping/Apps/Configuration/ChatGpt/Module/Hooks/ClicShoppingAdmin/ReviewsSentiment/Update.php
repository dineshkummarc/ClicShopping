<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Configuration\ChatGpt\Module\Hooks\ClicShoppingAdmin\ReviewsSentiment;

use AllowDynamicProperties;
use ClicShopping\OM\Registry;
use ClicShopping\Apps\Configuration\ChatGpt\ChatGpt as ChatGptApp;
use ClicShopping\Apps\Configuration\Administrators\Classes\ClicShoppingAdmin\AdministratorAdmin;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\Gpt;
use ClicShopping\AI\Domain\Embedding\NewVector;
use ClicShopping\Sites\Common\HTMLOverrideCommon;
use ClicShopping\AI\Domain\Semantics\Semantics;
use function count;

#[AllowDynamicProperties]
class Update implements \ClicShopping\OM\Modules\HooksInterface
{
  public mixed $app;
  public mixed $lang;
  public mixed $semantics;

  /**
   * Constructor method for initializing the ChatGpt application.
   * Ensures that the ChatGpt instance is registered with the Registry and fetches it for use.
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
    $this->app->loadDefinitions('Module/Hooks/ClicShoppingAdmin/ReviewsSentiment/rag');    
  }

  /**
   * Retrieves all customer reviews for a specific review ID, sanitizing the input,
   * and returning the review texts concatenated and separated by "<br> - ".
   *
   * @return string A string containing all customer reviews related to the specified review ID,
   * concatenated and separated by "<br> - ".
   */
  private function getAllCustomerReviews(): string
  {
    $id = HTML::sanitize($_GET['rID']);

    $Qreview = $this->app->db->prepare('select rd.reviews_text
                                        from :table_reviews r, 
                                            :table_reviews_description rd
                                        where r.status = 1
                                        and rd.languages_id = 1
                                        and r.reviews_id = :reviews_id
                                        and r.reviews_id = rd.reviews_id
                                      ');
    $Qreview->bindInt(':reviews_id', $id);
    $Qreview->execute();

    $review_array = $Qreview->fetchAll();

    $review_texts = [];

    foreach ($review_array as $value) {
      $review_texts[] = $value['reviews_text'];
    }

// Output the review texts separated by <br>
    $result =  implode('<br> - ', $review_texts);

    return $result;
  }

  /**
   * Generates a sentiment summary based on customer product reviews.
   *
   * @param int $language_id The ID of the language in which the sentiment analysis should be written.
   * @param string $products_name The name of the product for which the sentiment analysis is performed.
   *
   * @return string The sentiment analysis summary based on the provided product reviews.
   */
  private function generateSentiment(int $language_id, string $products_name): string
  {
    $CLICSHOPPING_Language = Registry::get('Language');
    $language_name = $CLICSHOPPING_Language->getLanguagesName($language_id);
    $text_reviews = $this->getAllCustomerReviews();

    // Split the message into words
    $words = preg_split('/\s+/', $text_reviews, -1, PREG_SPLIT_NO_EMPTY);

    // Check if the message exceeds 300 words
    if (count($words) > 2250) {
      $words = array_slice($words, 0, 300);
      $text_reviews = implode(' ', $words);
    }

    $language_array = [
      'products_name' => $products_name,
      'language_name' => $language_name,
      'text_reviews' => $text_reviews
    ];

    $prompt = $this->app->getDef('text_sentiment', $language_array);

    $sentiment = Gpt::getGptResponse($prompt, 2300, 0.5);

    return $sentiment;
  }

  /**
   * Executes the process of managing and storing sentiment data for product reviews.
   * Depending on the existence of a record, it updates or creates new sentiment entries,
   * including multi-language support for the product's sentiment description.
   *
   * @return bool|void Returns false if GPT status is unavailable; otherwise, performs the execution process without return value.
   */
  public function execute()
  {
    $CLICSHOPPING_Language = Registry::get('Language');
    $CLICSHOPPING_ProductsAdmin = Registry::get('ProductsAdmin');

    if (Gpt::checkGptStatus() === false || CLICSHOPPING_APP_CHATGPT_RA_OPENAI_EMBEDDING == 'False' || CLICSHOPPING_APP_CHATGPT_RA_STATUS == 'False') {
      return false;
    }

    $embedding_enabled = \defined('CLICSHOPPING_APP_CHATGPT_RA_OPENAI_EMBEDDING') && CLICSHOPPING_APP_CHATGPT_RA_OPENAI_EMBEDDING == 'True' && \defined( 'CLICSHOPPING_APP_CHATGPT_RA_STATUS') && CLICSHOPPING_APP_CHATGPT_RA_STATUS == 'True';

    $id = HTML::sanitize($_GET['rID']);
    $user_admin = AdministratorAdmin::getUserAdmin();
    $languages = $CLICSHOPPING_Language->getLanguages();

    $Qchek = $this->app->db->get('reviews_sentiment', 'id', ['reviews_id' => (int)$id]);
    $Qproduct = $this->app->db->get('reviews', 'products_id', ['reviews_id' => (int)$id]);
    $products_id = $Qproduct->valueInt('products_id');
    //update
    if (!empty($Qchek->valueInt('id'))) {
      $sql_data_array = [
        'reviews_id' => (int)$id,
        'date_modified' => 'now()',
        'user_admin' => $user_admin,
      ];

      $this->app->db->save('reviews_sentiment', $sql_data_array, ['id' => (int)$Qchek->valueInt('id')]);

      for ($i = 0, $n = \count($languages); $i < $n; $i++) {
        $language_id = $languages[$i]['id'];
        $products_name = $CLICSHOPPING_ProductsAdmin->getProductsName($products_id, $language_id);

        $sql_data_array = [
          'description' => $this->generateSentiment($language_id, $products_name),
        ];

        $insert_sql_data = [
          'id' => (int)$Qchek->valueInt('id'),
          'language_id' => $language_id
        ];

        $this->app->db->save('reviews_sentiment_description ', $sql_data_array, $insert_sql_data);
      }
    } else {
      //insert
      $sql_data_array = [
        'reviews_id' => (int)$id,
        'date_added' => 'now()',
        'user_admin' => $user_admin,
        'products_id' => $products_id,
        'sentiment_status' => 1
      ];

      $this->app->db->save('reviews_sentiment', $sql_data_array);
      $last_id = $this->app->db->lastInsertId();

      for ($i = 0, $n = \count($languages); $i < $n; $i++) {
        $language_id = $languages[$i]['id'];
        $products_name = $CLICSHOPPING_ProductsAdmin->getProductsName($products_id, $language_id);

        $sql_data_array = [
          'description' => $this->generateSentiment($language_id, $products_name)
        ];

        $insert_sql_data = [
          'id' => (int)$last_id,
          'language_id' => (int)$language_id
        ];

        $sql_data_array = array_merge($sql_data_array, $insert_sql_data);

        $this->app->db->save('reviews_sentiment_description ', $sql_data_array);
      }
    }

    $Qcheck = $this->app->db->prepare('select id
                                         from :reviews_sentiment_embedding
                                         where entity_id = :entity_id
                                        ');
    $Qcheck->bindInt(':entity_id', $id);
    $Qcheck->execute();

    $insert_embedding = false;

    if ($Qcheck->fetch() === false) {
      $insert_embedding = true;
    }

    $QreviewSentiment = $this->app->db->prepare('SELECT distinct rs.id,
                                                                  rs.sentiment_status,
                                                                  rs.sentiment_approved,
                                                                  rs.date_added,
                                                                  rs.products_id,
                                                                  rs.reviews_id,
                                                                  rsd.language_id,
                                                                  rsd.description,
                                                                  rv.vote,
                                                                  rv.customer_id,
                                                                  rv.sentiment AS vote_sentiment
                                                    FROM 
                                                        :table_reviews_sentiment rs
                                                    INNER JOIN 
                                                        :table_reviews_sentiment_description rsd 
                                                        ON rs.id = rsd.sentiment_id
                                                    LEFT JOIN
                                                        :table_reviews_vote rv
                                                        ON rs.reviews_id = rv.reviews_id
                                                        AND rs.products_id = rv.products_id
                                                    WHERE 
                                                        rs.id = :id
                                                ');
    $QreviewSentiment->bindInt(':id', $id);
    $QreviewSentiment->execute();

    $review_sentiment_array = $QreviewSentiment->fetchAll();
    $review_sentiment_id = $QreviewSentiment->valueInt('id');

    if (is_array($review_sentiment_array)) {
      foreach ($review_sentiment_array as $item) {
        $language_code = $this->lang->getLanguageCodeById((int)$item['language_id']);
        $this->app->loadDefinitions('Module/Hooks/ClicShoppingAdmin/PageManager/rag', $language_code);
        $products_id = $item['products_id'];
        $language_id = $item['language_id'];

        $products_name = $CLICSHOPPING_ProductsAdmin->getProductsName($products_id, $item['language_id']);
        $sentiment_status = isset($item['sentiment_status']) ? HTMLOverrideCommon::cleanHtmlForEmbedding($item['sentiment_status']) : '';
        $sentiment_approved = isset($item['sentiment_approved']) ? HTMLOverrideCommon::cleanHtmlForEmbedding($item['sentiment_approved']) : '';
        $date_added = isset($item['date_added']) ? HTMLOverrideCommon::cleanHtmlForEmbedding($item['date_added']) : '';
        $description = isset($item['description']) ? HTMLOverrideCommon::cleanHtmlForEmbedding($item['description']) : '';
        $vote = isset($item['vote']) ? HTMLOverrideCommon::cleanHtmlForEmbedding($item['vote']) : '0';
        $customer_id = isset($item['customer_id']) ? HTMLOverrideCommon::cleanHtmlForEmbedding($item['customer_id']) : '';
        $vote_sentiment = isset($item['vote_sentiment']) ? HTMLOverrideCommon::cleanHtmlForEmbedding($item['vote_sentiment']) : '';

        //********************
        // add embedding
        //********************

        if ($embedding_enabled) {
          $embedding_data = "\n" . $this->app->getDef('text_review_sentiment_semantic_title', ['products_name' => $products_name]) . "\n";
          $embedding_data .= $this->app->getDef('text_sentiment_semantic_review_sentiment_id', ['review_sentiment_id' => $review_sentiment_id]) . "\n";
          $embedding_data .= $this->app->getDef('text_review_sentiment_semantic_status', ['products_name' => $products_name]) . ' : ' . $sentiment_status . "\n";
          $embedding_data .= $this->app->getDef('text_review_sentiment_semantic_approved', ['products_name' => $products_name]) . ' : ' . $sentiment_approved . "\n";
          $embedding_data .= $this->app->getDef('text_review_sentiment_semantic_date_added', ['products_name' => $products_name]) . ' : ' . $date_added . "\n";
          $embedding_data .= $this->app->getDef('text_review_sentiment_semantic_vote', ['products_name' => $products_name]) . ' : ' . $vote . "\n";
          $embedding_data .= $this->app->getDef('text_review_sentiment_semantic_customer_id', ['products_name' => $products_name]) . ' : ' . $customer_id . "\n";
          $embedding_data .= $this->app->getDef('text_review_sentiment_semantic_vote_sentiment', ['products_name' => $products_name]) . ' : ' . $vote_sentiment . "\n";
          $embedding_data .= $this->app->getDef('text_review_sentiment_semantic_description', ['products_name' => $products_name]) . ' : ' . HTMLOverrideCommon::cleanHtmlForEmbedding($description) . "\n";

          $taxonomy = $this->semantics->createTaxonomy(HTMLOverrideCommon::cleanHtmlForEmbedding($description), $language_code, null);

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

          $embedding_data .= "\n" . $this->app->getDef('text_review_sentiment_taxonomy') . " :\n";

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
              'type' => 'review_sentiment',
              'sourcetype' => 'manual',
              'sourcename' => 'manual',
              'date_modified' => 'now()',
              'language_id' => (int)$item['language_id'],
              'entity_id' => (int)$item['id'],
             ];

          $sql_data_array_embedding['vec_embedding'] = $new_embedding_literal;

            // MetaData  creation 
            $metadata = [
            'review_sentiment_name' => HtmlOverrideCommon::cleanHtmlForEmbedding($products_name),
            'content' => HtmlOverrideCommon::cleanHtmlForEmbedding($description) ,
            'language_id' => (int)$item['language_id'],
            'id' => (int)$item['id'],
            'type' => 'review_sentiment',
            'source' => [
              'type' => 'manual',
              'name' => 'manual'
            ],
            'entity_id' => (int)$item['id'],
            'chunk_number' => isset($item['chunknumber']) ? (int)$item['chunknumber'] : 1,
            'tags' => $taxonomy ? array_filter(array_map(fn($t) => trim(strip_tags($t)), explode("\n", $taxonomy))) : [],
            'date_modified' => 'now()'
          ];

         // Ajouter le JSON au tableau d'insertion
          $sql_data_array_embedding['metadata'] = json_encode($metadata, JSON_THROW_ON_ERROR);

          if ($insert_embedding === true) {
            $sql_data_array_embedding['entity_id'] = (int)$item['id'];
            $sql_data_array_embedding['language_id'] = (int)$language_id;
            $this->app->db->save('reviews_sentiment_embedding', $sql_data_array_embedding);
          } else {
            $sql_data_array_embedding['date_modified'] = 'now()';	  
            $update_sql_data = [
              'language_id' => $language_id,
              'entity_id' => $item['id']
            ];

            $this->app->db->save('reviews_sentiment_embedding', $sql_data_array_embedding, $update_sql_data);
          }
        }
      }
    }
  }
}
