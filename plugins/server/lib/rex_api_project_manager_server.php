<?php

// Aufruf: 
// /?rex-api-call=project_manager&api_key=###

class rex_api_project_manager_server extends rex_api_function
{
    protected $published = true;

    public function execute()
    {
        ob_end_clean();
        $func = rex_request('func','string');
        $protocol = rex_request('protocol','string');
        $domain = rex_request('domain','string');
        $param = rex_request('param','string');        // &param param1=value1,param2=value2
        $param = explode(',', $param);
        $param = '&'.implode('&', $param);
        $api_key = rex_request('api_key','string');
        $timestamp = time();
        $options = array(
          CURLOPT_FOLLOWLOCATION => true,
          CURLOPT_AUTOREFERER    => true,
          CURLOPT_MAXREDIRS      => 5,
          CURLOPT_HEADER         => false,
          CURLOPT_SSL_VERIFYPEER => false,
          CURLOPT_SSL_VERIFYHOST => false,
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:86.0) Gecko/20100101 Firefox/86.0',
          CURLOPT_TIMEOUT => 5
        );
        
        if($func == "delLog") {

          // ?rex-api-call=project_manager&api_key=634de6b36b4b4fde90e09c0a9588a7df&func=delLog&t=1549014945&_=1549014940721
          
          $resp = project_manager_client_api::fetch(project_manager_client_api::getUrls($protocol, $domain, 'rex-api-call=project_manager&api_key='.$api_key.'&func='.$func.$param), $options);
         
          $json = json_decode($resp, true);
            
          if(json_last_error() === JSON_ERROR_NONE && $json !== null) {
            if ($json['delLog'] == 1) {
              $params['delLog'] = 1;
            }
          } else {
            $params['delLog'] = -1;
          }
          
          // reload data          
          $response = project_manager_client_api::fetch(project_manager_client_api::getUrls($protocol, $domain, 'rex-api-call=project_manager&api_key='.$api_key.'&t='.$timestamp.$param), $options, $attempts);
          $resp = $response;
          $json = json_decode($resp, true);
          
          $project_manager_domain = rex_sql::factory()->setDebug(0)->getArray('SELECT * FROM ' . rex::getTable('project_manager_domain') . ' WHERE domain = ? LIMIT 1', [$domain]);
          
          if(json_last_error() === JSON_ERROR_NONE && $json !== null) {
            
            if ($json['status'] == 1) {
              
              rex_sql::factory()->setDebug(0)->setQuery('INSERT INTO ' . rex::getTable('project_manager_logs') . ' (`domain_id`, `createdate`, `raw`) VALUES(?,NOW(),?)', [$project_manager_domain[0]['id'],  $resp] );
              // SET STATUS
              rex_sql::factory()->setDebug(0)->setQuery("UPDATE " . rex::getTable('project_manager_domain') . " SET status = ?, updatedate = NOW() WHERE id = ?", [1, $project_manager_domain[0]['id']]);
              
            } else {
              // SET STATUS
              rex_sql::factory()->setDebug(0)->setQuery("UPDATE " . rex::getTable('project_manager_domain') . " SET status = ?, updatedate = NOW()WHERE id = ?", [0, $project_manager_domain[0]['id']]);
              project_manager_logger::failure($domain, 'API meldet status=' . (int) $json['status'] . ' (z. B. falscher API-Schlüssel)');
            }
            
          } else {
            // SET STATUS
            rex_sql::factory()->setDebug(0)->setQuery("UPDATE " . rex::getTable('project_manager_domain') . " SET status = ?, updatedate = NOW() WHERE id = ?", [-1, $project_manager_domain[0]['id']]);
            project_manager_logger::failure($domain, project_manager_client_api::formatAttempts(isset($attempts) ? $attempts : array()));
          }          
          rex_sql::factory()->setDebug(0)->setQuery("UPDATE " . rex::getTable('project_manager_domain') . " SET updatedate = NOW() WHERE id = ?", [$project_manager_domain[0]['id']]);            
          
        }
        
        if($func == "updateData") {
          
          // reload data
          $response = project_manager_client_api::fetch(project_manager_client_api::getUrls($protocol, $domain, 'rex-api-call=project_manager&api_key='.$api_key.'&t='.$timestamp.$param), $options, $attempts);
          $resp = $response;
          $json = json_decode($resp, true);
          $json_result = json_encode($json);
          
          project_manager_logger::deleteFile($domain);
          project_manager_logger::init($domain);
          project_manager_logger::log($domain . ' Abruf gestartet', 'Project Manager Server');
          project_manager_logger::log($json_result . ' -> Response', 'Project Manager Server');
                    
          $project_manager_domain = rex_sql::factory()->setDebug(0)->getArray('SELECT * FROM ' . rex::getTable('project_manager_domain') . ' WHERE domain = ? LIMIT 1', [$domain]);
          
          if(json_last_error() === JSON_ERROR_NONE && $json !== null) {
            
            if ($json['status'] == 1) {
              
              rex_sql::factory()->setDebug(0)->setQuery('INSERT INTO ' . rex::getTable('project_manager_logs') . ' (`domain_id`, `createdate`, `raw`) VALUES(?,NOW(),?)', [$project_manager_domain[0]['id'],  $resp] );
              // SET STATUS
              rex_sql::factory()->setDebug(0)->setQuery("UPDATE " . rex::getTable('project_manager_domain') . " SET status = ?, updatedate = NOW() WHERE id = ?", [1, $project_manager_domain[0]['id']]);
              
              //WRITE LOGFILE
              project_manager_logger::log('Status 1', 'Project Manager Server');
              
            } else {
              // SET STATUS
              rex_sql::factory()->setDebug(0)->setQuery("UPDATE " . rex::getTable('project_manager_domain') . " SET status = ?, updatedate = NOW()WHERE id = ?", [0, $project_manager_domain[0]['id']]);
              project_manager_logger::failure($domain, 'API meldet status=' . (int) $json['status'] . ' (z. B. falscher API-Schlüssel)');
              
              // WRITE LOGFILE
              project_manager_logger::log('Status 0', 'Project Manager Server');
            }
            
          } else {
            // SET STATUS
            rex_sql::factory()->setDebug(0)->setQuery("UPDATE " . rex::getTable('project_manager_domain') . " SET status = ?, updatedate = NOW() WHERE id = ?", [-1, $project_manager_domain[0]['id']]);
            project_manager_logger::failure($domain, project_manager_client_api::formatAttempts(isset($attempts) ? $attempts : array()));
            
            //WRITE LOGFILE
            project_manager_logger::log('Status -1', 'Project Manager Server');
          }
          rex_sql::factory()->setDebug(0)->setQuery("UPDATE " . rex::getTable('project_manager_domain') . " SET updatedate = NOW() WHERE id = ?", [$project_manager_domain[0]['id']]);       
          
        }
        
        header('Content-Type: application/json; charset=UTF-8');
        $response = json_encode($params ?? null, true);
        echo $response;
        exit();
        
    }
}

?>