<?php

namespace BitWasp\Bitcoin\Networking\Messages;

use BitWasp\Bitcoin\Networking\Serializer\Message\GetDataSerializer;
use BitWasp\Bitcoin\Networking\Serializer\Structure\InventorySerializer;

class GetData extends AbstractInventory
{
    /**
     * @return string
     */
    public function getNetworkCommand()
    {
        return 'getdata';
    }

    /**
     * {@inheritdoc}
     * @see \BitWasp\Bitcoin\SerializableInterface::getBuffer()
     */
    public function getBuffer()
    {
        return (new GetDataSerializer(new InventorySerializer()))->serialize($this);
    }
}
