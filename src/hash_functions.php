<?php

namespace OkBloomer\HashFunctions
{
    /**
     * The 32-bit MurmurHash3 hashing function.
     *
     * @param string $token
     * @return int
     */
    function murmur3(string $token) : int
    {
        return intval(hash('murmur3a', $token), 16);
    }

    /**
     * The 32-bit FNV1a hashing function.
     *
     * @param string $token
     * @return int
     */
    function fnv1(string $token) : int
    {
        return intval(hash('fnv1a32', $token), 16);
    }
}
