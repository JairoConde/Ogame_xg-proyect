<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

/**
 * Unit tests for the pure helpers under scripts/bot_lib/ and the
 * side-effect-free utilities extracted to scripts/bot_lib/util.php.
 *
 * Tests intentionally avoid PHPUnit data providers so failures point to
 * a single, named scenario instead of a numeric provider index. Each
 * group below mirrors one source file under scripts/bot_lib/.
 */
class BotLibTest extends TestCase
{
    // ---------------------------------------------------------------
    // util.php
    // ---------------------------------------------------------------

    public function testArgValueReadsEqualsForm(): void
    {
        $argv = ['script', '--user=bot1', '--loops=5'];
        $this->assertSame('bot1', argValue($argv, '--user'));
        $this->assertSame('5', argValue($argv, '--loops'));
    }

    public function testArgValueReadsSpaceForm(): void
    {
        $argv = ['script', '--user', 'bot1', '--loops', '5'];
        $this->assertSame('bot1', argValue($argv, '--user'));
        $this->assertSame('5', argValue($argv, '--loops'));
    }

    public function testArgValueReturnsDefaultWhenMissing(): void
    {
        $this->assertNull(argValue(['script'], '--user'));
        $this->assertSame('default', argValue(['script'], '--user', 'default'));
    }

    public function testArgValueDoesNotConsumeNextFlag(): void
    {
        // `--user --loops 5` should NOT yield "--loops" for --user.
        $argv = ['script', '--user', '--loops', '5'];
        $this->assertNull(argValue($argv, '--user'));
    }

    public function testHasFlagDetectsLiteralFlag(): void
    {
        $this->assertTrue(hasFlag(['script', '--dry-run'], '--dry-run'));
        $this->assertFalse(hasFlag(['script', '--dryrun'], '--dry-run'));
    }

    public function testParseUsersListSplitsAndTrims(): void
    {
        $this->assertSame(['bot1', 'bot2'], parseUsersList(' bot1 , bot2 '));
        $this->assertSame(['bot1', 'bot2'], parseUsersList('bot1,,bot2'));
    }

    public function testParseUsersListEmptyInputs(): void
    {
        $this->assertSame([], parseUsersList(null));
        $this->assertSame([], parseUsersList(''));
        $this->assertSame([], parseUsersList(' , '));
    }

    public function testNextLevelCostAppliesFactor(): void
    {
        $price = ['metal' => 60, 'crystal' => 15, 'deuterium' => 0, 'factor' => 1.5];
        // level 0 -> 1: factor^0 = 1
        $this->assertSame(['metal' => 60, 'crystal' => 15, 'deuterium' => 0], nextLevelCost($price, 0));
        // level 5 -> 6
        $expected = [
            'metal' => (int) floor(60 * pow(1.5, 5)),
            'crystal' => (int) floor(15 * pow(1.5, 5)),
            'deuterium' => 0,
        ];
        $this->assertSame($expected, nextLevelCost($price, 5));
    }

    public function testNextLevelCostHandlesMissingFields(): void
    {
        $cost = nextLevelCost([], 3);
        $this->assertSame(['metal' => 0, 'crystal' => 0, 'deuterium' => 0], $cost);
    }

    public function testMaxStorageFromLevelMonotonicallyIncreases(): void
    {
        $previous = -1;
        for ($lvl = 0; $lvl <= 12; $lvl++) {
            $value = maxStorageFromLevel($lvl, 1.0);
            $this->assertGreaterThan($previous, $value, "level {$lvl} should grow vs previous");
            $previous = $value;
        }
    }

    public function testMaxStorageFromLevelScalesWithMultiplier(): void
    {
        $base = maxStorageFromLevel(5, 1.0);
        $double = maxStorageFromLevel(5, 2.0);
        // The formula is linear in the resource multiplier.
        $this->assertSame($base * 2, $double);
    }

    public function testGetQueuedHangarAmountSumsMatchingIds(): void
    {
        $planet = ['planet_b_hangar_id' => '212,5;212,3;202,10;'];
        $this->assertSame(8, getQueuedHangarAmount($planet, 212));
        $this->assertSame(10, getQueuedHangarAmount($planet, 202));
        $this->assertSame(0, getQueuedHangarAmount($planet, 999));
    }

    public function testGetQueuedHangarAmountHandlesEmptyQueue(): void
    {
        $this->assertSame(0, getQueuedHangarAmount(['planet_b_hangar_id' => ''], 212));
        $this->assertSame(0, getQueuedHangarAmount(['planet_b_hangar_id' => '0'], 212));
        $this->assertSame(0, getQueuedHangarAmount([], 212));
    }

    public function testBotPlanetEnergyIsDeficitWhenAbsUsedExceedsMax(): void
    {
        $planet = ['planet_energy_max' => 400, 'planet_energy_used' => -500];
        $this->assertTrue(botPlanetEnergyIsDeficit($planet));
        $this->assertSame(100, botPlanetEnergyDeficitPoints($planet));
    }

    public function testBotPlanetEnergyNotDeficitWhenWithinCapacity(): void
    {
        $planet = ['planet_energy_max' => 500, 'planet_energy_used' => -400];
        $this->assertFalse(botPlanetEnergyIsDeficit($planet));
        $this->assertSame(0, botPlanetEnergyDeficitPoints($planet));
    }

    public function testBotPlanetEnergyDeficitWhenUsedStoredPositive(): void
    {
        // Some data paths store consumption as a positive planet_energy_used.
        $planet = ['planet_energy_max' => 400, 'planet_energy_used' => 500];
        $this->assertTrue(botPlanetEnergyIsDeficit($planet));
        $this->assertSame(100, botPlanetEnergyDeficitPoints($planet));
    }

    public function testBotPlanetEnergyDeficitWhenMaxZeroAndUsedNegative(): void
    {
        $planet = ['planet_energy_max' => 0, 'planet_energy_used' => -80];
        $this->assertTrue(botPlanetEnergyIsDeficit($planet));
        $this->assertSame(80, botPlanetEnergyDeficitPoints($planet));
    }

    public function testBotMiningDrillBuildableRemainingCountsHangarQueue(): void
    {
        $planet = [
            'ship_mining_drill' => 900,
            'planet_b_hangar_id' => '216,150;',
        ];
        $this->assertSame(0, botMiningDrillBuildableRemaining($planet));
    }

    public function testBotMiningDrillBuildableRemainingHeadroom(): void
    {
        $planet = [
            'ship_mining_drill' => 995,
            'planet_b_hangar_id' => '',
        ];
        $this->assertSame(5, botMiningDrillBuildableRemaining($planet));
    }

    public function testBotUserEffectiveColonyShipTotalSumsPlanetsAndQueue(): void
    {
        $planets = [
            ['ship_colony_ship' => 0, 'planet_b_hangar_id' => '208,1;'],
            ['ship_colony_ship' => 1, 'planet_b_hangar_id' => ''],
        ];
        $this->assertSame(2, botUserEffectiveColonyShipTotal($planets));
    }

    public function testBotCapBuildingActionRejectsMiningDrillOverHeadroom(): void
    {
        $planet = [
            'ship_mining_drill' => 998,
            'planet_b_hangar_id' => '',
        ];
        $action = ['type' => 'ship', 'id' => 216, 'column' => 'ship_mining_drill', 'amount' => 5];
        $this->assertNotNull(botCapBuildingAction($action, $planet));
    }

    public function testBotPlanetCanQueueShipyardUnitsRequiresHangar(): void
    {
        $planet = [
            'building_hangar' => 0,
            'planet_b_building_id' => '0',
        ];
        $this->assertFalse(botPlanetCanQueueShipyardUnits($planet));
        $planet['building_hangar'] = 1;
        $this->assertTrue(botPlanetCanQueueShipyardUnits($planet));
    }

    public function testBotPlanetFacilityUpgradeBlocksHangarDuringRoboticsUpgrade(): void
    {
        $future = time() + 3600;
        $planet = [
            'planet_b_building_id' => "14,5,100,{$future},build",
        ];
        $this->assertTrue(botPlanetFacilityUpgradeBlocksHangar($planet, time()));
    }

    public function testBotPlanetBuildingQueueRemainingMaxSeconds(): void
    {
        $now = 1_000_000;
        $planet = [
            'planet_b_building_id' => '1,10,500,' . ($now + 100) . ',build;2,8,400,' . ($now + 5000) . ',build',
        ];
        $this->assertSame(5000, botPlanetBuildingQueueRemainingMaxSeconds($planet, $now));
    }

    public function testBotShipyardUnitBuildSecondsPositive(): void
    {
        $planet = [
            'building_hangar' => 1,
            'building_nano_factory' => 0,
        ];
        $pricelist = [
            202 => ['metal' => 2000, 'crystal' => 2000, 'deuterium' => 0, 'factor' => 1],
        ];
        $t = botShipyardUnitBuildSeconds($planet, 202, $pricelist, 1.0);
        $this->assertGreaterThan(0.0, $t);
    }

    // ---------------------------------------------------------------
    // state.php (deterministic RNG)
    // ---------------------------------------------------------------

    public function testBotSeedIsDeterministicForSameUser(): void
    {
        $a = botSeed(42, 'bot1');
        $b = botSeed(42, 'bot1');
        $this->assertSame($a, $b);
    }

    public function testBotSeedDiffersAcrossUsers(): void
    {
        $a = botSeed(42, 'bot1');
        $b = botSeed(43, 'bot1');
        $c = botSeed(42, 'bot2');
        $this->assertNotSame($a, $b);
        $this->assertNotSame($a, $c);
    }

