<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

use ClicShopping\OM\HTML;
use ClicShopping\OM\Registry;

$CLICSHOPPING_MCP = Registry::get('MCP');
$CLICSHOPPING_Template = Registry::get('TemplateAdmin');

$CLICSHOPPING_Page = Registry::get('Site')->getPage();
?>

<div class="contentBody">
  <div class="row">
    <div class="col-md-12">
      <div class="card card-block headerCard">
        <div class="row">
          <span
            class="col-md-1 logoHeading"><?php echo HTML::image($CLICSHOPPING_Template->getImageDirectory() . 'categories/migration.png', $CLICSHOPPING_MCP->getDef('heading_title'), '40', '40'); ?></span>
          <span
            class="col-md-4 pageHeading"><?php echo '&nbsp;' . $CLICSHOPPING_MCP->getDef('heading_title'); ?></span>
          <span class="col-md-7 text-end"><?php echo HTML::button($CLICSHOPPING_MCP->getDef('button_configure'), null, $CLICSHOPPING_MCP->link('Configure'), 'primary'); ?></span>
        </div>
      </div>
    </div>
  </div>
  <div class="mt-2"></div>

  <div class="col-md-12 mainTitle"><strong><?php echo $CLICSHOPPING_MCP->getDef('text_mcp_data'); ?></strong></div>
  <div class="adminformTitle">
     <div class="row">
      <div class="card headerCard">
      <div class="card-body">
        <div class="row">
          <div class="flex-fill">
            <?php echo HTML::button($CLICSHOPPING_MCP->getDef('button_health_monitor'), null, $CLICSHOPPING_MCP->link('HealthMonitor'), 'success'); ?>
            <?php echo HTML::button($CLICSHOPPING_MCP->getDef('button_performance_monitor'), null, $CLICSHOPPING_MCP->link('PerformanceMonitor'), 'danger'); ?>
            <?php echo HTML::button($CLICSHOPPING_MCP->getDef('button_mcp_alert'), null, $CLICSHOPPING_MCP->link('AlertViewer'), 'warning'); ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>