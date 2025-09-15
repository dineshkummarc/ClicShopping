<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Marketing\Recommendations\Classes\ClicShoppingAdmin;

use ClicShopping\OM\Registry;
use function is_null;

class RecommendationsAdmin
{
  private mixed $db;

  public function __construct()
  {
    $this->db = Registry::get('Db');
  }

  /**
   * Calculates a recommendation score for a product using multiple data sources, such as product ratings, reviews,
   * user feedback, sentiment analysis, sales data, and external recommendations.
   *
   * @param float $productsRateWeight Weight applied to the product rating contribution to the score (default is 0.8).
   * @param float $reviewRate Average review rate for the product (default is 0).
   * @param float|null $userFeedback User-provided feedback score for the product; normalized to a range between -1 and 1 (default is 0).
   * @param float|null $sentimentScore Sentiment analysis score for the product, normalized to a range between -1 and 1 (optional).
   * @return float The calculated recommendation score for the product.
   */
  private static function calculateRecommendationScoreWithMultipleSources(int $productsId, float $productsRateWeight = 0.8, float $reviewRate = 0, ?float $userFeedback = 0, ?float $sentimentScore = null): float
  {
    // Normalize the user feedback to a value between -1 and 1
    $userFeedback = static::calculateUserFeedbackScore($userFeedback);

    // If a sentiment score is provided, adjust it to be between -1 and 1
    if (!is_null($sentimentScore)) {
      $sentimentScore = max(-1, min(1, $sentimentScore));
    }

    // Get scores from other recommendation sources (computed from data)
    $salesDataScore = static::getSalesDataScore($productsId);
    $externalRecommendationScore = static::getExternalRecommendationScore($productsId);

    // Weigh the scores from different sources (adjust weights as needed)
    $salesDataWeight = \defined('CLICSHOPPING_APP_RECOMMENDATIONS_PR_WEIGHT_SALES') ? (float)CLICSHOPPING_APP_RECOMMENDATIONS_PR_WEIGHT_SALES : 0.4;
    $externalRecommendationWeight = \defined('CLICSHOPPING_APP_RECOMMENDATIONS_PR_WEIGHT_EXTERNAL') ? (float)CLICSHOPPING_APP_RECOMMENDATIONS_PR_WEIGHT_EXTERNAL : 0.3;

    // Calculate the combined score as a weighted sum of individual scores
    $combinedScore = ($reviewRate * $productsRateWeight) +
      ($salesDataScore * $salesDataWeight) +
      ($externalRecommendationScore * $externalRecommendationWeight) +
      ($userFeedback * 0.2); // Adjust the weight of user feedback as needed

    // If a sentiment score is available, incorporate it into the combined score calculation with a specific weight
    if (!is_null($sentimentScore)) {
      $sentimentWeight = (float)CLICSHOPPING_APP_RECOMMENDATIONS_PR_WEIGHTING_SENTIMENT;
      $combinedScore = $combinedScore + ($sentimentScore * $sentimentWeight);
    }

    return $combinedScore;
  }

  /**
   * Calculates the recommendation score based on various weighted factors such as review rate, user feedback,
   * sentiment score, and product rate weight.
   *
   * @param float $productsRateWeight The weight factor applied to the product's rating, ranging between 0 and 1. Default is 0.8.
   * @param float $reviewRate The rate of reviews for the product, normalized between 0 and 1.
   * @param float|null $userFeedback The user feedback score, which will be normalized between -1 and 1. Default is 0.
   * @param float|null $feedbackWeight The weight of user feedback in the calculation. If null, a default value is used.
   * @param float|null $sentimentScore The sentiment score, if available, normalized between -1 and 1. Default is null.
   *
   * @return float The calculated recommendation score for the product.
   */
  public static function calculateRecommendationScoreBasedOnRange(float $productsRateWeight = 0.8, float $reviewRate = 0, ?float $userFeedback = 0, ?float $feedbackWeight, ?float $sentimentScore = null): float
  {
    if (is_null($feedbackWeight)) {
      $feedbackWeight = 0.2;
    }

    // Adjust the sentiment score to be between -1 and 1 (if provided)
    if (!is_null($sentimentScore)) {
      $sentimentScore = max(-1, min(1, $sentimentScore));
    }

    // Normalize the user feedback to a value between -1 and 1
    $userFeedback = self::calculateUserFeedbackScore($userFeedback);

    // If the review rate is low (e.g., 1), give more weight to the sentiment score
    if ($reviewRate <= 0.2) {
      $feedbackWeight = 0.7; // You can adjust this weight to your preference
    }

    // Calculate base score with normalized weights
    $baseWeight = 1 - $feedbackWeight;
    $score = ($reviewRate * $baseWeight) + ($userFeedback * $feedbackWeight);

    // If a sentiment score is available, incorporate it with proper weight normalization
    if (!is_null($sentimentScore)) {
      $sentimentWeight = (float)CLICSHOPPING_APP_RECOMMENDATIONS_PR_WEIGHTING_SENTIMENT;
      
      // Normalize weights so they sum to 1.0
      $totalWeight = $baseWeight + $feedbackWeight + $sentimentWeight;
      if ($totalWeight > 0) {
        $score = ($score * ($baseWeight + $feedbackWeight) + ($sentimentScore * $sentimentWeight)) / $totalWeight;
      }
    }

    // Apply the products rate weight (from reviews) to the final score
    $score *= $productsRateWeight;

    return $score;
  }

