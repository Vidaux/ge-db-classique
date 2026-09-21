<?php
// Ensure script runs with its own directory as the working directory so all relative paths resolve
chdir(__DIR__);
$ProjectRoot = dirname(__DIR__);
$WebOutputDir = $ProjectRoot;
$WebImageRoot = $WebOutputDir.DIRECTORY_SEPARATOR.'images';
$GeDataRoot = $ProjectRoot.DIRECTORY_SEPARATOR.'ge';
$GeneratedWebFiles = array();
$GeneratedWriteStats = array(
	'written' => 0,
	'unchanged' => 0,
	'removed' => 0,
	'failed' => 0,
);

function EnsureDirectory($path) {
	if (!is_dir($path)) {
		mkdir($path, 0777, true);
	}
}

EnsureDirectory($WebImageRoot);
foreach (array('Barrack', 'Items', 'Misc', 'Skills') as $WebImageFolder) {
	EnsureDirectory($WebImageRoot.DIRECTORY_SEPARATOR.$WebImageFolder);
}

function WebOutputPath($relativePath) {
	global $WebOutputDir;
	return $WebOutputDir.DIRECTORY_SEPARATOR.str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $relativePath);
}

function GeneratedManifestPath() {
	return __DIR__.DIRECTORY_SEPARATOR.'generated-web-files.json';
}

function GeneratedRelativePath($path) {
	global $WebOutputDir;
	$root = rtrim(str_replace('\\', '/', $WebOutputDir), '/');
	$normalizedPath = str_replace('\\', '/', $path);
	if ($normalizedPath === $root) {
		return '';
	}
	if (strpos($normalizedPath, $root.'/') !== 0) {
		return null;
	}
	return substr($normalizedPath, strlen($root) + 1);
}

function GeneratedContentHash($path, $content) {
	if (preg_match('/\.html?$/i', (string)$path)) {
		$content = preg_replace('/<div id="lastModified">.*?<\/div>/s', '<div id="lastModified">Last modified: [[normalized]]</div>', $content);
	}
	return sha1((string)$content);
}

function RegisterGeneratedWebFile($path) {
	global $GeneratedWebFiles;
	$relativePath = GeneratedRelativePath($path);
	if ($relativePath !== null && $relativePath !== '') {
		$GeneratedWebFiles[$relativePath] = true;
	}
}

function WriteGeneratedFile($path, $content) {
	global $GeneratedWriteStats;
	RegisterGeneratedWebFile($path);
	EnsureDirectory(dirname($path));

	if (is_file($path)) {
		$oldContent = file_get_contents($path);
		if ($oldContent !== false && GeneratedContentHash($path, $oldContent) === GeneratedContentHash($path, $content)) {
			$GeneratedWriteStats['unchanged']++;
			return false;
		}
	}

	if (file_put_contents($path, $content) === false) {
		$GeneratedWriteStats['failed']++;
		return false;
	}
	$GeneratedWriteStats['written']++;
	return true;
}

function RemoveEmptyGeneratedDirectories($startDirectory) {
	global $WebOutputDir;
	$root = rtrim(str_replace('\\', '/', $WebOutputDir), '/');
	$directory = rtrim($startDirectory, DIRECTORY_SEPARATOR);
	while (is_dir($directory)) {
		$normalizedDirectory = str_replace('\\', '/', $directory);
		if ($normalizedDirectory === $root || strpos($normalizedDirectory, $root.'/') !== 0) {
			break;
		}
		$entries = @scandir($directory);
		if ($entries === false || count(array_diff($entries, array('.', '..'))) > 0) {
			break;
		}
		if (!@rmdir($directory)) {
			break;
		}
		$directory = dirname($directory);
	}
}

function FinalizeGeneratedWebManifest() {
	global $GeneratedWebFiles, $GeneratedWriteStats;
	$manifestPath = GeneratedManifestPath();
	$previous = ReadJsonFile($manifestPath, array('files' => array()));
	$previousFiles = isset($previous['files']) && is_array($previous['files']) ? $previous['files'] : array();
	$currentFiles = array_keys($GeneratedWebFiles);
	sort($currentFiles, SORT_NATURAL);
	$currentLookup = array_fill_keys($currentFiles, true);

	foreach ($previousFiles as $relativePath) {
		if (isset($currentLookup[$relativePath])) {
			continue;
		}
		$path = WebOutputPath($relativePath);
		if (is_file($path) || is_link($path)) {
			@chmod($path, 0666);
			if (@unlink($path)) {
				$GeneratedWriteStats['removed']++;
				RemoveEmptyGeneratedDirectories(dirname($path));
			}
		}
	}

	WriteJsonFile($manifestPath, array(
		'schema' => 1,
		'generatedAt' => date('c'),
		'files' => $currentFiles,
	));
}

function GeDataPath($relativePath) {
	global $GeDataRoot;
	return $GeDataRoot.DIRECTORY_SEPARATOR.str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $relativePath);
}

function GeDataGlob($relativePattern) {
	return glob(GeDataPath($relativePattern)) ?: array();
}

function ChangelogStatePath($fileName) {
	return __DIR__.DIRECTORY_SEPARATOR.$fileName;
}

function ReadJsonFile($path, $defaultValue) {
	if (!file_exists($path)) {
		return $defaultValue;
	}
	$content = file_get_contents($path);
	if ($content === false || trim($content) === '') {
		return $defaultValue;
	}
	$decoded = json_decode($content, true);
	return is_array($decoded) ? $decoded : $defaultValue;
}

function WriteJsonFile($path, $data) {
	EnsureDirectory(dirname($path));
	file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
}

function ExtractChangelogFiles() {
	$patterns = array(
		'xml/datatable_accomplishment.xml',
		'xml/datatable_itemmake.xml',
		'xml/datatable_itemopt.xml',
		'xml/datatable_item_*.xml',
		'xml/datatable_job.xml',
		'xml/datatable_stance.xml',
		'xml/datatable_stancecondition.xml',
		'xml/datatable_skill.xml',
		'xml/datatable_npclist.xml',
		'xml/datatable_expedition_tag.xml',
		'xml/datatable_buff.xml',
		'xml/datatable_monster*.xml',
		'xml/datatable_map.xml',
		'xml/datatable_raid_respawntime.xml',
	);
	$files = array();
	foreach ($patterns as $pattern) {
		foreach (GeDataGlob($pattern) as $path) {
			$files[basename($path)] = $path;
		}
	}
	ksort($files, SORT_NATURAL);
	return $files;
}

function ChangelogTableLabel($fileName) {
	$baseName = preg_replace('/\.xml$/i', '', $fileName);
	$labels = array(
		'datatable_accomplishment' => 'Achievements',
		'datatable_itemmake' => 'Recipes',
		'datatable_itemopt' => 'Enchant options',
		'datatable_item_weapon' => 'Weapons',
		'datatable_item_armor' => 'Armor',
		'datatable_item_consume' => 'Consumables',
		'datatable_item_etc' => 'Items',
		'datatable_item_event' => 'Event items',
		'datatable_item_pet' => 'Pets',
		'datatable_item_recipe' => 'Recipe items',
		'datatable_item_glove' => 'Gloves',
		'datatable_item_belt' => 'Belts',
		'datatable_item_neck' => 'Necklaces',
		'datatable_item_earring' => 'Earrings',
		'datatable_item_ring' => 'Rings',
		'datatable_item_boots' => 'Shoes',
		'datatable_item_achieve' => 'Medals',
		'datatable_job' => 'Characters',
		'datatable_stance' => 'Stances',
		'datatable_stancecondition' => 'Stance conditions',
		'datatable_skill' => 'Skills',
		'datatable_npclist' => 'NPC list',
		'datatable_expedition_tag' => 'Character tags',
		'datatable_buff' => 'Buffs',
		'datatable_map' => 'Maps',
		'datatable_raid_respawntime' => 'Boss respawns',
	);
	if (isset($labels[$baseName])) {
		return $labels[$baseName];
	}
	if (strpos($baseName, 'datatable_item_') === 0) {
		return 'Items';
	}
	if (strpos($baseName, 'datatable_monster') === 0) {
		return 'Monsters';
	}
	return ucwords(str_replace('_', ' ', preg_replace('/^datatable_/', '', $baseName)));
}

function ChangelogRecordLabel($attrs, $fileName = '') {
	$baseName = preg_replace('/\.xml$/i', '', (string)$fileName);
	if ($baseName === 'datatable_itemmake' && isset($attrs['Target']) && preg_match('/^-?\d+$/', trim((string)$attrs['Target']))) {
		$targetName = ChangelogDisplayItemName($attrs['Target']);
		if ($targetName !== '' && $targetName !== 'None' && $targetName !== 'Item '.(1 * $attrs['Target'])) {
			return $targetName;
		}
	}
	foreach (array('ItemName', 'EngName', 'Name', 'Title', 'RecipeName', 'TargetName', 'ClassName', 'Desc') as $key) {
		if (isset($attrs[$key]) && trim((string)$attrs[$key]) !== '' && (string)$attrs[$key] !== 'None') {
			return (string)$attrs[$key];
		}
	}
	return '';
}

function NormalizeXmlValue($value) {
	return trim(str_replace(array("\r\n", "\r"), "\n", (string)$value));
}

function ChangelogEncodeAttrs($attrs) {
	$json = json_encode($attrs, JSON_UNESCAPED_SLASHES);
	if (function_exists('gzencode')) {
		return 'gz64:'.base64_encode(gzencode($json, 9));
	}
	return 'json:'.$json;
}

function ChangelogDecodeAttrs($value) {
	if (is_array($value)) {
		return $value;
	}
	if (!is_string($value) || $value === '') {
		return null;
	}
	if (strpos($value, 'gz64:') === 0 && function_exists('gzdecode')) {
		$decoded = gzdecode(base64_decode(substr($value, 5)));
		return is_string($decoded) ? json_decode($decoded, true) : null;
	}
	if (strpos($value, 'json:') === 0) {
		return json_decode(substr($value, 5), true);
	}
	return json_decode($value, true);
}

function ChangelogItemLinkTarget($fileName, $recordId, $attrs) {
	$baseName = preg_replace('/\.xml$/i', '', $fileName);
	$categoryMap = array(
		'datatable_item_weapon' => 'Weapon',
		'datatable_item_armor' => 'Armor',
		'datatable_item_achieve' => 'Medalkis',
		'datatable_item_glove' => 'Accs',
		'datatable_item_belt' => 'Accs',
		'datatable_item_neck' => 'Accs',
		'datatable_item_earring' => 'Accs',
		'datatable_item_ring' => 'Accs',
		'datatable_item_boots' => 'Accs',
	);
	$pageFiles = array(
		'Weapon' => 'weapons.html',
		'Armor' => 'armor.html',
		'Accs' => 'accessories.html',
		'Medalkis' => 'medals.html',
	);
	if (!isset($categoryMap[$baseName])) {
		return null;
	}
	$category = $categoryMap[$baseName];
	$itemType = $attrs['Category2Name'] ?? ($attrs['Category2'] ?? ($attrs['ItemClass'] ?? ChangelogTableLabel($fileName)));
	if (trim((string)$itemType) === '' || (string)$itemType === 'None') {
		$itemType = ChangelogTableLabel($fileName);
	}
	$anchor = ItemAnchorId($category, $recordId);
	return array(
		'type' => 'item',
		'category' => $category,
		'itemType' => (string)$itemType,
		'anchor' => $anchor,
		'href' => './'.$pageFiles[$category].'#'.$anchor,
	);
}

function ChangelogRecordLinkTarget($fileName, $recordId, $attrs) {
	$itemTarget = ChangelogItemLinkTarget($fileName, $recordId, $attrs);
	if ($itemTarget !== null) {
		return $itemTarget;
	}

	if (preg_replace('/\.xml$/i', '', $fileName) === 'datatable_accomplishment') {
		$anchor = 'Achievement_'.HtmlIdSuffix($recordId);
		return array(
			'type' => 'achievement',
			'anchor' => $anchor,
			'href' => './achievements.html#'.$anchor,
			'achievementType' => (string)($attrs['Type'] ?? ''),
		);
	}

	return null;
}

function ChangelogRecordSnapshot($fileName, $recordId, $attrs) {
	return array(
		'label' => ChangelogRecordLabel($attrs, $fileName),
		'hash' => sha1(json_encode($attrs, JSON_UNESCAPED_SLASHES)),
		'attrs' => ChangelogEncodeAttrs($attrs),
	);
}

function BuildExtractSnapshot() {
	$snapshot = array(
		'schema' => 4,
		'generatedAt' => date('c'),
		'files' => array(),
	);
	foreach (ExtractChangelogFiles() as $fileName => $path) {
		$reader = new XMLReader();
		if (!$reader->open($path)) {
			continue;
		}
		$fileSnapshot = array(
			'label' => ChangelogTableLabel($fileName),
			'hash' => sha1_file($path),
			'records' => array(),
		);
		while ($reader->read()) {
			if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'Class') {
				continue;
			}
			$attrs = array();
			if ($reader->moveToFirstAttribute()) {
				do {
					$attrs[$reader->name] = NormalizeXmlValue($reader->value);
				} while ($reader->moveToNextAttribute());
				$reader->moveToElement();
			}

			$id = $attrs['ClassID'] ?? ($attrs['ClassName'] ?? null);
			if ($id === null || $id === '') {
				continue;
			}
			ksort($attrs, SORT_NATURAL);
			$fileSnapshot['records'][(string)$id] = ChangelogRecordSnapshot($fileName, (string)$id, $attrs);
		}
		$reader->close();
		ksort($fileSnapshot['records'], SORT_NATURAL);
		$snapshot['files'][$fileName] = $fileSnapshot;
	}
	return $snapshot;
}

function FormatChangelogCount($count, $label) {
	return $count.' '.$label.($count === 1 ? '' : 's');
}

function ChangelogRecordForDetail($fileName, $recordId, $record) {
	$attrs = ChangelogDecodeAttrs($record['attrs'] ?? null);
	return array(
		'id' => (string)$recordId,
		'label' => $record['label'] ?? (string)$recordId,
		'file' => $fileName,
		'table' => ChangelogTableLabel($fileName),
		'link' => is_array($attrs) ? ChangelogRecordLinkTarget($fileName, $recordId, $attrs) : ($record['link'] ?? null),
	);
}

function ChangelogRecordFieldChanges($previousRecord, $currentRecord) {
	$previousAttrs = ChangelogDecodeAttrs($previousRecord['attrs'] ?? null);
	$currentAttrs = ChangelogDecodeAttrs($currentRecord['attrs'] ?? null);
	if (!is_array($previousAttrs) || !is_array($currentAttrs)) {
		return array();
	}
	$keys = array_unique(array_merge(array_keys($previousAttrs), array_keys($currentAttrs)));
	sort($keys, SORT_NATURAL);
	$changes = array();
	foreach ($keys as $key) {
		$before = array_key_exists($key, $previousAttrs) ? (string)$previousAttrs[$key] : '';
		$after = array_key_exists($key, $currentAttrs) ? (string)$currentAttrs[$key] : '';
		if ($before === $after) {
			continue;
		}
		$changes[] = array(
			'field' => (string)$key,
			'before' => $before,
			'after' => $after,
		);
	}
	return $changes;
}

function SummarizeExtractChanges($previous, $current) {
	$summary = array(
		'tables' => array(),
		'examples' => array(),
		'details' => array(),
		'totalAdded' => 0,
		'totalRemoved' => 0,
		'totalChanged' => 0,
	);
	foreach ($current['files'] as $fileName => $currentFile) {
		$previousFile = $previous['files'][$fileName] ?? array('records' => array(), 'label' => ChangelogTableLabel($fileName));
		$label = $currentFile['label'];
		$currentRecords = $currentFile['records'] ?? array();
		$previousRecords = $previousFile['records'] ?? array();
		$addedIds = array_values(array_diff(array_keys($currentRecords), array_keys($previousRecords)));
		$removedIds = array_values(array_diff(array_keys($previousRecords), array_keys($currentRecords)));
		$changedIds = array();
		foreach ($currentRecords as $recordId => $record) {
			if (isset($previousRecords[$recordId]) && ($previousRecords[$recordId]['hash'] ?? '') !== ($record['hash'] ?? '')) {
				$changedIds[] = $recordId;
			}
		}
		if (!$addedIds && !$removedIds && !$changedIds) {
			continue;
		}
		$detail = array(
			'file' => $fileName,
			'label' => $label,
			'added' => array(),
			'removed' => array(),
			'changed' => array(),
		);
		foreach ($addedIds as $id) {
			$detail['added'][] = ChangelogRecordForDetail($fileName, $id, $currentRecords[$id]);
		}
		foreach ($removedIds as $id) {
			$record = ChangelogRecordForDetail($fileName, $id, $previousRecords[$id]);
			$record['link'] = null;
			$detail['removed'][] = $record;
		}
		foreach ($changedIds as $id) {
			$record = ChangelogRecordForDetail($fileName, $id, $currentRecords[$id]);
			$record['fields'] = ChangelogRecordFieldChanges($previousRecords[$id], $currentRecords[$id]);
			$detail['changed'][] = $record;
		}
		$summary['tables'][] = array(
			'label' => $label,
			'added' => count($addedIds),
			'removed' => count($removedIds),
			'changed' => count($changedIds),
		);
		$summary['details'][] = $detail;
		$summary['totalAdded'] += count($addedIds);
		$summary['totalRemoved'] += count($removedIds);
		$summary['totalChanged'] += count($changedIds);
		foreach (array_slice($addedIds, 0, 3) as $id) {
			$name = $currentRecords[$id]['label'] ?: $id;
			$summary['examples'][] = 'Added '.$label.' entry: '.$name;
		}
		foreach (array_slice($removedIds, 0, 3) as $id) {
			$name = $previousRecords[$id]['label'] ?: $id;
			$summary['examples'][] = 'Removed '.$label.' entry: '.$name;
		}
		foreach (array_slice($changedIds, 0, 3) as $id) {
			$name = $currentRecords[$id]['label'] ?: ($previousRecords[$id]['label'] ?? $id);
			$summary['examples'][] = 'Updated '.$label.' entry: '.$name;
		}
	}
	foreach ($previous['files'] ?? array() as $fileName => $previousFile) {
		if (isset($current['files'][$fileName])) {
			continue;
		}
		$count = count($previousFile['records'] ?? array());
		$summary['tables'][] = array(
			'label' => $previousFile['label'] ?? ChangelogTableLabel($fileName),
			'added' => 0,
			'removed' => $count,
			'changed' => 0,
		);
		$detail = array(
			'file' => $fileName,
			'label' => $previousFile['label'] ?? ChangelogTableLabel($fileName),
			'added' => array(),
			'removed' => array(),
			'changed' => array(),
		);
		foreach (($previousFile['records'] ?? array()) as $recordId => $record) {
			$removedRecord = ChangelogRecordForDetail($fileName, $recordId, $record);
			$removedRecord['link'] = null;
			$detail['removed'][] = $removedRecord;
		}
		$summary['details'][] = $detail;
		$summary['totalRemoved'] += $count;
	}
	return $summary;
}

function BuildChangelogEntryItems($summary) {
	$items = array();
	$totals = array();
	if ($summary['totalAdded'] > 0) {
		$totals[] = FormatChangelogCount($summary['totalAdded'], 'added record');
	}
	if ($summary['totalChanged'] > 0) {
		$totals[] = FormatChangelogCount($summary['totalChanged'], 'changed record');
	}
	if ($summary['totalRemoved'] > 0) {
		$totals[] = FormatChangelogCount($summary['totalRemoved'], 'removed record');
	}
	if ($totals) {
		$items[] = 'Compared the latest IPF XML extracts against the previous snapshot: '.implode(', ', $totals).'.';
	}
	foreach (array_slice($summary['tables'], 0, 8) as $table) {
		$parts = array();
		if ($table['added']) {
			$parts[] = $table['added'].' added';
		}
		if ($table['changed']) {
			$parts[] = $table['changed'].' changed';
		}
		if ($table['removed']) {
			$parts[] = $table['removed'].' removed';
		}
		$items[] = $table['label'].': '.implode(', ', $parts).'.';
	}
	foreach (array_slice($summary['examples'], 0, 10) as $example) {
		$items[] = $example.'.';
	}
	return $items;
}

function UpdateExtractChangelogHistory() {
	$baselinePath = ChangelogStatePath('changelog-baseline.json');
	$historyPath = ChangelogStatePath('changelog-history.json');
	$current = BuildExtractSnapshot();
	$previous = ReadJsonFile($baselinePath, null);
	$history = ReadJsonFile($historyPath, array('schema' => 4, 'entries' => array()));
	$historySchema = $history['schema'] ?? null;
	$history['schema'] = 4;
	if (!isset($history['entries']) || !is_array($history['entries'])) {
		$history['entries'] = array();
	}
	$historyChanged = ChangelogNormalizeHistoryRecipeLabels($history);

	if (!is_array($previous) || !isset($previous['files'])) {
		array_unshift($history['entries'], array(
			'date' => date('Y-m-d'),
			'title' => 'Automated IPF extract diff',
			'items' => array('Initialized the automated IPF extract snapshot. Future parses will compare new extracts against this baseline.'),
			'summary' => array('totalAdded' => 0, 'totalChanged' => 0, 'totalRemoved' => 0),
			'details' => array(),
		));
		WriteJsonFile($baselinePath, $current);
		WriteJsonFile($historyPath, $history);
		return $history['entries'];
	}

	$summary = SummarizeExtractChanges($previous, $current);
	if (($summary['totalAdded'] + $summary['totalRemoved'] + $summary['totalChanged']) > 0) {
		array_unshift($history['entries'], array(
			'date' => date('Y-m-d'),
			'title' => 'Automated IPF extract diff',
			'items' => BuildChangelogEntryItems($summary),
			'summary' => array(
				'totalAdded' => $summary['totalAdded'],
				'totalChanged' => $summary['totalChanged'],
				'totalRemoved' => $summary['totalRemoved'],
			),
			'details' => $summary['details'],
		));
		$history['entries'] = array_slice($history['entries'], 0, 20);
		WriteJsonFile($baselinePath, $current);
		WriteJsonFile($historyPath, $history);
	} elseif (($previous['schema'] ?? 1) !== ($current['schema'] ?? 4)) {
		WriteJsonFile($baselinePath, $current);
		WriteJsonFile($historyPath, $history);
	} elseif ($historySchema !== 4 || $historyChanged) {
		WriteJsonFile($historyPath, $history);
	}

	return $history['entries'];
}

