<?php

namespace OkBloomer\Tests;

use OkBloomer\BloomFilter;
use PHPUnit\Framework\TestCase;

/**
 * @group Base
 * @covers \OkBloomer\BloomFilter
 */
class BloomFilterTest extends TestCase
{
    /**
     * @var \OkBloomer\BloomFilter
     */
    protected $filter;

    /**
     * @before
     */
    protected function setUp() : void
    {
        $this->filter = new BloomFilter(0.001, 3, 1024, BloomFilter::CRC32);
    }

    /**
     * @test
     */
    public function existsOrInsert() : void
    {
        $this->assertFalse($this->filter->existsOrInsert('foo'));

        $this->assertFalse($this->filter->existsOrInsert('bar'));

        $this->assertTrue($this->filter->exists('foo'));
    }

    /**
     * @test
     */
    public function insert() : void
    {
        $this->assertFalse($this->filter->exists('foo'));

        $this->filter->insert('foo');

        $this->assertTrue($this->filter->exists('foo'));
    }

    /**
     * @test
     * @return void
     */
    public function serialize() : void
    {
        $this->filter->insert('example');

        $copy = unserialize(serialize($this->filter));

        $this->assertEquals($this->filter, $copy);

        $this->assertTrue($copy->exists('example'));
        $this->assertFalse($copy->exists('example2'));
    }
}
