<?php

namespace DreamCommerce\ShopAppstoreLib\Itl;

interface ShopValve
{
    public function __construct($shop, array $exceptions = []);

    public function considerException(\Exception $e): void;

    public function reset(): void;

    public function setExceptions(array $exceptions): void;

    public function setLastExceptionDateTimeName(string $lastExceptionDateTimeName);

    public function setExceptionsCounterName(string $exceptionsCounterName);

    public function getLastExceptionDateTimeName(): string;

    public function getExceptionsCounterName(): string;
    
    public function isLimitExceeded(): bool;
}