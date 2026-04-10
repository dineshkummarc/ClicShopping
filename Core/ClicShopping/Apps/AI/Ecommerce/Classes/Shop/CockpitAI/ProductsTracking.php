<?php
  /**
   *
   * @copyright 2008 - https://www.clicshopping.org
   * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
   * @Licence GPL 2 & MIT
   * @Info : https://www.clicshopping.org/forum/trademark/
   *
   */

  namespace ClicShopping\Apps\AI\Ecommerce\Classes\Shop\CockpitAI;

  use ClicShopping\OM\CLICSHOPPING;
  use ClicShopping\OM\Registry;
  use ClicShopping\Sites\Shop\BotDetector;

   /*
       CREATE TABLE IF NOT EXISTS :table_products_cockpit_ai_tracking_impressions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Primary key - unique identifier for each product impression event',
  products_id INT(11) UNSIGNED NOT NULL COMMENT 'FK to products table - product identifier',
  language_id INT(11) NOT NULL COMMENT 'FK to languages table - language identifier (context of display)',
  page_code VARCHAR(64) NOT NULL COMMENT 'Logical page identifier (index, product_info, specials, featured, favorites, search, etc.)',
  module_code VARCHAR(64) NOT NULL COMMENT 'Display module identifier (box_new_products, box_featured, box_specials, etc.), reusable across pages',
  module_position VARCHAR(10) NOT NULL COMMENT 'Position of the module can be left, right, middle, top, bottom',
  module_sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Sort order of the module inside the module (ordering index starting at 0 or 1)',

  displayed_at DATETIME NOT NULL COMMENT 'Timestamp when the product was rendered/displayed to the user',
  session_hash BINARY(16) DEFAULT NULL COMMENT 'Session identifier hashed in binary (MD5 or equivalent) for deduplication and session-level analysis',
  customer_id INT(11) DEFAULT NULL COMMENT 'FK to customers table - null if visitor is not authenticated',
  customer_group_id INT(11) DEFAULT NULL  COMMENT 'FK to customer groups - pricing/segmentation context',
  metadata JSON DEFAULT NULL  COMMENT 'Optional JSON payload (A/B test, device, source context, campaign, rendering details)',
  PRIMARY KEY (id),

  KEY idx_products_date (products_id, displayed_at)    COMMENT 'Product-level time analysis',
  KEY idx_page_module_date (page_code, module_code, displayed_at)    COMMENT 'Page and module performance tracking over time',
  KEY idx_language_date (language_id, displayed_at)    COMMENT 'Language-based segmentation',
  KEY idx_customer (customer_id)     COMMENT 'Customer-level tracking',
  KEY idx_customer_group (customer_group_id)    COMMENT 'Segmentation by customer group',
  KEY idx_session (session_hash)    COMMENT 'Session-level grouping and deduplication',
 KEY idx_module_position (page_code, module_code, module_position,module_sort_order)    COMMENT 'Analysis of product ordering inside modules'

) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci
COMMENT='Product impressions tracking - captures where and how products are displayed across pages and modules';


ALTER TABLE :table_products_cockpit_ai_tracking_impressions ADD weight DECIMAL(3,2) NOT NULL DEFAULT 0.10 AFTER module_sort_order;
ALTER TABLE `clic_products_cockpit_ai_tracking_impressions` CHANGE `weight` `weight` DECIMAL(3,2) NOT NULL DEFAULT '0.10' COMMENT 'Module weight';
ALTER TABLE :table_products_cockpit_ai_tracking_impressions ADD INDEX idx_spam_check (session_hash, products_id, module_code, displayed_at);


CREATE OR REPLACE VIEW clic_products_tracking_summary AS
SELECT
  products_id
    COMMENT 'FK to products table - unique product identifier aggregated over impressions',

  language_id
    COMMENT 'FK to languages table - language context of the impressions aggregation',

  -- Popularité pondérée avec décroissance temporelle exponentielle
  -- Favorise les impressions récentes (demi-vie ~48h) et réduit l’impact des anciennes
  SUM(weight * EXP(-TIMESTAMPDIFF(HOUR, displayed_at, NOW()) / 48))
    / (1 + LOG(COUNT(*) + 1))
    AS popularity_heat
    COMMENT 'Time-decayed weighted popularity score normalized by log(volume) to reduce exposure bias',

  COUNT(*)
    AS total_impressions
    COMMENT 'Total number of impressions over the aggregation window (7 days)',

  COUNT(DISTINCT module_code)
    AS module_spread
    COMMENT 'Number of distinct modules where the product was displayed (diversity of exposure)',

  SUM(CASE WHEN weight >= 0.5 THEN 1 ELSE 0 END)
    / NULLIF(COUNT(*), 0)
    AS high_intent_ratio
    COMMENT 'Ratio of high-intent impressions (weight >= 0.5) over total impressions',

  STDDEV(weight)
    AS weight_stddev
    COMMENT 'Standard deviation of weights - measures variability of user intent signals',

  MAX(displayed_at)
    AS last_seen_at
    COMMENT 'Most recent timestamp when the product was displayed'

FROM clic_products_cockpit_ai_tracking_impressions

WHERE displayed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
  -- Restricts aggregation to recent data for reactivity and freshness

GROUP BY products_id, language_id;





Module (Box)	Weight	Justification IA
bm_products_new	0.10	Simple nouveauté, souvent ignorée.
bm_specials	0.15	Promo, attire l'œil mais reste passif.
bm_favorites	0.15	Rappel de produits déjà aimés.
bm_featured	0.20	Mise en avant manuelle (curation).
bm_best_seller	0.25	Preuve sociale (influence forte).
bm_recommendations	0.30	Critique : Validation de la pertinence de l'IA.
bm_products_related	0.35	Contextuel (lié au produit consulté).

 pf_products_new	0.40	L'utilisateur a cliqué sur "Nouveautés".
pf_specials	0.45	Chasseur de promos actif.
pf_favorites	0.60	Fort : Consultation volontaire de sa liste.
pf_search	0.70	Très Fort : Résultat d'une recherche précise.
cl_product_listing	0.50	Navigation par catégorie (intention d'achat).

product_info	1.00	Le Graal : Consultation de la fiche détaillée.
checkout_cart	0.80	Rappel de produits dans le panier ou cross-selling.
       */

  class ProductsTracking
  {
      /**
     * ProductsTracking constructor.
     * @param $CLICSHOPPING_Db
     */
    public function __construct()
    {
    }

    /**
     * Check if the tracking system is activated
     * @return bool
     */
    private static function checkStatusProductTracking(): bool
    {
      CLICSHOPPING::checkAppsIsActivated([
        'CLICSHOPPING_APP_ECOMMERCE_CAI_STATUS',
        'CLICSHOPPING_APP_ECOMMERCE_CAI_PRODUCT_TRACKING'
      ]);

      return true;
    }

    /**
     * Record a single product impression
     * @param int $products_id
     * @param string $module_code
     * @param string $module_position
     * @param int $sort_order
     * @param int $language_id
     * @param string|array|null $metadata
     */
    public static function insertProductTracking(
      int $products_id,
      string $module_code,
      string $module_position,
      int $sort_order,
      int $language_id,
      string|array|null $metadata = null,
      ?float $weight = null
    ): void
    {
      $CLICSHOPPING_Customer = Registry::get('Customer');
      $CLICSHOPPING_Db = Registry::get('Db');

      self::checkStatusProductTracking();

      // 1. Anti-Spam Check (Cool-down 15 min)
      // Avoids artificial inflation of scores by page refreshing
      if (self::isRecentImpression($products_id, $module_code, $module_position)) {
        return;
      }

      // If a bot is detected, stop execution immediately to avoid skewed analytics
      $botDetector = new BotDetector();

      if ($botDetector->isBot()) {
        return;
      }

      $session_id = session_id() ?: 'no-session';
      $binary_hash = pack("H*", md5($session_id));

      $customer_id = $CLICSHOPPING_Customer->isLoggedOn() ? (int)$CLICSHOPPING_Customer->getID() : null;

      $customer_group_id = (int)$CLICSHOPPING_Customer->getCustomersGroupID();

      /* // GDPR Compliance - Placeholder for customer consent check
      if (defined('CONFIG_TRACKING_ALLOW_CUSTOMER_ID') && CONFIG_TRACKING_ALLOW_CUSTOMER_ID == 'True') {
           // Logic for authorized tracking
      }
      */

      // Merge weight into metadata for the IA Cockpit
      $final_metadata = is_array($metadata) ? $metadata : [];

      if (!is_null($weight)) {
        $final_metadata['w'] = $weight;
      }

      $json_metadata = !empty($final_metadata) ? json_encode(array_filter($final_metadata, fn($v) => $v !== null)) : null;

      $insert_sql_array = [
        'products_id' => $products_id,
        'language_id' => $language_id,
        'page_code' =>  self::pageCode(['page_code' => null]),
        'displayed_at' => 'now()',
        'session_hash' => $binary_hash,
        'customer_id' => $customer_id,
        'customer_group_id' => $customer_group_id,
        'module_code' => $module_code,
        'module_position' => $module_position,
        'module_sort_order' => $sort_order,
        'metadata' => $json_metadata,
        'weight' => $weight
      ];

      $CLICSHOPPING_Db->save('products_cockpit_ai_tracking_impressions', $insert_sql_array);

      // Maintain Circular Buffer (FIFO)
      self::applyCircularQuota($module_code);
    }

    /**
     * Check if the product was already tracked in this session recently
     * Prevents F5/Refresh from skewing IA data
     */
    private static function isRecentImpression(int $products_id, string $module_code, string $module_position): bool
    {
      $CLICSHOPPING_Db = Registry::get('Db');
      $session_id = session_id() ?: 'no-session';
      $binary_hash = pack("H*", md5($session_id));

      $Qcheck = $CLICSHOPPING_Db->prepare('SELECT id 
                                           FROM :table_products_cockpit_ai_tracking_impressions 
                                           WHERE products_id = :products_id 
                                           AND module_code = :module_code 
                                           AND session_hash = :hash 
                                           AND module_position = :module_position
                                           AND displayed_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
                                           LIMIT 1');
      $Qcheck->bindInt(':products_id', $products_id);
      $Qcheck->bindValue(':module_position', $module_position);
      $Qcheck->bindValue(':module_code', $module_code);
      $Qcheck->bindValue(':hash', $binary_hash);

      $Qcheck->execute();

      return ($Qcheck->fetch() !== false);
    }

    /**
     * Dynamic determination of the page code with multiple fallbacks
     * @param array $data
     * @return string
     */
    private static function pageCode(array $data = []): string
    {
      // 1. Priority: Manually forced value from the module call
      if (!empty($data['page_code'])) {
        return (string)$data['page_code'];
      }

      // 2. URL Analysis ($_GET): Highly reliable as it's available early in the lifecycle
      if (isset($_GET) && !empty($_GET)) {
        $keys = array_keys($_GET);
        $first_key = $keys[0];

        // Sanitize key to avoid technical indexes like index_php
        if (!empty($first_key) && $first_key !== 'index_php' && !str_contains($first_key, '/')) {
          return (string)basename($first_key);
        }
      }

      // 3. Registry Attempt: If the Site object is already initialized
      try {
        if (Registry::exists('Site')) {
          $site = Registry::get('Site');
          if (method_exists($site, 'getPage') && $site->getPage() !== null) {
            $page_code = $site->getPage()->getCode();
            if (!empty($page_code)) {
              return (string)$page_code;
            }
          }
        }
      } catch (\Exception $e) {
        // Optional: error_log('Tracking PageCode Registry fail: ' . $e->getMessage());
      }

      // 4. Final Fallback: Ensures a value exists for MySQL NOT NULL constraint
      return 'index';
    }

    /**
     * Maintain a fixed number of rows per module (FIFO logic)
     * Ensures the IA Cockpit works with fresh data and prevents DB bloating
     * @param string $module_code
     */
    private static function applyCircularQuota(string $module_code): void
    {
      $CLICSHOPPING_Db = Registry::get('Db');

      // Define the maximum number of records to keep per module
      // This could be moved to a configuration constant later
      $quota = 1000;

      /** * Optimization: We trigger the deletion logic only 10% of the time (randomly)
       * to avoid performing a sub-query and a DELETE on every single impression.
       */
      if (random_int(1, 10) === 5) {
        /**
         * We look for the ID of the N-th record (the quota limit).
         * If an ID exists at this offset, it means we have exceeded the quota.
         */
        $Qcheck = $CLICSHOPPING_Db->prepare('SELECT id 
                                               FROM :table_products_cockpit_ai_tracking_impressions 
                                               WHERE module_code = :module_code 
                                               ORDER BY id DESC 
                                               LIMIT 1 OFFSET :offset');
        $Qcheck->bindValue(':module_code', $module_code);
        $Qcheck->bindInt(':offset', $quota);
        $Qcheck->execute();

        $result = $Qcheck->fetch();

        if ($result !== false) {
          $threshold_id = (int)$result['id'];

          // Delete everything older or equal to this threshold ID
          $Qdel = $CLICSHOPPING_Db->prepare('DELETE FROM :table_products_cockpit_ai_tracking_impressions 
                                             WHERE module_code = :module_code 
                                             AND id <= :threshold_id
                                             ');
          $Qdel->bindValue(':module_code', $module_code);
          $Qdel->bindInt(':threshold_id', $threshold_id);
          $Qdel->execute();
        }
      }
    }

    /**
     * Insert multiple rows (batch processing compatible with save())
     * @param array $rows
     */
    public static function insertMultiple(array $rows): void
    {
      if (!self::checkStatusProductTracking()) {
        return;
      }

      $CLICSHOPPING_Customer = Registry::get('Customer');
      $CLICSHOPPING_Db = Registry::get('Db');

      $botDetector = new BotDetector();
      if ($botDetector->isBot()) {
        return;
      }

      $session_id = session_id() ?: 'no-session';
      $binary_hash = pack("H*", md5($session_id));

      $customer_id = $CLICSHOPPING_Customer->isLoggedOn() ? (int)$CLICSHOPPING_Customer->getID() : null;

      $customer_group_id = (int)$CLICSHOPPING_Customer->getCustomersGroupID();

      foreach ($rows as $row) {
        if (!isset(
          $row['products_id'],
          $row['module_code'],
          $row['module_position'],
          $row['module_sort_order'],
          $row['language_id']
        )) {
          continue;
        }

        if (self::isRecentImpression(
          (int)$row['products_id'],
          (string)$row['module_code'],
          (string)$row['module_position']
        )) {
          continue;
        }

        $metadata = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
        $weight = isset($row['weight']) ? (float)$row['weight'] : null;

        if (!is_null($weight)) {
          $metadata['w'] = $weight;
        }

        $insert_sql_array = [
          'products_id' => (int)$row['products_id'],
          'language_id' => (int)$row['language_id'],
          'page_code' => self::pageCode(['page_code' => $row['page_code'] ?? null]),
          'displayed_at' => 'now()',
          'session_hash' => $binary_hash,
          'customer_id' => $customer_id,
          'customer_group_id' => $customer_group_id,
          'module_code' => (string)$row['module_code'],
          'module_position' => (string)$row['module_position'],
          'module_sort_order' => (int)$row['module_sort_order'],
          'metadata' => !empty($metadata) ? json_encode($metadata) : null,
          'weight' => $weight
        ];

        $CLICSHOPPING_Db->save(':table_products_cockpit_ai_tracking_impressions', $insert_sql_array);
      }
    }

    /**
     * Analytics - Retrieve product impression count
     * @param int $productId
     * @param int $days
     * @return int
     */
    public static function getProductImpressions(int $productId, int $days = 30): int
    {
      $CLICSHOPPING_Db = Registry::get('Db');

      $Q = $CLICSHOPPING_Db->prepare('SELECT COUNT(*) AS total
                                     FROM :table_products_cockpit_ai_tracking_impressions
                                     WHERE products_id = :products_id
                                     AND displayed_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
                                     ');
      $Q->bindInt(':products_id', $productId);
      $Q->bindInt(':days', $days);
      $Q->execute();

      return (int)$Q->valueInt('total');
    }

    /**
     * Analytics - Stats grouped by page and module
     * @param int $days
     * @return array
     */
    public static function getStatsByPageModule(int $days = 30): array
    {
      $CLICSHOPPING_Db = Registry::get('Db');

      $Q = $CLICSHOPPING_Db->prepare('SELECT page_code, 
                                             module_code, 
                                             COUNT(*) AS impressions
                                      FROM :table_products_cockpit_ai_tracking_impressions
                                      WHERE displayed_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
                                      GROUP BY page_code, module_code
                                      ORDER BY impressions DESC
                                      ');
      $Q->bindInt(':days', $days);
      $Q->execute();

      return $Q->fetchAll();
    }

    /**
     * Analytics - Top performing products
     * @param int $limit
     * @param int $days
     * @return array
     */
    public static function getTopProducts(int $limit = 20, int $days = 30): array
    {
      $CLICSHOPPING_Db = Registry::get('Db');

      $Q = $CLICSHOPPING_Db->prepare('SELECT products_id, COUNT(*) AS impressions
                                       FROM :table_products_cockpit_ai_tracking_impressions
                                       WHERE displayed_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
                                       GROUP BY products_id
                                       ORDER BY impressions DESC
                                       LIMIT :limit
                                       ');
      $Q->bindInt(':days', $days);
      $Q->bindInt(':limit', $limit);
      $Q->execute();

      return $Q->fetchAll();
    }

    /**
     * Analytics - Average display position
     * @param int $productId
     * @param int $days
     * @return float
     */
    public static function getAveragePosition(int $productId, int $days = 30): float
    {
      $CLICSHOPPING_Db = Registry::get('Db');

      $Q = $CLICSHOPPING_Db->prepare('SELECT AVG(module_sort_order) AS avg_position
                                       FROM :table_products_cockpit_ai_tracking_impressions
                                       WHERE products_id = :products_id
                                       AND displayed_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
                             ');
      $Q->bindInt(':products_id', $productId);
      $Q->bindInt(':days', $days);
      $Q->execute();

      return (float)$Q->value('avg_position');
    }
  }