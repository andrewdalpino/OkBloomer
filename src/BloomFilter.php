<?php

namespace OkBloomer;

use OkBloomer\Exceptions\InvalidArgumentException;

use function count;
use function call_user_func;
use function round;
use function max;
use function log;
use function end;

/**
 * Bloom Filter
 *
 * A probabilistic data structure that estimates the prior occurrence of a value with a maximum false positive rate.
 *
 * References:
 * [1] P. S. Almeida et al. (2007). Scalable Bloom Filters.
 *
 * @category    Data Structures
 * @package     andrewdalpino/OkBloomer
 */
class BloomFilter
{
    /**
     * The default hash function.
     *
     * @var callable(string):int
     */
    public const DEFAULT_HASH_FN = 'crc32';

    /**
     * The maximum number of bits of the hash function to use per slice.
     *
     * @var int
     */
    protected const MAX_BITS_PER_SLICE = 32;

    /**
     * The maximum size of a layer slice.
     *
     * @var int
     */
    protected const MAX_SLICE_SIZE = 2 ** self::MAX_BITS_PER_SLICE;

    /**
     * The false positive rate to remain below.
     *
     * @var float
     */
    protected $maxFalsePositiveRate;

    /**
     * The size of each layer of the filter in bits.
     *
     * @var int
     */
    protected $layerSize;

    /**
     * The number of hash functions used, i.e. the number of slices per layer.
     *
     * @var int
     */
    protected int $numSlices;

    /**
     * The size of each slice of each layer in bits.
     *
     * @var int
     */
    protected $sliceSize;

    /**
     * The layers of the filter.
     *
     * @var list<\OkBloomer\BooleanArray>
     */
    protected array $layers;

    /**
     * The size of the filter in bits.
     *
     * @var int
     */
    protected int $m;

    /**
     * The hash function that accepts a string token and returns an integer.
     *
     * @var callable(string):int
     */
    protected $hashFn;

    /**
     * The number of items in the filter.
     *
     * @var int
     */
    protected int $n = 0;

    /**
     * @param float $maxFalsePositiveRate
     * @param int|null $numSlices
     * @param int $layerSize
     * @param callable(string):int|null $hashFn
     * @throws \OkBloomer\Exceptions\InvalidArgumentException
     */
    public function __construct(
        float $maxFalsePositiveRate = 0.01,
        ?int $numSlices = 4,
        int $layerSize = 32000000,
        ?callable $hashFn = null
    ) {
        if ($maxFalsePositiveRate < 0.0 or $maxFalsePositiveRate > 1.0) {
            throw new InvalidArgumentException('Max false positive rate'
                . "  must be between 0 and 1, $maxFalsePositiveRate given.");
        }

        if (isset($numSlices) and $numSlices < 1) {
            throw new InvalidArgumentException('Number of slices'
                . " must be greater than 1, $numSlices given.");
        }

        if ($numSlices === null) {
            $numSlices = max(1, (int) log(1.0 / $maxFalsePositiveRate, 2));
        }

        if ($numSlices > $layerSize) {
            throw new InvalidArgumentException('Layer size must be'
                . " greater than $numSlices, $layerSize given.");
        }

        $sliceSize = (int) round($layerSize / $numSlices);

        if ($sliceSize > self::MAX_SLICE_SIZE) {
            throw new InvalidArgumentException('Slice size must be less'
                . ' than ' . self::MAX_SLICE_SIZE . ", $sliceSize given.");
        }

        $this->maxFalsePositiveRate = $maxFalsePositiveRate;
        $this->layerSize = $layerSize;
        $this->numSlices = $numSlices;
        $this->sliceSize = $sliceSize;
        $this->layers = [new BooleanArray($layerSize)];
        $this->m = $layerSize;
        $this->hashFn = $hashFn ?? self::DEFAULT_HASH_FN;
    }

    /**
     * Return the maximum false positive rate of the filter.
     *
     * @return float
     */
    public function maxFalsePositiveRate() : float
    {
        return $this->maxFalsePositiveRate;
    }

