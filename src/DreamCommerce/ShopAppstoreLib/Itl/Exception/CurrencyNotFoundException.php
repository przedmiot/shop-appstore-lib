<?php
namespace DreamCommerce\ShopAppstoreLib\Itl\Exception;

use DreamCommerce\ShopAppstoreLib\Resource\Exception\NotFoundException;
use Throwable;

class CurrencyNotFoundException extends NotFoundException
{
    public $currencyCode;

    public function __construct($currencyCode, $code = 0, Throwable $previous = null)
    {

        $this->currencyCode = $currencyCode;

        parent::__construct(sprintf('Currency %s not found or is not active in the shop', $currencyCode), $code, $previous);
    }

}