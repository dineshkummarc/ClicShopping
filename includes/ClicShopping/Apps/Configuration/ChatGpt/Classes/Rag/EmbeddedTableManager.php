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
