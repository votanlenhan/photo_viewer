<?php declare(strict_types=1);

// tests/api/HelpersTest.php

// Chắc chắn rằng file helpers.php được nạp
// Vì chúng ta đang test các hàm global, chúng ta cần require_once nó
// Lưu ý: Cách này không lý tưởng bằng việc sử dụng class và autoload,
// nhưng phù hợp với cấu trúc hiện tại của dự án.
require_once __DIR__ . '/../../api/helpers.php'; 

use PHPUnit\Framework\TestCase;

final class HelpersTest extends TestCase
{
    /**
     * @dataProvider pathNormalizationProvider
     */
    public function testNormalizePathInput(?string $input, string $expected): void // Chấp nhận null input
    {
        $this->assertSame($expected, normalize_path_input($input));
    }

    /**
     * Cung cấp dữ liệu cho testNormalizePathInput
     */
    public static function pathNormalizationProvider(): array
    {
        return [
            'Empty string' => ['', ''],
            'Null input' => [null, ''], // Test case cho null input
            'Single slash' => ['/', ''],
            'Multiple slashes' => ['///', ''],
            'Simple path' => ['folder/subfolder', 'folder/subfolder'],
            'Leading slash' => ['/folder/subfolder', 'folder/subfolder'],
            'Trailing slash' => ['folder/subfolder/', 'folder/subfolder'],
            'Leading and trailing slashes' => ['/folder/subfolder/', 'folder/subfolder'],
            'Backslashes' => ['folder\\subfolder', 'folder/subfolder'],
            'Mixed slashes' => ['/folder\\subfolder/', 'folder/subfolder'],
            'Parent directory (..) should be removed' => ['folder/../subfolder', 'folder/subfolder'],
            'Multiple parent directories' => ['folder/../../subfolder', 'folder/subfolder'],
            'Leading parent directories' => ['../folder/subfolder', 'folder/subfolder'],
            'Trailing parent directories' => ['folder/subfolder/..', 'folder/subfolder'],
            'Null byte removal' => ["folder/subfolder\0hidden", 'folder/subfolderhidden'],
            'Complex case' => ['/../folder\\..\\another/./subfolder/../final/', 'folder/another/./subfolder/final'], // Note: '.' is not removed by this simple normalization
        ];
    }

    // TODO: Thêm các test case khác cho các hàm helper khác sau này
} 