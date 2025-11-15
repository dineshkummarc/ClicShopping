<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Tools\MCP\Sites\Shop\Pages\RagBI;

use AllowDynamicProperties;
use ClicShopping\Apps\Configuration\ChatGpt\Classes\ClicShoppingAdmin\Gpt;
use ClicShopping\Apps\Tools\MCP\Classes\ClicShoppingAdmin\MCPConnector;
use ClicShopping\Apps\Tools\MCP\Classes\Shop\EndPoint\RagBIPermissions;
use ClicShopping\Apps\Tools\MCP\Classes\Shop\Security\Authentification;
use ClicShopping\Apps\Tools\MCP\Classes\Shop\Security\McpPermissions;
use ClicShopping\Apps\Tools\MCP\Classes\Shop\Security\McpSecurity;
use ClicShopping\Apps\Tools\MCP\Classes\Shop\Security\McpShop;
use ClicShopping\Apps\Tools\MCP\Classes\Shop\Security\Message;

use ClicShopping\AI\Insfrastructure\Orm\DoctrineOrm;
use ClicShopping\AI\Insfrastructure\Storage\MariaDBVectorStore;
use ClicShopping\AI\Rag\MultiDBRAGManager;
use ClicShopping\AI\Domain\SemanticSearch\Semantics;

use LLPhant\Embeddings\EmbeddingGenerator\OpenAI\OpenAI3LargeEmbeddingGenerator;

use ClicShopping\Apps\Tools\MCP\MCP;

use ClicShopping\OM\HTML;
use ClicShopping\OM\Registry;

#[AllowDynamicProperties]
class RagBI extends \ClicShopping\OM\PagesAbstract
{
  /**
   * Database connection instance.
   * @var mixed
   */
  public mixed $db;
  /**
   * ClicShopping application instance.
   * @var mixed
   */
  public mixed $app;
  /**
   * Display the message
   * @var bool
   */
  public mixed $message;

 // private mixed $mcpConnector;
  private mixed $ragBIPermissions;

  /** @var McpPermissions The McpPermissions instance for access control. */
  public McpPermissions $mcpPermissions;

  /** @var string The username authenticated via session or key. */
  private string $authenticatedUsername = '';

  /**
   * Determines if the site template should be used.
   * @var bool
   */
  protected bool $use_site_template = false;

  /**
   * The file name for the page.
   * @var string|null
   */
  protected ?string $file = null;

  /**
   * Initializes the class by setting up dependencies, headers, and request routing.
   * It handles all incoming API requests (GET, POST, OPTIONS) and routes them to the appropriate handler.
   *
   * @return void
   */
  protected function init()
  {
    $this->db = Registry::get('Db');
    $this->lang = Registry::get('Language');

    // Set JSON content type
    header('Content-Type: application/json');

    // Enable CORS for MCP server with security headers
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    // Ajout de HTTP_MCP_USER/KEY et HTTP_MCP_TOKEN
    header(
      'Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-API-Key, X-Session-Token, X-MCP-USER, X-MCP-KEY, X-MCP-TOKEN'
    );
    header('Access-Control-Allow-Credentials: true');

    // Security headers
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    if (!Registry::exists('MCP')) {
      Registry::set('MCP', new MCP());
    }

    $this->app = Registry::get('MCP');

    if (!Registry::exists('Message')) {
      Registry::set('Message', new Message());
    }
    $this->message = Registry::get('Message');

    // Initialisation de McpPermissions
    if (!Registry::exists('McpPermissions')) {
      Registry::set('McpPermissions', new McpPermissions());
    }
    $this->mcpPermissions = Registry::get('McpPermissions');

    if (!Registry::exists('MCPConnector')) {
      Registry::set('MCPConnector', new MCPConnector());
    }
    $this->mcpConnector = Registry::get('MCPConnector');

    if (!Registry::exists('RagBIPermissions')) {
      Registry::set('RagBIPermissions', new RagBIPermissions());
    }
    $this->ragBIPermissions = Registry::get('RagBIPermissions');

    // Maintien de la vérification de statut de l'application
    if (!\defined('CLICSHOPPING_APP_MCP_MC_STATUS') || CLICSHOPPING_APP_MCP_MC_STATUS == 'False') {
      $this->sendErrorResponse('API is disabled');
      return;
    }

    // Si c'est une requête OPTIONS (preflight), on autorise et on sort
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
      http_response_code(200);
      exit;
    }

