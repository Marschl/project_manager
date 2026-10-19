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
     * @param array    $options  curl-Optionen (ohne CURLOPT_URL)
     * @param array    $attempts optional: Beschreibung jedes Versuchs (siehe describe())
     *
     * @return string|false Antwort der letzten versuchten URL
     */
    public static function fetch(array $urls, array $options, &$attempts = null)
    {
        $response = false;
        $attempts = [];

        foreach ($urls as $url) {
            $curl = curl_init();
            curl_setopt_array($curl, [CURLOPT_URL => $url] + $options);
            $response = curl_exec($curl);
            $attempts[] = self::describe($curl, $url, $response);

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
     * @param array   $attempts  optional: Beschreibung der Versuche je Schlüssel (siehe describe())
     *
     * @return array<string, string|null> Antwort je Schlüssel (letzter Versuch)
     */
    public static function fetchMulti(array $urlsByKey, array $options, &$attempts = null)
    {
        $responses = [];
        $attempts = [];
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

            // erst danach sind curl_errno()/curl_error() der einzelnen Handles gesetzt
            while (curl_multi_info_read($multi)) {
            }

            foreach ($handles as $key => $handle) {
                $responses[$key] = curl_multi_getcontent($handle);
                $attempts[$key][] = self::describe($handle, $urlsByKey[$key][$round], $responses[$key]);
                curl_multi_remove_handle($multi, $handle);
            }

            curl_multi_close($multi);
        }

        return $responses;
    }

    /**
     * Beschreibt einen Versuch für das Fehlerprotokoll. Enthält bewusst nur den
     * Pfad der URL, nie die Query (API-Schlüssel) und nie den Antwortinhalt.
     *
     * @param resource|\CurlHandle $handle
     * @param string               $url
     * @param mixed                $response
     *
     * @return array{path: string, errno: int, error: string, http: int, time: float, redirects: int, final_path: string, bytes: int, valid: bool}
     */
    private static function describe($handle, $url, $response)
    {
        $path = parse_url($url, PHP_URL_PATH);
        $finalPath = parse_url((string) curl_getinfo($handle, CURLINFO_EFFECTIVE_URL), PHP_URL_PATH);

        return [
            'path' => $path ? $path : '/',
            'errno' => curl_errno($handle),
            'error' => curl_error($handle),
            'http' => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
            'time' => round((float) curl_getinfo($handle, CURLINFO_TOTAL_TIME), 1),
            'redirects' => (int) curl_getinfo($handle, CURLINFO_REDIRECT_COUNT),
            'final_path' => $finalPath ? $finalPath : '/',
            'bytes' => is_string($response) ? strlen($response) : 0,
            'valid' => self::isValidResponse($response),
        ];
    }

    /**
     * Ursache der fehlgeschlagenen Versuche als eine Zeile, z. B.
     * "/index.php: kein JSON (HTTP 200, 1 Redirect auf /, 0 Byte); /: cURL-Fehler 28 nach 5.0 s".
     *
     * @param array $attempts Versuche eines Projekts (Ergebnis von fetch()/fetchMulti())
     *
     * @return string
     */
    public static function formatAttempts(array $attempts)
    {
        $parts = [];

        foreach ($attempts as $attempt) {
            if ($attempt['errno']) {
                // falls eine cURL-Meldung die URL enthält: API-Schlüssel nicht ins Protokoll übernehmen
                $error = preg_replace('/api_key=[^&\s]*/', 'api_key=***', preg_replace('/\s+/', ' ', $attempt['error']));
                $reason = 'cURL-Fehler ' . $attempt['errno'] . ' (' . $error . ') nach ' . $attempt['time'] . ' s';
            } elseif ($attempt['http'] >= 400) {
                $reason = 'HTTP ' . $attempt['http'];
            } else {
                $reason = 'kein JSON (HTTP ' . $attempt['http'];
                if ($attempt['redirects'] > 0) {
                    $reason .= ', ' . $attempt['redirects'] . ' Redirect' . (1 === $attempt['redirects'] ? '' : 's') . ' auf ' . $attempt['final_path'];
                }
                $reason .= ', ' . $attempt['bytes'] . ' Byte)';
            }

            $parts[] = $attempt['path'] . ': ' . $reason;
        }

        return $parts ? implode('; ', $parts) : 'keine Antwort';
    }
}
