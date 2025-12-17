<?php
/**
 * ApiCostCalculator
 * 
 * Calcule les coûts API basés sur les tokens et modèles utilisés
 * Maintient une table de prix à jour pour différents providers
 */

namespace ClicShopping\AI\Infrastructure\Metrics;

class ApiCostCalculator
{
  /**
   * Tarifs par modèle (prix par 1K tokens)
   * Format: [model => [input_cost, output_cost]]
   */
  private const PRICING = [
    // OpenAI
    'gpt-4' => [0.03, 0.06],
    'gpt-4-turbo' => [0.01, 0.03],
    'gpt-4-turbo-preview' => [0.01, 0.03],
    'gpt-3.5-turbo' => [0.0005, 0.0015],
    'gpt-3.5-turbo-16k' => [0.003, 0.004],
    
    // Anthropic Claude
    'claude-3-opus' => [0.015, 0.075],
    'claude-3-sonnet' => [0.003, 0.015],
    'claude-3-haiku' => [0.00025, 0.00125],
    'claude-2.1' => [0.008, 0.024],
    'claude-2' => [0.008, 0.024],
    
    // Mistral
    'mistral-large' => [0.008, 0.024],
    'mistral-medium' => [0.0027, 0.0081],
    'mistral-small' => [0.002, 0.006],
    
    // Local (gratuit)
    'llama2' => [0.0, 0.0],
    'llama-2-70b' => [0.0, 0.0],
    'mistral-7b' => [0.0, 0.0],
    'mixtral-8x7b' => [0.0, 0.0],
    'ollama' => [0.0, 0.0]
  ];
  
  /**
   * Calcule le coût total d'un appel API
   * 
   * @param string $model Nom du modèle
   * @param int $promptTokens Tokens du prompt
   * @param int $completionTokens Tokens de la complétion
   * @return float Coût en USD
   */
  public static function calculateCost(string $model, int $promptTokens, int $completionTokens): float
  {
    // Normaliser le nom du modèle
    $model = strtolower($model);
    
    // Chercher le modèle exact ou un match partiel
    $pricing = self::findModelPricing($model);
    
    if ($pricing === null) {
      // Modèle inconnu, utiliser un coût par défaut conservateur
      return self::calculateDefaultCost($promptTokens, $completionTokens);
    }
    
    [$inputCost, $outputCost] = $pricing;
    
    // Calculer le coût
    $promptCost = ($promptTokens / 1000) * $inputCost;
    $completionCost = ($completionTokens / 1000) * $outputCost;
    
    return round($promptCost + $completionCost, 6);
  }
  
  /**
   * Trouve le pricing pour un modèle (avec match partiel)
   * 
   * @param string $model Nom du modèle
   * @return array|null [input_cost, output_cost] ou null
   */
  private static function findModelPricing(string $model): ?array
  {
    // Match exact
    if (isset(self::PRICING[$model])) {
      return self::PRICING[$model];
    }
    
    // Match partiel (ex: "gpt-4-0613" match "gpt-4")
    foreach (self::PRICING as $knownModel => $pricing) {
      if (strpos($model, $knownModel) !== false) {
        return $pricing;
      }
    }
    
    // Détection par provider
    if (strpos($model, 'gpt') !== false) {
      return self::PRICING['gpt-3.5-turbo']; // Défaut OpenAI
    }
    if (strpos($model, 'claude') !== false) {
      return self::PRICING['claude-3-haiku']; // Défaut Anthropic (le moins cher)
    }
    if (strpos($model, 'mistral') !== false) {
      return self::PRICING['mistral-small']; // Défaut Mistral
    }
    if (strpos($model, 'llama') !== false || strpos($model, 'ollama') !== false) {
      return [0.0, 0.0]; // Local, gratuit
    }
    
    return null;
  }
  
  /**
   * Calcule un coût par défaut pour modèles inconnus
   * 
   * @param int $promptTokens Tokens du prompt
   * @param int $completionTokens Tokens de la complétion
   * @return float Coût en USD
   */
  private static function calculateDefaultCost(int $promptTokens, int $completionTokens): float
  {
    // Utiliser le coût de gpt-3.5-turbo comme référence
    $defaultInputCost = 0.0005;
    $defaultOutputCost = 0.0015;
    
    $promptCost = ($promptTokens / 1000) * $defaultInputCost;
    $completionCost = ($completionTokens / 1000) * $defaultOutputCost;
    
    return round($promptCost + $completionCost, 6);
  }
  
  /**
   * Estime le coût d'un prompt avant l'appel API
   * 
   * @param string $model Nom du modèle
   * @param int $estimatedPromptTokens Tokens estimés du prompt
   * @param int $estimatedCompletionTokens Tokens estimés de la complétion
   * @return float Coût estimé en USD
   */
  public static function estimateCost(string $model, int $estimatedPromptTokens, int $estimatedCompletionTokens): float
  {
    return self::calculateCost($model, $estimatedPromptTokens, $estimatedCompletionTokens);
  }
  
  /**
   * Récupère les informations de pricing pour un modèle
   * 
   * @param string $model Nom du modèle
   * @return array ['input_cost' => float, 'output_cost' => float, 'currency' => 'USD']
   */
  public static function getModelPricing(string $model): array
  {
    $model = strtolower($model);
    $pricing = self::findModelPricing($model);
    
    if ($pricing === null) {
      return [
        'input_cost' => 0.0005,
        'output_cost' => 0.0015,
        'currency' => 'USD',
        'note' => 'Default pricing (model unknown)'
      ];
    }
    
    return [
      'input_cost' => $pricing[0],
      'output_cost' => $pricing[1],
      'currency' => 'USD',
      'per' => '1K tokens'
    ];
  }
  
  /**
   * Liste tous les modèles supportés avec leurs prix
   * 
   * @return array Liste des modèles et prix
   */
  public static function getAllPricing(): array
  {
    $result = [];
    
    foreach (self::PRICING as $model => $pricing) {
      $result[$model] = [
        'input_cost' => $pricing[0],
        'output_cost' => $pricing[1],
        'currency' => 'USD',
        'per' => '1K tokens'
      ];
    }
    
    return $result;
  }
  
  /**
   * Calcule le coût total pour un ensemble d'interactions
   * 
   * @param array $interactions Liste d'interactions avec tokens
   * @return array ['total_cost' => float, 'by_model' => array]
   */
  public static function calculateBatchCost(array $interactions): array
  {
    $totalCost = 0.0;
    $byModel = [];
    
    foreach ($interactions as $interaction) {
      $model = $interaction['model'] ?? 'unknown';
      $promptTokens = $interaction['prompt_tokens'] ?? 0;
      $completionTokens = $interaction['completion_tokens'] ?? 0;
      
      $cost = self::calculateCost($model, $promptTokens, $completionTokens);
      $totalCost += $cost;
      
      if (!isset($byModel[$model])) {
        $byModel[$model] = [
          'count' => 0,
          'total_cost' => 0.0,
          'total_tokens' => 0
        ];
      }
      
      $byModel[$model]['count']++;
      $byModel[$model]['total_cost'] += $cost;
      $byModel[$model]['total_tokens'] += $promptTokens + $completionTokens;
    }
    
    return [
      'total_cost' => round($totalCost, 6),
      'by_model' => $byModel,
      'currency' => 'USD'
    ];
  }
}
