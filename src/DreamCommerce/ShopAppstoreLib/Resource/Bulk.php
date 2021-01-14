<?php


namespace DreamCommerce\ShopAppstoreLib\Resource;

use DreamCommerce\ShopAppstoreLib\Client\Exception\Exception;
use DreamCommerce\ShopAppstoreLib\Exception\HttpException;
use DreamCommerce\ShopAppstoreLib\Resource;

class Bulk extends Resource implements \Countable
{
    const RESOURCES_QUANTITY_LIMIT = 25;
    const API_PATH = '/webapi/rest/';

    protected $bulkOfResources = [];

    protected $name = 'bulk';

    public function addResources($resourceOrResources, $requestMethod = 'get')
    {
        if (!is_array($resourceOrResources)) {
            $resourceOrResources = [$resourceOrResources];
        }

        foreach ($resourceOrResources as $res) {
            if (count($this->bulkOfResources) >= self::RESOURCES_QUANTITY_LIMIT) {
                throw new Resource\Exception\BulkException(sprintf('Bulk can contain only %d resource(s).', self::RESOURCES_QUANTITY_LIMIT));
            }

            if (!$res instanceof Resource) {
                throw new Resource\Exception\BulkException(sprintf('You should pass (an) instance(s) of %s as a first argument.', Resource::class));
            }

            $this->bulkOfResources[] = [
                'requestMethod' => $requestMethod,
                'resource' => $res
            ];
        }

        return $this;
    }

    protected function createPost()
    {
        $iWillBeASonOfJ = [];

        foreach ($this->bulkOfResources as $i => $resource) {
            $path = self::API_PATH . $resource['resource']->getName();

            if ($resource['resource']->resourceId) {
                $path .= '/' . $resource['resource']->resourceId;
            }

            $singleCall = [
                'id' => $i + 1,
                'path' => $path,
                'method' => $resource['requestMethod']
            ];

            if ($resource['resource']->requestData) {
                $singleCall['body'] = $resource['resource']->requestData;
            }

            if ($criteria = $resource['resource']->getCriteria()) {
                $singleCall['params'] = $criteria;
            }

            $iWillBeASonOfJ[] = $singleCall;
        }

        return $iWillBeASonOfJ;
    }

    public function post($data = [])
    {
        $data = parent::post($this->createPost());

        $responses = [];

        foreach ($data['items'] as $item) {
            $index = $item['id'] - 1;

            if ($item['code'] >= 400) {
                $httpEx = new HttpException(@$item['body']['error_description'] ?: @$item['body']['error'], $item['code']);
                $responses[$index] = new Exception('HTTP error: ' . $httpEx->getMessage(), Exception::API_ERROR, $httpEx);
                continue;
            }

            $resourceArr = $this->bulkOfResources[$index];
            if (in_array($resourceArr['requestMethod'], ['get', 'head'])) {
                $responses[$index] = $this->transformResponse([
                    'headers' => [
                        'Code' => $item['code']
                    ],
                    'data' => $item['body']
                ], $resourceArr['resource']->isCollection());
            } elseif ('post' === $resourceArr['requestMethod']) {
                $responses[$index] = $item['body'];
            } elseif (in_array($resourceArr['requestMethod'], ['put', 'delete'])) {
                $responses[$index] = 1 == $item['body'] ? true : false;
            }
        }

        return $responses;
    }

    public function get($id = null)
    {
        throw new Resource\Exception\MethodUnsupportedException();
    }

    public function head($id = null)
    {
        throw new Resource\Exception\MethodUnsupportedException();
    }

    public function put($id = null, $data = array())
    {
        throw new Resource\Exception\MethodUnsupportedException();
    }

    public function delete($id)
    {
        throw new Resource\Exception\MethodUnsupportedException();
    }

    public function count()
    {
        return count($this->bulkOfResources);
    }


}