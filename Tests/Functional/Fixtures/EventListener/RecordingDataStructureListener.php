<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Fixtures\EventListener;

use TYPO3\CMS\Core\Configuration\Event\AfterFlexFormDataStructureParsedEvent;

/**
 * Mimics EXT:form's DataStructureIdentifierListener: appends select items to a
 * FlexForm field at runtime, the way a dynamic DataStructure does.
 *
 * Records its own invocations so a test can tell "the items are duplicated"
 * apart from "the listener ran twice" — those have different causes and
 * different fixes.
 */
final class RecordingDataStructureListener
{
    public const FIELD = 'settings.dynamicList';

    /** Values appended on every invocation, exactly as ext:form appends its form list. */
    public const VALUES = ['alpha', 'beta', 'gamma'];

    public static int $invocations = 0;

    public static function reset(): void
    {
        self::$invocations = 0;
    }

    public function __invoke(AfterFlexFormDataStructureParsedEvent $event): void
    {
        $dataStructure = $event->getDataStructure();
        $element = &$dataStructure['sheets']['sDEF']['ROOT']['el'][self::FIELD] ?? null;
        if (!is_array($element)) {
            return;
        }

        self::$invocations++;
        foreach (self::VALUES as $value) {
            $element['config']['items'][] = ['label' => strtoupper($value), 'value' => $value];
        }
        unset($element);

        $event->setDataStructure($dataStructure);
    }
}
