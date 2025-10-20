#!/usr/bin/env php
<?php
declare(strict_types=1);

ini_set('display_errors', '1');

const XBRL_INSTANCE_NS = 'http://www.xbrl.org/2003/instance';
const XBRLDI_NS = 'http://xbrl.org/2005/xbrldi';
const LINK_NS = 'http://www.xbrl.org/2003/linkbase';
const XLINK_NS = 'http://www.w3.org/1999/xlink';
const XS_NS = 'http://www.w3.org/2001/XMLSchema';
const XSI_NS = 'http://www.w3.org/2001/XMLSchema-instance';
const XML_NS = 'http://www.w3.org/XML/1998/namespace';

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

function usage(): void
{
    $script = basename(__FILE__);
    $message = <<<TXT
Usage: php {$script} [--zip=path/to/instance.zip] [--cache-dir=path] [--schema=path] [--keep-temp]

Options:
  --zip        Path to the inline XBRL archive (.zip). Required.
  --cache-dir  Directory for caching downloaded taxonomy schemas (default: ./cache/taxonomy).
  --schema     Path to the SQL schema file to bootstrap the database (default: ./sql/schema.sql).
  --keep-temp  Keep the extracted temporary directory for inspection.

Database connection is controlled via environment variables:
  DB_DSN   (default: mysql:host=127.0.0.1;dbname=xbrl;charset=utf8mb4)
  DB_USER  (default: root)
  DB_PASS  (default: empty string)
TXT;
    fwrite(STDERR, $message . PHP_EOL);
}

function logInfo(string $message): void
{
    fwrite(STDOUT, '[INFO] ' . $message . PHP_EOL);
}

function logWarn(string $message): void
{
    fwrite(STDERR, '[WARN] ' . $message . PHP_EOL);
}

function logError(string $message): void
{
    fwrite(STDERR, '[ERROR] ' . $message . PHP_EOL);
}

function ensureDirectory(string $path): void
{
    if (!is_dir($path)) {
        if (!mkdir($path, 0777, true) && !is_dir($path)) {
            throw new RuntimeException('Unable to create directory: ' . $path);
        }
    }
}

function extractZip(string $zipPath, string $destination): void
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException('Failed to open zip archive: ' . $zipPath);
    }
    ensureDirectory($destination);
    if (!$zip->extractTo($destination)) {
        $zip->close();
        throw new RuntimeException('Failed to extract archive to ' . $destination);
    }
    $zip->close();
}

function findFirstFile(string $directory, string $needle): ?string
{
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
    foreach ($iterator as $file) {
        if ($file->isFile() && strcasecmp($file->getFilename(), $needle) === 0) {
            return $file->getPathname();
        }
    }
    return null;
}

function getNamespaceMap(DOMElement $element): array
{
    $map = [];
    if ($element->hasAttributes()) {
        foreach ($element->attributes as $attr) {
            if ($attr->nodeName === 'xmlns') {
                $map[''] = $attr->nodeValue;
            } elseif ($attr->prefix === 'xmlns') {
                $map[$attr->localName] = $attr->nodeValue;
            }
        }
    }
    return $map;
}

function findPrefixForNamespace(array $namespaceMap, ?string $namespace): ?string
{
    foreach ($namespaceMap as $prefix => $ns) {
        if ($ns === $namespace) {
            return $prefix === '' ? null : $prefix;
        }
    }
    return null;
}

