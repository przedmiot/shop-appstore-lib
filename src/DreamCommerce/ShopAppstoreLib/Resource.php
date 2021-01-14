<?php

namespace DreamCommerce\ShopAppstoreLib;

use DreamCommerce\ShopAppstoreLib\Client\Exception\Exception;
use DreamCommerce\ShopAppstoreLib\Exception\HttpException;
use DreamCommerce\ShopAppstoreLib\Resource\Bulk;
use DreamCommerce\ShopAppstoreLib\Resource\Exception\CommunicationException;
use DreamCommerce\ShopAppstoreLib\Resource\Exception\ObjectLockedException;
use DreamCommerce\ShopAppstoreLib\Resource\Exception\MethodUnsupportedException;
use DreamCommerce\ShopAppstoreLib\Resource\Exception\NotFoundException;
use DreamCommerce\ShopAppstoreLib\Resource\Exception\PermissionsException;
use DreamCommerce\ShopAppstoreLib\Resource\Exception\ResourceException;
use DreamCommerce\ShopAppstoreLib\Resource\Exception\ValidationException;

/**
 * Class Resource
 * @package DreamCommerce
 */
class Resource
{
    /**
     * Object '%s' as id: '%s' is read-only and cannot be modified
     */
    const HTTP_ERROR_OBJECT_READONLY = 'object_readonly';

    /**
     * Cannot delete internal object '%s' as id: '%s'
     */
    const HTTP_ERROR_CANNOT_DELETE_INTERNAL_OBJECT = 'cannot_delete_internal_object';

    /**
     * @var ClientInterface|null
     */
    public $client = null;

    /**
     * @var string|null resource name
     */
    protected $name = null;

    /**
     * @var null|string chosen filters placeholder
     */
    protected $filters = null;

    /**
     * @var null|int limiter value
     */
    protected $limit = null;

    /**
     * @var null|string ordering value
     */
    protected $order = null;

    /**
     * @var null|int page number
     */
    protected $page = null;

    /**
     * @var bool specifies whether resource has no collection at all
     */
    protected $isSingleOnly = false;

    /**
     * @var array
     */
    protected static $resources = array();

    /**
     * @var data to submit - for POSTs and PUTs
     */
    public $requestData;

    /**
     * @var Id of a resource - for GET, PUT, DELETE or HEAD requests
     */
    public $resourceId;


    /**
     * @param ClientInterface $client
     */
    public function __construct(ClientInterface $client)
    {
        $this->client = $client;
    }

    /**
     * @param ClientInterface $client
     * @param string $name
     * @param boolean $forceRecreateResource
     * @return Resource
     * @throws ResourceException
     */
    public static function factory(ClientInterface $client, $name, $forceRecreateResource = false)
    {
        $name = ucfirst($name);
        if (!isset(self::$resources[$name]) || $forceRecreateResource) {

            $class = "\\DreamCommerce\\ShopAppstoreLib\\Resource\\" . $name;
            if (class_exists($class)) {
                self::$resources[$name] = new $class($client);
            } else {
                throw new ResourceException("Unknown Resource '" . $name . "'");
            }
        }

        return self::$resources[$name];
    }

    /**
     * returns resource name
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @param $response
     * @param bool $isCollection should transform response as a collection?
     * @return mixed
     * @throws ResourceException
     */
    protected function transformResponse($response, $isCollection)
    {
        $code = null;
        if (isset($response['headers']['Code'])) {
            $code = $response['headers']['Code'];
        }

        // everything is okay when 200-299 status code
        if ($code >= 200 && $code < 300) {
            // for example, last insert ID
            if ($isCollection) {
                if (isset($response['data']['list'])) {
                    $objectList = new ResourceList($response['data']['list']);
                } else {
                    $objectList = new ResourceList();
                }

                // add meta properties (eg. count, page, etc) as a ArrayObject properties
                if (isset($response['data']['page'])) {
                    $objectList->setPage($response['data']['page']);
                } elseif (isset($response['headers']['X-Shop-Result-Page'])) {
                    $objectList->setPage($response['headers']['X-Shop-Result-Page']);
                }

                if (isset($response['data']['count'])) {
                    $objectList->setCount($response['data']['count']);
                } elseif (isset($response['headers']['X-Shop-Result-Count'])) {
                    $objectList->setCount($response['headers']['X-Shop-Result-Count']);
                }

                if (isset($response['data']['pages'])) {
                    $objectList->setPageCount($response['data']['pages']);
                } elseif (isset($response['headers']['X-Shop-Result-Pages'])) {
                    $objectList->setPageCount($response['headers']['X-Shop-Result-Pages']);
                }

                return $objectList;
            } else {

                $result = $response['data'];

                if (!is_scalar($response['data'])) {
                    $result = new \ArrayObject(
                        ResourceList::transform($result)
                    );
                }

                return $result;
            }

        } else {

            if (isset($response['data']['error'])) {
                $msg = $response['data']['error'];
            } else {
                $msg = $response;
            }

            throw new ResourceException($msg, $code);
        }
    }