function BuildAutomatedChangelogHtml($entries) {
	if (!$entries) {
		return '<h3>Automated IPF Extract Diff</h3><ul><li>No automated extract changes have been recorded yet.</li></ul>';
	}
	$html = '';
	foreach (array_slice($entries, 0, 5) as $entry) {
		$title = trim(($entry['date'] ?? '').' '.($entry['title'] ?? 'Automated IPF extract diff'));
		$html .= '<h3>'.Html($title).'</h3><ul>';
		foreach (($entry['items'] ?? array()) as $item) {
			$html .= '<li>'.ChangelogOverviewItemHtml($item).'</li>';
		}
		$html .= '</ul>';
	}
	return $html;
}

function ChangelogValueHtml($value) {
	$value = str_replace("\n", '\n', (string)$value);
	if (strlen($value) > 240) {
		$value = substr($value, 0, 237).'...';
	}
	if ($value === '') {
		$value = '(blank)';
	}
	return Html($value);
}

function ChangelogNormalizeItemId($itemId) {
	$itemId = trim((string)$itemId);
	return preg_match('/^-?\d+$/', $itemId) ? (string)(1 * $itemId) : $itemId;
}

function ChangelogCleanDisplayName($value) {
	$value = trim((string)$value);
	return ($value === '' || strcasecmp($value, 'None') === 0) ? '' : $value;
}

function ChangelogStanceDisplayName($attrs) {
	foreach (array('Name', 'EngName', 'ClassName') as $nameField) {
		$name = ChangelogCleanDisplayName($attrs[$nameField] ?? '');
		if ($name !== '') {
			return $name;
		}
	}
	$stanceId = ChangelogCleanDisplayName($attrs['ClassID'] ?? '');
	return $stanceId !== '' ? 'Stance '.$stanceId : 'Unknown stance';
}

function ChangelogSkillStanceLookup() {
	static $lookup = null;
	if ($lookup !== null) {
		return $lookup;
	}

	$lookup = array();
	foreach (GeDataGlob('xml/datatable_stance.xml') as $path) {
		$reader = new XMLReader();
		if (!$reader->open($path)) {
			continue;
		}
		while ($reader->read()) {
			if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'Class') {
				continue;
			}
			$attrs = array();
			if ($reader->moveToFirstAttribute()) {
				do {
					$attrs[$reader->name] = NormalizeXmlValue($reader->value);
				} while ($reader->moveToNextAttribute());
				$reader->moveToElement();
			}

			$stanceId = ChangelogCleanDisplayName($attrs['ClassID'] ?? '');
			$stanceName = ChangelogStanceDisplayName($attrs);
			for ($slot = 1; $slot <= 5; $slot++) {
				$skillId = ChangelogNormalizeItemId($attrs['SkillID'.$slot] ?? '');
				if ($skillId === '' || $skillId === '0') {
					continue;
				}
				if (!isset($lookup[$skillId])) {
					$lookup[$skillId] = array();
				}
				$dedupeKey = strtolower($stanceId.'|'.$stanceName);
				if (!isset($lookup[$skillId][$dedupeKey])) {
					$lookup[$skillId][$dedupeKey] = array(
						'id' => $stanceId,
						'name' => $stanceName,
					);
				}
			}
		}
		$reader->close();
	}
	foreach ($lookup as $skillId => $stances) {
		$lookup[$skillId] = array_values($stances);
	}
	return $lookup;
}

function ChangelogSkillStanceNamesForRecord($record) {
	$recordId = ChangelogNormalizeItemId($record['id'] ?? '');
	if ($recordId === '') {
		return array();
	}
	$lookup = ChangelogSkillStanceLookup();
	$stances = $lookup[$recordId] ?? array();
	$names = array();
	foreach ($stances as $stance) {
		$name = ChangelogCleanDisplayName($stance['name'] ?? '');
		if ($name !== '' && !in_array($name, $names, true)) {
			$names[] = $name;
		}
	}
	return $names;
}

function ChangelogSkillStanceLabelForRecord($record) {
	$names = ChangelogSkillStanceNamesForRecord($record);
	if (!$names) {
		return 'No stance listed';
	}
	if (count($names) === 1) {
		return $names[0];
	}
	return 'Multiple stances: '.implode(', ', $names);
}

function ChangelogSkillStanceMetaHtml($record) {
	$names = ChangelogSkillStanceNamesForRecord($record);
	if (!$names) {
		return '<span class="changelog-record-meta">Stance: No stance listed</span>';
	}
	$prefix = count($names) === 1 ? 'Stance: ' : 'Stances: ';
	return '<span class="changelog-record-meta">'.Html($prefix.implode(', ', $names)).'</span>';
}

function ChangelogGroupSkillRecordsByStance($records) {
	$groups = array();
	foreach ($records as $record) {
		$label = ChangelogSkillStanceLabelForRecord($record);
		$key = strtolower($label);
		if (!isset($groups[$key])) {
			$groups[$key] = array(
				'label' => $label,
				'records' => array(),
			);
		}
		$groups[$key]['records'][] = $record;
	}
	return $groups;
}

function ChangelogItemNameLookup() {
	static $lookup = null;
	if ($lookup !== null) {
		return $lookup;
	}

	$lookup = array();
	foreach (GeDataGlob('xml/datatable_item_*.xml') as $path) {
		$reader = new XMLReader();
		if (!$reader->open($path)) {
			continue;
		}
		while ($reader->read()) {
			if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'Class') {
				continue;
			}
			$attrs = array();
			if ($reader->moveToFirstAttribute()) {
				do {
					$attrs[$reader->name] = NormalizeXmlValue($reader->value);
				} while ($reader->moveToNextAttribute());
				$reader->moveToElement();
			}
			$id = $attrs['ClassID'] ?? null;
			if ($id === null || trim((string)$id) === '') {
				continue;
			}
			foreach (array('EngName', 'ItemName', 'Name', 'ClassName') as $nameField) {
				$name = trim((string)($attrs[$nameField] ?? ''));
				if ($name !== '' && $name !== 'None') {
					$lookup[ChangelogNormalizeItemId($id)] = $name;
					break;
				}
			}
		}
		$reader->close();
	}
	return $lookup;
}

function ChangelogDisplayItemName($itemId) {
	$itemId = trim((string)$itemId);
	if ($itemId === '' || strcasecmp($itemId, 'None') === 0) {
		return 'None';
	}
	if (!preg_match('/^-?\d+$/', $itemId)) {
		return $itemId;
	}
	$normalizedId = ChangelogNormalizeItemId($itemId);
	if ($normalizedId === '0') {
		return 'None';
	}

	global $Items;
	$name = '';
	if (function_exists('ItemDisplayName') && isset($Items) && is_array($Items)) {
		$name = ItemDisplayName($normalizedId);
	}
	if ($name === '' || $name === 'None' || $name === 'Item '.$normalizedId) {
		$lookup = ChangelogItemNameLookup();
		$name = $lookup[$normalizedId] ?? $name;
	}
	if ($name === '' || $name === 'None') {
		return 'Item '.$normalizedId;
	}
	return $name;
}

function ChangelogFieldUsesItemQuantityPairs($fieldName) {
	return strcasecmp((string)$fieldName, 'RewardItem') === 0;
}

function ChangelogFieldUsesItemId($fieldName, $sourceFile = '') {
	$fieldName = (string)$fieldName;
	$baseName = preg_replace('/\.xml$/i', '', (string)$sourceFile);
	if ($baseName === 'datatable_itemmake' && preg_match('/^(Recipe|Target|Stuff\d+|MaterialID)$/i', $fieldName)) {
		return true;
	}
	if (preg_match('/^Drop\d+ID$/i', $fieldName)) {
		return true;
	}
	if (preg_match('/ItemID$/i', $fieldName)) {
		return true;
	}
	return false;
}

function ChangelogFormatItemQuantityPairs($value) {
	$raw = trim((string)$value);
	if ($raw === '') {
		return $value;
	}
	$parts = preg_split('/\s*,\s*/', $raw);
	if (count($parts) < 2 || count($parts) % 2 !== 0) {
		return $value;
	}

	$items = array();
	for ($i = 0; $i < count($parts); $i += 2) {
		$itemId = trim((string)$parts[$i]);
		$quantity = trim((string)$parts[$i + 1]);
		if (!preg_match('/^-?\d+$/', $itemId)) {
			return $value;
		}
		if (ChangelogNormalizeItemId($itemId) === '0') {
			continue;
		}
		$text = ChangelogDisplayItemName($itemId);
		if ($quantity !== '' && $quantity !== '0') {
			$text .= ' x '.$quantity;
		}
		$items[] = $text;
	}
	return $items ? implode('; ', $items) : 'None';
}

function ChangelogFormatItemIdValue($value) {
	$raw = trim((string)$value);
	if ($raw === '') {
		return $value;
	}
	$parts = preg_split('/\s*,\s*/', $raw);
	$items = array();
	foreach ($parts as $part) {
		$part = trim((string)$part);
		if ($part === '') {
			continue;
		}
		if (!preg_match('/^-?\d+$/', $part)) {
			return $value;
		}
		$items[] = ChangelogDisplayItemName($part);
	}
	return $items ? implode(', ', $items) : $value;
}

function ChangelogValueHtmlForField($fieldName, $value, $sourceFile = '') {
	if (ChangelogFieldUsesItemQuantityPairs($fieldName)) {
		$value = ChangelogFormatItemQuantityPairs($value);
	} elseif (ChangelogFieldUsesItemId($fieldName, $sourceFile)) {
		$value = ChangelogFormatItemIdValue($value);
	}
	return ChangelogValueHtml($value);
}

function ChangelogRecipeRecordAttrsLookup() {
	static $lookup = null;
	if ($lookup !== null) {
		return $lookup;
	}

	$lookup = array();
	$baseline = ReadJsonFile(ChangelogStatePath('changelog-baseline.json'), array('files' => array()));
	foreach (($baseline['files']['datatable_itemmake.xml']['records'] ?? array()) as $recordId => $record) {
		$attrs = ChangelogDecodeAttrs($record['attrs'] ?? null);
		if (is_array($attrs)) {
			$lookup[(string)$recordId] = $attrs;
		}
	}

	foreach (GeDataGlob('xml/datatable_itemmake.xml') as $path) {
		$reader = new XMLReader();
		if (!$reader->open($path)) {
			continue;
		}
		while ($reader->read()) {
			if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'Class') {
				continue;
			}
			$attrs = array();
			if ($reader->moveToFirstAttribute()) {
				do {
					$attrs[$reader->name] = NormalizeXmlValue($reader->value);
				} while ($reader->moveToNextAttribute());
				$reader->moveToElement();
			}
			$id = $attrs['ClassID'] ?? null;
			if ($id !== null && trim((string)$id) !== '' && !isset($lookup[(string)$id])) {
				$lookup[(string)$id] = $attrs;
			}
		}
		$reader->close();
	}

	return $lookup;
}

function ChangelogLegacyRecipeNameOverrides() {
	return array(
		'13125' => 'Sakura Branch',
		'13126' => 'Frozen Marlin',
		'13127' => 'Nodachi',
		'13128' => 'Silver Skeleton Bracer',
		'13131' => 'Small Frozen Marlin',
		'13134' => 'Frozen Marlin Cannon',
		'13136' => 'Abyss Pistol costume',
		'13138' => 'Abyss Bangle costume',
		'13140' => 'Abyss Grimore costume',
		'60024' => 'Total Status Ampule',
		'60025' => 'Total Status Ampule (Event)',
		'60026' => 'Total Status Ampule',
		'60027' => 'Total Status Ampule (Event)',
	);
}

function ChangelogRecipeRecordLabel($recordId) {
	$lookup = ChangelogRecipeRecordAttrsLookup();
	$recordId = ChangelogNormalizeItemId($recordId);
	$attrs = $lookup[$recordId] ?? null;
	if (!is_array($attrs)) {
		$legacyNames = ChangelogLegacyRecipeNameOverrides();
		return $legacyNames[$recordId] ?? '';
	}

	if (isset($attrs['Target']) && preg_match('/^-?\d+$/', trim((string)$attrs['Target']))) {
		$targetName = ChangelogDisplayItemName($attrs['Target']);
		if ($targetName !== '' && $targetName !== 'None' && $targetName !== 'Item '.(1 * $attrs['Target'])) {
			return $targetName;
		}
	}
	if (isset($attrs['Recipe']) && preg_match('/^-?\d+$/', trim((string)$attrs['Recipe']))) {
		$recipeName = ChangelogDisplayItemName($attrs['Recipe']);
		if ($recipeName !== '' && $recipeName !== 'None' && $recipeName !== 'Item '.(1 * $attrs['Recipe'])) {
			return $recipeName;
		}
	}
	foreach (array('TargetName', 'RecipeName') as $key) {
		$name = trim((string)($attrs[$key] ?? ''));
		if ($name !== '' && $name !== 'None') {
			return $name;
		}
	}
	return '';
}

function ChangelogOverviewItemTextWithRecipeLabels($item) {
	$text = trim((string)$item);
	if (preg_match('/^(Added|Updated|Removed) Recipes entry: (-?\d+)\.$/', $text, $matches)) {
		$recipeLabel = ChangelogRecipeRecordLabel($matches[2]);
		if ($recipeLabel !== '') {
			return $matches[1].' Recipes entry: '.$recipeLabel.'.';
		}
	}
	return $text;
}

function ChangelogNormalizeHistoryRecipeLabels(&$history) {
	$changed = false;
	foreach ($history['entries'] as &$entry) {
		if (isset($entry['items']) && is_array($entry['items'])) {
			foreach ($entry['items'] as $index => $item) {
				$normalizedItem = ChangelogOverviewItemTextWithRecipeLabels($item);
				if ($normalizedItem !== $item) {
					$entry['items'][$index] = $normalizedItem;
					$changed = true;
				}
			}
		}
		if (!isset($entry['details']) || !is_array($entry['details'])) {
			continue;
		}
		foreach ($entry['details'] as &$table) {
			if (preg_replace('/\.xml$/i', '', (string)($table['file'] ?? '')) !== 'datatable_itemmake') {
				continue;
			}
			foreach (array('added', 'changed', 'removed') as $action) {
				if (!isset($table[$action]) || !is_array($table[$action])) {
					continue;
				}
				foreach ($table[$action] as &$record) {
					$label = trim((string)($record['label'] ?? ''));
					$recordId = (string)($record['id'] ?? '');
					if ($recordId === '' || ($label !== '' && $label !== $recordId && !preg_match('/^-?\d+$/', $label))) {
						continue;
					}
					$recipeLabel = ChangelogRecipeRecordLabel($recordId);
					if ($recipeLabel !== '') {
						$record['label'] = $recipeLabel;
						$changed = true;
					}
				}
				unset($record);
			}
		}
		unset($table);
	}
	unset($entry);
	return $changed;
}

function ChangelogRecordDisplayLabel($record) {
	$label = trim((string)($record['label'] ?? ''));
	$recordId = (string)($record['id'] ?? '');
	$fileName = (string)($record['file'] ?? '');
	if (preg_replace('/\.xml$/i', '', $fileName) === 'datatable_itemmake' && ($label === '' || $label === $recordId || preg_match('/^-?\d+$/', $label))) {
		$recipeLabel = ChangelogRecipeRecordLabel($recordId);
		if ($recipeLabel !== '') {
			return $recipeLabel;
		}
	}
	if ($label !== '') {
		return $label;
	}
	return $recordId !== '' ? $recordId : 'Unknown record';
}

function ChangelogRecordLinkHtml($record, $allowLink = true) {
	$label = ChangelogRecordDisplayLabel($record);
	$link = $record['link'] ?? null;
	if (!$allowLink || !is_array($link) || empty($link['href'])) {
		return Html($label);
	}
	if (($link['type'] ?? '') === 'item') {
		return ItemPageLink($link['category'], $link['itemType'], $link['anchor'], $label);
	}
	if (($link['type'] ?? '') === 'achievement') {
		$onclick = 'setSidePanel('.JsLiteral('AchievmenttempSide').','.JsLiteral('docked').');'
			.'ToggleContent('.JsLiteral('MainContainer').','.JsLiteral('MainAchievments').');';
		if (($link['achievementType'] ?? '') !== '') {
			$onclick .= 'ToggleContent('.JsLiteral('MainAchievments').','.JsLiteral('Achieves'.$link['achievementType']).');';
		}
		$onclick .= 'ScrollToContentTop();closeNav();';
		return '<a href="'.Html($link['href']).'" onclick="'.Html($onclick).'">'.Html($label).'</a>';
	}
	return '<a href="'.Html($link['href']).'">'.Html($label).'</a>';
}

function BuildChangelogFieldTable($fields, $sourceFile = '') {
	if (!$fields) {
		return '<p class="changelog-detail-note">Field-level details are not available for this historical record.</p>';
	}
	$html = '<table class="changelog-field-table"><thead><tr><th>Field</th><th>Before</th><th>After</th></tr></thead><tbody>';
	foreach ($fields as $field) {
		$fieldName = $field['field'] ?? '';
		$html .= '<tr><td>'.Html($fieldName).'</td><td>'.ChangelogValueHtmlForField($fieldName, $field['before'] ?? '', $sourceFile).'</td><td>'.ChangelogValueHtmlForField($fieldName, $field['after'] ?? '', $sourceFile).'</td></tr>';
	}
	$html .= '</tbody></table>';
	return $html;
}

function ChangelogActionLabel($action) {
	$labels = array(
		'added' => 'Added',
		'changed' => 'Updated',
		'removed' => 'Removed',
	);
	return $labels[$action] ?? ucfirst((string)$action);
}

function BuildChangelogRecordListItems($records, $action, $sourceFile = '') {
	$label = ChangelogActionLabel($action);
	$html = '';
	foreach ($records as $record) {
		$html .= '<li>';
		$html .= '<div class="changelog-record-line"><span class="change-pill change-'.$action.'">'.$label.'</span> '
			.ChangelogRecordLinkHtml($record, $action !== 'removed')
			.' <span class="changelog-record-id">ID '.Html($record['id'] ?? '').'</span>';
		if (preg_replace('/\.xml$/i', '', (string)$sourceFile) === 'datatable_skill') {
			$html .= ' '.ChangelogSkillStanceMetaHtml($record);
		}
		$html .= '</div>';
		if ($action === 'changed') {
			$fields = $record['fields'] ?? array();
			$count = count($fields);
			$summary = $count > 0 ? $count.' changed field'.($count === 1 ? '' : 's') : 'Changed fields';
			$html .= '<details class="changelog-field-details"><summary>'.Html($summary).'</summary>'.BuildChangelogFieldTable($fields, $sourceFile).'</details>';
		}
		$html .= '</li>';
	}
	return $html;
}

function BuildChangelogSkillRecordList($records, $action, $sourceFile = '') {
	$html = '<h4>'.ChangelogActionLabel($action).'</h4>';
	foreach (ChangelogGroupSkillRecordsByStance($records) as $group) {
		$count = count($group['records']);
		$html .= '<div class="changelog-skill-stance-group">';
		$html .= '<h5>'.Html($group['label']).'<span>'.Html(FormatChangelogCount($count, 'skill')).'</span></h5>';
		$html .= '<ul class="changelog-record-list">'.BuildChangelogRecordListItems($group['records'], $action, $sourceFile).'</ul>';
		$html .= '</div>';
	}
	return $html;
}

function BuildChangelogRecordList($records, $action, $sourceFile = '') {
	if (!$records) {
		return '';
	}
	if (preg_replace('/\.xml$/i', '', (string)$sourceFile) === 'datatable_skill') {
		return BuildChangelogSkillRecordList($records, $action, $sourceFile);
	}

	$html = '<h4>'.ChangelogActionLabel($action).'</h4><ul class="changelog-record-list">';
	$html .= BuildChangelogRecordListItems($records, $action, $sourceFile);
	$html .= '</ul>';
	return $html;
}

function BuildChangelogDetailsHtml($entry) {
	$details = $entry['details'] ?? array();
	if (!$details) {
		return '<p class="changelog-detail-note">This historical entry was recorded before detailed field tracking was added, so only the overview is available.</p>';
	}
	$html = '<div class="changelog-details">';
	foreach ($details as $table) {
		$added = $table['added'] ?? array();
		$changed = $table['changed'] ?? array();
		$removed = $table['removed'] ?? array();
		$parts = array();
		if ($added) {
			$parts[] = count($added).' added';
		}
		if ($changed) {
			$parts[] = count($changed).' updated';
		}
		if ($removed) {
			$parts[] = count($removed).' removed';
		}
		$html .= '<section class="changelog-table-detail">';
		$html .= '<h3>'.Html($table['label'] ?? 'Records').'<span>'.Html(implode(', ', $parts)).'</span></h3>';
		$sourceFile = $table['file'] ?? '';
		$html .= BuildChangelogRecordList($added, 'added', $sourceFile);
		$html .= BuildChangelogRecordList($changed, 'changed', $sourceFile);
		$html .= BuildChangelogRecordList($removed, 'removed', $sourceFile);
		$html .= '</section>';
	}
	$html .= '</div>';
	return $html;
}

function ChangelogCurrentLinkLookup() {
	static $lookup = null;
	if ($lookup !== null) {
		return $lookup;
	}
	$lookup = array();
	$baseline = ReadJsonFile(ChangelogStatePath('changelog-baseline.json'), array('files' => array()));
	foreach (($baseline['files'] ?? array()) as $fileName => $file) {
		$tableLabel = $file['label'] ?? ChangelogTableLabel($fileName);
		foreach (($file['records'] ?? array()) as $recordId => $record) {
			if (empty($record['label'])) {
				continue;
			}
			$attrs = ChangelogDecodeAttrs($record['attrs'] ?? null);
			$link = is_array($attrs) ? ChangelogRecordLinkTarget($fileName, $recordId, $attrs) : ($record['link'] ?? null);
			if (empty($link)) {
				continue;
			}
			$key = strtolower($tableLabel.'|'.$record['label']);
			if (!isset($lookup[$key])) {
				$lookup[$key] = array(
					'id' => (string)$recordId,
					'label' => $record['label'],
					'link' => $link,
				);
			}
		}
	}
	return $lookup;
}

function ChangelogOverviewItemHtml($item) {
	$text = ChangelogOverviewItemTextWithRecipeLabels($item);
	if (preg_match('/^(Added|Updated|Removed) ([^:]+) entry: (.+)\.$/', $text, $matches)) {
		$action = $matches[1];
		$tableLabel = $matches[2];
		$recordLabel = $matches[3];
		if ($action !== 'Removed') {
			$lookup = ChangelogCurrentLinkLookup();
			$key = strtolower($tableLabel.'|'.$recordLabel);
			if (isset($lookup[$key])) {
				return Html($action.' '.$tableLabel.' entry: ').ChangelogRecordLinkHtml($lookup[$key]).'.';
			}
		}
	}
	return Html($text);
}

