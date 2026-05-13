<?php

declare(strict_types=1);

namespace App\Test;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class ArchitectureTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';

    /**
     * Test that all controller classes extend BaseController.
     */
    public function testAllGameControllersExtendBaseController(): void
    {
        $controllerDir = self::ROOT . '/app/Http/Controllers/Game';
        $files = glob($controllerDir . '/*Controller.php');
        $this->assertNotEmpty($files, 'No controller files found at ' . $controllerDir);

        $violations = [];
        foreach ($files as $file) {
            $contents = file_get_contents($file);
            if (!str_contains($contents, 'extends BaseController')) {
                $violations[] = basename($file);
            }
        }

        $this->assertEmpty(
            $violations,
            'Controllers not extending BaseController: ' . implode(', ', $violations)
        );
    }

    /**
     * Test that all admin controllers extend BaseController.
     */
    public function testAllAdminControllersExtendBaseController(): void
    {
        $controllerDir = self::ROOT . '/app/Http/Controllers/Adm';
        $files = glob($controllerDir . '/*Controller.php');
        $this->assertNotEmpty($files, 'No admin controller files found at ' . $controllerDir);

        $violations = [];
        foreach ($files as $file) {
            $contents = file_get_contents($file);
            if (!str_contains($contents, 'extends BaseController')) {
                $violations[] = basename($file);
            }
        }

        $this->assertEmpty(
            $violations,
            'Admin controllers not extending BaseController: ' . implode(', ', $violations)
        );
    }

    /**
     * Test that all PHP files in app/ parse without syntax errors.
     */
    public function testAllAppFilesParseCorrectly(): void
    {
        $directory = new \RecursiveDirectoryIterator(self::ROOT . '/app');
        $iterator = new \RecursiveIteratorIterator($directory);
        $regex = new \RegexIterator($iterator, '/^.+\.php$/i', \RecursiveRegexIterator::GET_MATCH);

        $filesWithErrors = [];
        foreach ($regex as $fileInfo) {
            $filePath = $fileInfo[0];
            $output = [];
            $returnCode = 0;
            exec('php -l ' . escapeshellarg($filePath) . ' 2>&1', $output, $returnCode);

            if ($returnCode !== 0) {
                $filesWithErrors[] = $filePath . ': ' . implode(' ', $output);
            }
        }

        $this->assertEmpty(
            $filesWithErrors,
            'Files with PHP syntax errors (' . count($filesWithErrors) . '): ' . PHP_EOL . implode(PHP_EOL, array_slice($filesWithErrors, 0, 10))
        );
    }

    /**
     * Test that all expected namespaces are properly declared.
     */
    public function testNamespacesMatchDirectoryStructure(): void
    {
        $tests = [
            ['file' => self::ROOT . '/app/Core/BaseController.php', 'expected' => 'App\Core'],
            ['file' => self::ROOT . '/app/Http/Controllers/Game/BuildingsController.php', 'expected' => 'App\Http\Controllers\Game'],
            ['file' => self::ROOT . '/app/Models/Game/Buildings.php', 'expected' => 'App\Models\Game'],
            ['file' => self::ROOT . '/app/Libraries/Formulas.php', 'expected' => 'App\Libraries'],
        ];

        foreach ($tests as $test) {
            $this->assertFileExists($test['file']);
            $contents = file_get_contents($test['file']);
            $this->assertStringContainsString(
                'namespace ' . $test['expected'] . ';',
                $contents,
                $test['file'] . ' should have namespace ' . $test['expected']
            );
        }
    }
}
