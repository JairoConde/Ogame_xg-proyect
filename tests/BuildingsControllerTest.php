<?php

declare(strict_types=1);

namespace App\Test;

use App\Http\Controllers\Game\BuildingsController;
use PHPUnit\Framework\TestCase;

/**
 * @covers App\Http\Controllers\Game\BuildingsController
 */
class BuildingsControllerTest extends TestCase
{
    /**
     * Test that the class constant MODULE_ID is correctly defined.
     *
     * @coversNothing
     */
    public function testModuleIdConstant(): void
    {
        $this->assertSame(3, BuildingsController::MODULE_ID);
    }

    /**
     * Test that getCurrentPage method exists, is private, and returns string.
     *
     * @covers ::getCurrentPage
     */
    public function testGetCurrentPageMethodSignature(): void
    {
        $reflection = new \ReflectionMethod(BuildingsController::class, 'getCurrentPage');

        $this->assertTrue($reflection->isPrivate());
        $this->assertFalse($reflection->isStatic());

        $this->assertTrue($reflection->hasReturnType());
        $this->assertSame('string', $reflection->getReturnType()->getName());
        $this->assertSame('getCurrentPage', $reflection->getName());
    }

    /**
     * Test getCurrentPage uses filter_input with INPUT_GET and 'page'.
     *
     * @covers ::getCurrentPage
     */
    public function testGetCurrentPageUsesFilterInput(): void
    {
        $reflection = new \ReflectionMethod(BuildingsController::class, 'getCurrentPage');
        $file = $reflection->getFileName();
        $startLine = $reflection->getStartLine();
        $endLine = $reflection->getEndLine();

        $source = file($file);
        $methodSource = implode('', array_slice($source, $startLine - 1, $endLine - $startLine + 1));

        $this->assertStringContainsString('filter_input(INPUT_GET, \'page\')', $methodSource);
        $this->assertStringContainsString('$allowed_pages', $methodSource);
        $this->assertStringContainsString("'resources'", $methodSource);
        $this->assertStringContainsString("'station'", $methodSource);
        $this->assertStringContainsString('throw new Exception', $methodSource);
    }

    /**
     * Test that runAction method exists and uses filter_input for input validation.
     *
     * @covers ::runAction
     */
    public function testRunActionUsesFilterInput(): void
    {
        $reflection = new \ReflectionMethod(BuildingsController::class, 'runAction');
        $file = $reflection->getFileName();
        $startLine = $reflection->getStartLine();
        $endLine = $reflection->getEndLine();

        $source = file($file);
        $methodSource = implode('', array_slice($source, $startLine - 1, $endLine - $startLine + 1));

        $this->assertStringContainsString('filter_input(INPUT_GET, \'cmd\')', $methodSource);
        $this->assertStringContainsString('filter_input(INPUT_GET, \'building\', FILTER_VALIDATE_INT)', $methodSource);
        $this->assertStringContainsString('filter_input(INPUT_GET, \'listid\', FILTER_VALIDATE_INT)', $methodSource);

        $this->assertStringContainsString("'cancel'", $methodSource);
        $this->assertStringContainsString("'destroy'", $methodSource);
        $this->assertStringContainsString("'insert'", $methodSource);
        $this->assertStringContainsString("'remove'", $methodSource);

        $this->assertTrue($reflection->isPrivate());
    }

    /**
     * Test that getAllowedBuildings method exists and has the expected structure.
     *
     * @covers ::getAllowedBuildings
     */
    public function testGetAllowedBuildingsStructure(): void
    {
        $reflection = new \ReflectionMethod(BuildingsController::class, 'getAllowedBuildings');
        $file = $reflection->getFileName();
        $startLine = $reflection->getStartLine();
        $endLine = $reflection->getEndLine();

        $source = file($file);
        $methodSource = implode('', array_slice($source, $startLine - 1, $endLine - $startLine + 1));

        // Verify planet resources building IDs
        $this->assertStringContainsString('[1, 2, 3, 4, 12, 22, 23, 24]', $methodSource);
        $this->assertStringContainsString('[14, 15, 21, 31, 33, 34, 44]', $methodSource);
        $this->assertStringContainsString('[41, 42, 43]', $methodSource);

        // Verify it calls getCurrentPage
        $this->assertStringContainsString('$this->getCurrentPage()', $methodSource);
    }

