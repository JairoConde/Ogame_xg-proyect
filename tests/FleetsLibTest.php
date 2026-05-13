<?php

declare(strict_types=1);

namespace App\Test;

use App\Libraries\FleetsLib;
use PHPUnit\Framework\TestCase;

/**
 * @covers App\Libraries\FleetsLib
 */
class FleetsLibTest extends TestCase
{
    /**
     * @covers ::getFleetShipsArray
     * @dataProvider getFleetShipsArrayProvider
     */
    public function testGetFleetShipsArray(string $input, array $expected): void
    {
        $this->assertSame($expected, FleetsLib::getFleetShipsArray($input));
    }

    /**
     * @return array<string, array{string, array}>
     */
    public function getFleetShipsArrayProvider(): array
    {
        return [
            'empty string returns empty array' => [
                '',
                [],
            ],
            'non-serialized string returns empty array' => [
                '202,90;203,5;',
                [],
            ],
            'serialized empty array' => [
                serialize([]),
                [],
            ],
            'single ship' => [
                serialize([202 => 10]),
                [202 => 10],
            ],
            'multiple ships' => [
                serialize([202 => 90, 203 => 5, 204 => 20]),
                [202 => 90, 203 => 5, 204 => 20],
            ],
            'malformed serialized returns empty array' => [
                'a:1:{i:202;s:2:"10"',  // truncated - missing closing }
                [],
            ],
        ];
    }

