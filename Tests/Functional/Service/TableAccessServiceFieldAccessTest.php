<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Service;

use Hn\McpServer\Service\TableAccessService;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Test field access restrictions in TableAccessService
 * Verifies that file fields and inaccessible inline relations are properly blocked
 */
class TableAccessServiceFieldAccessTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    protected TableAccessService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);

        $this->service = new TableAccessService();
    }

    /**
     * Test that file type fields are not accessible
     */
    public function testFileFieldsAreNotAccessible(): void
    {
        // The 'media' field on pages table is type='file'
        $canAccess = $this->service->canAccessField('pages', 'media');

        $this->assertFalse($canAccess, 'File fields should not be accessible');
    }

    /**
     * Test that file fields are hidden from available fields
     */
    public function testFileFieldsAreHiddenFromSchema(): void
    {
        $fields = $this->service->getAvailableFields('pages');

        $this->assertArrayNotHasKey('media', $fields, 'File field "media" should not be in available fields');
    }

    /**
     * Test that sys_file_reference table is accessible (needed for FAL operations)
     */
    public function testSysFileReferenceTableIsAccessible(): void
    {
        $canAccess = $this->service->canAccessTable('sys_file_reference');

        $this->assertTrue($canAccess, 'sys_file_reference table should be accessible for FAL operations');
    }

    /**
     * Test that file fields referencing sys_file_reference are accessible
     */
    public function testFileFieldsToSysFileReferenceAreAccessible(): void
    {
        // pages has 'media' field which is type='file' referencing sys_file_reference
        if (!isset($GLOBALS['TCA']['pages']['columns']['media'])) {
            $this->markTestSkipped('pages.media field not available in this TYPO3 version');
        }

        $fieldConfig = $GLOBALS['TCA']['pages']['columns']['media'] ?? [];
        if (($fieldConfig['config']['type'] ?? '') !== 'file') {
            $this->markTestSkipped('pages.media is not a file field in this TYPO3 version');
        }

        $canAccess = $this->service->canAccessField('pages', 'media');

        $this->assertTrue($canAccess, 'File fields referencing sys_file_reference should be accessible');
    }

    /**
     * Test that regular accessible fields remain accessible
     */
    public function testRegularFieldsRemainAccessible(): void
    {
        // Test that normal text fields are accessible
        $canAccessTitle = $this->service->canAccessField('pages', 'title');
        $canAccessDescription = $this->service->canAccessField('pages', 'description');

        $this->assertTrue($canAccessTitle, 'Regular text field "title" should be accessible');
        $this->assertTrue($canAccessDescription, 'Regular text field "description" should be accessible');
    }

    /**
     * Test that available fields includes file fields
     */
    public function testAvailableFieldsIncludesFileFields(): void
    {
        $fields = $this->service->getAvailableFields('pages');

        // Check that normal fields are present
        $this->assertArrayHasKey('title', $fields, 'Title field should be available');
        $this->assertArrayHasKey('description', $fields, 'Description field should be available');

        // Check that file field is present (now supported)
        $this->assertArrayHasKey('media', $fields, 'Media file field should be available');
    }

    /**
     * Test that tt_content fields include file fields
     */
    public function testTtContentFieldsIncludeFileFields(): void
    {
        $fields = $this->service->getAvailableFields('tt_content', 'textmedia');

        // Check that normal fields are present
        $this->assertArrayHasKey('header', $fields, 'Header field should be available');
        $this->assertArrayHasKey('bodytext', $fields, 'Bodytext field should be available');

        // Check that assets field is now present (file fields are supported)
        if (isset($GLOBALS['TCA']['tt_content']['columns']['assets'])) {
            $this->assertArrayHasKey('assets', $fields, 'Assets field should be available for file references');
        }

        // Check that image field (if type=file) is present
        if (isset($GLOBALS['TCA']['tt_content']['columns']['image'])) {
            $imageConfig = $GLOBALS['TCA']['tt_content']['columns']['image'] ?? [];
            if (($imageConfig['config']['type'] ?? '') === 'file') {
                $this->assertArrayHasKey('image', $fields, 'Image file field should be available');
            }
        }
    }
}
