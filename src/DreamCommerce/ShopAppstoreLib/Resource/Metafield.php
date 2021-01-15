<?php

namespace DreamCommerce\ShopAppstoreLib\Resource;

use DreamCommerce\ShopAppstoreLib\Resource;
use DreamCommerce\ShopAppstoreLib\Exception\ClientException;

/**
 * Resource Metafield
 *
 * @package DreamCommerce\ShopAppstoreLib\Resource
 * @link https://developers.shoper.pl/developers/api/resources/metafields
 */
class Metafield extends Resource
{
    /**
     * type of integer
     */
    const TYPE_INT = 1;
    /**
     * type of float
     */
    const TYPE_FLOAT = 2;
    /**
     * type of string
     */
    const TYPE_STRING = 3;
    /**
     * type of binary data
     */
    const TYPE_BLOB = 4;

    protected $name = 'metafields';

    public $objectName = 'system';

    public function get($object = null, $id = null)
    {
        if ($object) {
            $this->setObjectName($object);
        }

        return parent::get($id);
    }

    public function getBulk($object = null, $id = null)
    {
        if ($object) {
            $this->setObjectName($object);
        }

        return parent::getBulk($id);
    }

    public function delete($id, $object = null)
    {
        if ($object) {
            $this->setObjectName($object);
        }

        return parent::delete($id);
    }


    public function setObjectName($name)
    {
        $this->objectName = $name;
        return $this;
    }

    public function getResourceIdentifiers()
    {
        return $this->objectName . ($this->resourceId ? '/' . $this->resourceId : '');
    }

    public function resetIdentifiers()
    {
        parent::resetIdentifiers();
        $this->objectName = 'system';
    }

    public function addRequestData($data)
    {
        if (@$data['object']) {
            $this->objectName = $data['object'];
        }
        return parent::addRequestData($data);
    }


}