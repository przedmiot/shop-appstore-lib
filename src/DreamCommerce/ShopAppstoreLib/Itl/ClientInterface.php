<?php
namespace DreamCommerce\ShopAppstoreLib\Itl;

interface ClientInterface extends \DreamCommerce\ShopAppstoreLib\ClientInterface {
    public function setClient($client);
    public function getClient();
    public function getEntrypoint();
}