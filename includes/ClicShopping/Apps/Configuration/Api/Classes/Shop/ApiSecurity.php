<?php
/**
 *
 * @copyright 2008 - https://www.clicshopping.org
 * @Brand : ClicShoppingAI(TM) at Inpi all right Reserved
 * @Licence GPL 2 & MIT
 * @Info : https://www.clicshopping.org/forum/trademark/
 *
 */

namespace ClicShopping\Apps\Configuration\Api\Classes\Shop;

use ClicShopping\OM\CLICSHOPPING;
use ClicShopping\OM\HTTP;
use ClicShopping\OM\Registry;
use Exception;

class ApiSecurity {

  const SESSION_TIMEOUT_MINUTES = 60;
  const MAX_LOGIN_ATTEMPTS = 5;
  const RATE_LIMIT_WINDOW = 900; // 15 minutes
  const MAX_REQUESTS_PER_WINDOW = 50;
  const ACCOUNT_LOCK_DURATION = 1800; // 30 minutes

  /**
   * Enregistre un événement de sécurité dans la base de données
   * @param string $eventType Type d'événement (e.g., 'login_attempt', 'rate_limit_exceeded')
   * @param array $details Détails supplémentaires sur l'événement
   */
  public static function isAccountLocked(string $username): bool
  {
    try {
      $CLICSHOPPING_Db = Registry::get('Db');

      $key = 'login_attempts_' . hash('sha256', $username);

      $Qattempts = $CLICSHOPPING_Db->get('api_failed_attempts', [
        'attempts',
        'last_attempt'
      ], [
        'identifier' => $key
      ]);

      if ($Qattempts->rowCount() === 0) {
        return false;
      }

      $attempts = $Qattempts->valueInt('attempts');
      $lastAttempt = $Qattempts->valueInt('last_attempt');

      // Compte verrouillé si MAX_LOGIN_ATTEMPTS dépassé
      if ($attempts >= self::MAX_LOGIN_ATTEMPTS) {
        $timeSinceLastAttempt = time() - $lastAttempt;
        // Déverrouiller après 30 minutes (1800 secondes)
        return $timeSinceLastAttempt < self::ACCOUNT_LOCK_DURATION;
      }

      return false;

    } catch (\Exception $e) {
      self::logSecurityEvent('Error checking account lock status', [
        'username' => $username,
        'error' => $e->getMessage()
      ]);

      return false; // En cas d'erreur, ne pas bloquer
    }
  }

