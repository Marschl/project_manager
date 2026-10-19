<?php

class rex_cronjob_project_manager_pagespeed extends rex_cronjob
{
  
    const BLOCK_REQUESTS = 20;      // 10 Projekte je Block, je zwei Abrufe (mobile, desktop)
    const DEFAULT_RATE_LIMIT = 30;  // Abfragen pro Minute und Projekt (Standard-Kontingent der PageSpeed-API)
    const CONFIG_NAMESPACE = 'project_manager/pagespeed';
    
    /**
     * Wurde im letzten Block das Pro-Minute-Limit der API überschritten?
     */
    protected $rateLimited = false;
  
    public function execute() {
      
      $count = rex_sql::factory()->setDebug(0)->getArray('SELECT count(*) as count FROM rex_project_manager_domain');
      $blocks = max(1, (int) ceil($count[0]['count'] / 10));
      
      $limit = $this->getTimeLimit();
      $perMinute = $this->getRateLimit();
      $start = $this->now();
      $success = true;
      $lastBlock = 20.0; // Schätzung, bis ein Block gemessen ist
      
      for ($i = 0; $i < $blocks; $i++) {
        
        // Abstand zum letzten Block einhalten, auch über Läufe hinweg (Pro-Minute-Limit)
        $wait = $this->secondsToWait($perMinute);
        $elapsed = $this->now() - $start;
        
        // Der erste Block läuft immer, solange nicht gewartet werden muss. Jeder weitere nur, wenn die Zeit
        // reicht. Die Projekte werden nach dem ältesten Eintrag abgearbeitet, der Rest folgt im nächsten Lauf.
        if (($i > 0 || $wait > 0) && !self::hasTimeForNextBlock($elapsed + $wait, $lastBlock, $limit)) {
          if ($success) {
            $this->setMessage($i . ' von ' . $blocks . ' Blöcken, Rest im nächsten Lauf (max_execution_time ' . $limit . ' s, Limit ' . $perMinute . ' Abfragen pro Minute)');
          }
          break;
        }
        
        if ($wait > 0) {
          $this->pause($wait);
        }
        
        $blockStart = $this->now();
        rex_config::set(self::CONFIG_NAMESPACE, 'project_manager_pagespeed_last_block', $blockStart);
        
        if (!$this->getData()) {
          $success = false;
        }
        
        $lastBlock = $this->now() - $blockStart;
        
        // Nach einem Ratenlimit-Fehler scheitert auch der nächste Block, der Rest folgt im nächsten Lauf
        if ($this->rateLimited) {
          break;
        }
      }
      
      return $success;
      
    }
    
    /**
     * max_execution_time in Sekunden, 0 = unbegrenzt
     */
    protected function getTimeLimit() {
      return (int) ini_get('max_execution_time');
    }
    
    /**
     * Abfragen pro Minute. Startwert 30, wird aus der Fehlerantwort der API übernommen (siehe learnRateLimit()).
     */
    protected function getRateLimit() {
      return self::currentRateLimit();
    }
    
    /**
     * Abfragen pro Minute (gespeicherter Wert oder Startwert 30)
     *
     * @return int
     */
    public static function currentRateLimit() {
      return (int) rex_config::get(self::CONFIG_NAMESPACE, 'project_manager_pagespeed_rate_limit', self::DEFAULT_RATE_LIMIT);
    }
    
    protected function now() {
      return microtime(true);
    }
    
    protected function pause($seconds) {
      sleep($seconds);
    }
    
    /**
     * Sekunden, die bis zum nächsten erlaubten Blockstart noch fehlen (0 = sofort).
     */
    protected function secondsToWait($perMinute) {
      return self::secondsUntilNextBlock($perMinute, $this->now());
    }
    
    /**
     * @param int   $perMinute Abfragen pro Minute, 0 = keine Drosselung
     * @param float $now       aktuelle Zeit (microtime)
     *
     * @return int
     */
    public static function secondsUntilNextBlock($perMinute, $now) {
      if ($perMinute <= 0) {
        return 0;
      }
      
      $last = (float) rex_config::get(self::CONFIG_NAMESPACE, 'project_manager_pagespeed_last_block', 0);
      
      return max(0, (int) ceil($last + self::minBlockInterval($perMinute) - $now));
    }
    
    /**
     * Wie viele Projekte haben einen aktuellen PageSpeed-Wert (Status 1, nicht älter als $hours Stunden)?
     *
     * @param array[] $items Zeilen mit status_psi und createdate_psi
     * @param int     $hours
     * @param int     $now   Unix-Zeit
     *
     * @return int
     */
    public static function countCurrent(array $items, $hours, $now) {
      $current = 0;
      
      foreach ($items as $item) {
        if (isset($item['status_psi'], $item['createdate_psi']) && 1 == $item['status_psi'] && strtotime($item['createdate_psi']) >= $now - $hours * 3600) {
          $current++;
        }
      }
      
      return $current;
    }
    
