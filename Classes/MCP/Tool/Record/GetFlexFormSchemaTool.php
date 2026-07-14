<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\Record;

use Mcp\Types\CallToolResult;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Configuration\FlexForm\FlexFormTools;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Hn\McpServer\Utility\TcaFormattingUtility;
use Hn\McpServer\Service\TableAccessService;

/**
 * Tool for getting FlexForm schema information
 */
class GetFlexFormSchemaTool extends AbstractRecordTool
{
    /**
     * Get the tool schema
     */
    public function getSchema(): array
    {
        return [
            'description' => 'Get schema information for a specific FlexForm field. Returns field definitions, types, and configuration options for the FlexForm DataStructure.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'table' => [
                        'type' => 'string',
                        'description' => 'The table name containing the FlexForm field (default: tt_content)',
                        'default' => 'tt_content',
                    ],
                    'field' => [
                        'type' => 'string',
                        'description' => 'The field name containing the FlexForm data (default: pi_flexform)',
                        'default' => 'pi_flexform',
                    ],
                    'identifier' => [
                        'type' => 'string',
                        'description' => 'The FlexForm identifier. TYPO3 14 keys DataStructures directly by CType (e.g. "news_pi1", "form_formframework").',
                    ],
                    'recordUid' => [
                        'type' => 'integer',
                        'description' => 'Optional record UID. When given, the record is loaded and used to resolve the DataStructure, so record-dependent schemas (e.g. EXT:form finisher override sheets) are included.',
                    ],
                ],
                'required' => ['identifier'],
            ],
            'annotations' => [
                'readOnlyHint' => true,
                'idempotentHint' => true
            ]
        ];
    }

    /**
     * Execute the tool logic
     */
    protected function doExecute(array $params): CallToolResult
    {

        // Get parameters
        $table = $params['table'] ?? 'tt_content';
        $field = $params['field'] ?? 'pi_flexform';
        $identifier = $params['identifier'] ?? '';
        $recordUid = isset($params['recordUid']) ? (int)$params['recordUid'] : null;

        // Validate parameters
        if (empty($identifier)) {
            throw new \InvalidArgumentException('Identifier parameter is required');
        }

        // Validate table access using TableAccessService
        $this->ensureTableAccess($table, 'read');

        // Check if the table and field exist
        if (!isset($GLOBALS['TCA'][$table]['columns'][$field])) {
            throw new \InvalidArgumentException("Field '$field' not found in table '$table'");
        }

        // Check if the field is a FlexForm field
        if (($GLOBALS['TCA'][$table]['columns'][$field]['config']['type'] ?? '') !== 'flex') {
            throw new \InvalidArgumentException("Field '$field' in table '$table' is not a FlexForm field");
        }

        // Build the header
        $header = "FLEXFORM SCHEMA: $identifier\n";
        $header .= "=======================================\n\n";
        $header .= "Table: $table\n";
        $header .= "Field: $field\n\n";

        // Resolve the DataStructure through TYPO3's FlexFormTools so the
        // DataStructure identifier events run (BeforeFlexFormDataStructureIdentifierInitializedEvent
        // / AfterFlexFormDataStructureParsedEvent). This is essential for
        // dynamic DataStructures such as EXT:form (form_formframework): the
        // `settings.persistenceIdentifier` select items (the list of available
        // forms) and the per-finisher override sheets are injected at runtime
        // and are absent from the static FlexForm XML file. Reading the raw
        // file — as this tool did previously — would hide them from the LLM.
        $structure = $this->resolveDataStructure($table, $field, $identifier, $recordUid);

        if ($structure !== null) {
            $processedData = $this->processFlexFormXml($structure);
            if (!empty($processedData['fields']) || !empty($processedData['sheets'])) {
                $result = $this->formatFlexFormSchema($processedData, $header);
                return $this->createSuccessResult($result);
            }
        }

        // If we get here, the identifier could not be resolved to a DataStructure
        throw new \InvalidArgumentException("FlexForm schema not found for identifier: $identifier");
    }

    /**
     * Resolve a FlexForm DataStructure to its normalized array form via
     * TYPO3's FlexFormTools, running the DataStructure identifier events.
     *
     * Returns null when no candidate row yields a DataStructure that actually
     * contains fields (e.g. the identifier is unknown or its extension is not
     * installed).
     */
    protected function resolveDataStructure(string $table, string $field, string $identifier, ?int $recordUid): ?array
    {
        $fieldTca = $GLOBALS['TCA'][$table]['columns'][$field];
        $flexFormTools = GeneralUtility::makeInstance(FlexFormTools::class);

        foreach ($this->buildCandidateRows($table, $field, $identifier, $recordUid) as $candidate) {
            try {
                $dsIdentifier = $flexFormTools->getDataStructureIdentifier($fieldTca, $table, $field, $candidate['row']);
            } catch (\Throwable) {
                // Pointer fields did not match a DataStructure for this row —
                // try the next candidate.
                continue;
            }

            // A candidate row must resolve to a DataStructure that is specific
            // to the requested identifier. When the pointer value does not
            // match, FlexFormTools silently falls back to the `default` ds key
            // (see getDataStructureIdentifierFromTcaArray) — accepting that
            // would make every unknown identifier "resolve" to the default DS.
            // This applies to real records too: a record whose pointer fields
            // no longer match any ds key (e.g. legacy list_type rows after a
            // plugin switched to CType registration) falls back to `default`,
            // which must not shadow an explicitly requested identifier that a
            // later synthesized candidate can resolve.
            if (!$candidate['allowDefault']) {
                $parsed = json_decode($dsIdentifier, true);
                if (is_array($parsed) && ($parsed['dataStructureKey'] ?? null) === 'default') {
                    continue;
                }
            }

            try {
                $structure = $flexFormTools->parseDataStructureByIdentifier($dsIdentifier);
            } catch (\Throwable) {
                continue;
            }

            if ($this->dataStructureHasFields($structure)) {
                return $structure;
            }
        }

        return null;
    }

    /**
     * Build the list of record rows to try when resolving the DataStructure.
     *
     * FlexFormTools derives the DataStructure from a record's pointer fields
     * (for tt_content: `list_type,CType`). Since callers pass an identifier
     * rather than a full record, we synthesize plausible rows — or load the
     * real record when a recordUid is supplied.
     *
     * Each entry is `['row' => array, 'allowDefault' => bool]`. `allowDefault`
     * marks rows whose `default` ds resolution is legitimate — only when the
     * caller explicitly asked for the "default" identifier.
     *
     * @return array<int, array{row: array<string, mixed>, allowDefault: bool}>
     */
    protected function buildCandidateRows(string $table, string $field, string $identifier, ?int $recordUid): array
    {
        $candidates = [];
        $allowDefault = ($identifier === 'default');

        // Best case: an actual record. It carries the real pointer values and,
        // for EXT:form, the selected persistenceIdentifier — which the DS event
        // needs to add the finisher-override sheets.
        if ($recordUid !== null) {
            $record = BackendUtility::getRecord($table, $recordUid);
            if (is_array($record)) {
                $candidates[] = ['row' => $record, 'allowDefault' => $allowDefault];
            }
        }

        // The identifier may be a CType (TYPO3 14 / own-CType plugins such as
        // form_formframework), a list_type (legacy plugins such as news_pi1),
        // or a combined ds array key ("*,news_pi1", "news_pi1,list"). Combined
        // keys follow the ds_pointerField order (tt_content: "list_type,CType");
        // "*" is a wildcard placeholder.
        $cType = $identifier;
        $listType = $identifier;
        if (str_contains($identifier, ',')) {
            [$first, $second] = array_map('trim', explode(',', $identifier, 2));
            if ($first !== '*' && $first !== '') {
                $listType = $first;
            }
            if ($second !== '*' && $second !== '') {
                $cType = $second;
            }
        }

        // Candidate 1: identifier is a CType.
        $candidates[] = ['row' => [$field => '', 'CType' => $cType, 'list_type' => ''], 'allowDefault' => $allowDefault];
        // Candidate 2: identifier is a list_type under the generic "list" CType.
        $candidates[] = ['row' => [$field => '', 'CType' => 'list', 'list_type' => $listType], 'allowDefault' => $allowDefault];

        return $candidates;
    }

    /**
     * Whether a parsed DataStructure declares at least one field in any sheet.
     */
    protected function dataStructureHasFields(array $structure): bool
    {
        foreach (($structure['sheets'] ?? []) as $sheet) {
            if (!empty($sheet['ROOT']['el'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Convert dot notation field name to JSON path
     * e.g., "settings.orderBy" -> "pi_flexform.settings.orderBy"
     */
    protected function getJsonPath(string $fieldName): string
    {
        if (strpos($fieldName, '.') === false) {
            return 'pi_flexform.' . $fieldName;
        }

        $parts = explode('.', $fieldName);
        return 'pi_flexform.' . implode('.', $parts);
    }

    /**
     * Process a single field configuration
     *
     * @param string $fieldName The field name
     * @param array $field The field configuration
     * @return array Processed field data with type, label, description, etc.
     */
    protected function processField(string $fieldName, array $field): array
    {
        $fieldData = [
            'name' => $fieldName,
            'type' => 'unknown',
            'label' => $fieldName,
            'description' => '',
            'config' => [],
            'jsonPath' => $this->getJsonPath($fieldName)
        ];

        // Check if field uses TCEforms structure (older format) or direct configuration (newer format)
        $fieldConfig = isset($field['TCEforms']) ? $field['TCEforms'] : $field;

        // Get field label
        if (isset($fieldConfig['label'])) {
            $fieldData['label'] = TableAccessService::translateLabel($fieldConfig['label']);
        }

        // Get field type and config
        if (isset($fieldConfig['config']['type'])) {
            $fieldData['type'] = $fieldConfig['config']['type'];
            $fieldData['config'] = $fieldConfig['config'];
        }

        // Get field description
        if (isset($fieldConfig['description'])) {
            $fieldData['description'] = TableAccessService::translateLabel($fieldConfig['description']);
        }

        return $fieldData;
    }

    /**
     * Process a collection of fields
     *
     * @param array $fields The fields to process
     * @return array Array of processed field data
     */
    protected function processFields(array $fields): array
    {
        $processedFields = [];

        foreach ($fields as $fieldName => $field) {
            $processedFields[] = $this->processField($fieldName, $field);
        }

        return $processedFields;
    }

    /**
     * Process FlexForm sheets
     *
     * @param array $sheets The sheets to process
     * @return array Processed sheets data
     */
    protected function processSheets(array $sheets): array
    {
        $processedSheets = [];

        foreach ($sheets as $sheetName => $sheet) {
            $sheetData = [
                'name' => $sheetName,
                'fields' => []
            ];

            if (isset($sheet['ROOT']['el'])) {
                $sheetData['fields'] = $this->processFields($sheet['ROOT']['el']);
            }

            $processedSheets[] = $sheetData;
        }

        return $processedSheets;
    }

    /**
     * Process FlexForm XML structure
     *
     * @param array $xmlArray The parsed XML array
     * @return array Processed FlexForm data
     */
    protected function processFlexFormXml(array $xmlArray): array
    {
        $data = [
            'sheets' => [],
            'fields' => [],
            'hasSheets' => false
        ];

        if (isset($xmlArray['sheets'])) {
            // Multi-sheet FlexForm
            $data['hasSheets'] = true;
            $data['sheets'] = $this->processSheets($xmlArray['sheets']);

            // Collect all field names for JSON example
            foreach ($data['sheets'] as $sheet) {
                foreach ($sheet['fields'] as $field) {
                    $data['fields'][] = $field['name'];
                }
            }
        } elseif (isset($xmlArray['ROOT']['el'])) {
            // Single sheet FlexForm
            $processedFields = $this->processFields($xmlArray['ROOT']['el']);
            $data['fields'] = array_column($processedFields, 'name');

            // Store as single unnamed sheet for consistency
            $data['sheets'][] = [
                'name' => null,
                'fields' => $processedFields
            ];
        }

        return $data;
    }

    /**
     * Format processed FlexForm data as text
     *
     * @param array $data Processed FlexForm data
     * @param string $prefix Additional prefix text
     * @return string Formatted text output
     */
    protected function formatFlexFormSchema(array $data, string $prefix = ''): string
    {
        $result = $prefix;

        if ($data['hasSheets']) {
            $result .= "SHEETS:\n";
            $result .= "-------\n";

            foreach ($data['sheets'] as $sheet) {
                $result .= "Sheet: {$sheet['name']}\n";
                $result .= "  Fields:\n";

                foreach ($sheet['fields'] as $field) {
                    $result .= $this->formatField($field, '  ');
                }

                $result .= "\n";
            }
        } else {
            $result .= "FIELDS:\n";
            $result .= "------\n";

            if (!empty($data['sheets'][0]['fields'])) {
                foreach ($data['sheets'][0]['fields'] as $field) {
                    $result .= $this->formatField($field, '');
                }
            }

            $result .= "\n";
        }

        // Add JSON structure example
        $result .= "JSON STRUCTURE:\n";
        $result .= "==============\n";
        $result .= "When reading or writing FlexForm data, use nested objects/arrays:\n\n";

        if (!empty($data['fields'])) {
            $jsonExample = $this->buildJsonExample($data['fields']);
            $result .= json_encode($jsonExample, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } else {
            $result .= json_encode(['pi_flexform' => ['example' => 'This is an example of the FlexForm data structure']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $result .= "\n\nNote: Field names with dots (e.g., \"settings.orderBy\") are automatically\n";
        $result .= "converted to nested structures by TYPO3.";

        return $result;
    }

    /**
     * Format a single field for text output
     *
     * @param array $field The field data
     * @param string $indent Indentation prefix
     * @return string Formatted field text
     */
    protected function formatField(array $field, string $indent): string
    {
        $result = $indent . "- {$field['name']}";

        if ($field['label'] !== $field['name']) {
            $result .= " ({$field['label']})";
        }

        $result .= ": {$field['type']}";

        // Add field details based on type
        if (!empty($field['config'])) {
            TcaFormattingUtility::addFieldDetailsInline($result, $field['config']);
        }

        if (!empty($field['description'])) {
            $result .= " - {$field['description']}";
        }

        $result .= "\n";
        $result .= $indent . "  JSON Path: {$field['jsonPath']}\n";

        return $result;
    }

    /**
     * Build example JSON structure from field names
     */
    protected function buildJsonExample(array $fieldNames): array
    {
        $example = ['pi_flexform' => []];

        foreach ($fieldNames as $fieldName) {
            // Skip non-field entries
            if (strpos($fieldName, '.') === false) {
                $example['pi_flexform'][$fieldName] = '<' . $fieldName . ' value>';
            } else {
                // Handle nested structure
                $parts = explode('.', $fieldName);
                $current = &$example['pi_flexform'];

                // Navigate/create the nested structure
                for ($i = 0; $i < count($parts) - 1; $i++) {
                    if (!isset($current[$parts[$i]])) {
                        $current[$parts[$i]] = [];
                    }
                    $current = &$current[$parts[$i]];
                }

                // Set the final value
                $current[$parts[count($parts) - 1]] = '<' . $parts[count($parts) - 1] . ' value>';
            }
        }

        return $example;
    }
}
