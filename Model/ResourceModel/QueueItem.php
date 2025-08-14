<?php
declare(strict_types=1);
namespace Merlin\CacheWarmer\Model\ResourceModel;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class QueueItem extends AbstractDb
{
    protected function _construct()
    {
        $this->_init('merlin_cachewarmer_queue', 'queue_id');
    }
}