    /**
     * reset object request
     */
    public function reset()
    {
        $this->filters = array();
        $this->limit = null;
        $this->order = null;
        $this->page = null;
        $this->resourceId = null;
        $this->requestData = null;

        return $this;
    }

    /**
     * get an array with specified criteria
     * @return array
     */
    public function getCriteria()
    {
        $result = array();

        if ($this->filters) {
            $result['filters'] = $this->filters;
        }

        if ($this->limit !== null) {
            $result['limit'] = $this->limit;
        }

        if ($this->order !== null) {
            $result['order'] = $this->order;
        }

        if ($this->page !== null) {
            $result['page'] = $this->page;
        }

        return $result;
    }

    /**
     * set records limit
     * @param int $count collection's items limit in range 1-50
     * @return $this
     * @throws \RuntimeException
     */
    public function limit($count)
    {
        if ($count < 1 || $count > 50) {
            throw new \RuntimeException('Limit beyond 1-50 range', ResourceException::LIMIT_BEYOND_RANGE);
        }

        $this->limit = $count;

        return $this;
    }

    /**
     * set filters for finding
     * @param array $filters
     * @return $this
     * @throws \RuntimeException
     */
    public function filters($filters)
    {
        if (!is_array($filters)) {
            throw new \RuntimeException('Filters not specified', ResourceException::FILTERS_NOT_SPECIFIED);
        }

        $this->filters = json_encode($filters);

        return $this;
    }

    /**
     * specify page
     * @param int $page
     * @return $this
     * @throws \RuntimeException
     */
    public function page($page)
    {
        $page = (int)$page;

        if ($page < 0) {
            throw new \RuntimeException('Invalid page specified', ResourceException::INVALID_PAGE);
        }

        $this->page = $page;

        return $this;
    }

    /**
     * order record by column
     * @param string $expr syntax:
     * <field> (asc|desc)
     * or
     * (+|-)<field>
     * @return $this
     * @throws \RuntimeException
     */
    public function order($expr)
    {
        $matches = array();

        $expr = (array)$expr;

        $result = array();

        foreach ($expr as $e) {
            // basic syntax, with asc/desc suffix
            if (preg_match('/([a-z_0-9.]+) (asc|desc)$/i', $e)) {
                $result[] = $e;
            } else if (preg_match('/([\+\-]?)([a-z_0-9.]+)/i', $e, $matches)) {

                // alternative syntax - with +/- prefix
                $subResult = $matches[2];
                if ($matches[1] == '' || $matches[1] == '+') {
                    $subResult .= ' asc';
                } else {
                    $subResult .= ' desc';
                }
                $result[] = $subResult;
            } else {
                // something which should never happen but take care [;
                throw new \RuntimeException('Cannot understand ordering expression', ResourceException::ORDER_NOT_SUPPORTED);
            }
        }

        $this->order = $result;

        return $this;
    }

    /**
     * Read Resource
     *
     * @param mixed $args,... params
     * @return \ArrayObject
     * @throws ResourceException
     */
    public function get($id = null)
    {
        if ($id) {
            $this->addResourceId($id);
        }

        $response = '';

        try {
            $response = $this->client->request($this, 'get');
        } catch (Exception $ex) {
            $this->dispatchException($ex);
        }

        return $this->transformResponse($response, $this->isCollection());
    }


    /**
     * Convetrs simple get to "bulk" get. Useful in case of large query string (i.e. many ids in filter)
     *
     * @return mixed
     * @throws Exception
     * @throws ResourceException
     * @throws Resource\Exception\BulkException
     */
    public function getBulk($id = null)
    {
        $result = (new Bulk($this->client))->addResources($this->addResourceId($id))->post()[0];
        if ($result instanceof Exception) {
            throw $result;
        }

        return $result;
    }

