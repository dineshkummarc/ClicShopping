<?php
namespace ClicShopping\Apps\Configuration\ChatGpt\Classes\Rag;

use ClicShopping\OM\Registry;
use Doctrine\DBAL\Connection;

/**
 * EmbeddedTableManager Class
 *
 * Cette classe gère les requêtes spécifiques aux tables embarquées (embedded)
 * pour les analyses numériques et statistiques dans un contexte e-commerce.
 */
class EmbeddedTableManager
{
  //private Connection $connection;
  private array $embeddedTables;
  private array $tableRelations;
  private mixed $db;
  /**
   * Constructeur pour EmbeddedTableManager
   */
  public function __construct()
  {
    $entityManager = DoctrineOrm::getEntityManager();
    //$this->connection = $entityManager->getConnection();
    $this->db = Registry::get('Db');

    // Définition des tables embarquées à analyser
    $this->embeddedTables = [
      'products' => [
        'table' => 'products',
        'id_column' => 'products_id',
        'numeric_columns' => ['products_price', 'products_quantity', 'products_ordered'],
        'reference_columns' => ['products_model', 'products_sku', 'products_ean']
      ],
      'products_description' => [
        'table' => 'products_description',
        'id_column' => 'products_id',
        'parent_table' => 'products',
        'language_column' => 'language_id'
      ],
      'orders' => [
        'table' => 'orders_total', //orders
        'id_column' => 'value',
        'numeric_columns' => ['orders_id']
      ],
      'categories' => [
        'table' => 'categories',
        'id_column' => 'categories_id'
      ],
      'categories_description' => [
        'table' => 'categories_description',
        'id_column' => 'categories_id',
        'parent_table' => 'categories',
        'language_column' => 'language_id'
      ]
    ];

    // Définition des relations entre tables
    $this->tableRelations = [
      'products' => [
        'products_description' => 'products_id',
        'products_to_categories' => 'products_id'
      ],
      'categories' => [
        'categories_description' => 'categories_id',
        'products_to_categories' => 'categories_id'
      ]
    ];
  }

