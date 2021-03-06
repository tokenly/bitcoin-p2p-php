<?php

namespace BitWasp\Bitcoin\Networking\Serializer\Message;

use BitWasp\Bitcoin\Networking\Messages\GetData;
use BitWasp\Bitcoin\Networking\Serializer\Structure\InventorySerializer;
use BitWasp\Buffertools\Parser;
use BitWasp\Buffertools\TemplateFactory;

class GetDataSerializer
{
    /**
     * @var InventorySerializer
     */
    private $inv;

    /**
     * @param InventorySerializer $inv
     */
    public function __construct(InventorySerializer $inv)
    {
        $this->inv = $inv;
    }

    /**
     * @return \BitWasp\Buffertools\Template
     */
    public function getTemplate()
    {
        return (new TemplateFactory())
            ->vector(function (Parser &$parser) {
                return $this->inv->fromParser($parser);
            })
            ->getTemplate();
    }

    /**
     * @param Parser $parser
     * @return GetData
     */
    public function fromParser(Parser & $parser)
    {
        list ($addrs) = $this->getTemplate()->parse($parser);
        return new GetData($addrs);
    }

    /**
     * @param $data
     * @return GetData
     */
    public function parse($data)
    {
        return $this->fromParser(new Parser($data));
    }

    /**
     * @param GetData $getData
     * @return \BitWasp\Buffertools\Buffer
     */
    public function serialize(GetData $getData)
    {
        return $this->getTemplate()->write([
            $getData->getItems()
        ]);
    }
}