  /* Enregistre un événement de sécurité dans la base de données
   * @param string $eventType Type d'événement (e.g., 'login_attempt', 'rate_limit_exceeded')
   * @param array $details Détails supplémentaires sur l'événement
   */
  public static function checkRateLimit(string $identifier, string $action): bool
  {
    try {

      $CLICSHOPPING_Db = Registry::get('Db');

      $key = $action . '_' . hash('sha256', $identifier);
      $window_start = time() - self::RATE_LIMIT_WINDOW;

      // Nettoyer les anciennes entrées
      $Qdelete = $CLICSHOPPING_Db->prepare('delete 
                                            from :table_api_rate_limit 
                                            where timestamp < :window_start');
      $Qdelete->bindValue(':window_start', $window_start);
      $Qdelete->execute();


      // Compter les tentatives récentes
      $Qcount = $CLICSHOPPING_Db->prepare('select count(id) as count 
                                          from :table_api_rate_limit
                                          where  identifier = :identifier
                                          and  timestamp >= :timestamp
                                          ');
      $Qcount->bindValue(':identifier', $key);
      $Qcount->bindValue(':timestamp', $window_start);
      $Qcount->execute();

      $attempts = $Qcount->valueInt('count') ?? 0;

      if ($attempts >= self::MAX_REQUESTS_PER_WINDOW) {
        return false;
      }

      // Enregistrer cette tentative
      $CLICSHOPPING_Db->save('api_rate_limit', [
        'identifier' => $key,
        'timestamp' => time(),
        'ip' => HTTP::getIpAddress()
      ]);

      return true;

    } catch (\Exception $e) {
      self::logSecurityEvent('Rate limit check failed', [
        'identifier' => $identifier,
        'action' => $action,
        'error' => $e->getMessage()
      ]);

      return true;
    }
  }

  /**
   * Enregistre un événement de sécurité dans la base de données
   * @param string $eventType Type d'événement (e.g., 'login_attempt', 'rate_limit_exceeded')
   * @param array $details Détails supplémentaires sur l'événement
   */
  public static function incrementFailedAttempts(string $username): void
  {
    try {
      $CLICSHOPPING_Db = Registry::get('Db');

      $key = 'login_attempts_' . hash('sha256', $username);

      $Qexisting = $CLICSHOPPING_Db->get('api_failed_attempts', ['attempts', 'last_attempt'], [
        'identifier' => $key
      ]);

      if ($Qexisting->rowCount() > 0) {
        $attempts = $Qexisting->valueInt('attempts') + 1;

        $CLICSHOPPING_Db->save('api_failed_attempts', [
          'attempts' => $attempts,
          'last_attempt' => time()
        ], ['identifier' => $key]);
      } else {
        $CLICSHOPPING_Db->save('api_failed_attempts', [
          'identifier' => $key,
          'attempts' => 1,
          'last_attempt' => time()
        ]);
      }
    } catch (\Exception $e) {
      // Log mais ne pas faire échouer l'authentification
      self::logSecurityEvent('Failed to increment failed attempts', [
        'username' => $username,
        'error' => $e->getMessage()
      ]);
    }
  }

  /**
   * Enregistre un événement de sécurité dans la base de données
   * @param string $username
   */
  public static function resetFailedAttempts(string $username)
  {
    try {
      $CLICSHOPPING_Db = Registry::get('Db');
      $key = 'login_attempts_' . hash('sha256', $username);

      $CLICSHOPPING_Db->delete('api_failed_attempts', ['identifier' => $key]);
    } catch (\Exception $e) {
      // Log mais ne pas faire échouer l'authentification
      self::logSecurityEvent('Failed to reset failed attempts', [
        'username' => $username,
        'error' => $e->getMessage()
      ]);
    }
  }

  /** Enregistre un événement de sécurité dans un fichier de log
   * @param string $event Type d'événement (e.g., 'login_attempt', 'rate_limit_exceeded')
   * @param array $data Détails supplémentaires sur l'événement
   */
  public static function logSecurityEvent(string $event, array $data = [])
  {
    try {
      $logData = [
        'timestamp' => date('c'),
        'event' => $event,
        'ip' => HTTP::getIpAddress(),
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
        'data' => $data
      ];

      $logDir = CLICSHOPPING::getConfig('dir_root', 'Shop') . 'Work/Log/';
      $logFile = $logDir . 'api_security.log';

      if (!is_dir($logDir)) {
        if (!mkdir($logDir, 0755, true) && !is_dir($logDir)) {
          throw new \Runtime\Exception(sprintf('Directory "%s" was not created', $logDir));
        }
      }

      file_put_contents($logFile, json_encode($logData) . PHP_EOL, FILE_APPEND | LOCK_EX);
    } catch (\Throwable $e) {
      error_log('[API_SECURITY_LOG_ERROR] ' . $e->getMessage());
    }
  }


  /**
   * Validates IP address against allowed IPs for the API with enhanced security
   *
   * @param int $api_id The API ID used to retrieve the associated IP address.
   * @return bool Returns true if IP is allowed, false otherwise.
   * @throws Exception If validation fails due to database error
   */
 public static function validateIp(int $api_id): bool
 {
   if ($api_id <= 0) {
     self::logSecurityEvent('Invalid API ID in IP check', ['api_id' => $api_id]);
     return false;
   }

   try {
     $CLICSHOPPING_Db = Registry::get('Db');

     if (!$CLICSHOPPING_Db) {
       throw new \Exception("Database connection not available");
     }

     $clientIp = HTTP::getIpAddress();

     if (empty($clientIp)) {
       self::logSecurityEvent('Unable to determine client IP', ['api_id' => $api_id]);
       return false;
     }

     $Qips = $CLICSHOPPING_Db->get('api_ip', 'ip', ['api_id' => $api_id]);

     if (empty($Qips)) {
       self::logSecurityEvent('No IP restrictions found for API', [
         'api_id' => $api_id,
         'client_ip' => $clientIp
       ]);
       // Si aucune restriction IP, autoriser (comportement par défaut)
       return true;
     }

     foreach ($Qips as $allowedIp) {
       $ip = $allowedIp['ip'];

       // Vérifications de sécurité améliorées
       if ($ip === '127.0.0.1' || $ip === 'localhost') {
         // Autoriser localhost seulement si la requête vient vraiment de localhost
         if (in_array($clientIp, ['127.0.0.1', '::1'])) {
           self::logSecurityEvent('Localhost access granted', [
             'api_id' => $api_id,
             'client_ip' => $clientIp
           ]);
           return true;
         }
       } elseif ($ip === $clientIp) {
         self::logSecurityEvent('IP match found', [
           'api_id' => $api_id,
           'client_ip' => $clientIp,
           'allowed_ip' => $ip
         ]);
         return true;
       } // Support pour les ranges CIDR si nécessaire
       elseif (self::ipInRange($clientIp, $ip)) {
         self::logSecurityEvent('IP in allowed range', [
           'api_id' => $api_id,
           'client_ip' => $clientIp,
           'range' => $ip
         ]);
         return true;
       }
     }

     self::logSecurityEvent('IP access denied', [
       'api_id' => $api_id,
       'client_ip' => $clientIp,
       'allowed_ips' => array_column($Qips, 'ip')
     ]);

     return false;

   } catch (PDOException $e) {
     self::logSecurityEvent('Database error in IP check', [
       'api_id' => $api_id,
       'error' => $e->getMessage()
     ]);
     throw new \Exception("IP validation failed");
   }
  }

  /**
   * Check if an IP address is within a given range (CIDR)
   *
   * @param string $ip The IP address to check
   * @param string $range The CIDR range (e.g., '
   */
  public static function ipInRange(string $ip, string $range): bool
  {
    if (strpos($range, '/') === false) {
      return $ip === $range;
    }

    list($subnet, $bits) = explode('/', $range);
    $ip = ip2long($ip);
    $subnet = ip2long($subnet);
    $mask = -1 << (32 - $bits);
    $subnet &= $mask;

    return ($ip & $mask) === $subnet;
  }
}