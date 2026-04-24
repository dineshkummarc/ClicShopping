<?php
/**
 * ProductsRecommendations
 *
 * Service centralisé de recommandations produits pour ClicShopping.
 * Gère trois contextes : product, cart, checkout, home
 * Gère trois modes    : cas1 (co-achat global), cas2 (historique perso), fallback (bestsellers)
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 */

namespace ClicShopping\Apps\AI\Ecommerce\Classes\Shop\Products;

use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\Gpt;
use ClicShopping\OM\CLICSHOPPING;
use ClicShopping\OM\Registry;

class ProductsRecommendations
{
  // Seuil minimum de commandes pour activer le Cas 2 (historique perso)
  const MIN_ORDERS_FOR_PERSONAL = 3;

  // Seuil anti-clone par défaut si $cosinus non fourni (évite les substituts trop proches)
  const MIN_COSINE_DISTANCE = 0.15;

  // Score de co-occurrence minimal pour le Cas 2 (filtre les paires trop faibles)
  const MIN_RELEVANCE_SCORE = 2;
    public function __construct() {
    }

    /*
   * Vérifie que toutes les apps nécessaires sont actives.
   * Appelé en début de get() — centralise la garde pour tous les modules.
   *
   * @return bool  true = tout est actif, false = au moins une app manquante
   */
  public function checkStatus(): bool
  {
    $requiredConstants = [
      'CLICSHOPPING_APP_ECOMMERCE_CAI_STATUS',
      'CLICSHOPPING_APP_ECOMMERCE_EC_STATUS',
      'CLICSHOPPING_APP_CHATGPT_RA_OPENAI_EMBEDDING',
      'CLICSHOPPING_APP_CHATGPT_RA_STATUS',
    ];

    $result = CLICSHOPPING::checkAppsIsActivated($requiredConstants);

    if ($result === false) {
      return false;
    }

    if (!Gpt::checkGptStatus()) {
      return false;
    }

    return true;
  }


  /**
   * Appelé depuis n'importe quelle page via Registry::get('ProductsRecommendations')->get([...])
   *
   * @param array $params [
   *   'context'      => string   'product'|'cart'|'checkout'|'home'
   *   'product_ids'  => array    liste des products_id de référence
   *   'customer_id'  => int|null null si invité
   *   'group_id'     => int      0 = B2C, >0 = B2B
   *   'language_id'  => int
   *   'limit'        => int
   *   'cosinus'      => float    seuil cosinus configuré dans le module (ex: 0.35)
   * ]
   * @return array  liste de products_id recommandés (ordonnés par score)
   */
  public function get(array $params): array
  {
    // Vérification centralisée : si une app requise est inactive → retour vide
    if (!$this->checkStatus()) {
      return [];
    }

    $customer_id = $params['customer_id'] ?? null;
    $group_id    = $params['group_id']    ?? 0;
    $language_id = $params['language_id'] ?? 1;
    $limit       = $params['limit']       ?? 6;
    $product_ids = $params['product_ids'] ?? [];
    $cosinus     = $params['cosinus']     ?? self::MIN_COSINE_DISTANCE;

    if (empty($product_ids)) {
      return [];
    }

    // --- Routage Cas 1 / Cas 2 ---
    $mode = self::resolveMode($customer_id);

    if ($mode === 'personal') {
      // Cas 2 : recommandations basées sur l'historique du client
      $results = self::getPersonalRecommendations(
        $customer_id, $product_ids, $group_id, $language_id, $limit, $cosinus
      );

      // Résultats insuffisants → fallback Cas 1
      if (count($results) < max(1, (int)($limit / 2))) {
        $results = self::getComplementaryRecommendations(
          $product_ids, $group_id, $language_id, $limit, $cosinus
        );
      }
    } else {
      // Cas 1 : co-achat global (invité ou historique trop court)
      $results = self::getComplementaryRecommendations(
        $product_ids, $group_id, $language_id, $limit, $cosinus
      );
    }

    // Dernier recours : bestsellers filtrés par groupe et langue
    if (empty($results)) {
      $results = self::getFallbackBestsellers($product_ids, $group_id, $language_id, $limit);
    }

    return $results;
  }

