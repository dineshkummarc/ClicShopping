<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\AI\Agents\PromptSystem;

use AllowDynamicProperties;
use ClicShopping\OM\CLICSHOPPING;
use ClicShopping\OM\Registry;

/**
 * PromptSystem Class
 *
 * Système centralisé de gestion des prompts pour garantir des sorties JSON valides
 * et structurées pour tous les agents du système.
 *
 * Responsabilités :
 * - Définir les prompts système rigides
 * - Valider les schémas JSON
 * - Fournir des templates pour chaque type d'opération
 * - Garantir la cohérence des formats de sortie
 */
#[AllowDynamicProperties]
class PromptSystem
{
  /**
   * Types d'étapes autorisés dans les plans
   */
  private const ALLOWED_STEP_TYPES = [
    'semantic_search',
    'analytics_query',
    'calculation',
    'validation_check',
    'sql_correction',
    'data_synthesis',
    'web_search',
    'filtering',
    'aggregation',
    'comparison',
  ];

  /**
   * Niveaux de complexité autorisés
   */
  private const COMPLEXITY_LEVELS = [
    'simple',
    'medium',
    'complex',
    'very_complex',
  ];

  /**
   * Obtient le prompt système pour le TaskPlanner
   *
   * @return string Prompt système rigide
   */
  public static function getTaskPlannerSystemPrompt(): string
  {
    $CLICSHOPPING_Language = Registry::get('Language');
    $allowedTypes = implode(', ', self::ALLOWED_STEP_TYPES);
    $complexityLevels = implode('|', self::COMPLEXITY_LEVELS);
    $entityGuidelines = self::getEntityMetadataGuidelines();

    // Load SYSTEM prompt in English for better LLM evaluation (internal process)
    // Note: This evaluates the response quality, not user-facing
    $CLICSHOPPING_Language->loadDefinitions('rag_task_planner', 'en', null, 'ClicShoppingAdmin');

    $array = [
      'allowed_types' => $allowedTypes,
      'complexity_levels' => $complexityLevels,
      'entity_guidelines' => $entityGuidelines
    ];

    $text_instructions_task_planner = $CLICSHOPPING_Language->getDef('text_instructions_task_planner', $array);

    return $text_instructions_task_planner;
  }

  /**
   * Construit un prompt de contexte pour le TaskPlanner
   *
   * @param string $userQuery Requête utilisateur
   * @param array $memoryContext Contexte de la mémoire conversationnelle
   * @param array $reasoningSteps Étapes de raisonnement (si disponibles)
   * @param array $metadata Métadonnées additionnelles
   * @return string Prompt de contexte complet
   */
  public static function buildTaskPlannerContext( string $userQuery, array $memoryContext = [], array $reasoningSteps = [], array $metadata = []): string
  {
    $parts = [];

    $parts[] = "--- PLANNING CONTEXT ---";
    $parts[] = "";
    $parts[] = "User Query: \"{$userQuery}\"";
    $parts[] = "";

    // *** NOUVEAU : Ajouter les métadonnées d'entité si disponibles ***
    if (!empty($metadata['entity_type'])) {
      $parts[] = "Primary Entity Type: {$metadata['entity_type']}";
      if (!empty($metadata['entity_id'])) {
        $parts[] = "Primary Entity ID: {$metadata['entity_id']}";
      } else {
        $parts[] = "Primary Entity ID: (not specified or ambiguous)";
      }
      $parts[] = "";
    }

    // Contexte mémoire
    if (!empty($memoryContext)) {
      $parts[] = "Memory Context (last 3 interactions):";
      foreach (array_slice($memoryContext, -3) as $i => $interaction) {
        $parts[] = ((int)$i + 1) . ". User: " . substr($interaction['user_message'] ?? '', 0, 100);
        $parts[] = "   Assistant: " . substr($interaction['system_response'] ?? '', 0, 100);
        // Ajouter entity_type si disponible en mémoire
        if (!empty($interaction['entity_type'])) {
          $parts[] = "   Entity Type: {$interaction['entity_type']}";
        }
      }
      $parts[] = "";
    }

    // Étapes de raisonnement
    if (!empty($reasoningSteps)) {
      $parts[] = "Reasoning Required (from ReasoningAgent):";
      foreach ($reasoningSteps as $i => $step) {
        $parts[] = ((int)$i + 1) . ". " . ($step['description'] ?? $step);
      }
      $parts[] = "";
    }

    // Métadonnées additionnelles
    if (!empty($metadata)) {
      $parts[] = "Additional Context:";
      foreach ($metadata as $key => $value) {
        if (is_string($value) && !in_array($key, ['entity_type', 'entity_id'])) {
          $parts[] = "- {$key}: {$value}";
        }
      }
      $parts[] = "";
    }

    $parts[] = "--- INSTRUCTIONS ---";
    $parts[] = "Generate a JSON execution plan following the schema defined in the system prompt.";
    $parts[] = "Remember: Output ONLY valid JSON, no other text.";

    return implode("\n", $parts);
  }

