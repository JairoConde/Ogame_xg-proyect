<?php

declare(strict_types=1);

namespace App\Test;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing — BattleConstants defines global constants; we verify their values exist.
 */
class BattleConstantsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../app/Libraries/BattleEngine/Constants/BattleConstants.php';
    }

    public function testBattleWinConstant(): void
    {
        $this->assertSame(1, BATTLE_WIN);
    }

    public function testBattleLoseConstant(): void
    {
        $this->assertSame(-1, BATTLE_LOSE);
    }

    public function testBattleDrawConstant(): void
    {
        $this->assertSame(0, BATTLE_DRAW);
    }

    public function testShieldCellsConstant(): void
    {
        $this->assertSame(100, SHIELD_CELLS);
    }

    public function testUseBiexplosionSystemConstant(): void
    {
        $this->assertTrue(USE_BIEXPLOSION_SYSTEM);
    }

    public function testProbToRealMagicConstant(): void
    {
        $this->assertSame(2, PROB_TO_REAL_MAGIC);
    }

    public function testEpsilonConstant(): void
    {
        $this->assertSame(1.2e-6, EPSILON);
    }

    public function testRoundsConstant(): void
    {
        $this->assertSame(6, ROUNDS);
    }

    public function testShieldsTechIncrementFactor(): void
    {
        $this->assertSame(0.1, SHIELDS_TECH_INCREMENT_FACTOR);
    }

    public function testArmourTechIncrementFactor(): void
    {
        $this->assertSame(0.1, ARMOUR_TECH_INCREMENT_FACTOR);
    }

    public function testWeaponsTechIncrementFactor(): void
    {
        $this->assertSame(0.1, WEAPONS_TECH_INCREMENT_FACTOR);
    }

    public function testCostToArmourConstant(): void
    {
        $this->assertSame(0.1, COST_TO_ARMOUR);
    }

    public function testMinProbToExplodeConstant(): void
    {
        $this->assertSame(0.3, MIN_PROB_TO_EXPLODE);
    }

    public function testDefenseRepairProbConstant(): void
    {
        $this->assertSame(0.7, DEFENSE_REPAIR_PROB);
    }

    public function testShipRepairProbConstant(): void
    {
        $this->assertSame(0, SHIP_REPAIR_PROB);
    }

    public function testUseHitshipLimitationConstant(): void
    {
        $this->assertTrue(USE_HITSHIP_LIMITATION);
    }

    public function testUseExplodedLimitationConstant(): void
    {
        $this->assertTrue(USE_EXPLODED_LIMITATION);
    }

    public function testUseRfConstant(): void
    {
        $this->assertTrue(USE_RF);
    }

    public function testUseRandomicRfConstant(): void
    {
        $this->assertTrue(USE_RANDOMIC_RF);
    }

    public function testMaxRfBuffConstant(): void
    {
        $this->assertSame(0.2, MAX_RF_BUFF);
    }

    public function testMaxRfNerfConstant(): void
    {
        $this->assertSame(0.2, MAX_RF_NERF);
    }

    public function testOnlyFirstAndLastRoundConstant(): void
    {
        $this->assertFalse(ONLY_FIRST_AND_LAST_ROUND);
    }

    public function testRepairedDoDebrisConstant(): void
    {
        $this->assertTrue(REPAIRED_DO_DEBRIS);
    }

    public function testShipDebrisFactorConstant(): void
    {
        $this->assertSame(0.3, SHIP_DEBRIS_FACTOR);
    }

    public function testDefenseDebrisFactorConstant(): void
    {
        $this->assertSame(0.3, DEFENSE_DEBRIS_FACTOR);
    }

    public function testPointUnitConstant(): void
    {
        $this->assertSame(1000, POINT_UNIT);
    }

    public function testMoonUnitProbConstant(): void
    {
        $this->assertSame(100000, MOON_UNIT_PROB);
    }

    public function testMaxMoonProbConstant(): void
    {
        $this->assertSame(20, MAX_MOON_PROB);
    }

    public function testMoonMinStartSizeConstant(): void
    {
        $this->assertSame(2000, MOON_MIN_START_SIZE);
    }

    public function testMoonMaxStartSizeConstant(): void
    {
        $this->assertSame(6000, MOON_MAX_START_SIZE);
    }

    public function testMoonMinFactorConstant(): void
    {
        $this->assertSame(100, MOON_MIN_FACTOR);
    }

    public function testMoonMaxFactorConstant(): void
    {
        $this->assertSame(200, MOON_MAX_FACTOR);
    }

    public function testMoonMaxHightTempDifferenceFromPlanetConstant(): void
    {
        $this->assertSame(30, MOON_MAX_HIGHT_TEMP_DIFFERENCE_FROM_PLANET);
    }

    public function testMoonMaxLowTempDifferenceFromPlanetConstant(): void
    {
        $this->assertSame(10, MOON_MAX_LOW_TEMP_DIFFERENCE_FROM_PLANET);
    }

    public function testDefaultMoonNameConstant(): void
    {
        $this->assertSame('moon', DEFAULT_MOON_NAME);
    }

    public function testTimezoneConstant(): void
    {
        $this->assertSame('Europe/Vatican', TIMEZONE);
    }
}
