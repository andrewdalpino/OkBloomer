<?php

namespace OkBloomer\Benchmarks;

use OkBloomer\BooleanArray;

class BooleanArrayBench
{
    /**
     * @Subject
     * @Iterations(5)
     * @OutputTimeUnit("seconds", precision=3)
     */
    public function insertBooleanArray() : void
    {
        $booleans = new BooleanArray(65536);

        for ($i = 0; $i < 65536; ++$i) {
            $booleans[$i] = (bool) rand(0, 1);
        }
    }

    /**
     * @Subject
     * @Iterations(5)
     * @OutputTimeUnit("seconds", precision=3)
     */
    public function insertPhpArray() : void
    {
        $booleans = [];

        for ($i = 0; $i < 65536; ++$i) {
            $booleans[$i] = (bool) rand(0, 1);
        }
    }
}