  /**
   * Recherche par référence produit (SKU, EAN, Model)
   */
  public function searchByReference(string $reference): array
  {
    $CLICSHOPPING_Language = Registry::get('Language');
    $query = $this->db->prepare('SELECT distinct pd.products_name,
                                                 pd.products_description,
                                                  p.products_id,
                                                 p.products_model,
                                                 p.products_sku,
                                                 p.products_ean,
                                                 p.products_quantity
                                     FROM :table_products p,
                                          :table_products_description pd
                                     WHERE (products_model = :reference 
                                        OR products_sku = :reference 
                                        OR products_ean = :reference)
                                     and pd.language_id = :language_id
                                     limit 1
                                 ');

    $query->bindValue('reference', $reference);
    $query->bindInt('language_id', $CLICSHOPPING_Language->getId());
    $query->execute();
    $results = $query->fetchAll();

    return $this->formatResults($results, 'products');
  }


  /**
   * Recherche le niveau d'alerte de stock pour un produit spécifique par référence
   */
  public function getStockAlertByReference(string $reference): array
  {
    $CLICSHOPPING_Language = Registry::get('Language');

    $query = $this->db->prepare('SELECT distinct pd.products_name,
                                                  pd.products_description,
                                                  p.products_id,
                                                  p.products_model,
                                                  p.products_sku,
                                                  p.products_ean,
                                                  p.products_quantity,
                                                  p.products_quantity_alert,
                                                  p.products_discountinued
                                     FROM :table_products p,
                                          :table_products_description pd
                                     WHERE (products_model = :reference 
                                        OR products_sku = :reference 
                                        OR products_ean = :reference)
                                     and pd.language_id = :language_id
                                     limit 1
                                 ');

    $query->bindValue('reference', $reference);
    $query->bindInt('language_id', $CLICSHOPPING_Language->getId());
    $query->execute();
    $result = $query->fetch();

    if ($result !== false) {
      if($result['products_quantity'] <= $result['products_discountinued']) {
        $result['is_below_alert'] = $result['products_discountinued'];
      } else {
        $result['is_below_alert'] = false;
      }

      return [
        'type' => 'stock_alert_info',
        'count' => 1,
        'items' => [
          [
            'id' => $result['products_id'],
            'data' => [
              'products_name' => $result['products_name'] ?? 'Produit sans nom',
              'products_description' => $result['products_description'] ?? 'pas de description',
              'products_quantity' => $result['products_quantity'] ?? 'N/A',
              'products_quantity_alert' => $result['products_quantity_alert'] ?? 'Non d&eacute;fini',
              'is_below_alert' => $result['is_below_alert'] ??  'Non d&eacute;fini',
              'products_model' => $result['products_model'] ?? $reference,
              'products_sku' => $result['products_sku'] ?? null,
              'products_ean' => $result['products_ean'] ?? null
            ]
          ]
        ]
      ];
    }

    return [
      'type' => 'stock_alert_info',
      'count' => 0,
      'items' => []
    ];
  }







  /**
   * Analyse de stock (produits en stock, en alerte, etc.)
   */
  public function analyzeStock(string $analysisType, int|null $languageId = null): array
  {
    $CLICSHOPPING_Language = Registry::get('Language');
    $results = [];
    $productTable = $this->embeddedTables['products'];

    switch ($analysisType) {
      case 'in_stock':
        $query = $this->db->prepare('SELECT distinct p.*,
                                                     pd.products_name 
                                     FROM :table_products p,
                                          :table_products_description pd
                                     WHERE p.products_quantity > 0
                                    AND pd.language_id = :language_id
                                    AND p.products_id = pd.products_id  
                                    ');
        $query->bindInt('language_id', $CLICSHOPPING_Language->getId());
        $query->execute();
        break;

      case 'out_of_stock':
        $query = $this->db->prepare('SELECT distinct p.*,
                                                     pd.products_name 
                                     FROM :table_products p,
                                          :table_products_description pd
                                     WHERE p.products_quantity <= 0
                                    AND pd.language_id = :language_id
                                    AND p.products_id = pd.products_id  
                                    ');
        $query->bindInt('language_id', $CLICSHOPPING_Language->getId());
        $query->execute();
        break;

      case 'alert_level':
        $query = $this->db->prepare('SELECT distinct p.*,
                                                     pd.products_name 
                                     FROM :table_products p,
                                          :table_products_description pd
                                     WHERE p.products_quantity_alert <= p.products_discountinued
                                    AND pd.language_id = :language_id
                                    AND p.products_id = pd.products_id  
                                    ');
        $query->bindInt('language_id', $CLICSHOPPING_Language->getId());
        $query->execute();
        break;

      case 'total_inventory_value':
        $query = $this->db->prepare('SELECT  SUM(p.products_quantity * p.products_price) as total_value
                                     FROM :table_products 
                                    ');
        $query->execute();
        break;

      default:
        return [];
    }

    if ($analysisType === 'total_inventory_value') {
      $results = $query->fetch();
    } else {
      $results = $query->fetchAll();
    }

    return $this->formatResults($results, 'products');
  }

  /**
   * Analyse statistique (sommes, moyennes, comptages)
   */
  public function calculateStatistics(string $entityType, string $statType, ?string $column = null): array
  {
    if (!isset($this->embeddedTables[$entityType])) {
      return ['error' => "Type d'entité non pris en charge: {$entityType}"];
    }

    $table = $this->embeddedTables[$entityType];
    $tableName = 'clic_' . $table['table'];

    // Déterminer la colonne à utiliser pour les statistiques
    if ($column === null) {
      // Utiliser une colonne numérique par défaut selon le type d'entité
      if ($entityType === 'products' && $statType !== 'count') {
        $column = 'products_price';
      } elseif ($entityType === 'orders' && $statType !== 'count') {
        $column = 'value';
      }
    }

    // Construire la requête selon le type de statistique
    switch ($statType) {
      case 'count':
        $query = "SELECT COUNT(*) as count FROM {$tableName}";
        break;

      case 'sum':
        if ($column === null) {
          return ['error' => "Colonne requise pour le calcul de somme"];
        }
        $query = "SELECT SUM({$column}) as sum FROM {$tableName}";
        break;

      case 'avg':
        if ($column === null) {
          return ['error' => "Colonne requise pour le calcul de moyenne"];
        }
        $query = "SELECT AVG({$column}) as average FROM {$tableName}";
        break;

      case 'min':
        if ($column === null) {
          return ['error' => "Colonne requise pour le calcul du minimum"];
        }
        $query = "SELECT MIN({$column}) as minimum FROM {$tableName}";
        break;

      case 'max':
        if ($column === null) {
          return ['error' => "Colonne requise pour le calcul du maximum"];
        }
        $query = "SELECT MAX({$column}) as maximum FROM {$tableName}";
        break;

      default:
        return ['error' => "Type de statistique non pris en charge: {$statType}"];
    }

    $stmt = $this->db->prepare($query);
    $stmt->execute();

    return $stmt->fetch();
  }

  /**
   * Recherche avancée avec filtres multiples
   */
  public function advancedSearch(string $entityType, array $filters, ?int $languageId = null, int $limit = 10): array
  {
    if (!isset($this->embeddedTables[$entityType])) {
      return ['error' => "Type d'entité non pris en charge: {$entityType}"];
    }

    $table = $this->embeddedTables[$entityType];
    $tableName = 'clic_' . $table['table'];
    $idColumn = $table['id_column'];

    // Construction de la requête de base
    $query = "SELECT * FROM {$tableName} WHERE 1=1";

    $params = [];

    // Ajout des filtres
    foreach ($filters as $column => $value) {
      if (is_array($value) && isset($value['operator'])) {
        // Filtre avec opérateur personnalisé
        $operator = $value['operator'];
        $filterValue = $value['value'];

        switch ($operator) {
          case 'like':
            $query .= " AND {$column} LIKE :filter_{$column}";
            $params["filter_{$column}"] = "%{$filterValue}%";
            break;

          case 'gt':
            $query .= " AND {$column} > :filter_{$column}";
            $params["filter_{$column}"] = $filterValue;
            break;

          case 'lt':
            $query .= " AND {$column} < :filter_{$column}";
            $params["filter_{$column}"] = $filterValue;
            break;

          case 'gte':
            $query .= " AND {$column} >= :filter_{$column}";
            $params["filter_{$column}"] = $filterValue;
            break;

          case 'lte':
            $query .= " AND {$column} <= :filter_{$column}";
            $params["filter_{$column}"] = $filterValue;
            break;

          case 'in':
            if (is_array($filterValue)) {
              $placeholders = [];
              foreach ($filterValue as $i => $val) {
                $placeholders[] = ":filter_{$column}_{$i}";
                $params["filter_{$column}_{$i}"] = $val;
              }
              $query .= " AND {$column} IN (" . implode(', ', $placeholders) . ")";
            }
            break;
        }
      } else {
        // Filtre d'égalité simple
        $query .= " AND {$column} = :filter_{$column}";
        $params["filter_{$column}"] = $value;
      }
    }

    // Ajout du filtre de langue si applicable
    if ($languageId !== null && isset($table['language_column'])) {
      $query .= " AND {$table['language_column']} = :language_id";
      $params['language_id'] = $languageId;
    }

    // Ajout de la limite
    $query .= " LIMIT :limit";
    $params['limit'] = $limit;

    // Exécution de la requête
    $stmt = $this->db->prepare($query);

    foreach ($params as $key => $value) {
      $stmt->bindValue($key, $value);
    }

    $stmt->execute();
    $results = $stmt->fetchAll();

    return $this->formatResults($results, $entityType);
  }

  /**
   * Formatage des résultats pour une présentation cohérente
   */
  private function formatResults(array $results, string $entityType): array
  {
    if (empty($results)) {
      return [];
    }

    // Si c'est un résultat unique (statistique)
    if (!isset($results[0])) {
      return [
        'type' => 'statistics',
        'entity_type' => $entityType,
        'data' => $results
      ];
    }

    // Pour les résultats multiples
    $formattedResults = [
      'type' => 'entity_list',
      'entity_type' => $entityType,
      'count' => count($results),
      'items' => []
    ];

    foreach ($results as $result) {
      $formattedResults['items'][] = [
        'id' => $result[$this->embeddedTables[$entityType]['id_column']] ?? null,
        'data' => $result
      ];
    }

    return $formattedResults;
  }
}
