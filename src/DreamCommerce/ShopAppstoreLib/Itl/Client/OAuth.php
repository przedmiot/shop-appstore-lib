<?php
namespace DreamCommerce\ShopAppstoreLib\Itl\Client;

  use DreamCommerce\ShopAppstoreLib\Itl\Client;
  use DreamCommerce\ShopAppstoreLib\Itl\ClientInterface;
  use DreamCommerce\ShopAppstoreLib\Itl\Http;
  use DreamCommerce\ShopAppstoreLib\HttpInterface;
  use DreamCommerce\ShopAppstoreLib\Client\Exception\OAuthException;

  class OAuth extends \DreamCommerce\ShopAppstoreLib\Client\OAuth implements ClientInterface
  {

     protected $client;


    /**
     * {@inheritdoc}
     */
      public function setHttpClient(HttpInterface $httpClient)
      {
          $this->httpClient = $httpClient;
          if ($this->logger) {
              $this->httpClient->setLogger($this->logger);
          }

          return $this;
      }

      /**
       * {@inheritdoc}
       */
      public function getHttpClient()
      {
          if ($this->httpClient === null) {
              $this->setHttpClient(new Http());
          }

          return $this->httpClient;
      }

      public function setClient($client)
      {
          $this -> client = $client;
      }

      public function getClient() {
          return $this->client;
      }

      public function getEntrypoint() {
          return $this->entrypoint;
      }

      /**
       * Overwritten becouse org library doesn't check if $res['data'] is an array
       * Refresh OAuth tokens
       *
       * @return array
       * @throws \DreamCommerce\Exception\ClientException
       */
      public function refreshTokens()
      {
          $res = $this->getHttpClient()->post($this->entrypoint . '/oauth/token', [
              'client_id' => $this->getClientId(),
              'client_secret' => $this->getClientSecret(),
              'refresh_token' => $this->getRefreshToken()
          ], [
              'grant_type'=>'refresh_token'
          ], [
              'Content-Type' => 'application/x-www-form-urlencoded'
          ]);


          if (
           !$res
            || !is_array($res['data'])
            || !empty($res['data']['error'])
            || !@$res['data']['access_token']
            || !@$res['data']['refresh_token']
        ) {
              throw new OAuthException($res['error'] ?: 'There are no new tokens in the answer...', OAuthException::API_ERROR);
          }

          $this->accessToken = $res['data']['access_token'];
          $this->refreshToken = $res['data']['refresh_token'];
          $this->expiresIn = (int)$res['data']['expires_in'];
          $this->scopes = explode(',', $res['data']['scope']);

          return $res['data'];
      }




  }