    /**
     * Test that canInitBuildAction method exists with the correct parameters.
     *
     * @covers ::canInitBuildAction
     */
    public function testCanInitBuildActionSignature(): void
    {
        $reflection = new \ReflectionMethod(BuildingsController::class, 'canInitBuildAction');

        $this->assertTrue($reflection->isPrivate());
        $this->assertFalse($reflection->isStatic());

        $params = $reflection->getParameters();
        $this->assertCount(2, $params);

        $this->assertSame('building_id', $params[0]->getName());
        $this->assertSame('list_id', $params[1]->getName());
    }

    /**
     * Test that isWorkInProgress method exists and checks the expected building IDs.
     *
     * @covers ::isWorkInProgress
     */
    public function testIsWorkInProgressStructure(): void
    {
        $reflection = new \ReflectionMethod(BuildingsController::class, 'isWorkInProgress');
        $file = $reflection->getFileName();
        $startLine = $reflection->getStartLine();
        $endLine = $reflection->getEndLine();

        $source = file($file);
        $methodSource = implode('', array_slice($source, $startLine - 1, $endLine - $startLine + 1));

        $this->assertStringContainsString('$working_buildings = [14, 15, 21]', $methodSource);
    }

    /**
     * Test that buildButton method exists and has expected structure.
     *
     * @covers ::buildButton
     */
    public function testBuildButtonHasExpectedColorsAndKeys(): void
    {
        $reflection = new \ReflectionMethod(BuildingsController::class, 'buildButton');
        $file = $reflection->getFileName();
        $startLine = $reflection->getStartLine();
        $endLine = $reflection->getEndLine();

        $source = file($file);
        $methodSource = implode('', array_slice($source, $startLine - 1, $endLine - $startLine + 1));

        $this->assertStringContainsString("'all_occupied'", $methodSource);
        $this->assertStringContainsString("'allowed'", $methodSource);
        $this->assertStringContainsString("'not_allowed'", $methodSource);
        $this->assertStringContainsString("'allowed_for_queue'", $methodSource);
        $this->assertStringContainsString("'work_in_progress'", $methodSource);

        $this->assertStringContainsString("'red'", $methodSource);
        $this->assertStringContainsString("'green'", $methodSource);
    }

    /**
     * Test that setUpBuildings method exists.
     *
     * @covers ::setUpBuildings
     */
    public function testSetUpBuildingsMethodExists(): void
    {
        $reflection = new \ReflectionMethod(BuildingsController::class, 'setUpBuildings');

        $this->assertTrue($reflection->isPrivate());
        $this->assertSame('setUpBuildings', $reflection->getName());
    }

    /**
     * Test that buildPage method exists.
     *
     * @covers ::buildPage
     */
    public function testBuildPageMethodExists(): void
    {
        $reflection = new \ReflectionMethod(BuildingsController::class, 'buildPage');

        $this->assertTrue($reflection->isPrivate());
        $this->assertSame('buildPage', $reflection->getName());
    }

    /**
     * Test that index method exists, is public, returns void.
     *
     * @covers ::index
     */
    public function testIndexMethodSignature(): void
    {
        $reflection = new \ReflectionMethod(BuildingsController::class, 'index');

        $this->assertTrue($reflection->isPublic());
        $this->assertFalse($reflection->isStatic());

        $this->assertTrue($reflection->hasReturnType());
        $this->assertSame('void', (string) $reflection->getReturnType());
    }
}