    // =========================================================================
    // START: LOGIQUE D'AUTHENTIFICATION ET DE GESTION DE SESSION (Source unique)
    // =========================================================================

    // 1. Récupération des paramètres (vérification de l'URL et des Headers)
    $username = $_GET['user_name'] ?? $_POST['user_name'] ?? $_SERVER['HTTP_X_MCP_USER'] ?? $_SERVER['HTTP_MCP_USER'] ?? null;
    $key = $_GET['key'] ?? $_POST['key'] ?? $_SERVER['HTTP_X_MCP_KEY'] ?? $_SERVER['HTTP_MCP_KEY'] ?? null;
    $mcpSessionId = $_GET['token'] ?? $_POST['token'] ?? $_SERVER['HTTP_X_MCP_TOKEN'] ?? $_SERVER['HTTP_MCP_TOKEN'] ?? null;

    // DÉCODAGE DE L'EN-TÊTE AUTHORIZATION BASIC (pour le premier appel curl)
    $authorizationHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
    if (empty($username) && empty($key) && !empty($authorizationHeader)) {
      if (preg_match('/Basic\s+(.*)/i', $authorizationHeader, $matches)) {
        $decodedCredentials = base64_decode($matches[1]);
        if (str_contains($decodedCredentials, ':')) {
          list($authUsername, $authKey) = explode(':', $decodedCredentials, 2);
          // Utiliser ces valeurs pour l'authentification
          $username = $authUsername;
          $key = $authKey;
        }
      }
    }

    // 2. Vérification/Création de session
    if (empty($mcpSessionId)) {
      // Le token de session est manquant -> Tentative d'authentification par identifiants
      if (empty($username) || empty($key)) {
        $this->message->sendError(
          'Unauthorized: Missing required session token OR credentials for authentication.',
          401
        );
        return;
      }

      try {
        // AUTHENTIFICATION ET CRÉATION DE SESSION
        $authentification = new Authentification($username, $key);
        $mcpSessionId = $authentification->authenticateAndCreateSession();
        // L'utilisateur est maintenant authentifié, on utilise le $username fourni
        $this->authenticatedUsername = $username;
      } catch (\Exception $e) {
        McpSecurity::logSecurityEvent(
          'API Access Denied - Authentication Failed',
          ['username' => $username, 'error' => $e->getMessage()]
        );

        $this->message->sendError('Unauthorized: Authentication failed. ' . $e->getMessage(), 401);
        return;
      }
    } else {
      // 3. Validation de la session (Token) existante
      try {
        // Valide le Session ID, vérifie l'IP, et renouvelle si nécessaire.
        $validSessionId = McpSecurity::checkToken($mcpSessionId);

        // IMPORTANT : Récupérer le nom d'utilisateur associé au token pour la vérification des permissions.
        $this->authenticatedUsername = McpSecurity::getUsernameFromSession($validSessionId);

        if (empty($this->authenticatedUsername)) {
          throw new \Exception("Session token is valid but associated username could not be found.");
        }
      } catch (\Exception $e) {
        McpSecurity::logSecurityEvent(
          'API Access Denied - Invalid Session Token',
          ['session_id' => $mcpSessionId, 'error' => $e->getMessage()]
        );
        $this->message->sendError('Unauthorized: Invalid or expired session token. ' . $e->getMessage(), 401);
        return;
      }
    }

    // =========================================================================
    // END: LOGIQUE D'AUTHENTIFICATION ET DE GESTION DE SESSION
    // =========================================================================

    // 4. VÉRIFICATION DES PERMISSIONS MCP ET RAG-BI (Post-authentification)

