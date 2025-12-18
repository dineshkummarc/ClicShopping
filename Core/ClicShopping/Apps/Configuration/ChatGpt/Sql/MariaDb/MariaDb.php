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
            date_added date DEFAULT NULL,
            KEY idx_date_added (date_added)
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
            date_added date DEFAULT NULL,
            KEY idx_gpt_id (gpt_id),
            KEY idx_date_added (date_added),
            KEY idx_model (model),
            KEY idx_ia_type (ia_type)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
          ALTER TABLE :table_gpt_usage  ADD PRIMARY KEY (usage_id);
          ALTER TABLE :table_gpt_usage  MODIFY usage_id int(11) NOT NULL AUTO_INCREMENT;
          EOD;
      $CLICSHOPPING_Db->exec($sql);
    }

    $Qcheck = $CLICSHOPPING_Db->query('show tables like ":table_categories_embedding"');

    if ($Qcheck->fetch() === false) {
      $sql = <<<EOD
      CREATE TABLE IF NOT EXISTS :table_categories_embedding (
        id SERIAL PRIMARY KEY,
          content longtext DEFAULT NULL,
          type text DEFAULT NULL,
          sourcetype text default 'manual',
          sourcename text default 'manual',
          embedding vector(3072) NOT NULL,
          chunknumber int default 128,
          date_modified datetime DEFAULT NULL,
          entity_id INT,
          language_id INT,
          KEY idx_entity_id (entity_id),
          KEY idx_language_id (language_id),
          KEY idx_entity_lang (entity_id, language_id),
          KEY idx_date_modified (date_modified)
      );

      CREATE VECTOR INDEX embedding_index ON :table_categories_embedding (embedding);

       CREATE TABLE IF NOT EXISTS :table_products_embedding (
              id SERIAL PRIMARY KEY,
                content longtext DEFAULT NULL,
                type text DEFAULT NULL,
                sourcetype text default 'manual',
                sourcename text default 'manual',
                embedding vector(3072) NOT NULL,
                chunknumber int default 128,
                date_modified datetime DEFAULT NULL,
                entity_id INT,
                language_id INT,
                KEY idx_entity_id (entity_id),
                KEY idx_language_id (language_id),
                KEY idx_entity_lang (entity_id, language_id),
                KEY idx_date_modified (date_modified)
              );

      CREATE VECTOR INDEX embedding_index ON :table_products_embedding (embedding);
      
      CREATE TABLE IF NOT EXISTS :table_pages_manager_embedding (
              id SERIAL PRIMARY KEY,
          content longtext DEFAULT NULL,
          type TEXT DEFAULT NULL,
          sourcetype TEXT DEFAULT 'manual',
          sourcename TEXT DEFAULT 'manual',
          embedding VECTOR(3072) NOT NULL,
          chunknumber INT DEFAULT 128,
          date_modified DATETIME DEFAULT NULL,
          entity_id INT,
          language_id INT,
          KEY idx_entity_id (entity_id),
          KEY idx_language_id (language_id),
          KEY idx_entity_lang (entity_id, language_id),
          KEY idx_date_modified (date_modified)
      );

      CREATE VECTOR INDEX embedding_index ON :table_pages_manager_embedding (embedding);

      CREATE TABLE IF NOT EXISTS :table_manufacturers_embedding (
              id SERIAL PRIMARY KEY,
          content longtext DEFAULT NULL,
          type TEXT DEFAULT NULL,
          sourcetype TEXT DEFAULT 'manual',
          sourcename TEXT DEFAULT 'manual',
          embedding VECTOR(3072) NOT NULL,
          chunknumber INT DEFAULT 128,
          date_modified DATETIME DEFAULT NULL,
          entity_id INT,
          language_id INT,
          KEY idx_entity_id (entity_id),
          KEY idx_language_id (language_id),
          KEY idx_entity_lang (entity_id, language_id),
          KEY idx_date_modified (date_modified)
      );

      CREATE VECTOR INDEX embedding_index ON :table_manufacturers_embedding (embedding);

      CREATE TABLE IF NOT EXISTS :table_suppliers_embedding (
              id SERIAL PRIMARY KEY,
          content longtext DEFAULT NULL,
          type TEXT DEFAULT NULL,
          sourcetype TEXT DEFAULT 'manual',
          sourcename TEXT DEFAULT 'manual',
          embedding VECTOR(3072) NOT NULL,
          chunknumber INT DEFAULT 128,
          date_modified DATETIME DEFAULT NULL,
          entity_id INT,
          KEY idx_entity_id (entity_id),
          KEY idx_date_modified (date_modified)
      );

      CREATE VECTOR INDEX embedding_index ON :table_suppliers_embedding (embedding);

      CREATE TABLE IF NOT EXISTS :table_reviews_embedding (
              id SERIAL PRIMARY KEY,
          content longtext DEFAULT NULL,
          type TEXT DEFAULT NULL,
          sourcetype TEXT DEFAULT 'manual',
          sourcename TEXT DEFAULT 'manual',
          embedding VECTOR(3072) NOT NULL,
          chunknumber INT DEFAULT 128,
          date_modified DATETIME DEFAULT NULL,
          entity_id INT,
          language_id INT,
          KEY idx_entity_id (entity_id),
          KEY idx_language_id (language_id),
          KEY idx_entity_lang (entity_id, language_id),
          KEY idx_date_modified (date_modified)
      );

      CREATE VECTOR INDEX embedding_index ON :table_reviews_embedding (embedding);

      CREATE TABLE IF NOT EXISTS :table_reviews_sentiment_embedding (
              id SERIAL PRIMARY KEY,
          content longtext DEFAULT NULL,
          type TEXT DEFAULT NULL,
          sourcetype TEXT DEFAULT 'manual',
          sourcename TEXT DEFAULT 'manual',
          embedding VECTOR(3072) NOT NULL,
          chunknumber INT DEFAULT 128,
          date_modified DATETIME DEFAULT NULL,
          entity_id INT,
          language_id INT,
          KEY idx_entity_id (entity_id),
          KEY idx_language_id (language_id),
          KEY idx_entity_lang (entity_id, language_id),
          KEY idx_date_modified (date_modified)
      );

      CREATE VECTOR INDEX embedding_index ON :table_reviews_sentiment_embedding (embedding);

      CREATE TABLE IF NOT EXISTS :table_return_orders_embedding (
              id SERIAL PRIMARY KEY,
          content longtext DEFAULT NULL,
          type TEXT DEFAULT NULL,
          sourcetype TEXT DEFAULT 'manual',
          sourcename TEXT DEFAULT 'manual',
          embedding VECTOR(3072) NOT NULL,
          chunknumber INT DEFAULT 128,
          date_modified DATETIME DEFAULT NULL,
          entity_id INT,
          KEY idx_entity_id (entity_id),
          KEY idx_date_modified (date_modified)
      );

      CREATE VECTOR INDEX embedding_index ON :table_return_orders_embedding (embedding);

      CREATE TABLE IF NOT EXISTS :table_orders_embedding (
          id SERIAL PRIMARY KEY,
          content longtext DEFAULT NULL,
          type TEXT DEFAULT NULL,
          sourcetype TEXT DEFAULT 'manual',
          sourcename TEXT DEFAULT 'manual',
          embedding VECTOR(3072) NOT NULL,
          chunknumber INT DEFAULT 128,
          date_modified DATETIME DEFAULT NULL,
          entity_id INT,
          KEY idx_entity_id (entity_id),
          KEY idx_date_modified (date_modified)
      );

      CREATE VECTOR INDEX embedding_index ON :table_orders_embedding (embedding);    
    EOD;
      $CLICSHOPPING_Db->exec($sql);
    }

