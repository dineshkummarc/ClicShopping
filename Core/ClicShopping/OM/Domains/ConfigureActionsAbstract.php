<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\OM\Domains;

use ClicShopping\OM\Cache;
use ClicShopping\OM\Registry;

/**
 * Abstract base class for configuration actions (Install, Delete, Process, Uninstall)
 * Provides common functionality for all configuration actions across all apps
 */
abstract class ConfigureActionsAbstract extends PagesActionsAbstract
{
  protected $app;
  protected $appName;
  protected $appKey;
  protected $messageStack;
  
  /**
   * Initialize common properties based on the namespace
   */
  protected function init()
  {
    // Extract app information from namespace
    $reflection = new \ReflectionClass($this);
    $namespace = $reflection->getNamespaceName();
    $parts = explode('\\', $namespace);
    
    if (count($parts) >= 4) {
      $this->appName = $parts[3];
      $this->appKey = $this->appName; // Registry key
    }
    
    $this->app = Registry::get($this->appKey);
    $this->messageStack = Registry::get('MessageStack');
  }
  
  /**
   * Get the current module from page data
   */
  protected function getCurrentModule(): string
  {
    return $this->page->data['current_module'] ?? '';
  }
  
  /**
   * Get the configuration module instance
   */
  protected function getConfigModule(string $module)
  {
    return Registry::get($this->appKey . 'AdminConfig' . $module);
  }
  
  /**
   * Redirect to the configure page
   */
  protected function redirectToConfigure(string $module): void
  {
    $this->app->redirect('Configure&module=' . $module);
  }
  
  /**
   * Add success message
   */
  protected function addSuccessMessage(string $message): void
  {
    $this->messageStack->add($message, 'success', $this->appKey);
  }
  
  /**
   * Clear administrator menu cache
   */
  protected function clearMenuCache(): void
  {
    Cache::clear('menu-administrator');
  }

  /**
   * Remove all entries from a specified database table
   */
  protected function removeTableNameFromDb(string $table_name): void
  {
    $CLICSHOPPING_Db = Registry::get('Db');

    $Qcheck = $CLICSHOPPING_Db->query('show tables like ":table_' .$table_name . '"');

    if ($Qcheck->fetch() !== false) {
      $Qdelete = $CLICSHOPPING_Db->prepare('delete from :table_' .$table_name . '');
      $Qdelete->execute();
    }
  }
}