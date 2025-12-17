<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin;

use ClicShopping\OM\Registry;

class Status
{
  public static function getWebSearchRagStatus(int $id, int $status)
  {
    $CLICSHOPPING_Db = Registry::get('Db');

    if ($status == 1) {
      return $CLICSHOPPING_Db->save('rag_websearch', ['status' => 1],  ['id' => (int)$id] );
    } elseif ($status == 0) {
      return $CLICSHOPPING_Db->save('rag_websearch', ['status' => 0], ['id' => (int)$id]);

    } else {
      return -1;
    }
  }
}