<?php

namespace DreamCommerce\ShopAppstoreLib\Itl;

use DreamCommerce\ShopAppstoreLib\Exception\Exception;
use DreamCommerce\ShopAppstoreLib\Itl\Exception\CurrencyNotFoundException;
use DreamCommerce\ShopAppstoreLib\Resource;
use DreamCommerce\ShopAppstoreLib\Resource\ProductStock;
use Itl\Utils\Misc\BC;

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

    protected $currenciesRatesCache;

    protected $currenciesIdsCache;

    protected $defaultCurrCache;

    protected $shopDataCache;

    const GET_WHOLE_LIST_PAGES_LIMIT = 1000;

    const GET_WHOLE_LIST_ROWS_PER_PAGE = 50;


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
        $response = $resource->limit(self::GET_WHOLE_LIST_ROWS_PER_PAGE)->page($page)->get();
        if ($response->count) {
            foreach ($response as $i => $row) {
                if (is_callable($grabRowCallback)) {
                    list($index, $data) = call_user_func($grabRowCallback, $row,
                        ($page - 1) * self::GET_WHOLE_LIST_ROWS_PER_PAGE + $i);
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
        if (!$priceCurrencyAbbr) {
            $priceCurrencyAbbr = $this->defaultCurrCache;
        }
        if (!$exchangeToCurrencyAbbr) {
            $exchangeToCurrencyAbbr = $this->defaultCurrCache;
        }

        if ($priceCurrencyAbbr === $exchangeToCurrencyAbbr) {
            return BC::bcround($price);
        }

        if ($priceCurrencyAbbr === $this->defaultCurrCache) {
            return $this->exchangeFromDefaultShopCurr($price, $exchangeToCurrencyAbbr);
        } else {
            if ($exchangeToCurrencyAbbr === $this->defaultCurrCache) {
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
        return BC::bcround(bcmul($price, $this->currenciesRatesCache[$currAbbr], 3));
    }

    public function exchangeFromDefaultShopCurr($price, $currAbbr)
    {
        $this->getCurrencyIdByAbbr($currAbbr);
        if (0 == $this->currenciesRatesCache[$currAbbr]) {
            throw new Exception('Exchange rate of :curr should be greater than 0', [
                'curr' => $currAbbr
            ]);
        }
        return BC::bcround(bcdiv($price, $this->currenciesRatesCache[$currAbbr], 3));
    }

    /**
     * @param $currAbbr
     * @return integer
     * @throws CurrencyNotFoundException
     */
    public function getCurrencyIdByAbbr($currAbbr)
    {
        $this->loadCurrencies();
        if (!isset($this->currenciesIdsCache[$currAbbr])) {
            throw new CurrencyNotFoundException(__('Currency :curr is not defined in the shop!', [
                'curr' => $currAbbr
            ]));
        }
        return $this->currenciesIdsCache[$currAbbr];
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
            }
            else {
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
        if (is_null($this->currenciesIdsCache)) {
            $currencies = static::getWholeList($this->resource('Currency')->filters(['active' => 1]));
            $this->currenciesRatesCache = [];
            foreach ($currencies as $currency) {
                $this->currenciesIdsCache[$currency->name] = $currency->currency_id;
                if ($currency->default) {
                    $this->defaultCurrCache = $currency->name;
                } else {
                    $this->currenciesRatesCache[$currency->name] = $currency->rate;
                }
            }
        }
    }


    protected function loadShopData()
    {
        if (is_null($this->shopDataCache)) {
            $this->shopDataCache = $this->ApplicationConfig->get();
            $this->defaultCurrCache = $this->shopDataCache->default_currency_name;
        }
        return $this->shopDataCache;
    }

}
