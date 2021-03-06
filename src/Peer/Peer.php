<?php

namespace BitWasp\Bitcoin\Networking\Peer;

use BitWasp\Bitcoin\Block\BlockInterface;
use BitWasp\Bitcoin\Block\FilteredBlock;
use BitWasp\Bitcoin\Bloom\BloomFilter;
use BitWasp\Bitcoin\Chain\BlockLocator;
use BitWasp\Bitcoin\Networking\Messages\Version;
use BitWasp\Bitcoin\Networking\Messages\Ping;
use BitWasp\Bitcoin\Networking\NetworkMessage;
use BitWasp\Bitcoin\Networking\NetworkSerializable;
use BitWasp\Bitcoin\Networking\Structure\AlertDetail;
use BitWasp\Bitcoin\Networking\Structure\Inventory;
use BitWasp\Bitcoin\Networking\Structure\NetworkAddressInterface;
use BitWasp\Bitcoin\Networking\Structure\NetworkAddressTimestamp;
use BitWasp\Bitcoin\Crypto\EcAdapter\Signature\SignatureInterface;
use BitWasp\Bitcoin\Transaction\TransactionInterface;
use BitWasp\Buffertools\Buffer;
use BitWasp\Buffertools\Parser;
use Evenement\EventEmitter;
use React\EventLoop\LoopInterface;
use React\EventLoop\Timer\Timer;
use React\Promise\Deferred;
use React\Socket\Connection;
use React\SocketClient\Connector;
use React\Stream\Stream;

class Peer extends EventEmitter
{
    const USER_AGENT = "bitcoin-php/v0.1";
    const PROTOCOL_VERSION = "70000";

    /**
     * @var string
     */
    private $buffer = '';

    /**
     * @var NetworkAddressInterface
     */
    private $localAddr;

    /**
     * @var NetworkAddressInterface
     */
    private $remoteAddr;

    /**
     * @var BloomFilter
     */
    private $filter;

    /**
     * @var LoopInterface
     */
    private $loop;

    /**
     * @var \BitWasp\Bitcoin\Networking\Messages\Factory
     */
    private $msgs;

    /**
     * @var Stream
     */
    private $stream;

    /**
     * @var bool
     */
    private $exchangedVersion = false;

    /**
     * @var int
     */
    private $lastPongTime;

    /**
     * @var bool
     */
    private $inbound;

    /**
     * Whether we want this peer to relay tx's to us.
     * @var bool
     */
    private $relayToUs = false;

    /**
     * @var bool
     */
    private $relayToPeer = false;

    private $downloadPeer = false;

    /**
     * @param NetworkAddressInterface $local
     * @param \BitWasp\Bitcoin\Networking\Messages\Factory $msgs
     * @param LoopInterface $loop
     */
    public function __construct(
        NetworkAddressInterface $local,
        \BitWasp\Bitcoin\Networking\Messages\Factory $msgs,
        LoopInterface $loop
    ) {
        $this->localAddr = $local;
        $this->msgs = $msgs;
        $this->loop = $loop;
        $this->lastPongTime = time();
    }

    /**
     * @return bool
     */
    public function isDownloadPeer()
    {
        return $this->downloadPeer;
    }

    /**
     * @param $flag
     */
    public function setDownloadPeerFlag($flag)
    {
        if (!is_bool($flag)) {
            throw new \InvalidArgumentException('Flag must be a bool');
        }

        $this->downloadPeer = $flag;
    }

    /**
     * @return NetworkAddressInterface
     */
    public function getRemoteAddr()
    {
        return $this->remoteAddr;
    }

    /**
     * @return NetworkAddressInterface
     */
    public function getLocalAddr()
    {
        return $this->localAddr;
    }

    /**
     * Set to true by calling requestRelay()
     * @return bool
     */
    public function checkWeRequestedRelay()
    {
        return $this->relayToUs;
    }

    /**
     * Check if peer sent version message requesting relay, or, set a filter.
     * @return bool
     */
    public function checkPeerRequestedRelay()
    {
        return $this->relayToPeer;
    }

