<?php

namespace DreamCommerce\ShopAppstoreLib\Itl;


use DreamCommerce\ShopAppstoreLib\HttpInterface;
use DreamCommerce\ShopAppstoreLib\Exception\HttpException;
use Itl\Utils\HTTP\HTTPTransport;
use Itl\Utils\HTTP\HTTPTransportAwareInterface;
use Itl\Utils\HTTP\Exception\CurlException;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerAwareInterface;

/**
 * Class Http
 * @package DreamCommerce\ShopAppstoreLib\Itl
 *
 * Adds an additional abstraction layer (objects implementing Itl\Utils\HTTP\HTTPTransport) for a http transport.
 * It's possible to use CURL now! Or even sockets! Or even your old socks!
 *
 */
class Http implements HttpInterface, HTTPTransportAwareInterface
{
    const TIMEOUT = 15;

    public static $retryLimit = 5;

    public $transport;

    protected static $sleepOnNextQuery;

    protected $logger;

    protected $queryLogger;

    protected $exceptionHandlersArr = [];

    public $url;

    public function setHTTPTransport(HTTPTransport $ITLHTTPTransport)
    {
        $this->transport = $ITLHTTPTransport;
        if ($this->logger && $this->transport instanceof LoggerAwareInterface) {
            $this->transport->setLogger($this->logger);
        }
    }

    public function setQueryLogger(\DreamCommerce\ShopAppstoreLib\Itl\Querieslog $queryLogger)
    {
        $this->queryLogger = $queryLogger;
    }

    /**
     * @param LoggerInterface $logger
     * @return $this
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
        if ($this->transport && $this->transport instanceof LoggerAwareInterface) {
            $this->transport->setLogger($this->logger);
        }
    }

    /**
     * @return LoggerInterface
     */
    public function getLogger()
    {
        return $this->logger;
    }


    /**
     * Do handlera zostaną przekazane 2 argumenty:
     * 1: wyjątek
     * 2: bieżący obiekt ($this)
     * Jeżeli handler zwróci boolowskie true, zostanie ponowiona próba połączenia (o ile nie wyczerpał się jeszcze limit).
     * Handler może zmianić np. $this->url dla kolejne próby.
     *
     * @param callable $handler
     */
    public function setExceptionHandler(callable $handler)
    {
        $this->exceptionHandlersArr[] = $handler;
    }


    /***
     * set connection retrying limit
     * @param int $num
     * @throws HttpException
     */
    public static function setRetryLimit($num)
    {
        if ($num < 1) {
            throw new HttpException('Limit ' . (int)$num . ' is too low', HttpException::LIMIT_TOO_LOW);
        }

        self::$retryLimit = $num;
    }


    protected function processUrl($url, $query = [])
    {
        if (!$query) {
            return $url;
        }
        $processedUrl = $url;
        // URL has already query string, merge
        if (strpos($url, '?') !== false) {
            $components = parse_url($url);
            $params = [];
            parse_str($components['query'], $params);
            $params = $params + $query;
            $components['query'] = http_build_query($params);
            $processedUrl = http_build_url($components);
        } else {
            $processedUrl .= '?' . http_build_query($query);
        }
        return $processedUrl;
    }


    protected function perform($method, $url, $body = [], $query = [], $headers = [])
    {

        //Body tylko przy POST i PUT nie jest null
        if (!is_null($body)) {
            $body = (array)$body;
            //Body - przerabianie na jsona, jeśli trzeba
            if (
                'application/json' === $headers['Content-Type']
            ) {
                $body = json_encode($body);
            }
        }


        //Timeout
        $this->transport->setTimeout(self::TIMEOUT);

        //Url
        $this->url = $url;

        //NAGŁÓWKI
        if (!$headers) {
            $headers = [];
        }

        if (!isset($headers['Content-Type'])) {
            $headers['Content-Type'] = 'application/json';
        }

        if (!isset($headers['Accept-Encoding'])) {
            $headers['Accept-Encoding'] = 'gzip';
        }
        //NAGŁÓWKI KONIEC

        //Kolejne próby wykonania zapytania
        for ($i = 1; $i <= self::$retryLimit; $i++) {
            if (@static::$sleepOnNextQuery[$url]) {
                sleep($i * 2 + 1);
                unset(static::$sleepOnNextQuery[$url]);
            }

            $url = $this->processUrl($this->url, $query);
            $this->transport->setUrl($url);

            if ($this->queryLogger) {
                $this->queryLogger
                    ->method($method)
                    ->url($url)
                    ->tryCounter($i);
            }

            $retry = !$this->query($method, $url, $body, $headers);

            if (!$retry) {
                break;
            } elseif ($this->queryLogger) {
                $this->queryLogger
                    ->write();
            }
        }
        //Kolejne próby wykonania zapytania KONIEC

        //Sorry, wodzu!
        if ($retry) {
            throw new HttpException('Retries count exceeded (self::$retryLimit: ' . self::$retryLimit . ')',
                HttpException::QUOTA_EXCEEDED, null, $this->getResponseHeaders());
        }


        $parsedPayload = null;
        $responseHeaders = $this->getResponseHeaders();
        $responseBody = $this->transport->getResponseBody();

        // try to decode response
        if ('application/json' === $responseHeaders['Content-Type']) {
            $parsedPayload = @json_decode($responseBody, true);
            if (!$parsedPayload && !is_array($parsedPayload)) {
                throw new HttpException('Result is not a valid JSON', HttpException::MALFORMED_RESULT, null,
                    $responseHeaders, $responseBody);
            }
        } else {
            $parsedPayload = $responseBody;
        }

        return [
            'data' => $parsedPayload,
            'headers' => $responseHeaders
        ];
    }

