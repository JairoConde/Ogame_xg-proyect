<?php

declare(strict_types=1);

namespace App\Test;

use App\Libraries\BattleEngine\Utils\Gauss;
use App\Libraries\BattleEngine\Utils\GeometricDistribution;
use App\Libraries\BattleEngine\Utils\Math;
use App\Libraries\BattleEngine\Utils\Number;
use Exception;
use PHPUnit\Framework\TestCase;

/**
 * @covers App\Libraries\BattleEngine\Utils\Gauss
 * @covers App\Libraries\BattleEngine\Utils\GeometricDistribution
 * @covers App\Libraries\BattleEngine\Utils\Math
 * @covers App\Libraries\BattleEngine\Utils\Number
 */
class BattleEngineUtilsTest extends TestCase
{
    // ========================================================================
    //  Gauss tests
    // ========================================================================

    /**
     * @covers App\Libraries\BattleEngine\Utils\Gauss::getNext
     */
    public function testGaussGetNextReturnsFloat(): void
    {
        $result = Gauss::getNext();
        $this->assertIsFloat($result);
    }

    /**
     * @covers App\Libraries\BattleEngine\Utils\Gauss::getNext
     */
    public function testGaussGetNextProducesDifferentValues(): void
    {
        $results = [];
        for ($i = 0; $i < 10; $i++) {
            $results[] = Gauss::getNext();
        }
        // Remove duplicates; there should be more than 1 unique value
        $unique = array_unique($results);
        $this->assertGreaterThan(1, count($unique));
    }

    /**
     * @covers App\Libraries\BattleEngine\Utils\Gauss::getNextMs
     */
    public function testGaussGetNextMsUsesMeanAndStdDev(): void
    {
        $mean = 100;
        $stdDev = 10;

        $values = [];
        for ($i = 0; $i < 100; $i++) {
            $values[] = Gauss::getNextMs($mean, $stdDev);
        }

        $avg = array_sum($values) / count($values);
        // With 100 samples the average should be roughly near 100
        $this->assertGreaterThan(70, $avg);
        $this->assertLessThan(130, $avg);
    }

    /**
     * @covers App\Libraries\BattleEngine\Utils\Gauss::getNextMsBetween
     */
    public function testGaussGetNextMsBetweenRespectsBounds(): void
    {
        $min = 5;
        $max = 15;
        $mean = 10;
        $stdDev = 2;

        for ($i = 0; $i < 50; $i++) {
            $value = Gauss::getNextMsBetween($mean, $stdDev, $min, $max);
            $this->assertGreaterThanOrEqual($min, $value);
            $this->assertLessThanOrEqual($max, $value);
        }
    }

    /**
     * @covers App\Libraries\BattleEngine\Utils\Gauss::getNextMsBetween
     */
    public function testGaussGetNextMsBetweenThrowsWhenMeanOutOfBounds(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Mean is not bounded by min and max');

        Gauss::getNextMsBetween(50, 5, 10, 20);
    }

    /**
     * @covers App\Libraries\BattleEngine\Utils\Gauss::getNextMsBetween
     */
    public function testGaussGetNextMsBetweenMeanBelowMinBound(): void
    {
        $this->expectException(Exception::class);

        Gauss::getNextMsBetween(5, 2, 10, 20);
    }

    // ========================================================================
    //  GeometricDistribution tests
    // ========================================================================

    /**
     * @covers App\Libraries\BattleEngine\Utils\GeometricDistribution::getProbabilityFromMean
     */
    public function testGeometricGetProbabilityFromMean(): void
    {
        // Mean = 10 => probability = 1/10 = 0.1
        $this->assertSame(0.1, GeometricDistribution::getProbabilityFromMean(10));
        // Mean = 4 => probability = 1/4 = 0.25
        $this->assertSame(0.25, GeometricDistribution::getProbabilityFromMean(4));
    }

    /**
     * @covers App\Libraries\BattleEngine\Utils\GeometricDistribution::getProbabilityFromMean
     */
    public function testGeometricGetProbabilityFromMeanMeanLessThanOrEqualToOne(): void
    {
        // Mean <= 1 => returns 1 (int)
        $this->assertSame(1, GeometricDistribution::getProbabilityFromMean(1));
        $this->assertSame(1, GeometricDistribution::getProbabilityFromMean(0));
        $this->assertSame(1, GeometricDistribution::getProbabilityFromMean(-5));
    }

    /**
     * @covers App\Libraries\BattleEngine\Utils\GeometricDistribution::getMeanFromProbability
     */
    public function testGeometricGetMeanFromProbability(): void
    {
        // Probability = 0.5 => mean = 1/0.5 = 2
        $this->assertSame(2.0, GeometricDistribution::getMeanFromProbability(0.5));
        // Probability = 0.25 => mean = 1/0.25 = 4
        $this->assertSame(4.0, GeometricDistribution::getMeanFromProbability(0.25));
    }

    /**
     * @covers App\Libraries\BattleEngine\Utils\GeometricDistribution::getMeanFromProbability
     */
    public function testGeometricGetMeanFromProbabilityZeroProbability(): void
    {
        // Probability = 0 => returns INF
        $this->assertSame(INF, GeometricDistribution::getMeanFromProbability(0));
    }