function BuildChangelogSection() {
	global $AutomatedChangelogEntries;
	$html = '<div id="Changelog"><section class="changelog-page">';
	$html .= '<div class="home-hero"><h1>Changelog</h1><p>Detailed automated IPF extract changes, including added, updated, and removed records. Added and updated equipment records link to their generated database entry when that entry exists.</p></div>';
	if (!$AutomatedChangelogEntries) {
		$html .= '<article class="changelog-entry"><p>No automated extract changes have been recorded yet.</p></article>';
	} else {
		foreach ($AutomatedChangelogEntries as $entry) {
			$title = trim(($entry['date'] ?? '').' '.($entry['title'] ?? 'Automated IPF extract diff'));
			$html .= '<article class="changelog-entry">';
			$html .= '<h2>'.Html($title).'</h2>';
			if (!empty($entry['items'])) {
				$html .= '<ul class="changelog-overview">';
				foreach ($entry['items'] as $item) {
					$html .= '<li>'.ChangelogOverviewItemHtml($item).'</li>';
				}
				$html .= '</ul>';
			}
			$html .= BuildChangelogDetailsHtml($entry);
			$html .= '</article>';
		}
	}
	$html .= '</section></div>';
	return $html;
}

function WebImagePath($folder, $fileName) {
	global $WebImageRoot;
	return $WebImageRoot.DIRECTORY_SEPARATOR.$folder.DIRECTORY_SEPARATOR.$fileName;
}

function ClearWebImageFolder($folder) {
	$directory = WebImagePath($folder, '');
	if (!is_dir($directory)) {
		return;
	}
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ($iterator as $path) {
		if ($path->isDir()) {
			rmdir($path->getPathname());
		} else {
			unlink($path->getPathname());
		}
	}
}

function ClearGeneratedWebImages() {
	foreach (array('Barrack', 'Items', 'Skills') as $folder) {
		ClearWebImageFolder($folder);
	}
}

function WebImageSrc($folder, $fileName) {
	return './images/'.$folder.'/'.$fileName;
}

function WriteWebAsset($relativePath, $content) {
	$assetPath = WebOutputPath($relativePath);
	WriteGeneratedFile($assetPath, $content);
}

function ExternalizePageAssets($html) {
	$assetHeader = "/* Generated by tools/English.php. Do not edit this file directly. */\n\n";
	$styleStart = strpos($html, '<style>');
	if ($styleStart !== false) {
		$styleContentStart = $styleStart + strlen('<style>');
		$styleEnd = strpos($html, '</style>', $styleContentStart);
		if ($styleEnd !== false) {
			$css = trim(substr($html, $styleContentStart, $styleEnd - $styleContentStart))."\n";
			$styleReplacement = "\n<link rel=\"stylesheet\" href=\"./assets/css/site.css\">\n";
			$html = substr($html, 0, $styleStart).$styleReplacement.substr($html, $styleEnd + strlen('</style>'));
		}
	}

	if (isset($css)) {
		$css = str_replace(array('url("./images/', "url('./images/"), array('url("../../images/', "url('../../images/"), $css);
		WriteWebAsset('assets/css/site.css', $assetHeader.$css);
	}

	$scriptFiles = array('game-data.js', 'app.js');
	$scriptIndex = 0;
	$searchOffset = 0;
	while (($scriptStart = strpos($html, '<script>', $searchOffset)) !== false) {
		$scriptContentStart = $scriptStart + strlen('<script>');
		$scriptEnd = strpos($html, '</script>', $scriptContentStart);
		if ($scriptEnd === false) {
			break;
		}
		$fileName = $scriptFiles[$scriptIndex] ?? 'page-'.$scriptIndex.'.js';
		$script = trim(substr($html, $scriptContentStart, $scriptEnd - $scriptContentStart))."\n";
		WriteWebAsset('assets/js/'.$fileName, $assetHeader.$script);
		$scriptIndex++;
		$scriptReplacement = "\n<script src=\"./assets/js/".$fileName."\" defer></script>\n";
		$html = substr($html, 0, $scriptStart).$scriptReplacement.substr($html, $scriptEnd + strlen('</script>'));
		$searchOffset = $scriptStart + strlen($scriptReplacement);
	}

	return $html;
}

function ExtractTopLevelDivById($html, $id) {
	$start = strpos($html, '<div id="'.$id.'"');
	if ($start === false) {
		return '';
	}

	preg_match_all('/<\/?div\b[^>]*>/i', $html, $matches, PREG_OFFSET_CAPTURE, $start);
	$depth = 0;
	foreach ($matches[0] as $match) {
		$tag = $match[0];
		$offset = $match[1];
		if (stripos($tag, '</div') === 0) {
			$depth--;
			if ($depth === 0) {
				return substr($html, $start, $offset + strlen($tag) - $start);
			}
		} else {
			$depth++;
		}
	}

	$mainClose = strrpos($html, "</div>\r\n</body>");
	if ($mainClose === false) {
		$mainClose = strrpos($html, "</div>\n</body>");
	}
	if ($mainClose !== false && $mainClose > $start) {
		return substr($html, $start, $mainClose - $start);
	}

	return substr($html, $start);
}

function ActivatePageSection($sectionHtml) {
	return preg_replace('/^(<div\b[^>]*?)\sstyle="display:\s*none;?\s*"([^>]*>)/i', '$1$2', $sectionHtml, 1);
}

function FirstChildDivId($sectionHtml) {
	$openEnd = strpos($sectionHtml, '>');
	if ($openEnd === false) {
		return '';
	}
	$innerHtml = substr($sectionHtml, $openEnd + 1);
	if (preg_match('/<div\s+id="([^"]+)"/i', $innerHtml, $match)) {
		return $match[1];
	}
	return '';
}

function RoutePrefix($basePath, $route) {
	return $basePath.ltrim($route, './');
}

function RewriteRelativeWebPaths($html, $basePath) {
	if ($basePath === '' || $basePath === './') {
		return $html;
	}
	return str_replace(
		array('href="./', "href='./", 'src="./', "src='./", '&#039;./images/', '&quot;./images/', '`./images/', "'./images/", '"./images/'),
		array('href="'.$basePath, "href='".$basePath, 'src="'.$basePath, "src='".$basePath, '&#039;'.$basePath.'images/', '&quot;'.$basePath.'images/', '`'.$basePath.'images/', "'".$basePath.'images/', '"'.$basePath.'images/'),
		$html
	);
}

function BuildSiteNavigation($activeKey, $lastModifiedHtml, $basePath = './') {
	$links = array(
		'home' => array('Home', 'index.html'),
		'changelog' => array('Changelog', 'changelog.html'),
		'characters' => array('Characters', 'characters/'),
		'achievements' => array('Achievements', 'achievements/'),
		'maps' => array('Maps', 'maps/'),
		'monsters' => array('Bosses', 'monsters/'),
		'monster-hunt' => array('Monster Hunt', 'monster-hunt/'),
		'weapons' => array('Weapons', 'weapons/'),
		'armor' => array('Armor', 'armor/'),
		'accessories' => array('Accessories', 'accessories/'),
		'medals' => array('Medals', 'medals/'),
		'enchant-chips' => array('Enchant Chips', 'enchant-chips/'),
	);

	$nav = '<div id="main">';
	foreach ($links as $key => $link) {
		$class = $key === $activeKey ? ' class="is-active"' : '';
		$nav .= '<a'.$class.' href="'.Html(RoutePrefix($basePath, $link[1])).'">'.$link[0].'</a>';
	}
	$nav .= $lastModifiedHtml;
	$nav .= '</div>';
	return $nav;
}

function BuildSideNavigation($fullHtml, $sidePanelId) {
	$sideNavHtml = ExtractTopLevelDivById($fullHtml, 'mySidenav');
	if ($sideNavHtml === '') {
		return '';
	}

	$closeButtonHtml = '';
	if (preg_match('/<a id="closebtn".*?<\/a>/s', $sideNavHtml, $match)) {
		$closeButtonHtml = $match[0];
	}

	$panelHtml = $sidePanelId ? ExtractTopLevelDivById($sideNavHtml, $sidePanelId) : '';
	if ($panelHtml !== '') {
		$panelHtml = ActivatePageSection($panelHtml);
	}
	$sideNavClass = $sidePanelId ? 'sidenav is-docked' : 'sidenav';
	return '<div id="mySidenav" class="'.$sideNavClass.'">'.$closeButtonHtml.$panelHtml.'</div>';
}

function BuildBodyAttributes($page) {
	$attributes = array('data-page' => $page['Key']);
	if (!empty($page['TypePage'])) {
		$attributes['data-type-page'] = '1';
	}
	if (!empty($page['SidePanel'])) {
		$attributes['class'] = 'has-side-panel';
	}
	foreach (array('SidePanel', 'ContentParent', 'DefaultChild') as $key) {
		if (!empty($page[$key])) {
			$attributeName = strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $key));
			$attributes['data-'.$attributeName] = $page[$key];
		}
	}

	$output = '';
	foreach ($attributes as $name => $value) {
		$output .= ' '.$name.'="'.Html($value).'"';
	}
	return $output;
}

function BuildPageHtml($fullHtml, $page, $sectionHtml) {
	$bodyStart = strpos($fullHtml, '<body>');
	$headHtml = $bodyStart === false ? '' : substr($fullHtml, 0, $bodyStart);
	$basePath = $page['BasePath'] ?? './';
	$lastModifiedHtml = '';
	if (preg_match('/<div id="lastModified">.*?<\/div>/s', $fullHtml, $match)) {
		$lastModifiedHtml = $match[0];
	}

	$pageHtml = $headHtml
		.'<body'.BuildBodyAttributes($page).'>'
		.BuildSideNavigation($fullHtml, $page['SidePanel'] ?? '')
		.BuildSiteNavigation($page['Key'], $lastModifiedHtml, $basePath)
		.'<div id="MainContainer">'
		.ActivatePageSection($sectionHtml)
		.'</div>'
		.'</body>'
		.'</html>';
	return RewriteRelativeWebPaths($pageHtml, $basePath);
}

function BuildHomeSection() {
	global $AutomatedChangelogHtml;
	return '<div id="Home"><section class="home-page">'
		.'<div class="home-hero">'
		.'<h1>Granado Espada - Classique Database</h1>'
		.'<p>Browse character, equipment, monster, achievement, medal, and enchant chip data generated from the local game tables.</p>'
		.'</div>'
		.'<div class="changelog-panel">'
		.'<h2>Changelog</h2>'
		.($AutomatedChangelogHtml ?: '')
		.'<p><a href="./changelog.html">View detailed changelog</a></p>'
		.'</div>'
		.'</section></div>';
}

function WriteWebsitePages($fullHtml) {
	$sections = array(
		'Home' => BuildHomeSection(),
		'Changelog' => BuildChangelogSection(),
		'Character' => ExtractTopLevelDivById($fullHtml, 'Character'),
		'MainAchievments' => ExtractTopLevelDivById($fullHtml, 'MainAchievments'),
		'Maps' => ExtractTopLevelDivById($fullHtml, 'Maps'),
		'Monsters' => ExtractTopLevelDivById($fullHtml, 'Monsters'),
		'Weapon' => ExtractTopLevelDivById($fullHtml, 'Weapon'),
		'Armor' => ExtractTopLevelDivById($fullHtml, 'Armor'),
		'Accs' => ExtractTopLevelDivById($fullHtml, 'Accs'),
		'Medalkis' => ExtractTopLevelDivById($fullHtml, 'Medalkis'),
		'EnchantChips' => ExtractTopLevelDivById($fullHtml, 'EnchantChips'),
	);

	$pages = array(
		array('Key' => 'home', 'File' => 'index.html', 'Section' => 'Home'),
		array('Key' => 'changelog', 'File' => 'changelog.html', 'Section' => 'Changelog'),
		array('Key' => 'characters', 'File' => 'characters.html', 'RouteFile' => 'characters/index.html', 'Section' => 'Character'),
		array('Key' => 'achievements', 'File' => 'achievements.html', 'RouteFile' => 'achievements/index.html', 'Section' => 'MainAchievments', 'SidePanel' => 'AchievmenttempSide', 'ContentParent' => 'MainAchievments', 'DefaultChild' => 'AchievesGeneral'),
		array('Key' => 'maps', 'File' => 'maps.html', 'RouteFile' => 'maps/index.html', 'Section' => 'Maps', 'SidePanel' => 'MapstempSide', 'ContentParent' => 'Maps'),
		array('Key' => 'monsters', 'File' => 'monsters.html', 'RouteFile' => 'monsters/index.html', 'Section' => 'Monsters', 'SidePanel' => 'MonsterstempSide'),
		array('Key' => 'weapons', 'File' => 'weapons.html', 'RouteFile' => 'weapons/index.html', 'Section' => 'Weapon', 'SidePanel' => 'WeapontempSide', 'ContentParent' => 'Weapon'),
		array('Key' => 'armor', 'File' => 'armor.html', 'RouteFile' => 'armor/index.html', 'Section' => 'Armor', 'SidePanel' => 'ArmortempSide', 'ContentParent' => 'Armor'),
		array('Key' => 'accessories', 'File' => 'accessories.html', 'RouteFile' => 'accessories/index.html', 'Section' => 'Accs', 'SidePanel' => 'AccstempSide', 'ContentParent' => 'Accs'),
		array('Key' => 'medals', 'File' => 'medals.html', 'RouteFile' => 'medals/index.html', 'Section' => 'Medalkis', 'SidePanel' => 'MedalkistempSide', 'ContentParent' => 'Medalkis'),
		array('Key' => 'enchant-chips', 'File' => 'enchant-chips.html', 'RouteFile' => 'enchant-chips/index.html', 'Section' => 'EnchantChips', 'SidePanel' => 'EnchanttempSide', 'ContentParent' => 'EnchantChips', 'DefaultChild' => 'EnchantIndex'),
	);

	foreach ($pages as $page) {
		$sectionHtml = $sections[$page['Section']] ?? '';
		if ($sectionHtml === '') {
			continue;
		}
		if (empty($page['DefaultChild']) && !empty($page['ContentParent'])) {
			$page['DefaultChild'] = FirstChildDivId($sectionHtml);
		}
		$page['BasePath'] = './';
		WriteGeneratedFile(WebOutputPath($page['File']), BuildPageHtml($fullHtml, $page, $sectionHtml));
		if (!empty($page['RouteFile'])) {
			$routedPage = $page;
			$routedPage['BasePath'] = '../';
			WriteGeneratedFile(WebOutputPath($page['RouteFile']), BuildPageHtml($fullHtml, $routedPage, $sectionHtml));
		}
	}
}

function CopyToWebImage($sourcePath, $folder, $fileName) {
	if ($fileName === '' || $fileName === 'None' || !file_exists($sourcePath)) {
		return false;
	}
	$destination = WebImagePath($folder, $fileName);
	EnsureDirectory(dirname($destination));
	copy($sourcePath, $destination);
	return file_exists($destination);
}

ClearGeneratedWebImages();

foreach (array(WebOutputPath('images/Misc/*'), GeDataPath('images/Misc/*'), GeDataPath('Images/Misc/*')) as $MiscGlob) {
	foreach (glob($MiscGlob) ?: array() as $MiscFile) {
		if (is_file($MiscFile)) {
			CopyToWebImage($MiscFile, 'Misc', basename($MiscFile));
		}
	}
}

global $filecontent;
$filecontent=file_get_contents("EnglishTemplate");
date_default_timezone_set('America/New_York');
global $classId;
// include SaXtA function (Saving  attributes in  XML files to Arrays. Usage - SaXtA(
// $filename = text string. relative file paths to xml files, separated with spacebar sings. Examples- "./XML/datatable_somestuff.xml" or "./XML/datatable_somestuff.xml ./XML/datatable_otherstuff.xml"
// $ArrayName = text string. Name of array in which data will be stored. 

// Data access after SaXtA- $ArrayName[$Attribute][$ClassID].
include "./function.xmls2Arrays.php";

function DictionaryAttributeValue($line, $attribute) {
	if (preg_match('/\s'.preg_quote($attribute, '/').'="([^"]*)"/', $line, $matches)) {
		return $matches[1];
	}
	return null;
}