  // -------------------------------------------------------------------------
  // Résolution du mode
  // -------------------------------------------------------------------------

  /**
   * Détermine si on utilise le Cas 1 ou le Cas 2.
   */
  private static function resolveMode(?int $customer_id): string
  {
    if ($customer_id === null) {
      return 'complementary'; // Invité → Cas 1
    }

    $orderCount = self::countCustomerOrders($customer_id);

    return ($orderCount >= self::MIN_ORDERS_FOR_PERSONAL)
      ? 'personal'       // Cas 2
      : 'complementary'; // Cas 1 (connecté mais historique trop court)
  }

  /**
   * Compte les commandes validées d'un client.
   */
  private static function countCustomerOrders(int $customer_id): int
  {
    $CLICSHOPPING_Db = Registry::get('Db');

    $Q = $CLICSHOPPING_Db->prepare('
      SELECT COUNT(DISTINCT orders_id)
      FROM :table_orders
      WHERE customers_id = :customers_id
        AND orders_status >= 2
    ');
    $Q->bindInt(':customers_id', $customer_id);
    $Q->execute();

    return (int)$Q->fetchColumn();
  }

  // -------------------------------------------------------------------------
  // CAS 1 : Complémentarité globale (co-achat + score hybride)
  // -------------------------------------------------------------------------

  /**
   * $cosinus est utilisé comme seuil anti-clone :
   * seuls les produits dont la distance cosinus > $cosinus sont retenus.
   * Plus $cosinus est élevé, plus on exclut de produits similaires.
   */
  private static function getComplementaryRecommendations(
    array $product_ids,
    int   $group_id,
    int   $language_id,
    int   $limit,
    float $cosinus   // ← utilisé dans le SQL via :min_cosine
  ): array {
    $CLICSHOPPING_Db = Registry::get('Db');

    $placeholders = implode(',', array_map('intval', $product_ids));
    $primary_id   = (int)$product_ids[0];

    if ($group_id == 0) {
      // ---- B2C ----
      $sql = "
        WITH target AS (
          SELECT pe.embedding, p.products_price
          FROM :table_products_embedding pe
          JOIN :table_products p ON p.products_id = pe.entity_id
          WHERE pe.entity_id = :primary_id
            AND pe.language_id = :language_id
          LIMIT 1
        )

        SELECT DISTINCT
          p.products_id,
          VEC_DISTANCE_COSINE(pe.embedding, t.embedding) AS dist,
          COALESCE(pc.score, 0)                          AS complementarity,
          (
            0.15 * (1 - VEC_DISTANCE_COSINE(pe.embedding, t.embedding)) +
            0.55 * COALESCE(pc.score, 0) +
            0.10 * LOG(1 + p.products_ordered) +
            0.10 * p.products_price -
            0.30 * LEAST(1, p.products_price / NULLIF(t.products_price, 0))
          ) AS score

        FROM :table_products p
        JOIN :table_products_embedding pe ON pe.entity_id = p.products_id
          AND pe.language_id = :language_id
        JOIN :table_products_to_categories p2c ON p.products_id = p2c.products_id
        JOIN :table_categories c ON p2c.categories_id = c.categories_id
        CROSS JOIN target t
        LEFT JOIN :table_products_cooccurrence pc
          ON pc.product_id IN ({$placeholders})
         AND pc.related_id = p.products_id

        WHERE p.products_status = 1
          AND p.products_archive = 0
          AND p.products_view = 1
          AND p.products_id NOT IN ({$placeholders})
          AND c.status = 1

          -- Exclusion stricte des substituts (même catégorie)
          AND p2c.categories_id NOT IN (
            SELECT p2c2.categories_id
            FROM :table_products_to_categories p2c2
            WHERE p2c2.products_id IN ({$placeholders})
          )

          -- Anti-clone : seuil cosinus configuré dans le module
          AND VEC_DISTANCE_COSINE(pe.embedding, t.embedding) > :min_cosine

        ORDER BY complementarity DESC, score DESC
        LIMIT :limit
      ";
    } else {
      // ---- B2B ----
      $sql = "
        WITH target AS (
          SELECT pe.embedding, p.products_price
          FROM :table_products_embedding pe
          JOIN :table_products p ON p.products_id = pe.entity_id
          WHERE pe.entity_id = :primary_id
            AND pe.language_id = :language_id
          LIMIT 1
        )

        SELECT DISTINCT
          p.products_id,
          VEC_DISTANCE_COSINE(pe.embedding, t.embedding) AS dist,
          COALESCE(pc.score, 0)                          AS complementarity,
          (
            0.15 * (1 - VEC_DISTANCE_COSINE(pe.embedding, t.embedding)) +
            0.55 * COALESCE(pc.score, 0) +
            0.10 * LOG(1 + p.products_ordered) +
            0.10 * p.products_price -
            0.30 * LEAST(1, p.products_price / NULLIF(t.products_price, 0))
          ) AS score

        FROM :table_products p
        JOIN :table_products_groups g ON p.products_id = g.products_id
        JOIN :table_products_embedding pe ON pe.entity_id = p.products_id
          AND pe.language_id = :language_id
        JOIN :table_products_to_categories p2c ON p.products_id = p2c.products_id
        JOIN :table_categories c ON p2c.categories_id = c.categories_id
        CROSS JOIN target t
        LEFT JOIN :table_products_cooccurrence pc
          ON pc.product_id IN ({$placeholders})
         AND pc.related_id = p.products_id

        WHERE p.products_status = 1
          AND p.products_archive = 0
          AND p.products_view = 1
          AND g.customers_group_id = :customers_group_id
          AND g.products_group_view = 1
          AND g.price_group_view = 1
          AND p.products_id NOT IN ({$placeholders})
          AND c.status = 1

          AND p2c.categories_id NOT IN (
            SELECT p2c2.categories_id
            FROM :table_products_to_categories p2c2
            WHERE p2c2.products_id IN ({$placeholders})
          )

          AND VEC_DISTANCE_COSINE(pe.embedding, t.embedding) > :min_cosine

        ORDER BY complementarity DESC, score DESC
        LIMIT :limit
      ";
    }

    $Q = $CLICSHOPPING_Db->prepare($sql);
    $Q->bindInt(':primary_id',   $primary_id);
    $Q->bindInt(':language_id',  $language_id);
    $Q->bindInt(':limit',        $limit);
    $Q->bindDecimal(':min_cosine', $cosinus);  // ← $cosinus du module, pas la constante

    if ($group_id != 0) {
      $Q->bindInt(':customers_group_id', $group_id);
    }

    $Q->execute();

    $results = [];
    while ($Q->fetch()) {
      $results[] = $Q->valueInt('products_id');
    }

    return $results;
  }

  // -------------------------------------------------------------------------
  // CAS 2 : Historique personnel
  // -------------------------------------------------------------------------

  /**
   * $cosinus sert ici de seuil sur relevance_score normalisé :
   * on filtre les paires produits dont la co-occurrence est trop faible.
   * MIN_RELEVANCE_SCORE est le seuil absolu minimum (au moins 2 co-achats).
   */
  private static function getPersonalRecommendations(
    int   $customer_id,
    array $product_ids,
    int   $group_id,
    int   $language_id,
    int   $limit,
    float $cosinus   // ← utilisé comme multiplicateur du seuil MIN_RELEVANCE_SCORE
  ): array {
    $CLICSHOPPING_Db = Registry::get('Db');

    $placeholders = implode(',', array_map('intval', $product_ids));

    // Seuil de relevance adaptatif : plus $cosinus est élevé, plus on est sélectif
    $min_relevance = max(self::MIN_RELEVANCE_SCORE, (int)round($cosinus * 10));

    if ($group_id == 0) {
      // ---- B2C ----
      $sql = "
        SELECT DISTINCT
          op_rec.products_id,
          COUNT(*) AS relevance_score

        FROM :table_orders o_ref
        JOIN :table_orders_products op_ref ON o_ref.orders_id = op_ref.orders_id

        -- Clients ayant acheté les mêmes produits
        JOIN :table_orders_products op_similar
          ON op_ref.products_id = op_similar.products_id
         AND op_similar.orders_id != o_ref.orders_id

        -- Ce que ces clients similaires ont aussi acheté
        JOIN :table_orders_products op_rec ON op_similar.orders_id = op_rec.orders_id

        JOIN :table_products p ON p.products_id = op_rec.products_id
        JOIN :table_products_to_categories p2c ON p.products_id = p2c.products_id
        JOIN :table_categories c ON p2c.categories_id = c.categories_id

        WHERE o_ref.customers_id = :customer_id
          AND p.products_status = 1
          AND p.products_archive = 0
          AND p.products_view = 1
          AND c.status = 1

          -- Exclure ce que le client a déjà acheté
          AND op_rec.products_id NOT IN (
            SELECT op2.products_id
            FROM :table_orders o2
            JOIN :table_orders_products op2 ON o2.orders_id = op2.orders_id
            WHERE o2.customers_id = :customer_id
          )

          AND op_rec.products_id NOT IN ({$placeholders})

          AND p2c.categories_id NOT IN (
            SELECT p2c2.categories_id
            FROM :table_products_to_categories p2c2
            WHERE p2c2.products_id IN ({$placeholders})
          )

        GROUP BY op_rec.products_id
        HAVING relevance_score >= :min_relevance
        ORDER BY relevance_score DESC
        LIMIT :limit
      ";
    } else {
      // ---- B2B ----
      $sql = "
        SELECT DISTINCT
          op_rec.products_id,
          COUNT(*) AS relevance_score

        FROM :table_orders o_ref
        JOIN :table_orders_products op_ref ON o_ref.orders_id = op_ref.orders_id

        JOIN :table_orders_products op_similar
          ON op_ref.products_id = op_similar.products_id
         AND op_similar.orders_id != o_ref.orders_id

        JOIN :table_orders_products op_rec ON op_similar.orders_id = op_rec.orders_id

        JOIN :table_products p ON p.products_id = op_rec.products_id
        JOIN :table_products_groups g ON p.products_id = g.products_id
        JOIN :table_products_to_categories p2c ON p.products_id = p2c.products_id
        JOIN :table_categories c ON p2c.categories_id = c.categories_id

        WHERE o_ref.customers_id = :customer_id
          AND p.products_status = 1
          AND p.products_archive = 0
          AND p.products_view = 1
          AND g.customers_group_id = :customers_group_id
          AND g.products_group_view = 1
          AND g.price_group_view = 1
          AND c.status = 1

          AND op_rec.products_id NOT IN (
            SELECT op2.products_id
            FROM :table_orders o2
            JOIN :table_orders_products op2 ON o2.orders_id = op2.orders_id
            WHERE o2.customers_id = :customer_id
          )

          AND op_rec.products_id NOT IN ({$placeholders})

          AND p2c.categories_id NOT IN (
            SELECT p2c2.categories_id
            FROM :table_products_to_categories p2c2
            WHERE p2c2.products_id IN ({$placeholders})
          )

        GROUP BY op_rec.products_id
        HAVING relevance_score >= :min_relevance
        ORDER BY relevance_score DESC
        LIMIT :limit
      ";
    }

    $Q = $CLICSHOPPING_Db->prepare($sql);
    $Q->bindInt(':customer_id',   $customer_id);
    $Q->bindInt(':limit',         $limit);
    $Q->bindInt(':min_relevance', $min_relevance);

    if ($group_id != 0) {
      $Q->bindInt(':customers_group_id', $group_id);
    }

    $Q->execute();

    $results = [];
    while ($Q->fetch()) {
      $results[] = $Q->valueInt('products_id');
    }

    return $results;
  }

  // -------------------------------------------------------------------------
  // FALLBACK : Bestsellers filtrés par groupe et langue
  // -------------------------------------------------------------------------

  /**
   * Dernier recours si Cas 1 et Cas 2 ne retournent rien.
   * $group_id  → filtre B2B via products_groups
   * $language_id → joint products_description pour cohérence langue
   */
  private static function getFallbackBestsellers(
    array $product_ids,
    int   $group_id,    // ← désormais utilisé pour le filtre B2B
    int   $language_id, // ← désormais utilisé pour le join description
    int   $limit
  ): array {
    $CLICSHOPPING_Db = Registry::get('Db');

    $placeholders = implode(',', array_map('intval', $product_ids));

    if ($group_id == 0) {
      // ---- B2C ----
      $sql = "
        SELECT DISTINCT p.products_id
        FROM :table_products p
        JOIN :table_products_description pd ON p.products_id = pd.products_id
          AND pd.language_id = :language_id
        JOIN :table_products_to_categories p2c ON p.products_id = p2c.products_id
        JOIN :table_categories c ON p2c.categories_id = c.categories_id

        WHERE p.products_status = 1
          AND p.products_archive = 0
          AND p.products_view = 1
          AND c.status = 1
          AND p.products_id NOT IN ({$placeholders})

          AND p2c.categories_id NOT IN (
            SELECT p2c2.categories_id
            FROM :table_products_to_categories p2c2
            WHERE p2c2.products_id IN ({$placeholders})
          )

        ORDER BY p.products_ordered DESC
        LIMIT :limit
      ";
    } else {
      // ---- B2B ----
      $sql = "
        SELECT DISTINCT p.products_id
        FROM :table_products p
        JOIN :table_products_description pd ON p.products_id = pd.products_id
          AND pd.language_id = :language_id
        JOIN :table_products_groups g ON p.products_id = g.products_id
        JOIN :table_products_to_categories p2c ON p.products_id = p2c.products_id
        JOIN :table_categories c ON p2c.categories_id = c.categories_id

        WHERE p.products_status = 1
          AND p.products_archive = 0
          AND p.products_view = 1
          AND g.customers_group_id = :customers_group_id
          AND g.products_group_view = 1
          AND g.price_group_view = 1
          AND c.status = 1
          AND p.products_id NOT IN ({$placeholders})

          AND p2c.categories_id NOT IN (
            SELECT p2c2.categories_id
            FROM :table_products_to_categories p2c2
            WHERE p2c2.products_id IN ({$placeholders})
          )

        ORDER BY p.products_ordered DESC
        LIMIT :limit
      ";
    }

    $Q = $CLICSHOPPING_Db->prepare($sql);
    $Q->bindInt(':language_id', $language_id);
    $Q->bindInt(':limit',       $limit);

    if ($group_id != 0) {
      $Q->bindInt(':customers_group_id', $group_id);
    }

    $Q->execute();

    $results = [];
    while ($Q->fetch()) {
      $results[] = $Q->valueInt('products_id');
    }

    return $results;
  }

  // -------------------------------------------------------------------------
  // Utilitaire : SQL installation table co-occurrence
  // -------------------------------------------------------------------------

  /**
   * Retourne le SQL de création + peuplement de la table co-occurrence.
   * À exécuter via un script d'installation ou un cron — jamais dans le constructeur.
   */
  public static function insertCooccurrence(): void
  {
    $CLICSHOPPING_Db = Registry::get('Db');

    $Qproducts = $CLICSHOPPING_Db->prepare("INSERT INTO :table_products_cooccurrence (product_id, related_id, score)
                                            SELECT
                                              a.products_id,
                                              b.products_id,
                                              COUNT(*) AS score
                                            FROM orders_products a
                                            JOIN orders_products b
                                              ON a.orders_id = b.orders_id
                                             AND a.products_id != b.products_id
                                            GROUP BY a.products_id, b.products_id
                                            ON DUPLICATE KEY UPDATE score = VALUES(score)
                                        ");

    $Qproducts->execute();
  }
}
