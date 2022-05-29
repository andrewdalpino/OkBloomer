<?php

namespace OkBloomer;

use OkBloomer\Exceptions\InvalidArgumentException;

use function count;
use function round;
use function max;
use function log;
use function end;

/**
 * Bloom Filter
 *
 * A probabilistic data structure that estimates the prior occurrence of a given item with a maximum false positive rate.
 *
 * References:
 * [1] P. S. Almeida et al. (2007). Scalable Bloom Filters.
 *
 * @category    Data Structures
 * @package     Scienide/OkBloomer
 * @author      Andrew DalPino
 */
class BloomFilter
{
    /**
     * The CRC32b callback function.
     *
     * @var callable(string):int
     */
    public const CRC32 = 'crc32';

    /**
     * The MurmurHash3 callback function.
     *
     * @var callable(string):int
     */
    public const MURMUR3 = [self::class, 'murmur3'];

    /**
     * The FNV1 callback function.
     *
     * @var callable(string):int
     */
    public const FNV1 = [self::class, 'fnv1'];

    /**
     * The maximum size of a layer slice.
     *
     * @var int
     */
    protected const MAX_SLICE_SIZE = 2147483647;

    /**
     * The false positive rate to remain below.
     *
     * @var float
     */
    protected $maxFalsePositiveRate;

    /**
     * The number of hash functions used, i.e. the number of slices per layer.
     *
     * @var int
     */
    protected int $numHashes;

    /**
     * The size of each layer of the filter in bits.
     *
     * @var int
     */
    protected $layerSize;

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
     * The 32-bit MurmurHash3 hashing function.
     *
     * @param string $token
     * @return int
     */
    public static function murmur3(string $token) : int
    {
        return intval(hash('murmur3a', $token), 16);
    }

    /**
     * The 32-bit FNV1a hashing function.
     *
     * @param string $token
     * @return int
     */
    public static function fnv1(string $token) : int
    {
        return intval(hash('fnv1a32', $token), 16);
    }

    /**
     * @param float $maxFalsePositiveRate
     * @param int|null $numHashes
     * @param int $layerSize
     * @param callable(string):int|null $hashFn
     * @throws \OkBloomer\Exceptions\InvalidArgumentException
     */
    public function __construct(
        float $maxFalsePositiveRate = 0.01,
        ?int $numHashes = 4,
        int $layerSize = 32000000,
        ?callable $hashFn = null
    ) {
        if ($maxFalsePositiveRate < 0.0 or $maxFalsePositiveRate > 1.0) {
            throw new InvalidArgumentException('Max false positive rate'
                . "  must be between 0 and 1, $maxFalsePositiveRate given.");
        }

        if (isset($numHashes) and $numHashes < 1) {
            throw new InvalidArgumentException('Number of hashes'
                . " must be greater than 1, $numHashes given.");
        }

        if ($numHashes === null) {
            $numHashes = max(1, (int) log(1.0 / $maxFalsePositiveRate, 2));
        }

        if ($layerSize < $numHashes) {
            throw new InvalidArgumentException('Layer size must be'
                . " greater than $numHashes, $layerSize given.");
        }

        $sliceSize = (int) round($layerSize / $numHashes);

        if ($sliceSize > self::MAX_SLICE_SIZE) {
            throw new InvalidArgumentException('Layer slice size'
                . ' must be less than ' . self::MAX_SLICE_SIZE
                . ", $sliceSize given.");
        }

        $this->maxFalsePositiveRate = $maxFalsePositiveRate;
        $this->numHashes = $numHashes;
        $this->layerSize = $layerSize;
        $this->sliceSize = $sliceSize;
        $this->layers = [new BooleanArray($layerSize)];
        $this->m = $layerSize;
        $this->hashFn = $hashFn ?? self::CRC32;
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
     * Return the number of hash functions used in the filter.
     *
     * @return int
     */
    public function numHashes() : int
    {
        return $this->numHashes;
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
     * Return the proportion of bits that are set.
     *
     * @return float
     */
    public function utilization() : float
    {
        return $this->n / $this->m;
    }

    /**
     * Return the proportion of bits that are not set.
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
        return $this->utilization() ** $this->numHashes;
    }

    /**
     * Insert an element into the filter.
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
     * Return an array of offsets from a given token.
     *
     * @param string $token
     * @return list<int>
     */
    protected function hash(string $token) : array
    {
        $offsets = [];

        for ($i = 1; $i <= $this->numHashes; ++$i) {
            $offset = call_user_func($this->hashFn, "{$i}{$token}");

            $offset %= $this->sliceSize;
            $offset *= $i;

            $offsets[] = (int) $offset;
        }

        return $offsets;
    }
}
