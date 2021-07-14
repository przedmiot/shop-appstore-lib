<?php

namespace DreamCommerce\ShopAppstoreLib\Itl;

use DreamCommerce\ShopAppstoreLib\Exception\Exception;
use DreamCommerce\ShopAppstoreLib\Itl\Exception\CurrencyNotFoundException;
use DreamCommerce\ShopAppstoreLib\Resource;
use DreamCommerce\ShopAppstoreLib\Resource\ProductStock;
use DreamCommerce\ShopAppstoreLib\ResourceList;
use Itl\Utils\Misc\BC;
use Psr\SimpleCache\CacheInterface;

/**
 * Class Client
 * @package DreamCommerce\ShopAppstoreLib\Itl
 *
 * Replaces original DreamCommerce\ShopAppstoreLib\Client class.
 * Injects a few default dependencies into \DreamCommerce\ShopAppstoreLib\Itl\Http (if used).
 * restores a bit of magic
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

    /**
     * @var CacheInterface
     */
    protected $cacheRepository;

    protected $localCache = [];

    const GET_WHOLE_LIST_PAGES_LIMIT = 1000;

    public function __construct(
        ClientInterface $clientAdapter,
        $queriesLogRootDir = null,
        \Psr\Log\LoggerInterface $psr3Logger = null
    )
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
            if ($psr3Logger) {
                $httpClient->setLogger($psr3Logger);
            }
        }

        $this->adapter = $clientAdapter;
        $clientAdapter->setClient($this);
    }


    public function __call($name, $arguments)
    {
        return call_user_func_array([$this->adapter, $name], $arguments);
    }

    /**
     * @param $name API`s Resource name
     * @return Resource
     */
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

    public static function getWholeList(Resource $resource, $grabRowCallback = null, $list = [], $page = 1)
    {
        if (self::GET_WHOLE_LIST_PAGES_LIMIT <= $page) {
            throw new Exception('It\'s escalating...!');
        }
        $response = $resource->limit(ResourceList::MAX_ROWS_PER_PAGE)->page($page)->get();
        if ($response->count) {
            foreach ($response as $i => $row) {
                if (is_callable($grabRowCallback)) {
                    list($index, $data) = call_user_func($grabRowCallback, $row,
                        ($page - 1) * ResourceList::MAX_ROWS_PER_PAGE + $i);
                    $list[$index] = $data;
                } else {
                    $list[] = $row;
                }
            }
        }
        if ($response->page < $response->pages) {
            return self::getWholeList($resource, $grabRowCallback, $list, ++$page);
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

    public function getProductForStock($stock)
    {
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

        $stockId = $stockOrStockId instanceof \ArrayObject ? $stockOrStockId->stock_id : $stockOrStockId;

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

    public function getOptionName($optionId, $lang = null)
    {
        $lang = $lang ?: $this->locale;
        
        if (!@$this->optionNamesCache[$lang][$optionId]) {
            throw new \Exception('Method self::loadOptionNamesAndOptionValueNames() should be called before!');
        }
        return $this->optionNamesCache[$lang][$optionId];
    }

    public function getOptionValueName($ovalueId, $lang = null)
    {
        $lang = $lang ?: $this->locale;

        if (!@$this->optionValuesNamesCache[$lang][$ovalueId]) {
            throw new \Exception('Method self::loadOptionNamesAndOptionValueNames() should be called before!');
        }
        return $this->optionValuesNamesCache[$lang][$ovalueId];
    }


    /**
     * Exchange office
     *
     * @param $price int|float|string quota we want to exchange
     * @param null int|float|string $exchangeToCurrencyAbbr  currency we want to exchange to, null if it's default shop currency
     * @param null int|float|string $priceCurrencyAbbr currency we want to exchange from, null if it's default shop currency
     * @return string
     * @throws Exception
     */
    public function getPriceInCurr($price, $exchangeToCurrencyAbbr = null, $priceCurrencyAbbr = null)
    {
        $this->loadCurrencies();

        $defaultCurrencyName = $this->getFromCache('defaultCurrencyName');

        if (!$priceCurrencyAbbr) {
            $priceCurrencyAbbr = $defaultCurrencyName;
        }

        if (!$exchangeToCurrencyAbbr) {
            $exchangeToCurrencyAbbr = $defaultCurrencyName;
        }

        if ($priceCurrencyAbbr === $exchangeToCurrencyAbbr) {
            return BC::bcround($price);
        }

        if ($priceCurrencyAbbr === $defaultCurrencyName) {
            return $this->exchangeFromDefaultShopCurr($price, $exchangeToCurrencyAbbr);
        } else {
            if ($exchangeToCurrencyAbbr === $defaultCurrencyName) {
                return $this->exchangeToDafaultShopCurr($price, $priceCurrencyAbbr);
            } else {
                return $this->exchangeFromDefaultShopCurr($this->exchangeToDafaultShopCurr($price, $priceCurrencyAbbr),
                    $exchangeToCurrencyAbbr);
            }
        }
    }

    public function exchangeToDafaultShopCurr($price, $currAbbr)
    {
        $this->getCurrencyIdByAbbr($currAbbr);
        return BC::bcround(bcmul($price, $this->getFromCache('currenciesRates')[$currAbbr], 3));
    }

    public function exchangeFromDefaultShopCurr($price, $currAbbr)
    {
        $this->getCurrencyIdByAbbr($currAbbr);

        $rate = $this->getFromCache('currenciesRates')[$currAbbr];

        if (0 == $rate) {
            throw new Exception('Exchange rate of :curr should be greater than 0', [
                'curr' => $currAbbr
            ]);
        }
        return BC::bcround(bcdiv($price, $rate, 3));
    }

    /**
     * @param $currAbbr
     * @return integer
     * @throws CurrencyNotFoundException
     */
    public function getCurrencyIdByAbbr($currAbbr)
    {
        $this->loadCurrencies();
        $currenciesIdsCache = $this->getFromCache('currenciesIds');

        if (!isset($currenciesIdsCache[$currAbbr])) {
            throw new CurrencyNotFoundException($currAbbr);
        }
        return $currenciesIdsCache[$currAbbr];
    }

    public function getLocalizedNamesOfOrdersStatuses($lang = null)
    {
        return $this->getLocalizedNamesOf('Status', ['active' => 1], $lang);
    }

    public function getLocalizedNamesOfShippings($lang = null)
    {
        return $this->getLocalizedNamesOf('Shipping', [], $lang, true);
    }

    public function getLocalizedNamesOfPayments($lang = null)
    {
        return $this->getLocalizedNamesOf('Payment', [], $lang, true);
    }

    /**
     * @param $what string A resource name
     * @param $filter array
     * @param $lang string|array (every language will be )
     * @param $translationsCanBeDisabled is there turn-on-or-off-separate-translation strategy used?
     *
     * @return array names of resources indexed by their ids
     */
    protected function getLocalizedNamesOf($what, array $filters = [], $lang = null, $translationsCanBeDisabled = false)
    {
        $lang = $lang ?: $this->locale;
        $langArr = (array)$lang;

        $namesArr = [];
        $resourcesArr = self::getWholeList($this->$what->filters($filters));
        foreach ($resourcesArr as $resource) {
            $id = $resource->{strtolower($what) . '_id'};

            $translation = $this->getTranslation(@$resource->translations, $langArr);
            if ($translation) {
                if ($translationsCanBeDisabled && !$translation->active) {
                    continue;
                }
                if (@$translation->name) {
                    $namesArr[$id] = $translation->name;
                } elseif (@$translation->title) {
                    $namesArr[$id] = $translation->title;
                }
            } else {
                $namesArr[$id] = __('No translation');
            }
        }
        return $namesArr;
    }

    protected function getTranslation($translationsArr, $lang = null)
    {
        $lang = $lang ?: $this->locale;
        $lang = (array)$lang;

        foreach ($lang as $desiredLang) {
            foreach ($translationsArr as $translationLang => $transaltedArr) {
                if ($desiredLang == $translationLang || substr($translationLang, 0, 2) == $desiredLang) {
                    return $transaltedArr;
                }
            }
        }
    }


    public function getDefaultShopLocale()
    {
        return $this->loadShopData()->default_language_name;
    }

    public function getShopTimezone()
    {
        return $this->loadShopData()->locale_timezone;
    }

    protected function loadCurrencies()
    {
        if (!$this->isCached('currenciesIds')) {
            $currencies = static::getWholeList($this->resource('Currency')->filters(['active' => 1]));
            $currenciesIdsMap = $currenciesRates = [];
            foreach ($currencies as $currency) {
                $currenciesIdsMap[$currency->name] = $currency->currency_id;
                if ($currency->default) {
                    $this->cache('defaultCurrencyName', $currency->name, 60);
                }
                $currenciesRates[$currency->name] = $currency->rate;

            }
            $this->cache('currenciesIds', $currenciesIdsMap, 60);
            $this->cache('currenciesRates', $currenciesRates, 60);
        }
    }

    protected function loadShopData()
    {
        return $this->getFromCacheOrCacheReturned('ApplicationConfig', function () {
            return $this->ApplicationConfig->get();
        });
    }

    /**
     * Adds cache repository (only Laravel cache supported - @param $repository
     * @throws \DreamCommerce\ShopAppstoreLib\Client\Exception\Exception
     * @todo write an adapter interface)
     *
     */
    public function setLaravelCacheRepository($repository)
    {
        if (!$repository instanceof \Illuminate\Cache\Repository) {
            throw new \DreamCommerce\ShopAppstoreLib\Client\Exception\Exception('Method works only with Laravel cache repository, so I\'m very very sorry!');
        }
        $this->cacheRepository = $repository;
    }

    protected function cache($key, $value, $ttl = 600)
    {
        $this->localCache[$key] = $value;
        if ($this->cacheRepository) {
            $this->cacheRepository->set($this->prepareExternalCacheKey($key), $value, $ttl);
        }
        return $value;
    }

    protected function getFromCache($key, $default = null)
    {
        if (isset($this->localCache[$key])) {
            return $this->localCache[$key];
        } elseif ($this->cacheRepository->has($this->prepareExternalCacheKey($key))) {
            return $this->cacheRepository->get($this->prepareExternalCacheKey($key));
        } else {
            return $default;
        }
    }

    protected function isCached($key)
    {
        if (isset($this->localCache[$key])) {
            return true;
        } elseif ($this->cacheRepository->has($this->prepareExternalCacheKey($key))) {
            return true;
        }
        return false;
    }

    protected function prepareExternalCacheKey($key)
    {
        return $this->adapter->getEntrypoint() . '__' . $key;
    }

    protected function getFromCacheOrCacheReturned(string $key, callable $callable, $ttl = 600)
    {
        if ($this->isCached($key)) {
            return $this->getFromCache($key);
        } else {
            return $this->cache($key, call_user_func($callable), $ttl);
        }
    }

}