function resolveSchemaLocation(string $schemaLocation, string $currentPath, string $cacheDir): ?string
{
    if ($schemaLocation === '') {
        return null;
    }

    if (preg_match('/^https?:\/\//i', $schemaLocation)) {
        ensureDirectory($cacheDir);
        $urlPath = parse_url($schemaLocation, PHP_URL_PATH) ?? '';
        $extension = pathinfo($urlPath, PATHINFO_EXTENSION);
        $hash = hash('sha256', $schemaLocation);
        $localName = $hash . ($extension ? '.' . $extension : '.xsd');
        $localPath = rtrim($cacheDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $localName;
        if (!file_exists($localPath)) {
            logInfo('Downloading taxonomy schema: ' . $schemaLocation);
            $context = stream_context_create([
                'http' => ['timeout' => 30],
                'https' => ['timeout' => 30],
            ]);
            $data = @file_get_contents($schemaLocation, false, $context);
            if ($data === false) {
                logWarn('Unable to download schema: ' . $schemaLocation);
                return null;
            }
            file_put_contents($localPath, $data);
        }
        return $localPath;
    }

    if (str_starts_with($schemaLocation, 'file://')) {
        $schemaLocation = substr($schemaLocation, 7);
    }

    $baseDir = dirname($currentPath);
    $candidate = $baseDir . DIRECTORY_SEPARATOR . $schemaLocation;
    if (file_exists($candidate)) {
        return realpath($candidate) ?: $candidate;
    }
    if (file_exists($schemaLocation)) {
        return realpath($schemaLocation) ?: $schemaLocation;
    }
    logWarn('Unable to resolve schema location: ' . $schemaLocation . ' referenced from ' . $currentPath);
    return null;
}

function parseTaxonomy(string $taxonomyPath, string $cacheDir): array
{
    $concepts = [];
    $linkbaseRefs = [];
    $roleRefs = [];
    $queue = [];
    $visited = [];

    $taxonomyPath = realpath($taxonomyPath) ?: $taxonomyPath;
    $queue[] = $taxonomyPath;

    while ($queue) {
        $path = array_pop($queue);
        if (!$path || isset($visited[$path])) {
            continue;
        }
        $visited[$path] = true;

        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;
        if (@$dom->load($path) === false) {
            logWarn('Failed to load taxonomy schema: ' . $path);
            continue;
        }
        $schema = $dom->documentElement;
        if (!$schema || $schema->namespaceURI !== XS_NS) {
            continue;
        }

        $namespaceMap = getNamespaceMap($schema);
        $targetNamespace = $schema->getAttribute('targetNamespace');
        $prefixForTarget = findPrefixForNamespace($namespaceMap, $targetNamespace);

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('xs', XS_NS);
        $xpath->registerNamespace('link', LINK_NS);
        $xpath->registerNamespace('xlink', XLINK_NS);
        $xpath->registerNamespace('xbrli', XBRL_INSTANCE_NS);

        foreach ($xpath->query('//link:linkbaseRef') as $linkbaseNode) {
            /** @var DOMElement $linkbaseNode */
            $linkbaseRefs[] = [
                'targetNamespace' => $targetNamespace,
                'href' => $linkbaseNode->getAttributeNS(XLINK_NS, 'href') ?: $linkbaseNode->getAttribute('xlink:href'),
                'role' => $linkbaseNode->getAttributeNS(XLINK_NS, 'role') ?: $linkbaseNode->getAttribute('xlink:role'),
                'arcrole' => $linkbaseNode->getAttributeNS(XLINK_NS, 'arcrole') ?: $linkbaseNode->getAttribute('xlink:arcrole'),
                'type' => $linkbaseNode->getAttributeNS(XLINK_NS, 'type') ?: $linkbaseNode->getAttribute('xlink:type'),
            ];
        }

        foreach ($xpath->query('//link:roleRef') as $roleRef) {
            /** @var DOMElement $roleRef */
            $roleRefs[] = [
                'targetNamespace' => $targetNamespace,
                'roleUri' => $roleRef->getAttribute('roleURI') ?: $roleRef->getAttribute('roleUri'),
                'href' => $roleRef->getAttributeNS(XLINK_NS, 'href') ?: $roleRef->getAttribute('xlink:href'),
            ];
        }

        /** @var DOMElement $element */
        foreach ($xpath->query('//xs:element[@name]') as $element) {
            $name = $element->getAttribute('name');
            if ($name === '') {
                continue;
            }
            $substitutionGroup = $element->getAttribute('substitutionGroup');
            if ($substitutionGroup && str_starts_with($substitutionGroup, 'link:')) {
                // Skip linkbase-specific elements.
                continue;
            }

            $idAttr = $element->getAttribute('id') ?: null;
            $type = $element->getAttribute('type') ?: null;
            $abstractFlag = strtolower($element->getAttribute('abstract')) === 'true';
            $nillable = $element->hasAttribute('nillable') ? strtolower($element->getAttribute('nillable')) !== 'false' : true;

            $xbrliAttributes = [];
            if ($element->hasAttributes()) {
                foreach ($element->attributes as $attr) {
                    if ($attr->namespaceURI === XBRL_INSTANCE_NS) {
                        $xbrliAttributes[$attr->localName] = $attr->nodeValue;
                    }
                }
            }

            $periodType = $xbrliAttributes['periodType'] ?? null;
            $balance = $xbrliAttributes['balance'] ?? null;

            $documentation = null;
            /** @var DOMElement|null $documentationNode */
            $documentationNode = $xpath->query('./xs:annotation/xs:documentation', $element)->item(0);
            if ($documentationNode) {
                $documentation = trim($documentationNode->textContent);
            }

            $prefix = $prefixForTarget;
            if ($prefix === null) {
                $prefix = '';
            }
            $qname = $prefix ? $prefix . ':' . $name : $name;
            $conceptKey = ($targetNamespace ?: '') . '#' . $name;

            if (!isset($concepts[$conceptKey])) {
                $concepts[$conceptKey] = [
                    'namespace' => $targetNamespace,
                    'local_name' => $name,
                    'qname' => $qname,
                    'id_attr' => $idAttr,
                    'substitution_group' => $substitutionGroup ?: null,
                    'type' => $type,
                    'period_type' => $periodType,
                    'balance' => $balance,
                    'abstract' => $abstractFlag,
                    'nillable' => $nillable,
                    'documentation' => $documentation,
                ];
            } else {
                $concepts[$conceptKey] = array_replace($concepts[$conceptKey], [
                    'substitution_group' => $substitutionGroup ?: $concepts[$conceptKey]['substitution_group'],
                    'type' => $type ?: $concepts[$conceptKey]['type'],
                    'period_type' => $periodType ?: $concepts[$conceptKey]['period_type'],
                    'balance' => $balance ?: $concepts[$conceptKey]['balance'],
                    'documentation' => $concepts[$conceptKey]['documentation'] ?: $documentation,
                ]);
            }
        }

        foreach ($xpath->query('/xs:schema/xs:import | /xs:schema/xs:include | /xs:schema/xs:redefine') as $importNode) {
            /** @var DOMElement $importNode */
            $schemaLocation = $importNode->getAttribute('schemaLocation');
            if ($schemaLocation === '') {
                continue;
            }
            $resolved = resolveSchemaLocation($schemaLocation, $path, $cacheDir);
            if ($resolved) {
                $queue[] = $resolved;
            }
        }
    }

    return [
        'concepts' => $concepts,
        'linkbases' => $linkbaseRefs,
        'roles' => $roleRefs,
    ];
}

function determinePeriodType(?string $startDate, ?string $endDate, ?string $instant): ?string
{
    if ($startDate !== null && $endDate !== null) {
        return 'duration';
    }
    if ($instant !== null) {
        return 'instant';
    }
    return 'forever';
}

function getInnerXml(DOMNode $node): string
{
    $inner = '';
    foreach ($node->childNodes as $child) {
        $inner .= $node->ownerDocument->saveXML($child);
    }
    return $inner;
}

function resolveQName(DOMNode $contextNode, string $qname): array
{
    $prefix = null;
    $local = $qname;
    if (str_contains($qname, ':')) {
        [$prefix, $local] = explode(':', $qname, 2);
    }
    $namespace = $prefix !== null ? $contextNode->lookupNamespaceURI($prefix) : $contextNode->lookupNamespaceURI(null);
    return [
        'qname' => $qname,
        'namespace' => $namespace ?: '',
        'localName' => $local,
        'prefix' => $prefix,
    ];
}

function extractContextMembers(DOMXPath $xpath, DOMElement $contextNode, string $location): array
{
    $members = [];
    $typed = [];
    $path = $location === 'segment'
        ? './xbrli:entity/xbrli:segment'
        : './xbrli:scenario';

    foreach ($xpath->query($path, $contextNode) as $locationNode) {
        /** @var DOMElement $locationNode */
        foreach ($xpath->query('.//xbrldi:explicitMember', $locationNode) as $memberNode) {
            /** @var DOMElement $memberNode */
            $dimension = $memberNode->getAttribute('dimension');
            $value = trim($memberNode->textContent);
            $members[] = [
                'dimension' => resolveQName($memberNode, $dimension),
                'member' => resolveQName($memberNode, $value),
            ];
        }
        foreach ($xpath->query('.//xbrldi:typedMember', $locationNode) as $memberNode) {
            /** @var DOMElement $memberNode */
            $dimension = $memberNode->getAttribute('dimension');
            $typed[] = [
                'dimension' => resolveQName($memberNode, $dimension),
                'xml' => getInnerXml($memberNode),
            ];
        }
    }

    return [
        'explicit' => $members,
        'typed' => $typed,
    ];
}

function parseInstance(string $instancePath): array
{
    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = false;
    if (@$dom->load($instancePath) === false) {
        throw new RuntimeException('Unable to parse XBRL instance: ' . $instancePath);
    }

    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('xbrli', XBRL_INSTANCE_NS);
    $xpath->registerNamespace('xbrldi', XBRLDI_NS);
    $xpath->registerNamespace('link', LINK_NS);
    $xpath->registerNamespace('xlink', XLINK_NS);
    $xpath->registerNamespace('xsi', XSI_NS);

    $contexts = [];
    foreach ($xpath->query('/xbrli:xbrl/xbrli:context') as $contextNode) {
        /** @var DOMElement $contextNode */
        $contextId = $contextNode->getAttribute('id');
        if ($contextId === '') {
            continue;
        }
        $identifierNode = $xpath->query('./xbrli:entity/xbrli:identifier', $contextNode)->item(0);
        $entityIdentifier = $identifierNode ? trim($identifierNode->textContent) : null;
        $entityScheme = $identifierNode ? $identifierNode->getAttribute('scheme') : null;

        $startDateNode = $xpath->query('./xbrli:period/xbrli:startDate', $contextNode)->item(0);
        $endDateNode = $xpath->query('./xbrli:period/xbrli:endDate', $contextNode)->item(0);
        $instantNode = $xpath->query('./xbrli:period/xbrli:instant', $contextNode)->item(0);

        $startDate = $startDateNode ? $startDateNode->textContent : null;
        $endDate = $endDateNode ? $endDateNode->textContent : null;
        $instant = $instantNode ? $instantNode->textContent : null;

        $periodType = determinePeriodType($startDate, $endDate, $instant);

        $segments = extractContextMembers($xpath, $contextNode, 'segment');
        $scenarios = extractContextMembers($xpath, $contextNode, 'scenario');

        $contexts[$contextId] = [
            'id' => $contextId,
            'entity_identifier' => $entityIdentifier,
            'entity_scheme' => $entityScheme,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'instant' => $instant,
            'period_type' => $periodType,
            'segments' => $segments,
            'scenarios' => $scenarios,
        ];
    }

    $units = [];
    foreach ($xpath->query('/xbrli:xbrl/xbrli:unit') as $unitNode) {
        /** @var DOMElement $unitNode */
        $unitId = $unitNode->getAttribute('id');
        if ($unitId === '') {
            continue;
        }
        $measureNodes = $xpath->query('./xbrli:measure', $unitNode);
        if ($measureNodes->length > 0) {
            $measures = [];
            foreach ($measureNodes as $measureNode) {
                $measures[] = trim($measureNode->textContent);
            }
            $units[$unitId] = [
                'id' => $unitId,
                'type' => 'measure',
                'measures' => $measures,
            ];
            continue;
        }
        $divideNode = $xpath->query('./xbrli:divide', $unitNode)->item(0);
        if ($divideNode instanceof DOMElement) {
            $numerators = [];
            $denominators = [];
            foreach ($xpath->query('./xbrli:unitNumerator/xbrli:measure', $divideNode) as $measureNode) {
                $numerators[] = trim($measureNode->textContent);
            }
            foreach ($xpath->query('./xbrli:unitDenominator/xbrli:measure', $divideNode) as $measureNode) {
                $denominators[] = trim($measureNode->textContent);
            }
            $units[$unitId] = [
                'id' => $unitId,
                'type' => 'divide',
                'measures' => [
                    'numerators' => $numerators,
                    'denominators' => $denominators,
                ],
            ];
        }
    }

    $facts = [];
    $missingConcepts = [];
    foreach ($xpath->query('//*[@contextRef]') as $factNode) {
        /** @var DOMElement $factNode */
        $contextRef = $factNode->getAttribute('contextRef');
        $conceptNamespace = $factNode->namespaceURI ?: '';
        $localName = $factNode->localName;
        $prefix = $factNode->prefix;
        $qname = $prefix ? $prefix . ':' . $localName : $localName;
        $unitRef = $factNode->hasAttribute('unitRef') ? $factNode->getAttribute('unitRef') : null;
        $decimals = $factNode->hasAttribute('decimals') ? $factNode->getAttribute('decimals') : null;
        $precision = $factNode->hasAttribute('precision') ? $factNode->getAttribute('precision') : null;
        $language = $factNode->getAttributeNS(XML_NS, 'lang') ?: null;
        $isNil = strtolower($factNode->getAttributeNS(XSI_NS, 'nil') ?? '') === 'true';
        $value = null;
        $raw = null;
        if (!$isNil) {
            $raw = trim($factNode->textContent);
            $value = $raw;
        }
        $facts[] = [
            'namespace' => $conceptNamespace,
            'local_name' => $localName,
            'qname' => $qname,
            'contextRef' => $contextRef,
            'unitRef' => $unitRef,
            'decimals' => $decimals,
            'precision' => $precision,
            'language' => $language,
            'is_nil' => $isNil,
            'value' => $value,
            'raw' => $raw,
            'xsi_type' => $factNode->getAttributeNS(XSI_NS, 'type') ?: null,
        ];

        $conceptKey = $conceptNamespace . '#' . $localName;
        if (!isset($missingConcepts[$conceptKey])) {
            $missingConcepts[$conceptKey] = [
                'namespace' => $conceptNamespace,
                'local_name' => $localName,
                'qname' => $qname,
                'id_attr' => null,
                'substitution_group' => null,
                'type' => $factNode->getAttributeNS(XSI_NS, 'type') ?: null,
                'period_type' => null,
                'balance' => null,
                'abstract' => false,
                'nillable' => true,
                'documentation' => null,
            ];
        }
    }

    return [
        'contexts' => $contexts,
        'units' => $units,
        'facts' => $facts,
        'missingConcepts' => $missingConcepts,
    ];
}

function ensureSchema(PDO $pdo, string $schemaPath): void
{
    if (!file_exists($schemaPath)) {
        throw new RuntimeException('Schema file not found: ' . $schemaPath);
    }
    $sql = file_get_contents($schemaPath);
    if ($sql === false) {
        throw new RuntimeException('Unable to read schema file: ' . $schemaPath);
    }
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }
}

