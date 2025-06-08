<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 *
 * In order to minimize the number and size of HTTP requests for CSS content,
 * this script combines multiple CSS files into a single file and compresses
 * it on-the-fly.
 *
 * To use this in your HTML, link to it in the usual way:
 * <link rel="stylesheet" type="text/css" media="screen, print" href="/css/compressed.css.php" />
 */

// Configuration de sécurité
define('MAX_FILE_SIZE', 2097152); // 2MB par fichier CSS
define('MAX_TOTAL_SIZE', 10485760); // 10MB total
define('CACHE_DURATION', 86400); // 24 heures

// Désactiver l'affichage des erreurs en production
error_reporting(0);
ini_set('display_errors', 0);

// Logger les erreurs de sécurité
function log_security_error($message, $file = null) {
    $log_message = "[" . date('Y-m-d H:i:s') . "] CSS Compressor Security: " . $message;

    if ($file) {
        $log_message .= " - File: " . $file;
    }

    error_log($log_message);
}

// Fonction sécurisée pour récupérer les fichiers CSS
function get_files_secure($root_dir, $all_data = []) {
    // Normaliser le répertoire racine
    $root_dir = realpath($root_dir);
    if (!$root_dir || !is_dir($root_dir) || !is_readable($root_dir)) {
        log_security_error("Invalid or unreadable root directory", $root_dir);
        return [];
    }

    // Extensions autorisées (whitelist stricte)
    $allowed_extensions = ['css'];

    // Fichiers spécifiquement exclus
    $ignore_files = [
        'general/stylesheet.css',
        'general/stylesheet_responsive.css',
        'general/link_general.css',
        'general/link_general_responsive.css',
        'modules_boxes/modules_boxes_general.css',
        'modules_checkout_payment/modules_checkout_payment_general.css',
        'modules_checkout_shipping/modules_checkout_shipping_general.css',
        'modules_footer/modules_footer_general.css',
        'modules_front_page/modules_front_page_general.css',
        'modules_header/modules_header_general.css',
        'modules_index_categories/modules_index_categories_general.css',
        'modules_login/modules_login_general.css',
        'modules_products_info/modules_products_info_general.css',
        'modules_products_listing/modules_products_listing_general.css',
        'modules_products_new/modules_products_new_general.css',
        'modules_products_specials/modules_products_specials_general.css',
        'modules_shopping_cart/modules_shopping_cart_general.css',
        'modules_products_search/modules_products_search_general.css',
        'general/bootstrap_customize.css'
    ];

    // Pattern pour ignorer les fichiers commençant par _
    $ignore_regex = '/^_/';

    // Répertoires à ignorer
    $ignore_dirs = ['.', '..', '.git', '.svn', 'node_modules', 'vendor'];

    // Scanner le répertoire de façon sécurisée
    $dir_content = @scandir($root_dir, SCANDIR_SORT_ASCENDING);
    if (!$dir_content) {
        log_security_error("Failed to scan directory", $root_dir);
        return $all_data;
    }

    foreach ($dir_content as $content) {
        // Ignorer les entrées vides ou dangereuses
        if (empty($content) || $content === '.' || $content === '..') {
            continue;
        }

        // Construire le chemin complet
        $path = $root_dir . DIRECTORY_SEPARATOR . $content;

        // Résoudre le chemin réel et vérifier la sécurité
        $real_path = realpath($path);
        if (!$real_path) {
            log_security_error("Invalid path detected", $path);
            continue;
        }

        // Vérifier que le fichier est bien dans le répertoire autorisé (protection path traversal)
        if (strpos($real_path, $root_dir) !== 0) {
            log_security_error("Path traversal attempt detected", $real_path);
            continue;
        }

        if (is_file($real_path) && is_readable($real_path)) {
            // Vérifier la taille du fichier
            $file_size = filesize($real_path);
            if ($file_size === false || $file_size > MAX_FILE_SIZE) {
                log_security_error("File too large or unreadable", $real_path);
                continue;
            }

            // Créer le chemin relatif pour la comparaison
            $relative_path = str_replace($root_dir . DIRECTORY_SEPARATOR, '', $real_path);
            $relative_path = str_replace(DIRECTORY_SEPARATOR, '/', $relative_path);

            // Vérifier si le fichier est dans la liste d'exclusion
            if (in_array($relative_path, $ignore_files)) {
                continue;
            }

            // Vérifier le pattern d'exclusion
            if (preg_match($ignore_regex, basename($content))) {
                continue;
            }

            // Valider l'extension
            $file_extension = strtolower(pathinfo($content, PATHINFO_EXTENSION));
            if (!in_array($file_extension, $allowed_extensions)) {
                continue;
            }

            // Vérifier que c'est bien un fichier CSS (validation MIME basique)
            $file_content_sample = file_get_contents($real_path, false, null, 0, 100);
            if ($file_content_sample === false) {
                log_security_error("Cannot read file sample", $real_path);
                continue;
            }

            // Ajouter le fichier à la liste
            $all_data[] = $real_path;

        } elseif (is_dir($real_path) && is_readable($real_path)) {
            // Vérifier si le répertoire n'est pas dans la liste d'exclusion
            if (in_array($content, $ignore_dirs)) {
                continue;
            }

            // Récursion sécurisée avec limite de profondeur
            static $depth = 0;
            if ($depth < 10) { // Limite la profondeur pour éviter les attaques
                $depth++;
                $all_data = get_files_secure($real_path, $all_data);
                $depth--;
            } else {
                log_security_error("Maximum directory depth reached", $real_path);
            }
        }
    }

    return $all_data;
}