    /**
     * Must be called before connect(). This tells the remote node to relay transactions to us.
     */
    public function requestRelay()
    {
        $this->relayToUs = true;
        return $this;
    }

    /**
     * @return bool
     */
    public function hasFilter()
    {
        return $this->filter !== null;
    }

    /**
     * @return BloomFilter
     * @throws \Exception
     */
    public function getFilter()
    {
        if (!$this->hasFilter()) {
            throw new \Exception('No filter set for peer');
        }

        return $this->filter;
    }

    /**
     * @return bool
     */
    public function ready()
    {
        return $this->exchangedVersion;
    }

    /**
     * @param NetworkSerializable $msg
     */
    public function send(NetworkSerializable $msg)
    {
        $net = $msg->getNetworkMessage();
        $this->stream->write($net->getBinary());
        $this->emit('send', [$net]);
    }

    /**
     * Handler for incoming data. Buffers possibly fragmented packets since they arrive sequentially.
     * Before finishing the version exchange, this will only emit Version and VerAck messages.
     */
    private function onData()
    {
        $tmp = $this->buffer;
        $parser = new Parser(new Buffer($tmp));

        try {
            while ($message = $this->msgs->parse($parser)) {
                $tmp = $parser->getBuffer()->slice($parser->getPosition())->getBinary();
                $command = $message->getCommand();
                if ($this->exchangedVersion || ($command == 'version' || $command == 'verack')) {
                    $this->emit('msg', [$this, $message]);
                }
            }
        } catch (\Exception $e) {
            $this->buffer = $tmp;
            // Do nothing - it was probably a fragmented message
        }
    }

    /**
     * Initializes basic peer functionality - used in server and client contexts
     *
     * @return $this
     */
    private function setupConnection()
    {
        $this->stream->on('data', function ($data) {
            $this->buffer .= $data;
            $this->emit('data');
        });

        $this->stream->on('close', function () {
            $this->close();
        });

        $this->on('data', function () {
            $this->onData();
        });

        $this->on('msg', function (Peer $peer, NetworkMessage $msg) {
            $this->emit($msg->getCommand(), [$peer, $msg->getPayload()]);
        });

        return $this;
    }

    /**
     * @param int $timeout
     * @return $this
     */
    public function timeoutWithoutVersion($timeout = 20)
    {
        $this->once('data', function () use ($timeout) {
            $this->loop->addPeriodicTimer($timeout, function (Timer $timer) {
                if (false === $this->exchangedVersion) {
                    $this->intentionalClose();
                }
                $timer->cancel();
            });
        });

        return $this;
    }

    /**
     * @param Connection $connection
     * @return \React\Promise\Promise|\React\Promise\PromiseInterface
     */
    public function inboundConnection(Connection $connection)
    {
        $this->stream = $connection;
        $this->inbound = true;
        $this->setupConnection();
        $deferred = new Deferred();

        $this->on('version', function (Peer $peer, Version $version) {
            $this->remoteAddr = $version->getSenderAddress();
            $this->version();
        });

        $this->on('verack', function () use ($deferred) {
            if (false === $this->exchangedVersion) {
                $this->exchangedVersion = true;
                $this->verack();
                $this->emit('ready', [$this]);
                $deferred->resolve($this);
            }
        });

        return $deferred->promise();
    }
    
    /**
     * @param Connector $connector
     * @param NetworkAddressInterface $remoteAddr
     * @return \React\Promise\Promise|\React\Promise\PromiseInterface
     */
    public function connect(Connector $connector, NetworkAddressInterface $remoteAddr)
    {
        $deferred = new Deferred();
        $this->remoteAddr = $remoteAddr;
        $this->inbound = false;

        $connector
            ->create($this->remoteAddr->getIp(), $this->remoteAddr->getPort())
            ->then(function (Stream $stream) use ($deferred) {
                $this->stream = $stream;
                $this->setupConnection();

                $this->on('version', function () {
                    $this->verack();
                });

                $this->on('verack', function () use ($deferred) {
                    if (false === $this->exchangedVersion) {
                        $this->exchangedVersion = true;
                        $this->emit('ready', [$this]);
                        $deferred->resolve($this);
                    }
                });

                $this->version();

            }, function ($error) use ($deferred) {
                $deferred->reject($error);
            });


        return $deferred->promise();
    }