function upsertDocument(PDO $pdo, string $zipPath, string $instancePath): int
{
    $hash = hash_file('sha256', $instancePath);
    $stmt = $pdo->prepare('INSERT INTO xbrl_documents (document_name, document_hash) VALUES (?, ?) ON DUPLICATE KEY UPDATE document_name = VALUES(document_name), id = LAST_INSERT_ID(id)');
    $stmt->execute([
        basename($zipPath),
        $hash,
    ]);
    return (int)$pdo->lastInsertId();
}

function insertTaxonomyConcepts(PDO $pdo, array $concepts): array
{
    $stmt = $pdo->prepare('INSERT INTO xbrl_taxonomy_concepts (namespace, local_name, qname, id_attr, substitution_group, type, period_type, balance, abstract_flag, nillable_flag, documentation) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE substitution_group = VALUES(substitution_group), type = VALUES(type), period_type = VALUES(period_type), balance = VALUES(balance), abstract_flag = VALUES(abstract_flag), nillable_flag = VALUES(nillable_flag), documentation = VALUES(documentation), id = LAST_INSERT_ID(id)');
    $map = [];
    foreach ($concepts as $key => $concept) {
        $stmt->execute([
            $concept['namespace'] ?? '',
            $concept['local_name'] ?? '',
            $concept['qname'] ?? ($concept['local_name'] ?? ''),
            $concept['id_attr'] ?? null,
            $concept['substitution_group'] ?? null,
            $concept['type'] ?? null,
            $concept['period_type'] ?? null,
            $concept['balance'] ?? null,
            !empty($concept['abstract']) ? 1 : 0,
            array_key_exists('nillable', $concept) ? (!empty($concept['nillable']) ? 1 : 0) : 1,
            $concept['documentation'] ?? null,
        ]);
        $map[$key] = (int)$pdo->lastInsertId();
    }
    return $map;
}

