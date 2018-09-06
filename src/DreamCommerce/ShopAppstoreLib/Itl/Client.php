<?php

namespace DreamCommerce\ShopAppstoreLib\Itl;

use DreamCommerce\ShopAppstoreLib\Resource;
use DreamCommerce\ShopAppstoreLib\Resource\ProductStock;

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

    public $locale = 'en';

    protected $optionNamesCache;

    protected $optionValuesNamesCache;

    protected $productsCache;

    protected $stocksNamesCache;

    public function __construct(
        ClientInterface $clientAdapter,
        string $queriesLogRootDir = null,
        \Psr\Log\LoggerInterface $psr3Logger = null
    ) {
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
            if ($psr3Logger) {
                $httpClient->setLogger($psr3Logger);
            }
        }

        $this->adapter = $clientAdapter;
        $clientAdapter -> setClient($this);
    }


    public function __call($name, $arguments)
    {
        return call_user_func_array([$this->adapter, $name], $arguments);
    }

    public function __get($name)
    {
        return $this->resource($name);
    }

    public function resource($name)
    {
        $resourceClassName = 'DreamCommerce\ShopAppstoreLib\Resource\\' . $name;
        return new $resourceClassName($this->adapter);
    }

    public function authenticate($authCode)
    {
        return $this->adapter->setAuthCode($authCode)->authenticate();
    }

    public function refreshTokens($refreshToken)
    {
        return $this->adapter->setRefreshToken($refreshToken)->refreshTokens();
    }

    public static function getWholeList(Resource $resource, $list = [], $page = 1)
    {
        $response = $resource->limit(50)->page($page)->get();
        if ($response->count) {
            foreach ($response as $row) {
                $list[] = $row;
            }
        }
        if ($response->page < $response->pages) {
            return self::getWholeList($list, ++$page);
        } else {
            return $list;
        }
    }

    public function getProductsForStocks($stocks)
    {
        if (!$stocks) {
            return [];
        }


        foreach ($stocks as $stock) {
            if (!@$this->productsCache[$stock->product_id]) {
                $loadProductsFor[] = $stock->product_id;
            }
        }

        if (@$loadProductsFor) {

            $shoperProductsArrTmp = static::getWholeList($this->Product->filters([
                'product_id' => ['IN' => [array_unique($loadProductsFor)]]
            ]));

            if ($shoperProductsArrTmp) {
                foreach ($shoperProductsArrTmp as $shoperProduct) {
                    $this->productsCache[$shoperProduct->product_id] = $shoperProduct;
                }
            }

        }

        $shoperProductsArr = [];
        foreach ($stocks as $stock) {
            $shoperProductsArr[$stock->product_id] = $this->productsCache[$stock->product_id];
        }

        return $shoperProductsArr ?: [];
    }

    public function getProductForStock($stock) {
        return $this->getProductsForStocks([$stock])[0];
    }

    public function loadNamesForStocks($stocks, $lang = null)
    {
        $lang = $lang ?: $this->locale;

        $loadProductFor = [];
        foreach ($stocks as $stock) {
            if (!@$this->stocksNamesCache[$lang][$stock->stock_id]) {
                $loadProductFor[] = $stock;
            }
        }

        $products = $this->getProductsForStocks($loadProductFor);
        $this->loadOptionNamesAndOptionValueNames($loadProductFor, $lang);

        foreach ($loadProductFor as $stock) {
            $this->stocksNamesCache[$lang][$stock->stock_id] = @$products[$stock->product_id]->translations->$lang ? $products[$stock->product_id]->translations->$lang->name : __('[No translation]');
            if ($stock->extended) {
                $optionsNamesArr = [];
                foreach ($stock->options as $optionId => $optionValueId) {
                    $optionsNamesArr[] = sprintf('%s: %s', $this->getOptionName($optionId, $lang),
                        $this->getOptionValueName($optionValueId, $lang));
                }
                $this->stocksNamesCache[$lang][$stock->stock_id] = sprintf('%s (%s)',
                    $this->stocksNamesCache[$lang][$stock->stock_id], join(', ', $optionsNamesArr));
            }
        }
    }

    public function getNameForStock($stockOrStockId, $lang = null)
    {
        $lang = $lang ?: $this->locale;

        $stockId = $stockOrStockId instanceof \ArrayObject ? $stockOrStockId -> stock_id : $stockOrStockId;

        if (!@$this->stocksNamesCache[$lang][$stockId]) {
            throw new \Exception('Method self::loadNamesForStocks() should be called before!');
        }
        return $this->stocksNamesCache[$lang][$stockId];
    }


    public function loadOptionNamesAndOptionValueNames($stocks, $lang = null)
    {
        $optionIdsArr = $ovalueIdsArr = [];

        $lang = $lang ?: $this->locale;

        foreach ($stocks as $stock) {
            if ($stock->extended) {
                foreach ($stock->options as $optionId => $optionValueId) {
                    if (!@$this->optionNamesCache[$lang][$optionId]) {
                        $optionIdsArr[] = $optionId;
                    }
                    if (!@$this->optionValuesNamesCache[$lang][$optionValueId]) {
                        $ovalueIdsArr[] = $optionValueId;
                    }
                }
            }
        }

        if ($optionIdsArr) {
            $optionIdsArr = array_unique($optionIdsArr);
            $optionsObjArr = self::getWholeList($this->Option->filters([
                'option_id' => ['IN' => [$optionIdsArr]]
            ]));

            foreach ($optionsObjArr as $option) {
                $this->optionNamesCache[$lang][$option->option_id] = @$option->translations->$lang ? $option->translations->$lang->name : __('[no transaltion]');
            }
        }

        if ($ovalueIdsArr) {
            $ovalueIdsArr = array_unique($ovalueIdsArr);
            $optionValuesObjArr = self::getWholeList($this->OptionValue->filters([
                'ovalue_id' => ['IN' => [$ovalueIdsArr]]
            ]));

            foreach ($optionValuesObjArr as $optionValue) {
                $this->optionValuesNamesCache[$lang][$optionValue->ovalue_id] = @$optionValue->translations->$lang ? $optionValue->translations->$lang->value : __('[No transaltion]');
            }
        }
    }

    public function getOptionName($optionId, $lang)
    {
        if (!@$this->optionNamesCache[$lang][$optionId]) {
            throw new \Exception('Method self::loadOptionNamesAndOptionValueNames() should be called before!');
        }
        return $this->optionNamesCache[$lang][$optionId];
    }

    public function getOptionValueName($ovalueId, $lang)
    {
        if (!@$this->optionValuesNamesCache[$lang][$ovalueId]) {
            throw new \Exception('Method self::loadOptionNamesAndOptionValueNames() should be called before!');
        }
        return $this->optionValuesNamesCache[$lang][$ovalueId];
    }

}
