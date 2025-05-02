<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Sites\Common;

use ClicShopping\OM\HTML;
use function strlen;
/**
 * Class HTMLOverrideCommon
 *
 * Provides methods to process and manipulate HTML content. It extends the `HTML` class
 * and adds functionality to strip HTML tags, clean and minify HTML, and minify JavaScript code.
 */
class HTMLOverrideCommon extends HTML
{
  /**
   * Removes invisible characters from a given text.
   *
   * @param string $text The text to clean.
   * @return string The cleaned text without invisible characters.
   */
  public static function removeInvisibleCharacters(string $text): string
  {
    // Liste des caractères invisibles à supprimer (espaces non-coupables, zéros largeur, etc.)
    $invisibleChars = [
      '\u200b',  // Zero Width Space
      '\u200c',  // Zero Width Non-Joiner
      '\u200d',  // Zero Width Joiner
      '\u200e',  // Left-to-Right Mark
      '\u200f',  // Right-to-Left Mark
      '\u00a0',  // Non-breaking space
      '\u202f',  // Narrow non-breaking space
      '\u2060',  // Word joiner
      '\u2028',  // Line separator
      '\u2029',  // Paragraph separator
    ];

    // Remplacer chaque caractère invisible par une chaîne vide
    foreach ($invisibleChars as $char) {
      $text = preg_replace('/' . preg_quote($char, '/') . '/u', '', $text);
    }

    // Retourne le texte nettoyé des caractères invisibles
    return $text;
  }

 /**
  * Cleans an HTML string by removing tags, JavaScript, and HTML entities.
  *
  * @param string $html The HTML content to clean.
  * @param int|null $maxLength Maximum length of the cleaned text.
  * @return string Cleaned and optionally truncated text.
  */
  public static function cleanHtmlOptimized(string $html, ?int $maxLength = null): string
  {
    // Supprime les balises <script> et <style>
    $clean = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
    $clean = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $clean);

    // Supprime toutes les autres balises HTML
    $clean = strip_tags($clean);

    // Décodage des entités HTML pour récupérer du texte lisible
    $clean = html_entity_decode($clean, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $clean = self::removeInvisibleCharacters($clean);
    // Normalisation des espaces
    $clean = preg_replace('/\s+/', ' ', trim($clean));

    // Tronquer si une longueur max est spécifiée
    if ($maxLength !== null && mb_strlen($clean, 'UTF-8') > $maxLength) {
      $clean = mb_substr($clean, 0, $maxLength - 3, 'UTF-8') . '...';
    }

    // Sécurisation XSS (pour affichage web)
    return htmlspecialchars($clean, ENT_QUOTES | ENT_HTML5, 'UTF-8');
  }

 /**
   * Cleans an HTML text by removing unnecessary content for embedding.
   * - Removes scripts, styles, images, iframes, external links.
   * - Retains only the text useful for indexing and searching.
   *
   * @param string $html The HTML content to clean.
   * @return string Cleaned and structured text for embedding.
   */
 public static function cleanHtmlForEmbedding(string $html): string
 {
     if (empty($html)) {
         return '';
     }

     // Pre-process: Convert common encoded characters
     $html = str_replace(['&nbsp;', '&amp;', '&quot;', '&lt;', '&gt;'], [' ', '&', '"', '<', '>'], $html);

     // Preserve meaningful line breaks before stripping tags
     $html = str_replace(['</p>', '</div>', '</h1>', '</h2>', '</h3>', '</h4>', '</h5>', '</h6>', '<br>', '<br/>', '<hr>'], "\n", $html);

     // Strip all non-content elements while preserving meaningful structure
     $clean = preg_replace([
         // Remove scripts, styles and other non-content elements
         '/<(script|style|iframe|object|embed|noscript|svg|canvas|meta|link|form|button|input|select|textarea)[^>]*>.*?<\/\1>/is',
         // Remove images but preserve alt text
         '/<img[^>]*alt=["\']([^"\']*)["\'][^>]*>/i',
         // Replace links with their text content
         '/<a\b[^>]*>(.*?)<\/a>/i',
         // Remove all remaining HTML tags
         '/<[^>]*>/'
     ], [
         '',
         '$1',
         '$1',
         ' '
     ], $html);

     // Convert HTML entities and clean text
     $clean = html_entity_decode($clean, ENT_QUOTES | ENT_HTML5, 'UTF-8');
     $clean = self::removeInvisibleCharacters($clean);

     // Normalize whitespace while preserving meaningful breaks
     $clean = preg_replace('/\s*\n\s*/', "\n", $clean);
     $clean = preg_replace('/[ \t]+/', ' ', $clean);
     $clean = preg_replace('/\n{3,}/', "\n\n", $clean);

     // Keep only valid text characters while preserving structure
     $clean = preg_replace('/[^\p{L}\p{N}\p{P}\s]/u', '', $clean);

     return trim($clean);
 }

