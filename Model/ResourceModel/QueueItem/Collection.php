<?php
declare(strict_types=1);
namespace Merlin\CacheWarmer\Model\ResourceModel\QueueItem;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'queue_id';
    protected function _construct()
    {
        $this->_init(\Merlin\CacheWarmer\Model\QueueItem::class, \Merlin\CacheWarmer\Model\ResourceModel\QueueItem::class);
    }
}