    /**
     * Mindestabstand zwischen den Starts zweier Blöcke in Sekunden. Alle Abrufe eines Blocks starten
     * gleichzeitig, pro Minute passen daher floor(Limit / Abrufe je Block) Blöcke. Dazu 2 s Reserve.
     *
     * @param int $perMinute Abfragen pro Minute
     *
     * @return float
     */
    public static function minBlockInterval($perMinute) {
      $blocksPerMinute = max(1, (int) floor($perMinute / self::BLOCK_REQUESTS));
      
      return 60 / $blocksPerMinute + 2;
    }
    
    /**
     * Reicht die Zeit für einen weiteren Block? Als Dauer eines Blocks gilt das
     * Anderthalbfache des zuletzt gemessenen Blocks (mit Reserve für das Schreiben).
     *
     * @param float $elapsed   Laufzeit in Sekunden bis zum Start des Blocks (inkl. Wartezeit)
     * @param float $lastBlock Dauer des letzten Blocks in Sekunden
     * @param int   $limit     max_execution_time in Sekunden, 0 = unbegrenzt
     *
     * @return bool
     */
    public static function hasTimeForNextBlock($elapsed, $lastBlock, $limit) {
      if ($limit <= 0) {
        return true;
      }
      
      return $elapsed + 1.5 * $lastBlock < $limit;
    }
    
    /**
     * Ist die Antwort der API ein Ratenlimit-Fehler (HTTP 429, rateLimitExceeded)?
     */
    public static function isRateLimitError($pagespeed) {
      if (!is_array($pagespeed)) {
        return false;
      }
      
      return (isset($pagespeed['error']['code']) && 429 == $pagespeed['error']['code'])
        || (isset($pagespeed['error']['errors'][0]['reason']) && 'rateLimitExceeded' === $pagespeed['error']['errors'][0]['reason']);
    }
    
    /**
     * Meldung für das Cronjob-Log aus der Fehlerantwort der API.
     */
    public static function errorMessage($pagespeed) {
      if (isset($pagespeed['error']['errors'][0]['message'])) {
        return $pagespeed['error']['errors'][0]['message'] . ': ' . (isset($pagespeed['error']['errors'][0]['reason']) ? $pagespeed['error']['errors'][0]['reason'] : '');
      }
      
      return 'Ungültige Antwort der PageSpeed-API';
    }
    
    /**
     * Das Kontingent steht in der Fehlerantwort (metadata.quota_limit_value, Einheit 1/min/{project}).
     * Es wird gespeichert und von secondsToWait() genutzt, damit der Job es künftig nicht mehr überschreitet.
     */
    protected function learnRateLimit($pagespeed) {
      if (!isset($pagespeed['error']['details']) || !is_array($pagespeed['error']['details'])) {
        return;
      }
      
      foreach ($pagespeed['error']['details'] as $detail) {
        if (isset($detail['metadata']['quota_unit'], $detail['metadata']['quota_limit_value'])
          && '1/min/{project}' === $detail['metadata']['quota_unit']
          && (int) $detail['metadata']['quota_limit_value'] > 0
        ) {
          rex_config::set(self::CONFIG_NAMESPACE, 'project_manager_pagespeed_rate_limit', (int) $detail['metadata']['quota_limit_value']);
          return;
        }
      }
    }
    