    /**
     * Returns all rows of a given resource using _bulk_ API method.
     *
     * @param callable $ownIndexCallback callback for returned list custom manipulations (will get: row data, org index; should return an array [index, data of the row])
     * @return array
     * @throws ResourceException
     * @throws Resource\Exception\BulkException
     */
    public function getWholeListBulk($ownIndexCallback = null)
    {
        $clone = clone $this->limit(ResourceList::MAX_ROWS_PER_PAGE);
        $clone->resourceId = null;

        //first page - we want to know how many pages are there
        $response = $clone->page(1)->getBulk();
        $list = $response->list->getArrayCopy();

        if (1 < $response->pages) {
            $page = 2;
            //second and another pages
            $bulkOfResources = [];
            while ($page <= $response->pages) {
                $clone = clone $clone;
                $clone->page($page);
                $bulkOfResources[] = $clone;
                if (Bulk::RESOURCES_QUANTITY_LIMIT == count($bulkOfResources) || $page == $response->pages) {
                    $bulkResult = (new Resource\Bulk($this->client))->addResources($bulkOfResources)->post();
                    foreach ($bulkResult as $item) {
                        if ($item instanceof \Exception) {
                            throw $item;
                        }
                        $list = array_merge($list, $item->list->getArrayCopy());
                    }
                    $bulkOfResources = [];
                }
                $page++;
            }
        }

        if ($ownIndexCallback) {
            if (!is_callable($ownIndexCallback)) {
                throw new ResourceException('Argument should be callable');
            }

            $list2 = [];
            foreach ($list as $i => $row) {
                list($index, $data) = call_user_func($ownIndexCallback, $row, $i);
                $list2[$index] = $data;
            }
            $list = $list2;
        }

        return $list;
    }


    /**
     * Read Resource without data
     *
     * @return \ArrayObject
     * @throws ResourceException
     */
    public function head($id = null)
    {
        if ($id) {
            $this->addResourceId($id);
        }

        if (!$this->resourceId) {
            throw new ResourceException('I\'m not sure what should be checked... Give me an id.', ResourceException::UNSUFFICIENT_CALL_ARGUMENTS);
        }

        $response = '';

        try {
            $response = $this->client->request($this, 'head');
        } catch (Exception $ex) {
            $this->dispatchException($ex);
        }

        return $this->transformResponse($response, false);
    }

    /**
     * determines if resource call is collection or not
     * @param $args
     * @return bool
     */
    public function isCollection()
    {
        if ($this->isSingleOnly || is_null($this->resourceId)) {
            return false;
        }
    }

    /**
     * Create Resource
     * @param array $data
     * @return integer
     * @throws ResourceException
     */
    public function post($data = [])
    {
        if ($data) {
            $this->addRequestData($data);
        }
        $response = '';
        try {
            $response = $this->client->request($this, 'post');
            return $response['data'];
        } catch (Exception $ex) {
            $this->dispatchException($ex);
        }
    }

    /**
     * Update Resource
     * @param null|int $id
     * @param array $data
     * @return bool
     * @throws ResourceException
     */
    public function put($id = null, $data = array())
    {
        if ($id) {
            $this->addResourceId($id);
        }
        if ($data) {
            $this->addRequestData($data);
        }

        if (!$this->resourceId) {
            throw new ResourceException('I\'m not sure what should be updated... Give me an id.', ResourceException::UNSUFFICIENT_CALL_ARGUMENTS);
        }

        try {
            $this->client->request($this, 'put');
        } catch (Exception $ex) {
            $this->dispatchException($ex);
        }

        return true;
    }

    /**
     * Delete Resource
     * @param int $id
     * @return bool
     * @throws ResourceException
     */
    public function delete($id)
    {
        if ($this->getCriteria()) {
            throw new ResourceException('Filtering not supported in DELETE', ResourceException::FILTERS_IN_UNSUPPORTED_METHOD);
        }

        if ($id) {
            $this->addResourceId($id);
        }

        if (!$this->resourceId) {
            throw new ResourceException('I\'m not sure what should be deleted... Give me an id.', ResourceException::UNSUFFICIENT_CALL_ARGUMENTS);
        }

        try {
            $this->client->request($this, 'delete');
        } catch (Exception $ex) {
            $this->dispatchException($ex);
        }

        return true;
    }

    protected function dispatchException(Exception $ex)
    {

        /**
         * @var $httpException HttpException
         */
        $httpException = $ex->getPrevious();

        if (!$httpException) {
            throw $ex;
        }

        switch ($httpException->getCode()) {
            case 400:
                throw new ValidationException($httpException->getResponse(), 0, $httpException);
            case 404:
                throw new NotFoundException($httpException->getResponse(), 0, $httpException);
            case 405:
                throw new MethodUnsupportedException($httpException->getResponse(), 0, $httpException);
            case 409:
                throw new ObjectLockedException($httpException->getResponse(), 0, $httpException);
            case 401:
                throw new PermissionsException($httpException->getResponse(), 0, $httpException);
        }

        $exception = new CommunicationException($httpException->getMessage(), $httpException->getCode(), $httpException);

        $logger = $this->client->getLogger();
        // log error if no custom logger is configured
        if ($logger && $logger instanceof Logger) {
            $logger->error((string)$httpException, array((string)$httpException));
        }

        throw $exception;

    }

    /**
     * for put or post
     *
     * @param $data
     * @return $this
     */

    public function addRequestData($data)
    {
        $this->requestData = $data;
        return $this;
    }

    /**
     * For get, put or delete
     *
     * @param $id
     * @return $this
     */
    public function addResourceId($id)
    {
        $this->resourceId = $id;
        return $this;
    }
}
