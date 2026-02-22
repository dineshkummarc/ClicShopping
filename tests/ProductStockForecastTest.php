<?php

declare(strict_types=1);

use ClicShopping\Apps\Catalog\Products\Classes\ClicShoppingAdmin\ProductStock;

require_once __DIR__ . '/../Core/ClicShopping/Apps/Catalog/Products/Classes/ClicShoppingAdmin/ProductStock.php';

function assertFloatApprox(float $expected, float $actual, float $delta, string $message): void
{
  if (abs($expected - $actual) > $delta) {
    throw new RuntimeException($message . ' Expected: ' . $expected . ', got: ' . $actual);
  }
}

function assertSameValue($expected, $actual, string $message): void
{
  if ($expected !== $actual) {
    throw new RuntimeException($message . ' Expected: ' . var_export($expected, true) . ', got: ' . var_export($actual, true));
  }
}

// Test: demand stats
$stats = ProductStock::calculateDemandStats([1, 2, 3, 4, 5]);
assertSameValue(5, $stats['count'], 'Count should be 5');
assertFloatApprox(3.0, $stats['mean'], 0.0001, 'Mean should be 3.0');
assertFloatApprox(1.4142, $stats['stddev'], 0.0005, 'Stddev should be ~1.4142');

// Test: forecast
$forecast = ProductStock::calculateDemandForecast([1, 2, 3, 4, 5], 10);
assertFloatApprox(3.0, $forecast['mean_daily'], 0.0001, 'Mean daily should be 3.0');
assertFloatApprox(30.0, $forecast['mean_total'], 0.0001, 'Mean total should be 30.0');
assertFloatApprox(4.4721, $forecast['stddev_total'], 0.001, 'Stddev total should be ~4.4721');

// Test: stock-out probability
$probLow = ProductStock::calculateStockoutProbability(5.0, 10.0, 2.0);
$probHigh = ProductStock::calculateStockoutProbability(20.0, 10.0, 2.0);
if (!($probLow > 0.5)) {
  throw new RuntimeException('Stockout probability should be high when stock below mean');
}
if (!($probHigh < 0.01)) {
  throw new RuntimeException('Stockout probability should be low when stock above mean');
}

// Test: reorder quantity
$reorder = ProductStock::calculateReorderQuantity(5.0, 20.0, 3.0);
assertFloatApprox(18.0, $reorder, 0.0001, 'Reorder quantity should be 18.0');

echo "OK\n";
