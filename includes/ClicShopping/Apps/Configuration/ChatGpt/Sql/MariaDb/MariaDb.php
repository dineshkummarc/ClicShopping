<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Configuration\ChatGpt\Sql\MariaDb;

use ClicShopping\OM\Cache;
use ClicShopping\OM\Registry;

class MariaDb
{
  /**
   * Executes the installation process for the ChatGpt module.
   *
   * @return void
   */
  public function execute()
  {
    $CLICSHOPPING_ChatGpt = Registry::get('ChatGpt');
    $CLICSHOPPING_ChatGpt->loadDefinitions('Sites/ClicShoppingAdmin/install');

    self::installDbMenuAdministration();
    self::installDb();
  }

  /**
   * Installs the ChatGPT administration menu entry in the database.
   *
   * This method checks if the ChatGPT entry already exists in the `administrator_menu` table.
   * If it does not exist, it creates a new entry with appropriate details, including menu ordering,
   * link, image, and associated application code. It also inserts the corresponding labels in the
   * `administrator_menu_description` table for each available language. After the operation, it clears
   * the administrator menu cache.
   *
   * @return void
   */
  private static function installDbMenuAdministration(): void
  {
    $CLICSHOPPING_Db = Registry::get('Db');
    $CLICSHOPPING_ChatGpt = Registry::get('ChatGpt');
    $CLICSHOPPING_Language = Registry::get('Language');

    $Qcheck = $CLICSHOPPING_Db->get('administrator_menu', 'app_code', ['app_code' => 'app_configuration_chatgpt']);

    if ($Qcheck->fetch() === false) {
      $sql_data_array = [
        'sort_order' => 100,
        'link' => 'index.php?A&Configuration\ChatGpt&ChatGpt&Configure',
        'image' => 'chatgpt.gif',
        'b2b_menu' => 0,
        'access' => 1,
        'app_code' => 'app_configuration_chatgpt'
      ];

      $insert_sql_data = ['parent_id' => 14];
      $sql_data_array = array_merge($sql_data_array, $insert_sql_data);

      $CLICSHOPPING_Db->save('administrator_menu', $sql_data_array);

      $id = $CLICSHOPPING_Db->lastInsertId();
      $languages = $CLICSHOPPING_Language->getLanguages();

      for ($i = 0, $n = \count($languages); $i < $n; $i++) {
        $language_id = $languages[$i]['id'];
        $sql_data_array = ['label' => $CLICSHOPPING_ChatGpt->getDef('title_menu')];

        $insert_sql_data = [
          'id' => (int)$id,
          'language_id' => (int)$language_id
        ];

        $sql_data_array = array_merge($sql_data_array, $insert_sql_data);

        $CLICSHOPPING_Db->save('administrator_menu_description', $sql_data_array);
      }

      Cache::clear('menu-administrator');
    }
  }