  /**
   * Calculates the recommendation score for a given product based on various input factors
   * such as product rate weight, review rate, user feedback, strategy, and sentiment score.
   *
   * @param float|null $productsRateWeight The weight of the product rating in score calculation. Defaults to 0.8.
   * @param float|null $reviewRate The review rate of the product, adjusted to a scale of 0 to 1. Defaults to 0.
   * @param float|null $userFeedback The numeric feedback provided by the user, normalized between -1 and 1. Defaults to 0.
   * @param string|null $strategy The scoring strategy to be applied (e.g., 'Range' or other strategies). Defaults to 'Range'.
   * @param float|null $sentimentScore The sentiment score associated with product feedback, if available.
   *
   * @return float The calculated recommendation score based on the provided inputs and strategy.
   */
  public function calculateRecommendationScore(int $productsId, float $productsRateWeight =  0.8, float|null $reviewRate = 0, float|null $userFeedback = 0, string|null $strategy = 'Range', float|null $sentimentScore = null): float
  {
    // Normalize review rate consistently for both strategies
    $maxReviewRate = 5; // Maximum possible review rate
    $reviewRate = $reviewRate / $maxReviewRate;

    // Normalize the user feedback to a value between -1 and 1
    $userFeedback = self::calculateUserFeedbackScore($userFeedback);

    if ($strategy == 'Range') {
      return self::calculateRecommendationScoreBasedOnRange($productsRateWeight, $reviewRate, $userFeedback, null, $sentimentScore);
    } else {
      return self::calculateRecommendationScoreWithMultipleSources($productsId, $productsRateWeight, $reviewRate, $userFeedback, $sentimentScore);
    }
  }

  /**
   * Computes a normalized sales data score for a product based on products_ordered.
   * Returns a value in [0,1]. If max is 0, returns 0.
   */
  private static function getSalesDataScore(int $productsId): float
  {
    $db = Registry::get('Db');

    // Current product sales
    $Qcur = $db->prepare('select products_ordered as po from :table_products where products_id = :pid');
    $Qcur->bindInt(':pid', $productsId);
    $Qcur->execute();
    $current = (float)$Qcur->valueDecimal('po');

    // Max sales across catalog
    $Qmax = $db->prepare('select max(products_ordered) as max_po from :table_products');
    $Qmax->execute();
    $max = (float)$Qmax->valueDecimal('max_po');

    if ($max <= 0) {
      return 0.0;
    }

    $score = $current / $max; // normalize [0,1]
    return max(0.0, min(1.0, $score));
  }

  /**
   * Computes a normalized external recommendation score from the recommendations table.
   * Uses min/max thresholds if defined; otherwise normalizes by observed min/max.
   * Returns a value in [0,1].
   */
  private static function getExternalRecommendationScore(int $productsId): float
  {
    $db = Registry::get('Db');

    // Average score for this product
    $Qavg = $db->prepare('select avg(score) as avg_score from :table_products_recommendations where products_id = :pid');
    $Qavg->bindInt(':pid', $productsId);
    $Qavg->execute();
    $avg = (float)$Qavg->valueDecimal('avg_score');

    // Bounds for normalization
    $minCfg = \defined('CLICSHOPPING_APP_RECOMMENDATIONS_PR_MIN_SCORE') ? (float)CLICSHOPPING_APP_RECOMMENDATIONS_PR_MIN_SCORE : 0.0;
    $maxCfg = \defined('CLICSHOPPING_APP_RECOMMENDATIONS_PR_MAX_SCORE') ? (float)CLICSHOPPING_APP_RECOMMENDATIONS_PR_MAX_SCORE : 5.0;

    $min = $minCfg;
    $max = $maxCfg;

    if ($max <= $min) {
      return 0.0;
    }

    $normalized = ($avg - $min) / ($max - $min);
    return max(0.0, min(1.0, $normalized));
  }