function insertTaxonomyRefs(PDO $pdo, int $documentId, array $taxonomyData): void
{
    $pdo->prepare('DELETE FROM xbrl_taxonomy_linkbases WHERE document_id = ?')->execute([$documentId]);
    $pdo->prepare('DELETE FROM xbrl_taxonomy_role_refs WHERE document_id = ?')->execute([$documentId]);

    if (!empty($taxonomyData['linkbases'])) {
        $stmt = $pdo->prepare('INSERT INTO xbrl_taxonomy_linkbases (document_id, target_namespace, href, role, arcrole, linkbase_type) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($taxonomyData['linkbases'] as $linkbase) {
            $stmt->execute([
                $documentId,
                $linkbase['targetNamespace'] ?? null,
                $linkbase['href'] ?? null,
                $linkbase['role'] ?? null,
                $linkbase['arcrole'] ?? null,
                $linkbase['type'] ?? null,
            ]);
        }
    }

    if (!empty($taxonomyData['roles'])) {
        $stmt = $pdo->prepare('INSERT INTO xbrl_taxonomy_role_refs (document_id, role_uri, href) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE href = VALUES(href)');
        foreach ($taxonomyData['roles'] as $role) {
            if (empty($role['roleUri'])) {
                continue;
            }
            $stmt->execute([
                $documentId,
                $role['roleUri'],
                $role['href'] ?? null,
            ]);
        }
    }
}