  /**
   * Installs the database tables required for the GPT functionality if they do not already exist.
   *
   * @return void
   */
  private static function installDb()
  {
    $CLICSHOPPING_Db = Registry::get('Db');

    $Qcheck = $CLICSHOPPING_Db->query('show tables like ":table_gpt"');

    if ($Qcheck->fetch() === false) {
      $sql = <<<EOD
          CREATE TABLE :table_gpt (
            gpt_id int(11) NOT NULL,
            question text NOT NULL,
            response text NOT NULL,
            date_added date DEFAULT NULL
          ) CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
          
          ALTER TABLE :table_gpt  ADD PRIMARY KEY (gpt_id);
          ALTER TABLE :table_gpt  MODIFY gpt_id int(11) NOT NULL AUTO_INCREMENT;
          
          CREATE TABLE :table_gpt_usage (
            usage_id int(11) NOT NULL,
            gpt_id int(11) NOT NULL,
            promptTokens int(11) DEFAULT NULL,
            completionTokens int(11) DEFAULT NULL,
            totalTokens int(11) DEFAULT NULL,
            ia_type varchar(255) DEFAULT NULL,
            model varchar(255) DEFAULT NULL,
            date_added date DEFAULT NULL
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
          ALTER TABLE :table_gpt_usage  ADD PRIMARY KEY (usage_id);
          ALTER TABLE :table_gpt_usage  MODIFY usage_id int(11) NOT NULL AUTO_INCREMENT;
          EOD;
      $CLICSHOPPING_Db->exec($sql);
    }


    $Qcheck = $CLICSHOPPING_Db->query('show tables like ":table_categories_embedding"');

    if ($Qcheck->fetch() === false) {
      $sql = <<<EOD
      CREATE TABLE IF NOT EXISTS clic_categories_embedding (
        id SERIAL PRIMARY KEY,
          content text DEFAULT NULL,
          type text DEFAULT NULL,
          sourcetype text default 'manual',
          sourcename text default 'manual',
          embedding vector(3072) NOT NULL,
          chunknumber int default 128,
          date_modified datetime DEFAULT NULL,
          entity_id INT,
          language_id INT
      );
      -- Add vector index separately
      CREATE VECTOR INDEX embedding_index ON clic_categories_embedding (embedding);
      
       CREATE TABLE IF NOT EXISTS clic_products_embedding (
              id SERIAL PRIMARY KEY,
                content text DEFAULT NULL,
                type text DEFAULT NULL,
                sourcetype text default 'manual',
                sourcename text default 'manual',
                embedding vector(3072) NOT NULL,
                chunknumber int default 128,
                date_modified datetime DEFAULT NULL,
                entity_id INT,
                language_id INT
              );
      -- Add vector index separately
      CREATE VECTOR INDEX embedding_index ON clic_products_embedding (embedding);
      
      CREATE TABLE IF NOT EXISTS clic_page_manager_embedding (
              id SERIAL PRIMARY KEY,
          content TEXT DEFAULT NULL,
          type TEXT DEFAULT NULL,
          sourcetype TEXT DEFAULT 'manual',
          sourcename TEXT DEFAULT 'manual',
          embedding VECTOR(3072) NOT NULL,
          chunknumber INT DEFAULT 128,
          date_modified DATETIME DEFAULT NULL,
          entity_id INT,
          language_id INT
      );
      
      -- Add vector index separately
      CREATE VECTOR INDEX embedding_index ON clic_page_manager_embedding (embedding);
      
      
      CREATE TABLE IF NOT EXISTS clic_manufacturers_embedding (
              id SERIAL PRIMARY KEY,
          content TEXT DEFAULT NULL,
          type TEXT DEFAULT NULL,
          sourcetype TEXT DEFAULT 'manual',
          sourcename TEXT DEFAULT 'manual',
          embedding VECTOR(3072) NOT NULL,
          chunknumber INT DEFAULT 128,
          date_modified DATETIME DEFAULT NULL,
          entity_id INT,
          language_id INT
      );
      
      -- Add vector index separately
      CREATE VECTOR INDEX embedding_index ON clic_manufacturers_embedding (embedding);
      
      
      CREATE TABLE IF NOT EXISTS clic_suppliers_embedding (
              id SERIAL PRIMARY KEY,
          content TEXT DEFAULT NULL,
          type TEXT DEFAULT NULL,
          sourcetype TEXT DEFAULT 'manual',
          sourcename TEXT DEFAULT 'manual',
          embedding VECTOR(3072) NOT NULL,
          chunknumber INT DEFAULT 128,
          date_modified DATETIME DEFAULT NULL,
          entity_id INT
      );
      
      -- Add vector index separately
      CREATE VECTOR INDEX embedding_index ON clic_suppliers_embedding (embedding);
      
      
      
      CREATE TABLE IF NOT EXISTS clic_reviews_embedding (
              id SERIAL PRIMARY KEY,
          content TEXT DEFAULT NULL,
          type TEXT DEFAULT NULL,
          sourcetype TEXT DEFAULT 'manual',
          sourcename TEXT DEFAULT 'manual',
          embedding VECTOR(3072) NOT NULL,
          chunknumber INT DEFAULT 128,
          date_modified DATETIME DEFAULT NULL,
          entity_id INT,
          language_id INT
      );
      
      -- Add vector index separately
      CREATE VECTOR INDEX embedding_index ON clic_reviews_embedding (embedding);
      
      
      CREATE TABLE IF NOT EXISTS clic_reviews_sentiment_embedding (
              id SERIAL PRIMARY KEY,
          content TEXT DEFAULT NULL,
          type TEXT DEFAULT NULL,
          sourcetype TEXT DEFAULT 'manual',
          sourcename TEXT DEFAULT 'manual',
          embedding VECTOR(3072) NOT NULL,
          chunknumber INT DEFAULT 128,
          date_modified DATETIME DEFAULT NULL,
          entity_id INT,
          language_id INT
      );
      
      -- Add vector index separately
      CREATE VECTOR INDEX embedding_index ON clic_reviews_sentiment_embedding (embedding);
      
      
      
      CREATE TABLE IF NOT EXISTS clic_return_orders_embedding (
              id SERIAL PRIMARY KEY,
          content TEXT DEFAULT NULL,
          type TEXT DEFAULT NULL,
          sourcetype TEXT DEFAULT 'manual',
          sourcename TEXT DEFAULT 'manual',
          embedding VECTOR(3072) NOT NULL,
          chunknumber INT DEFAULT 128,
          date_modified DATETIME DEFAULT NULL,
          entity_id INT
      );
      
      -- Add vector index separately
      CREATE VECTOR INDEX embedding_index ON clic_return_orders_embedding (embedding);
      
      
      
      CREATE TABLE IF NOT EXISTS clic_orders_embedding (
          id SERIAL PRIMARY KEY,
          content TEXT DEFAULT NULL,
          type TEXT DEFAULT NULL,
          sourcetype TEXT DEFAULT 'manual',
          sourcename TEXT DEFAULT 'manual',
          embedding VECTOR(3072) NOT NULL,
          chunknumber INT DEFAULT 128,
          date_modified DATETIME DEFAULT NULL,
          entity_id INT
      );
      
      -- Add vector index separately
      CREATE VECTOR INDEX embedding_index ON clic_orders_embedding (embedding);


    EOD;
      $CLICSHOPPING_Db->exec($sql);
    }
  }
}