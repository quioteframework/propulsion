<?php

/**
 * Script to modernize Propel test files for PHPUnit 12 compatibility
 */

function updateTestFile($filePath) {
    $content = file_get_contents($filePath);
    $originalContent = $content;
    
    // Track if we made any changes
    $hasChanges = false;
    
    // Replace setUp() method to use the modern signature
    if (preg_match('/protected function setUp\(\)/', $content)) {
        $content = preg_replace(
            '/protected function setUp\(\)/',
            'protected function setUp(): void',
            $content
        );
        $hasChanges = true;
    }
    
    // Replace tearDown() method to use the modern signature
    if (preg_match('/protected function tearDown\(\)/', $content)) {
        $content = preg_replace(
            '/protected function tearDown\(\)/',
            'protected function tearDown(): void',
            $content
        );
        $hasChanges = true;
    }
    
    // Replace setUpBeforeClass() method to use the modern signature
    if (preg_match('/public static function setUpBeforeClass\(\)/', $content)) {
        $content = preg_replace(
            '/public static function setUpBeforeClass\(\)/',
            'public static function setUpBeforeClass(): void',
            $content
        );
        $hasChanges = true;
    }
    
    // Replace tearDownAfterClass() method to use the modern signature
    if (preg_match('/public static function tearDownAfterClass\(\)/', $content)) {
        $content = preg_replace(
            '/public static function tearDownAfterClass\(\)/',
            'public static function tearDownAfterClass(): void',
            $content
        );
        $hasChanges = true;
    }
    
    // Replace PHPUnit_Framework_TestCase with proper use statement if needed
    // This should be rare since we're updating base classes, but let's handle it
    if (strpos($content, 'PHPUnit_Framework_TestCase') !== false) {
        // Add use statement at the top if not already present
        if (strpos($content, 'use PHPUnit\Framework\TestCase;') === false) {
            $content = preg_replace(
                '/(<\?php\s*\n)/s',
                "$1\nuse PHPUnit\\Framework\\TestCase;\n",
                $content
            );
        }
        
        $content = str_replace('PHPUnit_Framework_TestCase', 'TestCase', $content);
        $hasChanges = true;
    }
    
    // Update deprecated assertion methods
    $assertionReplacements = [
        'assertType(' => 'assertInstanceOf(',
        'assertNotType(' => 'assertNotInstanceOf(',
        'assertRegExp(' => 'assertMatchesRegularExpression(',
        'assertNotRegExp(' => 'assertDoesNotMatchRegularExpression(',
        'assertContainsOnly(' => 'assertContainsOnlyInstancesOf(',
        'assertNotContainsOnly(' => 'assertNotContainsOnlyInstancesOf(',
    ];
    
    foreach ($assertionReplacements as $old => $new) {
        if (strpos($content, $old) !== false) {
            $content = str_replace($old, $new, $content);
            $hasChanges = true;
        }
    }
    
    // Only write if we made changes
    if ($hasChanges && $content !== $originalContent) {
        file_put_contents($filePath, $content);
        echo "Updated: $filePath\n";
        return true;
    }
    
    return false;
}

function updateAllTestFiles($directory) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory)
    );
    
    $updatedCount = 0;
    $totalCount = 0;
    
    foreach ($iterator as $file) {
        if ($file->isFile() && pathinfo($file, PATHINFO_EXTENSION) === 'php') {
            $filePath = $file->getPathname();
            
            // Only process test files
            if (strpos($filePath, 'Test.php') !== false || 
                strpos($filePath, 'TestCase.php') !== false ||
                strpos($filePath, 'TestBase.php') !== false) {
                
                $totalCount++;
                if (updateTestFile($filePath)) {
                    $updatedCount++;
                }
            }
        }
    }
    
    echo "\nProcessed $totalCount files, updated $updatedCount files.\n";
}

// Run the update
$testDir = __DIR__ . '/testsuite';
if (is_dir($testDir)) {
    echo "Starting PHPUnit modernization of test files in: $testDir\n";
    updateAllTestFiles($testDir);
} else {
    echo "Test directory not found: $testDir\n";
}

// Also update any remaining files in tools/helpers
$helpersDir = __DIR__ . '/tools/helpers';
if (is_dir($helpersDir)) {
    echo "\nUpdating helper files in: $helpersDir\n";
    updateAllTestFiles($helpersDir);
}

echo "\nModernization complete!\n";
