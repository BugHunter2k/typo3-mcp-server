<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool\File;

use Hn\McpServer\MCP\Tool\File\UploadFileTool;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class UploadFileToolTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../../Fixtures/sys_file_storage.csv');

        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('default');

        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['BE_USER'] = $backendUser;

        // Create storage base path
        @mkdir($this->instancePath . '/fileadmin', 0777, true);
    }

    public function testUploadSmallPng(): void
    {
        $tool = new UploadFileTool();

        // Generate a small PNG in memory
        $image = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($image);
        $pngData = ob_get_clean();
        imagedestroy($image);

        $result = $tool->execute([
            'folder' => '1:/',
            'filename' => 'test-upload.png',
            'fileData' => base64_encode($pngData),
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertCount(1, $result->content);
        $this->assertInstanceOf(TextContent::class, $result->content[0]);

        $content = $result->content[0]->text;

        $this->assertStringContainsString('FILE UPLOADED', $content);
        $this->assertStringContainsString('test-upload.png', $content);
        $this->assertStringContainsString('UID:', $content);

        // Verify file exists on disk
        $this->assertFileExists($this->instancePath . '/fileadmin/test-upload.png');
    }

    public function testUploadToSubfolder(): void
    {
        @mkdir($this->instancePath . '/fileadmin/uploads', 0777, true);

        $tool = new UploadFileTool();

        $result = $tool->execute([
            'folder' => '1:/uploads/',
            'filename' => 'doc.txt',
            'fileData' => base64_encode('Hello World'),
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $content = $result->content[0]->text;

        $this->assertStringContainsString('doc.txt', $content);
        $this->assertFileExists($this->instancePath . '/fileadmin/uploads/doc.txt');
    }

    public function testConflictModeRename(): void
    {
        // Pre-create a file
        file_put_contents($this->instancePath . '/fileadmin/existing.txt', 'original');

        $tool = new UploadFileTool();

        $result = $tool->execute([
            'folder' => '1:/',
            'filename' => 'existing.txt',
            'fileData' => base64_encode('new content'),
            'conflictMode' => 'rename',
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $content = $result->content[0]->text;

        // Should have been renamed (not overwrite original)
        $this->assertStringContainsString('FILE UPLOADED', $content);
        // Original should still exist with original content
        $this->assertSame('original', file_get_contents($this->instancePath . '/fileadmin/existing.txt'));
    }

    public function testConflictModeCancel(): void
    {
        // Pre-create a file
        file_put_contents($this->instancePath . '/fileadmin/existing2.txt', 'original');

        $tool = new UploadFileTool();

        $result = $tool->execute([
            'folder' => '1:/',
            'filename' => 'existing2.txt',
            'fileData' => base64_encode('new content'),
            'conflictMode' => 'cancel',
        ]);

        // Cancel mode should produce an error when file exists
        $this->assertTrue($result->isError, 'Expected error when file exists with cancel mode');
    }

    public function testInvalidBase64(): void
    {
        $tool = new UploadFileTool();

        $result = $tool->execute([
            'folder' => '1:/',
            'filename' => 'test.txt',
            'fileData' => '',
        ]);

        $this->assertTrue($result->isError);

        $content = $result->content[0]->text;
        $this->assertStringContainsString('Invalid or empty Base64', $content);
    }

    public function testShowsFileMetadataAfterUpload(): void
    {
        $tool = new UploadFileTool();

        $result = $tool->execute([
            'folder' => '1:/',
            'filename' => 'info-test.txt',
            'fileData' => base64_encode('some content here'),
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $content = $result->content[0]->text;

        $this->assertStringContainsString('UID:', $content);
        $this->assertStringContainsString('Name: info-test.txt', $content);
        $this->assertStringContainsString('Size:', $content);
        $this->assertStringContainsString('MIME:', $content);
        $this->assertStringContainsString('Path:', $content);
        $this->assertStringContainsString('URL:', $content);
    }

    public function testTempFileCleanedUp(): void
    {
        $tool = new UploadFileTool();

        $tool->execute([
            'folder' => '1:/',
            'filename' => 'cleanup-test.txt',
            'fileData' => base64_encode('test data'),
        ]);

        // Verify no temp files with our prefix remain
        $tempFiles = glob(sys_get_temp_dir() . '/mcp_upload_*');
        $this->assertEmpty($tempFiles, 'Temp files should be cleaned up after upload');
    }
}