    public function getData() {

        $this->rateLimited = false;

        $websites = rex_sql::factory()->setDebug(0)->getArray('SELECT D.domain AS domain, D.is_ssl as is_ssl FROM
        (SELECT domain, createdate FROM rex_project_manager_domain_psi) AS PSI
        RIGHT JOIN
        (SELECT domain, updatedate, is_ssl FROM rex_project_manager_domain) AS D
        ON
        PSI.domain = D.domain
        GROUP BY D.domain
        ORDER BY PSI.createdate ASC LIMIT 10');
        
        $error = false;
        $multi_curl = curl_multi_init();
        $resps = array();
        $options = array(
          CURLOPT_FOLLOWLOCATION => true,
          CURLOPT_AUTOREFERER    => true,
          CURLOPT_MAXREDIRS      => 4,
          CURLOPT_HEADER         => false,
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_SSL_VERIFYPEER => false,
          CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:86.0) Gecko/20100101 Firefox/86.0',
          CURLOPT_TIMEOUT => 30 // seconds
        );
      
        foreach($websites as $website) {
          
          $fstreams = array();
          
          $domain = $website['domain'];
          if($website['is_ssl']) {
              $prefix = "https://";
          } else {
              $prefix = "http://";
          }
  
          $url_desktop = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?filter_third_party_resources=false&locale=de_DE&screenshot=true&snapshots=false&strategy=desktop&key='.rex_config::get('project_manager/pagespeed', 'project_manager_pagespeed_api_key').'&url='.urlencode($prefix.$website['domain']);
          $url_mobile = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?filter_third_party_resources=false&locale=de_DE&screenshot=true&snapshots=false&strategy=mobile&key='.rex_config::get('project_manager/pagespeed', 'project_manager_pagespeed_api_key').'&url='.urlencode($prefix.$website['domain']);
          $resps[$domain.";mobile"] = curl_init($url_mobile);
          $resps[$domain.";desktop"] = curl_init($url_desktop);
          curl_setopt_array($resps[$domain.";mobile"], $options);
          curl_setopt_array($resps[$domain.";desktop"], $options);
          curl_multi_add_handle($multi_curl, $resps[$domain.";mobile"]);
          curl_multi_add_handle($multi_curl, $resps[$domain.";desktop"]);
         
        }
        
        $active = null;
        do {
          curl_multi_exec($multi_curl, $active);
        } while ($active > 0);
   
 
        foreach ($resps as $key => $response) {
          $domain = explode(";", $key)[0];
          $mode = explode(";", $key)[1];
          $resp = curl_multi_getcontent($response);
          curl_multi_remove_handle($multi_curl, $response);
          $pagespeed = json_decode($resp, true);
          
          $score_mobile = 0;
          $score_desktop = 0;
          if (isset($pagespeed['lighthouseResult'])) {
            if ($pagespeed['lighthouseResult']['categories']['performance']['score'] != NULL) $score_mobile = $pagespeed['lighthouseResult']['categories']['performance']['score'];
            if ($pagespeed['lighthouseResult']['categories']['performance']['score'] != NULL) $score_desktop = $pagespeed['lighthouseResult']['categories']['performance']['score'];
          }
          if(json_last_error() === JSON_ERROR_NONE && (!array_key_exists("error", $pagespeed)))  {
           		if($mode == "mobile") {
                  rex_sql::factory()->setDebug(0)->setQuery('INSERT INTO ' . rex::getTable('project_manager_domain_psi') . ' (`domain`, `raw`, `createdate`, `score_mobile`, `status`) VALUES(:domain, :resp, NOW(), :score_mobile, 1) 
                  ON DUPLICATE KEY UPDATE domain = :domain, `raw` = :resp, createdate = NOW(), `score_mobile` = :score_mobile,  `status` = 1', [":domain" => $domain, ":resp" => $resp, ":score_mobile" => $score_mobile] );
              } else if($mode == "desktop") {                  
              	  rex_sql::factory()->setDebug(0)->setQuery('INSERT INTO ' . rex::getTable('project_manager_domain_psi') . ' (`domain`, `raw`, `createdate`, `score_desktop`, `status`) VALUES(:domain, :resp, NOW(), :score_desktop, 1) 
                  ON DUPLICATE KEY UPDATE domain = :domain, `raw` = :resp, createdate = NOW(), `score_desktop` = :score_desktop, `status` = 1', [":domain" => $domain, ":resp" => $resp, ":score_desktop" => $score_desktop] );              
              } 
          } else {
            
            // ERROR HANDLE
            $rateLimited = self::isRateLimitError($pagespeed);
            
            // Bei Ratenlimit die vorhandenen Daten nicht durch die Fehlerantwort ersetzen, der Abruf folgt im nächsten Lauf
            if (!$rateLimited) {
              if($mode == "desktop") {
              
                rex_sql::factory()->setDebug(0)->setQuery('INSERT INTO ' . rex::getTable('project_manager_domain_psi') . ' (`domain`, `raw`, `createdate`,  `status`) VALUES(:domain, :resp, NOW(), -1)
                    ON DUPLICATE KEY UPDATE domain = :domain, `raw` = :resp, createdate = NOW(), `status` = -1', [":domain" => $domain, ":resp" => $resp] );
              
              } else  if($mode == "mobile") {
                rex_sql::factory()->setDebug(0)->setQuery('INSERT INTO ' . rex::getTable('project_manager_domain_psi') . ' (`domain`, `raw`, `createdate`, `status`) VALUES(:domain, :resp, NOW(), -1)
                    ON DUPLICATE KEY UPDATE domain = :domain, `raw` = :resp, createdate = NOW(),  `status` = -1', [":domain" => $domain, ":resp" => $resp] );
              
              }
            
            } else {
              $this->rateLimited = true;
              $this->learnRateLimit($pagespeed);
            }
            
            $this->setMessage(self::errorMessage($pagespeed));
            $error = true;

          }
        }
        curl_multi_close($multi_curl);
        
        if ($error === true) {
          return false;
        } else {
          return true;
        }
    }
    public function getTypeName()
    {
        return rex_i18n::msg('project_manager_cronjob_pagespeed_name');
    }

    public function getParamFields()
    {
        return [];
    }
}
?>