<?php

declare(strict_types=1);

namespace App\Test;

use App\Libraries\Formulas;
use PHPUnit\Framework\TestCase;

/**
 * @covers App\Libraries\Formulas
 */
class FormulasTest extends TestCase
{
    public function testPhalanxRangeReturnsZeroForLevelZero(): void
    {
        $this->assertSame(0, Formulas::phalanxRange(0));
    }

    public function testPhalanxRangeReturnsOneForLevelOne(): void
    {
        $this->assertSame(1, Formulas::phalanxRange(1));
    }

    public function testPhalanxRangeReturnsLevelSquaredMinusOne(): void
    {
        $this->assertSame(3, Formulas::phalanxRange(2));  // 2^2 - 1 = 3
        $this->assertSame(8, Formulas::phalanxRange(3));  // 3^2 - 1 = 8
        $this->assertSame(24, Formulas::phalanxRange(5)); // 5^2 - 1 = 24
    }

    public function testMissileRange(): void
    {
        $this->assertSame(4, Formulas::missileRange(1));   // 1*5 - 1 = 4
        $this->assertSame(9, Formulas::missileRange(2));   // 2*5 - 1 = 9
        $this->assertSame(49, Formulas::missileRange(10)); // 10*5 - 1 = 49
    }

    public function testGetMoonDestructionChance(): void
    {
        // With a large moon and few death stars, chance should be low
        $chance = Formulas::getMoonDestructionChance(5000, 1);
        $this->assertGreaterThan(0, $chance);
        $this->assertLessThanOrEqual(100, $chance);

        // More death stars = higher chance
        $lowChance = Formulas::getMoonDestructionChance(5000, 1);
        $highChance = Formulas::getMoonDestructionChance(5000, 10);
        $this->assertGreaterThan($lowChance, $highChance);
    }

    public function testGetMoonDestructionChanceSmallMoon(): void
    {
        // Small moons are easier to destroy
        $chance = Formulas::getMoonDestructionChance(1000, 1);
        $this->assertGreaterThan(0, $chance);
    }

    public function testGetDeathStarsDestructionChance(): void
    {
        $chance = Formulas::getDeathStarsDestructionChance(5000);
        $this->assertEqualsWithDelta(sqrt(5000) / 2, $chance, 1.0); // Allow 1.0 delta due to rounding
    }

    public function testGetIonTechnologyBonus(): void
    {
        $this->assertSame(0.0, Formulas::getIonTechnologyBonus(0));
        $this->assertSame(0.04, Formulas::getIonTechnologyBonus(1));
        $this->assertSame(0.20, Formulas::getIonTechnologyBonus(5));
    }

    public function testGetPlasmaTechnologyBonusForMetal(): void
    {
        $bonus = Formulas::getPlasmaTechnologyBonus(10, 'metal');
        $this->assertSame(0.10, $bonus); // 10 * 0.01
    }

    public function testGetPlasmaTechnologyBonusForCrystal(): void
    {
        $bonus = Formulas::getPlasmaTechnologyBonus(10, 'crystal');
        $this->assertEqualsWithDelta(0.066, $bonus, 0.001); // 10 * 0.0066
    }

    public function testGetPlasmaTechnologyBonusForDeuterium(): void
    {
        $bonus = Formulas::getPlasmaTechnologyBonus(10, 'deuterium');
        $this->assertEqualsWithDelta(0.033, $bonus, 0.001); // 10 * 0.0033
    }

    public function testGetPlasmaTechnologyBonusZero(): void
    {
        $bonus = Formulas::getPlasmaTechnologyBonus(0, 'metal');
        $this->assertSame(0.0, $bonus);
    }

    public function testGetDevelopmentCost(): void
    {
        // price * factor^level
        $cost = Formulas::getDevelopmentCost(100, 2.0, 3);
        $this->assertSame(800.0, $cost); // 100 * 2^3 = 800
    }

    public function testGetDevelopmentCostLevelZero(): void
    {
        $cost = Formulas::getDevelopmentCost(100, 2.0, 0);
        $this->assertSame(100.0, $cost); // 100 * 2^0 = 100
    }

    public function testSetPlanetImageReturnsString(): void
    {
        $image = Formulas::setPlanetImage(1, 1);
        $this->assertIsString($image);
        $this->assertNotEmpty($image);
    }

    public function testSetPlanetTempReturnsArray(): void
    {
        $temp = Formulas::setPlanetTemp(1);
        $this->assertIsArray($temp);
        $this->assertArrayHasKey('min', $temp);
        $this->assertArrayHasKey('max', $temp);
    }

    public function testSetPlanetTempVariesByPosition(): void
    {
        $temp1 = Formulas::setPlanetTemp(1);
        $temp15 = Formulas::setPlanetTemp(15);
        // Inner planets are hotter
        $this->assertGreaterThan($temp15['max'], $temp1['max']);
    }
}
