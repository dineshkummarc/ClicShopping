<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

use ClicShopping\OM\CLICSHOPPING;
use ClicShopping\OM\HTML;
  use ClicShopping\OM\HTTP;
  use ClicShopping\OM\Registry;

$CLICSHOPPING_Template = Registry::get('Template');
$CLICSHOPPING_ProductsCommon = Registry::get('ProductsCommon');

// ----------------------------------------------------------------//
//                      file not found                             //
// ----------------------------------------------------------------//

if ($CLICSHOPPING_ProductsCommon->getProductsCount() < 1 || (\is_null($CLICSHOPPING_ProductsCommon->getID())) || $CLICSHOPPING_ProductsCommon->getID() === false) {
  http_response_code(404);
  HTTP::redirect(CLICSHOPPING::getConfig('http_server', 'Shop') . CLICSHOPPING::getConfig('http_path', 'Shop') . 'error_documents/404.php');
} elseif ($CLICSHOPPING_ProductsCommon->getProductsGroupView() == 1 || $CLICSHOPPING_ProductsCommon->getProductsView() == 1) {
// ----------------------------------------------------------------
// ---- Display products with autorization  ----
// ------------------------------------------------------------
  require_once($CLICSHOPPING_Template->getTemplateFiles('breadcrumb'));
  $CLICSHOPPING_ProductsCommon->countUpdateProductsView();
  ?>
  <section class="product" id="product">
    <div class="contentContainer">
      <div class="contentText">
        <div class="productsInfoContent">
          <?php echo $CLICSHOPPING_Template->getBlocks('modules_products_info'); ?>
        </div>
      </div>
    </div>
  </section>
  <?php
}