    /**
     *
     */
    public function intentionalClose()
    {
        $this->emit('intentionaldisconnect', [$this]);
        $this->close();
    }

    /**
     *
     */
    public function close()
    {
        $this->stream->end();
        $this->removeAllListeners();
    }

    /**
     * @return \BitWasp\Bitcoin\Networking\Messages\Version
     */
    public function version()
    {
        $this->send($this->msgs->version(
            self::PROTOCOL_VERSION,
            Buffer::hex('0000000000000001', 8),
            time(),
            $this->remoteAddr,
            $this->localAddr,
            new Buffer(self::USER_AGENT),
            '363709',
            $this->relayToUs
        ));
    }

    /**
     *
     */
    public function verack()
    {
        $this->send($this->msgs->verack());
    }

    /**
     * @param Inventory[] $vInv
     */
    public function inv(array $vInv)
    {
        $this->send($this->msgs->inv($vInv));
    }

    /**
     * @param Inventory[] $vInv
     */
    public function getdata(array $vInv)
    {
        $this->send($this->msgs->getdata($vInv));
    }

    /**
     * @param array $vInv
     */
    public function notfound(array $vInv)
    {
        $this->send($this->msgs->notfound($vInv));
    }

    /**
     * @param NetworkAddressTimestamp[] $vNetAddr
     */
    public function addr(array $vNetAddr)
    {
        $this->send($this->msgs->addr($vNetAddr));
    }

    /**
     *
     */
    public function getaddr()
    {
        $this->send($this->msgs->getaddr());
    }

    /**
     *
     */
    public function ping()
    {
        $this->send($this->msgs->ping());
    }

    /**
     * @param Ping $ping
     */
    public function pong(Ping $ping)
    {
        $this->send($this->msgs->pong($ping));
    }

    /**
     * @param TransactionInterface $tx
     */
    public function tx(TransactionInterface $tx)
    {
        $this->send($this->msgs->tx($tx));
    }

    /**
     * @param BlockLocator $locator
     */
    public function getblocks(BlockLocator $locator)
    {
        $this->send($this->msgs->getblocks(
            self::PROTOCOL_VERSION,
            $locator
        ));
    }

    /**
     * @param BlockLocator $locator
     */
    public function getheaders(BlockLocator $locator)
    {
        $this->send($this->msgs->getheaders(
            self::PROTOCOL_VERSION,
            $locator
        ));
    }

    /**
     * @param BlockInterface $block
     */
    public function block(BlockInterface $block)
    {
        $this->send($this->msgs->block($block));
    }

    /**
     * @param array $vHeaders
     */
    public function headers(array $vHeaders)
    {
        $this->send($this->msgs->headers($vHeaders));
    }

    /**
     * @param AlertDetail $detail
     * @param SignatureInterface $signature
     */
    public function alert(AlertDetail $detail, SignatureInterface $signature)
    {
        $this->send($this->msgs->alert($detail, $signature));
    }

    /**
     * @param Buffer $data
     */
    public function filteradd(Buffer $data)
    {
        $this->send($this->msgs->filteradd($data));
    }

    /**
     * @param BloomFilter $filter
     */
    public function filterload(BloomFilter $filter)
    {
        $this->send($this->msgs->filterload($filter));
    }

    /**
     *
     */
    public function filterclear()
    {
        $this->send($this->msgs->filterclear());
    }

    /**
     * @param FilteredBlock $filtered
     */
    public function merkleblock(FilteredBlock $filtered)
    {
        $this->send($this->msgs->merkleblock($filtered));
    }

    /**
     *
     */
    public function mempool()
    {
        $this->send($this->msgs->mempool());
    }

    /**
     * Issue a Reject message, with a required $msg, $code, and $reason
     *
     * @param Buffer $msg
     * @param int $code
     * @param Buffer $reason
     * @param Buffer $data
     */
    public function reject(Buffer $msg, $code, Buffer $reason, Buffer $data = null)
    {
        $this->send($this->msgs->reject($msg, $code, $reason, $data));
    }
}