    protected function query($method, $url, $body, $headers)
    {
        try {
            $responseHeaders = [];

            $this->transport->sendRequest($method, $body, $headers);

            $responseHeaders = $this->getResponseHeaders();

            $responseCode = $this->transport->getResponseCode();

            $responseBody = $this->transport->getResponseBody();

            if ($this->queryLogger) {
                $this->queryLogger
                    ->responseCode($responseCode)
                    ->responseStatus($this->transport->getResponseStatus());
            }

            if (429 === $responseCode && isset($responseHeaders['Retry-After'])) {
                sleep($responseHeaders['Retry-After']);
                return false;
            }

            //Puste ciało dokumentu
            if (!$responseBody) {
                throw new HttpException('Response body seems to be empty...');
            }

            /**
             * Niespodziewana struktura odpowiedzi - niby ok ale nie ma JSON-a
             */
            if (
                'application/json' !== $responseHeaders['Content-Type']
                && 200 === $responseCode
            ) {
                throw new HttpException('Response status equals 200 but Content-Type is different than "application/json". It seems sth is wrong.',
                    HttpException::MALFORMED_RESULT, null, $responseHeaders, $responseBody);
            }

            //Obsługa błędów HTTP
            if ($responseCode < 200 || $responseCode >= 400) {
                $result = $responseBody;
                // decode if it's JSON
                if ('application/json' === $responseHeaders['Content-Type']) {
                    $result = @json_decode($responseBody, true);
                }

                if (is_array($result)) {
                    $description = $result['error'];

                    if (isset($result['error_description'])) {
                        $description = $result['error_description'];
                    }
                    throw new HttpException($description, $responseCode, null, $responseHeaders,
                        $result);
                } else {
                    throw new \Exception(mb_strlen($result) > 256 ? mb_substr($result, 0, 256) . ' ...' : $result);
                }
            }
            //Obsługa błędów HTTP KONIEC
        } catch (Exception $e) {
            if ($this->queryLogger) {
                $this->queryLogger
                    ->responseStatus(
                        sprintf(
                            ' [HTTP request failed: %s (%d)]',
                            $e->getMessage(),
                            $e->getCode()
                        )
                    );
            }

            //Obsługa exception handlerów obsługujących np. specyficzne błędy CURL-a
            if ($this->exceptionHandlersArr) {
                try {
                    foreach ($this->exceptionHandlersArr as $callable) {
                        if (true === $callable($e, $this)) {
                            return false;
                        }
                    }
                } catch (Exception $e) {
                    if ($this->queryLogger) {
                        $this->queryLogger
                            ->write();
                    }
                    throw $e;
                }
            }
            //Obsługa exception handlerów obsługujących np. specyficzne błędy CURL-a KONIEC

            throw new HttpException(
                sprintf(
                    'HTTP request failed: %s (%d)',
                    $e->getMessage(),
                    $e->getCode()
                ),
                HttpException::REQUEST_FAILED,
                $e,
                $responseHeaders
            );
        } finally {
            if ($this->queryLogger) {
                $this->queryLogger
                    ->write();
            }
        }

        if (
            isset($responseHeaders['X-Shop-Api-Calls'])
            && isset($responseHeaders['X-Shop-Api-Limit'])
            && (0 == $responseHeaders['X-Shop-Api-Limit'] - $responseHeaders['X-Shop-Api-Calls'])
        ) {
            static::$sleepOnNextQuery[$url] = true;
        }

        return true;
    }

    protected function getResponseHeaders()
    {
        $headers = $this->transport->getResponseHeaders();
        //nie ma takiego headera ale pozostałe elementy biblioteki DC z niego korzystają...
        if (!isset($headers['Code'])) {
            $headers['Code'] = $this->transport->getResponseCode();
        }
        return $headers;
    }

    public function setSkipSsl($status)
    {
        //DON'T SKIP SSL! NEVER!
    }


    public function get($url, $query = [], $headers = [])
    {
        return $this->perform('GET', $url, null, $query, $headers);
    }

    /**
     * Performs a HEAD request
     *
     * @param string $url
     * @param array $query query string params
     * @param array $headers
     * @throws Exception\HttpException
     * @return string
     */
    public function head($url, $query = [], $headers = [])
    {
        //API Shopera chyba nie używa HEAD-a - na razie nie zadziała
        return $this->perform('HEAD', $url, null, $query, $headers);
    }

    /**
     * Performs a POST request
     *
     * @param string $url
     * @param array|\stdClass $body form fields
     * @param array $query query string params
     * @param array $headers
     * @throws Exception\HttpException
     * @return string
     */
    public function post($url, $body = [], $query = [], $headers = [])
    {
        return $this->perform('POST', $url, $body, $query, $headers);
    }

    /**
     * Performs a PUT request
     *
     * @param string $url
     * @param array|\stdClass $body form fields
     * @param array $query query string params
     * @param array $headers
     * @throws Exception\HttpException
     * @return string
     */
    public function put($url, $body = [], $query = [], $headers = [])
    {
        return $this->perform('PUT', $url, $body, $query, $headers);
    }

    /**
     * Performs a DELETE request
     *
     * @param $url
     * @param array $query query string params
     * @param array $headers
     * @throws Exception\HttpException
     * @return string
     */
    public function delete($url, $query = [], $headers = [])
    {
        return $this->perform('DELETE', $url, null, $query, $headers);
    }
}
