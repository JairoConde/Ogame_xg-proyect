<?php

declare(strict_types=1);

namespace App\Test;

use App\Core\BaseController;
use App\Core\Enumerators\DefensesEnumerator as Defenses;
use App\Core\Enumerators\ShipsEnumerator as Ships;
use App\Core\Objects;
use App\Http\Controllers\Game\ShipyardController;
use PHPUnit\Framework\TestCase;

/**
 * @covers App\Http\Controllers\Game\ShipyardController
 */
class ShipyardControllerTest extends TestCase
{
    /**
     * Helper to set a property on the instance via reflection.
     */
    private function setProperty(object $instance, string $property, $value): void
    {
        $reflection = new \ReflectionProperty(ShipyardController::class, $property);
        $reflection->setAccessible(true);
        $reflection->setValue($instance, $value);
    }

    /**
     * Helper to invoke a private/protected method via reflection.
     *
     * @return mixed
     */
    private function invokeMethod(object $instance, string $methodName, array $args = [])
    {
        $reflection = new \ReflectionMethod(ShipyardController::class, $methodName);
        $reflection->setAccessible(true);

        return $reflection->invoke($instance, ...$args);
    }

    /**
     * Create a ShipyardController mock with planet data and required
     * dependencies preset.
     */
    private function createControllerWithPlanet(array $planet): object
    {
        $instance = $this->getMockBuilder(ShipyardController::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $this->setProperty($instance, 'planet', $planet);

        return $instance;
    }

    // --- getShieldDomeItemLimit tests ---

    public function testGetShieldDomeItemLimitReturnsZeroForNonShieldItem(): void
    {
        $instance = $this->createControllerWithPlanet([]);

        $result = $this->invokeMethod($instance, 'getShieldDomeItemLimit', [999]);
        $this->assertIsInt($result);
        $this->assertSame(0, $result);
    }

    public function testGetShieldDomeItemLimitReturnsOneForSmallShieldDome(): void
    {
        $instance = $this->createControllerWithPlanet([
            'defense_small_shield_dome' => 0,
            'planet_b_hangar_id' => '',
        ]);

        // getShieldDomeItemLimit needs objects to call isShieldDomeAvailable
        $objectsMock = $this->createMock(Objects::class);
        $objectsMock->method('getObjects')->willReturnCallback(
            fn (?int $id = null) => match ($id) {
                Defenses::defense_small_shield_dome => 'defense_small_shield_dome',
                Defenses::defense_large_shield_dome => 'defense_large_shield_dome',
                default => ''
            }
        );

        $objectsProp = new \ReflectionProperty(BaseController::class, 'objects');
        $objectsProp->setAccessible(true);
        $objectsProp->setValue($instance, $objectsMock);

        $result = $this->invokeMethod(
            $instance,
            'getShieldDomeItemLimit',
            [Defenses::defense_small_shield_dome]
        );
        $this->assertIsInt($result);
        $this->assertSame(1, $result);
    }

    public function testGetShieldDomeItemLimitReturnsOneForLargeShieldDome(): void
    {
        $instance = $this->createControllerWithPlanet([
            'defense_large_shield_dome' => 0,
            'planet_b_hangar_id' => '',
        ]);

        $objectsMock = $this->createMock(Objects::class);
        $objectsMock->method('getObjects')->willReturnCallback(
            fn (?int $id = null) => match ($id) {
                Defenses::defense_small_shield_dome => 'defense_small_shield_dome',
                Defenses::defense_large_shield_dome => 'defense_large_shield_dome',
                default => ''
            }
        );

        $objectsProp = new \ReflectionProperty(BaseController::class, 'objects');
        $objectsProp->setAccessible(true);
        $objectsProp->setValue($instance, $objectsMock);

        $result = $this->invokeMethod(
            $instance,
            'getShieldDomeItemLimit',
            [Defenses::defense_large_shield_dome]
        );
        $this->assertIsInt($result);
        $this->assertSame(1, $result);
    }

    // --- buildItemsQueue tests ---

    public function testBuildItemsQueueReturnsEmptyStringForEmptyQueue(): void
    {
        $instance = $this->createControllerWithPlanet([
            'planet_b_hangar_id' => '',
        ]);

        $result = $this->invokeMethod($instance, 'buildItemsQueue');
        $this->assertSame('', $result);
    }

    public function testBuildItemsQueueReturnsEmptyStringForSemicolonOnlyQueue(): void
    {
        $instance = $this->createControllerWithPlanet([
            'planet_b_hangar_id' => ';',
        ]);

        $result = $this->invokeMethod($instance, 'buildItemsQueue');
        $this->assertSame('', $result);
    }

    // --- processQueueToArray tests ---

    public function testProcessQueueToArrayReturnsEmptyArrayForEmptyQueue(): void
    {
        $instance = $this->createControllerWithPlanet([
            'planet_b_hangar_id' => '',
        ]);

        $result = $this->invokeMethod($instance, 'processQueueToArray');
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testProcessQueueToArrayParsesSingleItem(): void
    {
        $instance = $this->createControllerWithPlanet([
            'planet_b_hangar_id' => '204,5',
        ]);

        $result = $this->invokeMethod($instance, 'processQueueToArray');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('204', $result);
        $this->assertSame(5, $result['204']);
    }

    public function testProcessQueueToArrayParsesMultipleItems(): void
    {
        $instance = $this->createControllerWithPlanet([
            'planet_b_hangar_id' => '204,5;205,10;206,3',
        ]);

        $result = $this->invokeMethod($instance, 'processQueueToArray');
        $this->assertCount(3, $result);
        $this->assertSame(5, $result['204']);
        $this->assertSame(10, $result['205']);
        $this->assertSame(3, $result['206']);
    }

    public function testProcessQueueToArrayAccumulatesSameItemId(): void
    {
        $instance = $this->createControllerWithPlanet([
            'planet_b_hangar_id' => '204,5;204,7',
        ]);

        $result = $this->invokeMethod($instance, 'processQueueToArray');
        $this->assertCount(1, $result);
        $this->assertSame(12, $result['204']);
    }

    public function testProcessQueueToArrayReturnsEmptyArrayForSemicolonOnly(): void
    {
        $instance = $this->createControllerWithPlanet([
            'planet_b_hangar_id' => ';',
        ]);

        $result = $this->invokeMethod($instance, 'processQueueToArray');
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testProcessQueueToArraySkipsEmptyEntries(): void
    {
        $instance = $this->createControllerWithPlanet([
            'planet_b_hangar_id' => '204,5;;206,3',
        ]);

        $result = $this->invokeMethod($instance, 'processQueueToArray');
        $this->assertCount(2, $result);
        $this->assertSame(5, $result['204']);
        $this->assertSame(3, $result['206']);
    }

    // --- getMiningDrillItemLimit tests ---

    public function testGetMiningDrillItemLimitReturnsHardCapValue(): void
    {
        $instance = $this->createControllerWithPlanet([
            'planet_b_hangar_id' => '',
            'ship_mining_drill' => 10,
        ]);

        $result = $this->invokeMethod($instance, 'getMiningDrillItemLimit');
        $this->assertIsInt($result);
        $this->assertSame(990, $result); // 1000 - 10 - 0
    }

    public function testGetMiningDrillItemLimitReturnsZeroWhenAtCap(): void
    {
        $instance = $this->createControllerWithPlanet([
            'planet_b_hangar_id' => '',
            'ship_mining_drill' => 1000,
        ]);

        $result = $this->invokeMethod($instance, 'getMiningDrillItemLimit');
        $this->assertIsInt($result);
        $this->assertSame(0, $result); // 1000 - 1000 - 0
    }

    public function testGetMiningDrillItemLimitAccountsForQueue(): void
    {
        $instance = $this->createControllerWithPlanet([
            'planet_b_hangar_id' => Ships::ship_mining_drill . ',5',
            'ship_mining_drill' => 990,
        ]);

        $result = $this->invokeMethod($instance, 'getMiningDrillItemLimit');
        $this->assertIsInt($result);
        $this->assertSame(5, $result); // 1000 - 990 - 5
    }

    public function testGetMiningDrillItemLimitReturnsZeroWhenOverCap(): void
    {
        $instance = $this->createControllerWithPlanet([
            'planet_b_hangar_id' => '',
            'ship_mining_drill' => 1500,
        ]);

        $result = $this->invokeMethod($instance, 'getMiningDrillItemLimit');
        $this->assertIsInt($result);
        $this->assertSame(0, $result); // max(0, 1000 - 1500 - 0)
    }
}
