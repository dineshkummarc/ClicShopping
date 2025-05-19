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

$CLICSHOPPING_Cache = Registry::get('Cache');
$CLICSHOPPING_MessageStack = Registry::get('MessageStack');
$CLICSHOPPING_Template = Registry::get('TemplateAdmin');

if (defined('USE_MEMCACHED') && USE_MEMCACHED === 'false') {
  ?>
  <div class="alert alert-warning">
    <?php echo $CLICSHOPPING_Cache->getDef('text_memcache_not_available'); ?>
  </div>
  <?php
} else {
  $memcache = Registry::get('Memcached');
  $CLICSHOPPING_Memcached = Registry::get('Memcached');

  $CLICSHOPPING_Memcached->addServer('127.0.0.1', 11211);
  $stats = $CLICSHOPPING_Memcached->getStats();

  $memcache_available = is_array($stats) && count($stats) > 0;

  if (isset($_POST['reset_memcache'])) {
    $CLICSHOPPING_Memcached->flush();
    $CLICSHOPPING_MessageStack->add($CLICSHOPPING_Cache->getDef('text_memcache_flushed'), 'success');
  }
?>
<div class="contentBody">
  <div class="row">
    <div class="col-md-12">
      <div class="card card-block headerCard">
        <div class="row">
          <span
            class="col-md-1 logoHeading"><?php echo HTML::image($CLICSHOPPING_Template->getImageDirectory() . 'categories/cache.gif', $CLICSHOPPING_Cache->getDef('heading_title'), '40', '40'); ?></span>
          <span
            class="col-md-4 pageHeading"><?php echo '&nbsp;' . $CLICSHOPPING_Cache->getDef('heading_title'); ?></span>
          <span class="col-md-7 text-end">
            <div class="btn-group float-end" role="group">
              <?php
                echo HTML::form('memcached', $CLICSHOPPING_Cache->link('Cache&ResetMemcached'));
                echo HTML::button($CLICSHOPPING_Cache->getDef('text_reset_memcached'), null, null, 'danger'); ?>
              </form>
            </div>
          </span>
        </div>
      </div>
    </div>
  </div>
  <div class="mt-1"></div>

  <div class="row">
    <div class="col-md-12">
      <?php if ($memcache_available): ?>
        <div class="card">
          <div class="card-header">
            <ul class="nav nav-tabs card-header-tabs" role="tablist">
              <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tab1" role="tab"><?php echo $CLICSHOPPING_Cache->getDef('heading_memory_usage'); ?></a></li>
              <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab2" role="tab"><?php echo $CLICSHOPPING_Cache->getDef('heading_cache_statistics'); ?></a></li>
            </ul>
          </div>

          <div class="card-block">
            <div class="tab-content">
              <div class="tab-pane active" id="tab1" role="tabpanel">
                <div class="table-responsive">
                  <table class="table table-hover">
                    <thead>
                    <tr class="dataTableHeadingRow">
                      <th><?php echo $CLICSHOPPING_Cache->getDef('text_memory_type'); ?></th>
                      <th><?php echo $CLICSHOPPING_Cache->getDef('text_value'); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($stats as $server => $data): ?>
                      <tr>
                        <td>Server</td>
                        <td><?php echo $server; ?></td>
                      </tr>
                      <tr>
                        <td><?php echo $CLICSHOPPING_Cache->getDef('text_used_memory'); ?></td>
                        <td><?php echo number_format($data['bytes'] / 1024 / 1024, 2) . ' MB'; ?></td>
                      </tr>
                      <tr>
                        <td><?php echo $CLICSHOPPING_Cache->getDef('text_total_memory'); ?></td>
                        <td><?php echo number_format($data['limit_maxbytes'] / 1024 / 1024, 2) . ' MB'; ?></td>
                      </tr>
                    <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>

              <div class="tab-pane" id="tab2" role="tabpanel">
                <div class="table-responsive">
                  <table class="table table-hover">
                    <thead>
                    <tr class="dataTableHeadingRow">
                      <th><?php echo $CLICSHOPPING_Cache->getDef('text_statistic'); ?></th>
                      <th><?php echo $CLICSHOPPING_Cache->getDef('text_value'); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($stats as $server => $data): ?>
                      <tr>
                        <td>Hits</td>
                        <td><?php echo number_format($data['get_hits']); ?></td>
                      </tr>
                      <tr>
                        <td>Misses</td>
                        <td><?php echo number_format($data['get_misses']); ?></td>
                      </tr>
                      <tr>
                        <td>Evictions</td>
                        <td><?php echo number_format($data['evictions']); ?></td>
                      </tr>
                      <tr>
                        <td>Total Items</td>
                        <td><?php echo number_format($data['total_items']); ?></td>
                      </tr>
                    <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>
        </div>
      <?php else: ?>
        <div class="alert alert-warning">
          <?php echo $CLICSHOPPING_Cache->getDef('text_memcache_not_available'); ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php
}