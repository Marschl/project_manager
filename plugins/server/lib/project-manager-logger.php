<?php

class project_manager_logger extends rex_log_file
{
  public static $init = false;
  public static $logfile;
  public const DATE_FORMAT = 'Y-m-d H:i:s';
  
  public static function init($domain)
  {
    self::$logfile = new rex_log_file(rex_path::log('project_manager_'.$domain.'_last.log'), 100000);      
  }
  
  public static function log($logstr, $logname = 'Projekt Manager')
  {    
    $data = [
      date(self::DATE_FORMAT, time()),
      $logname,
      $logstr
    ];
    
    self::$logfile->add($data);
  }
  
  /**
   * Fehlschlag eines Abrufs mit Ursache. Anders als das Projekt-Log (wird bei jedem
   * Abruf neu angelegt) bleibt dieses Protokoll über mehrere Läufe erhalten.
   */
  public static function failure($domain, $reason)
  {
    $log = new rex_log_file(rex_path::log('project_manager_failures.log'), 200000);
    $log->add([date(self::DATE_FORMAT, time()), $domain, $reason]);
  }

  public static function getPath($domain)
  {
    return rex_path::log('project_manager_'.$domain.'_last.log');
  }
  
  public static function deleteFile($domain)
  {
    rex_file::delete(rex_path::log('project_manager_'.$domain.'_last.log'));;
  }
  
  public static function close()
  {
    self::$logfile = null;
    self::$init = false;
  }
}