    // Toujours obtenir l'action même si l'URL est pourrie, pour la vérification de permission
    $action = HTML::sanitize($_GET['action'] ?? $_POST['action'] ?? 'analyze_sales');

    // Vérification de la permission RAG-BI générale (via RagBIPermissions)
    if (!$this->ragBIPermissions->canAccessRagBI($this->authenticatedUsername)) {
      McpSecurity::logSecurityEvent('RAG-BI Access Denied - General permission check failed', [
        'username' => $this->authenticatedUsername,
        'action' => $action
      ]);
      $this->message->sendError(
        'Forbidden: User "' . $this->authenticatedUsername . '" does not have general RAG-BI access.',
        403
      );
      return;
    }

    // Vérification de la permission MCP principale pour l'action demandée
    if (!$this->mcpPermissions->hasPermissionForEndpoint($this->authenticatedUsername, 'RagBI', $action)) {
      McpSecurity::logSecurityEvent('API Access Denied - Permission check failed', [
        'username' => $this->authenticatedUsername,
        'action' => $action
      ]);

      $this->message->sendError(
        'Forbidden: User "' . $this->authenticatedUsername . '" does not have permission for action "' . $action . '".',
        403
      );
      return;
    }

    // =========================================================================
    // START: LOGIQUE RAG-BI (Le reste de votre code)
    // =========================================================================

