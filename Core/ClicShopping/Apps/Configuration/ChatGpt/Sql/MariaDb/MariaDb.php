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
use ClicShopping\OM\CLICSHOPPING;
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
            gpt_id int(11) NOT NULL COMMENT 'Primary key - unique identifier for each GPT interaction',
            question text NOT NULL COMMENT 'User question or prompt submitted to GPT',
            response text NOT NULL COMMENT 'GPT generated response text',
            date_added date DEFAULT NULL COMMENT 'Date when the interaction was recorded',
            KEY idx_date_added (date_added)
          ) CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='GPT interaction history with questions and responses';

          ALTER TABLE :table_gpt  ADD PRIMARY KEY (gpt_id);
          ALTER TABLE :table_gpt  MODIFY gpt_id int(11) NOT NULL AUTO_INCREMENT;

          CREATE TABLE :table_gpt_usage (
            usage_id int(11) NOT NULL COMMENT 'Primary key - unique identifier for each usage record',
            gpt_id int(11) NOT NULL COMMENT 'FK to gpt table - links to the GPT interaction',
            promptTokens int(11) DEFAULT NULL COMMENT 'Number of tokens used in the prompt/input',
            completionTokens int(11) DEFAULT NULL COMMENT 'Number of tokens used in the completion/output',
            totalTokens int(11) DEFAULT NULL COMMENT 'Total tokens used (prompt + completion)',
            ia_type varchar(255) DEFAULT NULL COMMENT 'AI provider type - openai, ollama, anthropic, etc.',
            model varchar(255) DEFAULT NULL COMMENT 'Model name used - gpt-4, gpt-3.5-turbo, claude-3, etc.',
            date_added date DEFAULT NULL COMMENT 'Date when the usage was recorded',
            KEY idx_gpt_id (gpt_id),
            KEY idx_date_added (date_added),
            KEY idx_model (model),
            KEY idx_ia_type (ia_type)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Token usage tracking for GPT API calls and cost analysis';
          ALTER TABLE :table_gpt_usage  ADD PRIMARY KEY (usage_id);
          ALTER TABLE :table_gpt_usage  MODIFY usage_id int(11) NOT NULL AUTO_INCREMENT;
          EOD;
      $CLICSHOPPING_Db->exec($sql);
    }

    $Qcheck = $CLICSHOPPING_Db->query('show tables like ":table_categories_embedding"');

    if ($Qcheck->fetch() === false) {
      $sql = <<<EOD
      CREATE TABLE IF NOT EXISTS :table_categories_embedding (
        id SERIAL PRIMARY KEY COMMENT 'Primary key - unique identifier for each category embedding',
          content longtext DEFAULT NULL COMMENT 'Category content for embedding generation - description, metadata, etc.',
          type text DEFAULT NULL COMMENT 'Type of content - description, metadata, full_text',
          sourcetype text default 'manual' COMMENT 'Source type - manual, automated, imported',
          sourcename text default 'manual' COMMENT 'Name of the source system or process',
          embedding vector(3072) NOT NULL COMMENT 'Vector embedding (3072 dimensions) for semantic search',
          chunknumber int default 128 COMMENT 'Chunk size used for embedding generation',
          date_modified datetime DEFAULT NULL COMMENT 'Last modification timestamp',
          entity_id INT COMMENT 'FK to categories table - category ID',
          language_id INT COMMENT 'FK to languages table - language identifier',
          metadata longtext DEFAULT NULL COMMENT 'Additional metadata about the embedding - structure varies by type',
          KEY idx_entity_id (entity_id),
          KEY idx_language_id (language_id),
          KEY idx_entity_lang (entity_id, language_id),
          KEY idx_date_modified (date_modified)
      ) COMMENT='Vector embeddings for categories - enables semantic category search and recommendations';

      CREATE VECTOR INDEX embedding_index ON :table_categories_embedding (embedding);

       CREATE TABLE IF NOT EXISTS :table_products_embedding (
          id SERIAL PRIMARY KEY COMMENT 'Primary key - unique identifier for each product embedding',
          content longtext DEFAULT NULL COMMENT 'Product content for embedding generation - name, description, specifications, etc.',
          type text DEFAULT NULL COMMENT 'Type of content - description, specifications, reviews, full_text',
          sourcetype text default 'manual' COMMENT 'Source type - manual, automated, imported',
          sourcename text default 'manual' COMMENT 'Name of the source system or process',
          embedding vector(3072) NOT NULL COMMENT 'Vector embedding (3072 dimensions) for semantic search',
          chunknumber int default 128 COMMENT 'Chunk size used for embedding generation',
          date_modified datetime DEFAULT NULL COMMENT 'Last modification timestamp',
          entity_id INT COMMENT 'FK to products table - product ID',
          language_id INT COMMENT 'FK to languages table - language identifier',
          metadata longtext DEFAULT NULL COMMENT 'Additional metadata about the embedding - may include price, category, manufacturer info',
          KEY idx_entity_id (entity_id),
          KEY idx_language_id (language_id),
          KEY idx_entity_lang (entity_id, language_id),
          KEY idx_date_modified (date_modified)
        ) COMMENT='Vector embeddings for products - enables semantic product search and recommendations';

      CREATE VECTOR INDEX embedding_index ON :table_products_embedding (embedding);
      
      CREATE TABLE IF NOT EXISTS :table_pages_manager_embedding (
          id SERIAL PRIMARY KEY COMMENT 'Primary key - unique identifier for each page embedding',
          content longtext DEFAULT NULL COMMENT 'Page content for embedding generation - title, body, metadata, etc.',
          type TEXT DEFAULT NULL COMMENT 'Type of content - page_content, metadata, full_text',
          sourcetype TEXT DEFAULT 'manual' COMMENT 'Source type - manual, automated, imported',
          sourcename TEXT DEFAULT 'manual' COMMENT 'Name of the source system or process',
          embedding VECTOR(3072) NOT NULL COMMENT 'Vector embedding (3072 dimensions) for semantic search',
          chunknumber INT DEFAULT 128 COMMENT 'Chunk size used for embedding generation',
          date_modified DATETIME DEFAULT NULL COMMENT 'Last modification timestamp',
          entity_id INT COMMENT 'FK to pages_manager table - page ID',
          language_id INT COMMENT 'FK to languages table - language identifier',
          metadata longtext DEFAULT NULL COMMENT 'Additional metadata about the embedding - may include page_type, status, url',
          KEY idx_entity_id (entity_id),
          KEY idx_language_id (language_id),
          KEY idx_entity_lang (entity_id, language_id),
          KEY idx_date_modified (date_modified)
      ) COMMENT='Vector embeddings for CMS pages - enables semantic page search and content discovery';

      CREATE VECTOR INDEX embedding_index ON :table_pages_manager_embedding (embedding);

      CREATE TABLE IF NOT EXISTS :table_manufacturers_embedding (
          id SERIAL PRIMARY KEY COMMENT 'Primary key - unique identifier for each manufacturer embedding',
          content longtext DEFAULT NULL COMMENT 'Manufacturer content for embedding generation - name, description, history, etc.',
          type TEXT DEFAULT NULL COMMENT 'Type of content - description, history, metadata, full_text',
          sourcetype TEXT DEFAULT 'manual' COMMENT 'Source type - manual, automated, imported',
          sourcename TEXT DEFAULT 'manual' COMMENT 'Name of the source system or process',
          embedding VECTOR(3072) NOT NULL COMMENT 'Vector embedding (3072 dimensions) for semantic search',
          chunknumber INT DEFAULT 128 COMMENT 'Chunk size used for embedding generation',
          date_modified DATETIME DEFAULT NULL COMMENT 'Last modification timestamp',
          entity_id INT COMMENT 'FK to manufacturers table - manufacturer ID',
          language_id INT COMMENT 'FK to languages table - language identifier',
          metadata longtext DEFAULT NULL COMMENT 'Additional metadata about the embedding - may include product count, supplier info',
          KEY idx_entity_id (entity_id),
          KEY idx_language_id (language_id),
          KEY idx_entity_lang (entity_id, language_id),
          KEY idx_date_modified (date_modified)
      ) COMMENT='Vector embeddings for manufacturers - enables semantic manufacturer search and brand discovery';

      CREATE VECTOR INDEX embedding_index ON :table_manufacturers_embedding (embedding);

      CREATE TABLE IF NOT EXISTS :table_suppliers_embedding (
          id SERIAL PRIMARY KEY COMMENT 'Primary key - unique identifier for each supplier embedding',
          content longtext DEFAULT NULL COMMENT 'Supplier content for embedding generation - name, description, capabilities, etc.',
          type TEXT DEFAULT NULL COMMENT 'Type of content - description, capabilities, metadata, full_text',
          sourcetype TEXT DEFAULT 'manual' COMMENT 'Source type - manual, automated, imported',
          sourcename TEXT DEFAULT 'manual' COMMENT 'Name of the source system or process',
          embedding VECTOR(3072) NOT NULL COMMENT 'Vector embedding (3072 dimensions) for semantic search',
          chunknumber INT DEFAULT 128 COMMENT 'Chunk size used for embedding generation',
          date_modified DATETIME DEFAULT NULL COMMENT 'Last modification timestamp',
          entity_id INT COMMENT 'FK to suppliers table - supplier ID',
          metadata longtext DEFAULT NULL COMMENT 'Additional metadata about the embedding - may include product count, contact details',                  
          KEY idx_entity_id (entity_id),
          KEY idx_date_modified (date_modified)
      ) COMMENT='Vector embeddings for suppliers - enables semantic supplier search and sourcing recommendations';

      CREATE VECTOR INDEX embedding_index ON :table_suppliers_embedding (embedding);

      CREATE TABLE IF NOT EXISTS :table_reviews_embedding (
          id SERIAL PRIMARY KEY COMMENT 'Primary key - unique identifier for each review embedding',
          content longtext DEFAULT NULL COMMENT 'Review content for embedding generation - review text, title, etc.',
          type TEXT DEFAULT NULL COMMENT 'Type of content - review_text, summary, full_text',
          sourcetype TEXT DEFAULT 'manual' COMMENT 'Source type - manual, automated, imported',
          sourcename TEXT DEFAULT 'manual' COMMENT 'Name of the source system or process',
          embedding VECTOR(3072) NOT NULL COMMENT 'Vector embedding (3072 dimensions) for semantic search',
          chunknumber INT DEFAULT 128 COMMENT 'Chunk size used for embedding generation',
          date_modified DATETIME DEFAULT NULL COMMENT 'Last modification timestamp',
          entity_id INT COMMENT 'FK to reviews table - review ID',
          language_id INT COMMENT 'FK to languages table - language identifier',
          metadata longtext DEFAULT NULL COMMENT 'Additional metadata about the embedding - may include rating, product_id, customer_id',
          KEY idx_entity_id (entity_id),
          KEY idx_language_id (language_id),
          KEY idx_entity_lang (entity_id, language_id),
          KEY idx_date_modified (date_modified)
      ) COMMENT='Vector embeddings for product reviews - enables semantic review search and sentiment analysis';

      CREATE VECTOR INDEX embedding_index ON :table_reviews_embedding (embedding);

      CREATE TABLE IF NOT EXISTS :table_reviews_sentiment_embedding (
          id SERIAL PRIMARY KEY COMMENT 'Primary key - unique identifier for each sentiment embedding',
          content longtext DEFAULT NULL COMMENT 'Sentiment-analyzed review content with emotional context',
          type TEXT DEFAULT NULL COMMENT 'Type of content - sentiment_analysis, emotional_context, full_text',
          sourcetype TEXT DEFAULT 'manual' COMMENT 'Source type - manual, automated, ai_analyzed',
          sourcename TEXT DEFAULT 'manual' COMMENT 'Name of the source system or AI model',
          embedding VECTOR(3072) NOT NULL COMMENT 'Vector embedding (3072 dimensions) for sentiment-aware semantic search',
          chunknumber INT DEFAULT 128 COMMENT 'Chunk size used for embedding generation',
          date_modified DATETIME DEFAULT NULL COMMENT 'Last modification timestamp',
          entity_id INT COMMENT 'FK to reviews table - review ID',
          language_id INT COMMENT 'FK to languages table - language identifier',
          metadata longtext DEFAULT NULL COMMENT 'Additional metadata about the embedding - may include sentiment_score, review_id, product_id',
          KEY idx_entity_id (entity_id),
          KEY idx_language_id (language_id),
          KEY idx_entity_lang (entity_id, language_id),
          KEY idx_date_modified (date_modified)
      ) COMMENT='Vector embeddings for review sentiment analysis - enables emotion-aware search and trend detection';

      CREATE VECTOR INDEX embedding_index ON :table_reviews_sentiment_embedding (embedding);

      CREATE TABLE IF NOT EXISTS :table_return_orders_embedding (
          id SERIAL PRIMARY KEY COMMENT 'Primary key - unique identifier for each return order embedding',
          content longtext DEFAULT NULL COMMENT 'Return order content for embedding generation - reason, description, notes, etc.',
          type TEXT DEFAULT NULL COMMENT 'Type of content - return_reason, customer_notes, full_text',
          sourcetype TEXT DEFAULT 'manual' COMMENT 'Source type - manual, automated, imported',
          sourcename TEXT DEFAULT 'manual' COMMENT 'Name of the source system or process',
          embedding VECTOR(3072) NOT NULL COMMENT 'Vector embedding (3072 dimensions) for semantic search',
          chunknumber INT DEFAULT 128 COMMENT 'Chunk size used for embedding generation',
          date_modified DATETIME DEFAULT NULL COMMENT 'Last modification timestamp',
          entity_id INT COMMENT 'FK to return_orders table - return order ID',
          metadata longtext DEFAULT NULL COMMENT 'Additional metadata about the embedding - may include order_status, total, customer_id',
          KEY idx_entity_id (entity_id),
          KEY idx_date_modified (date_modified)
      ) COMMENT='Vector embeddings for return orders - enables semantic return analysis and pattern detection';

      CREATE VECTOR INDEX embedding_index ON :table_return_orders_embedding (embedding);

      CREATE TABLE IF NOT EXISTS :table_orders_embedding (
          id SERIAL PRIMARY KEY COMMENT 'Primary key - unique identifier for each order embedding',
          content longtext DEFAULT NULL COMMENT 'Order content for embedding generation - items, notes, customer info, etc.',
          type TEXT DEFAULT NULL COMMENT 'Type of content - order_details, customer_notes, full_text',
          sourcetype TEXT DEFAULT 'manual' COMMENT 'Source type - manual, automated, imported',
          sourcename TEXT DEFAULT 'manual' COMMENT 'Name of the source system or process',
          embedding VECTOR(3072) NOT NULL COMMENT 'Vector embedding (3072 dimensions) for semantic search',
          chunknumber INT DEFAULT 128 COMMENT 'Chunk size used for embedding generation',
          date_modified DATETIME DEFAULT NULL COMMENT 'Last modification timestamp',
          entity_id INT COMMENT 'FK to orders table - order ID',
          metadata longtext DEFAULT NULL COMMENT 'Additional metadata about the embedding - may include order_status, total, customer_id',
          KEY idx_entity_id (entity_id),
          KEY idx_date_modified (date_modified)
      ) COMMENT='Vector embeddings for orders - enables semantic order search and pattern analysis';

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
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Primary key - auto-incremented unique identifier',
        content text DEFAULT NULL COMMENT 'Correction pattern content for embedding generation',
        type text DEFAULT NULL COMMENT 'Type of correction pattern',
        sourcetype text DEFAULT NULL COMMENT 'Source type of the correction pattern',
        sourcename text DEFAULT NULL COMMENT 'Name of the source system or module',
        embedding vector(3072) NOT NULL COMMENT 'Embedding vector for semantic search',
        metadata JSON NOT NULL DEFAULT '{}' COMMENT 'Additional metadata in JSON format',
        chunknumber int(11) DEFAULT 128 COMMENT 'Chunk size used for embedding generation',
        date_modified datetime DEFAULT NULL COMMENT 'Last modification timestamp',
        entity_id int(11) NULL DEFAULT 0 COMMENT 'Entity ID (0 = no specific entity, NULL = unknown)',
        entity_type VARCHAR(50) NULL COMMENT 'Type of entity (product, category, page, etc.)',
        language_id int(11) NOT NULL COMMENT 'Language identifier for the correction pattern',
        VECTOR KEY (embedding),
        PRIMARY KEY (id),
        UNIQUE KEY id (id),
        KEY idx_entity_id (entity_id),
        KEY idx_language_id (language_id),
        KEY idx_user_id (metadata(100)),
        KEY idx_entity (entity_id, entity_type),
        KEY idx_entity_language (entity_id, language_id),
        KEY idx_entity_type_language (entity_type, language_id, entity_id),
        KEY idx_date_modified (date_modified)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
     EOD;

      $CLICSHOPPING_Db->exec($sql);
    }


    $Qcheck = $CLICSHOPPING_Db->query('show tables like ":table_rag_memory_retention_log"');

    if ($Qcheck->fetch() === false) {
      $sql = <<<EOD
        CREATE TABLE IF NOT EXISTS :table_rag_memory_retention_log (
          id BIGINT AUTO_INCREMENT PRIMARY KEY COMMENT 'Primary key - unique identifier for each memory retention record',
          user_id VARCHAR(255) NOT NULL COMMENT 'User identifier for memory tracking',
          interaction_id VARCHAR(255) NOT NULL UNIQUE COMMENT 'Unique interaction identifier - prevents duplicates',
          timestamp_recorded TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'When the memory was first recorded',
          timestamp_migrated TIMESTAMP NULL COMMENT 'When the memory was migrated to a different level',
          level ENUM('working', 'short_term', 'long_term') DEFAULT 'short_term' COMMENT 'Memory retention level - working: active session, short_term: recent history, long_term: permanent storage',
          status ENUM('pending', 'short_term_stored', 'long_term_stored', 'archived') DEFAULT 'pending' COMMENT 'Processing status - pending: awaiting storage, short_term_stored: in short-term memory, long_term_stored: in long-term memory, archived: moved to archive',
          KEY idx_user_id (user_id),
          KEY idx_status (status),
          KEY idx_status_level (status, level),
          KEY idx_user_status (user_id, status),
          KEY idx_timestamp_recorded (timestamp_recorded)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Memory retention tracking for RAG system - manages working, short-term, and long-term memory lifecycle';

        EOD;
      $CLICSHOPPING_Db->exec($sql);
    }

    $Qcheck = $CLICSHOPPING_Db->query('show tables like ":table_rag_conversation_memory_embedding"');


    if ($Qcheck->fetch() === false) {
      $sql = <<<EOD
        CREATE TABLE :table_rag_conversation_memory_embedding (
          id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Primary key - auto-incremented unique identifier',
          content text DEFAULT NULL COMMENT 'Conversation content for embedding generation',
          type text DEFAULT NULL COMMENT 'Type of content (conversation, message, etc.)',
          sourcetype text DEFAULT NULL COMMENT 'Source type of the conversation',
          sourcename text DEFAULT NULL COMMENT 'Name of the source system or module',
          embedding vector(3072) NOT NULL COMMENT 'Embedding vector (NULL if not yet generated)',
          user_message TEXT COMMENT 'User message from conversation',
          assistant_response TEXT COMMENT 'Assistant response from conversation',
          chunknumber int(11) DEFAULT 128 COMMENT 'Chunk size used for embedding generation',
          date_modified datetime DEFAULT NULL COMMENT 'Last modification timestamp',
          entity_id int(11) NULL DEFAULT NULL COMMENT 'Entity ID (nullable for general conversations)',
          entity_type VARCHAR(50) NULL DEFAULT NULL COMMENT 'Entity type (nullable for general conversations)',
          language_id int(11) NOT NULL COMMENT 'Language identifier for the conversation',
          user_id VARCHAR(255) DEFAULT NULL COMMENT 'User ID for fast filtering',
          interaction_id VARCHAR(255) DEFAULT NULL COMMENT 'Interaction ID to prevent duplicates',
          metadata JSON NOT NULL DEFAULT '{}' COMMENT 'Additional metadata in JSON format',
          created_at DATETIME DEFAULT NULL COMMENT 'Creation timestamp',
          VECTOR KEY (embedding),
          PRIMARY KEY (id),
          UNIQUE KEY id (id),
          KEY idx_user_id (user_id),
          KEY idx_interaction_id (interaction_id),
          KEY idx_language_id (language_id),
          KEY idx_user_lang_date (user_id, language_id, date_modified),
          KEY idx_date_modified (date_modified),
          KEY idx_entity (entity_id, entity_type),
          KEY idx_interaction_user (interaction_id, user_id),
          KEY idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
          cache_id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Primary key - unique identifier for each cached calculation',
          expression VARCHAR(500) NOT NULL COMMENT 'Mathematical expression that was calculated',
          expression_hash VARCHAR(64) NOT NULL COMMENT 'MD5 hash of expression for fast lookup and deduplication',
          result DOUBLE NOT NULL COMMENT 'Calculated result value',
          result_type VARCHAR(50) NOT NULL COMMENT 'Type of result - number, percentage, currency, etc.',
          variables JSON DEFAULT NULL COMMENT 'Variables used in the calculation (JSON format)',
          execution_time FLOAT NOT NULL COMMENT 'Time taken to execute calculation in milliseconds',
          created_at DATETIME NOT NULL COMMENT 'When the calculation was first cached',
          last_accessed DATETIME NOT NULL COMMENT 'Last time this cached result was accessed',
          access_count INT(11) UNSIGNED DEFAULT 0 COMMENT 'Number of times this cached result has been reused',
          PRIMARY KEY (cache_id),
          UNIQUE KEY idx_expression_hash (expression_hash),
          KEY idx_created_at (created_at),
          KEY idx_last_accessed (last_accessed)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Cache for mathematical calculations to improve performance and reduce redundant computations';


        CREATE TABLE IF NOT EXISTS  :table_rag_calculator_logs (
          log_id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Primary key - unique identifier for each calculation log entry',
          user_id VARCHAR(100) NOT NULL COMMENT 'User who initiated the calculation',
          expression TEXT NOT NULL COMMENT 'Mathematical expression that was evaluated',
          result DOUBLE DEFAULT NULL COMMENT 'Calculated result (NULL if calculation failed)',
          success TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Success flag - 0: failed, 1: succeeded',
          error_message TEXT DEFAULT NULL COMMENT 'Error message if calculation failed',
          execution_time FLOAT NOT NULL COMMENT 'Time taken to execute calculation in milliseconds',
          step_id VARCHAR(100) DEFAULT NULL COMMENT 'Step identifier in multi-step calculations',
          plan_id VARCHAR(100) DEFAULT NULL COMMENT 'Plan identifier for grouped calculations',
          metadata JSON DEFAULT NULL COMMENT 'Additional metadata in JSON format',
          created_at DATETIME NOT NULL COMMENT 'When the calculation was logged',
          PRIMARY KEY (log_id),
          KEY idx_user_id (user_id),
          KEY idx_created_at (created_at),
          KEY idx_success (success),
          KEY idx_plan_id (plan_id),
          KEY idx_user_success_date (user_id, success, created_at),
          KEY idx_plan_step (plan_id, step_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Audit log for all calculator operations - tracks success, failures, and performance metrics';

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

    $Qcheck = $CLICSHOPPING_Db->query('show tables like ":table_rag_web_search_results"');

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
      `id` int(11) NOT NULL AUTO_INCREMENT COMMENT 'Primary key - unique identifier for each web search source',
      `site_domain` varchar(255) NOT NULL COMMENT 'Domain name of the web search source - e.g., wikipedia.org, stackoverflow.com',
      `authority_score` float(15,4) NOT NULL COMMENT 'Authority/trust score for this source (0.0000-1.0000) - higher is more authoritative',
      `status` tinyint(1) NOT NULL COMMENT 'Source status - 0: disabled, 1: enabled',
      `description` text NOT NULL COMMENT 'Description of the source and its content focus',
      `search_pattern` varchar(255) COMMENT 'URL pattern for constructing search queries on this source',
      PRIMARY KEY (`id`),
      INDEX `idx_site_domain` (`site_domain`),
      INDEX `idx_status_authority` (`status`, `authority_score`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Trusted web search sources with authority scores for RAG system';
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
          interaction_id INT NOT NULL AUTO_INCREMENT COMMENT 'Primary key - unique interaction identifier',
          user_id INT DEFAULT NULL COMMENT 'User ID who initiated the interaction',
          session_id VARCHAR(255) DEFAULT NULL COMMENT 'Session identifier for tracking user sessions',
          question TEXT NOT NULL COMMENT 'User question or query text',
          response TEXT DEFAULT NULL COMMENT 'System response to the user query',
          request_type VARCHAR(50) DEFAULT NULL COMMENT 'Type of request: analytics, semantic, error, security_blocked',
          confidence DECIMAL(5,2) DEFAULT NULL COMMENT 'Confidence score of the response (0-100)',
          response_quality INT DEFAULT NULL COMMENT 'Quality score of the response (0-100)',
          response_time INT DEFAULT NULL COMMENT 'Response time in milliseconds',
          execution_time INT DEFAULT NULL COMMENT 'Execution time in milliseconds',
          tokens_used INT DEFAULT NULL COMMENT 'Number of tokens used in the API call',
          api_cost DECIMAL(10,6) DEFAULT NULL COMMENT 'API cost in USD for this interaction',
          language_id INT DEFAULT 1 COMMENT 'Language identifier for the interaction',
          entity_id INT DEFAULT 0 COMMENT 'Entity ID (0 = no specific entity, NULL = unknown)',
          entity_type VARCHAR(50) DEFAULT NULL COMMENT 'Type of entity (product, category, page, etc.)',
          agent_used VARCHAR(50) DEFAULT NULL COMMENT 'Agent that processed the query (orchestrator, analytics_agent, semantic_agent, etc.)',
          intent_type VARCHAR(50) DEFAULT NULL COMMENT 'Intent classification (analytics, semantic, hybrid, web_search)',
          date_added DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Timestamp when the interaction was created',
          
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
        COMMENT='Main RAG interactions table with base metrics';
      EOD;
      $CLICSHOPPING_Db->exec($sql);
    }

    $Qcheck = $CLICSHOPPING_Db->query('show tables like ":table_rag_statistics"');

    if ($Qcheck->fetch() === false) {
      $sql = <<<EOD
        CREATE TABLE IF NOT EXISTS :table_rag_statistics (
          id INT NOT NULL AUTO_INCREMENT COMMENT 'Primary key - unique identifier for each statistics record',
          interaction_id INT DEFAULT NULL COMMENT 'FK to rag_interactions table - links to the interaction (managed by code)',
          -- Performance
          response_time_ms INT DEFAULT NULL COMMENT 'Response time in milliseconds',
          cache_hit BOOLEAN DEFAULT FALSE COMMENT 'Cache hit flag - TRUE: result from cache, FALSE: fresh computation',

          -- API & Costs
          api_provider VARCHAR(50) DEFAULT NULL COMMENT 'API provider - openai, anthropic, ollama, etc.',
          model_used VARCHAR(100) DEFAULT NULL COMMENT 'Model name used - gpt-4, claude-3, llama2, etc.',
          tokens_prompt INT DEFAULT NULL COMMENT 'Number of tokens in the prompt/input',
          tokens_completion INT DEFAULT NULL COMMENT 'Number of tokens in the completion/output',
          tokens_total INT DEFAULT NULL COMMENT 'Total tokens used (prompt + completion)',
          api_cost_usd DECIMAL(10,6) DEFAULT NULL COMMENT 'API cost in USD for this interaction',
      
          -- Classification & Agents
          agent_type VARCHAR(50) DEFAULT NULL COMMENT 'Agent type that processed the request - orchestrator, analytics_agent, semantic_agent, etc.',
          classification_type VARCHAR(50) DEFAULT NULL COMMENT 'Classification type - analytics, semantic, hybrid, web_search',
          confidence_score DECIMAL(5,2) DEFAULT NULL COMMENT 'Confidence score of the classification (0.00-100.00)',
      
          -- Quality & Security
          security_score DECIMAL(5,2) DEFAULT NULL COMMENT 'Security score (0.00-100.00) - higher is safer',
          response_quality DECIMAL(5,2) DEFAULT NULL COMMENT 'Response quality score (0.00-100.00)',
          guardrails_triggered BOOLEAN DEFAULT FALSE COMMENT 'Guardrails triggered flag - TRUE: safety measures activated, FALSE: normal processing',
      
          -- Errors
          error_occurred BOOLEAN DEFAULT FALSE COMMENT 'Error flag - TRUE: error occurred, FALSE: successful',
          error_type VARCHAR(100) DEFAULT NULL COMMENT 'Type of error - timeout, api_error, validation_error, etc.',
          error_message TEXT DEFAULT NULL COMMENT 'Detailed error message',
      
          -- Metadata
          user_id INT DEFAULT NULL COMMENT 'User identifier',
          session_id VARCHAR(255) DEFAULT NULL COMMENT 'Session identifier',
          language_id INT DEFAULT 1 COMMENT 'Language identifier',
      
          -- Timestamps
          date_added DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When the statistics record was created',
      
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
      COMMENT='Detailed statistics for RAG dashboard - performance, costs, quality, and error tracking';

      ALTER TABLE :table_rag_statistics ADD COLUMN IF NOT EXISTS metadata JSON DEFAULT NULL;
      ALTER TABLE :table_rag_statistics ADD COLUMN IF NOT EXISTS query_type VARCHAR(50) DEFAULT NULL;
      ALTER TABLE :table_rag_statistics ADD COLUMN IF NOT EXISTS success BOOLEAN DEFAULT TRUE;
      ALTER TABLE :table_rag_statistics ADD COLUMN IF NOT EXISTS response_time INT DEFAULT NULL;
      ALTER TABLE :table_rag_statistics ADD COLUMN IF NOT EXISTS created_at DATETIME DEFAULT NULL;

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

      ALTER TABLE :table_rag_query_cache ADD COLUMN IF NOT EXISTS entity_id INT UNSIGNED NULL AFTER interpretation;
      ALTER TABLE :table_rag_query_cache ADD COLUMN IF NOT EXISTS entity_type VARCHAR(50) NULL AFTER entity_id;
      ALTER TABLE :table_rag_query_cache ADD INDEX IF NOT EXISTS idx_entity (entity_type, entity_id);

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
        'entity_type' VARCHAR(50) DEFAULT NULL COMMENT 'Type of entity (web_search)',
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
    
//--------------------------------------
// RAG Schema Embeddings
//--------------------------------------

    $Qcheck = $CLICSHOPPING_Db->query('show tables like ":table_rag_schema_embeddings"');

    if ($Qcheck->fetch() === false) {
      $sql = <<<EOD
      CREATE TABLE IF NOT EXISTS :table_rag_schema_embeddings (
        id SERIAL PRIMARY KEY COMMENT 'Primary key - unique identifier for each schema embedding',
        table_name VARCHAR(255) NOT NULL UNIQUE COMMENT 'Database table name - unique identifier for the schema',
        schema_text TEXT NOT NULL COMMENT 'Complete schema definition including columns, types, and comments',
        embedding_vector VECTOR(3072) NOT NULL COMMENT 'Vector embedding of the schema for semantic search',
        token_count INT DEFAULT 0 COMMENT 'Number of tokens in the schema text',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'When the schema embedding was created',
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last time the schema embedding was updated',
        KEY idx_table_name (table_name),
        KEY idx_updated_at (updated_at)
      ) COMMENT='Vector embeddings of database schemas for semantic schema discovery and natural language to SQL';
      
      CREATE VECTOR INDEX embedding_index ON :table_rag_schema_embeddings (embedding_vector);
    EOD;
      $CLICSHOPPING_Db->exec($sql);
    }

//--------------------------------------
// RAG Security Events
//--------------------------------------
    self::installSecurityTables();

  }

  /**
   * Add taxonomy and metadata columns to embedding tables
   * 
   * This method adds the required JSON columns for taxonomy and metadata separation
   * to all specified embedding tables. It ensures that embeddings contain only pure
   * content while taxonomy and metadata are stored separately.
   * 
   * @param array $tables List of table names to update (without prefix)
   * @return array Results of schema updates with status for each table
   */
  public static function addTaxonomyColumns(array $tables = []): array
  {
    $CLICSHOPPING_Db = Registry::get('Db');
    $prefix = CLICSHOPPING::getConfig('db_table_prefix');
    $results = [];
    
    // Default to all known embedding tables if none specified
    if (empty($tables)) {
      $tables = [
        'categories_embedding',
        'products_embedding',
        'orders_embedding',
        'manufacturers_embedding',
        'suppliers_embedding',
        'pages_manager_embedding',
        'return_orders_embedding',
        'reviews_embedding',
        'reviews_sentiment_embedding'
      ];
    }
    
    foreach ($tables as $table) {
      $fullTableName = $prefix . $table;
      $tableResult = [
        'table' => $fullTableName,
        'taxonomy_added' => false,
        'metadata_added' => false,
        'errors' => []
      ];
      
      try {
        // Check if table exists
        $checkTable = $CLICSHOPPING_Db->query("SHOW TABLES LIKE '{$fullTableName}'");
        if ($checkTable->fetch() === false) {
          $tableResult['errors'][] = "Table does not exist";
          $results[] = $tableResult;
          continue;
        }
        
        // Add taxonomy column if it doesn't exist
        try {
          $CLICSHOPPING_Db->exec("
            ALTER TABLE {$fullTableName} 
            ADD COLUMN IF NOT EXISTS taxonomy JSON DEFAULT NULL 
            COMMENT 'Structured taxonomy metadata (separate from embedding content)'
          ");
          $tableResult['taxonomy_added'] = true;
        } catch (\Exception $e) {
          $tableResult['errors'][] = "Taxonomy column: " . $e->getMessage();
        }
        
        // Add metadata column if it doesn't exist
        try {
          $CLICSHOPPING_Db->exec("
            ALTER TABLE {$fullTableName} 
            ADD COLUMN IF NOT EXISTS metadata JSON DEFAULT NULL 
            COMMENT 'Document metadata for filtering and display'
          ");
          $tableResult['metadata_added'] = true;
        } catch (\Exception $e) {
          $tableResult['errors'][] = "Metadata column: " . $e->getMessage();
        }
        
        $tableResult['success'] = empty($tableResult['errors']);
        
      } catch (\Exception $e) {
        $tableResult['errors'][] = "General error: " . $e->getMessage();
        $tableResult['success'] = false;
      }
      
      $results[] = $tableResult;
    }
    
    return $results;
  }

  /**
   * Create indexes for JSON metadata queries
   * 
   * Creates functional indexes on JSON fields to enable efficient filtering
   * by metadata fields like document_type, entity_type, etc.
   * 
   * @param string $tableName Table name (without prefix) to create indexes on
   * @return bool Success status
   */
  public static function createMetadataIndexes(string $tableName): bool
  {
    $CLICSHOPPING_Db = Registry::get('Db');
    $prefix = CLICSHOPPING::getConfig('db_table_prefix');
    $fullTableName = $prefix . $tableName;
    
    try {
      // Check if table exists
      $checkTable = $CLICSHOPPING_Db->query("SHOW TABLES LIKE '{$fullTableName}'");
      if ($checkTable->fetch() === false) {
        error_log("Table {$fullTableName} does not exist");
        return false;
      }
      
      // Create index on metadata->>'$.document_type' for efficient filtering
      // Using generated column approach for MariaDB compatibility
      try {
        $CLICSHOPPING_Db->exec("
          ALTER TABLE {$fullTableName} 
          ADD INDEX IF NOT EXISTS idx_metadata_document_type 
          ((CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.document_type')) AS CHAR(50))))
        ");
      } catch (\Exception $e) {
        error_log("Index creation for document_type failed: " . $e->getMessage());
        // Continue with other indexes even if this one fails
      }
      
      // Create index on metadata->>'$.entity_type' for efficient filtering
      try {
        $CLICSHOPPING_Db->exec("
          ALTER TABLE {$fullTableName} 
          ADD INDEX IF NOT EXISTS idx_metadata_entity_type 
          ((CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.entity_type')) AS CHAR(50))))
        ");
      } catch (\Exception $e) {
        error_log("Index creation for entity_type failed: " . $e->getMessage());
      }
      
      return true;
      
    } catch (\Exception $e) {
      error_log("Error creating metadata indexes for {$fullTableName}: " . $e->getMessage());
      return false;
    }
  }

  /**
   * Validate schema changes
   * 
   * Verifies that the taxonomy and metadata columns were added successfully
   * and that indexes were created properly.
   * 
   * @param string $tableName Table name (without prefix) to validate
   * @return array Validation results with detailed status
   */
  public static function validateSchema(string $tableName): array
  {
    $CLICSHOPPING_Db = Registry::get('Db');
    $prefix = CLICSHOPPING::getConfig('db_table_prefix');
    $fullTableName = $prefix . $tableName;
    
    $validation = [
      'table' => $fullTableName,
      'exists' => false,
      'has_taxonomy_column' => false,
      'has_metadata_column' => false,
      'has_content_column' => false,
      'has_embedding_column' => false,
      'indexes' => [],
      'record_count' => 0,
      'errors' => []
    ];
    
    try {
      // Check if table exists
      $checkTable = $CLICSHOPPING_Db->query("SHOW TABLES LIKE '{$fullTableName}'");
      if ($checkTable->fetch() === false) {
        $validation['errors'][] = "Table does not exist";
        return $validation;
      }
      $validation['exists'] = true;
      
      // Check columns
      $columns = $CLICSHOPPING_Db->query("SHOW COLUMNS FROM {$fullTableName}");
      while ($column = $columns->fetch()) {
        $columnName = $column['Field'];
        
        if ($columnName === 'taxonomy') {
          $validation['has_taxonomy_column'] = true;
          $validation['taxonomy_type'] = $column['Type'];
        }
        if ($columnName === 'metadata') {
          $validation['has_metadata_column'] = true;
          $validation['metadata_type'] = $column['Type'];
        }
        if ($columnName === 'content') {
          $validation['has_content_column'] = true;
        }
        if ($columnName === 'embedding') {
          $validation['has_embedding_column'] = true;
        }
      }
      
      // Check indexes
      $indexes = $CLICSHOPPING_Db->query("SHOW INDEX FROM {$fullTableName}");
      while ($index = $indexes->fetch()) {
        $validation['indexes'][] = $index['Key_name'];
      }
      
      // Get record count
      $countResult = $CLICSHOPPING_Db->query("SELECT COUNT(*) as cnt FROM {$fullTableName}");
      $countRow = $countResult->fetch();
      $validation['record_count'] = (int)($countRow['cnt'] ?? 0);
      
      // Overall validation status
      $validation['valid'] = 
        $validation['exists'] &&
        $validation['has_taxonomy_column'] &&
        $validation['has_metadata_column'] &&
        $validation['has_content_column'] &&
        $validation['has_embedding_column'];
      
    } catch (\Exception $e) {
      $validation['errors'][] = $e->getMessage();
      $validation['valid'] = false;
    }
    
    return $validation;
  }

  /**
   * Install security events tables for RAG system
   * 
   * Creates tables for security event logging and configuration:
   * - rag_security_events: Comprehensive security event logging
   * - rag_security_config: Security configuration and thresholds
   * 
   * @return void
   */
  private static function installSecurityTables(): void
  {
    $CLICSHOPPING_Db = Registry::get('Db');

    // Check if rag_security_events table exists
    $Qcheck = $CLICSHOPPING_Db->query('show tables like ":table_rag_security_events"');

    if ($Qcheck->fetch() === false) {
      $sql = <<<EOD
      CREATE TABLE IF NOT EXISTS :table_rag_security_events (
        `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Primary key - auto-incremented unique identifier',
        `event_id` VARCHAR(255) NOT NULL COMMENT 'Unique event identifier (UUID)',
        `event_type` ENUM(
          'threat_detected',
          'threat_blocked', 
          'false_positive',
          'security_check_passed',
          'security_check_failed',
          'pattern_fallback',
          'llm_unavailable',
          'response_validation_failed',
          'leakage_detected',
          'grounding_failed',
          'query_allowed',
          'query_blocked',
          'security_fallback',
          'layer_performance',
          'test_event'
        ) NOT NULL COMMENT 'Type of security event',
        `severity` ENUM('low', 'medium', 'high', 'critical') NOT NULL DEFAULT 'medium' COMMENT 'Event severity level',
        `threat_type` VARCHAR(100) DEFAULT NULL COMMENT 'Type of threat: instruction_override, exfiltration, hallucination, etc.',
        `threat_score` DECIMAL(5,2) DEFAULT NULL COMMENT 'Threat score (0.00-1.00)',
        `confidence` DECIMAL(5,2) DEFAULT NULL COMMENT 'Detection confidence (0.00-1.00)',
        `user_query` TEXT NOT NULL COMMENT 'Original user query that triggered the event',
        `query_language` VARCHAR(10) DEFAULT 'en' COMMENT 'Language of the query (en, fr, es, de)',
        `query_hash` VARCHAR(64) DEFAULT NULL COMMENT 'MD5 hash of query for deduplication',
        `detection_method` ENUM('llm_semantic', 'pattern_based', 'response_validation', 'hybrid') NOT NULL COMMENT 'Method used for detection',
        `detection_layer` VARCHAR(50) DEFAULT NULL COMMENT 'Security layer that detected: SemanticSecurityAnalyzer, PatternSecurityDetector, etc.',
        `matched_patterns` JSON DEFAULT NULL COMMENT 'Patterns that matched (if pattern-based)',
        `llm_reasoning` TEXT DEFAULT NULL COMMENT 'LLM reasoning for the detection (if LLM-based)',
        `action_taken` ENUM('blocked', 'allowed', 'flagged', 'logged_only', 'fallback_triggered', 'layer_executed', 'layer_failed', 'test') NOT NULL DEFAULT 'logged_only' COMMENT 'Action taken on the event',
        `blocked` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 if query was blocked, 0 if allowed',
        `response_generated` TEXT DEFAULT NULL COMMENT 'Response that was generated (if any)',
        `response_blocked` TINYINT(1) DEFAULT 0 COMMENT '1 if response was blocked due to validation failure',
        `user_id` VARCHAR(255) DEFAULT NULL COMMENT 'User ID who triggered the event',
        `session_id` VARCHAR(255) DEFAULT NULL COMMENT 'Session identifier',
        `ip_address` VARCHAR(45) DEFAULT NULL COMMENT 'IP address (IPv4 or IPv6)',
        `user_agent` TEXT DEFAULT NULL COMMENT 'User agent string',
        `interaction_id` INT DEFAULT NULL COMMENT 'FK to rag_interactions (managed by code)',
        `request_type` VARCHAR(50) DEFAULT NULL COMMENT 'Type of request: analytics, semantic, hybrid, web_search',
        `agent_used` VARCHAR(50) DEFAULT NULL COMMENT 'Agent that processed: orchestrator, analytics_agent, semantic_agent',
        `detection_time_ms` INT DEFAULT NULL COMMENT 'Time taken for detection in milliseconds',
        `total_processing_time_ms` INT DEFAULT NULL COMMENT 'Total processing time in milliseconds',
        `metadata` JSON DEFAULT NULL COMMENT 'Additional metadata in JSON format',
        `context` JSON DEFAULT NULL COMMENT 'Additional context about the event',
        `error_message` TEXT DEFAULT NULL COMMENT 'Error message if detection failed',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Event creation timestamp',
        `date_added` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Alias for created_at (consistency with other tables)',
        `expires_at` DATETIME DEFAULT NULL COMMENT 'Expiration date for automatic cleanup (90 days default)',
        `archived` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 if event has been archived',
        PRIMARY KEY (`id`),
        UNIQUE KEY `idx_event_id` (`event_id`),
        KEY `idx_event_type` (`event_type`),
        KEY `idx_severity` (`severity`),
        KEY `idx_threat_type` (`threat_type`),
        KEY `idx_threat_score` (`threat_score`),
        KEY `idx_detection_method` (`detection_method`),
        KEY `idx_action_taken` (`action_taken`),
        KEY `idx_blocked` (`blocked`),
        KEY `idx_user_id` (`user_id`),
        KEY `idx_session_id` (`session_id`),
        KEY `idx_interaction_id` (`interaction_id`),
        KEY `idx_created_at` (`created_at`),
        KEY `idx_expires_at` (`expires_at`),
        KEY `idx_archived` (`archived`),
        KEY `idx_query_hash` (`query_hash`),
        KEY `idx_severity_created` (`severity`, `created_at`),
        KEY `idx_event_type_created` (`event_type`, `created_at`),
        KEY `idx_user_created` (`user_id`, `created_at`),
        KEY `idx_blocked_severity` (`blocked`, `severity`),
        KEY `idx_threat_type_score` (`threat_type`, `threat_score`),
        KEY `idx_detection_method_created` (`detection_method`, `created_at`),
        KEY `idx_archived_expires` (`archived`, `expires_at`),
        FULLTEXT KEY `ft_user_query` (`user_query`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      COMMENT='Security events log for RAG system - tracks all security-related events with 90-day retention';
EOD;
      $CLICSHOPPING_Db->exec($sql);
    }

    // Check if rag_security_config table exists
    $Qcheck = $CLICSHOPPING_Db->query('show tables like ":table_rag_security_config"');

    if ($Qcheck->fetch() === false) {
      $sql = <<<EOD
      CREATE TABLE IF NOT EXISTS :table_rag_security_config (
        `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Primary key',
        `config_key` VARCHAR(100) NOT NULL UNIQUE COMMENT 'Configuration key (e.g., threat_threshold, llm_timeout)',
        `config_value` TEXT NOT NULL COMMENT 'Configuration value (JSON for complex values)',
        `config_type` ENUM('string', 'integer', 'float', 'boolean', 'json') NOT NULL DEFAULT 'string' COMMENT 'Data type of the value',
        `description` TEXT DEFAULT NULL COMMENT 'Description of the configuration',
        `category` VARCHAR(50) DEFAULT 'general' COMMENT 'Configuration category: thresholds, timeouts, features, alerting',
        `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 if configuration is active',
        `min_value` DECIMAL(10,4) DEFAULT NULL COMMENT 'Minimum allowed value (for numeric types)',
        `max_value` DECIMAL(10,4) DEFAULT NULL COMMENT 'Maximum allowed value (for numeric types)',
        `allowed_values` JSON DEFAULT NULL COMMENT 'List of allowed values (for enum-like configs)',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Creation timestamp',
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update timestamp',
        `updated_by` VARCHAR(255) DEFAULT NULL COMMENT 'User who last updated the config',
        PRIMARY KEY (`id`),
        UNIQUE KEY `idx_config_key` (`config_key`),
        KEY `idx_category` (`category`),
        KEY `idx_is_active` (`is_active`),
        KEY `idx_updated_at` (`updated_at`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      COMMENT='Security configuration for RAG system';
EOD;
      $CLICSHOPPING_Db->exec($sql);

      // Insert default configuration values
      $sql = <<<EOD
      INSERT INTO :table_rag_security_config (`config_key`, `config_value`, `config_type`, `description`, `category`, `min_value`, `max_value`) VALUES
        ('threat_threshold', '0.7', 'float', 'Threat score threshold for blocking (0.0-1.0)', 'thresholds', 0.0, 1.0),
        ('high_confidence_threshold', '0.9', 'float', 'High confidence threshold (0.0-1.0)', 'thresholds', 0.0, 1.0),
        ('false_positive_threshold', '0.3', 'float', 'Threshold below which to flag as potential false positive', 'thresholds', 0.0, 1.0),
        ('llm_timeout_ms', '5000', 'integer', 'LLM security analysis timeout in milliseconds', 'timeouts', 1000, 30000),
        ('pattern_timeout_ms', '100', 'integer', 'Pattern detection timeout in milliseconds', 'timeouts', 10, 1000),
        ('total_security_timeout_ms', '6000', 'integer', 'Total security check timeout in milliseconds', 'timeouts', 1000, 30000),
        ('use_llm_primary_security', 'true', 'boolean', 'Use LLM as primary security method', 'features', NULL, NULL),
        ('use_pattern_fallback', 'false', 'boolean', 'Use pattern-based detection as fallback', 'features', NULL, NULL),
        ('enable_response_validation', 'true', 'boolean', 'Enable response validation layer', 'features', NULL, NULL),
        ('log_all_queries', 'false', 'boolean', 'Log all queries (not just threats)', 'features', NULL, NULL),
        ('log_blocked_only', 'true', 'boolean', 'Log only blocked queries', 'features', NULL, NULL),
        ('log_retention_days', '90', 'integer', 'Number of days to retain security logs', 'retention', 1, 365),
        ('auto_archive_enabled', 'true', 'boolean', 'Enable automatic archiving of old logs', 'retention', NULL, NULL),
        ('email_alerts_enabled', 'false', 'boolean', 'Enable email alerts for security events', 'alerting', NULL, NULL),
        ('alert_email', '', 'string', 'Email address for security alerts', 'alerting', NULL, NULL),
        ('alert_threshold_per_hour', '10', 'integer', 'Number of threats per hour to trigger alert', 'alerting', 1, 1000),
        ('alert_on_critical_only', 'true', 'boolean', 'Only send alerts for critical severity events', 'alerting', NULL, NULL)
      ON DUPLICATE KEY UPDATE 
        `config_value` = VALUES(`config_value`),
        `updated_at` = CURRENT_TIMESTAMP;
EOD;
      $CLICSHOPPING_Db->exec($sql);
    }
  }
}