    /**
     * @covers App\Libraries\BattleEngine\Utils\GeometricDistribution::getVarianceFromProbability
     */
    public function testGeometricGetVarianceFromProbability(): void
    {
        // Probability = 0.5 => (1 - 0.5) / (0.5^2) = 0.5 / 0.25 = 2
        $this->assertSame(2.0, GeometricDistribution::getVarianceFromProbability(0.5));
        // Probability = 0.25 => (1 - 0.25) / (0.25^2) = 0.75 / 0.0625 = 12
        $this->assertSame(12.0, GeometricDistribution::getVarianceFromProbability(0.25));
    }

    /**
     * @covers App\Libraries\BattleEngine\Utils\GeometricDistribution::getVarianceFromProbability
     */
    public function testGeometricGetVarianceFromProbabilityZeroProbability(): void
    {
        $this->assertSame(INF, GeometricDistribution::getVarianceFromProbability(0));
    }

    /**
     * @covers App\Libraries\BattleEngine\Utils\GeometricDistribution::getStandardDeviationFromProbability
     */
    public function testGeometricGetStandardDeviationFromProbability(): void
    {
        // Variance for p=0.5 is 2 => sqrt(2) ≈ 1.414
        $stdDev = GeometricDistribution::getStandardDeviationFromProbability(0.5);
        $this->assertEqualsWithDelta(sqrt(2.0), $stdDev, 1e-10);
    }

    /**
     * @covers App\Libraries\BattleEngine\Utils\GeometricDistribution::getStandardDeviationFromProbability
     */
    public function testGeometricGetStandardDeviationFromProbabilityZeroProbability(): void
    {
        $this->assertSame(INF, GeometricDistribution::getStandardDeviationFromProbability(0));
    }

    // ========================================================================
    //  Number tests
    // ========================================================================

    /**
     * @covers App\Libraries\BattleEngine\Utils\Number::__construct
     */
    public function testNumberConstructorSetsProperties(): void
    {
        $num = new Number(10, 3);
        $this->assertSame(10, $num->result);
        $this->assertSame(3, $num->rest);
    }

    /**
     * @covers App\Libraries\BattleEngine\Utils\Number::__construct
     */
    public function testNumberDefaultRestIsZero(): void
    {
        $num = new Number(42);
        $this->assertSame(42, $num->result);
        $this->assertSame(0, $num->rest);
    }

    /**
     * @covers App\Libraries\BattleEngine\Utils\Number::__toString
     */
    public function testNumberToString(): void
    {
        $num = new Number(5, 2);
        $this->assertSame('result=5;rest=2;', (string) $num);
    }

    /**
     * @covers App\Libraries\BattleEngine\Utils\Number::__toString
     */
    public function testNumberToStringWithZeroRest(): void
    {
        $num = new Number(99);
        $this->assertSame('result=99;rest=0;', (string) $num);
    }

    // ========================================================================
    //  Math tests
    // ========================================================================

    /**
     * @covers App\Libraries\BattleEngine\Utils\Math::divide
     */
    public function testMathDivideBasic(): void
    {
        $num = new Number(10);
        $den = new Number(3);

        $result = Math::divide($num, $den);

        $this->assertInstanceOf(Number::class, $result);
        $this->assertEqualsWithDelta(10 / 3, $result->result, 1e-10);
        $this->assertSame(0, $result->rest);
    }

    /**
     * @covers App\Libraries\BattleEngine\Utils\Math::divide
     */
    public function testMathDivideReal(): void
    {
        $num = new Number(10);
        $den = new Number(3);

        $result = Math::divide($num, $den, true);

        $this->assertInstanceOf(Number::class, $result);
        $this->assertEquals(3, $result->result); // floor(10/3) returns float in PHP 8
        $this->assertSame(1, $result->rest);   // 10 % 3
    }

    /**
     * @covers App\Libraries\BattleEngine\Utils\Math::divide
     */
    public function testMathDivideRealWithExactDivision(): void
    {
        $num = new Number(12);
        $den = new Number(3);

        $result = Math::divide($num, $den, true);

        $this->assertEquals(4, $result->result);
        $this->assertSame(0, $result->rest);
    }

    /**
     * @covers App\Libraries\BattleEngine\Utils\Math::divide
     */
    public function testMathDivideThrowsOnZeroDenominatorReal(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('denum is zero');

        Math::divide(new Number(5), new Number(0), true);
    }

    /**
     * @covers App\Libraries\BattleEngine\Utils\Math::multiple
     */
    public function testMathMultipleBasic(): void
    {
        $first = new Number(4);
        $second = new Number(5);

        $result = Math::multiple($first, $second);

        $this->assertInstanceOf(Number::class, $result);
        $this->assertSame(20, $result->result);
        $this->assertSame(0, $result->rest);
    }