    try {
      Gpt::getEnvironment();

      $prompt = HTML::sanitize($_POST['message']);
      //$prompt = 'donne moi un tableau de l evolution du chiffre d\'affaires par mois de l\'annéé 2025'; // test

      $languageId = Registry::get('Language')->getId();

      $ragManager = new MultiDBRAGManager();

      if (\defined('CLICSHOPPING_APP_CHATGPT_RA_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_RAG_MANAGER == 'True' && CLICSHOPPING_APP_CHATGPT_RA_STATUS == 'True') {
        $queryType = isset($_POST['queryType']) ? HTML::sanitize($_POST['queryType']) : 'semantic';
        //$queryType = 'analytics'; // test

        if ($queryType === 'semantic') {
          $queryType = Semantics::classifyQuery($prompt);
        }

        if ($queryType === 'analytics') {
          $analyticsResults = $ragManager->executeAnalyticsQuery($prompt);
          $result = $ragManager->formatResults($analyticsResults);

          if (is_null($result)) {
            error_log("Error: result is null for analytic query.");
          }
        } else {
          if ($queryType === 'semantic') {
            // Approach 2: Use the current aborescence

            $embeddingGenerator = new OpenAI3LargeEmbeddingGenerator();
            $entityManager = DoctrineOrm::getEntityManager();
            $embeddingTables = [];

            $knownTables = $ragManager->knownEmbeddingTable();

            // Add first the known table
            foreach ($knownTables as $tableName) {
              try {
                $vectorStore = new MariaDBVectorStore($embeddingGenerator, $tableName);
                $embeddingTables[$tableName] = $vectorStore;
              } catch (\Exception $e) {
                if (\defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER') && CLICSHOPPING_APP_CHATGPT_RA_OPENAI_EMBEDDING == 'True') {
                  error_log("Erreur lors de l'initialisation de la table {$tableName} : " . $e->getMessage());
                  // Continuer avec les autres tables en cas d'erreur
                }
              }
            }

            // Other table search inside the DB
            try {
              $tables = DoctrineOrm::getEmbeddingTables();

              foreach ($tables as $tableName) {
                if (!in_array($tableName, $knownTables)) {
                  try {
                    $vectorStore = new MariaDBVectorStore($embeddingGenerator, $tableName);
                    $embeddingTables[$tableName] = $vectorStore;
                  } catch (\Exception $e) {
                    if (\defined('CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER' ) && CLICSHOPPING_APP_CHATGPT_RA_OPENAI_EMBEDDING == 'True') {
                      error_log("Erreur lors de l'initialisation de la table {$tableName} : " . $e->getMessage());
                      // If error continue
                    }
                  }
                }
              }
            } catch (\Exception $e) {
              if (\defined( 'CLICSHOPPING_APP_CHATGPT_RA_DEBUG_RAG_MANAGER' ) && CLICSHOPPING_APP_CHATGPT_RA_OPENAI_EMBEDDING == 'True') {
                error_log("Erreur lors de la recherche des tables d'embedding : " . $e->getMessage());
                // If error continue
              }
            }

            // 4️ Search in all vector table inside the DB
            $allResults = [];
            $context = '';

            foreach ($embeddingTables as $tableName => $vectorStore) {
              // Check if the table is valid
              try {
                // Language filter if specified
                $filter = null;

                if ($languageId !== null) {
                  $filter = function ($metadata) use ($languageId) {
                    return isset($metadata['language_id']) && $metadata['language_id'] == $languageId;
                  };
                }

                // USe similaritySearch signature
                $results = $vectorStore->similaritySearch($prompt, 2, 0.5, $filter);

                foreach ($results as $doc) {
                  $entityInfo = '';
                  if (isset($doc->metadata['entity_type']) && isset($doc->metadata['entity_id'])) {
                    $entityInfo = " ({$doc->metadata['entity_type']} #{$doc->metadata['entity_id']})";
                  }

                  $context .= "Source: {$tableName}{$entityInfo}\n";
                  $context .= $doc->content . "\n\n";
                }
              } catch (\Exception $e) {
                error_log("Erreur lors de la recherche dans la table {$tableName} : " . $e->getMessage());
                // If error continue
              }
            }

            // 5️ Si des documents pertinents ont été trouvés, les envoyer à OpenAI pour une réponse enrichie
            if (!empty($context)) {
              $result = Gpt::getGptResponse($context . "\n\nQuestion : " . $prompt);
            } else {
              //If no information found, use openAI directly
              $result = Gpt::getGptResponse($prompt);
            }

            $pos = strstr($result, ':');
            if ($pos !== false) {
              $result = substr($pos, 2);
            }
          }
        }

        Gpt::saveData($prompt, $result, null, true);
        // Retourner du JSON pour l'API MCP
        echo json_encode([
          'status' => 'success',
          'data' => [
            'response' => $result,
            'type' => 'rag-bi',
            'source' => 'clicshopping-ragbi',
            'confidence' => 0.9,
            'metadata' => [
              'query_type' => $queryType,
              'language' => $languageId,
              'processing_time' => microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'],
              'rag_enabled' => true,
              'clicshopping_processed' => true
            ]
          ]
        ]);

        exit;
      } else {
        $result = Gpt::getGptResponse($prompt);

        $pos = strstr($result, ':');

        if ($pos !== false) {
          $result = substr($pos, 2); // Pour enlever les deux-points et l'espace
        }

        Gpt::saveData($prompt, $result, null, true);

        // Retourner du JSON pour l'API MCP
        echo json_encode([
          'status' => 'success',
          'data' => [
            'response' => $result,
            'type' => 'rag-bi',
            'source' => 'clicshopping-ragbi',
            'confidence' => 0.8,
            'metadata' => [
              'query_type' => 'semantic',
              'language' => $languageId,
              'processing_time' => microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'],
              'rag_enabled' => false,
              'clicshopping_processed' => true
            ]
          ]
        ]);
      }
      exit;
    } catch
    (\Exception $e) {
      error_log('Erreur dans le traitement AJAX : ' . $e->getMessage());
      echo json_encode([
        'status' => 'error',
        'data' => [
          'response' => "Une erreur s'est produite lors du traitement de votre requête : " . $e->getMessage(),
          'type' => 'error',
          'source' => 'clicshopping-ragbi',
          'confidence' => 0.1,
          'error' => [
            'type' => 'processing_error',
            'message' => $e->getMessage()
          ],
          'metadata' => [
            'rag_enabled' => false,
            'clicshopping_processed' => false
          ]
        ]
      ]);

      exit;
    }
  }