// Fonction pour sanitiser le contenu CSS
function sanitize_css_content($content) {
    // Supprimer les expressions JavaScript potentiellement dangereuses
    $content = preg_replace('/expression\s*\(/i', '', $content);
    $content = preg_replace('/javascript\s*:/i', '', $content);
    $content = preg_replace('/vbscript\s*:/i', '', $content);
    $content = preg_replace('/data\s*:\s*text\/html/i', '', $content);

    // Supprimer les imports externes non sécurisés
    $content = preg_replace('/@import\s+url\s*\(\s*["\']?https?:\/\/[^"\']*["\']?\s*\)/i', '', $content);

    return $content;
}

// Fonction pour compresser le CSS de façon sécurisée
function compress_css($content) {
    // Supprimer les commentaires CSS
    $content = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $content);

    // Supprimer les espaces après les deux points
    $content = str_replace(': ', ':', $content);

    // Supprimer les espaces et sauts de ligne inutiles
    $content = str_replace(["\r\n", "\r", "\n", "\t"], '', $content);

    // Supprimer les espaces multiples
    $content = preg_replace('/\s+/', ' ', $content);

    // Supprimer les espaces autour des caractères spéciaux
    $content = str_replace([' {', '{ ', ' }', '} ', ' ;', '; ', ' ,', ', '], ['{', '{', '}', '}', ';', ';', ',', ','], $content);

    return trim($content);
}

