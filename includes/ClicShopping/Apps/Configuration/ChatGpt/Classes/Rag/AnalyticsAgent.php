<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Configuration\ChatGpt\Classes\Rag;

use ClicShopping\OM\Registry;
use ClicShopping\OM\CLICSHOPPING;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\Gpt;

/**
 * AnalyticsAgent Class
 *
 * Cette classe utilise LLPhant pour créer un agent intelligent capable
 * d'interpréter des requêtes métiers complexes et de générer des requêtes SQL
 * pour l'analyse de données e-commerce.
 */
class AnalyticsAgent
{
  protected $chat;
  private $db;
  private $languageId;

  /**
   * Constructeur pour AnalyticsAgent
   *
   * @param int|null $languageId ID de la langue pour le filtrage des résultats
   */
  public function __construct(?int $languageId = null)
  {
    $this->db = Registry::get('Db');
    $this->languageId = $languageId ?? Registry::get('Language')->getId();
    $dbConfig = $this->getDbConfig();
    $this->chat = Gpt::getOpenAiGpt(null);
    $this->setSystemMessage();
  }

  /**
   * Récupère la configuration de la base de données
   *
   * @return array Configuration de la base de données
   */
  private function getDbConfig(): array
  {
    // Par défaut, utiliser les valeurs de configuration de ClicShopping
    $dbConfig = [
      'host' => CLICSHOPPING::getConfig('db_server'),
      'port' => 3306, // Port par défaut pour MariaDB
      'dbname' => CLICSHOPPING::getConfig('db_database'),
      'username' => CLICSHOPPING::getConfig('db_username'),
      'password' => CLICSHOPPING::getConfig('db_password')
    ];

    return $dbConfig;
  }

