<?php

declare(strict_types=1);

namespace App\Test;

use App\Libraries\Functions;
use PHPUnit\Framework\TestCase;

/**
 * @covers App\Libraries\Functions
 */
class FunctionsTest extends TestCase
{
    public function testRedirectCallsExitAfterHeader(): void
    {
        // This test verifies the function structure is valid
        // (redirect now calls header() then exit separately instead of exit(header()))
        $reflection = new \ReflectionMethod(Functions::class, 'redirect');
        $file = $reflection->getFileName();
        $startLine = $reflection->getStartLine();
        $endLine = $reflection->getEndLine();

        $source = file($file);
        $methodSource = implode('', array_slice($source, $startLine - 1, $endLine - $startLine + 1));

        // Verify it calls header() and exit separately
        $this->assertStringContainsString("header('location:", $methodSource);
        $this->assertStringContainsString('exit;', $methodSource);
    }

    public function testSendMessageHasCorrectDefaultTypes(): void
    {
        $reflection = new \ReflectionMethod(Functions::class, 'sendMessage');
        $params = $reflection->getParameters();

        $timeParam = $params[2]; // $time
        $typeParam = $params[3]; // $type

        $this->assertSame('time', $timeParam->getName());
        $this->assertTrue($timeParam->isDefaultValueAvailable());
        $this->assertSame(0, $timeParam->getDefaultValue());

        $this->assertSame('type', $typeParam->getName());
        $this->assertTrue($typeParam->isDefaultValueAvailable());
        $this->assertSame(0, $typeParam->getDefaultValue());
    }
}