 /**
  * Cleans HTML text for SEO by removing harmful tags and normalizing the content.
  *
  * @param string $html The HTML content to clean.
  * @return string Cleaned and optimized text for SEO.
  */
  public static function cleanHtmlForSEO(string $html): string
  {
    // Supprime les balises nuisibles au SEO (scripts, styles, iframes, objets, boutons)
    $clean = preg_replace('/<(script|style|iframe|object|embed|noscript|svg|canvas|meta|link|button|form|input|select|textarea)[^>]*>.*?<\/\1>/is', '', $html);

    // Supprime les balises <img> (images)
    $clean = preg_replace('/<img[^>]*>/i', '', $clean);

    // Supprime les balises <a> mais garde le texte du lien
    $clean = preg_replace('/<a\b[^>]*>(.*?)<\/a>/i', '\1', $clean);

    // Supprime toutes les autres balises HTML
    $clean = strip_tags($clean);

    // Décodage des entités HTML
    $clean = html_entity_decode($clean, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $clean = self::removeInvisibleCharacters($clean);
    // Supprime uniquement les caractères spéciaux non pertinents (évite de supprimer - , / |)
    $clean = preg_replace('/[^\p{L}\p{N}\s,\/|.-]/u', '', $clean);

    // Normalisation des espaces
    $clean = preg_replace('/\s+/', ' ', trim($clean));

    return $clean;
  }

  /**
   * Minifies the given HTML by removing unnecessary whitespaces and optimizing formatting while preserving functionality.
   *
   * @param string $input The HTML string to be minified.
   * @return string The minified HTML string.
   */
  public static function getMinifyHtml(string $input)
  {
    if (trim($input) === '') return $input;
    // Remove extra white-space(s) between HTML attribute(s)
    $input = preg_replace_callback('#<([^\/\s<>!]+)(?:\s+([^<>]*?)\s*|\s*)(\/?)>#s', function ($matches) {
      return '<' . $matches[1] . preg_replace('#([^\s=]+)(\=([\'"]?)(.*?)\3)?(\s+|$)#s', ' $1$2', $matches[2]) . $matches[3] . '>';
    }, str_replace("\r", "", $input));

    if (str_contains($input, '</script>')) {
      $input = preg_replace_callback('#<script(.*?)>(.*?)</script>#is', function ($matches) {
        return '<script' . $matches[1] . '>' . static::getMinifyJS($matches[2]) . '</script>';
      }, $input);
    }

    $array_string = [
      // t = text
      // o = tag open
      // c = tag close
      // Keep important white-space(s) after self-closing HTML tag(s)
      '#<(img|input)(>| .*?>)#s',
      // Remove a line break and two or more white-space(s) between tag(s)
      '#(<!--.*?-->)|(>)(?:\n*|\s{2,})(<)|^\s*|\s*$#s',
      '#(<!--.*?-->)|(?<!\>)\s+(<\/.*?>)|(<[^\/]*?>)\s+(?!\<)#s', // t+c || o+t
      '#(<!--.*?-->)|(<[^\/]*?>)\s+(<[^\/]*?>)|(<\/.*?>)\s+(<\/.*?>)#s', // o+o || c+c
      '#(<!--.*?-->)|(<\/.*?>)\s+(\s)(?!\<)|(?<!\>)\s+(\s)(<[^\/]*?\/?>)|(<[^\/]*?\/?>)\s+(\s)(?!\<)#s', // c+t || t+o || o+t -- separated by long white-space(s)
      '#(<!--.*?-->)|(<[^\/]*?>)\s+(<\/.*?>)#s', // empty tag
      '#<(img|input)(>| .*?>)<\/\1>#s', // reset previous fix
      '#(&nbsp;)&nbsp;(?![<\s])#', // clean up ...
      '#(?<=\>)(&nbsp;)(?=\<)#', // --ibid
      // Remove HTML comment(s) except IE comment(s)
      '#\s*<!--(?!\[if\s).*?-->\s*|(?<!\>)\n+(?=\<[^!])#s'
    ];

    $array_replace = [
      '<$1$2</$1>',
      '$1$2$3',
      '$1$2$3',
      '$1$2$3$4$5',
      '$1$2$3$4$5$6$7',
      '$1$2$3',
      '<$1$2',
      '$1 ',
      '$1',
      ""
    ];

    return preg_replace($array_string, $array_replace, $input);
  }

  /**
   * Minifies a block of JavaScript code by removing unnecessary characters such as comments,
   * extra whitespaces, and semicolons. Also converts certain JavaScript notations to more concise formats.
   *
   * @param string $input The JavaScript code to be minified.
   * @return string The minified JavaScript code.
   */
  public static function getMinifyJS(string $input)
  {
    if (trim($input) === '') return $input;

    $array_string = [
      // Remove comment(s)
      '#\s*("(?:[^"\\\]++|\\\.)*+"|\'(?:[^\'\\\\]++|\\\.)*+\')\s*|\s*\/\*(?!\!|@cc_on)(?>[\s\S]*?\*\/)\s*|\s*(?<![\:\=])\/\/.*(?=[\n\r]|$)|^\s*|\s*$#',
      // Remove white-space(s) outside the string and regex
      '#("(?:[^"\\\]++|\\\.)*+"|\'(?:[^\'\\\\]++|\\\.)*+\'|\/\*(?>.*?\*\/)|\/(?!\/)[^\n\r]*?\/(?=[\s.,;]|[gimuy]|$))|\s*([!%&*\(\)\-=+\[\]\{\}|;:,.<>?\/])\s*#s',
      // Remove the last semicolon
      '#;+\}#',
      // Minify object attribute(s) except JSON attribute(s). From `{'foo':'bar'}` to `{foo:'bar'}`
      '#([\{,])([\'])(\d+|[a-z_][a-z0-9_]*)\2(?=\:)#i',
      // --ibid. From `foo['bar']` to `foo.bar`
      '#([a-z0-9_\)\]])\[([\'"])([a-z_][a-z0-9_]*)\2\]#i'
    ];

    $array_replace = [
      '$1',
      '$1$2',
      '}',
      '$1$3',
      '$1.$3'
    ];

    return preg_replace($array_string, $array_replace, $input);
  }
}