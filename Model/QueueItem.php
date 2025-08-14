<?php
declare(strict_types=1);
namespace Merlin\CacheWarmer\Model;
use Magento\Framework\Model\AbstractModel;

class QueueItem extends AbstractModel
{
    protected function _construct()
    {
        $this->_init(\Merlin\CacheWarmer\Model\ResourceModel\QueueItem::class);
    }
}
