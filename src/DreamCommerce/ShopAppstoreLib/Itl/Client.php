<?php

namespace DreamCommerce\ShopAppstoreLib\Itl;

use DreamCommerce\ShopAppstoreLib\Client\Exception as ClientException;
use DreamCommerce\ShopAppstoreLib\ClientInterface;

/**
 * Class Client
 * @package DreamCommerce\ShopAppstoreLib\Itl
 *
 * Replaces original DreamCommerce\ShopAppstoreLib\Client class.
 * Injects a few default dependencies into \DreamCommerce\ShopAppstoreLib\Itl\Http (if used).
 * Love it or hate it (you muggle!) - restores a bit of magic known from previous versions of org library.
 *
 * Usage:
 * $client = new \DreamCommerce\ShopAppstoreLib\Itl\Client  (
 * new DreamCommerce\ShopAppstoreLib\Itl\Client\OAuth(
 * [
 * 'entrypoint' => 'blabla',
 * 'access_token' => 'blabla',
 * 'client_id' => 'blabla',
 * 'client_secret' => 'blabla'
 * ], 'queriesLog')
 * );
 * var_dump($client->resource('Product')->get(1));
 * //or
 * var_dump(DreamCommerce\ShopAppstoreLib\Resource\Product($client->adapter)->get(1));
 *
 */
class Client
{
    /**
     * @var ClientInterface
     */
    public $adapter;

    public function __construct(ClientInterface $clientAdapter, string $queriesLogRootDir = null)
    {
        if (!$clientAdapter->getHttpClient()) {
            $clientAdapter->setHttpClient(new \DreamCommerce\ShopAppstoreLib\Itl\Http);
        }
        $httpClient = $clientAdapter->getHttpClient();
        if ($httpClient instanceof \DreamCommerce\ShopAppstoreLib\Itl\Http) {
            if ($queriesLogRootDir) {
                Querieslog::$logRootDir = $queriesLogRootDir;
            }
            $httpClient->setHTTPTransport(new \Itl\Utils\HTTP\Curl());
            $httpClient->setQueryLogger(Querieslog::factory());
        }

        $this->adapter = $clientAdapter;
    }


    public function __call($name, $arguments)
    {
        return call_user_func_array([$this->adapter, $name], $arguments);
    }

    public function __get($name) {
        return $this->resource($name);
    }

    public function resource($name)
    {
        $resourceClassName = 'DreamCommerce\ShopAppstoreLib\Resource\\' . $name;
        return new $resourceClassName($this->adapter);
    }


    public function getWholeList(DreamCommerce\Resource $resource, $list = [], $page = 1)
    {
        $response = $resource->limit(50)->page($page)->get();
        if ($response->count) {
            foreach ($response as $row) {
                $list[] = $row;
            }
        }
        if ($response->page < $response->pages) {
            return $this->getWholeList($resource, $list, ++$page);
        } else {
            return $list;
        }
    }

    public function authenticate($authCode)
    {
        return $this->adapter->setAuthCode($authCode)->authenticate();
    }

}
