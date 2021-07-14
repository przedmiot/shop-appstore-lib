<?php


namespace DreamCommerce\ShopAppstoreLib\Itl;


use DreamCommerce\ShopAppstoreLib\Client\OAuth;
use DreamCommerce\ShopAppstoreLib\Exception\HttpException;
use Illuminate\Support\Facades\Cache;
use Itl\ShoperAppStoreFoundation\Events\ShoperAPIAccessTokenReceived;
use Itl\ShoperAppStoreFoundation\LaravelLoggerAdapter;
use Itl\ShoperAppStoreFoundation\Misc\ShoperApisAreEnabledHelper;
use DreamCommerce\ShopAppstoreLib\Itl\Exception\APIClientFactoryExceptionRecoverable;
use DreamCommerce\ShopAppstoreLib\Itl\Exception\ClientFactoryException;


abstract class AbstractClientFactory
{
    const AUTH_CODE_VALIDITY = 86400;

    const AUTH_CODE_OUTDATED = 1;
    const ACCESS_TOKEN_OBTAINING_PROBLEM = 2;
    const ACCESS_TOKEN_REFRESHING_PROBLEM = 3;

    protected static $instances = [];

    protected $shopModel;

    /**
     * @var \DreamCommerce\ShopAppstoreLib\Itl\Client
     */
    protected $APIClient;

    protected $accessTokenColumnName = 'access_token';
    protected $refreshTokenColumnName = 'refresh_token';
    protected $authCodeColumnName = 'auth_code';

    protected $accessTokenExpiresColumnName = 'expires';
    protected $accessTokenExpiresColumnIsUnixTimestamp = true;

    protected $recordCreatedColumnName = 'created';

    protected $shopURLColumnName = 'shop_url';


    protected abstract function getClientSecret();
    protected abstract function getClientId();
    protected abstract function getQueriesLogRootDir();
    protected abstract function getPSRLogger();
    protected abstract function logWholeCommunication();
    protected abstract function getLocale();
    protected abstract function clientAdditionalConfiguration(Client $client);
    protected abstract function onAuthenticate($shop);
    protected abstract function reportException(\Exception $e);
    protected abstract function storeShopModel($shop);

    /**
     * @param  $shopModel
     * @param null $retryLimit API calls retries limit (overwrites default HTTP Class limit if passed)
     * @return Client
     * @throws \DreamCommerce\ShopAppstoreLib\Exception\HttpException
     */
    public static function factory($shopModel, $retryLimit = null)
    {
        if (!@self::$instances[$shopModel->id]) {
            new static($shopModel);
        }
        if ($retryLimit) {
            Http::setRetryLimit($retryLimit);
        }
        return self::$instances[$shopModel->id];
    }


    protected function throwRecoverableException($exceptionType, $msg = null) {
        throw new APIClientFactoryExceptionRecoverable($msg ?: __('Recoverable exception'), $exceptionType);
    }

    private function __construct($shopModel)
    {
        if (!is_object($shopModel)) {
            throw new ClientFactoryException('Some kind of an object representing a shop was expected...');
        }

        $this->shopModel = $shopModel;

        self::$instances[$shopModel->id] = $this->APIClient = new Client (
            new OAuth(
                [
                    'entrypoint' => $shopModel->{$this->shopURLColumnName},
                    'client_id' => $this->getClientId(),
                    'client_secret' => $this->getClientSecret(),
                ]), $this->getQueriesLogRootDir(),
            $this->logWholeCommunication() ? $this->getPSRLogger() : null
        );

        $this->APIClient->locale = $this->getLocale();
        
        if (!$shopModel->accessToken) {
            $this->getAccessToken();
        } else {
            $this->refreshAccessTokenIfItsNeeded();
        }

        $this->APIClient->setAccessToken($this->shopModel->accessToken);

        $this->clientAdditionalConfiguration($this->APIClient);
    }

    protected function getAccessToken()
    {
        if (!$this->shopModel->{$this->authCodeColumnName}) {
            throw new ClientFactoryException('No auth_code.');
        }

        if (strtotime($this->shopModel->{$this->recordCreatedColumnName}) + self::AUTH_CODE_VALIDITY <= time()) {
            $this->throwRecoverableException(self::AUTH_CODE_OUTDATED, __('Application didn\'t receive access token during the required time period. Reinstall it please. Then repeat your current action.'));
        }

        try {
            $this->storeAuthData($this->APIClient->authenticate($this->shopModel->{$this->authCodeColumnName}));
            $this->onAuthenticate($this->shopModel);
        } catch (\DreamCommerce\ShopAppstoreLib\Exception\Exception $e) {
            if ($e instanceof HttpException && HttpException::MALFORMED_RESULT == $e->getCode()) {
            } else {
                $this->reportException($e);
            }

            $this->throwRecoverableException(self::ACCESS_TOKEN_OBTAINING_PROBLEM, __('We have problems with obtaining of access token. Wait a moment and try again. If it doesn\'t help - reinstall the application.  If reinstalling doesn\'t give a desired effect - contact the service provider.'));
        }
    }

    protected function refreshAccessTokenIfItsNeeded()
    {
        $tokenExpirationUTimestamp =  $this->accessTokenExpiresColumnIsUnixTimestamp ? $this->shopModel->{$this->accessTokenExpiresColumnName} : new (\DateTime( $this->shopModel->{$this->accessTokenExpiresColumnName}))->format('U');

        if ($tokenExpirationUTimestamp >= time() + 60 * 60 * 24) {
            //token valid longer than 1 day
            return false;
        }

        try {
            $this->storeAuthData($this->APIClient->refreshTokens($this->shopModel->{$this->refreshTokenColumnName}));
        } catch (\DreamCommerce\ShopAppstoreLib\Exception\Exception $e) {
            if ($e instanceof HttpException && HttpException::MALFORMED_RESULT == $e->getCode()) {
            } else {
                $this->reportException($e);
            }
            $this->throwRecoverableException(self::ACCESS_TOKEN_REFRESHING_PROBLEM, __('We have problems with refreshing of access token. Wait a moment and try again. If it doesn\'t help - reinstall the application.  If reinstalling doesn\'t give a desired effect - contact the service provider.'));
        }
    }

    protected function storeAuthData($authObj)
    {
        $this->shopModel->{$this->accessTokenColumnName} = $authObj['access_token'];
        $this->shopModel->{$this->refreshTokenColumnName} = $authObj['refresh_token'];

        $tokenExpirationDate =  new \DateTime('@' . ($authObj['expires_in'] + time()));

        $this->shopModel->{$this->accessTokenExpiresColumnName} = $this->accessTokenExpiresColumnIsUnixTimestamp ? $tokenExpirationDate->format('U') : $tokenExpirationDate->format('Y-m-d H:i:s');

        $this->storeShopModel($this->shopModel);
    }
}