//--------------------------------------
// RAG
//--------------------------------------

    $Qcheck = $CLICSHOPPING_Db->query('show tables like ":table_rag_correction_patterns_embedding"');

    if ($Qcheck->fetch() === false) {
      $sql = <<<EOD
     CREATE TABLE :table_rag_correction_patterns_embedding (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        content text DEFAULT NULL,
        type text DEFAULT NULL,
        sourcetype text DEFAULT NULL,
        sourcename text DEFAULT NULL,
        embedding vector(3072) NULL COMMENT 'Embedding vector (NULL if not yet generated)',
        chunknumber int(11) DEFAULT 128,
        date_modified datetime DEFAULT NULL,
        entity_id int(11) NULL DEFAULT 0 COMMENT 'Entity ID (0 = no specific entity, NULL = unknown)',
        entity_type VARCHAR(50) NULL COMMENT 'Type of entity (product, category, page, etc.)',
        language_id int(11) NOT NULL,
        metadata JSON NOT NULL DEFAULT '{}',
        VECTOR KEY (embedding),
        PRIMARY KEY (id),
        UNIQUE KEY id (id),
        KEY idx_entity_id (entity_id),
        KEY idx_language_id (language_id),
        KEY idx_entity (entity_id, entity_type),
        KEY idx_entity_language (entity_id, language_id),
        KEY idx_entity_type_language (entity_type, language_id, entity_id),
        KEY idx_date_modified (date_modified)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

      ALTER TABLE :table_rag_correction_patterns_embedding ADD COLUMN IF NOT EXISTS metadata JSON NOT NULL DEFAULT '{}' AFTER embedding;
      ALTER TABLE :table_rag_correction_patterns_embedding ADD COLUMN IF NOT EXISTS entity_type VARCHAR(50) NULL COMMENT 'Type of entity (product, category, page, etc.)' AFTER entity_id;
      ALTER TABLE :table_rag_correction_patterns_embedding ADD KEY IF NOT EXISTS idx_user_id (metadata(100));
      ALTER TABLE :table_rag_correction_patterns_embedding ADD KEY IF NOT EXISTS idx_entity (entity_id, entity_type);
      ALTER TABLE :table_rag_correction_patterns_embedding ADD KEY IF NOT EXISTS idx_entity_type_language (entity_type, language_id, entity_id);
      ALTER TABLE :table_rag_correction_patterns_embedding ADD KEY IF NOT EXISTS idx_date_modified (date_modified);
     EOD;

      $CLICSHOPPING_Db->exec($sql);
    }


    $Qcheck = $CLICSHOPPING_Db->query('show tables like ":table_rag_memory_retention_log"');

    if ($Qcheck->fetch() === false) {
      $sql = <<<EOD
        CREATE TABLE IF NOT EXISTS :table_rag_memory_retention_log (
          id BIGINT AUTO_INCREMENT PRIMARY KEY,
          user_id VARCHAR(255) NOT NULL,
          interaction_id VARCHAR(255) NOT NULL UNIQUE,
          timestamp_recorded TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          timestamp_migrated TIMESTAMP NULL,
          level ENUM('working', 'short_term', 'long_term') DEFAULT 'short_term',
          status ENUM('pending', 'short_term_stored', 'long_term_stored', 'archived') DEFAULT 'pending',
          KEY idx_user_id (user_id),
          KEY idx_status (status),
          KEY idx_status_level (status, level),
          KEY idx_user_status (user_id, status),
          KEY idx_timestamp_recorded (timestamp_recorded)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        EOD;
      $CLICSHOPPING_Db->exec($sql);
    }

    $Qcheck = $CLICSHOPPING_Db->query('show tables like ":table_rag_conversation_memory_embedding"');


    if ($Qcheck->fetch() === false) {
      $sql = <<<EOD
        CREATE TABLE :table_rag_conversation_memory_embedding (
          id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
          content text DEFAULT NULL,
          type text DEFAULT NULL,
          sourcetype text DEFAULT NULL,
          sourcename text DEFAULT NULL,
          embedding vector(3072) NULL COMMENT 'Embedding vector (NULL if not yet generated)',
          chunknumber int(11) DEFAULT 128,
          date_modified datetime DEFAULT NULL,
          entity_id int(11) NOT NULL,
          entity_type VARCHAR(50) DEFAULT NULL COMMENT 'Type of entity (product, category, page, etc.)',
          language_id int(11) NOT NULL,
          user_id VARCHAR(255) DEFAULT NULL COMMENT 'User ID for fast filtering',
          interaction_id VARCHAR(255) DEFAULT NULL COMMENT 'Interaction ID to prevent duplicates',
          metadata JSON NOT NULL DEFAULT '{}',
          VECTOR KEY (embedding),
          PRIMARY KEY (id),
          UNIQUE KEY id (id),
          KEY idx_user_id (user_id),
          KEY idx_interaction_id (interaction_id),
          KEY idx_language_id (language_id),
          KEY idx_user_lang_date (user_id, language_id, date_modified),
          KEY idx_date_modified (date_modified),
          KEY idx_entity (entity_id, entity_type),
          KEY idx_interaction_user (interaction_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
       
      ALTER TABLE :table_rag_conversation_memory_embedding ADD COLUMN IF NOT EXISTS metadata JSON NOT NULL DEFAULT '{}' AFTER embedding;
      ALTER TABLE :table_rag_conversation_memory_embedding ADD COLUMN IF NOT EXISTS entity_type VARCHAR(50) DEFAULT NULL COMMENT 'Type of entity (product, category, page, etc.)' AFTER entity_id;
      ALTER TABLE :table_rag_conversation_memory_embedding ADD COLUMN IF NOT EXISTS user_id VARCHAR(255) DEFAULT NULL COMMENT 'User ID for fast filtering' AFTER language_id;
      ALTER TABLE :table_rag_conversation_memory_embedding ADD COLUMN IF NOT EXISTS interaction_id VARCHAR(255) DEFAULT NULL COMMENT 'Interaction ID to prevent duplicates' AFTER user_id;
      ALTER TABLE :table_rag_conversation_memory_embedding ADD KEY IF NOT EXISTS idx_user_id (user_id);
      ALTER TABLE :table_rag_conversation_memory_embedding ADD KEY IF NOT EXISTS idx_interaction_id (interaction_id);
      ALTER TABLE :table_rag_conversation_memory_embedding ADD KEY IF NOT EXISTS idx_language_id (language_id);
      ALTER TABLE :table_rag_conversation_memory_embedding ADD KEY IF NOT EXISTS idx_user_lang_date (user_id, language_id, date_modified);
      ALTER TABLE :table_rag_conversation_memory_embedding ADD KEY IF NOT EXISTS idx_entity (entity_id, entity_type);
      ALTER TABLE :table_rag_conversation_memory_embedding ADD KEY IF NOT EXISTS idx_interaction_user (interaction_id, user_id);
      
      -- Task 0.1 (2025-12-12): Add user_message and assistant_response columns
      ALTER TABLE :table_rag_conversation_memory_embedding ADD COLUMN IF NOT EXISTS user_message TEXT COMMENT 'User message from conversation' AFTER embedding;
      ALTER TABLE :table_rag_conversation_memory_embedding ADD COLUMN IF NOT EXISTS assistant_response TEXT COMMENT 'Assistant response from conversation' AFTER user_message;
      ALTER TABLE :table_rag_conversation_memory_embedding ADD COLUMN IF NOT EXISTS created_at DATETIME DEFAULT NULL COMMENT 'Creation timestamp' AFTER metadata;
      ALTER TABLE :table_rag_conversation_memory_embedding ADD KEY IF NOT EXISTS idx_created_at (created_at);
      
      -- Task 2.2 Pre-fix (2025-12-12): Make embedding column nullable
      -- Embeddings are expensive to generate, allow NULL for conversations without immediate embedding
      ALTER TABLE :table_rag_conversation_memory_embedding MODIFY COLUMN embedding VECTOR(3072) NULL COMMENT 'Embedding vector (NULL if not yet generated)';
      
      -- Task 3.1 (2025-12-12): Make entity_id nullable to allow conversation memory without entity context
      -- Issue: Field 'entity_id' doesn't have a default value - causing INSERT failures
      -- Impact: Cannot save conversation history without entity_id
      ALTER TABLE :table_rag_conversation_memory_embedding MODIFY COLUMN entity_id INT(11) NULL DEFAULT NULL COMMENT 'Entity ID (nullable for general conversations)';
      ALTER TABLE :table_rag_conversation_memory_embedding MODIFY COLUMN entity_type VARCHAR(50) NULL DEFAULT NULL COMMENT 'Entity type (nullable for general conversations)';
      
      -- Also update rag_conversation_memory table if it exists (Task 2.16.2)
      ALTER TABLE :table_rag_conversation_memory_embedding ADD COLUMN IF NOT EXISTS entity_type VARCHAR(50) DEFAULT NULL COMMENT 'Type of entity (product, category, page, etc.)' AFTER entity_id;
      ALTER TABLE :table_rag_conversation_memory_embedding ADD KEY IF NOT EXISTS idx_entity (entity_id, entity_type);
    EOD;
      $CLICSHOPPING_Db->exec($sql);
    }

    $Qcheck = $CLICSHOPPING_Db->query('show tables like ":table_rag_memory_retention_log"');

    if ($Qcheck->fetch() === false) {
      $sql = <<<EOD
      CREATE TABLE IF NOT EXISTS :table_rag_memory_retention_log (
          id BIGINT AUTO_INCREMENT PRIMARY KEY,
          user_id VARCHAR(255) NOT NULL,
          interaction_id VARCHAR(255) NOT NULL UNIQUE,
          timestamp_recorded TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          timestamp_migrated TIMESTAMP NULL,
          level ENUM('working', 'short_term', 'long_term') DEFAULT 'short_term',
          status ENUM('pending', 'short_term_stored', 'long_term_stored', 'archived') DEFAULT 'pending',
          KEY idx_user_id (user_id),
          KEY idx_status (status),
          KEY idx_status_level (status, level),
          KEY idx_user_status (user_id, status),
          KEY idx_timestamp_recorded (timestamp_recorded)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    EOD;
      $CLICSHOPPING_Db->exec($sql);
    }





    $Qcheck = $CLICSHOPPING_Db->query('show tables like ":table_rag_calculator_cache"');

    if ($Qcheck->fetch() === false) {
      $sql = <<<EOD
        CREATE TABLE :table_rag_calculator_cache (
          cache_id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
          expression VARCHAR(500) NOT NULL,
          expression_hash VARCHAR(64) NOT NULL,
          result DOUBLE NOT NULL,
          result_type VARCHAR(50) NOT NULL,
          variables JSON DEFAULT NULL,
          execution_time FLOAT NOT NULL,
          created_at DATETIME NOT NULL,
          last_accessed DATETIME NOT NULL,
          access_count INT(11) UNSIGNED DEFAULT 0,
          PRIMARY KEY (cache_id),
          UNIQUE KEY idx_expression_hash (expression_hash),
          KEY idx_created_at (created_at),
          KEY idx_last_accessed (last_accessed)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS  :table_rag_calculator_logs (
          log_id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
          user_id VARCHAR(100) NOT NULL,
          expression TEXT NOT NULL,
          result DOUBLE DEFAULT NULL,
          success TINYINT(1) NOT NULL DEFAULT 0,
          error_message TEXT DEFAULT NULL,
          execution_time FLOAT NOT NULL,
          step_id VARCHAR(100) DEFAULT NULL,
          plan_id VARCHAR(100) DEFAULT NULL,
          metadata JSON DEFAULT NULL,
          created_at DATETIME NOT NULL,
          PRIMARY KEY (log_id),
          KEY idx_user_id (user_id),
          KEY idx_created_at (created_at),
          KEY idx_success (success),
          KEY idx_plan_id (plan_id),
          KEY idx_user_success_date (user_id, success, created_at),
          KEY idx_plan_step (plan_id, step_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    EOD;
      $CLICSHOPPING_Db->exec($sql);
    }

    $Qcheck = $CLICSHOPPING_Db->query('show tables like ":table_rag_web_search_requests"');

    if ($Qcheck->fetch() === false) {
      $sql = <<<EOD
        CREATE TABLE IF NOT EXISTS :table_rag_web_search_requests (
          `id` INT(11) NOT NULL AUTO_INCREMENT,
          `query` TEXT NOT NULL COMMENT 'Requête originale de l utilisateur',
          `translated_query` TEXT NULL COMMENT 'Requête traduite en anglais',
          `intent` ENUM('INTERNAL_RAG', 'EXTERNAL_WEB', 'ANALYTICS', 'MIXED') NOT NULL DEFAULT 'EXTERNAL_WEB' COMMENT 'Intention classifiée',
          `intent_confidence` FLOAT DEFAULT 0.0 COMMENT 'Confiance de la classification (0-1)',
          `search_engine` VARCHAR(50) DEFAULT 'serpapi' COMMENT 'Moteur utilisé (serpapi, bing, google)',
          `results_count` INT DEFAULT 0 COMMENT 'Nombre de résultats retournés',
          `response_summary` TEXT NULL COMMENT 'Synthèse générée par le LLM',
          `quality_score` FLOAT DEFAULT 0.0 COMMENT 'Score de qualité des résultats (0-1)',
          `cached` TINYINT(1) DEFAULT 0 COMMENT '1 si résultat provient du cache',
          `cached_in_rag` TINYINT(1) DEFAULT 0 COMMENT '1 si stocké dans rag_web_cache_embeddingpour apprentissage',
          `execution_time` FLOAT DEFAULT 0.0 COMMENT 'Temps d exécution en secondes',
          `api_cost` FLOAT DEFAULT 0.0 COMMENT 'Coût API estimé en USD',
          `user_admin` VARCHAR(255) NULL COMMENT 'Utilisateur ayant effectué la recherche',
          `session_id` VARCHAR(255) NULL COMMENT 'ID de session',
          `ip_address` VARCHAR(45) NULL COMMENT 'Adresse IP (IPv4 ou IPv6)',
          `audit_data` JSON NULL COMMENT 'Données d audit complètes (JSON)',
          `date_added` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'Date de création',
          
          PRIMARY KEY (`id`),
          INDEX `idx_query` (`query`(255)),
          INDEX `idx_intent` (`intent`),
          INDEX `idx_cached_in_rag` (`cached_in_rag`),
          INDEX `idx_date_added` (`date_added`),
          INDEX `idx_user_admin` (`user_admin`),
          INDEX `idx_quality_score` (`quality_score`),
          INDEX `idx_intent_confidence` (`intent`, `intent_confidence`),
          INDEX `idx_cached_quality` (`cached_in_rag`, `quality_score`),
          INDEX `idx_date_user` (`date_added`, `user_admin`),
          FULLTEXT INDEX `ft_query` (`query`)       
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    EOD;
      $CLICSHOPPING_Db->exec($sql);
    }

    $Qcheck = $CLICSHOPPING_Db->query('show tables like ":table_web_search_results"');

    if ($Qcheck->fetch() === false) {
      $sql = <<<EOD
        CREATE TABLE IF NOT EXISTS :table_rag_web_search_results (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `search_request_id` INT(11) NOT NULL COMMENT 'Lien vers rag_web_search_requests (géré par le code)',
        `position` INT DEFAULT 0 COMMENT 'Position dans les résultats (1, 2, 3...)',
        `title` TEXT NULL COMMENT 'Titre du résultat',
        `link` TEXT NULL COMMENT 'URL du résultat',
        `snippet` TEXT NULL COMMENT 'Extrait de texte descriptif',
        `source_domain` VARCHAR(255) NULL COMMENT 'Domaine source (google.com, wikipedia.org, etc.)',
        `relevance_score` FLOAT DEFAULT 0.0 COMMENT 'Score de pertinence calculé (0-1)',
        `date_added` DATETIME DEFAULT CURRENT_TIMESTAMP,

        PRIMARY KEY (`id`),
        INDEX `idx_search_request_id` (`search_request_id`),
        INDEX `idx_relevance_score` (`relevance_score`),
        INDEX `idx_source_domain` (`source_domain`),
        INDEX `idx_request_relevance` (`search_request_id`, `relevance_score`),
        INDEX `idx_domain_relevance` (`source_domain`, `relevance_score`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    EOD;
      $CLICSHOPPING_Db->exec($sql);
    }

    $Qcheck = $CLICSHOPPING_Db->query('show tables like ":table_rag_web_search_requests"');

    if ($Qcheck->fetch() === false) {
      $sql = <<<EOD
        CREATE TABLE IF NOT EXISTS :table_rag_web_cache_embedding(
          `id` INT(11) NOT NULL AUTO_INCREMENT,
          `content` LONGTEXT NOT NULL COMMENT 'Contenu complet (query + synthèse + sources)',
          `type` VARCHAR(50) DEFAULT 'web_search_cache' COMMENT 'Type de document',
          `sourcetype` VARCHAR(50) DEFAULT 'web_search' COMMENT 'Source du document',
          `sourcename` VARCHAR(255) DEFAULT 'serpapi' COMMENT 'Nom de la source (serpapi, bing, etc.)',
          `embedding` VECTOR(3072) NOT NULL COMMENT 'Vecteur d embedding (adapter selon modèle)',
          `original_query` TEXT NULL COMMENT 'Requête originale ayant généré ce résultat',
          `search_engine` VARCHAR(50) NULL COMMENT 'Moteur de recherche utilisé',
          `quality_score` FLOAT DEFAULT 0.0 COMMENT 'Score de qualité du résultat (0-1)',
          `usage_count` INT DEFAULT 0 COMMENT 'Nombre de fois que ce résultat a été réutilisé',
          `last_used` DATETIME NULL COMMENT 'Dernière date d utilisation',
          `entity_id` INT(11) NULL COMMENT 'ID de la requête source (optionnel)',
          `language_id` INT DEFAULT 1 COMMENT 'ID de la langue',
          `chunknumber` INT DEFAULT 128 COMMENT 'Taille du chunk utilisé',
          `date_modified` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

          PRIMARY KEY (`id`),
          INDEX `idx_type` (`type`),
          INDEX `idx_sourcetype` (`sourcetype`),
          INDEX `idx_quality_score` (`quality_score`),
          INDEX `idx_usage_count` (`usage_count`),
          INDEX `idx_last_used` (`last_used`),
          INDEX `idx_quality_usage` (`quality_score`, `usage_count`),
          INDEX `idx_quality_usage_last` (`quality_score`, `usage_count`, `last_used`),
          INDEX `idx_search_engine_quality` (`search_engine`, `quality_score`),
          INDEX `idx_language_quality` (`language_id`, `quality_score`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    EOD;
      $CLICSHOPPING_Db->exec($sql);
    }


    $Qcheck = $CLICSHOPPING_Db->query('show tables like ":table_web_search_results"');

    if ($Qcheck->fetch() === false) {
      $sql = <<<EOD
        CREATE TABLE IF NOT EXISTS :table_web_search_results (
      `id` INT(11) NOT NULL AUTO_INCREMENT,
      `search_request_id` INT(11) NOT NULL COMMENT 'Lien vers rag_web_search_requests (géré par le code)',
      `position` INT DEFAULT 0 COMMENT 'Position dans les résultats (1, 2, 3...)',
      `title` TEXT NULL COMMENT 'Titre du résultat',
      `link` TEXT NULL COMMENT 'URL du résultat',
      `snippet` TEXT NULL COMMENT 'Extrait de texte descriptif',
      `source_domain` VARCHAR(255) NULL COMMENT 'Domaine source (google.com, wikipedia.org, etc.)',
      `relevance_score` FLOAT DEFAULT 0.0 COMMENT 'Score de pertinence calculé (0-1)',
      `date_added` DATETIME DEFAULT CURRENT_TIMESTAMP,

      PRIMARY KEY (`id`),
      INDEX `idx_search_request_id` (`search_request_id`),
      INDEX `idx_relevance_score` (`relevance_score`),
      INDEX `idx_source_domain` (`source_domain`),
      INDEX `idx_request_relevance` (`search_request_id`, `relevance_score`),
      INDEX `idx_domain_relevance` (`source_domain`, `relevance_score`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    EOD;
      $CLICSHOPPING_Db->exec($sql);
    }

    $Qcheck = $CLICSHOPPING_Db->query('show tables like ":rag_web_cache_embedding"');

    if ($Qcheck->fetch() === false) {
      $sql = <<<EOD

    CREATE TABLE IF NOT EXISTS :table_rag_web_cache_embedding(
      `id` INT(11) NOT NULL AUTO_INCREMENT,
      `content` LONGTEXT NOT NULL COMMENT 'Contenu complet (query + synthèse + sources)',
      `type` VARCHAR(50) DEFAULT 'web_search_cache' COMMENT 'Type de document',
      `sourcetype` VARCHAR(50) DEFAULT 'web_search' COMMENT 'Source du document',
      `sourcename` VARCHAR(255) DEFAULT 'serpapi' COMMENT 'Nom de la source (serpapi, bing, etc.)',
      `embedding` VECTOR(3072) NOT NULL COMMENT 'Vecteur d embedding (adapter selon modèle)',
      `original_query` TEXT NULL COMMENT 'Requête originale ayant généré ce résultat',
      `search_engine` VARCHAR(50) NULL COMMENT 'Moteur de recherche utilisé',
      `quality_score` FLOAT DEFAULT 0.0 COMMENT 'Score de qualité du résultat (0-1)',
      `usage_count` INT DEFAULT 0 COMMENT 'Nombre de fois que ce résultat a été réutilisé',
      `last_used` DATETIME NULL COMMENT 'Dernière date d utilisation',
      `entity_id` INT(11) NULL COMMENT 'ID de la requête source (optionnel)',
      `language_id` INT DEFAULT 1 COMMENT 'ID de la langue',
      `chunknumber` INT DEFAULT 128 COMMENT 'Taille du chunk utilisé',
      `date_modified` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      `metadata` JSON DEFAULT NULL,
      PRIMARY KEY (`id`),
      INDEX `idx_type` (`type`),
      INDEX `idx_sourcetype` (`sourcetype`),
      INDEX `idx_quality_score` (`quality_score`),
      INDEX `idx_usage_count` (`usage_count`),
      INDEX `idx_last_used` (`last_used`),
      INDEX `idx_quality_usage` (`quality_score`, `usage_count`),
      INDEX `idx_quality_usage_last` (`quality_score`, `usage_count`, `last_used`),
      INDEX `idx_search_engine_quality` (`search_engine`, `quality_score`),
      INDEX `idx_language_quality` (`language_id`, `quality_score`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci

    EOD;
      $CLICSHOPPING_Db->exec($sql);
    }

    $Qcheck = $CLICSHOPPING_Db->query('show tables like ":table_rag_websearch"');

    if ($Qcheck->fetch() === false) {
      $sql = <<<EOD
      CREATE TABLE :table_rag_websearch (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `site_domain` varchar(255) NOT NULL,
      `authority_score` float(15,4) NOT NULL,
      `status` tinyint(1) NOT NULL,
      `description` text NOT NULL,
      `search_pattern` varchar(255),
      PRIMARY KEY (`id`),
      INDEX `idx_site_domain` (`site_domain`),
      INDEX `idx_status_authority` (`status`, `authority_score`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    EOD;
      $CLICSHOPPING_Db->exec($sql);
    }

    $Qcheck = $CLICSHOPPING_Db->query('show tables like ":table_rag_feedback"');

    if ($Qcheck->fetch() === false) {
      $sql = <<<EOD
      CREATE TABLE IF NOT EXISTS :table_rag_feedback (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `interaction_id` VARCHAR(255) NOT NULL COMMENT 'ID unique de l interaction',
        `feedback_type` ENUM('positive', 'negative', 'correction') NOT NULL COMMENT 'Type de feedback',
        `feedback_data` JSON NULL COMMENT 'Données additionnelles du feedback (correction, commentaire, rating)',
        `user_id` VARCHAR(100) NOT NULL COMMENT 'ID de l utilisateur',
        `timestamp` INT(11) NOT NULL COMMENT 'Timestamp Unix',
        `language_id` INT(11) NOT NULL COMMENT 'ID de la langue',
        `date_added` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'Date de création',
        
        PRIMARY KEY (`id`),
        INDEX `idx_interaction` (`interaction_id`),
        INDEX `idx_feedback_type` (`feedback_type`),
        INDEX `idx_timestamp` (`timestamp`),
        INDEX `idx_user_id` (`user_id`),
        INDEX `idx_user_type_date` (`user_id`, `feedback_type`, `date_added`),
        INDEX `idx_interaction_type` (`interaction_id`, `feedback_type`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    EOD;
      $CLICSHOPPING_Db->exec($sql);
    }


    $Qcheck = $CLICSHOPPING_Db->query('show tables like ":table_rag_interactions"');

    if ($Qcheck->fetch() === false) {
      $sql = <<<EOD
        CREATE TABLE IF NOT EXISTS :table_rag_interactions (
          interaction_id INT NOT NULL AUTO_INCREMENT,
          user_id INT DEFAULT NULL,
          session_id VARCHAR(255) DEFAULT NULL,
          question TEXT NOT NULL,
          response TEXT DEFAULT NULL,
          request_type VARCHAR(50) DEFAULT NULL COMMENT 'analytics, semantic, error, security_blocked',
          confidence DECIMAL(5,2) DEFAULT NULL,
          response_quality INT DEFAULT NULL COMMENT 'Score 0-100',
          response_time INT DEFAULT NULL COMMENT 'Temps de réponse en ms',
          execution_time INT DEFAULT NULL COMMENT 'Execution time in milliseconds',
          tokens_used INT DEFAULT NULL,
          api_cost DECIMAL(10,6) DEFAULT NULL,
          language_id INT DEFAULT 1,
          entity_id INT DEFAULT 0 COMMENT 'Entity ID (0 = no specific entity, NULL = unknown)',
          entity_type VARCHAR(50) DEFAULT NULL COMMENT 'Type of entity (product, category, page, etc.)',
          agent_used VARCHAR(50) DEFAULT NULL COMMENT 'Agent that processed the query (orchestrator, analytics_agent, semantic_agent, etc.)',
          intent_type VARCHAR(50) DEFAULT NULL COMMENT 'Intent classification (analytics, semantic, hybrid, web_search)',
          date_added DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          
          PRIMARY KEY (interaction_id),
          KEY idx_user_id (user_id),
          KEY idx_session_id (session_id),
          KEY idx_request_type (request_type),
          KEY idx_date_added (date_added),
          KEY idx_response_time (response_time),
          KEY idx_entity (entity_id, entity_type),
          KEY idx_agent_used (agent_used),
          KEY idx_intent_type (intent_type)
        )
        ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
        COMMENT='Table principale des interactions RAG avec métriques de base - Updated Task 2.17.4';
      EOD;
      $CLICSHOPPING_Db->exec($sql);
    }
    
    // Task 2.17.4: Add missing columns to existing table if they don't exist
    $CLICSHOPPING_Db->exec("
      ALTER TABLE :table_rag_interactions 
      ADD COLUMN IF NOT EXISTS execution_time INT DEFAULT NULL COMMENT 'Execution time in milliseconds' 
      AFTER response_time
    ");
    
    $CLICSHOPPING_Db->exec("
      ALTER TABLE :table_rag_interactions 
      ADD COLUMN IF NOT EXISTS entity_id INT DEFAULT 0 COMMENT 'Entity ID (0 = no specific entity, NULL = unknown)' 
      AFTER language_id
    ");
    
    $CLICSHOPPING_Db->exec("
      ALTER TABLE :table_rag_interactions 
      ADD COLUMN IF NOT EXISTS entity_type VARCHAR(50) DEFAULT NULL COMMENT 'Type of entity (product, category, page, etc.)' 
      AFTER entity_id
    ");
    
    $CLICSHOPPING_Db->exec("
      ALTER TABLE :table_rag_interactions 
      ADD COLUMN IF NOT EXISTS agent_used VARCHAR(50) DEFAULT NULL COMMENT 'Agent that processed the query (orchestrator, analytics_agent, semantic_agent, etc.)' 
      AFTER entity_type
    ");
    
    $CLICSHOPPING_Db->exec("
      ALTER TABLE :table_rag_interactions 
      ADD COLUMN IF NOT EXISTS intent_type VARCHAR(50) DEFAULT NULL COMMENT 'Intent classification (analytics, semantic, hybrid, web_search)' 
      AFTER agent_used
    ");
    
    // Add indexes if they don't exist
    $indexResult = $CLICSHOPPING_Db->query("SHOW INDEX FROM :table_rag_interactions WHERE Key_name = 'idx_entity'");
    if ($indexResult->fetch() === false) {
      $CLICSHOPPING_Db->exec("ALTER TABLE :table_rag_interactions ADD INDEX idx_entity (entity_id, entity_type)");
    }
    
    $indexResult = $CLICSHOPPING_Db->query("SHOW INDEX FROM :table_rag_interactions WHERE Key_name = 'idx_agent_used'");
    if ($indexResult->fetch() === false) {
      $CLICSHOPPING_Db->exec("ALTER TABLE :table_rag_interactions ADD INDEX idx_agent_used (agent_used)");
    }
    
    $indexResult = $CLICSHOPPING_Db->query("SHOW INDEX FROM :table_rag_interactions WHERE Key_name = 'idx_intent_type'");
    if ($indexResult->fetch() === false) {
      $CLICSHOPPING_Db->exec("ALTER TABLE :table_rag_interactions ADD INDEX idx_intent_type (intent_type)");
    }

    $Qcheck = $CLICSHOPPING_Db->query('show tables like ":table_rag_statistics"');

    if ($Qcheck->fetch() === false) {
      $sql = <<<EOD
        CREATE TABLE IF NOT EXISTS :table_rag_statistics (
          id INT NOT NULL AUTO_INCREMENT,
          interaction_id INT DEFAULT NULL COMMENT 'FK vers clic_chatgpt_interactions géré par le système',
          -- Performance
          response_time_ms INT DEFAULT NULL,
          cache_hit BOOLEAN DEFAULT FALSE,

          -- API & Coûts
          api_provider VARCHAR(50) DEFAULT NULL,
          model_used VARCHAR(100) DEFAULT NULL,
          tokens_prompt INT DEFAULT NULL,
          tokens_completion INT DEFAULT NULL,
          tokens_total INT DEFAULT NULL,
          api_cost_usd DECIMAL(10,6) DEFAULT NULL,
      
          -- Classification & Agents
          agent_type VARCHAR(50) DEFAULT NULL,
          classification_type VARCHAR(50) DEFAULT NULL,
          confidence_score DECIMAL(5,2) DEFAULT NULL,
      
          -- Qualité & Sécurité
          security_score DECIMAL(5,2) DEFAULT NULL,
          response_quality DECIMAL(5,2) DEFAULT NULL,
          guardrails_triggered BOOLEAN DEFAULT FALSE,
      
          -- Erreurs
          error_occurred BOOLEAN DEFAULT FALSE,
          error_type VARCHAR(100) DEFAULT NULL,
          error_message TEXT DEFAULT NULL,
      
          -- Métadonnées
          user_id INT DEFAULT NULL,
          session_id VARCHAR(255) DEFAULT NULL,
          language_id INT DEFAULT 1,
      
          -- Timestamps
          date_added DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      
          PRIMARY KEY (id),
          KEY idx_interaction_id (interaction_id),
          KEY idx_date_added (date_added),
          KEY idx_agent_type (agent_type),
          KEY idx_classification_type (classification_type),
          KEY idx_user_id (user_id),
          KEY idx_session_id (session_id),
          KEY idx_response_time (response_time_ms),
          KEY idx_error (error_occurred)
      )
      ENGINE=InnoDB
      DEFAULT CHARSET=utf8mb4
      COLLATE=utf8mb4_unicode_ci
      COMMENT='Statistiques détaillées pour dashboard RAG, intégrité gérée par le système';

      EOD;
      $CLICSHOPPING_Db->exec($sql);
    }

    // Table pour le cache des requêtes SQL
    $Qcheck = $CLICSHOPPING_Db->query('show tables like ":table_rag_query_cache"');

    if ($Qcheck->fetch() === false) {
      $sql = <<<EOD
          CREATE TABLE IF NOT EXISTS :table_rag_query_cache (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `cache_key` VARCHAR(64) NOT NULL UNIQUE COMMENT 'Clé MD5 unique',
        `user_query` VARCHAR(500) NOT NULL COMMENT 'Question utilisateur',
        `sql_query` TEXT NOT NULL COMMENT 'Requête SQL générée',
        `query_results` LONGTEXT COMMENT 'Résultats JSON',
        `created_at` DATETIME NOT NULL COMMENT 'Date de création',
        `expires_at` DATETIME NOT NULL COMMENT 'Date d expiration',
        `hit_count` INT(11) NOT NULL DEFAULT 0 COMMENT 'Nombre d utilisations',
        `interpretation` TEXT DEFAULT NULL COMMENT 'Interprétation en langage naturel',
        
        PRIMARY KEY (`id`),
        UNIQUE KEY `idx_cache_key` (`cache_key`),
        KEY `idx_expires` (`expires_at`),
        KEY `idx_hits` (`hit_count`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
      EOD;
      $CLICSHOPPING_Db->exec($sql);
    }

    // Table pour le cache des requêtes SQL
    $Qcheck = $CLICSHOPPING_Db->query('show tables like ":table_rag_cache_statistics"');

    if ($Qcheck->fetch() === false) {
      $sql = <<<EOD
      CREATE TABLE IF NOT EXISTS :table_rag_cache_statistics (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `cache_key` VARCHAR(64) NOT NULL COMMENT 'Clé de cache',
        `hit_count` INT(11) NOT NULL DEFAULT 0 COMMENT 'Nombre de hits',
        `miss_count` INT(11) NOT NULL DEFAULT 0 COMMENT 'Nombre de miss',
        `avg_execution_time` FLOAT DEFAULT 0 COMMENT 'Temps moyen en ms',
        `last_accessed` DATETIME DEFAULT NULL COMMENT 'Dernier accès',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      
        PRIMARY KEY (`id`),
        UNIQUE KEY `idx_cache_key` (`cache_key`),
        KEY `idx_last_accessed` (`last_accessed`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

      EOD;
      $CLICSHOPPING_Db->exec($sql);
    }

    // Table for web search daily statistics (clic_rag_web_search_stats)
    // Note: clic_rag_web_search_results already exists, no need to recreate
    $Qcheck = $CLICSHOPPING_Db->query('show tables like ":table_rag_web_search_stats"');

    if ($Qcheck->fetch() === false) {
      $sql = <<<EOD
      CREATE TABLE IF NOT EXISTS :table_rag_web_search_stats (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `date` DATE NOT NULL,
        `total_searches` INT(11) NOT NULL DEFAULT 0,
        `cache_hits` INT(11) NOT NULL DEFAULT 0,
        `api_calls` INT(11) NOT NULL DEFAULT 0,
        `failed_searches` INT(11) NOT NULL DEFAULT 0,
        `avg_response_time` DECIMAL(10,3) DEFAULT NULL COMMENT 'Average response time in seconds',
        `total_api_cost` DECIMAL(10,4) DEFAULT NULL COMMENT 'Total API cost in USD',
        `unique_queries` INT(11) NOT NULL DEFAULT 0,
        `price_comparisons` INT(11) NOT NULL DEFAULT 0 COMMENT 'Number of price comparison queries',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `idx_date` (`date`),
        KEY `idx_created_at` (`created_at`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      COMMENT='Daily statistics for web search usage';

      EOD;
      $CLICSHOPPING_Db->exec($sql);
    }



      $Qcheck = $CLICSHOPPING_Db->query('show tables like ":table_rag_web_cache_embedding"');

    if ($Qcheck->fetch() === false) {
      $sql = <<<EOD
      CREATE TABLE :table_rag_web_cache_embedding (
        `id` int(11) NOT NULL,
        `content` longtext NOT NULL COMMENT 'Contenu complet (query + synthèse + sources)',
        `type` varchar(50) DEFAULT 'web_search_cache' COMMENT 'Type de document',
        `sourcetype` varchar(50) DEFAULT 'web_search' COMMENT 'Source du document',
        `sourcename` varchar(255) DEFAULT 'serpapi' COMMENT 'Nom de la source (serpapi, bing, etc.)',
        `embedding` vector(3072) NOT NULL COMMENT 'Vecteur d embedding (adapter selon modèle)',
        `original_query` text DEFAULT NULL COMMENT 'Requête originale ayant généré ce résultat',
        `search_engine` varchar(50) DEFAULT NULL COMMENT 'Moteur de recherche utilisé',
        `quality_score` float DEFAULT 0 COMMENT 'Score de qualité du résultat (0-1)',
        `usage_count` int(11) DEFAULT 0 COMMENT 'Nombre de fois que ce résultat a été réutilisé',
        `last_used` datetime DEFAULT NULL COMMENT 'Dernière date d utilisation',
        `entity_id` int(11) DEFAULT NULL COMMENT 'ID de la requête source (optionnel)',
        `language_id` int(11) DEFAULT 1 COMMENT 'ID de la langue',
        `chunknumber` int(11) DEFAULT 128 COMMENT 'Taille du chunk utilisé',
        `date_modified` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`))
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

      ALTER TABLE :table_rag_web_cache_embedding
        ADD PRIMARY KEY (`id`),
        ADD KEY `idx_type` (`type`),
        ADD KEY `idx_sourcetype` (`sourcetype`),
        ADD KEY `idx_quality_score` (`quality_score`),
        ADD KEY `idx_usage_count` (`usage_count`),
        ADD KEY `idx_last_used` (`last_used`),
        ADD KEY `idx_quality_usage` (`quality_score`,`usage_count`),
        ADD KEY `idx_quality_usage_last` (`quality_score`, `usage_count`, `last_used`),
        ADD KEY `idx_search_engine_quality` (`search_engine`, `quality_score`),
        ADD KEY `idx_language_quality` (`language_id`, `quality_score`);

      ALTER TABLE :table_rag_web_cache_embedding MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
      EOD;
      
      $CLICSHOPPING_Db->exec($sql);
    }
  }
}