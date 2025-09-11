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
use ClicShopping\OM\HTTP;
use ClicShopping\OM\Registry;

// Start the clock for the page parse time log
define('PAGE_PARSE_START_TIME', microtime());

define('CLICSHOPPING_BASE_DIR', realpath(__DIR__ . '/../Core/ClicShopping/') . DIRECTORY_SEPARATOR);

require_once(CLICSHOPPING_BASE_DIR . 'OM/CLICSHOPPING.php');
spl_autoload_register('ClicShopping\OM\CLICSHOPPING::autoload');

CLICSHOPPING::initialize();

CLICSHOPPING::loadSite('ClicShoppingAdmin');
$CLICSHOPPING_Template = Registry::get('TemplateAdmin');

if (CLICSHOPPING::hasSitePage()) {
  if (CLICSHOPPING::isRPC() === false) {

    $page_file = CLICSHOPPING::getSitePageFile();

    if (empty($page_file) || !is_file($page_file)) {
      HTTP::redirect(
        CLICSHOPPING::getConfig('http_server', 'Shop') .
        CLICSHOPPING::getConfig('http_path', 'Shop') .
        'error_documents/404.php'
      );
    }

    if (CLICSHOPPING::useSiteTemplateWithPageFile()) {
      $headerFile = $CLICSHOPPING_Template->getTemplateHeaderFooterAdmin('header.php');
      if (is_file($headerFile)) {
        require_once($headerFile);
      }
    }

    include($page_file);

    if (CLICSHOPPING::useSiteTemplateWithPageFile()) {
      $footerFile = $CLICSHOPPING_Template->getTemplateHeaderFooterAdmin('footer.php');
      if (is_file($footerFile)) {
        require_once($footerFile);
      }
    }
  }

  goto main_sub3;
}

main_sub3: // Sites and Apps skip to here

$bottomFile = $CLICSHOPPING_Template->getTemplateHeaderFooterAdmin('application_bottom.php');
if (is_file($bottomFile)) {
  require_once($bottomFile);
}