// Initialisation sécurisée
try {
    $root_dir = realpath(__DIR__);
    if (!$root_dir) {
        throw new Exception("Cannot determine root directory");
    }

    // Obtenir tous les fichiers CSS de façon sécurisée
    $files_array = get_files_secure($root_dir);

    // Créer les chemins relatifs
    $files_css_replace = [];
    foreach ($files_array as $file) {
        $relative = str_replace($root_dir . DIRECTORY_SEPARATOR, '', $file);
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
        $files_css_replace[] = $relative;
    }

    $cssFilesaddon = $files_css_replace;

    // Fichiers CSS prioritaires (ordre d'inclusion)
    $cssFiles = [
        'general/stylesheet.css',
        'general/stylesheet_responsive.css',
        'general/link_general.css',
        'general/link_general_responsive.css',
        'modules_boxes/modules_boxes_general.css',
        'modules_checkout_payment/modules_checkout_payment_general.css',
        'modules_checkout_shipping/modules_checkout_shipping_general.css',
        'modules_footer/modules_footer_general.css',
        'modules_front_page/modules_front_page_general.css',
        'modules_header/modules_header_general.css',
        'modules_index_categories/modules_index_categories_general.css',
        'modules_login/modules_login_general.css',
        'modules_products_info/modules_products_info_general.css',
        'modules_products_listing/modules_products_listing_general.css',
        'modules_products_new/modules_products_new_general.css',
        'modules_products_specials/modules_products_specials_general.css',
        'modules_shopping_cart/modules_shopping_cart_general.css',
        'modules_products_search/modules_products_search_general.css',
        'general/bootstrap_customize.css'
    ];

    // Fusionner les listes de fichiers
    $cssFiles = array_merge($cssFiles, $cssFilesaddon);

    // Supprimer les doublons et valider les fichiers
    $cssFiles = array_unique($cssFiles);
    $validated_files = [];
    $total_size = 0;

    foreach ($cssFiles as $cssFile) {
        // Construire le chemin complet
        $full_path = $root_dir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $cssFile);
        $real_path = realpath($full_path);

        // Vérifications de sécurité
        if (!$real_path || !file_exists($real_path) || !is_readable($real_path)) {
            log_security_error("CSS file not accessible", $cssFile);
            continue;
        }

        // Vérifier que le fichier est dans le répertoire autorisé
        if (strpos($real_path, $root_dir) !== 0) {
            log_security_error("CSS file outside allowed directory", $cssFile);
            continue;
        }

        // Vérifier la taille
        $file_size = filesize($real_path);
        if ($file_size === false || $file_size > MAX_FILE_SIZE) {
            log_security_error("CSS file too large", $cssFile);
            continue;
        }

        $total_size += $file_size;
        if ($total_size > MAX_TOTAL_SIZE) {
            log_security_error("Total CSS size limit exceeded");
            break;
        }

        $validated_files[] = $real_path;
    }

    // Traitement des fichiers CSS validés
    $buffer = '';
    foreach ($validated_files as $css_file) {
        $content = file_get_contents($css_file);
        if ($content === false) {
            log_security_error("Failed to read CSS file", $css_file);
            continue;
        }

        // Sanitiser le contenu
        $content = sanitize_css_content($content);
        $buffer .= $content . "\n";
    }

    // Compresser le CSS final
    $buffer = compress_css($buffer);

    // Génération d'un hash sécurisé pour le cache
    $content_hash = hash('sha256', $buffer);
    $etag = '"' . substr($content_hash, 0, 16) . '"';

    // Gestion du cache sécurisée
    $timestamp = time() + CACHE_DURATION;
    $last_modified = gmdate('D, d M Y H:i:s', time()) . ' GMT';
    $expires = gmdate('D, d M Y H:i:s', $timestamp) . ' GMT';

    // Vérifier les en-têtes de cache du client
    $if_modified_since = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
    $if_none_match = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';

    // Réponse 304 si le contenu n'a pas changé
    if (($if_none_match && $if_none_match === $etag) ||
        ($if_modified_since && strtotime($if_modified_since) >= strtotime($last_modified))) {
        http_response_code(304);
        header('Cache-Control: public, max-age=' . CACHE_DURATION);
        header('ETag: ' . $etag);
        exit();
    }

    // Activer la compression GZIP si disponible
    if (extension_loaded('zlib') && !ob_get_level()) {
        ob_start('ob_gzhandler');
    }

    // En-têtes de sécurité et de cache
    header('Content-Type: text/css; charset=utf-8');
    header('Cache-Control: public, max-age=' . CACHE_DURATION);
    header('Last-Modified: ' . $last_modified);
    header('Expires: ' . $expires);
    header('ETag: ' . $etag);
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');

    // Sortir le CSS compressé
    echo $buffer;

} catch (Exception $e) {
    // En cas d'erreur, logger et retourner une réponse d'erreur propre
    log_security_error("Critical error: " . $e->getMessage());

    http_response_code(500);
    header('Content-Type: text/css; charset=utf-8');
    echo '/* CSS compression error - check server logs */';
}

// Nettoyer les buffers de sortie
if (ob_get_level()) {
    ob_end_flush();
}