function NormalizeDictionaryText($value, $encoding = 'UTF-8') {
	$value = (string)$value;
	if ($encoding !== 'UTF-8') {
		if (function_exists('mb_convert_encoding')) {
			$value = mb_convert_encoding($value, 'UTF-8', $encoding);
		} elseif (function_exists('iconv')) {
			$converted = @iconv($encoding, 'UTF-8//IGNORE', $value);
			if ($converted !== false) {
				$value = $converted;
			}
		}
	}
	$value = html_entity_decode($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	$value = str_replace('\\n', '<br>', $value);
	$value = str_replace('\\', '', $value);
	$value = str_replace("'", "", $value);
	$value = str_replace('`', '&#96;', $value);
	$value = str_replace("\r", "", $value);
	return $value;
}

function LoadLocalDictionaryTextFile($path, &$dictionary, $encoding = 'UTF-8') {
	if (!file_exists($path)) {
		return;
	}
	$handle = fopen($path, 'r');
	if ($handle === false) {
		return;
	}
	while (($line = fgets($handle)) !== false) {
		if (strpos($line, '<Text ') === false) {
			continue;
		}
		$classId = DictionaryAttributeValue($line, 'ClassID');
		$text = DictionaryAttributeValue($line, 'Text');
		if ($classId !== null && $text !== null) {
			$dictionary[$classId] = NormalizeDictionaryText($text, $encoding);
		}
	}
	fclose($handle);
}

function LoadMultilingualDictionaryTextFile($path, &$dictionary, $language = 'DEU') {
	if (!file_exists($path)) {
		return;
	}
	$handle = fopen($path, 'r');
	if ($handle === false) {
		return;
	}
	$currentClassId = null;
	while (($line = fgets($handle)) !== false) {
		if (strpos($line, '<TextSource ') !== false) {
			$currentClassId = DictionaryAttributeValue($line, 'ClassID');
			continue;
		}
		if ($currentClassId === null || strpos($line, '<Language ') === false) {
			continue;
		}
		if (DictionaryAttributeValue($line, 'Language') === $language) {
			$text = DictionaryAttributeValue($line, 'Text');
			if ($text !== null && !isset($dictionary[$currentClassId])) {
				$dictionary[$currentClassId] = NormalizeDictionaryText($text);
			}
			$currentClassId = null;
		}
	}
	fclose($handle);
}

function DictionaryText($classId) {
	static $dictionary = null;
	static $fallbackLoaded = false;

	if ($dictionary === null) {
		$dictionary = array();
		LoadLocalDictionaryTextFile(GeDataPath('dictionary/dictionary_local.xml'), $dictionary, 'ISO-8859-1');
	}
	if (!isset($dictionary[$classId]) && !$fallbackLoaded) {
		LoadMultilingualDictionaryTextFile(GeDataPath('dictionary/dictionary.xml'), $dictionary, 'DEU');
		$fallbackLoaded = true;
	}
	return $dictionary[$classId] ?? null;
}

function ResolveDictionaryRefs($value) {
	$value = (string)$value;
	if (strpos($value, '<$>') === false && strpos($value, '&lt;$&gt;') === false) {
		return $value;
	}
	for ($pass = 0; $pass < 5; $pass++) {
		$previous = $value;
		$value = preg_replace_callback('/(?:<\$>|&lt;\$&gt;)(\d+)(?:<\/>|&lt;\/&gt;)/', function($matches) {
			$text = DictionaryText($matches[1]);
			return $text === null ? $matches[0] : $text;
		}, $value);
		if ($value === $previous) {
			break;
		}
	}
	return $value;
}

function RemoveUnresolvedDictionaryRefs($value) {
	return preg_replace('/(?:<\$>|&lt;\$&gt;|&amp;lt;\$&amp;gt;)\d+(?:<\/>|&lt;\/&gt;|&amp;lt;\/&amp;gt;)/', '', (string)$value);
}

function ResolveDictionaryRefsInArray(&$value) {
	if (is_array($value)) {
		foreach ($value as &$childValue) {
			ResolveDictionaryRefsInArray($childValue);
		}
		unset($childValue);
		return;
	}
	if (is_string($value)) {
		$value = ResolveDictionaryRefs($value);
	}
}

function IsUnresolvedDictionaryRef($value) {
	return is_string($value) && preg_match('/^(?:<\$>|&lt;\$&gt;)\d+(?:<\/>|&lt;\/&gt;)$/', $value) === 1;
}

function ApplyCommonNameFallbacks(&$table) {
	if (empty($table['ClassID']) || !is_array($table['ClassID'])) {
		return;
	}
	$nameFields = array('Name', 'ItemName');
	$fallbackFields = array('EngName', 'ClassName', 'ClassType');
	foreach ($table['ClassID'] as $rowId) {
		foreach ($nameFields as $nameField) {
			if (!isset($table[$nameField][$rowId]) || !IsUnresolvedDictionaryRef($table[$nameField][$rowId])) {
				continue;
			}
			foreach ($fallbackFields as $fallbackField) {
				$fallback = $table[$fallbackField][$rowId] ?? '';
				if ($fallback !== '' && $fallback !== 'None' && !IsUnresolvedDictionaryRef($fallback)) {
					$table[$nameField][$rowId] = $fallback;
					break;
				}
			}
		}
	}
}

function IsInvalidCharacterDisplayName($value) {
	$value = trim((string)$value);
	if ($value === '' || $value === 'None' || IsUnresolvedDictionaryRef($value)) {
		return true;
	}
	return preg_match('/^(?:Required level\s*:|Recipe\s*-|Faction Skill Point\s*:|%s\b|\[[^\]]+\]\s*Stance level\s*:)/i', $value) === 1;
}

function ApplyCharacterNameFallbacks() {
	global $Characters;

	if (empty($Characters['ClassID']) || !is_array($Characters['ClassID'])) {
		return;
	}

	foreach ($Characters['ClassID'] as $id) {
		if (!IsInvalidCharacterDisplayName($Characters['Name'][$id] ?? '')) {
			continue;
		}
		foreach (array('EngName', 'ClassName') as $fallbackField) {
			$fallback = trim((string)($Characters[$fallbackField][$id] ?? ''));
			if ($fallback !== '' && $fallback !== 'None' && !IsInvalidCharacterDisplayName($fallback)) {
				$Characters['Name'][$id] = $fallback;
				break;
			}
		}
	}
}

function SaXtAGe($relativeFiles, $arrayName, $excludeAttrib = "") {
	$paths = array();
	foreach (explode(' ', $relativeFiles) as $relativeFile) {
		$relativeFile = trim($relativeFile);
		if ($relativeFile !== '') {
			$paths[] = GeDataPath($relativeFile);
		}
	}
	SaXtA(implode(' ', $paths), $arrayName, $excludeAttrib);
	global ${$arrayName};
	ResolveDictionaryRefsInArray(${$arrayName});
	ApplyCommonNameFallbacks(${$arrayName});
}

$ParseAll = in_array('--all', $argv ?? [], true);

function ShouldParseCategory($prompt) {
	global $ParseAll;
	if ($ParseAll) {
		echo $prompt." Yes\n";
		return true;
	}
	echo $prompt.' ';
	$line = fgets(STDIN);
	return !((trim($line) == 'N') or (trim($line) == 'n'));
}

function RichColorMarkupToHtml($value) {
	return preg_replace_callback('/\{#([A-Fa-f0-9]{3,8})\}(.*?)\{\/\}/s', function($matches) {
		return '<span style="color:#'.$matches[1].'">'.$matches[2].'</span>';
	}, (string)$value);
}

function JsArg($value) {
	$value = RichColorMarkupToHtml($value);
	return json_encode((string)$value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

function ItemDisplayName($id) {
	global $Items;
	$id = 1 * $id;
	$name = $Items['EngName'][$id] ?? '';
	if ($name === '' || $name === 'None') {
		$name = $Items['ItemName'][$id] ?? '';
	}
	if ($name === '' || $name === 'None') {
		$name = $Items['ClassName'][$id] ?? ('Item '.$id);
	}
	return $name;
}

function ImageTag($folder, $fileName, $alt = '', $attrs = '') {
	$fileName = (string)$fileName;
	if ($fileName === '' || $fileName === 'None') {
		return '';
	}
	$path = WebImagePath($folder, $fileName.'.bmp');
	if (!file_exists($path)) {
		return '';
	}
	$attrText = $attrs === '' ? '' : ' '.$attrs;
	return '<img src="'.WebImageSrc($folder, $fileName.'.bmp').'"'.$attrText.' alt="'.htmlspecialchars((string)$alt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'">';
}

function Html($value) {
	return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function CharacterAvailabilityJsonPath() {
	return WebOutputPath('assets/data/character-availability.json');
}

function WriteCharacterAvailabilityJson() {
	global $Characters, $CharacterAvailabilityByClassId;

	$characters = array();
	$CharacterAvailabilityByClassId = array();
	$defaultActiveClassIds = array(1, 2, 3, 4, 5);
	$existing = ReadJsonFile(CharacterAvailabilityJsonPath(), array('defaultActive' => false, 'characters' => array()));
	$existingByClassId = array();
	$existingByClassName = array();
	$existingByName = array();
	if (isset($existing['characters']) && is_array($existing['characters'])) {
		foreach ($existing['characters'] as $existingCharacter) {
			if (!is_array($existingCharacter) || !array_key_exists('active', $existingCharacter)) {
				continue;
			}
			$active = (bool)$existingCharacter['active'];
			if (isset($existingCharacter['classId'])) {
				$existingByClassId[(int)$existingCharacter['classId']] = $active;
			}
			if (isset($existingCharacter['className']) && $existingCharacter['className'] !== '') {
				$existingByClassName[(string)$existingCharacter['className']] = $active;
			}
			if (isset($existingCharacter['name']) && $existingCharacter['name'] !== '') {
				$existingByName[(string)$existingCharacter['name']] = $active;
			}
		}
	}

	if (isset($Characters['ClassID']) && is_array($Characters['ClassID'])) {
		foreach ($Characters['ClassID'] as $id) {
			$classId = (int)$id;
			$className = (string)($Characters['ClassName'][$id] ?? '');
			$name = (string)($Characters['Name'][$id] ?? '');
			if (array_key_exists($classId, $existingByClassId)) {
				$active = $existingByClassId[$classId];
			} elseif ($className !== '' && array_key_exists($className, $existingByClassName)) {
				$active = $existingByClassName[$className];
			} elseif ($name !== '' && array_key_exists($name, $existingByName)) {
				$active = $existingByName[$name];
			} else {
				$active = in_array($classId, $defaultActiveClassIds, true);
			}
			$CharacterAvailabilityByClassId[$classId] = $active;
			$characters[] = array(
				'classId' => $classId,
				'className' => $className,
				'name' => $name,
				'active' => $active,
			);
		}
	}

	usort($characters, function($a, $b) {
		return $a['classId'] <=> $b['classId'];
	});

	WriteJsonFile(CharacterAvailabilityJsonPath(), array(
		'defaultActive' => false,
		'characters' => $characters,
	));
}

function ValidStanceId($stanceId) {
	global $Stances;

	$stanceId = trim((string)$stanceId);
	return $stanceId !== '' && $stanceId !== '0' && isset($Stances['ClassID'][$stanceId]);
}

function AddCharacterStance($stanceId, &$stanIDs, &$megastances, &$searchParts) {
	global $Stances;

	if (!ValidStanceId($stanceId)) {
		return '';
	}

	$classId = (string)$Stances['ClassID'][$stanceId];
	AddCharacterSearchPart($searchParts, $Stances['Name'][$stanceId]);
	if (!in_array($classId, $stanIDs, true)) {
		$stanIDs[] = $classId;
	}
	if (!in_array($classId, $megastances, true)) {
		$megastances[] = $classId;
	}

	return '['.$Stances['Name'][$stanceId].']';
}

function ItemRichText($value) {
	$value = html_entity_decode((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	$value = RichColorMarkupToHtml($value);
	$value = preg_replace('/<br\s*\/?>/i', '<br>', $value);
	return strip_tags($value, '<br><span>');
}

function SplitItemDescriptionOptions($description) {
	$parts = preg_split('/(?:<br\s*\/?>\s*)+Options:\s*<br\s*\/?>/i', (string)$description, 2);
	if (count($parts) === 2) {
		return array(
			'Description' => trim($parts[0]),
			'Options' => trim($parts[1]),
		);
	}
	return array(
		'Description' => (string)$description,
		'Options' => '',
	);
}

function CharacterMainStat($stats) {
	$order = array('STR', 'AGI', 'DEX', 'HP', 'INT', 'SEN');
	$bestName = 'STR';
	$bestValue = -1;
	foreach ($order as $statName) {
		$value = (int)($stats[$statName] ?? 0);
		if ($value > $bestValue) {
			$bestName = $statName;
			$bestValue = $value;
		}
	}
	return array('Name' => $bestName, 'Value' => $bestValue, 'Display' => $bestName.' '.$bestValue);
}

function AddCharacterSearchPart(&$parts, $value) {
	$value = trim((string)$value);
	if ($value !== '' && $value !== '0' && strtoupper($value) !== 'NONE' && !in_array($value, $parts, true)) {
		$parts[] = $value;
	}
}

function EnchantChipName($enchantLv) {
	$level = 1 * $enchantLv;
	if ($level === 104) {
		return 'Veteran Chip';
	}
	if ($level === 108) {
		return 'Expert Chip';
	}
	if ($level === 112) {
		return 'Master Chip';
	}
	if ($level === 116 || $level === 500) {
		return 'High Master Chip';
	}
	if ($level > 100) {
		return 'Lv. '.$level.' Chip';
	}
	return 'Novice Chip';
}

function EnchantGroupId($category, $chipName, $optionGroup, $itemType) {
	return 'Enchant_'.substr(md5($category.'|'.$chipName.'|'.$optionGroup.'|'.$itemType), 0, 12);
}

function ItemAnchorId($category, $itemId) {
	return 'Item_'.preg_replace('/[^A-Za-z0-9_-]/', '_', $category.'_'.$itemId);
}

function SlugifyPathPart($value) {
	$value = strtolower(html_entity_decode((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
	$value = preg_replace('/[^a-z0-9]+/', '-', $value);
	$value = trim($value, '-');
	return $value === '' ? 'entry' : $value;
}

function ItemCategoryRouteDirectory($category) {
	$directories = array(
		'Weapon' => 'weapons',
		'Armor' => 'armor',
		'Accs' => 'accessories',
		'Medalkis' => 'medals',
	);
	return $directories[$category] ?? SlugifyPathPart($category);
}

function ItemTypeRoute($category, $itemType) {
	return ItemCategoryRouteDirectory($category).'/'.SlugifyPathPart($itemType).'/';
}

function ItemTypeAnchorHref($category, $itemType, $itemId) {
	return './'.ItemTypeRoute($category, $itemType).'#'.ItemAnchorId($category, $itemId);
}

function CharacterBaseRoute($characterName) {
	return 'characters/'.SlugifyPathPart($characterName).'/';
}

function HtmlIdSuffix($value) {
	$value = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)$value);
	return trim($value, '_');
}

function ReadTabCsvRows($filename) {
	if (!file_exists($filename)) {
		return array();
	}
	$handle = fopen($filename, 'r');
	if ($handle === false) {
		return array();
	}
	$headers = fgetcsv($handle, 0, "\t");
	if ($headers === false) {
		fclose($handle);
		return array();
	}
	$rows = array();
	while (($data = fgetcsv($handle, 0, "\t")) !== false) {
		$row = array();
		foreach ($headers as $index => $header) {
			$row[$header] = $data[$index] ?? '';
		}
		$rows[] = $row;
	}
	fclose($handle);
	return $rows;
}

function ReadConvertedIesCsvRows($iesFile) {
	if (!file_exists($iesFile) || !file_exists('./ix3.exe')) {
		return array();
	}
	$base = pathinfo($iesFile, PATHINFO_FILENAME);
	$tempDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'ge-db-'.$base.'-'.substr(md5(realpath($iesFile)), 0, 10);
	EnsureDirectory($tempDir);
	$workIes = $tempDir.DIRECTORY_SEPARATOR.basename($iesFile);
	$workExe = $tempDir.DIRECTORY_SEPARATOR.'ix3.exe';
	$workCsv = $tempDir.DIRECTORY_SEPARATOR.$base.'.csv';
	copy($iesFile, $workIes);
	copy('./ix3.exe', $workExe);
	if (!file_exists($workCsv) || filemtime($workCsv) < filemtime($workIes)) {
		$currentDir = getcwd();
		chdir($tempDir);
		shell_exec('ix3.exe '.escapeshellarg(basename($iesFile)));
		chdir($currentDir);
	}
	return ReadTabCsvRows($workCsv);
}

function FormatDropRate($rawValue) {
	$raw = trim((string)$rawValue);
	if ($raw === '' || $raw === '0') {
		return '';
	}
	return '1/'.$raw;
}

function FormatSecondsDuration($rawValue) {
	$seconds = (int)$rawValue;
	if ($seconds <= 0) {
		return '';
	}
	$days = intdiv($seconds, 86400);
	$seconds %= 86400;
	$hours = intdiv($seconds, 3600);
	$seconds %= 3600;
	$minutes = intdiv($seconds, 60);
	$parts = array();
	if ($days > 0) {
		$parts[] = $days.'d';
	}
	if ($hours > 0) {
		$parts[] = $hours.'h';
	}
	if ($minutes > 0 && $days === 0) {
		$parts[] = $minutes.'m';
	}
	if (empty($parts)) {
		$parts[] = ((int)$rawValue).'s';
	}
	return implode(' ', $parts);
}

function FormatRespawnRange($minValue, $maxValue) {
	$min = trim((string)$minValue);
	$max = trim((string)$maxValue);
	$minText = FormatSecondsDuration($min);
	$maxText = FormatSecondsDuration($max);
	if ($minText === '' && $maxText === '') {
		return 'Event/scheduled';
	}
	if ($maxText === '' || $min === $max) {
		return $minText;
	}
	if ($minText === '') {
		return $maxText;
	}
	return $minText.' - '.$maxText;
}

function AppendMonsterLocation(&$locations, $monsterId, $text) {
	$monsterId = (string)(1 * $monsterId);
	$text = trim((string)$text);
	if ($monsterId === '0' || $text === '') {
		return;
	}
	if (!isset($locations[$monsterId])) {
		$locations[$monsterId] = array();
	}
	if (!in_array($text, $locations[$monsterId], true)) {
		$locations[$monsterId][] = $text;
	}
}

function MonsterFlagText($monsterId, $monster) {
	$isBoss = (($monster['IsTypeBoss'] ?? '') === 'TRUE');
	$isRaid = (($monster['IsRaidMonster'] ?? '') === 'TRUE');
	if (preg_match('/BOSS|MBOSS|MIDBOSS/i', (string)($monster['Tactics'] ?? ''))) {
		$isBoss = true;
	}
	if (preg_match('/RAID/i', (string)($monster['Tactics'] ?? ''))) {
		$isRaid = true;
	}
	if ($isBoss && $isRaid) {
		return 'Boss / Raid';
	}
	if ($isRaid) {
		return 'Raid';
	}
	if ($isBoss) {
		return 'Boss';
	}
	return 'Monster';
}

function MonsterDisplayName($monsterId) {
	global $Monsters;
	return $Monsters['Name'][$monsterId] ?? ($Monsters['ClassName'][$monsterId] ?? ('Monster '.$monsterId));
}

function BuildMonsterGenericDropFields() {
	return array(
		'DropAccFreq' => array('Name' => 'Accessory drop pool', 'QuantityField' => '', 'Rules' => array('Acc'), 'Description' => 'Generic accessory roll from the monster table. Matching examples are detected from item rows with DropRule=Acc.'),
		'DropOldEqpFreq' => array('Name' => 'Old equipment drop pool', 'QuantityField' => '', 'Rules' => array('OldEqp'), 'Description' => 'Generic old equipment roll from the monster table. Matching examples are detected from item rows with DropRule=OldEqp.'),
		'DropNormEqpFreq' => array('Name' => 'Normal equipment drop pool', 'QuantityField' => '', 'Rules' => array('NormEqp'), 'Description' => 'Generic normal equipment roll from the monster table. Matching examples are detected from item rows with DropRule=NormEqp.'),
		'DropEliteEqpFreq' => array('Name' => 'Elite equipment drop pool', 'QuantityField' => '', 'Rules' => array('EliteEqp'), 'Description' => 'Generic elite equipment roll from the monster table. Matching examples are detected from item rows with DropRule=EliteEqp.'),
		'DropRecipeFreq' => array('Name' => 'Recipe drop pool', 'QuantityField' => '', 'Rules' => array(), 'Description' => 'Generic recipe roll from the monster table. The extracted item data does not expose a direct DropRule for this pool, so only the monster frequency is known.'),
		'DropEtcFreq' => array('Name' => 'Etc item drop pool', 'QuantityField' => '', 'Rules' => array('Etc'), 'Description' => 'Generic etc/material roll from the monster table. Matching examples are detected from item rows with DropRule=Etc.'),
		'DropInstFreq' => array('Name' => 'Instance drop pool', 'QuantityField' => '', 'Rules' => array('Inst'), 'Description' => 'Generic instance/special roll from the monster table. Matching examples are detected from item rows with DropRule=Inst.'),
		'DropCrystalFreq' => array('Name' => 'Crystal drop', 'QuantityField' => 'DropCrystalCount', 'Rules' => array('Crystal'), 'Description' => 'Generic crystal roll from the monster table. Quantity is read from DropCrystalCount when available.'),
	);
}

function MonsterExactDrops($monsterId) {
	global $Monsters;
	$drops = array();
	for ($i = 1; $i <= 6; $i++) {
		$dropId = $Monsters['Drop'.$i.'ID'][$monsterId] ?? '0';
		if ($dropId === '' || $dropId === '0') {
			continue;
		}
		$itemId = 1 * $dropId;
		$rawRate = $Monsters['Drop'.$i.'Per'][$monsterId] ?? '0';
		$quantity = $Monsters['Drop'.$i.'Quant'][$monsterId] ?? '1';
		$drops[] = array(
			'ItemId' => $itemId,
			'ItemName' => ItemDisplayName($itemId),
			'Rate' => FormatDropRate($rawRate),
			'RawRate' => $rawRate,
			'Quantity' => $quantity,
		);
	}
	return $drops;
}

function MonsterGenericDrops($monsterId, $genericDropFields = null) {
	global $Monsters;
	if ($genericDropFields === null) {
		$genericDropFields = BuildMonsterGenericDropFields();
	}
	$drops = array();
	foreach ($genericDropFields as $field => $definition) {
		$rawRate = trim((string)($Monsters[$field][$monsterId] ?? '0'));
		if ($rawRate === '' || $rawRate === '0') {
			continue;
		}
		$quantity = '1';
		if ($definition['QuantityField'] !== '') {
			$quantity = $Monsters[$definition['QuantityField']][$monsterId] ?? '1';
			if ($quantity === '' || $quantity === '0') {
				$quantity = '1';
			}
		}
		$drops[] = array(
			'Name' => $definition['Name'],
			'Field' => $field,
			'Rate' => FormatDropRate($rawRate),
			'RawRate' => $rawRate,
			'Quantity' => $quantity,
		);
	}
	return $drops;
}

function AddMapSourceIndex(&$index, $key, $mapId) {
	$key = trim((string)$key);
	if ($key === '' || $key === 'None') {
		return;
	}
	if (!isset($index[$key])) {
		$index[$key] = array();
	}
	if (!in_array($mapId, $index[$key], true)) {
		$index[$key][] = $mapId;
	}
}

function ResolveMongentypeMapIds($sourceClass, $mapSourceIndex) {
	$sourceClass = trim((string)$sourceClass);
	$candidates = array($sourceClass, 'dun_'.$sourceClass, 'btl_'.$sourceClass, 'fld_'.$sourceClass);
	if ($sourceClass === 'erc_boss') {
		$candidates[] = 'dun_erc01';
		$candidates[] = 'dun_erc_boss';
	}
	if (preg_match('/^(.+)_boss$/', $sourceClass)) {
		$candidates[] = 'dun_'.$sourceClass;
	}

	$mapIds = array();
	foreach ($candidates as $candidate) {
		if (!isset($mapSourceIndex[$candidate])) {
			continue;
		}
		foreach ($mapSourceIndex[$candidate] as $mapId) {
			if (!in_array($mapId, $mapIds, true)) {
				$mapIds[] = $mapId;
			}
		}
	}
	return $mapIds;
}

function BuildMapMonsterCard($monsterId, $genericDropFields) {
	global $Monsters;
	$flag = MonsterFlagText($monsterId, array(
		'IsTypeBoss' => $Monsters['IsTypeBoss'][$monsterId] ?? '',
		'IsRaidMonster' => $Monsters['IsRaidMonster'][$monsterId] ?? '',
		'Tactics' => $Monsters['Tactics'][$monsterId] ?? '',
	));
	$statFields = array(
		'ClassName' => 'Class',
		'RaceType' => 'Race',
		'ClassType' => 'Class Type',
		'WpnType' => 'Weapon Type',
		'ElemType' => 'Element',
		'DefType' => 'Defense Type',
		'SizeType' => 'Size',
		'AtkType' => 'Attack Type',
		'BlkType' => 'Block Type',
		'KDRank' => 'KD Rank',
		'MobRank' => 'Mob Rank',
		'Grade' => 'Grade',
		'EXP' => 'EXP',
		'STR' => 'STR',
		'CON' => 'CON',
		'Wpn' => 'Weapon',
		'Arm' => 'Armor',
		'Res' => 'Resistance',
		'HP' => 'HP',
		'RHP' => 'RHP',
		'KP' => 'KP',
		'BLOCK' => 'Block',
		'ASPD' => 'Attack Speed',
		'MSPD' => 'Move Speed',
		'MinR' => 'Min Range',
		'MaxR' => 'Max Range',
		'RFIRE' => 'Fire RES',
		'RICE' => 'Ice RES',
		'RLGHT' => 'Lightning RES',
		'RSTAT' => 'Status RES',
		'RPSY' => 'Mental RES',
		'MagicDefiance' => 'Magic Defiance',
		'MeleeDefiance' => 'Melee Defiance',
		'MartialDefiance' => 'Martial Defiance',
		'ShootDefiance' => 'Shoot Defiance',
		'DefIP_BM' => 'DEF Penetration',
		'IMP_BM' => 'Immunity',
		'AR_BM' => 'AR Bonus',
		'DR_BM' => 'DR Bonus',
		'Tactics' => 'Tactics',
		'Shape' => 'Shape',
	);
	$stats = '<dl class="map-monster-stats">';
	$stats .= '<div><dt>ID</dt><dd>'.Html($monsterId).'</dd></div>';
	$stats .= '<div><dt>Level</dt><dd>'.Html($Monsters['Lv'][$monsterId] ?? '').'</dd></div>';
	$stats .= '<div><dt>Flag</dt><dd>'.Html($flag).'</dd></div>';
	foreach ($statFields as $field => $label) {
		$value = trim((string)($Monsters[$field][$monsterId] ?? ''));
		if ($value === '' || $value === 'None') {
			continue;
		}
		$stats .= '<div><dt>'.Html($label).'</dt><dd>'.Html($value).'</dd></div>';
	}
	for ($i = 1; $i <= 4; $i++) {
		$skillId = trim((string)($Monsters['SkillID'.$i][$monsterId] ?? '0'));
		if ($skillId === '' || $skillId === '0') {
			continue;
		}
		$skillText = 'ID '.$skillId;
		$condition = trim((string)($Monsters['SkillCondition'.$i][$monsterId] ?? ''));
		$arg = trim((string)($Monsters['SkillArg'.$i][$monsterId] ?? ''));
		$freq = trim((string)($Monsters['SkillFreq'.$i][$monsterId] ?? ''));
		if ($condition !== '' && $condition !== 'None') {
			$skillText .= ' / '.$condition;
			if ($arg !== '' && $arg !== '0') {
				$skillText .= ' '.$arg;
			}
		}
		if ($freq !== '' && $freq !== '0') {
			$skillText .= ' / freq '.$freq;
		}
		$stats .= '<div><dt>Skill '.$i.'</dt><dd>'.Html($skillText).'</dd></div>';
	}
	$stats .= '</dl>';

	$dropHtml = '<ul class="monster-drop-list map-monster-drop-list">';
	$hasDrops = false;
	foreach (MonsterExactDrops($monsterId) as $drop) {
		$hasDrops = true;
		$dropHtml .= '<li><span class="monster-drop-name">'.Html($drop['ItemName']).'</span><span>x'.Html($drop['Quantity']).'</span><span>'.Html($drop['Rate']).'</span><span>raw '.Html($drop['RawRate']).'</span></li>';
	}
	foreach (MonsterGenericDrops($monsterId, $genericDropFields) as $drop) {
		$hasDrops = true;
		$dropHtml .= '<li class="monster-generic-drop"><span class="monster-drop-name">'.Html($drop['Name']).'<span class="monster-drop-field">'.Html($drop['Field']).'</span></span><span>x'.Html($drop['Quantity']).'</span><span>'.Html($drop['Rate']).'</span><span>raw '.Html($drop['RawRate']).'</span></li>';
	}
	$dropHtml .= '</ul>';
	if (!$hasDrops) {
		$dropHtml = '<p class="map-empty">No drops listed.</p>';
	}

	return '<article class="map-monster-card item-card">'
		.'<div class="item-card-top"><div class="item-card-name">'.Html(MonsterDisplayName($monsterId)).'</div><div class="item-card-equip">Lv '.Html($Monsters['Lv'][$monsterId] ?? '').' / '.Html($flag).'</div></div>'
		.'<div class="item-card-body"><div class="item-card-column map-monster-stats-column"><h4>Stats</h4>'.$stats.'</div><div class="item-card-column map-monster-drops-column"><h4>Drops</h4>'.$dropHtml.'</div></div>'
		.'</article>';
}

function BuildMapDropSummary($mapId, $monsterIds) {
	global $Maps;
	$dropLookup = array();
	for ($i = 1; $i <= 7; $i++) {
		$itemId = trim((string)($Maps['DropItem'.$i.'ID'][$mapId] ?? '0'));
		$rawRate = trim((string)($Maps['DropItem'.$i.'Freq'][$mapId] ?? '0'));
		if ($itemId === '' || $itemId === '0' || $rawRate === '' || $rawRate === '0') {
			continue;
		}
		$itemKey = (string)(1 * $itemId);
		if (!isset($dropLookup[$itemKey])) {
			$dropLookup[$itemKey] = array('Name' => ItemDisplayName($itemKey), 'Sources' => array(), 'Seen' => array());
		}
		$source = 'Map '.FormatDropRate($rawRate);
		if (!isset($dropLookup[$itemKey]['Seen'][$source])) {
			$dropLookup[$itemKey]['Seen'][$source] = true;
			$dropLookup[$itemKey]['Sources'][] = $source;
		}
	}
	foreach ($monsterIds as $monsterId) {
		foreach (MonsterExactDrops($monsterId) as $drop) {
			$itemKey = (string)$drop['ItemId'];
			if (!isset($dropLookup[$itemKey])) {
				$dropLookup[$itemKey] = array('Name' => $drop['ItemName'], 'Sources' => array(), 'Seen' => array());
			}
			$source = MonsterDisplayName($monsterId);
			if ($drop['Quantity'] !== '' && $drop['Quantity'] !== '1') {
				$source .= ' x'.$drop['Quantity'];
			}
			if ($drop['Rate'] !== '') {
				$source .= ' '.$drop['Rate'];
			}
			if (!isset($dropLookup[$itemKey]['Seen'][$source])) {
				$dropLookup[$itemKey]['Seen'][$source] = true;
				$dropLookup[$itemKey]['Sources'][] = $source;
			}
		}
	}
	uasort($dropLookup, function($a, $b) {
		return strcasecmp($a['Name'], $b['Name']);
	});
	if (empty($dropLookup)) {
		return '<section class="map-drop-panel"><h2>Drops</h2><p class="map-empty">No map or monster drops listed.</p></section>';
	}
	$html = '<section class="map-drop-panel"><h2>Drops</h2><ul class="map-drop-summary">';
	foreach ($dropLookup as $drop) {
		$html .= '<li>'.Html($drop['Name']).'('.Html(implode(', ', $drop['Sources'])).')</li>';
	}
	$html .= '</ul></section>';
	return $html;
}

function BuildMapsPage() {
	global $Maps, $Monsters;

	if (empty($Maps['ClassID'])) {
		return array('Header' => '', 'SideList' => '', 'List' => '');
	}

	$mapRecords = array();
	$mapSourceIndex = array();
	foreach ($Maps['ClassID'] as $mapId) {
		$className = trim((string)($Maps['ClassName'][$mapId] ?? ''));
		if ($className === '') {
			continue;
		}
		$name = trim((string)($Maps['Name'][$mapId] ?? ''));
		if ($name === '' || $name === 'None') {
			$name = trim((string)($Maps['Desc'][$mapId] ?? $className));
		}
		if ($name === '' || $name === 'None') {
			$name = $className;
		}
		$type = trim((string)($Maps['Type'][$mapId] ?? 'Other'));
		if ($type === '' || $type === 'None') {
			$type = 'Other';
		}
		$mapRecords[$mapId] = array(
			'Id' => $mapId,
			'PanelId' => 'Map_'.HtmlIdSuffix($mapId.'_'.$className),
			'Name' => $name,
			'ClassName' => $className,
			'Desc' => $Maps['Desc'][$mapId] ?? '',
			'Type' => $type,
			'BgName' => $Maps['BgName'][$mapId] ?? '',
			'TokName' => $Maps['TokName'][$mapId] ?? '',
			'BgmPlayList' => $Maps['BgmPlayList'][$mapId] ?? '',
			'Continent' => $Maps['Continent'][$mapId] ?? '',
		);
		AddMapSourceIndex($mapSourceIndex, $className, $mapId);
		AddMapSourceIndex($mapSourceIndex, $Maps['BgName'][$mapId] ?? '', $mapId);
		AddMapSourceIndex($mapSourceIndex, $Maps['TokName'][$mapId] ?? '', $mapId);
	}

	$mapMonsterIds = array();
	foreach (GeDataGlob('ies/mongentype_*.ies') as $mongenFile) {
		$sourceClass = preg_replace('/^mongentype_/', '', pathinfo($mongenFile, PATHINFO_FILENAME));
		$targetMapIds = ResolveMongentypeMapIds($sourceClass, $mapSourceIndex);
		if (empty($targetMapIds)) {
			continue;
		}
		foreach (ReadConvertedIesCsvRows($mongenFile) as $row) {
			$monsterId = (string)(1 * ($row['MonsterID'] ?? '0'));
			if ($monsterId === '0' || empty($Monsters['ClassID'][$monsterId])) {
				continue;
			}
			foreach ($targetMapIds as $mapId) {
				if (!isset($mapMonsterIds[$mapId])) {
					$mapMonsterIds[$mapId] = array();
				}
				$mapMonsterIds[$mapId][$monsterId] = true;
			}
		}
	}

	uasort($mapRecords, function($a, $b) {
		$typeCompare = strcasecmp($a['Type'], $b['Type']);
		if ($typeCompare !== 0) {
			return $typeCompare;
		}
		$nameCompare = strcasecmp($a['Name'], $b['Name']);
		if ($nameCompare !== 0) {
			return $nameCompare;
		}
		return ((int)$a['Id']) <=> ((int)$b['Id']);
	});

	$firstPanelId = '';
	$sideGroups = array();
	foreach ($mapRecords as $record) {
		if ($firstPanelId === '') {
			$firstPanelId = $record['PanelId'];
		}
		if (!isset($sideGroups[$record['Type']])) {
			$sideGroups[$record['Type']] = array();
		}
		$sideGroups[$record['Type']][] = $record;
	}

	$sideList = '<ul class="map-side-list">';
	foreach ($sideGroups as $type => $records) {
		$sideList .= '<li class="map-side-heading">'.Html($type).'</li>';
		foreach ($records as $record) {
			$sideList .= '<li class="WeapListLi"><a href="#'.$record['PanelId'].'" onclick="setSidePanel(`MapstempSide`,`docked`);ToggleContent(`MainContainer`,`Maps`);ToggleContent(`Maps`,`'.$record['PanelId'].'`);ScrollToContentTop();closeNav();">'.Html($record['Name']).'</a></li>';
		}
	}
	$sideList .= '</ul>';

	$genericDropFields = BuildMonsterGenericDropFields();
	$list = '';
	foreach ($mapRecords as $record) {
		$monsterIds = array_keys($mapMonsterIds[$record['Id']] ?? array());
		usort($monsterIds, function($a, $b) {
			$nameCompare = strcasecmp(MonsterDisplayName($a), MonsterDisplayName($b));
			if ($nameCompare !== 0) {
				return $nameCompare;
			}
			return ((int)$a) <=> ((int)$b);
		});
		$meta = '<dl class="map-meta-grid">';
		foreach (array('ClassName' => 'Class', 'Type' => 'Type', 'BgName' => 'Background', 'TokName' => 'Token', 'BgmPlayList' => 'BGM', 'Continent' => 'Continent') as $field => $label) {
			$value = trim((string)($record[$field] ?? ''));
			if ($value === '' || $value === 'None') {
				continue;
			}
			$meta .= '<div><dt>'.Html($label).'</dt><dd>'.Html($value).'</dd></div>';
		}
		$meta .= '</dl>';

		$monsterHtml = '';
		foreach ($monsterIds as $monsterId) {
			$monsterHtml .= BuildMapMonsterCard($monsterId, $genericDropFields);
		}
		if ($monsterHtml === '') {
			$monsterHtml = '<p class="map-empty">No monsters listed from the extracted spawn tables.</p>';
		} else {
			$monsterHtml = '<div class="map-monster-grid">'.$monsterHtml.'</div>';
		}

		$list .= '<div id="'.$record['PanelId'].'" class="map-panel" style="display:none">'
			.'<section class="map-detail-panel">'
			.'<div class="map-title-row"><h1>'.Html($record['Name']).'</h1><span>ID '.Html($record['Id']).'</span></div>'
			.$meta
			.'<h2>Monsters</h2>'
			.$monsterHtml
			.BuildMapDropSummary($record['Id'], $monsterIds)
			.'</section></div>';
	}

	return array(
		'Header' => "<span style=\"font-size:30px;cursor:pointer\" onclick=\"setSidePanel('MapstempSide','docked');ToggleContent('MainContainer','Maps');ToggleContent('Maps','".$firstPanelId."');ScrollToContentTop();\">Maps</span>",
		'SideList' => $sideList,
		'List' => $list,
	);
}

function BuildMonsterPage() {
	global $Monsters, $Items, $Maps, $RaidRespawns;

	if (empty($Monsters['ClassID'])) {
		return array(
			'Header' => '',
			'SideList' => '',
			'List' => '',
		);
	}

	$mapNames = array();
	if (!empty($Maps['ClassID'])) {
		foreach ($Maps['ClassID'] as $mapId) {
			$className = $Maps['ClassName'][$mapId] ?? '';
			if ($className === '') {
				continue;
			}
			$name = $Maps['Name'][$mapId] ?? '';
			if ($name === '' || $name === 'None') {
				$name = $Maps['Desc'][$mapId] ?? $className;
			}
			$mapNames[$className] = $name;
		}
	}

	$locations = array();
	$bossRespawns = array();
	$bossRespawnSeen = array();
	if (!empty($RaidRespawns['ClassID'])) {
		foreach ($RaidRespawns['ClassID'] as $raidId) {
			$bossId = $RaidRespawns['BossClassID'][$raidId] ?? '0';
			$mapName = $RaidRespawns['MapName'][$raidId] ?? '';
			$location = $RaidRespawns['Location'][$raidId] ?? '';
			$parts = array();
			if ($mapName !== '' && $mapName !== 'None') {
				$parts[] = $mapName;
			}
			if ($location !== '' && $location !== 'None' && $location !== $mapName) {
				$parts[] = $location;
			}
			AppendMonsterLocation($locations, $bossId, implode(' - ', $parts));
			$respawnText = FormatRespawnRange($RaidRespawns['Time01'][$raidId] ?? '0', $RaidRespawns['Time02'][$raidId] ?? '0');
			$rawTimer = trim((string)($RaidRespawns['Time01'][$raidId] ?? '0')).' - '.trim((string)($RaidRespawns['Time02'][$raidId] ?? '0'));
			$note = $RaidRespawns['TimeDesc'][$raidId] ?? '';
			if ($note === 'None') {
				$note = '';
			}
			$key = implode('|', array('raid', (string)(1 * $bossId), $mapName, $rawTimer, $location));
			if (!isset($bossRespawnSeen[$key])) {
				$bossRespawnSeen[$key] = true;
				$bossRespawns[] = array(
					'Name' => $RaidRespawns['Name'][$raidId] ?? ItemDisplayName($bossId),
					'ClassName' => $Monsters['ClassName'][$bossId] ?? ($RaidRespawns['ClassName'][$raidId] ?? ''),
					'Level' => $RaidRespawns['BossLv'][$raidId] ?? ($Monsters['Lv'][$bossId] ?? ''),
					'Flag' => 'Boss / Raid',
					'Map' => $mapName,
					'Location' => implode('<br>', array_map('Html', $parts)),
					'Respawn' => $respawnText,
					'Raw' => $rawTimer,
					'Source' => 'Raid respawn',
					'Note' => $note,
				);
			}
		}
	}

	foreach (GeDataGlob('ies/mongentype_*.ies') as $mongenFile) {
		$mapClass = preg_replace('/^mongentype_/', '', pathinfo($mongenFile, PATHINFO_FILENAME));
		$mapName = $mapNames[$mapClass] ?? $mapClass;
		$rows = ReadConvertedIesCsvRows($mongenFile);
		foreach ($rows as $row) {
			$monsterId = $row['MonsterID'] ?? '0';
			$respawn = $row['RespawnTime'] ?? '';
			$location = $mapName;
			if ($respawn !== '' && $respawn !== '0') {
				$location .= ' (respawn '.$respawn.')';
			}
			AppendMonsterLocation($locations, $monsterId, $location);
			$monsterKey = (string)(1 * $monsterId);
			if ($respawn !== '' && $respawn !== '0' && isset($Monsters['ClassID'][$monsterKey])) {
				$flag = MonsterFlagText($monsterKey, array(
					'IsTypeBoss' => $Monsters['IsTypeBoss'][$monsterKey] ?? '',
					'IsRaidMonster' => $Monsters['IsRaidMonster'][$monsterKey] ?? '',
					'Tactics' => $Monsters['Tactics'][$monsterKey] ?? '',
				));
				if ($flag !== 'Monster') {
					$key = implode('|', array('mongen', $monsterKey, $mapName, $respawn));
					if (!isset($bossRespawnSeen[$key])) {
						$bossRespawnSeen[$key] = true;
						$bossRespawns[] = array(
							'Name' => $Monsters['Name'][$monsterKey] ?? ($Monsters['ClassName'][$monsterKey] ?? ('Monster '.$monsterKey)),
							'ClassName' => $Monsters['ClassName'][$monsterKey] ?? '',
							'Level' => $Monsters['Lv'][$monsterKey] ?? '',
							'Flag' => $flag,
							'Map' => $mapName,
							'Location' => Html($location),
							'Respawn' => FormatSecondsDuration($respawn),
							'Raw' => $respawn,
							'Source' => 'Mongentype',
							'Note' => '',
						);
					}
				}
			}
		}
	}

	$monsterRows = array();
	$itemDrops = array();
	$genericDropFields = BuildMonsterGenericDropFields();

	$poolItems = array();
	foreach ($genericDropFields as $field => $definition) {
		$poolItems[$field] = array('Count' => 0, 'Samples' => array());
	}
	if (!empty($Items['ClassID'])) {
		foreach ($Items['ClassID'] as $itemId) {
			$itemDropRule = trim((string)($Items['DropRule'][$itemId] ?? ''));
			if ($itemDropRule === '') {
				continue;
			}
			foreach ($genericDropFields as $field => $definition) {
				if (empty($definition['Rules']) || !in_array($itemDropRule, $definition['Rules'], true)) {
					continue;
				}
				$poolItems[$field]['Count']++;
				if (count($poolItems[$field]['Samples']) < 8) {
					$poolItems[$field]['Samples'][] = ItemDisplayName($itemId);
				}
			}
		}
	}

	foreach ($Monsters['ClassID'] as $monsterId) {
		$drops = array();
		$genericDrops = array();
		for ($i = 1; $i <= 6; $i++) {
			$dropId = $Monsters['Drop'.$i.'ID'][$monsterId] ?? '0';
			if ($dropId === '' || $dropId === '0') {
				continue;
			}
			$itemId = 1 * $dropId;
			$itemName = ItemDisplayName($itemId);
			$rawRate = $Monsters['Drop'.$i.'Per'][$monsterId] ?? '0';
			$quantity = $Monsters['Drop'.$i.'Quant'][$monsterId] ?? '1';
			$drops[] = array(
				'ItemId' => $itemId,
				'ItemName' => $itemName,
				'Rate' => FormatDropRate($rawRate),
				'RawRate' => $rawRate,
				'Quantity' => $quantity,
			);
		}

		foreach ($genericDropFields as $field => $definition) {
			$rawRate = trim((string)($Monsters[$field][$monsterId] ?? '0'));
			if ($rawRate === '' || $rawRate === '0') {
				continue;
			}
			$quantity = '1';
			if ($definition['QuantityField'] !== '') {
				$quantity = $Monsters[$definition['QuantityField']][$monsterId] ?? '1';
				if ($quantity === '' || $quantity === '0') {
					$quantity = '1';
				}
			}
			$genericDrops[] = array(
				'Name' => $definition['Name'],
				'Field' => $field,
				'Rate' => FormatDropRate($rawRate),
				'RawRate' => $rawRate,
				'Quantity' => $quantity,
			);
		}

		if (empty($drops) && empty($genericDrops)) {
			continue;
		}

		$monster = array(
			'Id' => $monsterId,
			'Name' => $Monsters['Name'][$monsterId] ?? ($Monsters['ClassName'][$monsterId] ?? ('Monster '.$monsterId)),
			'ClassName' => $Monsters['ClassName'][$monsterId] ?? '',
			'Level' => $Monsters['Lv'][$monsterId] ?? '',
			'Flag' => MonsterFlagText($monsterId, array(
				'IsTypeBoss' => $Monsters['IsTypeBoss'][$monsterId] ?? '',
				'IsRaidMonster' => $Monsters['IsRaidMonster'][$monsterId] ?? '',
				'Tactics' => $Monsters['Tactics'][$monsterId] ?? '',
			)),
			'Location' => implode('<br>', array_map('Html', $locations[(string)(1 * $monsterId)] ?? array())),
			'Drops' => $drops,
			'GenericDrops' => $genericDrops,
		);
		$monsterRows[] = $monster;

		foreach ($drops as $drop) {
			if (!isset($itemDrops[$drop['ItemId']])) {
				$itemDrops[$drop['ItemId']] = array(
					'Name' => $drop['ItemName'],
					'Drops' => array(),
				);
			}
			$itemDrops[$drop['ItemId']]['Drops'][] = array(
				'MonsterName' => $monster['Name'],
				'Level' => $monster['Level'],
				'Flag' => $monster['Flag'],
				'Location' => $monster['Location'],
				'Rate' => $drop['Rate'],
				'RawRate' => $drop['RawRate'],
				'Quantity' => $drop['Quantity'],
			);
		}
	}

	usort($monsterRows, function($a, $b) {
		$nameCompare = strcasecmp($a['Name'], $b['Name']);
		if ($nameCompare !== 0) {
			return $nameCompare;
		}
		return ((int)$a['Level']) <=> ((int)$b['Level']);
	});
	uasort($itemDrops, function($a, $b) {
		return strcasecmp($a['Name'], $b['Name']);
	});
	usort($bossRespawns, function($a, $b) {
		$mapCompare = strcasecmp($a['Map'], $b['Map']);
		if ($mapCompare !== 0) {
			return $mapCompare;
		}
		return strcasecmp($a['Name'], $b['Name']);
	});

	$definitionTable = '<section id="DropPoolDefinitions" class="MonsterPanel"><h2>Drop Pool Definitions</h2><table class="monster-table monster-definition-table"><thead><tr><th>Pool</th><th>Source Field</th><th>Item Rule</th><th>Definition</th><th>Rate / Quantity</th></tr></thead><tbody>';
	$definitionTable .= '<tr><td>Exact item drops</td><td>Drop1ID-Drop6ID</td><td>Specific item IDs</td><td>Specific item IDs listed directly on the monster row.</td><td>DropNPer displays as 1/raw value. Quantity comes from DropNQuant.</td></tr>';
	foreach ($genericDropFields as $field => $definition) {
		$rateText = 'Displays as 1/raw value.';
		if ($definition['QuantityField'] !== '') {
			$rateText .= ' Quantity comes from '.$definition['QuantityField'].'.';
		} else {
			$rateText .= ' Quantity defaults to 1 because no quantity field is provided.';
		}
		$ruleText = !empty($definition['Rules']) ? implode(', ', $definition['Rules']) : 'Unresolved';
		$members = $poolItems[$field]['Count'] > 0 ? ' Detected members: '.$poolItems[$field]['Count'].'. Examples: '.implode(', ', $poolItems[$field]['Samples']).'.' : ' No matching item DropRule rows were found in the extracted data.';
		$definitionTable .= '<tr><td>'.Html($definition['Name']).'</td><td>'.Html($field).'</td><td>'.Html($ruleText).'</td><td>'.Html($definition['Description'].$members).'</td><td>'.Html($rateText).'</td></tr>';
	}
	$definitionTable .= '</tbody></table></section>';

	$bossTable = '<section id="BossRespawns" class="MonsterPanel"><h2>Boss Respawns</h2><table class="monster-table boss-respawn-table"><thead><tr><th>Boss</th><th>Level</th><th>Flag</th><th>Map / Location</th><th>Respawn</th><th>Raw Timer</th><th>Source / Note</th></tr></thead><tbody>';
	foreach ($bossRespawns as $boss) {
		$note = $boss['Note'] !== '' ? '<br><span class="monster-location">'.Html($boss['Note']).'</span>' : '';
		$bossTable .= '<tr><td><strong>'.Html($boss['Name']).'</strong><br><span>'.Html($boss['ClassName']).'</span></td><td>'.Html($boss['Level']).'</td><td>'.Html($boss['Flag']).'</td><td><strong>'.Html($boss['Map']).'</strong><br><span>'.($boss['Location'] ?: '').'</span></td><td>'.Html($boss['Respawn']).'</td><td>'.Html($boss['Raw']).'</td><td>'.Html($boss['Source']).$note.'</td></tr>';
	}
	$bossTable .= '</tbody></table></section>';

	$monsterTable = '<section id="MonsterDrops" class="MonsterPanel"><h2>Monster Drops</h2><div class="monster-tools"><label class="visually-hidden" for="MonsterSearch">Search monsters</label><input type="text" id="MonsterSearch" onkeyup="FilterMonsterTables()" placeholder="Search monsters, items, or locations"></div><table class="monster-table"><thead><tr><th>Monster</th><th>Level</th><th>Flag</th><th>Location</th><th>Drops</th></tr></thead><tbody>';
	foreach ($monsterRows as $monster) {
		$dropItems = '<ul class="monster-drop-list">';
		foreach ($monster['Drops'] as $drop) {
			$dropItems .= '<li><span class="monster-drop-name">'.Html($drop['ItemName']).'</span><span>x'.Html($drop['Quantity']).'</span><span>'.Html($drop['Rate']).'</span><span>raw '.Html($drop['RawRate']).'</span></li>';
		}
		foreach ($monster['GenericDrops'] as $drop) {
			$dropItems .= '<li class="monster-generic-drop"><span class="monster-drop-name">'.Html($drop['Name']).'<span class="monster-drop-field">'.Html($drop['Field']).'</span></span><span>x'.Html($drop['Quantity']).'</span><span>'.Html($drop['Rate']).'</span><span>raw '.Html($drop['RawRate']).'</span></li>';
		}
		$dropItems .= '</ul>';
		$monsterTable .= '<tr><td><strong>'.Html($monster['Name']).'</strong><br><span>'.Html($monster['ClassName']).'</span></td><td>'.Html($monster['Level']).'</td><td>'.Html($monster['Flag']).'</td><td>'.($monster['Location'] ?: '').'</td><td>'.$dropItems.'</td></tr>';
	}
	$monsterTable .= '</tbody></table></section>';

	$itemTable = '<section id="ItemDropLookup" class="MonsterPanel"><h2>Item Drop Lookup</h2><table class="monster-table"><thead><tr><th>Item</th><th>Monsters</th></tr></thead><tbody>';
	foreach ($itemDrops as $itemDrop) {
		$monsterList = '<ul class="monster-drop-list monster-source-list">';
		foreach ($itemDrop['Drops'] as $drop) {
			$detailParts = array();
			if ($drop['Level'] !== '') {
				$detailParts[] = 'Lv '.$drop['Level'];
			}
			if ($drop['Flag'] !== '') {
				$detailParts[] = $drop['Flag'];
			}
			$detailParts[] = 'x'.$drop['Quantity'];
			$detailParts[] = $drop['Rate'];
			$detailParts[] = 'raw '.$drop['RawRate'];
			$location = $drop['Location'] !== '' ? '<br><span class="monster-location">'.($drop['Location']).'</span>' : '';
			$monsterList .= '<li><span class="monster-drop-name">'.Html($drop['MonsterName']).'</span><span>'.Html(implode(' | ', $detailParts)).'</span>'.$location.'</li>';
		}
		$monsterList .= '</ul>';
		$itemTable .= '<tr><td><strong>'.Html($itemDrop['Name']).'</strong></td><td>'.$monsterList.'</td></tr>';
	}
	$itemTable .= '</tbody></table></section>';

	return array(
		'Header' => "<span style=\"font-size:30px;cursor:pointer\" onclick=\"setSidePanel('MonsterstempSide','docked');ToggleContent('MainContainer','Monsters');ScrollToContentTop();\">Bosses</span>",
		'SideList' => '<ul><li class="WeapListLi"><a href="#BossRespawns" onclick="ToggleContent(`MainContainer`,`Monsters`);closeNav();">Boss Respawns</a></li></ul>',
		'List' => $bossTable,
	);
}

function ItemEquipLevelGroup($tooltip) {
	$text = html_entity_decode((string)$tooltip, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	$text = preg_replace('/<br\s*\/?>/i', ' ', $text);
	$text = trim(strip_tags($text));
	if (preg_match('/Lv\.?\s*(\d+)/i', $text, $matches)) {
		$level = (int)$matches[1];
		return array(
			'Label' => 'Level '.$level,
			'Sort' => $level,
			'Key' => sprintf('%05d_Level_%d', $level, $level),
		);
	}

	$ranks = array(
		'Veteran' => 1000,
		'Expert' => 1100,
		'Master' => 1200,
		'High Master' => 1300,
		'HighMaster' => 1300,
	);
	foreach ($ranks as $rank => $sort) {
		if (preg_match('/\b'.preg_quote($rank, '/').'\b/i', $text)) {
			$label = $rank === 'HighMaster' ? 'High Master' : $rank;
			return array(
				'Label' => $label,
				'Sort' => $sort,
				'Key' => sprintf('%05d_%s', $sort, HtmlIdSuffix($label)),
			);
		}
	}

	return array(
		'Label' => 'No Level Requirement',
		'Sort' => 0,
		'Key' => '00000_No_Level_Requirement',
	);
}

function ItemLevelBlock($category, $categoryName, $levelLabel, $items) {
	$blockId = HtmlIdSuffix($category.'_'.$categoryName.'_'.$levelLabel);
	return '<section id="'.$blockId.'" class="item-level-block">'.
		'<div class="classic-level-head">'.
			'<span class="classic-title-left" aria-hidden="true"></span>'.
			'<h3 class="classic-title-mid">'.Html($levelLabel).'</h3>'.
			'<span class="classic-title-right" aria-hidden="true"></span>'.
		'</div>'.
		'<div class="classic-window-top" aria-hidden="true"></div>'.
		'<div class="classic-window-mid">'.
			'<div class="item-level-grid">'.$items.'</div>'.
		'</div>'.
		'<div class="classic-window-bottom" aria-hidden="true"></div>'.
	'</section>';
}

function ItemTypeLabel($categoryName) {
	return '<div class="item-type-label">'.Html($categoryName).'</div>';
}

function JsLiteral($value) {
	return json_encode((string)$value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

function EnchantChipLink($groupId, $chipName) {
	$onclick = "setSidePanel(null,'none');ToggleContent('MainContainer','EnchantChips');ToggleContent('EnchantChips','".$groupId."');ScrollToContentTop();closeNav();";
	return '<a class="chip-link" href="./enchant-chips.html#'.$groupId.'" onclick="'.Html($onclick).'">'.Html($chipName).'</a>';
}

function RegisterRenderedItemAnchor($category, $itemId) {
	global $RenderedItemAnchors;
	$RenderedItemAnchors[$category.'#'.$itemId] = true;
}

function HasRenderedItemAnchor($category, $itemId) {
	global $RenderedItemAnchors;
	return isset($RenderedItemAnchors[$category.'#'.$itemId]);
}

function ItemPageLink($category, $itemType, $itemId, $itemName, $requireRenderedAnchor = false) {
	if ($requireRenderedAnchor && !HasRenderedItemAnchor($category, $itemId)) {
		return Html($itemName);
	}
	return '<a href="'.Html(ItemTypeAnchorHref($category, $itemType, $itemId)).'">'.Html($itemName).'</a>';
}

function ItemCategoryPageKey($category) {
	$keys = array(
		'Weapon' => 'weapons',
		'Armor' => 'armor',
		'Accs' => 'accessories',
		'Medalkis' => 'medals',
	);
	return $keys[$category] ?? 'home';
}

function ItemCategorySidePanel($category) {
	$panels = array(
		'Weapon' => 'WeapontempSide',
		'Armor' => 'ArmortempSide',
		'Accs' => 'AccstempSide',
		'Medalkis' => 'MedalkistempSide',
	);
	return $panels[$category] ?? '';
}

function RegisterItemTypePage($category, $itemType, $panelHtml) {
	global $ItemTypePages;
	$key = $category.'#'.$itemType;
	$ItemTypePages[$key] = array(
		'Category' => $category,
		'Key' => ItemCategoryPageKey($category),
		'Route' => ItemTypeRoute($category, $itemType).'index.html',
		'ItemType' => $itemType,
		'PanelHtml' => $panelHtml,
		'SidePanel' => ItemCategorySidePanel($category),
	);
}

function BuildItemTypeSection($detail) {
	return '<div id="'.$detail['Category'].'">'.$detail['PanelHtml'].'</div>';
}

function WriteItemTypePages($fullHtml) {
	global $ItemTypePages;
	foreach ($ItemTypePages as $detail) {
		$page = array(
			'Key' => $detail['Key'],
			'BasePath' => '../../',
			'SidePanel' => $detail['SidePanel'],
			'ContentParent' => $detail['Category'],
			'DefaultChild' => $detail['Category'].$detail['ItemType'],
			'TypePage' => true,
		);
		WriteGeneratedFile(WebOutputPath($detail['Route']), BuildPageHtml($fullHtml, $page, BuildItemTypeSection($detail)));
	}
}

function RegisterCharacterDetailPage($characterName, $className, $actionScript) {
	global $CharacterDetailPages;
	$route = CharacterBaseRoute($characterName);
	if (isset($CharacterDetailPages[$route]) && $CharacterDetailPages[$route]['ClassName'] !== $className) {
		$route = 'characters/'.SlugifyPathPart($characterName.'-'.$className).'/';
		$suffix = 2;
		while (isset($CharacterDetailPages[$route])) {
			$route = 'characters/'.SlugifyPathPart($characterName.'-'.$className.'-'.$suffix).'/';
			$suffix++;
		}
	}

	$CharacterDetailPages[$route] = array(
		'Name' => $characterName,
		'ClassName' => $className,
		'Action' => $actionScript,
		'Route' => $route.'index.html',
	);
	return $route;
}

function BuildCharacterDetailSection($fullHtml, $detail) {
	$detailView = ExtractTopLevelDivById($fullHtml, 'CharacterDetailView');
	if ($detailView === '') {
		return '';
	}

	$detailView = preg_replace('/^<div id="CharacterDetailView"[^>]*>/i', '<div id="CharacterDetailView" class="character-detail-view standalone">', $detailView, 1);
	$detailView = preg_replace(
		'/<div class="character-detail-toolbar">.*?<\/div>/s',
		'<div class="character-detail-toolbar"><a class="character-back-button" href="./characters/">Back to Gallery</a></div>',
		$detailView,
		1
	);

	return '<div id="Character" class="character-page">'.
		$detailView.
		'<script>document.addEventListener("DOMContentLoaded",function(){'.$detail['Action'].'});</script>'.
	'</div>';
}

function WriteCharacterDetailPages($fullHtml) {
	global $CharacterDetailPages;
	foreach ($CharacterDetailPages as $detail) {
		$page = array(
			'Key' => 'characters',
			'BasePath' => '../../',
		);
		WriteGeneratedFile(WebOutputPath($detail['Route']), BuildPageHtml($fullHtml, $page, BuildCharacterDetailSection($fullHtml, $detail)));
	}
}

function RegisterEnchantGroup($category, $currentItem) {
	global ${$category};
	global $EnchantGroups;

	$optionGroup = ${$category}['OptionGroup'][$currentItem] ?? 'None';
	if ($optionGroup === 'None' || $optionGroup === '') {
		return '';
	}

	$enchantLv = ${$category}['EnchantLv'][$currentItem] ?? 0;
	$chipName = EnchantChipName($enchantLv);
	$itemType = ${$category}['Category2Name'][$currentItem] ?? (${$category}['Category1'][$currentItem] ?? $category);
	$groupId = EnchantGroupId($category, $chipName, $optionGroup, $itemType);
	$itemName = ${$category}['ItemName'][$currentItem] ?? (${$category}['ClassName'][$currentItem] ?? ('Item '.$currentItem));

	if (!isset($EnchantGroups[$groupId])) {
		$EnchantGroups[$groupId] = array(
			'Id' => $groupId,
			'Category' => $category,
			'ChipName' => $chipName,
			'EnchantLv' => $enchantLv,
			'OptionGroup' => $optionGroup,
			'ItemType' => $itemType,
			'Count' => 0,
			'Samples' => array(),
		);
	}

	$EnchantGroups[$groupId]['Count']++;
	if (count($EnchantGroups[$groupId]['Samples']) < 18) {
		$EnchantGroups[$groupId]['Samples'][] = array(
			'Category' => $category,
			'ItemType' => $itemType,
			'ItemId' => ItemAnchorId($category, $currentItem),
			'Name' => $itemName,
		);
	}

	return $groupId;
}

function BuildEnchantChipPage() {
	global $EnchantGroups;
	global $OptionList;

	if (empty($EnchantGroups)) {
		return array(
			'Header' => '',
			'SideList' => '',
			'List' => '<div id="EnchantIndex"><h2>Enchant Chips</h2></div>',
		);
	}

	uasort($EnchantGroups, function($a, $b) {
		$order = array('Novice Chip' => 1, 'Veteran Chip' => 2, 'Expert Chip' => 3, 'Master Chip' => 4, 'High Master Chip' => 5);
		$aOrder = $order[$a['ChipName']] ?? 99;
		$bOrder = $order[$b['ChipName']] ?? 99;
		return array($aOrder, $a['Category'], $a['ItemType'], $a['OptionGroup']) <=> array($bOrder, $b['Category'], $b['ItemType'], $b['OptionGroup']);
	});

	$sideList = '<ul><li class="WeapListLi"><a href="#" onclick="setSidePanel(null,\'none\');ToggleContent(\'MainContainer\',\'EnchantChips\');ToggleContent(\'EnchantChips\',\'EnchantIndex\');ScrollToContentTop();closeNav();return false;">Overview</a></li>';
	$overview = '<div id="EnchantIndex" class="EnchantIndex"><h2>Enchant Chips</h2><table><thead><tr><th>Chip</th><th>Item type</th><th>Option group</th><th>Items</th></tr></thead><tbody>';
	$details = '';

	foreach ($EnchantGroups as $group) {
		$linkAction = "setSidePanel(null,'none');ToggleContent('MainContainer','EnchantChips');ToggleContent('EnchantChips','".$group['Id']."');ScrollToContentTop();closeNav();";
		$link = '<a href="#'.$group['Id'].'" onclick="'.$linkAction.'">'.Html($group['ChipName']).' - '.Html($group['Category']).' / '.Html($group['ItemType']).'</a>';
		$sideList .= '<li class="WeapListLi">'.$link.'</li>';
		$overview .= '<tr><td>'.EnchantChipLink($group['Id'], $group['ChipName']).'</td><td>'.Html($group['Category']).' / '.Html($group['ItemType']).'</td><td>'.Html($group['OptionGroup']).'</td><td>'.Html($group['Count']).'</td></tr>';

		$samples = '';
		foreach ($group['Samples'] as $sample) {
			$samples .= '<li>'.ItemPageLink($sample['Category'], $sample['ItemType'], $sample['ItemId'], $sample['Name'], true).'</li>';
		}
		if ($group['Count'] > count($group['Samples'])) {
			$samples .= '<li>+'.Html($group['Count'] - count($group['Samples'])).' more</li>';
		}

		$details .= '<div id="'.$group['Id'].'" class="EnchantGroup" style="display:none">';
		$details .= '<h2>'.Html($group['ChipName']).'</h2>';
		$details .= '<dl class="enchant-meta"><dt>Applies to</dt><dd>'.Html($group['Category']).' / '.Html($group['ItemType']).'</dd><dt>Enchant Lv</dt><dd>'.Html($group['EnchantLv']).'</dd><dt>Option group</dt><dd>'.Html($group['OptionGroup']).'</dd><dt>Matching items</dt><dd>'.Html($group['Count']).'</dd></dl>';
		$details .= '<div class="enchant-options">'.($OptionList[$group['OptionGroup']] ?? '').'</div>';
		$details .= '<h3>Items</h3><ul class="enchant-samples">'.$samples.'</ul>';
		$details .= '</div>';
	}

	$sideList .= '</ul>';
	$overview .= '</tbody></table></div>';

	return array(
		'Header' => "<span style=\"font-size:30px;cursor:pointer\" onclick=\"setSidePanel(null,'none');ToggleContent('MainContainer','EnchantChips');ToggleContent('EnchantChips','EnchantIndex');ScrollToContentTop();\">Enchant Chips</span>",
		'SideList' => $sideList,
		'List' => $overview.$details,
	);
}

$AutomatedChangelogEntries = UpdateExtractChangelogHistory();
$AutomatedChangelogHtml = BuildAutomatedChangelogHtml($AutomatedChangelogEntries);
$CharacterDetailPages = array();
$ItemTypePages = array();
$megaitems = '';

foreach (GeDataGlob("xml/datatable_item_*.xml") as $filename) {
    $megaitems .= $filename . ' ';
}

global $megastances;

$megastances=array();
SaXtAGe("xml/datatable_accomplishment.xml", 'Achieve');
SaXtAGe("xml/datatable_itemmake.xml", 'Recipe');
SaXtAGe("xml/datatable_itemopt.xml",'Options');
SaXtAGe("xml/datatable_item_weapon.xml", 'Weapon');
SaXtAGe("xml/datatable_item_achieve.xml", 'Medalkis');
SaXtAGe("xml/datatable_item_glove.xml xml/datatable_item_belt.xml xml/datatable_item_neck.xml xml/datatable_item_earring.xml xml/datatable_item_ring.xml xml/datatable_item_boots.xml", 'Accs');
SaXtAGe("xml/datatable_item_armor.xml", 'Armor');
SaXtA(trim($megaitems), 'Items', 'Dummy_A_LH Dummy_A_RH Dummy_B Dummy_F Dummy_N_LH Dummy_N_RH Weight arg1 arg2 UseGravity UseInverse ItemIndicator DropSound');
ResolveDictionaryRefsInArray($Items);
ApplyCommonNameFallbacks($Items);
SaXtAGe("xml/datatable_job.xml", 'Characters');
ApplyCharacterNameFallbacks();
WriteCharacterAvailabilityJson();
SaXtAGe("xml/datatable_stance.xml", 'Stances');
SaXtAGe("xml/datatable_stancecondition.xml", 'StanceConds');
SaXtAGe("xml/datatable_skill.xml", 'Skill');
SaXtAGe("xml/datatable_npclist.xml", 'NPCList');
SaXtAGe("xml/datatable_expedition_tag.xml",'ExpTags');
SaXtAGe("xml/datatable_buff.xml",'Buffs');
SaXtAGe("xml/datatable_monster.xml xml/datatable_monster_1.xml xml/datatable_monster_2.xml xml/datatable_monster_3.xml xml/datatable_monster_4.xml", 'Monsters');
SaXtAGe("xml/datatable_map.xml", 'Maps');
SaXtAGe("xml/datatable_raid_respawntime.xml", 'RaidRespawns');

foreach ($Buffs['ClassID'] as $BCID)
{$Buffs['ToolTip'][$Buffs['ClassName'][$BCID]]=$Buffs['ToolTip'][$BCID];}
$CharCards = '';
ob_start();
foreach ($Characters['ClassID'] as $id)
{if(in_array($Characters['ClassName'][$id],$NPCList['ClassName'])&($Characters['JobSkill'][$id]!="0"))
	{
		$CharacterIsActive = !empty($CharacterAvailabilityByClassId[(int)$id]);
		//Just a bit more fancy text changes on character buffs.
			$Characters['CharacterBuff'][$id]=str_replace('ChaDEF','DEF +3',($Characters['CharacterBuff'][$id]));
			$Characters['CharacterBuff'][$id]=str_replace('ChaCast','Cast Time -2%',($Characters['CharacterBuff'][$id]));
			$Characters['CharacterBuff'][$id]=str_replace('ChaMHP','HP +1000',($Characters['CharacterBuff'][$id]));
			$Characters['CharacterBuff'][$id]=str_replace('ChaATK','ATK +3%',($Characters['CharacterBuff'][$id]));
			$Characters['CharacterBuff'][$id]=str_replace('ChaIncINT','INT +2',($Characters['CharacterBuff'][$id]));
			$Characters['CharacterBuff'][$id]=str_replace('ChaIncDEX','DEX +2',($Characters['CharacterBuff'][$id]));
			$Characters['CharacterBuff'][$id]=str_replace('ChaIncCON','HP +2',($Characters['CharacterBuff'][$id]));
			$Characters['CharacterBuff'][$id]=str_replace('ChaIncAGI','AGI +2',($Characters['CharacterBuff'][$id]));
			$Characters['CharacterBuff'][$id]=str_replace('ChaIncSTR','STR +2',($Characters['CharacterBuff'][$id]));
			$Characters['CharacterBuff'][$id]=str_replace('ChaIncCHA','SEN +2',($Characters['CharacterBuff'][$id]));			
			$Characters['CharacterBuff'][$id]=str_replace('ChaASPD','ASPD +3%',($Characters['CharacterBuff'][$id]));
			$Characters['CharacterBuff'][$id]=str_replace('ChaMSATK','ATK to monsters +5%',($Characters['CharacterBuff'][$id]));
			$Characters['CharacterBuff'][$id]=str_replace('ChaBLK','Block +3',($Characters['CharacterBuff'][$id]));
			$Characters['CharacterBuff'][$id]=str_replace('ChaHoldTime','Animation time -2%',($Characters['CharacterBuff'][$id]));
			$Characters['CharacterBuff'][$id]=str_replace('ChaCRTATK','Critical damage +5%',($Characters['CharacterBuff'][$id]));
			$Characters['CharacterBuff'][$id]=str_replace('ChaPCATK','ATK to PC +5%',($Characters['CharacterBuff'][$id]));
			$Characters['CharacterBuff'][$id]=str_replace('ChaEvasion','Skill Evasion +3%',($Characters['CharacterBuff'][$id]));
			$Characters['CharacterBuff'][$id]=str_replace('ChaPCDEF','Damage from PC -3%',($Characters['CharacterBuff'][$id]));
			$Characters['CharacterBuff'][$id]=str_replace('ChaMonDam','Damage from monsters -3%',($Characters['CharacterBuff'][$id]));
			$Characters['CharacterBuff'][$id]=str_replace('ChaREle','ALL RES +3',($Characters['CharacterBuff'][$id]));
			$Characters['CharacterBuff'][$id]=str_replace('ChaMSP','SP +300',($Characters['CharacterBuff'][$id]));
			$Characters['CharacterBuff'][$id]=str_replace('ChaPR','Evasion +3',($Characters['CharacterBuff'][$id]));
			$Characters['CharacterBuff'][$id]=str_replace('ChaRHP','HP Recovery +100',($Characters['CharacterBuff'][$id]));
			$Characters['CharacterBuff'][$id]=str_replace('ChaCRT','Critical +3',($Characters['CharacterBuff'][$id]));
			$Characters['CharacterBuff'][$id]=str_replace('ChaDrain','HP Absorbation +2%',($Characters['CharacterBuff'][$id]));
			$Characters['CharacterBuff'][$id]=str_replace('ChaHR','Accuracy +3',($Characters['CharacterBuff'][$id]));
			$Characters['CharacterBuff'][$id]=str_replace('ChaMSPD','MSPD +5%',($Characters['CharacterBuff'][$id]));
//Parsing expedition tags. Strict ',' delimiter considered. POSSIBLE leak later, if IMC decides to delimit stuff with spacebars, as it was with stances (', ')
$Etags=explode(',',$Characters['ExpeditionTag'][$id] ?? '');
$ArmorSets=explode(',',$Characters['EqpArmorSet'][$id] ?? '');
$CharactersTag1=$ExpTags['Name'][$Etags[0] ?? ''] ?? '';
$CharactersTag2=$ExpTags['Name'][$Etags[1] ?? ''] ?? '';
$CharactersTag3=$ExpTags['Name'][$Etags[2] ?? ''] ?? '';
$Armors="";
//This images below IS NOT created by prepare.bat. You`ll have to select, name and move them manually.
if(in_array('Robe',$ArmorSets)){$Armors=$Armors.'<img src="./images/Misc/Robe.jpg" alt="Robe armor">';};

if(in_array('Coat',$ArmorSets)){$Armors=$Armors.'<img src="./images/Misc/Coat.jpg" alt="Coat armor">';};

if(in_array('Leather',$ArmorSets))
{$Armors=$Armors.'<img src="./images/Misc/leather.jpg" alt="Leather armor">';};
if(in_array('Metal',$ArmorSets))
{$Armors=$Armors.'<img src="./images/Misc/Metal.jpg" alt="Metal armor">';};
//ToDo: fix C.Daria& Cano portraits with static links. IMC used different logic naming their portraits files.

$PortraitSuffix = $Characters['Gender'][$id]=="Male" ? '_m_barrack_on.bmp' : '_f_barrack_on.bmp';
$PortraitSourceName = $Characters['ClassName'][$id];
$PortraitSourceOverrides = array(
	'Kano' => 'kano',
	'Kano2' => 'navykano',
	'Kano2Rare' => 'chiefkano',
);
if (isset($PortraitSourceOverrides[$PortraitSourceName])) {
	$PortraitSourceName = $PortraitSourceOverrides[$PortraitSourceName];
}
$PortraitSource = GeDataPath("ui/BarrackPortrait/".$PortraitSourceName.$PortraitSuffix);
$PortraitDestinationName = $Characters['ClassName'][$id].$PortraitSuffix;
CopyToWebImage($PortraitSource, 'Barrack', $PortraitDestinationName);


if ($Characters['Gender'][$id]=="Male"){
	$Port = WebImageSrc('Barrack', $Characters['ClassName'][$id].'_m_barrack_on.bmp');
}else{
	$Port = WebImageSrc('Barrack', $Characters['ClassName'][$id].'_f_barrack_on.bmp');
}

$CharacterJobSkillId = $Characters['JobSkill'][$id] ?? '0';
if($CharacterJobSkillId !== '0' && isset($Skill['FileName'][$CharacterJobSkillId]))
{
CopyToWebImage(GeDataPath('ui/illust/'.$Skill['FileName'][$CharacterJobSkillId].'.bmp'), 'Skills', $Skill['FileName'][$CharacterJobSkillId].'.bmp');
}
if (!$CharacterIsActive) {
	continue;
}

$CharacterStats = array(
	'STR' => (int)$Characters['STR'][$id],
	'AGI' => (int)$Characters['AGI'][$id],
	'DEX' => (int)$Characters['DEX'][$id],
	'HP' => (int)$Characters['CON'][$id],
	'INT' => (int)$Characters['INT'][$id],
	'SEN' => (int)$Characters['CHA'][$id],
);
$CharacterMainStat = CharacterMainStat($CharacterStats);

//Preparing big strings for JS script` generation. Dirty way, would be cleaner to use JS objects and classes, but working
	ob_start();
	echo 'DrawStances();changepage('
	."'".$Characters['Name'][$id]."'"
	.",".$Characters['STR'][$id]
	.", ".$Characters['AGI'][$id]
	.', '.$Characters['CON'][$id]
	.', '.$Characters['DEX'][$id]
	.', '.$Characters['INT'][$id]
	.', '.$Characters['CHA'][$id]
	.",".
	"'".$Characters['JobDesc'][$id]."', "
	."'".'['.$CharactersTag1.']'.'['.$CharactersTag2.']'.'['.$CharactersTag3.']'."', "
	."'".$Characters['CharacterBuff'][$id].' *'.$Characters['CharacterBuffLv'][$id]."', "
	."'".$Port."', "
	."`".$Armors."`, "
	.$Characters['initLv'][$id].", "
	.'`'.$Skill['Name'][$Characters['JobSkill'][$id]]."`, "
	.'`'.$Skill['FileName'][$Characters['JobSkill'][$id]].'.bmp`, ';
	echo'`'.$Skill['TargetDesc'][$Characters['JobSkill'][$id]]."`, "
	.'`'.($Skill['CoolDown'][$Characters['JobSkill'][$id]]/1000).' sec.`, '
	.'`'.($Skill['CastTime'][$Characters['JobSkill'][$id]]/1000).' sec.` ,'
	.'`'.($Skill['HoldTime'][$Characters['JobSkill'][$id]]/1000).' sec.`, ';
	echo '`';
	if($Skill['SpendHP'][$Characters['JobSkill'][$id]]>0){echo $Skill['SpendHP'][$Characters['JobSkill'][$id]].' HP;';};
	if($Skill['SpendSP'][$Characters['JobSkill'][$id]]>0){echo $Skill['SpendSP'][$Characters['JobSkill'][$id]].' SP;';};
	if($Skill['SpendItemID'][$Characters['JobSkill'][$id]]>0){echo $Items['ItemName'][$Skill['SpendItemID'][$Characters['JobSkill'][$id]]].' x'.$Skill['SpendItemCount'][$Characters['JobSkill'][$id]].';';};
	echo '`, `';
	$JSKDSC="";
	if($Skill['SpecDesc1'][$Characters['JobSkill'][$id]]!=$Skill['SpecDesc10'][$Characters['JobSkill'][$id]]){
	$JSKDSC="<p>Lv.1</p><p id='JobSkillDesc1'>".$Skill['SpecDesc1'][$Characters['JobSkill'][$id]]."</p><br><p>Lv.10</p><p id='JobSkillDesc10'>".$Skill['SpecDesc10'][$Characters['JobSkill'][$id]].'</p>';
	} else {
		$JSKDSC="Lv.10</p><p id='JobSkillDesc10'>".$Skill['SpecDesc10'][$Characters['JobSkill'][$id]].'</p>';}
		if ($Skill['SpecDesc11'][$Characters['JobSkill'][$id]]!=$Skill['SpecDesc10'][$Characters['JobSkill'][$id]])
		{$JSKDSC .="<p>Lv.11</p><p id='JobSkillDesc11'>".$Skill['SpecDesc11'][$Characters['JobSkill'][$id]]."</p><p>Lv.12</p><p id='JobSkillDesc12'>".$Skill['SpecDesc12'][$Characters['JobSkill'][$id]].'</p>';};
	echo $JSKDSC.'`, dth()+';
	$Characters['EqpWeaponSet'][$id]=str_replace(" ","",$Characters['EqpWeaponSet'][$id]);
	$StanIDs=array();
	$CharacterWeaponSearchParts=array();
	$CharacterStanceSearchParts=array();
	foreach(explode(',',$Characters['EqpWeaponSet'][$id]) as $SCond){
		$RightHand = $StanceConds['RHand'][$SCond] ?? '';
		$LeftHand = $StanceConds['LHand'][$SCond] ?? '';
		AddCharacterSearchPart($CharacterWeaponSearchParts, $RightHand);
		AddCharacterSearchPart($CharacterWeaponSearchParts, $LeftHand);
		$StanceNames = '';
		for ($stanceNumber = 1; $stanceNumber <= 6; $stanceNumber++) {
			$stanceKey = 'Stance'.$stanceNumber;
			$StanceNames .= AddCharacterStance($StanceConds[$stanceKey][$SCond] ?? '', $StanIDs, $megastances, $CharacterStanceSearchParts);
		}
		echo 'dtrow(`'.$RightHand.'`,`'.$LeftHand.'`,`'.$StanceNames.'`)+';
	};
	echo '`</table>`,';
foreach ($StanIDs as $IDS){
	if (ValidStanceId($IDS)) {
		echo 'WriteStance'.$IDS.'()+';
	}
}
	echo "'');";
	$CharacterAction = RichColorMarkupToHtml(ob_get_clean());
	$CharacterSearchText = implode(' ', array_merge(array($Characters['Name'][$id], $Characters['ClassName'][$id], $Skill['Name'][$Characters['JobSkill'][$id]]), $CharacterWeaponSearchParts, $CharacterStanceSearchParts));
	$CharacterRoute = RegisterCharacterDetailPage($Characters['Name'][$id], $Characters['ClassName'][$id], $CharacterAction);
	$CharacterActiveAttr = $CharacterIsActive ? 'true' : 'false';
	$CharacterHiddenAttr = $CharacterIsActive ? '' : ' hidden';
	$CharCards .= '<a class="character-card" href="./'.Html($CharacterRoute).'" data-class-id="'.Html($id).'" data-class-name="'.Html($Characters['ClassName'][$id]).'" data-active="'.$CharacterActiveAttr.'" data-name="'.Html($Characters['Name'][$id]).'" data-search="'.Html($CharacterSearchText).'" data-main-stat="'.Html($CharacterMainStat['Name']).'" data-main-value="'.Html($CharacterMainStat['Value']).'" data-str="'.Html($CharacterStats['STR']).'" data-agi="'.Html($CharacterStats['AGI']).'" data-dex="'.Html($CharacterStats['DEX']).'" data-hp="'.Html($CharacterStats['HP']).'" data-int="'.Html($CharacterStats['INT']).'" data-sen="'.Html($CharacterStats['SEN']).'"'.$CharacterHiddenAttr.'><span class="character-card-image"><img src="'.Html($Port).'" alt="'.Html($Characters['Name'][$id]).'"></span><span class="character-card-name">'.Html($Characters['Name'][$id]).'</span><span class="character-card-stat" style="display:none"></span></a>';
	echo '<tr data-class-id="'.Html($id).'" data-class-name="'.Html($Characters['ClassName'][$id]).'" data-active="'.$CharacterActiveAttr.'" data-search="'.Html($CharacterSearchText).'"'.$CharacterHiddenAttr.'><td><a href="./'.Html($CharacterRoute).'">'.Html($Characters['Name'][$id]).'</a></td><td>'.$CharacterMainStat['Display'].'</td><td>'.$Characters['STR'][$id].'</td><td>'.$Characters['AGI'][$id].'</td><td>'.$Characters['DEX'][$id].'</td><td>'.$Characters['CON'][$id].'</td><td>'.$Characters['INT'][$id].'</td><td>'.$Characters['CHA'][$id].'</td><td>';
	
	
	foreach ($NPCList['ClassID'] as $CurrentNPC){
		if ($NPCList['ClassName'][$CurrentNPC]==$Characters['ClassName'][$id])
		{echo $NPCList['ScoutType'][$CurrentNPC];		};
									};
	
echo "</td></tr>\n";
};
};

$CharList = ob_get_clean();


ob_start();
$megastances=array_unique($megastances);


foreach ($megastances as $curstance){
if(!ValidStanceId($curstance)){
	continue;
}
echo 'function WriteStance'.$curstance.'(){DrawStanceHeader(`'.$Stances['Name'][$curstance].'`,`'
	.($Stances['StnATK'][$curstance]*100-100).'%`,`'
	.$Stances['StnHR'][$curstance].'(+'.($Stances['IncHR'][$curstance]*25).') on stance lv.25.`,`'
	.($Stances['SplLimit'][$curstance]+1).'`,`';
	
	if($Stances['SpendItemID'][$curstance]!='0'){
		
	echo $Items['ItemName'][$Stances['SpendItemID'][$curstance]].'x'.$Stances['SpendItemCount'][$curstance].'`,`';}
	else{
	echo 'None`,`';}
echo $Stances['ClassType'][$curstance].'`,`⚔'
.$Stances['StnIP'][$curstance].'+'.($Stances['IncIP'][$curstance]*25).'`,`';
if($Stances['SplLimit'][$curstance]!='0'){
echo $Stances['SplDam'][$curstance].' damage and  '.($Stances['SplRange'][$curstance]/100).'m distance`,`';}
else { echo 'None`,`';};
echo ($Stances['ASPD'][$curstance]/10).' On stance lv.25  +'.($Stances['IncASPD'][$curstance]*25).'%`,`'
.($Stances['IncCRT'][$curstance]*25).' on stance Lv.25`,`'
.$Stances['StnIgnoreDEFPer'][$curstance].'%`,`⛨'
.$Stances['StnIMP'][$curstance].'+'.($Stances['IncIMP'][$curstance]*25).'`,`🔥'
.$Stances['StnRFIRE'][$curstance].'/❄'
.$Stances['StnRICE'][$curstance].'/🗲'
.$Stances['StnRLGHT'][$curstance].'/❤'
.$Stances['StnRSTAT'][$curstance].'`,`'
.$Stances['MeleeDef'][$curstance].'⚔'
.$Stances['ShootDef'][$curstance].'🔫'
.$Stances['MagicDef'][$curstance].'❇'
.$Stances['DebuffDef'][$curstance].'❤`,`'
.$Stances['StnPR'][$curstance].'+'.($Stances['IncPR'][$curstance]*25).'`,`'
.$Stances['StnBLK'][$curstance].'+'.($Stances['IncBLK'][$curstance]*25).'`,`'
.$Stances['DefLayer'][$curstance].'`,`'
.($Stances['WSPD'][$curstance]/100).'`,`'
.$Stances['RSPD'][$curstance].'+'.($Stances['IncRSP'][$curstance]*25).'`,`'
.($Stances['RHits'][$curstance]+$Stances['LHits'][$curstance]).'`);DrawTableSkills('.
'WriteSkill'.$Stances['SkillID1'][$curstance].'(),'.
'WriteSkill'.$Stances['SkillID2'][$curstance].'(),'.
'WriteSkill'.$Stances['SkillID3'][$curstance].'(),'.
'WriteSkill'.$Stances['SkillID4'][$curstance].'(),'.
'WriteSkill'.$Stances['SkillID5'][$curstance].'()'.
')}
';}
global $megaskills;
$megaskills=array();	
$StanceList=ob_get_clean();

ob_start();
foreach ($Stances['ClassID'] as $SCID)
{
	if(!in_array($Stances['SkillID1'][$SCID],$megaskills))
		{
			array_push($megaskills,$Stances['SkillID1'][$SCID]);
		}
	if(!in_array($Stances['SkillID2'][$SCID],$megaskills))
		{
			array_push($megaskills,$Stances['SkillID2'][$SCID]);
		}	
	if(!in_array($Stances['SkillID3'][$SCID],$megaskills))
		{
			array_push($megaskills,$Stances['SkillID3'][$SCID]);
		}
	if(!in_array($Stances['SkillID4'][$SCID],$megaskills))
		{
			array_push($megaskills,$Stances['SkillID4'][$SCID]);
		}
	if(!in_array($Stances['SkillID5'][$SCID],$megaskills))
		{
			array_push($megaskills,$Stances['SkillID5'][$SCID]);
		}
};
$megaskills=array_unique($megaskills);
foreach ($megaskills as $CurrSkill)
{if($CurrSkill!="0"){
CopyToWebImage(GeDataPath('ui/illust/'.$Skill['FileName'][$CurrSkill].'.bmp'), 'Skills', $Skill['FileName'][$CurrSkill].'.bmp');
	$SkillSpecs = 'Lv.1: ';
	if($Skill['SklATK'][$CurrSkill]>0){$SkillSpecs .= 'ATK: '.($Skill['SklATK'][$CurrSkill]*100).'%';}
	$SkillSpecs .= '<br>'.$Skill['SpecDesc1'][$CurrSkill];
	if($Skill['SpecDesc10'][$CurrSkill]!=$Skill['SpecDesc1'][$CurrSkill])
	{
		$SkillSpecs .= '<br>Lv.10:';
		if($Skill['SklATK'][$CurrSkill]>0){$SkillSpecs .= 'ATK: '.(integer)($Skill['SklATK'][$CurrSkill]*200).'%';}
		$SkillSpecs .= $Skill['SpecDesc10'][$CurrSkill];
	}
	if($Skill['SpecDesc11'][$CurrSkill]!=$Skill['SpecDesc10'][$CurrSkill])
	{
		$SkillSpecs .= '<br>Lv.11:'.$Skill['SpecDesc11'][$CurrSkill];
		if($Skill['SklATK'][$CurrSkill]>0){$SkillSpecs .= 'ATK: '.(integer)($Skill['SklATK'][$CurrSkill]*220).'%';}
	}
	if($Skill['SpecDesc12'][$CurrSkill]!=$Skill['SpecDesc11'][$CurrSkill])
	{
		$SkillSpecs .= '<br>Lv.12:'.$Skill['SpecDesc12'][$CurrSkill];
		if($Skill['SklATK'][$CurrSkill]>0){$SkillSpecs .= 'ATK: '.(integer)($Skill['SklATK'][$CurrSkill]*240).'%';}
	}
	$SkillDebuffs = '';
	if($Skill['BuffID'][$CurrSkill]>0){
		$SkillDebuffs = $Buffs['ToolTip'][$Skill['BuffID'][$CurrSkill]] ?? '';
	}else{
		if($Skill['Buff'][$CurrSkill]!="None")
		{$SkillDebuffs = $Buffs['ToolTip'][$Skill['Buff'][$CurrSkill]] ?? '';}	
	};	
	$SkillCost = '';
	if($Skill['SpendHP'][$CurrSkill]>0){$SkillCost .= $Skill['SpendHP'][$CurrSkill].' HP;';};
	if($Skill['SpendSP'][$CurrSkill]>0){$SkillCost .= $Skill['SpendSP'][$CurrSkill].' SP;';};
	if($Skill['SpendItemID'][$CurrSkill]>0){$SkillCost .= ItemDisplayName($Skill['SpendItemID'][$CurrSkill]).' x'.$Skill['SpendItemCount'][$CurrSkill].';';};
	echo 'function WriteSkill'.$CurrSkill.'(){ return DrawSkill('
		.JsArg($Skill['Name'][$CurrSkill]).','
		.JsArg($Skill['FileName'][$CurrSkill]).','
		.JsArg('PvP:'.$Skill['PvPFix'][$CurrSkill].'/Soft:'.$Skill['SklSoft'][$CurrSkill].'/Medium:'.$Skill['SklMedium'][$CurrSkill].'/Metal:'.$Skill['SklMetal'][$CurrSkill]).','
		.($Skill['HoldTime'][$CurrSkill]/1000).','
		.($Skill['CastTime'][$CurrSkill]/1000).','
		.($Skill['CoolDown'][$CurrSkill]/1000).','
		.$Skill['HateAmnt'][$CurrSkill].','
		.JsArg($Skill['Desc'][$CurrSkill]).','
		.JsArg($SkillSpecs).','
		.JsArg($Skill['TargetDesc'][$CurrSkill]).','
		.JsArg($SkillDebuffs).','
		.JsArg($SkillCost)
		.');
	};
	';

};
};
$SkillList=ob_get_clean();
//END OF SKILL AND STANCE PARSING

//ACHIEVMENT PARSING
foreach($Achieve['ClassID'] as $AchID){
	$Reward=explode(', ',$Achieve['RewardItem'][$AchID]);
	
	$NArrayName='Achievements'.$Achieve['Type'][$AchID];
	
global $$NArrayName;
$$NArrayName=array();}
foreach($Achieve['ClassID'] as $AchID){
	$Reward=explode(', ',$Achieve['RewardItem'][$AchID]);
	$NArrayName='Achievements'.$Achieve['Type'][$AchID];

	array_push(${$NArrayName},'<tr id="Achievement_'.HtmlIdSuffix($AchID).'">
	<td>'.$Achieve['Title'][$AchID].'</td>
	<td>'.$Achieve['Desc'][$AchID].'</td>
	<td>'.$Achieve['AccomplishPoint'][$AchID].'</td>
	<td>'.$Achieve['AccomplishName'][$AchID].' ('.$Achieve['Stage'][$AchID].')</td>');
	if($Reward[0]!='0' and $Reward[1]!='0'){
	array_push(${$NArrayName},'
	<td>'.Html(ItemDisplayName($Reward[0])).' x '.Html($Reward[1]).'</td>
	</tr>');}
	else {array_push(${$NArrayName},'<td>No Reward Items!</td></tr>');}
}
$Achievmts="";
$Atypes=array();
$Achsides='<ul class="AchListUl">';
foreach ($Achieve['ClassID'] as $Atype){
	if(!in_array($Achieve['Type'][$Atype],$Atypes)){
	$ArType=$Achieve['Type'][$Atype];
	$Achsides=$Achsides.'<li class="AchListLi"><a href="#" onclick="ToggleContent(`MainAchievments`, `Achieves'.$ArType.'`);ScrollToContentTop();closeNav();return false;">'.$ArType.'</a></li>';
	$NArrayName='Achievements'.$ArType;
	$Achievmts=$Achievmts.'<table id="Achieves'.$ArType.'"><thead><tr><th>Title</th><th>Description</th><th>Points</th><th>Chain (Number in chain)</th><th>Reward</th></tr></thead><tbody>';
	foreach($$NArrayName as $Arow){
	$Achievmts=$Achievmts.$Arow;}
		$Achievmts=$Achievmts.'</tbody></table>';
array_push($Atypes,$ArType);
} ;

};
$Achsides=$Achsides.'</ul>';
//END OF ACHIEVEMENT PARSING
//START PARSING ITEMS (V 0.1 - ITEMS ONLY);
//Parsing recipes
foreach ($Recipe['ClassID'] as $CurrentRecipe){
if($Recipe['Stuff1Num'][$CurrentRecipe]!="0"){
ob_start();

$rowClass = 'dark';
echo ('<table><tbody>');
for($i=1;$i<8;$i++){
$RecipeStuffId = $Recipe['Stuff'.$i][$CurrentRecipe] ?? '0';
if($RecipeStuffId!="0"){	
$StuffId = 1*$RecipeStuffId;
$StuffFile = $Items['FileName'][$StuffId] ?? '';
$StuffName = ItemDisplayName($StuffId);
if($StuffFile !== '' && $StuffFile !== 'None')
{CopyToWebImage(GeDataPath('ui/illust/'.$StuffFile.'.bmp'), 'Items', $StuffFile.'.bmp');};
if($RecipeStuffId!="0") {
	echo('<tr class="'.$rowClass.'"><td>'.ImageTag('Items', $StuffFile, $StuffName, 'class="recipe-icon"').Html($StuffName).' x'.Html($Recipe['Stuff'.$i.'Num'][$CurrentRecipe] ?? '').'</td></tr>');
	$rowClass = $rowClass === 'dark' ? 'dark2' : 'dark';
}
};};
//ToDo: fix non-0 amount of "0" ID materials. U WOT,IMC? Gimme a 10 of nothing.
echo ('</tbody></table>');
$Recipe[$Recipe['Target'][$CurrentRecipe]]=ob_get_clean();
}}
//End of Recipes parsing

//Parsing options (enchants);

$OptionList = array();
$EnchantGroups = array();
$RenderedItemAnchors = array();

function FormatEnchantOptionText($description, $minValue, $maxValue, $rarity) {
	$description = trim(preg_replace('/\s+/', ' ', (string)$description));
	if ((string)$rarity !== '0') {
		return $description.'  '.$minValue.' - '.$maxValue.' (1:'.$rarity.' )  <br>';
	}
	$description = preg_replace('/(?:\s+by|\s*\+)\s*$/i', '', $description);
	return $description.'<br>';
}

foreach($Options['ClassID'] as $CurrentOption){
$Options['Desc'][$CurrentOption]=str_replace("на %s%%","",$Options['Desc'][$CurrentOption]);
$Options['Desc'][$CurrentOption]=str_replace("%s%%","",$Options['Desc'][$CurrentOption]);
$Options['Desc'][$CurrentOption]=str_replace("+%s","",$Options['Desc'][$CurrentOption]);
$Options['Desc'][$CurrentOption]=str_replace("%s","",$Options['Desc'][$CurrentOption]);
$Options['Desc'][$CurrentOption]=str_replace("%%","",$Options['Desc'][$CurrentOption]);	
ob_start();
$ReqGroupName = $Options['ReqGroupName'][$CurrentOption];
if ($Options['Rarity'][$CurrentOption]!='0' || preg_match('/^RING/i', $ReqGroupName)) echo FormatEnchantOptionText($Options['Desc'][$CurrentOption], $Options['MinValue'][$CurrentOption], $Options['MaxValue'][$CurrentOption], $Options['Rarity'][$CurrentOption]);
if(!isset($OptionList[$ReqGroupName])){$OptionList[$ReqGroupName]='';}
$OptionList[$ReqGroupName]=$OptionList[$ReqGroupName].ob_get_clean();
}
//End of parsing options
//Start pasion item stats& Combining item files:




function ItemCategoryParsing($Category){
    global ${$Category};
	
global $OptionList;
global $EnchantGroups;
global $Recipe;

    $ArrayNames=${$Category}['ClassID'];

    foreach($ArrayNames as $CurrentIt){
	
	ob_start();
	if(${$Category}['ItemName'][$CurrentIt]!='None'){
$ItemCategory1 = ${$Category}['Category1'][$CurrentIt] ?? $Category;
$ItemCategory2 = ${$Category}['Category2Name'][$CurrentIt] ?? $ItemCategory1;
$ItemAnchor = ItemAnchorId($Category, $CurrentIt);
$ItemDescriptionParts = SplitItemDescriptionOptions(${$Category}['Desc'][$CurrentIt]);
CopyToWebImage(GeDataPath('ui/illust/'.${$Category}['FileName'][$CurrentIt].'.bmp'), 'Items', ${$Category}['FileName'][$CurrentIt].'.bmp');
$ItemImage = ImageTag('Items', ${$Category}['FileName'][$CurrentIt], ${$Category}['ItemName'][$CurrentIt]);
ob_start();
if (isset(${$Category}['MaxSocketCount'][$CurrentIt]) && ${$Category}['MaxSocketCount'][$CurrentIt]!='0') echo 'Max Sockets: '.${$Category}['MaxSocketCount'][$CurrentIt].'<br>';
echo 'Rating: '.${$Category}['WLv'][$CurrentIt].'<br>';
if (isset(${$Category}['AR'][$CurrentIt])) {if(${$Category}['AR'][$CurrentIt]!='0') echo 'AR (in-built): '.${$Category}['AR'][$CurrentIt].'<br>';}
if (isset(${$Category}['ShldDR'][$CurrentIt])){if(${$Category}['ShldDR'][$CurrentIt]!='0') echo 'DR (in-built):'.${$Category}['ShldDR'][$CurrentIt].'<br>';}
if (isset(${$Category}['ASPD'][$CurrentIt])){if(${$Category}['ASPD'][$CurrentIt]!='0') echo 'ASPD:  '.${$Category}['ASPD'][$CurrentIt].'<br>';}
if (isset(${$Category}['ATK'][$CurrentIt])){if(${$Category}['ATK'][$CurrentIt]!='0') echo 'ATK: '.${$Category}['ATK'][$CurrentIt].'<br>';}
if (isset(${$Category}['BLK'][$CurrentIt])){if(${$Category}['BLK'][$CurrentIt]!='0') echo 'BLC: '.${$Category}['BLK'][$CurrentIt].'<br>';}
if (isset(${$Category}['CRT'][$CurrentIt])){if(${$Category}['CRT'][$CurrentIt]!='0') echo 'CRT: '.${$Category}['CRT'][$CurrentIt].'<br>';}
if (isset(${$Category}['DEF'][$CurrentIt])){if(${$Category}['DEF'][$CurrentIt]!='0') echo 'DEF: '.${$Category}['DEF'][$CurrentIt].'<br>';}
if (isset(${$Category}['FireATK'][$CurrentIt])){if(${$Category}['FireATK'][$CurrentIt]!='0') echo 'Fire ATK: '.${$Category}['FireATK'][$CurrentIt].'<br>';}
if (isset(${$Category}['FireIP'][$CurrentIt])){if(${$Category}['FireIP'][$CurrentIt]!='0') echo 'Fire PEN: '.${$Category}['FireIP'][$CurrentIt].'<br>';}
if (isset(${$Category}['IceATK'][$CurrentIt])){if(${$Category}['IceATK'][$CurrentIt]!='0') echo 'Ice ATK: '.${$Category}['IceATK'][$CurrentIt].'<br>';}
if (isset(${$Category}['IceIP'][$CurrentIt])){if(${$Category}['IceIP'][$CurrentIt]!='0') echo 'Ice PEN: '.${$Category}['IceIP'][$CurrentIt].'<br>';}
if (isset(${$Category}['LghtATK'][$CurrentIt])){if(${$Category}['LghtATK'][$CurrentIt]!='0') echo 'Light.ATK: '.${$Category}['LghtATK'][$CurrentIt].'<br>';}
if (isset(${$Category}['LgtIP'][$CurrentIt])){if(${$Category}['LgtIP'][$CurrentIt]!='0') echo 'Light.PEN: '.${$Category}['LgtIP'][$CurrentIt].'<br>';}
if (isset(${$Category}['PsyATK'][$CurrentIt])){if(${$Category}['PsyATK'][$CurrentIt]!='0') echo 'Mental. ATK: '.${$Category}['PsyATK'][$CurrentIt].'<br>';}
if (isset(${$Category}['PsyIP'][$CurrentIt])){if(${$Category}['PsyIP'][$CurrentIt]!='0') echo 'Mental PEN: '.${$Category}['PsyIP'][$CurrentIt].'<br>';}
if (isset(${$Category}['DefIP'][$CurrentIt])){if(${$Category}['DefIP'][$CurrentIt]!='0') echo 'Phys.PEN: '.${$Category}['DefIP'][$CurrentIt].'<br>';}
if (isset(${$Category}['IMP'][$CurrentIt])){if(${$Category}['IMP'][$CurrentIt]!='0') echo 'IMM: '.${$Category}['IMP'][$CurrentIt].'<br>';}
if (isset(${$Category}['SoftBane'][$CurrentIt])){if(${$Category}['SoftBane'][$CurrentIt]!='0') echo 'Soft Armor: '.${$Category}['SoftBane'][$CurrentIt].'<br>';}
if (isset(${$Category}['MediumBane'][$CurrentIt])){if(${$Category}['MediumBane'][$CurrentIt]!='0') echo 'Light Armor: '.${$Category}['MediumBane'][$CurrentIt].'<br>';}
if (isset(${$Category}['MetalBane'][$CurrentIt])){if(${$Category}['MetalBane'][$CurrentIt]!='0') echo 'Heavy Armor: '.${$Category}['MetalBane'][$CurrentIt].'<br>';}
if (isset(${$Category}['IncMHP'][$CurrentIt])){if(${$Category}['IncMHP'][$CurrentIt]!='0') echo 'Max.HP: '.${$Category}['IncMHP'][$CurrentIt].'<br>';}
if (isset(${$Category}['IncMSP'][$CurrentIt])){if(${$Category}['IncMSP'][$CurrentIt]!='0') echo 'Max.MP: '.${$Category}['IncMSP'][$CurrentIt].'<br>';}
if (isset(${$Category}['IncHeal'][$CurrentIt])){if(${$Category}['IncHeal'][$CurrentIt]!='0') echo 'Healing +'.${$Category}['IncHeal'][$CurrentIt].'<br>';}
if (isset(${$Category}['RFIRE'][$CurrentIt])){if(${$Category}['RFIRE'][$CurrentIt]!='0') echo 'Fire RES: '.${$Category}['RFIRE'][$CurrentIt].'<br>';}
if (isset(${$Category}['RICE'][$CurrentIt])){if(${$Category}['RICE'][$CurrentIt]!='0') echo 'Ice RES: '.${$Category}['RICE'][$CurrentIt].'<br>';}
if (isset(${$Category}['RLGHT'][$CurrentIt])){if(${$Category}['RLGHT'][$CurrentIt]!='0') echo 'Light.RES: '.${$Category}['RLGHT'][$CurrentIt].'<br>';}
if (isset(${$Category}['RPSY'][$CurrentIt])){if(${$Category}['RPSY'][$CurrentIt]!='0') echo 'Mental RES: '.${$Category}['RPSY'][$CurrentIt].'<br>';}
if (isset(${$Category}['RSTAT'][$CurrentIt])){if(${$Category}['RSTAT'][$CurrentIt]!='0') echo 'Debuff RES: '.${$Category}['RSTAT'][$CurrentIt].'<br>';}
if (isset(${$Category}['HPDrain'][$CurrentIt])){if(${$Category}['HPDrain'][$CurrentIt]!='0') echo 'Vamp: '.${$Category}['HPDrain'][$CurrentIt].'<br>';}
if (isset(${$Category}['HR'][$CurrentIt])){if(${$Category}['HR'][$CurrentIt]!='0') echo 'ACC: '.${$Category}['HR'][$CurrentIt].'<br>';}
if ($Category === 'Accs' && $ItemCategory2 === 'Ring' && isset(${$Category}['Spec'][$CurrentIt]) && ${$Category}['Spec'][$CurrentIt] !== 'None') echo Html(${$Category}['Spec'][$CurrentIt]).'<br>';
if (isset(${$Category}['IncAGI'][$CurrentIt])){if(${$Category}['IncAGI'][$CurrentIt]!='0') echo 'AGI +'.${$Category}['IncAGI'][$CurrentIt].'<br>';}
if (isset(${$Category}['IncSTR'][$CurrentIt])){if(${$Category}['IncSTR'][$CurrentIt]!='0') echo 'STR +'.${$Category}['IncSTR'][$CurrentIt].'<br>';}
if (isset(${$Category}['IncDEX'][$CurrentIt])){if(${$Category}['IncDEX'][$CurrentIt]!='0') echo 'DEX +'.${$Category}['IncDEX'][$CurrentIt].'<br>';}
if (isset(${$Category}['IncCON'][$CurrentIt])){if(${$Category}['IncCON'][$CurrentIt]!='0') echo 'HP +'.${$Category}['IncCON'][$CurrentIt].'<br>';}
if (isset(${$Category}['IncCHA'][$CurrentIt])){if(${$Category}['IncCHA'][$CurrentIt]!='0') echo 'SEN +'.${$Category}['IncCHA'][$CurrentIt].'<br>';}
if (isset(${$Category}['IncINT'][$CurrentIt])){if(${$Category}['IncINT'][$CurrentIt]!='0') echo 'INT +'.${$Category}['IncINT'][$CurrentIt].'<br>';}
if (isset(${$Category}['GolemBane'][$CurrentIt])){if(${$Category}['GolemBane'][$CurrentIt]!='0') echo 'LifeLess ATK: '.${$Category}['GolemBane'][$CurrentIt].'<br>';}
if (isset(${$Category}['UndeadBane'][$CurrentIt])){if(${$Category}['UndeadBane'][$CurrentIt]!='0') echo 'UnDead ATK: '.${$Category}['UndeadBane'][$CurrentIt].'<br>';}
if (isset(${$Category}['BeastBane'][$CurrentIt])){if(${$Category}['BeastBane'][$CurrentIt]!='0') echo 'WildLife ATK: '.${$Category}['BeastBane'][$CurrentIt].'<br>';}
if (isset(${$Category}['HumanBane'][$CurrentIt])){if(${$Category}['HumanBane'][$CurrentIt]!='0') echo 'Human ATK: '.${$Category}['HumanBane'][$CurrentIt].'<br>';}
if (isset(${$Category}['DemonBane'][$CurrentIt])){if(${$Category}['DemonBane'][$CurrentIt]!='0') echo 'Demon ATK: '.${$Category}['DemonBane'][$CurrentIt].'<br>';}
$AttributeText = ob_get_clean();

$OptionGroup = ${$Category}['OptionGroup'][$CurrentIt] ?? 'None';
$EnchantLv = ${$Category}['EnchantLv'][$CurrentIt] ?? 0;
$OptionText = $ItemDescriptionParts['Options'];
$EnchantText = '';
if ($OptionGroup !="None")
{
if (in_array($Category, array('Weapon', 'Armor', 'Accs'), true)) {
	$EnchantGroupId = RegisterEnchantGroup($Category, $CurrentIt);
	$ChipName = EnchantChipName($EnchantLv);
} else {
	$EnchantGroupId = '';
	$ChipName = EnchantChipName($EnchantLv);
}
if ($EnchantGroupId !== '') {
	$EnchantText = EnchantChipLink($EnchantGroupId, $ChipName);
} else {
	$EnchantText = Html($ChipName);
}
}

echo '<article id="'.$ItemAnchor.'" class="item-card">';
echo '<div class="item-card-top"><div class="item-card-image">'.$ItemImage.'</div><div class="item-card-summary">';
echo '<h3 class="item-card-name">'.Html(${$Category}['ItemName'][$CurrentIt]).'</h3>';
echo '<p class="item-card-equip">'.ItemRichText(${$Category}['ReqToolTip'][$CurrentIt]).'</p>';
echo '<p class="item-card-description">'.ItemRichText($ItemDescriptionParts['Description']).'</p>';
echo '</div></div>';
echo '<div class="item-card-body">';
echo '<section class="item-card-column"><h4>Attributes</h4><div class="item-card-text">'.ItemRichText($AttributeText).'</div></section>';
echo '<section class="item-card-column"><h4>Options</h4><div class="item-card-text">'.ItemRichText($OptionText).'</div>';
if ($EnchantText !== '') {
	echo '<div class="item-card-enchant"><h4>Enchant Chip</h4>'.$EnchantText.'</div>';
}
echo '</section></div>';
if(isset($Recipe[$CurrentIt]))
{
echo '<section class="item-card-recipe"><h4>Recipe</h4>'.$Recipe[$CurrentIt].'</section>';
}
echo '</article>';

$RenderedItem = ob_get_clean();
RegisterRenderedItemAnchor($Category, $ItemAnchor);
if (in_array($Category, array('Weapon', 'Armor', 'Accs'), true)) {
	$LevelGroup = ItemEquipLevelGroup(${$Category}['ReqToolTip'][$CurrentIt] ?? '');
	if(!isset($ItemStrings[$ItemCategory1][$ItemCategory2][$LevelGroup['Key']])){
		$ItemStrings[$ItemCategory1][$ItemCategory2][$LevelGroup['Key']] = array(
			'Label' => $LevelGroup['Label'],
			'Sort' => $LevelGroup['Sort'],
			'Items' => '',
		);
	}
	$ItemStrings[$ItemCategory1][$ItemCategory2][$LevelGroup['Key']]['Items'] .= $RenderedItem;
} else {
	if(!isset($ItemStrings[$ItemCategory1][$ItemCategory2])){$ItemStrings[$ItemCategory1][$ItemCategory2]='';}
	$ItemStrings[$ItemCategory1][$ItemCategory2]=$ItemStrings[$ItemCategory1][$ItemCategory2].$RenderedItem;
}

}}
//END OF ITEMS PARSING

global ${$Category.'CategoryList'};
global ${$Category.'List'};
${$Category.'CategoryList'}='<ul>';
${$Category.'List'}='<div id="'.$Category.'">';
Foreach ($ItemStrings as ${$Category.'Category1'} => $valuearray){
	
foreach ($valuearray as $CategoryName2 =>$Endvalue)
	{${$Category.'CategoryList'}=${$Category.'CategoryList'}.'<li class="WeapListLi"><a href="./'.Html(ItemTypeRoute($Category, $CategoryName2)).'">'.Html($CategoryName2).'</a></li>';
		if (in_array($Category, array('Weapon', 'Armor', 'Accs'), true)) {
			ksort($Endvalue, SORT_NATURAL);
			$LevelBlocks = ItemTypeLabel($CategoryName2);
			foreach ($Endvalue as $LevelBlock) {
				$LevelBlocks .= ItemLevelBlock($Category, $CategoryName2, $LevelBlock['Label'], $LevelBlock['Items']);
			}
			$TypePanelHtml = '<div id="'.$Category.$CategoryName2.'" class="item-category-panel">'.$LevelBlocks.'</div>';
		} else {
			$TypePanelHtml = '<div id="'.$Category.$CategoryName2.'">'.$Endvalue.'</div>';
		}
		${$Category.'List'}=${$Category.'List'}.$TypePanelHtml;
		RegisterItemTypePage($Category, $CategoryName2, $TypePanelHtml);
	}
	//ToDo: explore& fix the bug where applying Medal parsing also corrupted <div></div> pairs, messing the category structures.
	if($Category!='Accs'){
${$Category.'List'}=${$Category.'List'}.'</div>';
}}
${$Category.'CategoryList'}=${$Category.'CategoryList'}.'</ul>';
global $filecontent;
    $filecontent=str_replace("[[".$Category."_List]]",${$Category.'List'},$filecontent);
    $filecontent=str_replace("[[".$Category."_CategoryList]]",${$Category.'CategoryList'},$filecontent);
	$CategoryDisplayName = $Category === 'Medalkis' ? 'Medals' : $Category;
	$filecontent=str_replace("[[".$Category."_Header]]","<span style=\"font-size:30px;cursor:pointer\" onclick=\"setSidePanel('".$Category."tempSide','docked');ToggleContent('MainContainer','".$Category."');ScrollToContentTop();\">".$CategoryDisplayName."</span>",$filecontent);


};
if(!ShouldParseCategory("Parse weapon pages? N for 'No', anything else for 'Yes'")){
	echo 'Skipping Weapon pages';
	$filecontent=str_replace("[[Weapon_Header]]",'',$filecontent);
	    $filecontent=str_replace("[[Weapon_List]]",'',$filecontent);
    $filecontent=str_replace("[[Weapon_CategoryList]]",'',$filecontent);
	}else{
	ItemCategoryParsing("Weapon");
	}
if(!ShouldParseCategory("Parse Armor pages? N for 'No', anything else for 'Yes'")){
	echo 'Skipping Armor pages
	';
	$filecontent=str_replace("[[Armor_Header]]",'',$filecontent);
	$filecontent=str_replace("[[Armor_List]]",'',$filecontent);
	$filecontent=str_replace("[[Armor_CategoryList]]",'',$filecontent);
	}else{
	ItemCategoryParsing("Armor");}
if(!ShouldParseCategory("Parse accessoires and medals pages? N for 'No', anything else for 'Yes'")){
	echo 'Skipping A&M pages';
	$filecontent=str_replace("[[Accs_Header]]",'',$filecontent);
	$filecontent=str_replace("[[Medalkis_Header]]",'',$filecontent);
	$filecontent=str_replace("[[Accs_List]]",'',$filecontent);
    $filecontent=str_replace("[[Accs_CategoryList]]",'',$filecontent);
	$filecontent=str_replace("[[Medalkis_List]]",'',$filecontent);
    $filecontent=str_replace("[[Medalkis_CategoryList]]",'',$filecontent);
	}else{
	
ItemCategoryParsing("Accs");
ItemCategoryParsing("Medalkis");
	};

$EnchantPage = BuildEnchantChipPage();
$filecontent=str_replace("[[Enchant_Header]]",$EnchantPage['Header'],$filecontent);
$filecontent=str_replace("[[Enchant_SideList]]",$EnchantPage['SideList'],$filecontent);
$filecontent=str_replace("[[Enchant_List]]",$EnchantPage['List'],$filecontent);

$MapsPage = BuildMapsPage();
$filecontent=str_replace("[[Maps_Header]]",$MapsPage['Header'],$filecontent);
$filecontent=str_replace("[[Maps_SideList]]",$MapsPage['SideList'],$filecontent);
$filecontent=str_replace("[[Maps_List]]",$MapsPage['List'],$filecontent);

$MonsterPage = BuildMonsterPage();
$filecontent=str_replace("[[Monsters_Header]]",$MonsterPage['Header'],$filecontent);
$filecontent=str_replace("[[Monsters_SideList]]",$MonsterPage['SideList'],$filecontent);
$filecontent=str_replace("[[Monsters_List]]",$MonsterPage['List'],$filecontent);
	
$filecontent=str_replace("[[Character_Cards]]",$CharCards,$filecontent);
$filecontent=str_replace("[[Character_List]]",$CharList,$filecontent);
if(!ShouldParseCategory("Parse Achievement pages? N for 'No', anything else for 'Yes'")){
	echo 'Skipping Ach pages';
	$filecontent=str_replace("[[Ach_List]]",'',$filecontent);
    $filecontent=str_replace("[[Ach_SideList]]",'',$filecontent);
	$filecontent=str_replace("[[Ach_Header]]",'',$filecontent);
	}else{
	$filecontent=str_replace("[[Ach_Header]]","<span style=\"font-size:30px;cursor:pointer\" onclick=\"setSidePanel('AchievmenttempSide','docked');ToggleContent('MainContainer','MainAchievments');ToggleContent('MainAchievments','AchievesGeneral');ScrollToContentTop();\">Achievements</span>",$filecontent);
$filecontent=str_replace("[[Ach_List]]",$Achievmts,$filecontent);
$filecontent=str_replace("[[Ach_SideList]]",$Achsides,$filecontent);
	};
$filecontent=str_replace("[[Stance_List]]",$StanceList,$filecontent);
$filecontent=str_replace("[[Skill_List]]",$SkillList,$filecontent);
$filecontent=str_replace("[[Last_Modified]]",date('Y-m-d H:i:s T'),$filecontent);
$filecontent=RemoveUnresolvedDictionaryRefs($filecontent);
$filecontent=str_replace('\n','<br>',$filecontent);
$filecontent=str_replace('{br}','<br>',$filecontent);
$filecontent=RichColorMarkupToHtml($filecontent);
$filecontent=ExternalizePageAssets($filecontent);
WriteWebsitePages($filecontent);
WriteCharacterDetailPages($filecontent);
WriteItemTypePages($filecontent);
FinalizeGeneratedWebManifest();
echo 'Write summary: '.$GeneratedWriteStats['written'].' written, '.$GeneratedWriteStats['unchanged'].' unchanged, '.$GeneratedWriteStats['removed'].' stale removed, '.$GeneratedWriteStats['failed'].' failed.'."\n";
echo 'Done!';
echo "\n"; 
?>