  /**
   * Retrieves the most recommended products based on a defined group of customers, a minimum score,
   * and an optional analysis date range.
   *
   * @param int $limit The maximum number of products to retrieve (default is 10).
   * @param string|int $customers_group_id The ID of the customer group to filter the results.
   *                                        Use 99 for all groups, or a specific group ID.
   * @param string|null $date Optional analysis start date in 'Y-m-d' format. If null, retrieves results
   *                           without a date range.
   * @return array Returns an array of the most recommended products, each containing the product ID,
   *               recommendation count, recommendation date, and maximum score.
   */
  public function getMostRecommendedProducts(int $limit = 10, string|int $customers_group_id = 99, string|null $date): array
  {
    if ($customers_group_id == 99) {
      $customers_group = 'AND customers_group_id >= 0';
    } elseif ($customers_group_id > 0) {
      $customers_group = 'AND customers_group_id = ' . (int)$customers_group_id;
    } else {
      $customers_group = 'AND customers_group_id = 0';
    }

    $minRecommendedScore = (float)CLICSHOPPING_APP_RECOMMENDATIONS_PR_MIN_SCORE;

    $currentDate = date('Y-m-d');
    if (empty($date)) {
      $date_analyse = '';
    } else {
      $date_analyse = ' between ' . $date . ' AND ' . $currentDate;
    }

    $QmostRecommended = $this->db->prepare('SELECT products_id, 
                                                     COUNT(*) as recommendation_count,
                                                     recommendation_date,
                                                     MAX(score) as score
                                              FROM :table_products_recommendations
                                              WHERE score >= :minRecommendedScore
                                                    ' . $customers_group . '
                                                    ' . $date_analyse . '
                                              GROUP BY products_id
                                              ORDER BY recommendation_count DESC
                                              LIMIT :limit
                                             ');

    $QmostRecommended->bindInt(':limit', $limit);
    $QmostRecommended->bindDecimal(':minRecommendedScore', $minRecommendedScore);

    $QmostRecommended->execute();

    $mostRecommendedProducts = $QmostRecommended->fetchAll();

    return $mostRecommendedProducts;
  }

  /**
   * Retrieves a list of rejected products based on the given criteria.
   *
   * @param int $limit The maximum number of rejected products to retrieve. Defaults to 10.
   * @param string|int $customers_group_id The customer group ID to filter the products. Use 99 for no specific group filtering.
   * @param ?string $date The date range filter for retrieving rejected products. Defaults to null for no date filtering.
   * @return array The list of rejected products, including their IDs, rejection count, recommendation dates, and scores.
   */
  public function getRejectedProducts(int $limit = 10, int $customers_group_id = 99, string|null $date): array
  {
    if ($customers_group_id == 99) {
      $customers_group = '';
    } elseif ($customers_group_id > 0) {
      $customers_group = ' AND customers_group_id = ' . (int)$customers_group_id;
    } else {
      $customers_group = ' AND customers_group_id = 0';
    }

    $maxRejectedScore = (float)CLICSHOPPING_APP_RECOMMENDATIONS_PR_MAX_SCORE;

    $currentDate = date('Y-m-d');

    if (empty($date)) {
      $date_analyse = '';
    } else {
      $date_analyse = ' between ' . $date . ' AND ' . $currentDate;
    }

    $QrejectedProducts = $this->db->prepare('SELECT products_id, 
                                                      COUNT(*) as rejection_count,
                                                      recommendation_date as recommendation_date,
                                                      MAX(score) as score
                                              FROM :table_products_recommendations
                                              WHERE score < :maxRejectedScore
                                                    ' . $customers_group . '
                                                    ' . $date_analyse . '
                                              GROUP BY products_id
                                              ORDER BY rejection_count DESC
                                              LIMIT :limit
                                              ');

    $QrejectedProducts->bindInt(':limit', $limit);
    $QrejectedProducts->bindDecimal(':maxRejectedScore', $maxRejectedScore);
    $QrejectedProducts->execute();

    $rejectedProducts = $QrejectedProducts->fetchAll();

    return $rejectedProducts;
  }

  /**
   * Calculates a normalized user feedback score constrained between -1 and 1.
   *
   * @param float|null $userFeedback The feedback score provided by the user, which may fall outside the range of -1 to 1 or be null.
   * @return float The normalized feedback score, ensuring it is within the range of -1 and 1.
   */
  public static function calculateUserFeedbackScore(float|null $userFeedback): float
  {
    $normalizedFeedback = max(-1, min(1, $userFeedback));

    return $normalizedFeedback;
  }

//********************************************
// Review calculation
//********************************************
  /**
   * Calculates the average rating for a given product based on its reviews.
   *
   * @param int $products_id The ID of the product for which the average rating is to be calculated.
   * @return float The average rating of the product. Returns 0 if no reviews are found.
   */
  public function calculateProductsRateWeight(int $products_id): float
  {
    $Qcheck = $this->db->prepare('select avg(reviews_rating) as average
                                from :table_reviews
                                where products_id = :products_id
                              ');
    $Qcheck->bindInt(':products_id', $products_id);
    $Qcheck->execute();

    $review = $Qcheck->fetch();

    if (!$review || $review['average'] === null) {
      return 0;
    }

    $averageRating = $review['average'];

    return $averageRating;
  }
}