    /**
     * Return the size of each layer of the filter.
     *
     * @return int
     */
    public function layerSize() : int
    {
        return $this->layerSize;
    }

    /**
     * Return the number of hash functions used in the filter.
     *
     * @return int
     */
    public function numSlices() : int
    {
        return $this->numSlices;
    }

    /**
     * Return the size of a slice of a layer in bits.
     *
     * @return int
     */
    public function sliceSize() : int
    {
        return $this->sliceSize;
    }

    /**
     * Return the number of layers in the filter.
     *
     * @return int
     */
    public function numLayers() : int
    {
        return count($this->layers);
    }

    /**
     * Return the size of the Bloom filter in bits.
     *
     * @return int
     */
    public function size() : int
    {
        return $this->m;
    }

    /**
     * Return the number of bits that are set in the filter.
     *
     * @return int
     */
    public function n() : int
    {
        return $this->n;
    }

    /**
     * Return the proportion of the filter that is utilized.
     *
     * @return float
     */
    public function utilization() : float
    {
        return $this->n / $this->m;
    }

    /**
     * Return the proportion of filter that is free.
     *
     * @return float
     */
    public function capacity() : float
    {
        return 1.0 - $this->utilization();
    }

    /**
     * Return the probability of a recording a false positive.
     *
     * @return float
     */
    public function falsePositiveRate() : float
    {
        return $this->utilization() ** $this->numSlices;
    }

    /**
     * Embed an element into the filter.
     *
     * @param string $token
     */
    public function insert(string $token) : void
    {
        $offsets = $this->hash($token);

        /** @var \OkBloomer\BooleanArray $layer */
        $layer = end($this->layers);

        $changed = false;

        foreach ($offsets as $offset) {
            if (!$layer[$offset]) {
                $layer[$offset] = true;

                ++$this->n;

                $changed = true;
            }
        }

        if ($changed and $this->falsePositiveRate() > $this->maxFalsePositiveRate) {
            $this->addLayer();
        }
    }

    /**
     * Does a token exist in the filter? If so, return true or insert and return false.
     *
     * @param string $token
     * @return bool
     */
    public function existsOrInsert(string $token) : bool
    {
        $offsets = $this->hash($token);

        $q = count($this->layers) - 1;

        for ($i = 0; $i < $q; ++$i) {
            $layer = $this->layers[$i];

            foreach ($offsets as $offset) {
                if (!$layer[$offset]) {
                    continue 2;
                }
            }

            return true;
        }

        /** @var \OkBloomer\BooleanArray $layer */
        $layer = end($this->layers);

        $exists = true;

        foreach ($offsets as $offset) {
            if (!$layer[$offset]) {
                $layer[$offset] = true;

                ++$this->n;

                $exists = false;
            }
        }

        if (!$exists and $this->falsePositiveRate() > $this->maxFalsePositiveRate) {
            $this->addLayer();
        }

        return $exists;
    }

    /**
     * Does a token exist in the filter?
     *
     * @param string $token
     * @return bool
     */
    public function exists(string $token) : bool
    {
        $offsets = $this->hash($token);

        foreach ($this->layers as $layer) {
            foreach ($offsets as $offset) {
                if (!$layer[$offset]) {
                    continue 2;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * Add a layer to the filter.
     */
    protected function addLayer() : void
    {
        $this->layers[] = new BooleanArray($this->layerSize);

        $this->m += $this->layerSize;
    }

    /**
     * Return an array of hash offsets from a given token.
     *
     * @param string $token
     * @return list<int>
     */
    protected function hash(string $token) : array
    {
        $offsets = [];

        for ($i = 1; $i <= $this->numSlices; ++$i) {
            $offset = call_user_func($this->hashFn, "{$i}{$token}");

            $offset %= $this->sliceSize;
            $offset *= $i;

            $offsets[] = (int) $offset;
        }

        return $offsets;
    }
}
