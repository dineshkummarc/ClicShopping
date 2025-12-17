<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\OM\Module\Hooks\ClicShoppingAdmin\Header;

use ClicShopping\OM\CLICSHOPPING;

class HeaderOutputRag
{
  /**
   * Generates and returns HTML output for embedding charts if the current session belongs to an admin user.
   *
   * @return string|bool Returns the generated HTML string if the session is admin; otherwise, returns false.
   */
  public function display(): string|bool
  {
    $output = '';
    $status =  \defined('CLICSHOPPING_APP_CHATGPT_RA_STATUS') ?? 'False';

    if (isset($_SESSION['admin']) && $status == 'True') {
      $css_url = CLICSHOPPING::link('css/RAG/rag_dashboard.css');

      $output .= '<!-- Start Chart -->' . "\n";
      $output .= ' <link rel="stylesheet" href="'. $css_url . '" media="screen, print">' . "\n";
      $output .= '<!-- End Chart -->' . "\n";
    } else {
      return false;
    }

    return $output;
  }
}