  /**
   * Configure le message système pour l'agent LLPhant
   */
  private function setSystemMessage(): void
  {
    $systemMessage = "
            Tu es un assistant expert e-commerce spécialisé dans l'analyse de données pour ClicShopping.
            
            Les tables importantes sont :
            - products: Contient les informations de base des produits (products_id, products_model, products_price, products_quantity, products_date_added)
            - products_description: Contient les descriptions des produits (products_id, language_id, products_name, products_description)
            - categories: Contient les informations des catégories (categories_id, parent_id)
            - categories_description: Contient les descriptions des catégories (categories_id, language_id, categories_name)
            - products_to_categories: Table de liaison entre produits et catégories (products_id, categories_id)
            - orders: Contient les informations des commandes (orders_id, customers_id, date_purchased, orders_status)
            - orders_products: Contient les produits des commandes (orders_id, products_id, products_quantity, products_price)
            - orders_total: Contient les totaux des commandes (orders_id, value, class)
            - customers: Contient les informations des clients (customers_id, customers_name, customers_email_address)
            
            Si on te demande :
            - 'nombre de produits par catégorie' → SELECT cd.categories_name, COUNT(p.products_id) AS product_count FROM clic_products p JOIN clic_products_to_categories ptc ON p.products_id = ptc.products_id JOIN clic_categories c ON ptc.categories_id = c.categories_id JOIN clic_categories_description cd ON c.categories_id = cd.categories_id WHERE cd.language_id = [language_id] GROUP BY c.categories_id, cd.categories_name ORDER BY product_count DESC
            - 'moyenne des commandes par mois' → SELECT MONTH(o.date_purchased) AS month, YEAR(o.date_purchased) AS year, AVG(ot.value) AS average_order_value FROM clic_orders o JOIN clic_orders_total ot ON o.orders_id = ot.orders_id WHERE ot.class = 'ST' GROUP BY YEAR(o.date_purchased), MONTH(o.date_purchased) ORDER BY year, month
            - 'top produits vendus' → SELECT pd.products_name, SUM(op.products_quantity) AS total_sold FROM clic_orders_products op JOIN clic_products_description pd ON op.products_id = pd.products_id WHERE pd.language_id = [language_id] GROUP BY op.products_id, pd.products_name ORDER BY total_sold DESC LIMIT 10
            - 'produits en alerte de stock' → SELECT pd.products_name, p.products_quantity, p.products_quantity_alert FROM clic_products p JOIN clic_products_description pd ON p.products_id = pd.products_id WHERE p.products_quantity <= p.products_quantity_alert AND pd.language_id = [language_id] ORDER BY p.products_quantity ASC
            - 'chiffre d'affaires par mois' → SELECT MONTH(o.date_purchased) AS month, YEAR(o.date_purchased) AS year, SUM(ot.value) AS total_revenue FROM clic_orders o JOIN clic_orders_total ot ON o.orders_id = ot.orders_id WHERE ot.class = 'ST' GROUP BY YEAR(o.date_purchased), MONTH(o.date_purchased) ORDER BY year, month
            - 'combien de produits sont disponibles' → SELECT COUNT(products_id) AS total_available_products FROM clic_products WHERE products_status = 1
            - 'Combien de produits dont le statut est sur on dans la catégorie Coutellerie ? ' → SELECT COUNT(cp.products_id) AS total_active_products FROM clic_products cp JOIN clic_products_to_categories ptc ON cp.products_id = ptc.products_id JOIN clic_categories_description cd ON ptc.categories_id = cd.categories_id WHERE cp.products_status = '1' AND cd.categories_name LIKE '%Coutellerie%' AND cd.language_id = 2 
            
            Lorsque tu génères une requête SQL :
            1. Utilise toujours les préfixes de table complets (ex: clic_products au lieu de products)
            2. Ajoute des jointures appropriées pour les tables liées
            3. Filtre par language_id lorsque c'est pertinent
            4. Optimise la requête pour de bonnes performances
            5. Ajoute des clauses ORDER BY appropriées
            6. Limite les résultats à un nombre raisonnable si nécessaire (LIMIT)
            7. Si un champ texte est impliqué dans une condition (comme un nom ou une description), utilise l'opérateur LIKE avec les caractères génériques (%) pour permettre une recherche partielle
            8. Assure-toi que la requête est correcte avant de l'exécuter
            
            IMPORTANT: Réponds uniquement avec la requête SQL brute, sans aucun formatage, sans balises markdown, sans ```sql, sans commentaires, sans explications.
        ";

    // Remplacer [language_id] par l'ID de langue actuel
    $systemMessage = str_replace('[language_id]', $this->languageId, $systemMessage);

    // Définir le message système pour l'agent
    $this->chat->setSystemMessage($systemMessage);
  }

  /**
   * Analyse une question métier et génère une réponse
   *
   * @param string $question Question en langage naturel
   * @return string Réponse générée
   */
  public function analyserQuestion(string $question): string
  {
    $response = $this->chat->generateText($question);
    return $this->cleanSqlResponse($response);
  }

  /**
   * Génère uniquement la requête SQL pour une question donnée
   *
   * @param string $question Question en langage naturel
   * @return string Requête SQL générée
   */
  public function getRequeteSQL(string $question): string
  {
    $response = $this->chat->generateText($question);
    $cleanedResponse = $this->cleanSqlResponse($response);

    // Log de la requête SQL pour débogage
   // if (defined('CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER == 'True') {
      error_log("Analytics SQL Query for '$question': " . $cleanedResponse);
   // }

    return $cleanedResponse;
  }

  /**
   * Nettoie la réponse SQL des balises de formatage et autres éléments indésirables
   *
   * @param string $response Réponse brute du modèle
   * @return string Requête SQL nettoyée
   */
  private function cleanSqlResponse(string $response): string
  {
    // Supprimer les balises de formatage markdown
    $cleaned = preg_replace('/```sql\s*|\s*```/', '', $response);

    // Supprimer les balises HTML potentielles
    $cleaned = strip_tags($cleaned);

    // Supprimer les espaces et retours à la ligne superflus au début et à la fin
    $cleaned = trim($cleaned);

    return $cleaned;
  }

  /**
   * Exécute la requête SQL générée et retourne les résultats
   *
   * @param string $question Question en langage naturel
   * @return array Résultats de la requête
   */
  public function executeQuery(string $question): array
  {
    try {
      // Générer la requête SQL
      $sqlQuery = $this->getRequeteSQL($question);

      // Exécuter la requête SQL
      $query = $this->db->prepare($sqlQuery);
      $query->execute();
      $results = $query->fetchAll();

      return [
        'type' => 'analytics_results',
        'query' => $question,
        'sql_query' => $sqlQuery,
        'results' => $results,
        'count' => count($results)
      ];
    } catch (\Exception $e) {
      // Log de l'erreur pour débogage
      if (defined('CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER == 'True') {
        error_log("Analytics SQL Error: " . $e->getMessage());
        error_log("Failed SQL Query: " . ($sqlQuery ?? 'Requête non générée'));
      }

      return [
        'type' => 'error',
        'message' => $e->getMessage(),
        'query' => $question,
        'sql_query' => $sqlQuery ?? 'Requête non générée'
      ];
    }
  }

  /**
   * Traite une requête métier complète (génération SQL + exécution + interprétation)
   *
   * @param string $question Question en langage naturel
   * @param bool $includeSQL Inclure la requête SQL dans la réponse
   * @return array Résultats complets avec interprétation
   */
  public function processBusinessQuery(string $question, bool $includeSQL = true): array
  {
    try {
      // Exécuter la requête
      $results = $this->executeQuery($question);

      // Si une erreur s'est produite
      if ($results['type'] === 'error') {
        return $results;
      }

      // Générer une interprétation des résultats
      $interpretation = $this->interpretResults($question, $results['results']);

      // Préparer la réponse
      $response = [
        'type' => 'analytics_response',
        'question' => $question,
        'sql_query' => $results['sql_query'],
        'interpretation' => $interpretation,
        'count' => $results['count'],
        'results' => $results['results']
      ];

      return $response;
    } catch (\Exception $e) {
      // Log de l'erreur pour débogage
      if (defined('CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_CH_DEBUG_RAG_MANAGER == 'True') {
        error_log("Analytics Processing Error: " . $e->getMessage());
      }

      return [
        'type' => 'error',
        'message' => $e->getMessage(),
        'question' => $question
      ];
    }
  }

  /**
   * Interprète les résultats d'une requête en langage naturel
   *
   * @param string $question Question originale
   * @param array $results Résultats de la requête
   * @return string Interprétation en langage naturel
   */
  private function interpretResults(string $question, array $results): string
  {
    // Construire un prompt pour le LLM
    $prompt = "Tu es un expert en analyse de données e-commerce. Interprète ces résultats de requête SQL et fournis une explication claire et concise en français.\n\n";
    $prompt .= "Question : {$question}\n\n";
    $prompt .= "Résultats : " . json_encode($results, JSON_PRETTY_PRINT) . "\n\n";
    $prompt .= "Interprétation :";

    // Utiliser le chat pour générer l'interprétation
    $interpretation = $this->chat->generateText($prompt);

    return $interpretation;
  }

  /**
   * Vérifie si une requête est de type analytique
   *
   * @param string $query Requête à vérifier
   * @return bool True si la requête est analytique, false sinon
   */
  public function isAnalyticsQuery(string $query): bool
  {
    // Patterns pour détecter les requêtes d'analyse
    $analyticsPatterns = [
      '/combien|total|nombre|count|somme|sum|moyenne|average|min|max/i',
      '/stock|inventaire|disponible|disponibilité|alerte|niveau|reorder/i',
      '/REF[-\s]?\d+|SKU[-\s]?\d+|EAN[-\s]?\d+|\b\d{8,13}\b|ID\s*:\s*\d+/i',
      '/prix\s*(>|<|>=|<=|=)\s*(\d+[\.,]?\d*)/i',
      '/quantité\s*(>|<|>=|<=|=)\s*(\d+)/i',
      '/par catégorie|par client|par produit|par mois|par jour|par semaine|par an/i',
      '/top|meilleur|plus vendu|best seller|populaire/i',
      '/chiffre d\'affaires|ca|revenu|vente/i'
    ];

    foreach ($analyticsPatterns as $pattern) {
      if (preg_match($pattern, $query)) {
        return true;
      }
    }

    return false;
  }
}
