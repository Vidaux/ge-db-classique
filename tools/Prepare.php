<?php
chdir(__DIR__);

function createPath($path) {
	if (!is_dir($path)) {
		mkdir($path, 0777, true);
	}
	return is_dir($path);
}

function pathJoin() {
	$parts = array_filter(func_get_args(), function ($part) {
		return $part !== null && $part !== '';
	});
	return preg_replace('#[\\\\/]+#', DIRECTORY_SEPARATOR, join(DIRECTORY_SEPARATOR, $parts));
}

function runInDirectory($directory, $command) {
	$currentDirectory = getcwd();
	chdir($directory);
	$output = shell_exec($command);
	chdir($currentDirectory);
	return $output;
}

function echoOutput($output) {
	echo "<pre>$output</pre>";
}

$toolsDir = __DIR__;
$projectRoot = dirname($toolsDir);
$geDir = pathJoin($projectRoot, 'ge');

$izExe = pathJoin($toolsDir, 'iz.exe');
$ezExe = pathJoin($toolsDir, 'ez.exe');
$ix3Exe = pathJoin($toolsDir, 'ix3.exe');

createPath($geDir);
createPath(pathJoin($geDir, 'Images', 'Barrack'));
createPath(pathJoin($geDir, 'Images', 'Skills'));
createPath(pathJoin($geDir, 'xml'));

$ipfDir = $geDir;
if (!file_exists(pathJoin($ipfDir, 'dictionary.ipf')) && file_exists(pathJoin($toolsDir, 'dictionary.ipf'))) {
	$ipfDir = $toolsDir;
}

foreach (array('dictionary.ipf', 'ui.ipf', 'ies.ipf') as $ipfName) {
	$ipfPath = pathJoin($ipfDir, $ipfName);
	if (!file_exists($ipfPath)) {
		echoOutput("ipf not found: $ipfPath");
		continue;
	}

	$output = runInDirectory($geDir, escapeshellarg($izExe).' '.escapeshellarg($ipfPath));
	echoOutput($output);
}

foreach (array('ies.zip', 'ui.zip', 'dictionary.zip') as $zipName) {
	$zipSource = pathJoin($ipfDir, $zipName);
	$zipTarget = pathJoin($geDir, $zipName);

	if (!file_exists($zipSource) && file_exists(pathJoin($toolsDir, $zipName))) {
		$zipSource = pathJoin($toolsDir, $zipName);
	}

	if (!file_exists($zipSource)) {
		echoOutput("zip not found: $zipSource");
		continue;
	}

	if (realpath(dirname($zipSource)) !== realpath($geDir)) {
		copy($zipSource, $zipTarget);
	}

	$output = runInDirectory($geDir, escapeshellarg($ezExe).' '.escapeshellarg($zipName));
	echoOutput($output);
}

foreach (glob(pathJoin($geDir, 'ies', 'datatable_*.ies')) ?: array() as $docFile) {
	$output = runInDirectory($geDir, escapeshellarg($ix3Exe).' '.escapeshellarg('ies/'.basename($docFile)));
	echo $output;
}

foreach (glob(pathJoin($geDir, 'ies', 'datatable_*.xml')) ?: array() as $docFile) {
	copy($docFile, pathJoin($geDir, 'xml', basename($docFile)));
}

foreach (array('datatable_job.ies', 'datatable_stance.ies', 'datatable_skill.ies', 'datatable_npclist.ies', 'datatable_stancecondition.ies', 'datatable_expedition_tag.ies', 'datatable_buff.ies') as $curfile) {
	foreach (glob(pathJoin($geDir, 'ies', $curfile)) ?: array() as $globfile) {
		$output = runInDirectory($geDir, escapeshellarg($ix3Exe).' '.escapeshellarg('ies/'.basename($globfile)));
		echo $output;
	}

	foreach (glob(pathJoin($geDir, 'ies', str_replace('.ies', '.xml', $curfile))) ?: array() as $globfile) {
		copy($globfile, pathJoin($geDir, 'xml', basename($globfile)));
	}
}

?>