  /**
   * Valide un plan JSON généré par le LLM
   *
   * @param string $jsonString JSON à valider
   * @return array Résultat de validation
   */
  public static function validatePlanJSON(string $jsonString): array
  {
    $result = [
      'valid' => false,
      'data' => null,
      'errors' => [],
    ];

    // 1. Parser le JSON
    $json = json_decode($jsonString, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
      $result['errors'][] = 'Invalid JSON: ' . json_last_error_msg();
      return $result;
    }

    // 2. Vérifier les champs obligatoires
    $requiredFields = ['plan_id', 'description', 'complexity', 'initial_query', 'steps'];

    foreach ($requiredFields as $field) {
      if (!isset($json[$field])) {
        $result['errors'][] = "Missing required field: {$field}";
      }
    }

    // 3. Valider la complexité
    if (isset($json['complexity']) && !in_array($json['complexity'], self::COMPLEXITY_LEVELS)) {
      $result['errors'][] = "Invalid complexity level: {$json['complexity']}";
    }

    // 4. Valider les étapes
    if (isset($json['steps'])) {
      if (!is_array($json['steps']) || empty($json['steps'])) {
        $result['errors'][] = "Steps must be a non-empty array";
      } else {
        $stepIds = [];
        $hasFinalStep = false;

        foreach ($json['steps'] as $i => $step) {
          $stepErrors = self::validateStep($step, $i, $stepIds);
          $result['errors'] = array_merge($result['errors'], $stepErrors);

          if (isset($step['id'])) {
            $stepIds[] = $step['id'];
          }

          if (isset($step['metadata']['is_final']) && $step['metadata']['is_final'] === true) {
            $hasFinalStep = true;
          }
        }

        if (!$hasFinalStep) {
          $result['errors'][] = "At least one step must have 'is_final' set to true";
        }
      }
    }

    // 5. Résultat final
    if (empty($result['errors'])) {
      $result['valid'] = true;
      $result['data'] = $json;
    }

    return $result;
  }

