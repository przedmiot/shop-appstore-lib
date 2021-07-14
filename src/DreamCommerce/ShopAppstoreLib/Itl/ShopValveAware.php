<?php


namespace DreamCommerce\ShopAppstoreLib\Itl;

interface ShopValveAware
{
    /**
     * A valve is an object responsible for disabling an integration in response of too many exceptions
     *
     * @param ShopValve $valve
     */
    public function setValve(ShopValve $valve);
}