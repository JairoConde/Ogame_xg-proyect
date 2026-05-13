<?php

declare(strict_types=1);

namespace App\Test;

use App\Core\BaseController;
use App\Http\Controllers\Game\AllianceController;
use PHPUnit\Framework\TestCase;

/**
 * @covers App\Http\Controllers\Game\AllianceController
 */
class AllianceControllerTest extends TestCase
{
    /**
     * Helper to invoke a private/protected method on an instance via reflection.
     *
     * @return mixed
     */
    private function invokeMethodOnInstance(object $instance, string $methodName, array $args = [])
    {
        $reflection = new \ReflectionMethod(AllianceController::class, $methodName);
        $reflection->setAccessible(true);

        return $reflection->invoke($instance, ...$args);
    }

    /**
     * Create a mock that has all class properties available for reflection.
     */
    private function makeControllerMock(): object
    {
        return $this->getMockBuilder(AllianceController::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
    }

    // --- getAllianceId tests ---

    public function testGetAllianceIdReturnsIntWhenNoInputAndNoUserData(): void
    {
        $instance = $this->makeControllerMock();
        $userProp = new \ReflectionProperty(AllianceController::class, 'user');
        $userProp->setAccessible(true);
        $userProp->setValue($instance, [
            'user_ally_id' => 0,
            'user_ally_request' => 0,
        ]);

        $result = $this->invokeMethodOnInstance($instance, 'getAllianceId');
        $this->assertIsInt($result);
        $this->assertSame(0, $result);
    }

    public function testGetAllianceIdReturnsUserAllyIdWhenSetAndNoGetParam(): void
    {
        $instance = $this->makeControllerMock();
        $userProp = new \ReflectionProperty(AllianceController::class, 'user');
        $userProp->setAccessible(true);
        $userProp->setValue($instance, [
            'user_ally_id' => 42,
            'user_ally_request' => 0,
        ]);

        $result = $this->invokeMethodOnInstance($instance, 'getAllianceId');
        $this->assertIsInt($result);
        $this->assertSame(42, $result);
    }

    public function testGetAllianceIdReturnsUserAllyRequestWhenSet(): void
    {
        $instance = $this->makeControllerMock();
        $userProp = new \ReflectionProperty(AllianceController::class, 'user');
        $userProp->setAccessible(true);
        $userProp->setValue($instance, [
            'user_ally_id' => 0,
            'user_ally_request' => 99,
        ]);

        $result = $this->invokeMethodOnInstance($instance, 'getAllianceId');
        $this->assertIsInt($result);
        $this->assertSame(99, $result);
    }

    public function testGetAllianceIdPrecedenceUserAllyOverRequest(): void
    {
        $instance = $this->makeControllerMock();
        $userProp = new \ReflectionProperty(AllianceController::class, 'user');
        $userProp->setAccessible(true);
        $userProp->setValue($instance, [
            'user_ally_id' => 42,
            'user_ally_request' => 99,
        ]);

        $result = $this->invokeMethodOnInstance($instance, 'getAllianceId');
        $this->assertIsInt($result);
        $this->assertSame(42, $result);
    }

    // --- buildCircularBlock tests ---

    public function testBuildCircularBlockReturnsEmptyArrayWhenNoAccess(): void
    {
        $allianceMock = $this->createMock(\App\Libraries\Alliance\Alliances::class);
        $allianceMock->method('hasAccess')->willReturn(false);

        $instance = $this->makeControllerMock();

        $allyProp = new \ReflectionProperty(AllianceController::class, 'alliance');
        $allyProp->setAccessible(true);
        $allyProp->setValue($instance, $allianceMock);

        $result = $this->invokeMethodOnInstance($instance, 'buildCircularBlock');
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testBuildCircularBlockReturnsArrayWithKeysWhenHasAccess(): void
    {
        $allianceMock = $this->createMock(\App\Libraries\Alliance\Alliances::class);
        $allianceMock->method('hasAccess')->willReturn(true);

        $instance = $this->makeControllerMock();

        $allyProp = new \ReflectionProperty(AllianceController::class, 'alliance');
        $allyProp->setAccessible(true);
        $allyProp->setValue($instance, $allianceMock);

        $langsMock = $this->createMock(\CiLang::class);
        $langsMock->method('line')->willReturn('Circular message');

        $langsProp = new \ReflectionProperty(BaseController::class, 'langs');
        $langsProp->setAccessible(true);
        $langsProp->setValue($instance, $langsMock);

        $result = $this->invokeMethodOnInstance($instance, 'buildCircularBlock');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('detail_title', $result);
        $this->assertArrayHasKey('detail_content', $result);
        $this->assertSame('Circular message', $result['detail_title']);
    }
}