function insertContexts(PDO $pdo, int $documentId, array $contexts): void
{
    $pdo->prepare('DELETE FROM xbrl_context_dimensions WHERE document_id = ?')->execute([$documentId]);
    $pdo->prepare('DELETE FROM xbrl_contexts WHERE document_id = ?')->execute([$documentId]);

    if (empty($contexts)) {
        return;
    }

    $contextStmt = $pdo->prepare('INSERT INTO xbrl_contexts (document_id, context_id, entity_identifier, entity_scheme, period_type, start_date, end_date, instant, segment_json, scenario_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $dimensionStmt = $pdo->prepare('INSERT INTO xbrl_context_dimensions (document_id, context_id, location, dimension, member, is_typed, typed_member_xml) VALUES (?, ?, ?, ?, ?, ?, ?)');

    foreach ($contexts as $context) {
        $segmentJson = !empty($context['segments']) ? json_encode($context['segments'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        $scenarioJson = !empty($context['scenarios']) ? json_encode($context['scenarios'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

        $contextStmt->execute([
            $documentId,
            $context['id'],
            $context['entity_identifier'],
            $context['entity_scheme'],
            $context['period_type'],
            $context['start_date'],
            $context['end_date'],
            $context['instant'],
            $segmentJson,
            $scenarioJson,
        ]);

        foreach ($context['segments']['explicit'] ?? [] as $member) {
            $dimensionStmt->execute([
                $documentId,
                $context['id'],
                'segment',
                $member['dimension']['qname'] ?? null,
                $member['member']['qname'] ?? null,
                0,
                null,
            ]);
        }
        foreach ($context['segments']['typed'] ?? [] as $member) {
            $dimensionStmt->execute([
                $documentId,
                $context['id'],
                'segment',
                $member['dimension']['qname'] ?? null,
                null,
                1,
                $member['xml'] ?? null,
            ]);
        }
        foreach ($context['scenarios']['explicit'] ?? [] as $member) {
            $dimensionStmt->execute([
                $documentId,
                $context['id'],
                'scenario',
                $member['dimension']['qname'] ?? null,
                $member['member']['qname'] ?? null,
                0,
                null,
            ]);
        }
        foreach ($context['scenarios']['typed'] ?? [] as $member) {
            $dimensionStmt->execute([
                $documentId,
                $context['id'],
                'scenario',
                $member['dimension']['qname'] ?? null,
                null,
                1,
                $member['xml'] ?? null,
            ]);
        }
    }
}

function insertUnits(PDO $pdo, int $documentId, array $units): void
{
    $pdo->prepare('DELETE FROM xbrl_units WHERE document_id = ?')->execute([$documentId]);
    if (empty($units)) {
        return;
    }
    $stmt = $pdo->prepare('INSERT INTO xbrl_units (document_id, unit_id, unit_type, measures_json) VALUES (?, ?, ?, ?)');
    foreach ($units as $unit) {
        $measuresJson = json_encode($unit['measures'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt->execute([
            $documentId,
            $unit['id'],
            $unit['type'],
            $measuresJson,
        ]);
    }
}

function normalizeDecimal(?string $value): ?string
{
    if ($value === null) {
        return null;
    }
    $trimmed = trim($value);
    if ($trimmed === '') {
        return null;
    }
    if (preg_match('/^-?\\d+(?:\\.\\d+)?$/', $trimmed)) {
        return $trimmed;
    }
    return null;
}

function insertFacts(PDO $pdo, int $documentId, array $facts, array $conceptIdMap): void
{
    $pdo->prepare('DELETE FROM xbrl_facts WHERE document_id = ?')->execute([$documentId]);
    if (empty($facts)) {
        return;
    }
    $stmt = $pdo->prepare('INSERT INTO xbrl_facts (document_id, concept_id, concept_namespace, concept_local_name, concept_qname, context_id, unit_id, value_decimal, value_string, decimals_attr, precision_attr, language, is_nil) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($facts as $fact) {
        $conceptKey = ($fact['namespace'] ?? '') . '#' . ($fact['local_name'] ?? '');
        $conceptId = $conceptIdMap[$conceptKey] ?? null;
        $valueDecimal = normalizeDecimal($fact['value']);
        $valueString = $fact['raw'];
        $stmt->execute([
            $documentId,
            $conceptId,
            $fact['namespace'] ?? '',
            $fact['local_name'] ?? '',
            $fact['qname'] ?? '',
            $fact['contextRef'] ?? '',
            $fact['unitRef'] ?? null,
            $valueDecimal,
            $valueString,
            $fact['decimals'] ?? null,
            $fact['precision'] ?? null,
            $fact['language'] ?? null,
            !empty($fact['is_nil']) ? 1 : 0,
        ]);
    }
}

function removeDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($directory);
}

function main(array $argv): int
{
    $options = getopt('', ['zip:', 'cache-dir::', 'schema::', 'keep-temp']);
    $zipPath = $options['zip'] ?? ($argv[1] ?? null);
    if (!$zipPath) {
        usage();
        return 1;
    }
    if (!file_exists($zipPath)) {
        logError('Zip archive not found: ' . $zipPath);
        return 1;
    }

    $baseDir = dirname(__DIR__);
    $defaultCache = $baseDir . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'taxonomy';
    $cacheDir = $options['cache-dir'] ?? $defaultCache;
    $schemaPath = $options['schema'] ?? ($baseDir . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . 'schema.sql');
    $keepTemp = isset($options['keep-temp']);

    $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'xbrl_' . uniqid();
    extractZip($zipPath, $tempDir);

    $instancePath = findFirstFile($tempDir, 'instance.xbrl');
    $taxonomyPath = findFirstFile($tempDir, 'Taxonomy.xsd');
    if (!$instancePath || !$taxonomyPath) {
        logError('The archive must contain instance.xbrl and Taxonomy.xsd');
        if (!$keepTemp) {
            removeDirectory($tempDir);
        }
        return 1;
    }

    logInfo('Parsing taxonomy definitions ...');
    $taxonomyData = parseTaxonomy($taxonomyPath, $cacheDir);

    logInfo('Parsing XBRL instance ...');
    $instanceData = parseInstance($instancePath);

    foreach ($instanceData['missingConcepts'] as $key => $concept) {
        if (!isset($taxonomyData['concepts'][$key])) {
            $taxonomyData['concepts'][$key] = $concept;
        }
    }

    $dsn = getenv('DB_DSN') ?: 'mysql:host=127.0.0.1;dbname=xbrl;charset=utf8mb4';
    $user = getenv('DB_USER') ?: 'root';
    $pass = getenv('DB_PASS') ?: '';

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    ensureSchema($pdo, $schemaPath);

    $pdo->beginTransaction();
    try {
        $documentId = upsertDocument($pdo, $zipPath, $instancePath);
        insertTaxonomyRefs($pdo, $documentId, $taxonomyData);
        $conceptIdMap = insertTaxonomyConcepts($pdo, $taxonomyData['concepts']);
        insertContexts($pdo, $documentId, $instanceData['contexts']);
        insertUnits($pdo, $documentId, $instanceData['units']);
        insertFacts($pdo, $documentId, $instanceData['facts'], $conceptIdMap);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    logInfo('Import completed successfully.');
    logInfo(sprintf('Contexts: %d, Units: %d, Facts: %d, Concepts: %d',
        count($instanceData['contexts']),
        count($instanceData['units']),
        count($instanceData['facts']),
        count($taxonomyData['concepts'])
    ));

    if (!$keepTemp) {
        removeDirectory($tempDir);
    } else {
        logInfo('Temporary files retained at ' . $tempDir);
    }

    return 0;
}

exit(main($argv));
