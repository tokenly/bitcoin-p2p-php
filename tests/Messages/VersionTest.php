<?php

namespace BitWasp\Bitcoin\Tests\Networking\Messages;

use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Crypto\Random\Random;
use BitWasp\Bitcoin\Networking\Messages\Factory;
use BitWasp\Bitcoin\Networking\Serializer\NetworkMessageSerializer;
use BitWasp\Bitcoin\Networking\Structure\NetworkAddressTimestamp;
use BitWasp\Buffertools\Buffer;
use BitWasp\Bitcoin\Networking\Messages\Version;
use BitWasp\Bitcoin\Networking\Structure\NetworkAddress;
use BitWasp\Bitcoin\Tests\Networking\AbstractTestCase;

class VersionTest extends AbstractTestCase
{
    public function testVersion()
    {
        $factory = new Factory(Bitcoin::getDefaultNetwork(), new Random());
        $v = '60002';
        $services = Buffer::hex('0000000000000001');
        $time = (string)time();
        $recipient = new NetworkAddress(Buffer::hex('1'), '10.0.0.1', '8332');
        $sender = new NetworkAddress(Buffer::hex('1'), '10.0.0.2', '8332');
        $userAgent = new Buffer("/Satoshi:0.7.2/");
        $lastBlock = '212672';

        $version = $factory->version(
            $v,
            $services,
            $time,
            $recipient,
            $sender,
            $userAgent,
            $lastBlock,
            true
        );

        $this->assertEquals('version', $version->getNetworkCommand());
        $this->assertEquals($userAgent, $version->getUserAgent());
        $this->assertEquals($v, $version->getVersion());
        $this->assertEquals($time, $version->getTimestamp());
        $this->assertEquals($sender, $version->getSenderAddress());
        $this->assertEquals($recipient, $version->getRecipientAddress());
        $this->assertEquals($services, $version->getServices());
        $this->assertEquals($lastBlock, $version->getStartHeight());
        $this->assertInternalType('string', $version->getNonce());
        $this->assertTrue($version->getRelay());
        $this->assertTrue($version->hasBlockchain());
    }

    public function testVersionWithTimestampedAddress()
    {
        $factory = new Factory(Bitcoin::getDefaultNetwork(), new Random());
        $v = '60002';
        $services = Buffer::hex('0000000000000001');
        $time = (string)time();
        $recipient = new NetworkAddressTimestamp(1, Buffer::hex('1'), '10.0.0.1', '8332');
        $sender = new NetworkAddressTimestamp(1, Buffer::hex('1'), '10.0.0.2', '8332');
        $userAgent = new Buffer("/Satoshi:0.7.2/");
        $lastBlock = '212672';

        $version = $factory->version(
            $v,
            $services,
            $time,
            $recipient,
            $sender,
            $userAgent,
            $lastBlock,
            true
        );

        // Test that addresses were mutated
        $this->assertInstanceOf('\Bitwasp\Bitcoin\Networking\Structure\NetworkAddress', $version->getRecipientAddress());
        $this->assertInstanceOf('\Bitwasp\Bitcoin\Networking\Structure\NetworkAddress', $version->getSenderAddress());

    }


    public function testVersionWithoutBlockchain()
    {
        $factory = new Factory(Bitcoin::getDefaultNetwork(), new Random());
        $v = '60002';
        $services = Buffer::hex('0000000000000000');
        $time = (string)time();
        $recipient = new NetworkAddress(Buffer::hex('1'), '10.0.0.1', '8332');
        $sender = new NetworkAddress(Buffer::hex('1'), '10.0.0.2', '8332');
        $userAgent = new Buffer("/Satoshi:0.7.2/");
        $lastBlock = '212672';

        $version = $factory->version(
            $v,
            $services,
            $time,
            $recipient,
            $sender,
            $userAgent,
            $lastBlock,
            true
        );

        $this->assertFalse($version->hasBlockchain());
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testVersionFails()
    {
        $v = '60002';
        $services = Buffer::hex('0000000000000001');
        $time = time();
        $recipient = new NetworkAddress(Buffer::hex('1'), '10.0.0.1', '8332');
        $sender = new NetworkAddress(Buffer::hex('1'), '10.0.0.2', '8332');
        $userAgent = new Buffer("/Satoshi:0.7.2/");
        $lastBlock = '212672';
        $random = new Random();
        $nonce = $random->bytes(8)->getInt();
        new Version(
            $v,
            $services,
            $time,
            $recipient,
            $sender,
            $nonce,
            $userAgent,
            $lastBlock,
            1
        );
    }

    public function testNetworkSerializer()
    {
        $v = '60002';
        $services = Buffer::hex('0000000000000001');
        $time = (string)time();
        $recipient = new NetworkAddress(Buffer::hex('0000000000000001'), '10.0.0.1', '8332');
        $sender = new NetworkAddress(Buffer::hex('0000000000000001'), '10.0.0.2', '8332');
        $userAgent = new Buffer("/Satoshi:0.7.2/");
        $lastBlock = '212672';
        $random = new Random();
        $nonce = $random->bytes(8)->getInt();
        $version = new Version(
            $v,
            $services,
            $time,
            $recipient,
            $sender,
            $nonce,
            $userAgent,
            $lastBlock,
            true
        );

        $net = Bitcoin::getDefaultNetwork();
        $serializer = new NetworkMessageSerializer($net);
        $serialized = $version->getNetworkMessage()->getBuffer();
        $parsed = $serializer->parse($serialized)->getPayload();

        $this->assertEquals($version, $parsed);
    }
}
