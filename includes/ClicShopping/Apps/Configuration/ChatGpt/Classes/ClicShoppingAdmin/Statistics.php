<?php

namespace ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin;

use ClicShopping\OM\Registry;

class Statistics {

  /**
   * Retrieves the total number of tokens (promptTokens, completionTokens, totalTokens) used in the last month.
   *
   * @return array An associative array containing promptTokens, completionTokens, totalTokens, and date_added.
   */
  public static function getTotalTokenByMonth(): array
  {
    $CLICSHOPPING_Db = Registry::get('Db');

    $Qtotal = $CLICSHOPPING_Db->prepare('select sum(promptTokens) as promptTokens,
                                                  sum(completionTokens) as completionTokens,
                                                  sum(totalTokens) as totalTokens,
                                                  date_added
                                           from :table_gpt_usage
                                           where DATE_SUB(NOW(), INTERVAL 1 MONTH)
                                          ');
    $Qtotal->execute();

    $result = $Qtotal->fetch();

    return $result;
  }


  /**
   * Saves the token usage statistics to the database.
   *
   * @param array|null $usage
   * @param string $engine The engine used for the response.
   * @return void
   */
  public static function saveStats(array|null $usage, string $engine): void
  {
    $CLICSHOPPING_Db = Registry::get('Db');

    $promptTokens = 0;
    $completionTokens = 0;
    $totalTokens = 0;

    $QlastId = $CLICSHOPPING_Db->prepare('select gpt_id
                                           from :table_gpt
                                           order by gpt_id desc
                                           limit 1
                                          ');
    $QlastId->execute();

    if (!is_null($usage)) {
      $promptTokens = $usage['prompt_tokens'];
      $completionTokens = $usage['completion_tokens'];
      $totalTokens = $usage['total_tokens'];
    }

    $array_usage_sql = [
      'gpt_id' => $QlastId->valueInt('gpt_id'),
      'promptTokens' => $promptTokens, // Accéder à la valeur de 'prompt_tokens'
      'completionTokens' => $completionTokens, // Accéder à la valeur de 'completion_tokens'
      'totalTokens' => $totalTokens, // Accéder à la valeur de 'total_tokens
      'ia_type' => 'GPT',
      'model' => $engine,
      'date_added' => 'now()'
    ];

    $CLICSHOPPING_Db->save('gpt_usage', $array_usage_sql);
  }

  /**
   *
   * Retrieves token usage data for a specified GPT ID.
   *
   * @param int $id The unique identifier of the GPT entry.
   * @return array An associative array containing token usage data: promptTokens, completionTokens, totalTokens, and the date added.
   */
  public static function getTokenbyId(int $id): array
  {
    $CLICSHOPPING_Db = Registry::get('Db');

    $Qtotal = $CLICSHOPPING_Db->prepare('select sum(promptTokens) as promptTokens,
                                                  sum(completionTokens) as completionTokens,
                                                  sum(totalTokens) as totalTokens,
                                                  date_added
                                           from :table_gpt_usage
                                           where gpt_id = :gpt_id
                                          ');
    $Qtotal->binInt(':gtp_id', $id);
    $Qtotal->execute();

    $result = $Qtotal->fetch();

    return $result;
  }
}