    /**
     * @covers ::getFleetShipsArray
     */
    public function testGetFleetShipsArrayIgnoresNonArrayUnserialize(): void
    {
        // If unserialize returns a non-array (e.g. integer, string), should return []
        $result = FleetsLib::getFleetShipsArray(serialize('some_string'));
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * @covers ::setFleetShipsArray
     */
    public function testSetFleetShipsArray(): void
    {
        $this->assertSame(
            serialize([202 => 10, 203 => 5]),
            FleetsLib::setFleetShipsArray([202 => 10, 203 => 5])
        );
    }

    /**
     * @covers ::getMaxExpeditions
     * @dataProvider getMaxExpeditionsProvider
     */
    public function testGetMaxExpeditions(int $astrophysics, int $expected): void
    {
        $this->assertSame($expected, FleetsLib::getMaxExpeditions($astrophysics));
    }

    /**
     * @return array<string, array{int, int}>
     */
    public function getMaxExpeditionsProvider(): array
    {
        return [
            'level 0' => [0, 0],
            'level 1' => [1, 1],
            'level 4' => [4, 2],
            'level 9' => [9, 3],
            'level 16' => [16, 4],
        ];
    }

    /**
     * @covers ::getMaxColonies
     * @dataProvider getMaxColoniesProvider
     */
    public function testGetMaxColonies(int $astrophysics, int $expected): void
    {
        $this->assertSame($expected, FleetsLib::getMaxColonies($astrophysics));
    }

    /**
     * @return array<string, array{int, int}>
     */
    public function getMaxColoniesProvider(): array
    {
        return [
            'level 0' => [0, 0],
            'level 1' => [1, 1],
            'level 2' => [2, 1],
            'level 3' => [3, 2],
            'level 4' => [4, 2],
            'level 10' => [10, 5],
        ];
    }

    /**
     * @covers ::getMaxStorage
     * @dataProvider getMaxStorageProvider
     */
    public function testGetMaxStorage(int $shipStorage, int $hyperspaceTech, int $cargoOpt, ?int $shipId, int $expected): void
    {
        $this->assertSame($expected, FleetsLib::getMaxStorage($shipStorage, $hyperspaceTech, $cargoOpt, $shipId));
    }

    /**
     * @return array<string, array{int, int, int, int|null, int}>
     */
    public function getMaxStorageProvider(): array
    {
        return [
            'base storage no tech no optimisation' => [5000, 0, 0, null, 5000],
            'with hyperspace tech' => [5000, 10, 0, null, 7500],
            'with hyperspace tech rounding' => [5000, 1, 0, null, 5250],
            'small cargo with cargo optimisation' => [5000, 0, 5, 202, 7500],
            'big cargo with cargo optimisation' => [25000, 0, 5, 203, 37500],
            'non-cargo ship ignores optimisation' => [1000, 5, 10, 207, 1250],
            'all combined' => [5000, 10, 5, 202, 10000],
        ];
    }

    /**
     * @covers ::isFleetReturning
     * @dataProvider isFleetReturningProvider
     */
    public function testIsFleetReturning($fleetMess, bool $expected): void
    {
        $this->assertSame($expected, FleetsLib::isFleetReturning($fleetMess));
    }

    /**
     * @return array<string, array{mixed, bool}>
     */
    public function isFleetReturningProvider(): array
    {
        return [
            'mess 1 returns true' => [1, true],
            'mess "1" returns true' => ['1', true],
            'mess 0 returns false' => [0, false],
            'mess "0" returns false' => ['0', false],
            'mess null returns false' => [null, false],
        ];
    }

    /**
     * @covers ::hasResources
     * @dataProvider hasResourcesProvider
     */
    public function testHasResources(array $fleet, bool $expected): void
    {
        $this->assertSame($expected, FleetsLib::hasResources($fleet));
    }

    /**
     * @return array<string, array{array, bool}>
     */
    public function hasResourcesProvider(): array
    {
        return [
            'all resources zero' => [
                ['fleet_resource_metal' => 0, 'fleet_resource_crystal' => 0, 'fleet_resource_deuterium' => 0],
                false,
            ],
            'metal non-zero' => [
                ['fleet_resource_metal' => 100, 'fleet_resource_crystal' => 0, 'fleet_resource_deuterium' => 0],
                true,
            ],
            'crystal non-zero' => [
                ['fleet_resource_metal' => 0, 'fleet_resource_crystal' => 50, 'fleet_resource_deuterium' => 0],
                true,
            ],
            'deuterium non-zero' => [
                ['fleet_resource_metal' => 0, 'fleet_resource_crystal' => 0, 'fleet_resource_deuterium' => 25],
                true,
            ],
            'all resources non-zero' => [
                ['fleet_resource_metal' => 100, 'fleet_resource_crystal' => 50, 'fleet_resource_deuterium' => 25],
                true,
            ],
        ];
    }

    /**
     * @covers ::targetDistance
     * @dataProvider targetDistanceProvider
     */
    public function testTargetDistance(int $origG, int $destG, int $origS, int $destS, int $origP, int $destP, int $expected): void
    {
        $this->assertSame($expected, FleetsLib::targetDistance($origG, $destG, $origS, $destS, $origP, $destP));
    }

    /**
     * @return array<string, array{int, int, int, int, int, int, int}>
     */
    public function targetDistanceProvider(): array
    {
        return [
            'same coordinates' => [1, 1, 1, 1, 1, 1, 5],
            'different galaxy' => [1, 2, 1, 1, 1, 1, 20000],
            'same galaxy different system' => [1, 1, 1, 3, 1, 1, 2890],
            'same system different planet' => [1, 1, 1, 1, 3, 8, 1025],
            'default distance' => [1, 1, 1, 1, 1, 1, 5],
        ];
    }

    /**
     * @covers ::getFleetMissionName
     * @dataProvider getFleetMissionNameProvider
     */
    public function testGetFleetMissionName(int $mission, string $expected): void
    {
        $this->assertSame($expected, FleetsLib::getFleetMissionName($mission));
    }

    /**
     * @return array<string, array{int, string}>
     */
    public function getFleetMissionNameProvider(): array
    {
        return [
            'attack' => [1, 'Attack'],
            'acs' => [2, 'ACS'],
            'transport' => [3, 'Transport'],
            'deploy' => [4, 'Deploy'],
            'hold' => [5, 'Hold'],
            'spy' => [6, 'Spy'],
            'colonize' => [7, 'Colonize'],
            'recycle' => [8, 'Recycle'],
            'destroy' => [9, 'Destroy'],
            'missile' => [10, 'Missile'],
            'expedition' => [15, 'Expedition'],
            'unknown mission returns Unknown' => [99, 'Unknown'],
        ];
    }

    /**
     * Test that fleetShipsPopup method exists and has the correct signature.
     *
     * @covers ::fleetShipsPopup
     */
    public function testFleetShipsPopupMethodExists(): void
    {
        $reflection = new \ReflectionMethod(FleetsLib::class, 'fleetShipsPopup');

        $this->assertTrue($reflection->isPublic());
        $this->assertTrue($reflection->isStatic());

        $params = $reflection->getParameters();
        $this->assertCount(4, $params);

        $this->assertSame('fleetRow', $params[0]->getName());
        $this->assertSame('text', $params[1]->getName());
        $this->assertSame('fleet_type', $params[2]->getName());
        $this->assertSame('current_user', $params[3]->getName());
        $this->assertTrue($params[3]->isDefaultValueAvailable());
        $this->assertSame([], $params[3]->getDefaultValue());

        // Method has declared return type string
        $this->assertTrue($reflection->hasReturnType());
        $this->assertSame('string', $reflection->getReturnType()->getName());
    }
}
