<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\AI\Ecommerce\Classes\ClicShoppingAdmin\CockpitAI\SubScoring;

use ClicShopping\Apps\AI\Ecommerce\Classes\ClicShoppingAdmin\CockpitAI\SubFactors\FactorInterface;

/**
 * ScoringAxisInterface
 *
 * Contract for Score_X (product quality) and Score_Y (commercial performance) axes.
 * Each axis builds its own factor list and applies the reweighted scoring formula.
 */
interface ScoringAxisInterface
{
  /**
   * Get the axis code identifier: 'X' or 'Y'
   */
  public function getCode(): string;

  /**
   * Build the list of FactorInterface instances for the given product and context.
   *
   * @param array   $product Raw product data array (from DataCollector step 1)
   * @param Context $context Pipeline context carrying SEO status, catalog norms, thresholds
   * @return FactorInterface[] Array of instantiated factor objects
   */
  public function getFactors(array $product, Context $context): array;

  /**
   * Compute the axis score using the reweighted formula:
   * Score = Sum(wi * fi) / Sum(wi of valid factors) * 100
   *
   * Only factors with status 'valid' participate in both numerator and denominator.
   *
   * @param FactorInterface[] $factors Array of instantiated factor objects
   * @return float Score in [0..100]
   */
  public function computeScore(array $factors): float;

  /**
   * Return the configured weight for each factor code.
   *
   * @return array<string, float> Map of factor_code => weight
   */
  public function getWeights(): array;
}
