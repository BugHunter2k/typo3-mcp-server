<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Regression batch for the LIADEV-586 K20 findings (Vanessa Jung,
 * 2026-03-30). Every editor-reported failure gets a pinned test:
 *
 * - "Umbau Text-Media → Teaserbox failed"  → CType change with inline
 *   relations (expected fixed by upstream #94/#98, language control field)
 * - "Subheader could not be filled"        → subheader writable
 *   (expected fixed by upstream #90, showitem-driven schema)
 * - "Page creation in workspace failed"    → page create round trip
 * - "bodytext must be emptied manually"    → update with "" empties it
 */
class K20RegressionTest extends FunctionalTestCase
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
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_file.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_file_storage.csv');
        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('en');
    }

    /**
     * Test changing the CType of an element with inline file relations
     * (K20: "Umbau Text-Media → Teaserbox" failed on update)
     */
    public function testCTypeChangeWithInlineRelationsSurvives(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);

        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'CType' => 'textmedia',
                'header' => 'Text media element',
                'bodytext' => 'Original text',
                'assets' => [1],
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $uid = json_decode($result->content[0]->text, true)['uid'];

        // The K20 scenario: switch the content type of the existing element
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => $uid,
            'data' => [
                'CType' => 'textpic',
                'header' => 'Converted element',
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute(['table' => 'tt_content', 'uid' => $uid]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $record = json_decode($result->content[0]->text, true)['records'][0];

        $this->assertEquals('textpic', $record['CType']);
        $this->assertEquals('Converted element', $record['header']);
        $this->assertEquals('Original text', $record['bodytext'], 'Untouched fields must survive the CType change');
    }

    /**
     * Test that the subheader field is writable on create and update
     * (K20: "Subheader field could not be filled")
     */
    public function testSubheaderIsWritable(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);

        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'CType' => 'text',
                'header' => 'With subheader',
                'subheader' => 'Initial subheader',
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $uid = json_decode($result->content[0]->text, true)['uid'];

        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => $uid,
            'data' => ['subheader' => 'Updated subheader'],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute(['table' => 'tt_content', 'uid' => $uid]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $record = json_decode($result->content[0]->text, true)['records'][0];

        $this->assertEquals('Updated subheader', $record['subheader']);
    }

    /**
     * Test page creation in workspace context round-trips with the live UID
     * (K20: "Page creation in workspace failed")
     */
    public function testPageCreationInWorkspace(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);

        $result = $writeTool->execute([
            'table' => 'pages',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'title' => 'K20 workspace page',
                'doktype' => 1,
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $pageUid = json_decode($result->content[0]->text, true)['uid'];
        $this->assertGreaterThan(0, $pageUid);

        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute(['table' => 'pages', 'uid' => $pageUid]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $record = json_decode($result->content[0]->text, true)['records'][0];

        $this->assertEquals('K20 workspace page', $record['title']);
        $this->assertEquals($pageUid, $record['uid'], 'Workspace transparency: the exposed UID must be the live UID');
    }

    /**
     * Test that bodytext can be emptied via update with an empty string
     * (K20: "bodytext must be emptied manually")
     */
    public function testBodytextEmptiedViaUpdateWithEmptyString(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);

        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'CType' => 'text',
                'header' => 'Element with text',
                'bodytext' => '<p>Text that should be removable</p>',
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $uid = json_decode($result->content[0]->text, true)['uid'];

        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => $uid,
            'data' => ['bodytext' => ''],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute(['table' => 'tt_content', 'uid' => $uid]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $record = json_decode($result->content[0]->text, true)['records'][0];

        $this->assertSame('', (string)($record['bodytext'] ?? ''), 'bodytext must be empty after updating with ""');
    }

    /**
     * Test that the WriteTable schema communicates staged (non-destructive)
     * deletion so MCP clients do not refuse delete calls
     * (K20: "Ich kann permanente Löschungen nicht durchführen")
     */
    public function testDeleteIsAnnouncedAsStagedAndNonDestructive(): void
    {
        $schema = GeneralUtility::makeInstance(WriteTableTool::class)->getSchema();

        $this->assertFalse(
            $schema['annotations']['destructiveHint'],
            'destructiveHint must be explicitly false — absent means true per MCP spec, making clients refuse deletes'
        );
        $actionDescription = $schema['inputSchema']['properties']['action']['description'];
        $this->assertStringContainsString('STAGES', $actionDescription);
        $this->assertStringContainsString('published', $actionDescription);
    }
}