    public function testBotRngStaysWithinUnitInterval(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $v = botRng(12345, "k:{$i}");
            $this->assertGreaterThanOrEqual(0.0, $v);
            $this->assertLessThan(1.0, $v);
        }
    }

    public function testBotRngIsStableForSameInputs(): void
    {
        $this->assertSame(botRng(7, 'foo'), botRng(7, 'foo'));
        $this->assertNotSame(botRng(7, 'foo'), botRng(7, 'bar'));
    }

    public function testBotRngIntStaysWithinBounds(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $v = botRngInt(99, "k:{$i}", 3, 7);
            $this->assertGreaterThanOrEqual(3, $v);
            $this->assertLessThanOrEqual(7, $v);
        }
    }

    public function testBotRngIntCollapsesWhenMaxNotGreaterThanMin(): void
    {
        $this->assertSame(5, botRngInt(1, 'x', 5, 5));
        $this->assertSame(5, botRngInt(1, 'x', 5, 4));
    }

    public function testBotRngPickReturnsAnElement(): void
    {
        $options = ['a', 'b', 'c'];
        $picked = botRngPick(123, 'k', $options);
        $this->assertContains($picked, $options);
    }

    public function testBotRngPickThrowsOnEmptyList(): void
    {
        $this->expectException(InvalidArgumentException::class);
        botRngPick(1, 'k', []);
    }

    public function testBotRngShufflePreservesAllElements(): void
    {
        $list = [1, 2, 3, 4, 5];
        $shuffled = botRngShuffle(7, 'k', $list);
        sort($shuffled);
        $this->assertSame($list, $shuffled);
    }

    public function testBotRngShuffleIsStable(): void
    {
        $list = [10, 20, 30, 40, 50, 60];
        $a = botRngShuffle(7, 'k', $list);
        $b = botRngShuffle(7, 'k', $list);
        $this->assertSame($a, $b);
    }

    public function testBotRngWeightedPickReturnsNullForEmptyOrZero(): void
    {
        $this->assertNull(botRngWeightedPick(1, 'k', []));
        $this->assertNull(botRngWeightedPick(1, 'k', [
            ['weight' => 0, 'value' => 'a'],
            ['weight' => -1, 'value' => 'b'],
        ]));
    }

    public function testBotRngWeightedPickHonorsWeights(): void
    {
        // Heavy bias towards 'b' should hit it most of the time across
        // many independent keys.
        $candidates = [
            ['weight' => 0.01, 'value' => 'a'],
            ['weight' => 100.0, 'value' => 'b'],
        ];
        $hits = 0;
        for ($i = 0; $i < 200; $i++) {
            if (botRngWeightedPick(1, "k:{$i}", $candidates) === 'b') {
                $hits++;
            }
        }
        $this->assertGreaterThan(190, $hits);
    }

    // ---------------------------------------------------------------
    // safety.php
    // ---------------------------------------------------------------

    public function testCapClampUnitAmountRespectsQueueRowCap(): void
    {
        // Asking for more than BOT_CAP_UNITS_PER_QUEUE_ENTRY must clamp
        // down to the per-row maximum. We pass a desired well above the
        // cap so the row-entry limit is the binding constraint.
        $oversize = BOT_CAP_UNITS_PER_QUEUE_ENTRY * 2;
        $this->assertSame(BOT_CAP_UNITS_PER_QUEUE_ENTRY, botCapClampUnitAmount(0, $oversize));
    }

    public function testCapClampUnitAmountReturnsRequestedAmountWhenUnderAllCaps(): void
    {
        // 50k is below both BOT_CAP_UNITS_PER_QUEUE_ENTRY (10M) and
        // BOT_CAP_UNIT_PER_TYPE (1B), so the function should return it
        // verbatim instead of clamping to either ceiling.
        $this->assertSame(50_000, botCapClampUnitAmount(0, 50_000));
    }

    public function testCapClampUnitAmountRespectsAbsoluteCap(): void
    {
        $current = BOT_CAP_UNIT_PER_TYPE - 5;
        $this->assertSame(5, botCapClampUnitAmount($current, 100));
    }

    public function testCapClampUnitAmountReturnsZeroAtAbsoluteCap(): void
    {
        $this->assertSame(0, botCapClampUnitAmount(BOT_CAP_UNIT_PER_TYPE, 100));
        $this->assertSame(0, botCapClampUnitAmount(BOT_CAP_UNIT_PER_TYPE + 10, 100));
    }

    public function testCapBuildingTargetReached(): void
    {
        $this->assertFalse(botCapBuildingTargetReached(BOT_CAP_BUILDING_LEVEL - 1));
        $this->assertTrue(botCapBuildingTargetReached(BOT_CAP_BUILDING_LEVEL));
        $this->assertTrue(botCapBuildingTargetReached(BOT_CAP_BUILDING_LEVEL + 1));
    }

    public function testCapResearchTargetReached(): void
    {
        $this->assertFalse(botCapResearchTargetReached(BOT_CAP_RESEARCH_LEVEL - 1));
        $this->assertTrue(botCapResearchTargetReached(BOT_CAP_RESEARCH_LEVEL));
    }

    public function testCapBuildingActionAllowsNormalBuilding(): void
    {
        $action = ['type' => 'building', 'id' => 1, 'column' => 'building_metal_mine', 'amount' => 1];
        $planet = ['building_metal_mine' => 5];
        $this->assertNull(botCapBuildingAction($action, $planet));
    }

    public function testCapBuildingActionFlagsCappedBuilding(): void
    {
        $action = ['type' => 'building', 'id' => 1, 'column' => 'building_metal_mine', 'amount' => 1];
        $planet = ['building_metal_mine' => BOT_CAP_BUILDING_LEVEL];
        $this->assertNotNull(botCapBuildingAction($action, $planet));
    }

    public function testCapBuildingActionAllowsShipUnderCap(): void
    {
        $action = ['type' => 'ship', 'id' => 202, 'column' => 'ship_small_cargo_ship', 'amount' => 100];
        $planet = ['ship_small_cargo_ship' => 50];
        $this->assertNull(botCapBuildingAction($action, $planet));
    }

    public function testCapCostExceeded(): void
    {
        $this->assertFalse(botCapCostExceeded(['metal' => 1.0e10, 'crystal' => 1.0e10, 'deuterium' => 0]));
        $this->assertTrue(botCapCostExceeded([
            'metal' => 1.0e31,
            'crystal' => 0,
            'deuterium' => 0,
        ]));
    }

    // ---------------------------------------------------------------
    // strategy.php (colonization rules)
    // ---------------------------------------------------------------

    public function testColonizationAllowedPositionsExcludesReserved(): void
    {
        $allowed = botColonizationAllowedPositions();
        foreach (BOT_RESERVED_POSITIONS as $reserved) {
            $this->assertNotContains($reserved, $allowed);
        }
        // The current rules expose [3, 4, 5, 9, 10, 11, 12].
        $this->assertSame([3, 4, 5, 9, 10, 11, 12], $allowed);
    }

    public function testColonizationAllowedRejectsReservedPosition(): void
    {
        // Position 1, 2, 6, 7, 8, 13, 14, 15 are off-limits everywhere.
        foreach ([1, 2, 6, 7, 8, 13, 14, 15] as $p) {
            $this->assertFalse(botColonizationAllowed(1, BOT_RESERVED_SYSTEMS_PER_GALAXY + 1, $p));
        }
    }

    public function testColonizationAllowedRejectsReservedSystem(): void
    {
        for ($s = 1; $s <= BOT_RESERVED_SYSTEMS_PER_GALAXY; $s++) {
            $this->assertFalse(botColonizationAllowed(1, $s, 5));
        }
    }

    public function testColonizationAllowedAcceptsLegalSlot(): void
    {
        $this->assertTrue(botColonizationAllowed(1, BOT_RESERVED_SYSTEMS_PER_GALAXY + 5, 4));
        $this->assertTrue(botColonizationAllowed(2, 50, 9));
    }

    // ---------------------------------------------------------------
    // colonization.php
    // ---------------------------------------------------------------

    public function testMaxColoniesScalesWithAstrophysicsForMinero(): void
    {
        $this->assertSame(0, botColonizationMaxColonies(0, 'minero'));
        $this->assertSame(1, botColonizationMaxColonies(1, 'minero'));
        $this->assertSame(2, botColonizationMaxColonies(3, 'minero'));
        $this->assertSame(3, botColonizationMaxColonies(5, 'minero'));
    }

    public function testMaxColoniesAggressivePersonalitiesGetBonus(): void
    {
        // ceil(5/2)=3, +1 bonus -> 4 for cazador and flotero.
        $this->assertSame(4, botColonizationMaxColonies(5, 'cazador'));
        $this->assertSame(4, botColonizationMaxColonies(5, 'flotero'));
    }

    public function testMaxColoniesDefensorIsPenalized(): void
    {
        // ceil(5/2)=3, defensor returns base-1 -> 2.
        $this->assertSame(2, botColonizationMaxColonies(5, 'defensor'));
        $this->assertSame(0, botColonizationMaxColonies(1, 'defensor'));
    }

    public function testCanReachOtherGalaxiesByHyperspaceLevel(): void
    {
        $this->assertFalse(botColonizationCanReachOtherGalaxies([]));
        $this->assertFalse(botColonizationCanReachOtherGalaxies(['research_hyperspace_drive' => 4]));
        $this->assertTrue(botColonizationCanReachOtherGalaxies(['research_hyperspace_drive' => 5]));
        $this->assertTrue(botColonizationCanReachOtherGalaxies(['research_hyperspace_drive' => 9]));
    }

    public function testCountPlanetsInSystemIgnoresMoonsAndDestroyed(): void
    {
        $planets = [
            ['planet_galaxy' => 1, 'planet_system' => 50, 'planet_type' => 1, 'planet_destroyed' => 0],
            ['planet_galaxy' => 1, 'planet_system' => 50, 'planet_type' => 1, 'planet_destroyed' => 0],
            ['planet_galaxy' => 1, 'planet_system' => 50, 'planet_type' => 3, 'planet_destroyed' => 0], // moon
            ['planet_galaxy' => 1, 'planet_system' => 50, 'planet_type' => 1, 'planet_destroyed' => 1], // destroyed
            ['planet_galaxy' => 1, 'planet_system' => 51, 'planet_type' => 1, 'planet_destroyed' => 0],
        ];
        $this->assertSame(2, botColonizationCountPlanetsInSystem($planets, 1, 50));
        $this->assertSame(1, botColonizationCountPlanetsInSystem($planets, 1, 51));
        $this->assertSame(0, botColonizationCountPlanetsInSystem($planets, 2, 50));
    }

    public function testColonizationOrderedPositionsPrioritizesBigSizes(): void
    {
        $order = botColonizationOrderedPositions(123);
        $allowed = botColonizationAllowedPositions();
        // Same set of positions, no duplicates.
        sort($order);
        $sortedAllowed = $allowed;
        sort($sortedAllowed);
        $this->assertSame($sortedAllowed, $order);

        // Big sizes (4, 5, 9, 10) must come before any other allowed slot.
        $reorder = botColonizationOrderedPositions(123);
        $tier1Set = [4, 5, 9, 10];
        $foundOther = false;
        foreach ($reorder as $p) {
            if (in_array($p, $tier1Set, true)) {
                $this->assertFalse($foundOther, "tier1 slot {$p} appeared after a non-tier1 slot");
            } else {
                $foundOther = true;
            }
        }
    }

    public function testColonizationOrderedPositionsIsDeterministic(): void
    {
        $a = botColonizationOrderedPositions(7);
        $b = botColonizationOrderedPositions(7);
        $this->assertSame($a, $b);
    }

    // ---------------------------------------------------------------
    // travel.php
    // ---------------------------------------------------------------

    public function testTravelDistanceSamePlanetIsMinimum(): void
    {
        $coords = ['galaxy' => 1, 'system' => 30, 'planet' => 5];
        $d = botTravelDistance($coords, $coords);
        // App\Libraries\FleetsLib::targetDistance returns 5 for the
        // same-planet case; we just assert the wrapper is small.
        $this->assertGreaterThan(0, $d);
        $this->assertLessThan(50, $d);
    }

    public function testTravelDistanceIsSymmetric(): void
    {
        $a = ['galaxy' => 1, 'system' => 30, 'planet' => 5];
        $b = ['galaxy' => 1, 'system' => 35, 'planet' => 7];
        $this->assertSame(botTravelDistance($a, $b), botTravelDistance($b, $a));
    }

    public function testTravelDistanceCrossSystemLargerThanSamePlanet(): void
    {
        $a = ['galaxy' => 1, 'system' => 30, 'planet' => 5];
        $b = ['galaxy' => 1, 'system' => 80, 'planet' => 5];
        $this->assertGreaterThan(botTravelDistance($a, $a), botTravelDistance($a, $b));
    }

    public function testPickCargoMixReturnsNullWhenPayloadZero(): void
    {
        $this->assertNull(botPickCargoMix(0, 100, 100, []));
    }

    public function testPickCargoMixReturnsNullWhenNoCargoAvailable(): void
    {
        $this->assertNull(botPickCargoMix(50_000, 0, 0, []));
    }

    public function testPickCargoMixUsesBigCargoFirst(): void
    {
        // Big cargo capacity is 25k base. Payload 30k with both available
        // should preferentially use 2 big cargos (covers it) and 0 small.
        $mix = botPickCargoMix(30_000, 100, 100, []);
        $this->assertNotNull($mix);
        $this->assertSame(2, $mix['big']);
        $this->assertSame(0, $mix['small']);
        $this->assertGreaterThanOrEqual(30_000, $mix['capacity']);
    }

    public function testPickCargoMixFallsBackToSmallCargosWhenBigsRunOut(): void
    {
        // With no big cargos available, small cargo capacity is 5k base.
        $mix = botPickCargoMix(12_000, 100, 0, []);
        $this->assertNotNull($mix);
        $this->assertSame(0, $mix['big']);
        $this->assertSame(3, $mix['small']);
        $this->assertGreaterThanOrEqual(12_000, $mix['capacity']);
    }

    // ---------------------------------------------------------------
    // decisions.php (overrides)
    // ---------------------------------------------------------------

    public function testBotColonyShipOverrideNullWithoutAstrophysics(): void
    {
        $planet = [
            'ship_colony_ship' => 0,
            'building_hangar' => 5,
            'planet_b_building_id' => '0',
        ];
        $research = ['research_astrophysics' => 0, 'research_impulse_drive' => 5];
        $all = [$planet];
        $this->assertNull(botColonyShipOverride($planet, $research, $all));
    }

    public function testBotColonyShipOverrideReturnsShipWhenEligible(): void
    {
        $planet = [
            'ship_colony_ship' => 0,
            'building_hangar' => 4,
            'planet_b_building_id' => '0',
        ];
        $research = ['research_astrophysics' => 2, 'research_impulse_drive' => 3];
        $all = [$planet];
        $action = botColonyShipOverride($planet, $research, $all);
        $this->assertNotNull($action);
        $this->assertSame('ship', $action['type']);
        $this->assertSame(208, $action['id']);
        $this->assertSame('ship_colony_ship', $action['column']);
        $this->assertSame(1, $action['amount']);
    }

    public function testBotColonyShipOverrideReturnsHangarWhenBelowEffectiveLevel4(): void
    {
        $planet = [
            'ship_colony_ship' => 0,
            'building_hangar' => 2,
            'planet_b_building_id' => '0',
            'planet_energy_max' => 500,
            'planet_energy_used' => -100,
        ];
        $research = ['research_astrophysics' => 1, 'research_impulse_drive' => 0];
        $all = [$planet];
        $action = botColonyShipOverride($planet, $research, $all);
        $this->assertNotNull($action);
        $this->assertSame('building', $action['type']);
        $this->assertSame(21, $action['id']);
        $this->assertSame('building_hangar', $action['column']);
        $this->assertSame(1, $action['amount']);
    }

    public function testBotColonyShipOverrideNullForHangarPrereqWhenEnergyDeficit(): void
    {
        $planet = [
            'ship_colony_ship' => 0,
            'building_hangar' => 2,
            'planet_b_building_id' => '0',
            'planet_energy_max' => 50,
            'planet_energy_used' => -200,
        ];
        $research = ['research_astrophysics' => 1, 'research_impulse_drive' => 0];
        $all = [$planet];
        $this->assertNull(botColonyShipOverride($planet, $research, $all));
    }

    public function testBotHomeLaboratoryOverrideReturnsLabWhenHomeHasNone(): void
    {
        $planet = [
            'planet_id' => 100,
            'building_laboratory' => 0,
            'planet_b_building_id' => '0',
            'planet_energy_max' => 500,
            'planet_energy_used' => -100,
        ];
        $action = botHomeLaboratoryOverride($planet, 100);
        $this->assertNotNull($action);
        $this->assertSame('building', $action['type']);
        $this->assertSame(31, $action['id']);
        $this->assertSame('building_laboratory', $action['column']);
    }

    public function testBotHomeLaboratoryOverrideNullOnNonHomePlanet(): void
    {
        $planet = [
            'planet_id' => 2,
            'building_laboratory' => 0,
            'planet_b_building_id' => '0',
            'planet_energy_max' => 500,
            'planet_energy_used' => -100,
        ];
        $this->assertNull(botHomeLaboratoryOverride($planet, 100));
    }

    public function testBotHomeLaboratoryOverrideNullWhenLabQueued(): void
    {
        $planet = [
            'planet_id' => 7,
            'building_laboratory' => 0,
            'planet_b_building_id' => '31,3,0,9999999999;',
            'planet_energy_max' => 500,
            'planet_energy_used' => -100,
        ];
        $this->assertNull(botHomeLaboratoryOverride($planet, 7));
    }

    public function testBotHomeLaboratoryOverrideReturnsWhenEffectiveLabBelowThree(): void
    {
        $planet = [
            'planet_id' => 50,
            'building_laboratory' => 2,
            'planet_b_building_id' => '0',
            'planet_energy_max' => 500,
            'planet_energy_used' => -100,
        ];
        $action = botHomeLaboratoryOverride($planet, 50);
        $this->assertNotNull($action);
        $this->assertSame('building_laboratory', $action['column']);
    }

    public function testBotHomeLaboratoryOverrideNullWhenEnergyDeficit(): void
    {
        $planet = [
            'planet_id' => 1,
            'building_laboratory' => 0,
            'planet_b_building_id' => '0',
            'planet_energy_max' => 50,
            'planet_energy_used' => -200,
        ];
        $this->assertNull(botHomeLaboratoryOverride($planet, 1));
    }

    public function testBotResearchRemainingSecondsZeroWhenNoResearch(): void
    {
        $research = ['research_current_research' => 0];
        $planets = [['planet_id' => 1, 'planet_b_tech_id' => 0, 'planet_b_tech' => 0]];
        $this->assertSame(0, botResearchRemainingSeconds($research, $planets, 1_000_000));
    }

    public function testBotResearchRemainingSecondsFromResearchPlanet(): void
    {
        $research = ['research_current_research' => 5];
        $planets = [
            ['planet_id' => 1, 'planet_b_tech_id' => 0, 'planet_b_tech' => 0],
            ['planet_id' => 5, 'planet_b_tech_id' => 113, 'planet_b_tech' => 1_000_000],
        ];
        $this->assertSame(0, botResearchRemainingSeconds($research, $planets, 1_000_000));
        $this->assertSame(500_000, botResearchRemainingSeconds($research, $planets, 500_000));
    }

    public function testBotSumTopLaboratoriesLevel(): void
    {
        $planets = [
            ['planet_id' => 1, 'building_laboratory' => 5, 'planet_b_building_id' => '0'],
            ['planet_id' => 2, 'building_laboratory' => 2, 'planet_b_building_id' => '0'],
            ['planet_id' => 3, 'building_laboratory' => 8, 'planet_b_building_id' => '0'],
        ];
        $this->assertSame(13, botSumTopLaboratoriesLevel($planets, 2));
        $this->assertSame(15, botSumTopLaboratoriesLevel($planets, 3));
    }

    public function testBotPlanetWithMaxLaboratoryTieBreaksByPlanetId(): void
    {
        $planets = [
            ['planet_id' => 1, 'building_laboratory' => 4, 'planet_b_building_id' => '0'],
            ['planet_id' => 9, 'building_laboratory' => 4, 'planet_b_building_id' => '0'],
        ];
        $best = botPlanetWithMaxLaboratory($planets);
        $this->assertNotNull($best);
        $this->assertSame(9, (int) $best['planet_id']);
    }

    public function testEnergyOverrideReturnsNullWhenBalancePositive(): void
    {
        $planet = [
            'planet_energy_max' => 200,
            'planet_energy_used' => -100,
            'building_solar_plant' => 5,
            'planet_temp_max' => 0,
            'ship_solar_satellite' => 0,
            'planet_b_hangar_id' => '',
        ];
        $this->assertNull(botEnergyOverride($planet));
    }

    public function testEnergyOverrideForcesSolarPlantWhenLow(): void
    {
        $planet = [
            'planet_energy_max' => 0,
            'planet_energy_used' => -150,
            'building_solar_plant' => 5,
            'planet_temp_max' => 50,
            'ship_solar_satellite' => 0,
            'planet_b_hangar_id' => '',
        ];
        $action = botEnergyOverride($planet);
        $this->assertNotNull($action);
        $this->assertSame('building', $action['type']);
        $this->assertSame('building_solar_plant', $action['column']);
    }

    public function testEnergyOverrideForcesSatellitesOnceSolarReachesFloor(): void
    {
        // Solar plant at 18 -> the override switches to satellites.
        $planet = [
            'planet_energy_max' => 200,
            'planet_energy_used' => -300,
            'building_solar_plant' => 18,
            'planet_temp_max' => 100,
            'ship_solar_satellite' => 0,
            'planet_b_hangar_id' => '',
            'building_hangar' => 1,
            'building_robot_factory' => 2,
            'building_nano_factory' => 0,
            'planet_b_building_id' => '0',
        ];
        $action = botEnergyOverride($planet);
        $this->assertNotNull($action);
        $this->assertSame('ship', $action['type']);
        $this->assertSame('ship_solar_satellite', $action['column']);
        $this->assertGreaterThan(0, $action['amount']);
    }

    public function testEnergyOverrideSkipsWhenSatellitesAlreadyQueuedSufficient(): void
    {
        // Deficit of 200, satellite energy ~ (140+0)/6 = 23 each, queue 100
        // satellites already pending => no further satellites needed.
        $planet = [
            'planet_energy_max' => 0,
            'planet_energy_used' => -200,
            'building_solar_plant' => 18,
            'planet_temp_max' => 0,
            'ship_solar_satellite' => 0,
            'planet_b_hangar_id' => '212,100;',
        ];
        $this->assertNull(botEnergyOverride($planet));
    }

    public function testStorageOverrideReturnsNullBelowPanic(): void
    {
        $GLOBALS['resourceMultiplier'] = 1.0;
        $planet = $this->makeStorageTestPlanet(0.5, 0.5, 0.5);
        $this->assertNull(botStorageOverride($planet, ['bot_quirks' => []]));
    }

    public function testStorageOverrideUpgradesFullestBin(): void
    {
        $GLOBALS['resourceMultiplier'] = 1.0;
        $planet = $this->makeStorageTestPlanet(0.95, 0.5, 0.3);
        $action = botStorageOverride($planet, ['bot_quirks' => []]);
        $this->assertNotNull($action);
        $this->assertSame('building_metal_store', $action['column']);
    }

    public function testStorageOverrideHonorsPersonalityPanicThreshold(): void
    {
        $GLOBALS['resourceMultiplier'] = 1.0;
        $planet = $this->makeStorageTestPlanet(0.85, 0.4, 0.4);
        // Default panic = 0.9 so 0.85 should NOT trigger.
        $this->assertNull(botStorageOverride($planet, ['bot_quirks' => []]));
        // A bot with panic threshold 0.8 should trigger now.
        $this->assertNotNull(botStorageOverride(
            $planet,
            ['bot_quirks' => ['storage_panic_threshold' => 0.8]]
        ));
    }

    public function testEffectiveUnitCountIncludesQueue(): void
    {
        $planet = [
            'ship_solar_satellite' => 5,
            'planet_b_hangar_id' => '212,7;',
        ];
        $this->assertSame(12, botEffectiveUnitCount($planet, 212, 'ship_solar_satellite'));
    }

    public function testEffectiveUnitCountFallsBackToCurrentWhenQueueEmpty(): void
    {
        $planet = [
            'ship_solar_satellite' => 4,
            'planet_b_hangar_id' => '',
        ];
        $this->assertSame(4, botEffectiveUnitCount($planet, 212, 'ship_solar_satellite'));
    }

    // ---------------------------------------------------------------
    // transport.php
    // ---------------------------------------------------------------

    public function testTransportPersonalityCooldown(): void
    {
        $base = BOT_TRANSPORT_COOLDOWN_BASE_SECONDS;
        $this->assertSame($base - 300, botTransportPersonalityCooldown('flotero'));
        $this->assertSame($base - 300, botTransportPersonalityCooldown('cazador'));
        $this->assertSame($base + 900, botTransportPersonalityCooldown('defensor'));
        $this->assertSame($base + 300, botTransportPersonalityCooldown('tecnologico'));
        $this->assertSame($base, botTransportPersonalityCooldown('minero'));
        $this->assertSame($base, botTransportPersonalityCooldown('unknown'));
    }

    public function testTransportReserveRatioByRole(): void
    {
        // Pure 'minero' on a metal-role planet: base 0.10 - 0.05 = 0.05.
        $this->assertEquals(0.05, botTransportPersonalityReserveRatio('minero', 'metal'));
        // 'defensor' on military: base 0.50 + 0.20 = 0.70.
        $this->assertEquals(0.70, botTransportPersonalityReserveRatio('defensor', 'military'));
        // Unknown role -> neutral 0.20 baseline.
        $this->assertEquals(0.20, botTransportPersonalityReserveRatio('unknown_pers', 'unknown_role'));
    }

    public function testTransportReserveRatioCannotGoBelowFloor(): void
    {
        // 'minero' on metal would go below 0.05 if the floor didn't kick in.
        $r = botTransportPersonalityReserveRatio('minero', 'metal');
        $this->assertGreaterThanOrEqual(0.05, $r);
    }

    public function testTransportRoleSenderAndReceiver(): void
    {
        $this->assertTrue(botTransportRoleIsSenderLike('metal'));
        $this->assertTrue(botTransportRoleIsSenderLike('crystal'));
        $this->assertTrue(botTransportRoleIsSenderLike('support'));
        $this->assertFalse(botTransportRoleIsSenderLike('military'));
        $this->assertFalse(botTransportRoleIsSenderLike('industrial'));

        $this->assertTrue(botTransportRoleIsReceiverLike('military'));
        $this->assertTrue(botTransportRoleIsReceiverLike('industrial'));
        $this->assertFalse(botTransportRoleIsReceiverLike('metal'));
    }

    public function testTransportNextQueuedBuildingCostReturnsShortfall(): void
    {
        // Pricelist for metal mine (id=1): factor 1.5; level 0->1 = 60/15.
        $pricelist = $GLOBALS['pricelist'];
        $planet = [
            'planet_b_building_id' => '1,1,3600,9999999999,build;',
            'planet_metal' => 0,
            'planet_crystal' => 0,
            'planet_deuterium' => 0,
        ];
        $cost = botTransportNextQueuedBuildingCost($planet, $pricelist);
        $this->assertNotNull($cost);
        $this->assertSame(60, $cost['metal']);
        $this->assertSame(15, $cost['crystal']);
        $this->assertSame(0, $cost['deuterium']);
    }

    public function testTransportNextQueuedBuildingCostNullWhenAffordable(): void
    {
        $pricelist = $GLOBALS['pricelist'];
        $planet = [
            'planet_b_building_id' => '1,1,3600,9999999999,build;',
            'planet_metal' => 100,
            'planet_crystal' => 100,
            'planet_deuterium' => 100,
        ];
        $this->assertNull(botTransportNextQueuedBuildingCost($planet, $pricelist));
    }

    public function testTransportNextQueuedBuildingCostNullWhenQueueEmpty(): void
    {
        $pricelist = $GLOBALS['pricelist'];
        $planet = ['planet_b_building_id' => '0', 'planet_metal' => 0, 'planet_crystal' => 0, 'planet_deuterium' => 0];
        $this->assertNull(botTransportNextQueuedBuildingCost($planet, $pricelist));
    }

    public function testTransportNextQueuedHangarCostReturnsShortfall(): void
    {
        // Small cargo (id=202): metal=2000, crystal=2000 each.
        $pricelist = $GLOBALS['pricelist'];
        $planet = [
            'planet_b_hangar_id' => '202,5;',
            'planet_metal' => 0,
            'planet_crystal' => 0,
            'planet_deuterium' => 0,
        ];
        $cost = botTransportNextQueuedHangarCost($planet, $pricelist);
        $this->assertNotNull($cost);
        $this->assertSame(10_000, $cost['metal']);
        $this->assertSame(10_000, $cost['crystal']);
        $this->assertSame(0, $cost['deuterium']);
    }

    public function testTransportPlanetNeedMergesBuildingAndHangar(): void
    {
        $pricelist = $GLOBALS['pricelist'];
        $planet = [
            'planet_b_building_id' => '1,1,3600,9999999999,build;',
            'planet_b_hangar_id' => '202,5;',
            'planet_metal' => 0,
            'planet_crystal' => 0,
            'planet_deuterium' => 0,
        ];
        $need = botTransportPlanetNeed($planet, $pricelist);
        $this->assertNotNull($need);
        $this->assertSame(60 + 10_000, $need['metal']);
        $this->assertSame(15 + 10_000, $need['crystal']);
        $this->assertContains('building', $need['sources']);
        $this->assertContains('hangar', $need['sources']);
    }

    // ---------------------------------------------------------------
    // personality.php (target generation)
    // ---------------------------------------------------------------

    public function testPersonalTargetsAreDeterministic(): void
    {
        $a = botPersonalTargets(42, 'minero');
        $b = botPersonalTargets(42, 'minero');
        $this->assertSame($a, $b);
    }

    public function testPersonalTargetsRespectBuildingCap(): void
    {
        $targets = botPersonalTargets(42, 'minero');
        foreach ($targets as $col => $level) {
            $this->assertLessThanOrEqual(BOT_CAP_BUILDING_LEVEL, $level, "{$col} above cap");
            $this->assertGreaterThanOrEqual(1, $level, "{$col} below 1");
        }
    }

    public function testPersonalTargetsMineroBoostsMines(): void
    {
        // Same seed, different personalities -> minero must have >= mines.
        $seed = 12345;
        $minero = botPersonalTargets($seed, 'minero');
        $defensor = botPersonalTargets($seed, 'defensor');
        $this->assertGreaterThanOrEqual(
            $defensor['building_metal_mine'],
            $minero['building_metal_mine'] - 2 // tolerance for clamps
        );
        // Crystal mine specifically: +4 minero vs +0 defensor -> strictly higher.
        $this->assertGreaterThan(
            $defensor['building_crystal_mine'],
            $minero['building_crystal_mine']
        );
    }

    public function testPersonalTargetsTecnologicoBoostsLab(): void
    {
        $seed = 67890;
        $tecno = botPersonalTargets($seed, 'tecnologico');
        $minero = botPersonalTargets($seed, 'minero');
        $this->assertGreaterThan(
            $minero['building_laboratory'],
            $tecno['building_laboratory']
        );
    }

    public function testDefenseRecipeAlwaysIncludesRocketLauncher(): void
    {
        foreach (['minero', 'flotero', 'cazador', 'defensor', 'tecnologico'] as $pers) {
            $recipe = botDefenseRecipe(42, $pers);
            $cols = array_column($recipe, 'column');
            $this->assertContains('defense_rocket_launcher', $cols, "missing for {$pers}");
        }
    }

    public function testDefenseRecipeSharesSumToOne(): void
    {
        $recipe = botDefenseRecipe(42, 'flotero');
        $sum = 0.0;
        foreach ($recipe as $entry) {
            $sum += $entry['share'];
        }
        $this->assertEqualsWithDelta(1.0, $sum, 0.001);
    }

    // ---------------------------------------------------------------
    // combat.php (deterministic battle simulator)
    // ---------------------------------------------------------------

    public function testCombatSimulateAttackerOverwhelmsTinyDefender(): void
    {
        $pricelist = $GLOBALS['pricelist'];
        // 50 cruisers vs 1 light fighter -> attacker wins decisively.
        $sim = botCombatSimulate(
            [206 => 50],
            [204 => 1],
            [],
            ['research_weapons_technology' => 5, 'research_shielding_technology' => 5, 'research_armour_technology' => 5],
            ['research_weapons_technology' => 0, 'research_shielding_technology' => 0, 'research_armour_technology' => 0],
            $pricelist
        );
        $this->assertSame('attacker', $sim['winner']);
        $this->assertGreaterThanOrEqual(1, $sim['rounds_used']);
        $this->assertLessThanOrEqual(BOT_COMBAT_MAX_ROUNDS, $sim['rounds_used']);
        // The lone defender unit is destroyed.
        $this->assertSame(1, $sim['defender_losses_by_id'][204] ?? 0);
    }

    public function testCombatSimulateIsDeterministic(): void
    {
        $pricelist = $GLOBALS['pricelist'];
        $args = [
            [206 => 30, 207 => 5],
            [205 => 20],
            [401 => 50, 402 => 25],
            ['research_weapons_technology' => 4, 'research_shielding_technology' => 3, 'research_armour_technology' => 3],
            ['research_weapons_technology' => 2, 'research_shielding_technology' => 2, 'research_armour_technology' => 2],
            $pricelist,
        ];
        $first = botCombatSimulate(...$args);
        $second = botCombatSimulate(...$args);
        $this->assertSame($first, $second, 'Combat simulator must be deterministic');
    }

    public function testCombatLossesValueSumsBuildCost(): void
    {
        $pricelist = $GLOBALS['pricelist'];
        $value = botCombatLossesValue([204 => 2, 401 => 10], $pricelist);
        $expectedShip = 2 * (3000 + 1000 + 0); // light fighter price
        $expectedDef = 10 * (2000 + 0 + 0);    // rocket launcher price
        $this->assertSame($expectedShip + $expectedDef, $value);
    }

    public function testCombatDebrisOnlyFromShipsNotDefenses(): void
    {
        $pricelist = $GLOBALS['pricelist'];
        $debris = botCombatDebrisFromLosses([204 => 10, 401 => 100], $pricelist);
        // Light fighter: metal=3000, crystal=1000.
        $expectedM = (int) floor(3000 * 0.30 * 10);
        $expectedC = (int) floor(1000 * 0.30 * 10);
        $this->assertSame($expectedM, $debris['metal']);
        $this->assertSame($expectedC, $debris['crystal']);
    }

    // ---------------------------------------------------------------
    // attack.php (profitability ratio + helpers)
    // ---------------------------------------------------------------

    public function testAttackProfitabilityRatioFollowsBracket(): void
    {
        // Default constants: very_aggressive=3.0 .. conservative=6.0.
        $this->assertSame(3.0, botAttackProfitabilityRatio(9.0, []));
        $this->assertSame(4.0, botAttackProfitabilityRatio(7.0, []));
        $this->assertSame(5.0, botAttackProfitabilityRatio(5.0, []));
        $this->assertSame(6.0, botAttackProfitabilityRatio(2.0, []));
    }

    public function testAttackProfitabilityRatioRespectsSingleOverride(): void
    {
        $profile = ['attack_ratio' => 4.5];
        $this->assertSame(4.5, botAttackProfitabilityRatio(9.0, $profile));
        $this->assertSame(4.5, botAttackProfitabilityRatio(2.0, $profile));
    }

    public function testAttackProfitabilityRatioRespectsPerBracketOverride(): void
    {
        $profile = ['attack_ratios' => [
            'very_aggressive' => 2.5,
            'moderate' => 5.5,
            // missing aggressive + conservative -> falls back to defaults
        ]];
        $this->assertSame(
            max((float) BOT_ATTACK_RATIO_MIN, 2.5),
            botAttackProfitabilityRatio(9.0, $profile)
        );
        $this->assertSame(5.5, botAttackProfitabilityRatio(5.0, $profile));
        // Aggressive falls back to default.
        $this->assertSame(4.0, botAttackProfitabilityRatio(7.0, $profile));
        // Conservative falls back to default.
        $this->assertSame(6.0, botAttackProfitabilityRatio(2.0, $profile));
    }

    public function testAttackProfitabilityRatioClampsExtremeOverrides(): void
    {
        $profile = ['attack_ratio' => 99.0];
        $this->assertSame((float) BOT_ATTACK_RATIO_MAX, botAttackProfitabilityRatio(5.0, $profile));
        $profile = ['attack_ratio' => 0.1];
        $this->assertSame((float) BOT_ATTACK_RATIO_MIN, botAttackProfitabilityRatio(5.0, $profile));
    }

    public function testAttackAggressivenessBracketBoundaries(): void
    {
        $this->assertSame('very_aggressive', botAttackAggressivenessBracket(8.0));
        $this->assertSame('aggressive', botAttackAggressivenessBracket(7.99));
        $this->assertSame('aggressive', botAttackAggressivenessBracket(6.0));
        $this->assertSame('moderate', botAttackAggressivenessBracket(5.99));
        $this->assertSame('moderate', botAttackAggressivenessBracket(4.0));
        $this->assertSame('conservative', botAttackAggressivenessBracket(3.99));
        $this->assertSame('conservative', botAttackAggressivenessBracket(0.0));
    }

    public function testAttackAggressivenessScoreIsClampedAndPersonalityBiased(): void
    {
        $profile = ['aggressiveness' => 5, 'bot_style' => 'raider'];
        $cazadorState = ['bot_personality' => 'cazador', 'bot_archetype' => 'turbo', 'bot_current_focus' => 'mil'];
        $mineroState = ['bot_personality' => 'minero',  'bot_archetype' => 'turtle', 'bot_current_focus' => 'eco'];
        $cazadorScore = botAttackAggressivenessScore($profile, $cazadorState);
        $mineroScore = botAttackAggressivenessScore($profile, $mineroState);
        $this->assertGreaterThan($mineroScore, $cazadorScore);
        $this->assertLessThanOrEqual(10.0, $cazadorScore);
        $this->assertGreaterThanOrEqual(0.0, $mineroScore);
    }

    public function testAttackIntelTtlExpiry(): void
    {
        $now = 1_700_000_000;
        $fresh = ['captured_at' => $now - 60];
        $stale = ['captured_at' => $now - (int) BOT_INTEL_TTL_SECONDS - 1];
        $missing = ['captured_at' => 0];
        $this->assertTrue(botAttackIntelIsFresh($fresh, $now));
        $this->assertFalse(botAttackIntelIsFresh($stale, $now));
        $this->assertFalse(botAttackIntelIsFresh($missing, $now));
    }

    public function testAttackEstimateLootCappedByCargoCapacity(): void
    {
        $intel = ['snapshot' => ['resources' => ['metal' => 200_000, 'crystal' => 100_000, 'deuterium' => 50_000]]];
        // half = 100k+50k+25k = 175k. Cap at 60k.
        $loot = botAttackEstimateLoot($intel, 60_000);
        $this->assertSame(60_000, $loot['loot']);
        $loot = botAttackEstimateLoot($intel, 999_999_999);
        $this->assertSame(175_000, $loot['loot']);
    }

    public function testAttackReservedSystemsDecodesConstantList(): void
    {
        $reserved = botAttackReservedSystems();
        $this->assertContains(1, $reserved, 'system 1 must be reserved by default');
        foreach ($reserved as $sys) {
            $this->assertIsInt($sys);
        }
    }

    // ---------------------------------------------------------------
    // travel.php (raid mix reservation)
    // ---------------------------------------------------------------

    public function testRaidMixRespectsFleetReservePercentage(): void
    {
        // 100 light fighters (id=204) + 100 small cargos (id=202) available.
        // Default reserve=20% -> at most floor(100*0.8)=80 of each may fly.
        $researchRow = ['research_hyperspace_technology' => 0, 'research_cargo_optimization' => 0];
        $mix = botPickRaidMix(120_000, 0, [204 => 100, 202 => 100], $researchRow);
        $this->assertNotNull($mix);
        $this->assertLessThanOrEqual(80, (int) ($mix['by_id'][204] ?? 0));
        $this->assertLessThanOrEqual(80, (int) ($mix['by_id'][202] ?? 0));
    }

    // ---------------------------------------------------------------
    // attack.php — persistent metrics in bot_quirks
    // ---------------------------------------------------------------

    public function testAttackMetricsInitializesFromMissingState(): void
    {
        $state = ['bot_user_id' => 1];
        $delta = [
            'spies_sent' => 4,
            'intel_captured' => 2,
            'attacks_launched' => 1,
            'total_loot_launched' => 240_000,
            'total_my_losses_value' => 18_000,
            'last_spy_at' => 1_700_000_500,
            'last_attack_at' => 1_700_000_600,
            'last_loop_at' => 1_700_000_700,
        ];
        botAttackMergeMetrics($state, $delta);

        $metrics = $state['bot_quirks']['metrics'];
        $this->assertSame(4, $metrics['spies_sent']);
        $this->assertSame(2, $metrics['intel_captured']);
        $this->assertSame(1, $metrics['attacks_launched']);
        $this->assertSame(240_000, $metrics['total_loot_launched']);
        $this->assertSame(18_000, $metrics['total_my_losses_value']);
        $this->assertSame(1_700_000_500, $metrics['last_spy_at']);
        $this->assertSame(1_700_000_600, $metrics['last_attack_at']);
        $this->assertSame(1_700_000_700, $metrics['last_loop_at']);
        $this->assertSame(1_700_000_700, $metrics['first_seen_at']);
        $this->assertSame(1, $metrics['loops_processed']);
    }

    public function testAttackMetricsAccumulateOverMultipleLoops(): void
    {
        $state = ['bot_user_id' => 1];
        botAttackMergeMetrics($state, [
            'spies_sent' => 2,
            'attacks_launched' => 1,
            'total_loot_launched' => 100,
            'last_loop_at' => 1_700_000_000,
        ]);
        botAttackMergeMetrics($state, [
            'spies_sent' => 3,
            'attacks_skipped_ratio' => 4,
            'total_loot_launched' => 250,
            'last_loop_at' => 1_700_000_300,
        ]);

        $metrics = $state['bot_quirks']['metrics'];
        $this->assertSame(5, $metrics['spies_sent']);
        $this->assertSame(1, $metrics['attacks_launched']);
        $this->assertSame(4, $metrics['attacks_skipped_ratio']);
        $this->assertSame(350, $metrics['total_loot_launched']);
        $this->assertSame(2, $metrics['loops_processed']);
    }

    public function testAttackMetricsTimestampsKeepMaximum(): void
    {
        $state = ['bot_user_id' => 1];
        botAttackMergeMetrics($state, ['last_spy_at' => 1_700_000_500, 'last_loop_at' => 1_700_000_500]);
        // Stale delta arriving out-of-order (e.g. a re-played loop): the
        // stored timestamp must not regress.
        botAttackMergeMetrics($state, ['last_spy_at' => 1_700_000_100, 'last_loop_at' => 1_700_000_100]);

        $metrics = $state['bot_quirks']['metrics'];
        $this->assertSame(1_700_000_500, $metrics['last_spy_at']);
        $this->assertSame(1_700_000_500, $metrics['last_loop_at']);
    }

    public function testAttackMetricsFirstSeenIsSticky(): void
    {
        $state = ['bot_user_id' => 1];
        botAttackMergeMetrics($state, ['last_loop_at' => 1_700_000_500]);
        botAttackMergeMetrics($state, ['last_loop_at' => 1_700_000_900]);
        $this->assertSame(1_700_000_500, $state['bot_quirks']['metrics']['first_seen_at']);
    }

    public function testAttackMetricsPreserveUnrelatedQuirks(): void
    {
        $state = [
            'bot_user_id' => 1,
            'bot_quirks' => [
                'attack_cooldowns' => [42 => 1_700_000_000],
                'intel' => ['7' => ['captured_at' => 1_700_000_000]],
                'metrics' => ['spies_sent' => 9, 'loops_processed' => 3],
            ],
        ];
        botAttackMergeMetrics($state, ['spies_sent' => 2, 'last_loop_at' => 1_700_000_900]);

        $quirks = $state['bot_quirks'];
        $this->assertSame(11, $quirks['metrics']['spies_sent']);
        $this->assertSame(4, $quirks['metrics']['loops_processed']);
        $this->assertSame([42 => 1_700_000_000], $quirks['attack_cooldowns']);
        $this->assertArrayHasKey('7', $quirks['intel']);
    }

    // ---------------------------------------------------------------
    // attack.php — intel map size cap
    // ---------------------------------------------------------------

    public function testAttackTruncateIntelKeepsNewestWhenOverCap(): void
    {
        $intel = [
            10 => ['captured_at' => 100],
            20 => ['captured_at' => 300],
            30 => ['captured_at' => 200],
        ];
        $out = botAttackTruncateIntelByRecency($intel, 2);
        $this->assertCount(2, $out);
        $this->assertArrayHasKey(20, $out);
        $this->assertArrayHasKey(30, $out);
        $this->assertArrayNotHasKey(10, $out);
    }

    public function testAttackTruncateIntelNoOpWhenAtOrUnderCap(): void
    {
        $intel = [
            1 => ['captured_at' => 50],
            2 => ['captured_at' => 60],
        ];
        $this->assertSame($intel, botAttackTruncateIntelByRecency($intel, 2));
        $this->assertSame($intel, botAttackTruncateIntelByRecency($intel, 5));
    }

    public function testAttackTruncateIntelDisabledWhenMaxZero(): void
    {
        $intel = [];
        for ($i = 1; $i <= 10; $i++) {
            $intel[$i] = ['captured_at' => $i * 10];
        }
        $out = botAttackTruncateIntelByRecency($intel, 0);
        $this->assertCount(10, $out);
    }

    public function testAttackTruncateIntelTieBreakUsesHigherPlanetId(): void
    {
        $t = 1_700_000_000;
        $intel = [
            1 => ['captured_at' => $t],
            2 => ['captured_at' => $t],
            3 => ['captured_at' => $t],
        ];
        $out = botAttackTruncateIntelByRecency($intel, 2);
        $this->assertCount(2, $out);
        $this->assertArrayHasKey(3, $out);
        $this->assertArrayHasKey(2, $out);
        $this->assertArrayNotHasKey(1, $out);
    }

    // ---------------------------------------------------------------
    // attack.php — harvest-only pass for bots outside active window
    // ---------------------------------------------------------------

    public function testAttackMetricsHarvestOnlyLoopsCounterIsRecognised(): void
    {
        // Even without mysqli we can exercise the metrics fold directly:
        // verify the new counter key is summed like the other counters.
        $state = ['bot_user_id' => 1];
        botAttackMergeMetrics($state, ['harvest_only_loops' => 1]);
        botAttackMergeMetrics($state, ['harvest_only_loops' => 1]);
        botAttackMergeMetrics($state, ['harvest_only_loops' => 1]);
        $this->assertSame(3, $state['bot_quirks']['metrics']['harvest_only_loops']);
    }

    public function testAttackHarvestOnlyAccumulatesLoopsAndDoesNotEmitLogsWithoutPendingSpies(): void
    {
        // botAttackHarvestOnly has a mysqli typehint in its signature; the
        // helper itself never touches the DB when pending_spies is empty
        // and __synthetic disables persistence, but we still need a mysqli
        // *instance* to pass typehint validation. If the extension is not
        // loaded on the host PHP (common on minimal CLIs), skip the test;
        // the same code is exercised in-vivo inside the Docker bot
        // container, which always has mysqli.
        if (!extension_loaded('mysqli') || !class_exists('mysqli')) {
            $this->markTestSkipped('mysqli extension not loaded on host PHP');
        }

        $state = [
            'bot_user_id' => 99,
            '__synthetic' => true,
            'bot_quirks' => [
                'attack_cooldowns' => [],
                'spy_cooldowns' => [],
                'pending_spies' => [],
                'intel' => [],
            ],
        ];

        $fakeDb = $this->createMock(\mysqli::class);
        $logs = botAttackHarvestOnly($fakeDb, 'xgp_', $state);

        $this->assertSame([], $logs);
        $this->assertSame(1, $state['bot_quirks']['metrics']['harvest_only_loops']);
        $this->assertSame(0, $state['bot_quirks']['metrics']['intel_captured']);
        $this->assertGreaterThan(0, $state['bot_quirks']['metrics']['last_loop_at']);
    }

    public function testAttackHarvestOnlyPreservesIntelAndCooldownsAcrossCalls(): void
    {
        if (!extension_loaded('mysqli') || !class_exists('mysqli')) {
            $this->markTestSkipped('mysqli extension not loaded on host PHP');
        }

        // Use timestamps near the test wall-clock so botAttackSaveCooldowns'
        // 24h purge does not drop them under our feet.
        $now = time();

        $state = [
            'bot_user_id' => 99,
            '__synthetic' => true,
            'bot_quirks' => [
                'attack_cooldowns' => [10 => $now - 60],
                'spy_cooldowns' => [20 => $now - 120],
                'pending_spies' => [],
                'intel' => [30 => ['captured_at' => $now - 30, 'sent_at' => $now - 100]],
            ],
        ];

        $fakeDb = $this->createMock(\mysqli::class);
        botAttackHarvestOnly($fakeDb, 'xgp_', $state);
        botAttackHarvestOnly($fakeDb, 'xgp_', $state);

        $this->assertSame(2, $state['bot_quirks']['metrics']['harvest_only_loops']);
        $this->assertArrayHasKey(10, $state['bot_quirks']['attack_cooldowns']);
        $this->assertArrayHasKey(20, $state['bot_quirks']['spy_cooldowns']);
        $this->assertArrayHasKey(30, $state['bot_quirks']['intel']);
    }

    // ---------------------------------------------------------------
    // attack.php — reactive aggressiveness bonus (revenge mode)
    // ---------------------------------------------------------------

    public function testReactiveBonusIsZeroOutsideWindow(): void
    {
        $now = 1_700_000_000;
        $state = [
            'bot_last_attacked_at' => $now - 7 * 3600,
            'bot_attack_reactive_until' => $now - 3600,
        ];
        $this->assertSame(0.0, botAttackReactiveBonus($state, $now));
    }

    public function testReactiveBonusPeaksRightAfterAttack(): void
    {
        $now = 1_700_000_000;
        $state = [
            'bot_last_attacked_at' => $now,
            'bot_attack_reactive_until' => $now + 6 * 3600,
        ];
        $expected = defined('BOT_ATTACK_REACTIVE_BONUS_MAX')
            ? (float) BOT_ATTACK_REACTIVE_BONUS_MAX
            : 2.0;
        $this->assertEqualsWithDelta($expected, botAttackReactiveBonus($state, $now), 0.0001);
    }

    public function testReactiveBonusDecaysLinearlyToZero(): void
    {
        $attackedAt = 1_700_000_000;
        $until = $attackedAt + 6 * 3600;
        $state = [
            'bot_last_attacked_at' => $attackedAt,
            'bot_attack_reactive_until' => $until,
        ];
        $max = defined('BOT_ATTACK_REACTIVE_BONUS_MAX')
            ? (float) BOT_ATTACK_REACTIVE_BONUS_MAX
            : 2.0;

        $halfway = $attackedAt + 3 * 3600;
        $this->assertEqualsWithDelta($max * 0.5, botAttackReactiveBonus($state, $halfway), 0.001);

        $almostEnd = $until - 60; // 1 minute left in the window
        $bonusAtEnd = botAttackReactiveBonus($state, $almostEnd);
        $this->assertGreaterThan(0.0, $bonusAtEnd);
        $this->assertLessThan(0.1, $bonusAtEnd);
    }

    public function testReactiveBonusReturnsZeroWhenWindowDataIsMissing(): void
    {
        $now = 1_700_000_000;
        $this->assertSame(0.0, botAttackReactiveBonus([], $now));
        $this->assertSame(0.0, botAttackReactiveBonus(['bot_attack_reactive_until' => $now + 60], $now));
        // reactive_until before last_attacked_at is malformed; never apply.
        $state = [
            'bot_last_attacked_at' => $now,
            'bot_attack_reactive_until' => $now - 10,
        ];
        $this->assertSame(0.0, botAttackReactiveBonus($state, $now));
    }

    public function testAggressivenessScoreAppliesReactiveBonusToOffensiveBots(): void
    {
        $now = 1_700_000_000;
        $profile = ['aggressiveness' => 3, 'bot_style' => 'granja'];
        $stateBase = [
            'bot_personality' => 'cazador',
            'bot_archetype' => 'balanced',
            'bot_current_focus' => 'eco',
            'bot_last_attacked_at' => $now,
            'bot_attack_reactive_until' => $now + 6 * 3600,
        ];

        $stateNoReact = $stateBase;
        $stateNoReact['bot_attack_reactive_until'] = $now - 60;
        $baseline = botAttackAggressivenessScore($profile, $stateNoReact, null, $now);
        $reactive = botAttackAggressivenessScore($profile, $stateBase, null, $now);

        $this->assertGreaterThan($baseline, $reactive);
        $this->assertEqualsWithDelta(
            $baseline + (defined('BOT_ATTACK_REACTIVE_BONUS_MAX') ? (float) BOT_ATTACK_REACTIVE_BONUS_MAX : 2.0),
            $reactive,
            0.001
        );
    }

    public function testAggressivenessScoreIgnoresReactiveBonusForPassiveBots(): void
    {
        $now = 1_700_000_000;
        $profile = ['aggressiveness' => 3, 'bot_style' => 'granja'];
        $stateBase = [
            'bot_personality' => 'minero',
            'bot_archetype' => 'balanced',
            'bot_current_focus' => 'eco',
            'bot_last_attacked_at' => $now,
            'bot_attack_reactive_until' => $now + 6 * 3600,
        ];

        $stateNoReact = $stateBase;
        $stateNoReact['bot_attack_reactive_until'] = $now - 60;
        $baseline = botAttackAggressivenessScore($profile, $stateNoReact, null, $now);
        $reactive = botAttackAggressivenessScore($profile, $stateBase, null, $now);

        $this->assertSame($baseline, $reactive);
    }

    public function testAggressivenessScoreAppliesReactiveBonusToRaiderStyle(): void
    {
        $now = 1_700_000_000;
        // Raider style with a non-offensive personality should still qualify
        // for the bonus: profile-level raider overrides personality.
        $profile = ['aggressiveness' => 3, 'bot_style' => 'raider'];
        $state = [
            'bot_personality' => 'tecnologico',
            'bot_archetype' => 'balanced',
            'bot_current_focus' => 'eco',
            'bot_last_attacked_at' => $now,
            'bot_attack_reactive_until' => $now + 6 * 3600,
        ];
        $this->assertTrue(botAttackHasOffensiveLeaning($profile, $state));
        $bonus = botAttackReactiveBonus($state, $now);
        $this->assertGreaterThan(0.0, $bonus);
    }

    // ---------------------------------------------------------------
    // attack.php — revenge-first candidate reorder
    // ---------------------------------------------------------------

    public function testRevengeReorderMovesAttackerPlanetsToTheFront(): void
    {
        $candidates = [
            ['planet_id' => 11, 'planet_user_id' => 5],
            ['planet_id' => 12, 'planet_user_id' => 7], // attacker
            ['planet_id' => 13, 'planet_user_id' => 5],
            ['planet_id' => 14, 'planet_user_id' => 7], // attacker
        ];
        $out = botAttackReorderRevengeFirst($candidates, 7);
        $this->assertSame(12, $out[0]['planet_id']);
        $this->assertSame(14, $out[1]['planet_id']);
        $this->assertSame(11, $out[2]['planet_id']);
        $this->assertSame(13, $out[3]['planet_id']);
    }

    public function testRevengeReorderIsNoopWhenAttackerHasNoCandidates(): void
    {
        $candidates = [
            ['planet_id' => 11, 'planet_user_id' => 5],
            ['planet_id' => 12, 'planet_user_id' => 6],
        ];
        $this->assertSame($candidates, botAttackReorderRevengeFirst($candidates, 99));
    }

    public function testRevengeReorderIsNoopForZeroAttackerId(): void
    {
        $candidates = [
            ['planet_id' => 11, 'planet_user_id' => 5],
            ['planet_id' => 12, 'planet_user_id' => 6],
        ];
        $this->assertSame($candidates, botAttackReorderRevengeFirst($candidates, 0));
    }

    // ---------------------------------------------------------------
    // attack.php — reactive_attacks_launched metrics counter
    // ---------------------------------------------------------------

    public function testReactiveAttacksLaunchedAccumulates(): void
    {
        $state = ['bot_user_id' => 1];
        botAttackMergeMetrics($state, ['reactive_attacks_launched' => 1]);
        botAttackMergeMetrics($state, ['reactive_attacks_launched' => 2]);
        $this->assertSame(3, $state['bot_quirks']['metrics']['reactive_attacks_launched']);
    }

    // ---------------------------------------------------------------
    // safety.php — botFleetInsertTransactional
    // ---------------------------------------------------------------

    public function testFleetInsertTransactionalCommitsOnHappyPath(): void
    {
        if (!extension_loaded('mysqli') || !class_exists('mysqli')) {
            $this->markTestSkipped('mysqli extension not loaded on host PHP');
        }

        $db = $this->createMock(\mysqli::class);
        $db->expects($this->once())->method('begin_transaction')->willReturn(true);
        $db->method('query')->willReturn(true);
        $db->expects($this->once())->method('commit');
        $db->expects($this->never())->method('rollback');

        $result = botFleetInsertTransactional($db, 'xgp_', 7, function () {
            return 42;
        });

        $this->assertSame(42, $result);
    }

    public function testFleetInsertTransactionalRollsBackWhenClosureReturnsNull(): void
    {
        if (!extension_loaded('mysqli') || !class_exists('mysqli')) {
            $this->markTestSkipped('mysqli extension not loaded on host PHP');
        }

        $db = $this->createMock(\mysqli::class);
        $db->expects($this->once())->method('begin_transaction')->willReturn(true);
        $db->method('query')->willReturn(true);
        $db->expects($this->never())->method('commit');
        $db->expects($this->once())->method('rollback');

        $result = botFleetInsertTransactional($db, 'xgp_', 7, function () {
            return null;
        });

        $this->assertNull($result);
    }

    public function testFleetInsertTransactionalRollsBackWhenClosureReturnsZero(): void
    {
        if (!extension_loaded('mysqli') || !class_exists('mysqli')) {
            $this->markTestSkipped('mysqli extension not loaded on host PHP');
        }

        $db = $this->createMock(\mysqli::class);
        $db->expects($this->once())->method('begin_transaction')->willReturn(true);
        $db->method('query')->willReturn(true);
        $db->expects($this->never())->method('commit');
        $db->expects($this->once())->method('rollback');

        $result = botFleetInsertTransactional($db, 'xgp_', 7, function () {
            return 0;
        });

        $this->assertNull($result);
    }

    public function testFleetInsertTransactionalRollsBackWhenClosureThrows(): void
    {
        if (!extension_loaded('mysqli') || !class_exists('mysqli')) {
            $this->markTestSkipped('mysqli extension not loaded on host PHP');
        }

        $db = $this->createMock(\mysqli::class);
        $db->expects($this->once())->method('begin_transaction')->willReturn(true);
        $db->method('query')->willReturn(true);
        $db->expects($this->never())->method('commit');
        $db->expects($this->once())->method('rollback');

        $result = botFleetInsertTransactional($db, 'xgp_', 7, function () {
            throw new \RuntimeException('insert blew up');
        });

        $this->assertNull($result);
    }

    public function testFleetInsertTransactionalFallsBackWhenBeginRefuses(): void
    {
        if (!extension_loaded('mysqli') || !class_exists('mysqli')) {
            $this->markTestSkipped('mysqli extension not loaded on host PHP');
        }

        // begin_transaction returning false (autocommit weirdness, broken
        // connection, etc.) must fall back to non-transactional execution
        // so the bot loop keeps making progress. Old behaviour preserved.
        $db = $this->createMock(\mysqli::class);
        $db->expects($this->once())->method('begin_transaction')->willReturn(false);
        $db->expects($this->never())->method('commit');
        $db->expects($this->never())->method('rollback');

        $invoked = 0;
        $result = botFleetInsertTransactional($db, 'xgp_', 7, function () use (&$invoked) {
            $invoked++;

            return 99;
        });

        $this->assertSame(99, $result);
        $this->assertSame(1, $invoked);
    }

    public function testFleetInsertTransactionalRejectsNonPositiveSourcePlanetId(): void
    {
        if (!extension_loaded('mysqli') || !class_exists('mysqli')) {
            $this->markTestSkipped('mysqli extension not loaded on host PHP');
        }

        $db = $this->createMock(\mysqli::class);
        $db->expects($this->never())->method('begin_transaction');
        $db->expects($this->never())->method('query');
        $db->expects($this->never())->method('commit');
        $db->expects($this->never())->method('rollback');

        $invoked = false;
        $result = botFleetInsertTransactional($db, 'xgp_', 0, function () use (&$invoked) {
            $invoked = true;

            return 42;
        });

        $this->assertNull($result);
        $this->assertFalse($invoked);
    }

    // ---------------------------------------------------------------
    // attack.php — botAttackHarvestReturns (loot/losses observation)
    // ---------------------------------------------------------------

    public function testHarvestReturnsEmptyInFlightIsNoop(): void
    {
        if (!extension_loaded('mysqli') || !class_exists('mysqli')) {
            $this->markTestSkipped('mysqli extension not loaded on host PHP');
        }

        $db = $this->createMock(\mysqli::class);
        $db->expects($this->never())->method('query');

        $state = [
            'bot_user_id' => 1,
            '__synthetic' => true,
            'bot_quirks' => ['attacks_in_flight' => []],
        ];
        $logs = botAttackHarvestReturns($db, 'xgp_', $state, [], 1000);
        $this->assertSame([], $logs);
    }

    public function testHarvestReturnsTransitionsOutboundToReturning(): void
    {
        if (!extension_loaded('mysqli') || !class_exists('mysqli')) {
            $this->markTestSkipped('mysqli extension not loaded on host PHP');
        }

        $result = $this->getMockBuilder(\mysqli_result::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fetch_assoc', 'free'])
            ->getMock();
        $result->method('fetch_assoc')->willReturnOnConsecutiveCalls(
            [
                'fleet_id' => 42,
                'fleet_mess' => 1,
                'fleet_array' => serialize([204 => 30, 206 => 5]),
                'fleet_resource_metal' => 100000,
                'fleet_resource_crystal' => 50000,
                'fleet_resource_deuterium' => 10000,
                'fleet_end_time' => 2000,
            ],
            null
        );

        $db = $this->createMock(\mysqli::class);
        $db->method('query')->willReturn($result);

        $state = [
            'bot_user_id' => 1,
            '__synthetic' => true,
            'bot_quirks' => [
                'attacks_in_flight' => [
                    42 => [
                        'launched_at' => 100,
                        'expected_arrival' => 500,
                        'expected_return' => 900,
                        'ship_mix' => [204 => 50, 206 => 10],
                        'loot_estimated' => 200000,
                        'ship_value_launched' => 999999,
                        'status' => 'outbound',
                        'target_planet_id' => 13,
                    ],
                ],
            ],
        ];

        $logs = botAttackHarvestReturns($db, 'xgp_', $state, [], 1000);
        $this->assertCount(1, $logs);
        $this->assertStringContainsString('attack: returning fleet=42 loot=160000', $logs[0]);

        $entry = $state['bot_quirks']['attacks_in_flight'][42];
        $this->assertSame('returning', $entry['status']);
        $this->assertSame(160000, $entry['loot_real']);
        $this->assertSame([204 => 30, 206 => 5], $entry['ship_mix_returning']);
        // Metrics should NOT be accumulated yet (still in flight).
        $this->assertArrayNotHasKey('attacks_completed', $state['bot_quirks']['metrics'] ?? []);
    }

    public function testHarvestReturnsRowGoneCompletesReturningWithLosses(): void
    {
        if (!extension_loaded('mysqli') || !class_exists('mysqli')) {
            $this->markTestSkipped('mysqli extension not loaded on host PHP');
        }

        // Empty resultset: no row -> fleet completed.
        $result = $this->getMockBuilder(\mysqli_result::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fetch_assoc', 'free'])
            ->getMock();
        $result->method('fetch_assoc')->willReturn(null);
        $db = $this->createMock(\mysqli::class);
        $db->method('query')->willReturn($result);

        // Pricelist for losses computation: ship 204 costs 5_000 total,
        // ship 206 costs 30_000 total.
        $pricelist = [
            204 => ['metal' => 3000, 'crystal' => 1000, 'deuterium' => 1000],
            206 => ['metal' => 20000, 'crystal' => 7000, 'deuterium' => 3000],
        ];

        // Launched: 50x204 + 10x206 = 50*5000 + 10*30000 = 250_000+300_000=550_000
        // Returning: 30x204 + 5x206 = 30*5000 + 5*30000 = 150_000+150_000=300_000
        // Losses: 550_000 - 300_000 = 250_000.
        $state = [
            'bot_user_id' => 1,
            '__synthetic' => true,
            'bot_quirks' => [
                'attacks_in_flight' => [
                    42 => [
                        'launched_at' => 100,
                        'expected_arrival' => 500,
                        'expected_return' => 900,
                        'ship_mix' => [204 => 50, 206 => 10],
                        'loot_estimated' => 200000,
                        'ship_value_launched' => 550000,
                        'status' => 'returning',
                        'loot_real' => 160000,
                        'ship_mix_returning' => [204 => 30, 206 => 5],
                        'target_planet_id' => 13,
                    ],
                ],
            ],
        ];

        $logs = botAttackHarvestReturns($db, 'xgp_', $state, $pricelist, 1000);
        $this->assertCount(1, $logs);
        $this->assertStringContainsString(
            'attack: completed fleet=42 loot=160000 losses=250000',
            $logs[0]
        );

        $metrics = $state['bot_quirks']['metrics'];
        $this->assertSame(1, $metrics['attacks_completed']);
        $this->assertSame(160000, $metrics['total_loot_returned']);
        $this->assertSame(250000, $metrics['real_ships_lost_value']);
        $this->assertArrayNotHasKey(42, $state['bot_quirks']['attacks_in_flight']);
    }

    public function testHarvestReturnsRowGoneOutboundCountsNoData(): void
    {
        if (!extension_loaded('mysqli') || !class_exists('mysqli')) {
            $this->markTestSkipped('mysqli extension not loaded on host PHP');
        }

        $result = $this->getMockBuilder(\mysqli_result::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fetch_assoc', 'free'])
            ->getMock();
        $result->method('fetch_assoc')->willReturn(null);
        $db = $this->createMock(\mysqli::class);
        $db->method('query')->willReturn($result);

        $state = [
            'bot_user_id' => 1,
            '__synthetic' => true,
            'bot_quirks' => [
                'attacks_in_flight' => [
                    42 => [
                        'launched_at' => 100,
                        'expected_arrival' => 500,
                        'expected_return' => 900,
                        'ship_mix' => [204 => 50],
                        'loot_estimated' => 200000,
                        'ship_value_launched' => 50000,
                        'status' => 'outbound',
                        'target_planet_id' => 13,
                    ],
                ],
            ],
        ];

        $logs = botAttackHarvestReturns($db, 'xgp_', $state, [], 1000);
        $this->assertCount(1, $logs);
        $this->assertStringContainsString('attack: completed fleet=42 (no_data', $logs[0]);

        $metrics = $state['bot_quirks']['metrics'];
        $this->assertSame(1, $metrics['attacks_completed_no_data']);
        $this->assertSame(0, $metrics['attacks_completed'] ?? 0);
        $this->assertSame(0, $metrics['total_loot_returned'] ?? 0);
        $this->assertArrayNotHasKey(42, $state['bot_quirks']['attacks_in_flight']);
    }

    public function testHarvestReturnsTTLDropsStaleStillPresentRow(): void
    {
        if (!extension_loaded('mysqli') || !class_exists('mysqli')) {
            $this->markTestSkipped('mysqli extension not loaded on host PHP');
        }

        // Fleet row still present but in outbound state way past the
        // expected return + 1h paranoid window. Drop with no_data.
        $result = $this->getMockBuilder(\mysqli_result::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fetch_assoc', 'free'])
            ->getMock();
        $result->method('fetch_assoc')->willReturnOnConsecutiveCalls(
            [
                'fleet_id' => 42,
                'fleet_mess' => 0,
                'fleet_array' => serialize([204 => 30]),
                'fleet_resource_metal' => 0,
                'fleet_resource_crystal' => 0,
                'fleet_resource_deuterium' => 0,
                'fleet_end_time' => 1,
            ],
            null
        );
        $db = $this->createMock(\mysqli::class);
        $db->method('query')->willReturn($result);

        $state = [
            'bot_user_id' => 1,
            '__synthetic' => true,
            'bot_quirks' => [
                'attacks_in_flight' => [
                    42 => [
                        'launched_at' => 0,
                        'expected_arrival' => 100,
                        'expected_return' => 200,
                        'ship_mix' => [204 => 50],
                        'loot_estimated' => 0,
                        'ship_value_launched' => 0,
                        'status' => 'outbound',
                    ],
                ],
            ],
        ];

        // Now well past expected_return + 3600.
        $logs = botAttackHarvestReturns($db, 'xgp_', $state, [], 10000);
        $this->assertNotEmpty($logs);
        $foundDrop = false;
        foreach ($logs as $l) {
            if (strpos($l, 'attack: drop fleet=42 (stale_in_flight)') !== false) {
                $foundDrop = true;
            }
        }
        $this->assertTrue($foundDrop, 'TTL drop log should be emitted');
        $this->assertArrayNotHasKey(42, $state['bot_quirks']['attacks_in_flight']);
        $this->assertSame(1, $state['bot_quirks']['metrics']['attacks_completed_no_data']);
    }

    public function testHarvestReturnsNeverNegativeLossesIfShipsExceedLaunched(): void
    {
        // Defensive: if for any reason ship_mix_returning has more ships
        // than ship_value_launched would suggest, losses must clamp to 0
        // and never go negative (which would corrupt metrics aggregation).
        if (!extension_loaded('mysqli') || !class_exists('mysqli')) {
            $this->markTestSkipped('mysqli extension not loaded on host PHP');
        }

        $result = $this->getMockBuilder(\mysqli_result::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fetch_assoc', 'free'])
            ->getMock();
        $result->method('fetch_assoc')->willReturn(null);
        $db = $this->createMock(\mysqli::class);
        $db->method('query')->willReturn($result);

        $pricelist = [
            204 => ['metal' => 3000, 'crystal' => 1000, 'deuterium' => 1000],
        ];

        $state = [
            'bot_user_id' => 1,
            '__synthetic' => true,
            'bot_quirks' => [
                'attacks_in_flight' => [
                    42 => [
                        'status' => 'returning',
                        'expected_return' => 900,
                        'ship_mix' => [204 => 5],
                        'ship_value_launched' => 25000,
                        'loot_real' => 1000,
                        'ship_mix_returning' => [204 => 999],
                    ],
                ],
            ],
        ];

        botAttackHarvestReturns($db, 'xgp_', $state, $pricelist, 1000);
        $this->assertSame(
            0,
            $state['bot_quirks']['metrics']['real_ships_lost_value'],
            'Negative losses must clamp to 0'
        );
    }

    // ---------------------------------------------------------------
    // attack.php — botAttackHandlePendingPlans (two-phase recheck)
    // ---------------------------------------------------------------

    /**
     * Builds a mysqli mock whose query() returns a result row matching
     * the JOIN-of-planet+ships+defenses that botAttackReadTargetSnapshot
     * issues. `$snapshot['ships']` and `$snapshot['defenses']` are dicts
     * of {shipId => count}. Resources go in $resources.
     */
    private function makePendingPlansDb(array $resources, array $ships = [], array $defenses = []): \mysqli
    {
        $row = [
            'planet_id' => 99,
            'planet_galaxy' => 1,
            'planet_system' => 2,
            'planet_planet' => 3,
            'planet_metal' => (string) (int) ($resources['metal'] ?? 0),
            'planet_crystal' => (string) (int) ($resources['crystal'] ?? 0),
            'planet_deuterium' => (string) (int) ($resources['deuterium'] ?? 0),
        ];
        $shipColumns = [
            202 => 'ship_small_cargo_ship', 203 => 'ship_big_cargo_ship',
            204 => 'ship_light_fighter', 205 => 'ship_heavy_fighter',
            206 => 'ship_cruiser', 207 => 'ship_battleship',
            210 => 'ship_espionage_probe',
        ];
        foreach ($shipColumns as $id => $col) {
            $row[$col] = (int) ($ships[$id] ?? 0);
        }
        $defenseColumns = [
            401 => 'defense_rocket_launcher', 402 => 'defense_light_laser',
            403 => 'defense_heavy_laser', 404 => 'defense_gauss_cannon',
            405 => 'defense_ion_cannon', 406 => 'defense_plasma_turret',
            407 => 'defense_small_shield_dome', 408 => 'defense_large_shield_dome',
        ];
        foreach ($defenseColumns as $id => $col) {
            $row[$col] = (int) ($defenses[$id] ?? 0);
        }

        $result = $this->getMockBuilder(\mysqli_result::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fetch_assoc', 'free'])
            ->getMock();
        $result->method('fetch_assoc')->willReturn($row);

        $db = $this->createMock(\mysqli::class);
        $db->method('query')->willReturn($result);

        return $db;
    }

    private function makePlanForTest(int $targetPid, int $sourcePid, array $shipMix, int $armedAt, int $recheckArrival): array
    {
        return [
            'armed_at' => $armedAt,
            'recheck_fleet_id' => 1234,
            'recheck_arrival' => $recheckArrival,
            'source_planet_id' => $sourcePid,
            'ship_mix' => $shipMix,
            'ratio_threshold' => 5.0,
            'min_loot' => 50000,
            'estimated_loot' => 500000,
            'estimated_losses' => 100000,
            'target_planet_id' => $targetPid,
        ];
    }

    public function testHandlePlansEmptyIsNoop(): void
    {
        if (!extension_loaded('mysqli') || !class_exists('mysqli')) {
            $this->markTestSkipped('mysqli extension not loaded on host PHP');
        }
        $db = $this->createMock(\mysqli::class);
        $db->expects($this->never())->method('query');
        $state = ['bot_user_id' => 1, '__synthetic' => true, 'bot_quirks' => ['pending_attacks' => []]];
        $shipsByPlanet = [];
        $attackCooldowns = [];
        $res = botAttackHandlePendingPlans(
            $db,
            'xgp_',
            $state,
            ['user_id' => 1],
            [],
            $shipsByPlanet,
            $attackCooldowns,
            [],
            [],
            1.0,
            1000,
            5,
            0
        );
        $this->assertSame([], $res['logs']);
        $this->assertSame(0, $res['attacks_confirmed']);
    }

    public function testHandlePlansWaitsBeforeRecheckArrival(): void
    {
        if (!extension_loaded('mysqli') || !class_exists('mysqli')) {
            $this->markTestSkipped('mysqli extension not loaded on host PHP');
        }
        $db = $this->createMock(\mysqli::class);
        $db->expects($this->never())->method('query');
        $plan = $this->makePlanForTest(99, 1, [204 => 10], 500, 2000);
        $state = [
            'bot_user_id' => 1,
            '__synthetic' => true,
            'bot_quirks' => ['pending_attacks' => [99 => $plan]],
        ];
        $shipsByPlanet = [1 => [204 => 30]];
        $attackCooldowns = [];
        $res = botAttackHandlePendingPlans(
            $db,
            'xgp_',
            $state,
            ['user_id' => 1],
            [],
            $shipsByPlanet,
            $attackCooldowns,
            [],
            [],
            1.0,
            1000,
            5,
            0
        );
        $this->assertSame([], $res['logs']);
        $this->assertArrayHasKey(99, $state['bot_quirks']['pending_attacks']);
    }

    public function testHandlePlansAbortsOnStalePlan(): void
    {
        if (!extension_loaded('mysqli') || !class_exists('mysqli')) {
            $this->markTestSkipped('mysqli extension not loaded on host PHP');
        }
        if (!defined('BOT_ATTACK_PLAN_TTL_SECONDS')) {
            define('BOT_ATTACK_PLAN_TTL_SECONDS', 6 * 3600);
        }
        $db = $this->createMock(\mysqli::class);
        $db->expects($this->never())->method('query');
        // armed_at 100000, now 200000 -> 100000s ago > 6h TTL.
        $plan = $this->makePlanForTest(99, 1, [204 => 10], 100000, 100100);
        $state = [
            'bot_user_id' => 1,
            '__synthetic' => true,
            'bot_quirks' => ['pending_attacks' => [99 => $plan]],
        ];
        $shipsByPlanet = [1 => [204 => 20]];
        $attackCooldowns = [];
        $res = botAttackHandlePendingPlans(
            $db,
            'xgp_',
            $state,
            ['user_id' => 1],
            [],
            $shipsByPlanet,
            $attackCooldowns,
            [],
            [],
            1.0,
            200000,
            5,
            0
        );
        $this->assertCount(1, $res['logs']);
        $this->assertStringContainsString('stale_plan', $res['logs'][0]);
        $this->assertSame(1, $res['metrics_delta']['attacks_aborted_stale_plan']);
        $this->assertArrayNotHasKey(99, $state['bot_quirks']['pending_attacks']);
        $this->assertSame(30, $shipsByPlanet[1][204], 'Ships should be released back');
    }

    public function testHandlePlansAbortsOnNoSourcePlanet(): void
    {
        if (!extension_loaded('mysqli') || !class_exists('mysqli')) {
            $this->markTestSkipped('mysqli extension not loaded on host PHP');
        }
        // Source planet 99 does not exist in $planets list anymore.
        // Snapshot returns OK; we should still abort with no_source.
        $db = $this->makePendingPlansDb(
            ['metal' => 5_000_000, 'crystal' => 5_000_000, 'deuterium' => 1_000_000],
            [],
            []
        );
        $plan = $this->makePlanForTest(99, 999, [204 => 10], 1000, 1100);
        $state = [
            'bot_user_id' => 1,
            '__synthetic' => true,
            'bot_quirks' => ['pending_attacks' => [99 => $plan]],
        ];
        $shipsByPlanet = [999 => [204 => 5]];
        $attackCooldowns = [];
        $planets = [['planet_id' => 1, 'planet_galaxy' => 1, 'planet_system' => 1, 'planet_planet' => 1]];
        $res = botAttackHandlePendingPlans(
            $db,
            'xgp_',
            $state,
            ['user_id' => 1],
            $planets,
            $shipsByPlanet,
            $attackCooldowns,
            [],
            [],
            1.0,
            2000,
            5,
            0
        );
        $this->assertCount(1, $res['logs']);
        $this->assertStringContainsString('no_source', $res['logs'][0]);
        $this->assertSame(1, $res['metrics_delta']['attacks_aborted_no_source']);
        $this->assertSame(15, $shipsByPlanet[999][204], 'Released back to source even if planet gone');
    }

    public function testHandlePlansAbortsOnLowLoot(): void
    {
        if (!extension_loaded('mysqli') || !class_exists('mysqli')) {
            $this->markTestSkipped('mysqli extension not loaded on host PHP');
        }
        // Target with negligible resources -> loot below min_loot=50000.
        $db = $this->makePendingPlansDb(
            ['metal' => 100, 'crystal' => 100, 'deuterium' => 100],
            [],
            []
        );
        $plan = $this->makePlanForTest(99, 1, [204 => 10], 1000, 1100);
        $state = [
            'bot_user_id' => 1,
            '__synthetic' => true,
            'bot_quirks' => ['pending_attacks' => [99 => $plan]],
        ];
        $shipsByPlanet = [1 => [204 => 5]];
        $attackCooldowns = [];
        $planets = [['planet_id' => 1, 'planet_galaxy' => 1, 'planet_system' => 1, 'planet_planet' => 1]];
        $res = botAttackHandlePendingPlans(
            $db,
            'xgp_',
            $state,
            ['user_id' => 1],
            $planets,
            $shipsByPlanet,
            $attackCooldowns,
            [],
            [],
            1.0,
            2000,
            5,
            0
        );
        $this->assertSame(1, $res['metrics_delta']['attacks_aborted_low_loot']);
        $this->assertNotEmpty($res['logs']);
        $this->assertStringContainsString('low_loot', $res['logs'][0]);
        $this->assertSame(15, $shipsByPlanet[1][204], 'Ships released back');
        // Intel was refreshed with the fresh (low) snapshot even on abort.
        $this->assertArrayHasKey(99, $state['bot_quirks']['intel']);
        $this->assertSame(2000, $state['bot_quirks']['intel'][99]['captured_at']);
    }

    public function testHandlePlansAbortsOnSimDefenderWins(): void
    {
        if (!extension_loaded('mysqli') || !class_exists('mysqli')) {
            $this->markTestSkipped('mysqli extension not loaded on host PHP');
        }
        // Target with overwhelming defenses: 50 small cargos can carry
        // enough to clear the low_loot gate (capacity 50*5000=250k),
        // but 200 plasma turrets obliterate them in round 1. Sim must
        // declare defender winner.
        $db = $this->makePendingPlansDb(
            ['metal' => 10_000_000, 'crystal' => 10_000_000, 'deuterium' => 1_000_000],
            [],
            [406 => 200] // 200 plasma turrets
        );
        $plan = $this->makePlanForTest(99, 1, [202 => 50], 1000, 1100);
        $state = [
            'bot_user_id' => 1,
            '__synthetic' => true,
            'bot_quirks' => ['pending_attacks' => [99 => $plan]],
        ];
        $shipsByPlanet = [1 => [202 => 60]];
        $attackCooldowns = [];
        $planets = [['planet_id' => 1, 'planet_galaxy' => 1, 'planet_system' => 1, 'planet_planet' => 1]];
        // Full pricelist for both sides; combat needs price+caps to count units.
        $pricelist = [
            202 => ['metal' => 2000, 'crystal' => 2000, 'deuterium' => 0],
            406 => ['metal' => 50000, 'crystal' => 50000, 'deuterium' => 30000],
        ];
        $res = botAttackHandlePendingPlans(
            $db,
            'xgp_',
            $state,
            ['user_id' => 1],
            $planets,
            $shipsByPlanet,
            $attackCooldowns,
            [],
            $pricelist,
            1.0,
            2000,
            5,
            0
        );
        $this->assertSame(1, $res['metrics_delta']['attacks_aborted_sim_lose'] ?? 0);
        $this->assertNotEmpty($res['logs']);
        $this->assertStringContainsString('sim_lose', $res['logs'][0]);
    }

    public function testHandlePlansRespectsMaxAttacksThrottle(): void
    {
        if (!extension_loaded('mysqli') || !class_exists('mysqli')) {
            $this->markTestSkipped('mysqli extension not loaded on host PHP');
        }
        $db = $this->createMock(\mysqli::class);
        $db->expects($this->never())->method('query');
        $plan = $this->makePlanForTest(99, 1, [204 => 10], 1000, 1100);
        $state = [
            'bot_user_id' => 1,
            '__synthetic' => true,
            'bot_quirks' => ['pending_attacks' => [99 => $plan]],
        ];
        $shipsByPlanet = [1 => [204 => 30]];
        $attackCooldowns = [];
        // maxAttacks=2 and 2 already done this loop -> plan must wait.
        $res = botAttackHandlePendingPlans(
            $db,
            'xgp_',
            $state,
            ['user_id' => 1],
            [],
            $shipsByPlanet,
            $attackCooldowns,
            [],
            [],
            1.0,
            2000,
            2,
            2
        );
        $this->assertSame([], $res['logs']);
        $this->assertSame(0, $res['attacks_confirmed']);
        $this->assertArrayHasKey(99, $state['bot_quirks']['pending_attacks']);
    }

    public function testBotLogFileUsesDailySuffix(): void
    {
        $ts = strtotime('2024-06-10 15:30:00 UTC');
        $path = botLogFile('bot_alpha', $ts);
        $this->assertStringEndsWith('bot_alpha_2024-06-10.log', $path);
    }

    public function testBotLogsCleanupRemovesOldDatedFiles(): void
    {
        $dir = sys_get_temp_dir() . '/xgpro_botlog_' . bin2hex(random_bytes(4));
        $this->assertTrue(@mkdir($dir, 0777, true));
        touch($dir . '/u_2000-01-01.log');
        touch($dir . '/u_2099-12-31.log');

        $ref = strtotime('2026-05-11 12:00:00 UTC');
        $r = botLogsCleanupExpired(2, $dir, $ref);

        $this->assertSame(1, $r['deleted']);
        $this->assertFileDoesNotExist($dir . '/u_2000-01-01.log');
        $this->assertFileExists($dir . '/u_2099-12-31.log');

        @unlink($dir . '/u_2099-12-31.log');
        @rmdir($dir);
    }

    public function testBotLogsCleanupRemovesLegacyByMtime(): void
    {
        $dir = sys_get_temp_dir() . '/xgpro_botlog_' . bin2hex(random_bytes(4));
        $this->assertTrue(@mkdir($dir, 0777, true));
        $legacy = $dir . '/legacy.log';
        touch($legacy, strtotime('1999-01-01 12:00:00 UTC'));

        $ref = strtotime('2026-05-11 12:00:00 UTC');
        $r = botLogsCleanupExpired(2, $dir, $ref);

        $this->assertGreaterThanOrEqual(1, $r['deleted']);
        $this->assertFileDoesNotExist($legacy);
        @rmdir($dir);
    }

    // ---------------------------------------------------------------
    // cargo pressure signal (safety.php + decisions.php)
    // ---------------------------------------------------------------

    public function testBotCargoPressureMarkSetsState(): void
    {
        $state = ['bot_quirks' => []];
        $now = 1_700_000_000;
        botCargoPressureMark($state, $now);

        $this->assertArrayHasKey('cargo_pressure', $state['bot_quirks']);
        $cp = $state['bot_quirks']['cargo_pressure'];
        $this->assertSame(1, $cp['skips']);
        $this->assertSame($now, $cp['last_signal_at']);
        $this->assertSame($now + BOT_CARGO_PRESSURE_TTL_SECONDS, $cp['expires_at']);
    }

    public function testBotCargoPressureMarkAccumulatesWithinWindow(): void
    {
        $state = ['bot_quirks' => []];
        $now = 1_700_000_000;
        botCargoPressureMark($state, $now);
        // Second skip 10 minutes later, still inside the TTL window.
        botCargoPressureMark($state, $now + 600);

        $this->assertSame(2, $state['bot_quirks']['cargo_pressure']['skips']);
        $this->assertSame(
            $now + 600 + BOT_CARGO_PRESSURE_TTL_SECONDS,
            $state['bot_quirks']['cargo_pressure']['expires_at']
        );
    }

    public function testBotCargoPressureMarkResetsAfterExpiry(): void
    {
        $state = ['bot_quirks' => []];
        $first = 1_700_000_000;
        botCargoPressureMark($state, $first);
        // Skip 10 days later: previous window already expired -> reset.
        $later = $first + (BOT_CARGO_PRESSURE_TTL_SECONDS + 86_400);
        botCargoPressureMark($state, $later);
        $this->assertSame(1, $state['bot_quirks']['cargo_pressure']['skips']);
    }

    public function testBotCargoPressureActiveRespectsTtl(): void
    {
        $state = ['bot_quirks' => []];
        $now = 1_700_000_000;
        botCargoPressureMark($state, $now);

        $this->assertTrue(botCargoPressureActive($state, $now + 60));
        $this->assertFalse(
            botCargoPressureActive($state, $now + BOT_CARGO_PRESSURE_TTL_SECONDS + 1)
        );
    }

    public function testBotShipCandidatesIncludesBigCargoUnderPressure(): void
    {
        $planet = $this->makeFlatPlanet();
        $state = [
            'bot_quirks' => [],
            'bot_personality' => 'flotero',
        ];
        botCargoPressureMark($state, time());

        $cands = botShipCandidates(
            $planet,
            $state,
            'mil',
            'flotero',
            'main',
            ['eco' => 1.0, 'mil' => 1.0, 'def' => 1.0, 'tech' => 1.0]
        );

        $hasBigCargo = false;
        foreach ($cands as $c) {
            if (($c['value']['id'] ?? 0) === 203) {
                $hasBigCargo = true;

                break;
            }
        }
        $this->assertTrue($hasBigCargo, 'Big cargo (203) must be a candidate under cargo pressure');
    }

    public function testBotShipCandidatesOmitsBigCargoWithoutPressure(): void
    {
        $planet = $this->makeFlatPlanet();
        $state = [
            'bot_quirks' => [],
            'bot_personality' => 'flotero',
        ];

        $cands = botShipCandidates(
            $planet,
            $state,
            'mil',
            'flotero',
            'main',
            ['eco' => 1.0, 'mil' => 1.0, 'def' => 1.0, 'tech' => 1.0]
        );

        $hasBigCargo = false;
        foreach ($cands as $c) {
            if (($c['value']['id'] ?? 0) === 203) {
                $hasBigCargo = true;

                break;
            }
        }
        $this->assertFalse(
            $hasBigCargo,
            'Big cargo (203) must NOT be a candidate without cargo pressure'
        );
    }

    public function testCargoPressureMarkIncrementsLifetimeMetric(): void
    {
        $state = ['bot_quirks' => []];
        botCargoPressureMark($state, 1_700_000_000);
        botCargoPressureMark($state, 1_700_000_000 + 60);
        // Drop the active window: metric must keep growing across windows.
        botCargoPressureMark(
            $state,
            1_700_000_000 + BOT_CARGO_PRESSURE_TTL_SECONDS + 3600
        );
        $this->assertSame(
            3,
            (int) $state['bot_quirks']['metrics']['cargo_pressure_signals']
        );
    }

    public function testCargoPressureRecordQueuedAccumulates(): void
    {
        $state = ['bot_quirks' => []];
        botCargoPressureRecordQueued($state, 5);
        botCargoPressureRecordQueued($state, 3);
        botCargoPressureRecordQueued($state, 0); // ignored
        botCargoPressureRecordQueued($state, -1); // ignored
        $this->assertSame(
            8,
            (int) $state['bot_quirks']['metrics']['big_cargos_queued_under_pressure']
        );
    }

    public function testBotShipCandidatesGatesBigCargoOnPureMinerWithoutFleet(): void
    {
        // Pure-mining planet role + zero ships = no big cargo candidate
        // even with pressure: avoid wasting resources on a planet that
        // will never launch raids.
        $planet = $this->makeFlatPlanet();
        $state = ['bot_quirks' => [], 'bot_personality' => 'flotero'];
        botCargoPressureMark($state, time());

        $cands = botShipCandidates(
            $planet,
            $state,
            'eco',
            'flotero',
            'metal', // pure mining role
            ['eco' => 1.0, 'mil' => 1.0, 'def' => 1.0, 'tech' => 1.0]
        );

        $hasBigCargo = false;
        foreach ($cands as $c) {
            if (($c['value']['id'] ?? 0) === 203) {
                $hasBigCargo = true;

                break;
            }
        }
        $this->assertFalse(
            $hasBigCargo,
            'Big cargo must NOT be a candidate on a metal-role planet with no fleet'
        );
    }

    public function testBotShipCandidatesAllowsBigCargoOnMinerWhenAlreadyArmed(): void
    {
        // Pure-mining role BUT planet already has small cargos/fighters:
        // it's effectively a launch pad, so big cargo is allowed.
        $planet = $this->makeFlatPlanet();
        $planet['ship_small_cargo_ship'] = 10;
        $state = ['bot_quirks' => [], 'bot_personality' => 'flotero'];
        botCargoPressureMark($state, time());

        $cands = botShipCandidates(
            $planet,
            $state,
            'eco',
            'flotero',
            'metal',
            ['eco' => 1.0, 'mil' => 1.0, 'def' => 1.0, 'tech' => 1.0]
        );

        $hasBigCargo = false;
        foreach ($cands as $c) {
            if (($c['value']['id'] ?? 0) === 203) {
                $hasBigCargo = true;

                break;
            }
        }
        $this->assertTrue($hasBigCargo);
    }

    public function testStorageOverrideSoftenedUnderCargoPressure(): void
    {
        // Planet filled to ~95 % (above default 0.90 panic threshold,
        // below raised 0.98 panic threshold): without cargo pressure
        // the bot enqueues a storage upgrade; with pressure it doesn't.
        $planet = $this->makeStorageHeavyPlanet(0.95);
        $stateNoPressure = ['bot_quirks' => []];
        $stateWithPressure = ['bot_quirks' => []];
        botCargoPressureMark($stateWithPressure, time());

        $a = botStorageOverride($planet, $stateNoPressure);
        $b = botStorageOverride($planet, $stateWithPressure);

        $this->assertNotNull($a, 'Without pressure, 0.95 fill triggers override');
        $this->assertNull($b, 'With pressure, 0.95 fill must NOT trigger override');
    }

    public function testStorageOverrideStillFiresOnNearOverflowEvenUnderPressure(): void
    {
        // Above the raised 0.98 threshold the override must still fire,
        // otherwise the bot would lose hours of production while waiting
        // for a big-cargo build queue to drain.
        $planet = $this->makeStorageHeavyPlanet(0.99);
        $state = ['bot_quirks' => []];
        botCargoPressureMark($state, time());

        $r = botStorageOverride($planet, $state);
        $this->assertNotNull($r, 'Near-overflow must still fire under pressure');
    }

    /**
     * Builds a planet row with given fill ratios for each storage bin, at
     * a fixed store level so the resulting absolute values are predictable.
     *
     * @return array<string, mixed>
     */
    private function makeStorageHeavyPlanet(float $fill): array
    {
        // Fixed mid-game store level so maxStorageFromLevel returns a
        // stable number regardless of resourceMultiplier defaults.
        $resourceMultiplier = (float) ($GLOBALS['resourceMultiplier'] ?? 1.0);
        $maxAt = (int) maxStorageFromLevel(10, $resourceMultiplier);
        $absolute = (int) ($maxAt * $fill);

        return [
            'planet_id' => 1,
            'planet_metal' => $absolute,
            'planet_crystal' => $absolute,
            'planet_deuterium' => $absolute,
            'building_metal_store' => 10,
            'building_crystal_store' => 10,
            'building_deuterium_tank' => 10,
            'ship_small_cargo_ship' => 0,
            'ship_big_cargo_ship' => 0,
            'ship_light_fighter' => 0,
            'ship_heavy_fighter' => 0,
            'ship_cruiser' => 0,
            'ship_battleship' => 0,
            'ship_espionage_probe' => 0,
            'ship_mining_drill' => 0,
            'planet_b_hangar_id' => '',
        ];
    }

    public function testBotAttackFleetSlotsInfoComputesFree(): void
    {
        if (!extension_loaded('mysqli') || !class_exists('mysqli')) {
            $this->markTestSkipped('mysqli extension not loaded on host PHP');
        }
        $db = $this->createMock(\mysqli::class);
        $result = $this->createMock(\mysqli_result::class);
        $result->method('fetch_assoc')->willReturn(['c' => 3]);
        $result->method('free')->willReturn(null);
        $db->method('query')->willReturn($result);

        $info = botAttackFleetSlotsInfo($db, 'xgp_', 7, ['research_computer_technology' => 5]);

        $this->assertSame(3, $info['used']);
        $this->assertSame(6, $info['max']); // 1 + 5
        $this->assertSame(3, $info['free']);
    }

    public function testBotAttackFleetSlotsInfoClampsFreeAtZero(): void
    {
        if (!extension_loaded('mysqli') || !class_exists('mysqli')) {
            $this->markTestSkipped('mysqli extension not loaded on host PHP');
        }
        $db = $this->createMock(\mysqli::class);
        $result = $this->createMock(\mysqli_result::class);
        $result->method('fetch_assoc')->willReturn(['c' => 50]); // more fleets than max
        $result->method('free')->willReturn(null);
        $db->method('query')->willReturn($result);

        $info = botAttackFleetSlotsInfo($db, 'xgp_', 7, ['research_computer_technology' => 1]);

        $this->assertSame(0, $info['free']);
    }

    // ---------------------------------------------------------------
    // alliance_codec.php / alliance_policy.php
    // ---------------------------------------------------------------

    public function testBotAllianceParseRequirementsReturnsNullForPlainText(): void
    {
        $this->assertNull(botAllianceParseRequirements('Join us!'));
        $this->assertNull(botAllianceParseRequirements(''));
    }

    public function testBotAllianceParseRequirementsDetectsBotManagedJson(): void
    {
        $json = '{"bot_managed":true,"version":1,"soft_text":"Hi","requirements":{"min_total_points":100}}';
        $p = botAllianceParseRequirements($json);
        $this->assertIsArray($p);
        $this->assertTrue((bool) $p['bot_managed']);
        $this->assertSame('Hi', $p['soft_text']);
    }

    public function testBotAllianceEncodeRoundtrip(): void
    {
        $meta = [
            'bot_managed' => true,
            'version' => 1,
            'requirements' => ['min_total_points' => 500],
            'soft_text' => 'Hello',
        ];
        $enc = botAllianceEncodeRequirements($meta);
        $p = botAllianceParseRequirements($enc);
        $this->assertIsArray($p);
        $this->assertSame(500, (int) ($p['requirements']['min_total_points'] ?? 0));
    }

    public function testBotAllianceHumanReadableRequestUsesSoftText(): void
    {
        $j = botAllianceEncodeRequirements([
            'bot_managed' => true,
            'soft_text' => 'Shown',
            'requirements' => [],
        ]);
        $this->assertSame('Shown', botAllianceHumanReadableRequest($j, 'fallback'));
        $this->assertSame('plain', botAllianceHumanReadableRequest('plain', 'fallback'));
    }

    public function testBotAlliancePolicyAcceptsHumanApplicantWithPoints(): void
    {
        $pol = new BotAlliancePolicyRules();
        $dec = $pol->decideAcceptApplicant(
            ['personality' => 'cazador', 'alliance_meta' => null],
            [
                'is_bot_applicant' => false,
                'total_points' => 5000.0,
            ]
        );
        $this->assertSame('accept', $dec['action']);
    }

    public function testBotAlliancePolicyRejectsHumanWithZeroPoints(): void
    {
        $pol = new BotAlliancePolicyRules();
        $dec = $pol->decideAcceptApplicant(
            ['personality' => 'cazador', 'alliance_meta' => null],
            [
                'is_bot_applicant' => false,
                'total_points' => 0.0,
            ]
        );
        $this->assertSame('reject', $dec['action']);
    }

    public function testBotAlliancePolicyDecideCreateSkipsLowFounderScore(): void
    {
        $pol = new BotAlliancePolicyRules();
        $ctx = [
            'personality' => 'minero',
            'archetype' => 'turtle',
            'style' => 'granja',
            'stats' => [
                'total' => 500.0,
                'military' => 10.0,
                'ships' => 5.0,
                'research' => 0.0,
            ],
            'now' => 1000000,
        ];
        $dec = $pol->decideCreate($ctx);
        $this->assertSame('skip', $dec['action']);
    }

    public function testBotAllianceFilterCandidatesRespectsRejectedBy(): void
    {
        $now = 1000000;
        $ctx = [
            'personality' => 'flotero',
            'archetype' => 'balanced',
            'style' => 'granja',
            'stats' => [
                'total' => 50000.0,
                'military' => 10000.0,
                'ships' => 8000.0,
                'research' => 1000.0,
            ],
            'now' => $now,
            'rejected_by' => ['7' => $now + 3600],
        ];
        $rows = [
            [
                'alliance_id' => 7,
                'alliance_request' => '',
                'alliance_request_notallow' => 1,
                'member_count' => 3,
                'stat_total' => 10000,
                'stat_military' => 2000,
                'stat_research' => 100,
            ],
            [
                'alliance_id' => 8,
                'alliance_request' => '',
                'alliance_request_notallow' => 1,
                'member_count' => 2,
                'stat_total' => 8000,
                'stat_military' => 1500,
                'stat_research' => 50,
            ],
        ];
        $f = BotAlliancePolicyRules::filterCandidatesForApply($ctx, $rows, false);
        $this->assertCount(1, $f);
        $this->assertSame(8, (int) ($f[0]['alliance_id'] ?? 0));
    }

    public function testBotAllianceApplicantMeetsNumericRequirements(): void
    {
        $req = [
            'min_total_points' => 1000,
            'min_military_points' => 100,
            'min_fleet_points' => 50,
            'min_research_points' => 0,
        ];
        $app = [
            'total_points' => 2000.0,
            'military_points' => 200.0,
            'fleet_points' => 80.0,
            'research_points' => 10.0,
        ];
        $this->assertTrue(BotAlliancePolicyRules::applicantMeetsNumericRequirements($req, $app));
        $app['total_points'] = 500.0;
        $this->assertFalse(BotAlliancePolicyRules::applicantMeetsNumericRequirements($req, $app));
    }

    public function testBotAllianceDecideKickGrantsNewMemberGrace(): void
    {
        // A freshly-accepted member with very old user_onlinetime should
        // NOT be kicked: the grace period prevents the accept→kick→dissolve
        // pattern we observed in vivo.
        $pol = new BotAlliancePolicyRules();
        $now = 1_000_000_000;
        $ctx = [
            'now' => $now,
            'alliance_meta' => [
                'requirements' => [
                    'min_total_points' => 0,
                    'max_inactive_seconds' => 172800,
                ],
            ],
        ];
        $member = [
            'user_id' => 42,
            'user_ally_register_time' => $now - 60,
            'user_onlinetime' => $now - 999_999,
            'total_points' => 9999.0,
            'military_points' => 9999.0,
            'fleet_points' => 9999.0,
            'research_points' => 0.0,
        ];
        $dec = $pol->decideKick($ctx, $member);
        $this->assertSame('noop', $dec['action']);
        $this->assertSame('new_member_grace', $dec['reason']);
    }

    public function testBotAllianceDecideKickEnforcesInactivityAfterGrace(): void
    {
        $pol = new BotAlliancePolicyRules();
        $now = 1_000_000_000;
        $grace = defined('BOT_ALLIANCE_NEW_MEMBER_GRACE_SECONDS')
            ? (int) BOT_ALLIANCE_NEW_MEMBER_GRACE_SECONDS
            : 3600;
        $ctx = [
            'now' => $now,
            'alliance_meta' => [
                'requirements' => [
                    'min_total_points' => 0,
                    'max_inactive_seconds' => 172800,
                ],
            ],
        ];
        $member = [
            'user_id' => 42,
            'user_ally_register_time' => $now - $grace - 10,
            'user_onlinetime' => $now - 500_000,
            'total_points' => 1.0,
            'military_points' => 1.0,
            'fleet_points' => 1.0,
            'research_points' => 0.0,
        ];
        $dec = $pol->decideKick($ctx, $member);
        $this->assertSame('kick', $dec['action']);
        $this->assertSame('inactive', $dec['reason']);
    }

    public function testBotAllianceDecideDissolveYoungAllianceGrace(): void
    {
        // An alliance younger than BOT_ALLIANCE_DISSOLVE_GRACE_SECONDS must
        // not be auto-dissolved even if the founder is alone, otherwise no
        // bot-managed alliance would survive its first review cycle.
        $pol = new BotAlliancePolicyRules();
        $now = 1_000_000_000;
        $ctx = [
            'now' => $now,
            'member_count' => 1,
            'alliance_row' => ['alliance_register_time' => $now - 60],
        ];
        $dec = $pol->decideDissolve($ctx);
        $this->assertSame('noop', $dec['action']);
        $this->assertSame('young_alliance_grace', $dec['reason']);
    }

    public function testBotAllianceDecideDissolveAllowsOldEmptyAlliance(): void
    {
        $pol = new BotAlliancePolicyRules();
        $now = 1_000_000_000;
        $grace = defined('BOT_ALLIANCE_DISSOLVE_GRACE_SECONDS')
            ? (int) BOT_ALLIANCE_DISSOLVE_GRACE_SECONDS
            : 3600;
        $ctx = [
            'now' => $now,
            'member_count' => 1,
            'alliance_row' => ['alliance_register_time' => $now - $grace - 1000],
        ];
        $dec = $pol->decideDissolve($ctx);
        $this->assertSame('dissolve', $dec['action']);
        $this->assertSame('only_founder', $dec['reason']);
    }

    public function testBotAllianceDecideOwnershipSkipsHumanOwner(): void
    {
        $pol = new BotAlliancePolicyRules();
        $ctx = [
            'owner_is_bot' => false,
            'owner_inactive_seconds' => 999_999_999,
            'now' => 1_000_000_000,
        ];
        $dec = $pol->decideOwnershipTransfer($ctx, [['user_id' => 7, 'transfer_score' => 100.0]]);
        $this->assertSame('noop', $dec['action']);
        $this->assertSame('owner_not_bot', $dec['reason']);
    }

    public function testBotAllianceDecideOwnershipSkipsActiveOwner(): void
    {
        $pol = new BotAlliancePolicyRules();
        $threshold = defined('BOT_ALLIANCE_OWNER_INACTIVE_TRANSFER_SECONDS')
            ? (int) BOT_ALLIANCE_OWNER_INACTIVE_TRANSFER_SECONDS
            : 86400 * 14;
        $ctx = [
            'owner_is_bot' => true,
            'owner_inactive_seconds' => max(0, $threshold - 100),
            'now' => 1_000_000_000,
        ];
        $dec = $pol->decideOwnershipTransfer($ctx, [['user_id' => 7, 'transfer_score' => 100.0]]);
        $this->assertSame('noop', $dec['action']);
        $this->assertSame('owner_active', $dec['reason']);
    }

    public function testBotAllianceDecideOwnershipNoCandidate(): void
    {
        $pol = new BotAlliancePolicyRules();
        $threshold = defined('BOT_ALLIANCE_OWNER_INACTIVE_TRANSFER_SECONDS')
            ? (int) BOT_ALLIANCE_OWNER_INACTIVE_TRANSFER_SECONDS
            : 86400 * 14;
        $ctx = [
            'owner_is_bot' => true,
            'owner_inactive_seconds' => $threshold + 1,
            'now' => 1_000_000_000,
        ];
        $dec = $pol->decideOwnershipTransfer($ctx, []);
        $this->assertSame('noop', $dec['action']);
        $this->assertSame('no_candidate', $dec['reason']);
    }

    public function testBotAllianceDecideOwnershipPicksHighestScoreDeterministic(): void
    {
        $pol = new BotAlliancePolicyRules();
        $threshold = defined('BOT_ALLIANCE_OWNER_INACTIVE_TRANSFER_SECONDS')
            ? (int) BOT_ALLIANCE_OWNER_INACTIVE_TRANSFER_SECONDS
            : 86400 * 14;
        $ctx = [
            'owner_is_bot' => true,
            'owner_inactive_seconds' => $threshold + 100,
            'now' => 1_000_000_000,
        ];
        $members = [
            ['user_id' => 50, 'transfer_score' => 10.0],
            ['user_id' => 30, 'transfer_score' => 25.0],
            ['user_id' => 40, 'transfer_score' => 25.0],
        ];
        $dec = $pol->decideOwnershipTransfer($ctx, $members);
        $this->assertSame('transfer', $dec['action']);
        $this->assertSame('owner_inactive', $dec['reason']);
        // Tie on score: lowest user_id wins (deterministic across bots).
        $this->assertSame(30, $dec['params']['new_owner_id']);
    }

    public function testBotAllianceComputeTransferScoreRanksByStats(): void
    {
        $low = BotAlliancePolicyRules::computeTransferScore([
            'total_points' => 100,
            'military_points' => 10,
            'research_points' => 0,
            'user_ally_register_time' => 0,
        ]);
        $high = BotAlliancePolicyRules::computeTransferScore([
            'total_points' => 1_000_000,
            'military_points' => 100_000,
            'research_points' => 5_000,
            'user_ally_register_time' => 1_700_000_000,
        ]);
        $this->assertGreaterThan($low, $high);
    }

    public function testBotAllianceDecideDissolveBreaksGraceIfBetterAlt(): void
    {
        // Lone founder still inside the dissolve grace window but with
        // a clearly better alternative available -> must dissolve so
        // it can apply to the populated alliance.
        $pol = new BotAlliancePolicyRules();
        $now = 1_000_000_000;
        $grace = defined('BOT_ALLIANCE_DISSOLVE_GRACE_SECONDS')
            ? (int) BOT_ALLIANCE_DISSOLVE_GRACE_SECONDS
            : 3600;
        $minAlt = defined('BOT_ALLIANCE_BETTER_ALT_MIN_MEMBERS')
            ? (int) BOT_ALLIANCE_BETTER_ALT_MIN_MEMBERS
            : 3;
        $ctx = [
            'now' => $now,
            'member_count' => 1,
            'alliance_row' => ['alliance_register_time' => $now - intdiv($grace, 2)],
            'better_alternative_member_count' => $minAlt + 5,
        ];
        $dec = $pol->decideDissolve($ctx);
        $this->assertSame('dissolve', $dec['action']);
        $this->assertSame('better_alternative_in_grace', $dec['reason']);
    }

    public function testBotAllianceDecideDissolveKeepsGraceIfWeakAlt(): void
    {
        $pol = new BotAlliancePolicyRules();
        $now = 1_000_000_000;
        $grace = defined('BOT_ALLIANCE_DISSOLVE_GRACE_SECONDS')
            ? (int) BOT_ALLIANCE_DISSOLVE_GRACE_SECONDS
            : 3600;
        $ctx = [
            'now' => $now,
            'member_count' => 1,
            'alliance_row' => ['alliance_register_time' => $now - intdiv($grace, 2)],
            'better_alternative_member_count' => 1,
        ];
        $dec = $pol->decideDissolve($ctx);
        $this->assertSame('noop', $dec['action']);
        $this->assertSame('young_alliance_grace', $dec['reason']);
    }

    public function testBotAllianceAntiReboundActiveJustAfterDissolve(): void
    {
        $now = 1_000_000_000;
        $a = ['last_dissolved_at' => $now - 10];
        $this->assertTrue(BotAlliancePolicyRules::isWithinAntiRebound($a, $now));
    }

    public function testBotAllianceAntiReboundExpired(): void
    {
        $now = 1_000_000_000;
        $ttl = defined('BOT_ALLIANCE_ANTI_REBOUND_SECONDS')
            ? (int) BOT_ALLIANCE_ANTI_REBOUND_SECONDS
            : 3600;
        $a = ['last_dissolved_at' => $now - ($ttl + 1)];
        $this->assertFalse(BotAlliancePolicyRules::isWithinAntiRebound($a, $now));
    }

    public function testBotAllianceAntiReboundUnsetIsFalse(): void
    {
        $this->assertFalse(BotAlliancePolicyRules::isWithinAntiRebound([], 1_000_000_000));
        $this->assertFalse(BotAlliancePolicyRules::isWithinAntiRebound(['last_dissolved_at' => 0], 1_000_000_000));
    }

    public function testBotAllyLogisticsParseHumanRequestNullWithoutMarker(): void
    {
        $this->assertNull(botAllyLogisticsParseHumanRequest('Solo texto sin marcador.'));
    }

    public function testBotAllyLogisticsParseHumanRequestParsesTriple(): void
    {
        $p = botAllyLogisticsParseHumanRequest('[ALLY_REQ] metal=1000 crystal=2000 deut=3');
        $this->assertNotNull($p);
        $this->assertSame(1000, $p['metal']);
        $this->assertSame(2000, $p['crystal']);
        $this->assertSame(3, $p['deuterium']);
    }

    public function testBotAllyLogisticsParseHumanRequestDeuteriumAlias(): void
    {
        $p = botAllyLogisticsParseHumanRequest("Hola\n[ALLY_REQ] deuterium=500 metal=1");
        $this->assertNotNull($p);
        $this->assertSame(500, $p['deuterium']);
        $this->assertSame(1, $p['metal']);
    }

    // ---------------------------------------------------------------
    // diplomacy.php / acs_attack.php
    // ---------------------------------------------------------------

    public function testBotDiplomacyNormalisePairSwapsLargerLeft(): void
    {
        $this->assertSame([3, 7], botDiplomacyNormalisePair(7, 3));
        $this->assertSame([3, 7], botDiplomacyNormalisePair(3, 7));
    }

    public function testBotDiplomacyNormalisePairNullForInvalid(): void
    {
        $this->assertNull(botDiplomacyNormalisePair(0, 1));
        $this->assertNull(botDiplomacyNormalisePair(1, 0));
        $this->assertNull(botDiplomacyNormalisePair(5, 5));
        $this->assertNull(botDiplomacyNormalisePair(-1, 2));
    }

    public function testBotDiplomacyAllianceIsLosingSideOfWarUsesDamageThreshold(): void
    {
        // alliance 10 is on side A: dealt 30k, received 100k → threshold max(50k, 60k)=60k → losing.
        $this->assertTrue(botDiplomacyAllianceIsLosingSideOfWar(10, 10, 20, 30_000, 100_000));
        // Same row from alliance 20 perspective: dealt 100k, received 30k → not losing.
        $this->assertFalse(botDiplomacyAllianceIsLosingSideOfWar(20, 10, 20, 30_000, 100_000));
    }

    public function testBotAcsBuildLeaderShipMixReturnsNullWhenEmpty(): void
    {
        $this->assertNull(botAcsBuildLeaderShipMix([]));
        $this->assertNull(botAcsBuildLeaderShipMix([
            208 => 5,
            209 => 5,
            210 => 10,
            212 => 100,
        ]));
    }

    public function testBotAcsBuildLeaderShipMixIncludesAtLeastOneCargo(): void
    {
        $mix = botAcsBuildLeaderShipMix([
            204 => 200,
            205 => 100,
            202 => 30,
        ]);
        $this->assertNotNull($mix);
        $this->assertArrayHasKey(204, $mix);
        $this->assertArrayHasKey(202, $mix);
        $this->assertGreaterThan(0, $mix[202]);
    }

    public function testBotAcsBuildLeaderShipMixPrefersBigCargo(): void
    {
        $mix = botAcsBuildLeaderShipMix([
            207 => 50,
            203 => 10,
            202 => 50,
        ]);
        $this->assertNotNull($mix);
        $this->assertArrayHasKey(203, $mix);
        $this->assertArrayNotHasKey(202, $mix);
    }

    public function testBotAcsValueOfShipMixSumsMetalCrystal(): void
    {
        $pricelist = [
            204 => ['metal' => 3000, 'crystal' => 1000, 'deuterium' => 0],
            202 => ['metal' => 2000, 'crystal' => 2000, 'deuterium' => 0],
        ];
        $val = botAcsValueOfShipMix([204 => 10, 202 => 5], $pricelist);
        $this->assertSame((3000 + 1000) * 10 + (2000 + 2000) * 5, $val);
    }

    // ---------------------------------------------------------------
    // diplomacy decay
    // ---------------------------------------------------------------

    public function testDiplomacyComputeDecayZeroWhenNoElapsed(): void
    {
        $now = 1000;
        $this->assertSame(500, botDiplomacyComputeDecay(500, $now, $now, 50_000));
        $this->assertSame(500, botDiplomacyComputeDecay(500, $now + 10, $now, 50_000));
    }

    public function testDiplomacyComputeDecayLinearAfterOneHour(): void
    {
        $now = 10_000;
        $last = $now - 3600;
        $this->assertSame(450_000, botDiplomacyComputeDecay(500_000, $last, $now, 50_000));
    }

    public function testDiplomacyComputeDecayClampsToZero(): void
    {
        $now = 1_000_000;
        $last = $now - 3600 * 100;
        $this->assertSame(0, botDiplomacyComputeDecay(10_000, $last, $now, 50_000));
    }

    public function testDiplomacyComputeDecayIgnoresZeroRate(): void
    {
        $now = 10_000;
        $last = $now - 7200;
        $this->assertSame(123, botDiplomacyComputeDecay(123, $last, $now, 0));
    }

    public function testAttackReorderEnemiesFirstPullsEnemiesToFront(): void
    {
        $candidates = [
            ['planet_user_id' => 10, 'tag' => 'a'],
            ['planet_user_id' => 20, 'tag' => 'b'],
            ['planet_user_id' => 30, 'tag' => 'c'],
            ['planet_user_id' => 20, 'tag' => 'd'],
        ];
        $reordered = botAttackReorderEnemiesFirst($candidates, [20]);
        $this->assertSame('b', $reordered[0]['tag']);
        $this->assertSame('d', $reordered[1]['tag']);
        $this->assertSame('a', $reordered[2]['tag']);
        $this->assertSame('c', $reordered[3]['tag']);
    }

    public function testAttackReorderEnemiesFirstHandlesEmptyInputs(): void
    {
        $this->assertSame([], botAttackReorderEnemiesFirst([], [1, 2]));
        $list = [['planet_user_id' => 1]];
        $this->assertSame($list, botAttackReorderEnemiesFirst($list, []));
    }

    public function testDiplomacyParseMarkerExtractsKeyValues(): void
    {
        $text = "Some prose.\n[DIPLO_PEACE from_ally=3 peer_ally=7 offered=15000]";
        $parsed = botDiplomacyParseMarker($text, 'DIPLO_PEACE');
        $this->assertIsArray($parsed);
        $this->assertSame(3, $parsed['from_ally']);
        $this->assertSame(7, $parsed['peer_ally']);
        $this->assertSame(15000, $parsed['offered']);
    }

    public function testDiplomacyParseMarkerReturnsNullWhenMissing(): void
    {
        $this->assertNull(botDiplomacyParseMarker('hello world', 'DIPLO_NAP'));
    }

    public function testDiplomacyParseMarkerDoesNotPickWrongMarker(): void
    {
        $text = '[DIPLO_NAP from_ally=5 duration=3600]';
        $this->assertNull(botDiplomacyParseMarker($text, 'DIPLO_PEACE'));
    }

    /**
     * Minimal planet row covering the columns botShipCandidates touches
     * (mines, every ship column it can decide on). Numeric fields are 0
     * unless we want to drive a specific decision.
     *
     * @return array<string, mixed>
     */
    private function makeFlatPlanet(): array
    {
        return [
            'planet_id' => 1,
            'building_metal_mine' => 5,
            'building_crystal_mine' => 4,
            'building_deuterium_sintetizer' => 3,
            'building_hangar' => 1,
            'building_robot_factory' => 2,
            'building_nano_factory' => 0,
            'planet_b_building_id' => '0',
            'ship_small_cargo_ship' => 0,
            'ship_big_cargo_ship' => 0,
            'ship_light_fighter' => 0,
            'ship_heavy_fighter' => 0,
            'ship_cruiser' => 0,
            'ship_battleship' => 0,
            'ship_espionage_probe' => 0,
            'ship_mining_drill' => 0,
            'planet_b_hangar_id' => '',
        ];
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Builds a planet row with given fill ratios for each storage bin, at
     * a fixed store level so the resulting absolute values are predictable.
     *
     * @param float $metalFill   fraction in [0, 1]
     * @param float $crystalFill fraction in [0, 1]
     * @param float $deutFill    fraction in [0, 1]
     * @return array<string, mixed>
     */
    private function makeStorageTestPlanet(float $metalFill, float $crystalFill, float $deutFill): array
    {
        $level = 6;
        $cap = maxStorageFromLevel($level, 1.0);

        return [
            'building_metal_store' => $level,
            'building_crystal_store' => $level,
            'building_deuterium_tank' => $level,
            'planet_metal' => (int) floor($cap * $metalFill),
            'planet_crystal' => (int) floor($cap * $crystalFill),
            'planet_deuterium' => (int) floor($cap * $deutFill),
        ];
    }
}