    /**
     * @covers App\Libraries\BattleEngine\Utils\Math::multiple
     */
    public function testMathMultipleReal(): void
    {
        $first = new Number(3.5);
        $second = new Number(2);

        $result = Math::multiple($first, $second, true);

        $this->assertInstanceOf(Number::class, $result);
        $this->assertEquals(7, $result->result); // floor(7.0) returns float in PHP 8
        $this->assertEqualsWithDelta(0.0, $result->rest, 1e-10);
    }

    /**
     * @covers App\Libraries\BattleEngine\Utils\Math::multiple
     */
    public function testMathMultipleRealWithDecimalResult(): void
    {
        $first = new Number(3.3);
        $second = new Number(3);

        $result = Math::multiple($first, $second, true);

        $this->assertEquals(9, $result->result); // floor(9.9) returns float in PHP 8
        $this->assertEqualsWithDelta(0.9, $result->rest, 1e-10);
    }

    /**
     * @covers App\Libraries\BattleEngine\Utils\Math::multiple
     */
    public function testMathMultipleWithZero(): void
    {
        $result = Math::multiple(new Number(0), new Number(42));
        $this->assertSame(0, $result->result);
    }

    /**
     * @covers App\Libraries\BattleEngine\Utils\Math::heaviside
     */
    public function testHeavisideXGreaterThanOrEqualToY(): void
    {
        $this->assertSame(1, Math::heaviside(5, 3));
        $this->assertSame(1, Math::heaviside(3, 3));
    }

    /**
     * @covers App\Libraries\BattleEngine\Utils\Math::heaviside
     */
    public function testHeavisideXLessThanY(): void
    {
        $this->assertSame(0, Math::heaviside(2, 5));
    }

    /**
     * @covers App\Libraries\BattleEngine\Utils\Math::heaviside
     */
    public function testHeavisideWithNegativeValues(): void
    {
        $this->assertSame(1, Math::heaviside(-1, -2));
        $this->assertSame(0, Math::heaviside(-3, -1));
    }

    /**
     * @covers App\Libraries\BattleEngine\Utils\Math::rest
     */
    public function testRestBasic(): void
    {
        // 10 % 3 = 1
        $result = Math::rest(10, 3);
        $this->assertSame(1, $result);
    }

    /**
     * @covers App\Libraries\BattleEngine\Utils\Math::rest
     */
    public function testRestExactDivision(): void
    {
        $result = Math::rest(12, 3);
        $this->assertSame(0, $result);
    }

    /**
     * @covers App\Libraries\BattleEngine\Utils\Math::rest
     */
    public function testRestWithDivisorLessThanOne(): void
    {
        // Divisor < 1: both are scaled up by 10 until divisor >= 1
        // 12 % 0.5 => scaled: 12 % 5 (wait, 0.5*10=5, 12*10=120 => 120%5=0 ... actually
        // The code does: while divisor < 1 { divisor *= 10; dividend *= 10 }
        // So 10 % 0.3 → scaled to 100 % 3 = 1
        $result = Math::rest(10, 0.3);
        $this->assertSame(1, $result);
    }

    /**
     * @covers App\Libraries\BattleEngine\Utils\Math::rest
     */
    public function testRestNotReal(): void
    {
        $result = Math::rest(10.5, 3, false);
        // The $real = false branch: $decimal = (int)$dividendo - $dividendo; return $divisore % $dividendo + $decimal;
        // (int)10.5 - 10.5 = -0.5; 3 % 10.5 + (-0.5) = 3 - 0.5 = 2.5 via PHP casting
        $this->assertEqualsWithDelta(2.5, $result, 1e-10);
    }

    /**
     * @covers App\Libraries\BattleEngine\Utils\Math::tryEvent
     */
    public function testTryEventWithValidCallback(): void
    {
        $callback = function ($param) {
            return "called with: $param";
        };

        $result = Math::tryEvent(0, $callback, 'test');

        // 0% probability should never trigger
        $this->assertFalse($result);
    }

    /**
     * @covers App\Libraries\BattleEngine\Utils\Math::tryEvent
     */
    public function testTryEventThrowsOnInvalidCallback(): void
    {
        $this->expectException(Exception::class);

        Math::tryEvent(50, 'not_a_callable', 'param');
    }

    /**
     * @covers App\Libraries\BattleEngine\Utils\Math::recursive_sum
     */
    public function testRecursiveSumFlatArray(): void
    {
        $this->assertSame(15, Math::recursive_sum([1, 2, 3, 4, 5]));
    }

    /**
     * @covers App\Libraries\BattleEngine\Utils\Math::recursive_sum
     */
    public function testRecursiveSumNestedArray(): void
    {
        $this->assertSame(21, Math::recursive_sum([1, [2, 3], [4, [5, 6]]]));
    }

    /**
     * @covers App\Libraries\BattleEngine\Utils\Math::recursive_sum
     */
    public function testRecursiveSumEmptyArray(): void
    {
        $this->assertSame(0, Math::recursive_sum([]));
    }

    /**
     * @covers App\Libraries\BattleEngine\Utils\Math::recursive_sum
     */
    public function testRecursiveSumSingleElement(): void
    {
        $this->assertSame(42, Math::recursive_sum([42]));
    }
}
