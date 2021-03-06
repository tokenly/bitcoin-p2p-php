<?php

require_once "../vendor/autoload.php";


use BitWasp\Bitcoin\Bloom\BloomFilter;
use BitWasp\Bitcoin\Chain\BlockHashIndex;
use BitWasp\Bitcoin\Chain\BlockHeightIndex;
use BitWasp\Bitcoin\Chain\BlockIndex;
use BitWasp\Buffertools\Buffer;
use BitWasp\Bitcoin\Networking\Peer\Peer;
use BitWasp\Bitcoin\Flags;
use BitWasp\Bitcoin\Networking\Structure\Inventory;

function decodeInv(Peer $peer, \BitWasp\Bitcoin\Networking\Messages\Inv $inv)
{
    $txs = [];
    $filtered = [];
    $blks = [];

    foreach ($inv->getItems() as $item) {
        if ($item->isBlock()) {
            $blks[] = '';
        } else if ($item->isTx()) {
            $txs[] = '';
        } else if ($item->isFilteredBlock()) {
            $filtered[] = '';
        }
    }
    if (count($blks) > 0 || count($filtered) > 0 ) {
        echo " [blocks: " . count($blks) . ", txs: " . count($txs) . ", filtered: " . count($filtered) . "]\n";
    }
}
$math = BitWasp\Bitcoin\Bitcoin::getMath();

$key = \BitWasp\Bitcoin\Key\Deterministic\HierarchicalKeyFactory::fromEntropy(new Buffer('this random sentence can be used to form a private key trololol123'));
$hd = $key->deriveChild(1);
$publicKey = $hd->getPublicKey();
echo $publicKey->getAddress()->getAddress() . "\n";

$flags = new Flags(BloomFilter::UPDATE_P2PUBKEY_ONLY);
$filter = BloomFilter::create($math, 1, 1, 1, $flags);
$filter->insertData($publicKey->getBuffer());





$loop = React\EventLoop\Factory::create();
$factory = new \BitWasp\Bitcoin\Networking\Factory($loop);
$dns = $factory->getDns();
$peerFactory = $factory->getPeerFactory($dns);
$host = $peerFactory->getAddress('192.168.192.101');
$local = $peerFactory->getAddress('192.168.192.39', 32301);

$peers = $peerFactory->getLocator();
$manager = $peerFactory->getManager($peers);
$manager->on('outbound', function (Peer $peer) use (&$node, $filter) {
    $locatorType = true;
    $peer->filterload($filter);
    $peer->mempool();
    $peer->on('inv', 'decodeInv');
    $peer->on('headers', function ($peer, \BitWasp\Bitcoin\Networking\Messages\Headers $headers) {
        echo count($headers->getHeaders()) . " headers \n";
    });
    $peer->on('inv', function (Peer $peer, \BitWasp\Bitcoin\Networking\Messages\Inv $inv) use (&$node, $locatorType) {
        $filtered = [];
        $items = $inv->getItems();

        foreach ($items as $item) {
            if ($item->isBlock()) {
                $key = $item->getHash()->getHex();
                if (!$node->chain()->index()->height()->contains($key)) {
                    $filtered[] = new Inventory(
                        Inventory::MSG_FILTERED_BLOCK,
                        $item->getHash()
                    );
                }
            } else if ($item->isFilteredBlock()) {
                die('filtered');
            }
        }

        if (count($filtered) > 0){
            $peer->getdata($filtered);
        }

        echo "inv: latest height: " . $node->chain()->currentHeight() . "\n";
    });

    $peer->on('merkleblock', function (Peer $peer, \BitWasp\Bitcoin\Networking\Messages\MerkleBlock $merkle) use ($node, $filter, $locatorType) {
        $filtered = $merkle->getFilteredBlock();
        $header = $filtered->getHeader();
        $heightIndex = $node->chain()->index()->height();
        if (!$heightIndex->contains($header->getPrevBlock())) {
            $peer->getblocks($node->locator($locatorType));
        }

        $node->chain()->process($filtered->getHeader());
    });

    $peer->getblocks($node->locator($locatorType));

});
$redis = new Redis();
$redis->connect('127.0.0.1');

$mkCache = function ($namespace) use ($redis) {
    $cache = new \Doctrine\Common\Cache\RedisCache();
    $cache->setRedis($redis);
    $cache->setNamespace($namespace);
    return $cache;
};

$headerFS = $mkCache('headers');
$heightFS = $mkCache('height');
$hashFS = $mkCache('hash');

$headerchain = new \BitWasp\Bitcoin\Chain\Headerchain(
    $math,
    new \BitWasp\Bitcoin\Block\BlockHeader(
        '1',
        '0000000000000000000000000000000000000000000000000000000000000000',
        '4a5e1e4baab89f3a32518a88c31bc87f618f76673e2cc77ab2127b7afdeda33b',
        1231006505,
        \BitWasp\Buffertools\Buffer::hex('1d00ffff'),
        2083236893
    ),
    new \BitWasp\Bitcoin\Chain\HeaderStorage($headerFS),
    new BlockIndex(
        new BlockHashIndex($hashFS),
        new BlockHeightIndex($heightFS)
    )
);

$node = new \BitWasp\Bitcoin\Networking\Node\Node($local, $headerchain, $manager);
$node->start(1);

$loop->run();
