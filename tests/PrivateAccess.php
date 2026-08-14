<?php

namespace Modules\Sviat\Redis;

/**
 * Сток ще на PHP 8.0, де без setAccessible() рефлексія не пускає до private,
 * а форк уже на 8.5, де сам виклик застарілий і валить прогін.
 */
trait PrivateAccess
{
    /**
     * @param \ReflectionProperty|\ReflectionMethod $reflected
     * @return \ReflectionProperty|\ReflectionMethod
     */
    protected static function accessible($reflected)
    {
        if (PHP_VERSION_ID < 80100) {
            $reflected->setAccessible(true);
        }

        return $reflected;
    }
}
