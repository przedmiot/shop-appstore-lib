<?php
use Dreamcommerce\Exceptions\ClientException;
use Dreamcommerce\Exceptions\ResourceException;

require '../vendor/autoload.php';
require 'config.php';

try {
    $client = new Dreamcommerce\Client(
        Config::ENTRYPOINT, Config::APPID, Config::APP_SECRET
    );

    $client->setAccessToken('<INSERT TOKEN HERE>');

    $resource = $client->producers;
    // or
    //$resource = new \Dreamcommerce\Resource($client, 'producers');

    $insertedId = $resource->put(2, array(
        'name' => 'Awesome Manufacturer!'
    ));

    printf("Object modified");

} catch (ClientException $ex) {
    printf("An error occurred during the Client initialization: %s", $ex->getMessage());
} catch (ResourceException $ex) {
    printf("An error occurred during Resource access: %s", $ex->getMessage());
}