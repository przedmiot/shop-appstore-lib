<?php

namespace DreamCommerce\ShopAppstoreLib\Itl;

use Itl\Utils\Misc\SimpleLog;

class Querieslog extends SimpleLog
{
    protected $tryCounter = '';
    protected $url = '';
    protected $reponseCode = '';
    protected $responseStatus = '';
    protected $method = '';
    public static $instance;
    public static $logRootDir = 'appStoreQueriesLogs';

    public function method($method)
    {
        $this->method = $method;
        return $this;
    }

    public function tryCounter($i)
    {
        $this->tryCounter = $i;
        return $this;
    }

    public function url($url)
    {
        $this->url = urldecode($url);
        return $this;
    }

    public function responseCode($responseCode)
    {
        $this->responseCode = $responseCode;
        return $this;
    }

    public function responseStatus($responseStatus)
    {
        $this->responseStatus .= $responseStatus;
        return $this;
    }

    public function write()
    {
        if (!$this->url) {
            return;
        }
        $this->log('(pid:' . getmypid() . ') ' . $this->method . ' ' . $this->url . ' (try ' . $this->tryCounter . ') ' . @$this->responseCode . ' ' . $this->responseStatus);
        $this->method = $this->url = $this->tryCounter = $this->responseCode = $this->responseStatus = '';
    }

    public static function factory()
    {
        if (self::$instance) {
            return self::$instance;
        }
        self::$instance = new static(static::$logRootDir);
        register_shutdown_function(function () {
            static::factory()->write();
        });
        return self::$instance;
    }
}
