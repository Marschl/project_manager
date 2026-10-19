<?php

/**
 * Abruf der Client-API (rex-api-call=project_manager) auf den Projekten.
 *
 * Nicht jedes Projekt ist über dieselbe URL erreichbar: Meist geht der Aufruf
 * über "/index.php?rex-api-call=…". Bei manchen wird "/index.php" aber per
 * Redirect auf "/" geleitet, wobei die Query verloren geht, dort funktioniert
 * nur "/?rex-api-call=…". Darum wird zuerst die bisherige URL versucht und bei
 * ungültiger Antwort die andere Variante, bis eine gültiges JSON liefert.
 */
class project_manager_client_api
{
    /**
     * URLs in der Reihenfolge, in der sie versucht werden.
     *
     * @param string $protocol "http://" oder "https://"
     * @param string $domain
     * @param string $query    alles nach dem "?"
     * @param int    $cms      4 oder 5 (REDAXO-Version des Projekts)
     *
     * @return string[]
     */
    public static function getUrls($protocol, $domain, $query, $cms = 5)
    {
        $base = $protocol . urlencode($domain);

        if ($cms == 4) {
            return [$base . '/project_manager_client.php?' . $query];
        }

        return [$base . '/index.php?' . $query, $base . '/?' . $query];
    }

    /**
     * Gültig ist eine Antwort, die sich als JSON (nicht null) lesen lässt.
     *
     * @param mixed $response
     *
     * @return bool
     */
    public static function isValidResponse($response)
    {
        if (!is_string($response) || '' === $response) {
            return false;
        }

        $json = json_decode($response, true);

        return JSON_ERROR_NONE === json_last_error() && null !== $json;
    }

    /**
     * Einzelner Abruf, bei ungültiger Antwort mit der nächsten URL.
     *
     * @param string[] $urls
     * @param array    $options curl-Optionen (ohne CURLOPT_URL)
     *
     * @return string|false Antwort der letzten versuchten URL
     */
    public static function fetch(array $urls, array $options)
    {
        $response = false;

        foreach ($urls as $url) {
            $curl = curl_init();
            curl_setopt_array($curl, [CURLOPT_URL => $url] + $options);
            $response = curl_exec($curl);

            if (self::isValidResponse($response)) {
                break;
            }
        }

        return $response;
    }

    /**
     * Mehrere Abrufe parallel. Projekte mit ungültiger Antwort werden in einer
     * weiteren Runde mit ihrer nächsten URL erneut abgefragt.
     *
     * @param array[] $urlsByKey z. B. [domain => [url1, url2]]
     * @param array   $options   curl-Optionen (ohne CURLOPT_URL)
     *
     * @return array<string, string|null> Antwort je Schlüssel (letzter Versuch)
     */
    public static function fetchMulti(array $urlsByKey, array $options)
    {
        $responses = [];
        $rounds = $urlsByKey ? max(array_map('count', $urlsByKey)) : 0;

        for ($round = 0; $round < $rounds; ++$round) {
            $multi = curl_multi_init();
            $handles = [];

            foreach ($urlsByKey as $key => $urls) {
                if (isset($responses[$key]) && self::isValidResponse($responses[$key])) {
                    continue;
                }
                if (!isset($urls[$round])) {
                    continue;
                }

                $handles[$key] = curl_init($urls[$round]);
                curl_setopt_array($handles[$key], $options);
                curl_multi_add_handle($multi, $handles[$key]);
            }

            if (!$handles) {
                curl_multi_close($multi);
                break;
            }

            $active = null;

            do {
                $mrc = curl_multi_exec($multi, $active);
            } while (CURLM_CALL_MULTI_PERFORM == $mrc);

            while ($active && CURLM_OK == $mrc) {
                if (-1 != curl_multi_select($multi)) {
                    do {
                        $mrc = curl_multi_exec($multi, $active);
                    } while (CURLM_CALL_MULTI_PERFORM == $mrc);
                }
            }

            foreach ($handles as $key => $handle) {
                $responses[$key] = curl_multi_getcontent($handle);
                curl_multi_remove_handle($multi, $handle);
            }

            curl_multi_close($multi);
        }

        return $responses;
    }
}