  /**
   * Handle GET request
   */
  private function handleGetRequest(array $statusCheck)
  {
    if ($statusCheck['get'] == 0) {
      return $this->sendErrorResponse('Category fetch not allowed');
    }

    return $this->sendSuccessResponse(static::getCategories());
  }

  /**
   * Handle PUT request
   */
  private function handlePutRequest(array $statusCheck)
  {
    if (!$statusCheck['update'] == 0) {
      return $this->sendErrorResponse('Update not allowed');
    }

    return $this->sendSuccessResponse('Category updated successfully');
  }


  /**
   * Handle DELETE request
   */
  private function handleDeleteRequest(array $statusCheck)
  {
    if ($statusCheck['delete'] == 0) {
      return $this->sendErrorResponse('Category deletion not allowed');
    }

    return $this->sendSuccessResponse(static::deleteCategories());
  }

  /**
   * Handle POST request
   */
  private function handlePostRequest(array $statusCheck)
  {
    if (isset($_GET['update']) && $statusCheck['update'] == 0) {
      return $this->sendErrorResponse('Category update not allowed');
    }

    if (isset($_GET['insert']) && $statusCheck['insert'] == 0) {
      return $this->sendErrorResponse('Category insertion not allowed');
    }

    return $this->sendSuccessResponse(self::saveCategories());
  }

  /**
   * Sends a success response with the provided data.
   *
   * @param mixed $data The data to be included in the success response.
   * @return void
   */
  private function sendSuccessResponse(mixed $data): void
  {
    echo json_encode(['status' => 'success', 'data' => $data]);
    exit;
  }

  /**
   * Sends an error response with the provided message.
   *
   * @param string $message The error message to be included in the response.
   * @return void
   */
  private function sendErrorResponse(string $message): void
  {
    // Utiliser la méthode sendError de l'objet message pour le format JSON standard de l'API
    if (isset($this->message)) {
      $this->message->sendError($message, 500); // Code HTTP 500 par défaut si non spécifié
    } else {
      // Fallback si l'objet message n'est pas initialisé
      http_response_code(500);
      echo json_encode(['status' => 'error', 'message' => $message]);
      exit;
    }
  }


  /**
   * Checks the status based on the provided string and token.
   *
   * @param string $string The column name to be selected from the database.
   * @param string $token The session token used for identifying the API session.
   * @return int The integer value associated with the specified column.
   */
  private function statusCheck(string $string, string $token): int
  {
    $QstatusCheck = $this->db->prepare( // Correction: use $this->db instead of $this->Db
      'select a.' . $string . '
             from :table_mcp a,
                  :table_mcp_session ase
             where a.mcp_id = ase.mcp_id
             and ase.session_id = :session_id
           '
    );
    $QstatusCheck->bindValue('session_id', $token);
    $QstatusCheck->execute();

    return $QstatusCheck->valueInt($string);
  }

  /**
   * Valide une requête SQL pour RAG-BI
   * Utilise les contrôles stricts de RagBIPermissions
   *
   * @param string $sqlQuery Requête SQL à valider
   * @return bool True si autorisée, false sinon
   */
  public function validateRagBIQuery(string $sqlQuery): bool
  {
    // Utiliser l'utilisateur authentifié si la requête vient de l'intérieur de l'application
    $username = $this->authenticatedUsername;

    if (empty($username)) {
      // Si l'utilisateur n'est pas encore défini (appel hors du flux API standard), rejeter ou gérer différemment
      return false;
    }

    return $this->ragBIPermissions->canExecuteRagBIQuery($username, $sqlQuery);
  }

  /**
   * Obtient un rapport de sécurité pour l'utilisateur RAG-BI actuel
   *
   * @return array|null Rapport de sécurité ou null si non authentifié
   */
  public function getRagBISecurityReport(): ?array
  {
    // Utiliser l'utilisateur authentifié si la requête vient de l'intérieur de l'application
    $username = $this->authenticatedUsername;

    if (empty($username)) {
      return null;
    }

    return $this->ragBIPermissions->generateSecurityReport($username);
  }

  // Les fonctions validateRagBIAccess() et getMcpAuthFromRequest() ont été supprimées.
}