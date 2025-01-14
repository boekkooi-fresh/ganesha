<?php
namespace Ackintosh\Ganesha;

use Ackintosh\Ganesha\Storage\Adapter\Memcached;
use Ackintosh\Ganesha\Strategy\Rate;

class RateTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @test
     * @expectedException \LogicException
     */
    public function validateThrowsExceptionWhenTheRequiredParamsIsMissing()
    {
        Rate::validate([]);
    }

    /**
     * @test
     * @expectedException \InvalidArgumentException
     */
    public function validateThrowsExceptionWhenTheAdapterDoesntSupportCountStrategy()
    {
        $adapter = $this->getMockBuilder(Memcached::class)
            ->setConstructorArgs([new \Memcached()])
            ->getMock();
        $adapter->method('supportRateStrategy')
            ->willReturn(false);

        $params = [
            'adapter' => $adapter,
            'failureRateThreshold' => 10,
            'intervalToHalfOpen' => 10,
            'minimumRequests' => 10,
            'timeWindow' => 10,
        ];

        Rate::validate($params);
    }
}