  /**
   * Valide une étape individuelle
   *
   * @param array $step Étape à valider
   * @param int $index Index de l'étape
   * @param array $existingIds IDs déjà utilisés
   * @return array Erreurs trouvées
   */
  private static function validateStep(array $step, int $index, array $existingIds): array
  {
    $errors = [];

    // Champs obligatoires
    $requiredFields = ['id', 'type', 'description', 'prompt_content', 'depends_on', 'metadata'];

    foreach ($requiredFields as $field) {
      if (!isset($step[$field])) {
        $errors[] = "Step {$index}: Missing required field '{$field}'";
      }
    }

    // Valider le type
    if (isset($step['type']) && !in_array($step['type'], self::ALLOWED_STEP_TYPES)) {
      $errors[] = "Step {$index}: Invalid type '{$step['type']}'";
    }

    // Valider l'ID unique
    if (isset($step['id'])) {
      if (in_array($step['id'], $existingIds)) {
        $errors[] = "Step {$index}: Duplicate ID '{$step['id']}'";
      }

      if (!preg_match('/^step_\d+$/', $step['id'])) {
        $errors[] = "Step {$index}: ID must match pattern 'step_N'";
      }
    }

    // Valider depends_on
    if (isset($step['depends_on'])) {
      if (!is_array($step['depends_on'])) {
        $errors[] = "Step {$index}: 'depends_on' must be an array";
      } else {
        foreach ($step['depends_on'] as $depId) {
          if (!in_array($depId, $existingIds)) {
            $errors[] = "Step {$index}: Dependency '{$depId}' references non-existent step";
          }
        }
      }
    }

    // Valider metadata
    if (isset($step['metadata']) && !is_array($step['metadata'])) {
      $errors[] = "Step {$index}: 'metadata' must be an object";
    }

    return $errors;
  }

  /**
   * Nettoie une réponse LLM pour extraire le JSON
   *
   * @param string $response Réponse brute du LLM
   * @return string JSON nettoyé
   */
  public static function cleanJSONResponse(string $response): string
  {
    // Enlever les balises markdown
    $cleaned = preg_replace('/```json\s*|\s*```/', '', $response);

    // Enlever le texte avant le premier {
    if (($pos = strpos($cleaned, '{')) !== false) {
      $cleaned = substr($cleaned, $pos);
    } else {
      // Pas de { trouvé, retourner un JSON vide valide
      return '{"error": "No JSON structure found"}';
    }

    // Enlever le texte après le dernier }
    if (($pos = strrpos($cleaned, '}')) !== false) {
      $cleaned = substr($cleaned, 0, $pos + 1);
    } else {
      // Pas de } trouvé, ajouter une fermeture
      $cleaned .= '}';
    }

    // Trim
    $cleaned = trim($cleaned);

    // 🆕 NOUVEAU : Vérification basique de la validité JSON
    if (empty($cleaned)) {
      return '{"error": "Empty JSON response"}';
    }

    // Compter les accolades pour détecter les JSON tronqués
    $openBraces = substr_count($cleaned, '{');
    $closeBraces = substr_count($cleaned, '}');
    
    if ($openBraces > $closeBraces) {
      // Ajouter les accolades manquantes
      $cleaned .= str_repeat('}', $openBraces - $closeBraces);
    }

    // Test rapide de validité JSON
    $testDecode = json_decode($cleaned, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
      error_log("JSON cleaning warning: " . json_last_error_msg() . " for: " . substr($cleaned, 0, 100));
      // Retourner un JSON d'erreur valide
      return '{"error": "Invalid JSON structure", "original_error": "' . addslashes(json_last_error_msg()) . '"}';
    }

    return $cleaned;
  }

  /**
   * Retourne les directives sur la gestion de entity_id et entity_type
   * À inclure dans tous les prompts système liés aux données
   *
   * @return string Directives formatées
   */
  public static function getEntityMetadataGuidelines(): string
  {
    return <<<GUIDELINES
---
ENTITY METADATA GUIDELINES
---
When processing queries that reference specific entities (products, orders, customers):

1. ENTITY EXTRACTION: Always try to extract entity_id and entity_type from:
   - Direct references: "product 123", "order #456"
   - Context clues: "this product", "that customer"
   - Previous conversation history

2. ENTITY PROPAGATION: Pass entity metadata through all steps:
   - Include entity_id and entity_type in step metadata
   - Preserve entity context in results
   - Use _entity_metadata wrapper when needed

3. ENTITY TYPES SUPPORTED:
   - products (products_id)
   - orders (orders_id)  
   - customers (customers_id)
   - categories (categories_id)

4. METADATA FORMAT:
   ```json
   {
     "entity_id": 123,
     "entity_type": "products",
     "_entity_metadata": {
       "entity_id": 123,
       "entity_type": "products"
     }
   }
   ```

GUIDELINES;
  }
}