<?php
$cron = $argv[1];
error_reporting(E_ALL);
ini_set('display_errors', true);

require('./engine/CMSMain.inc.php');
require_once('./engine/simple_html_dom.php');
CMSGlobal::setTEXTHeader();

if (!$cron && !CMSLogicAdmin::getInstance()->isLoggedUser()) {
	die('LOGIN REQUIRED');
}

CMSProcess::instance()->start_process(CMSLogicProvider::COSTA, "parse");

CMSPluginSession::getInstance()->close();

$parser = new CMSClassGlassesParserCosta();

if (!$parser->syncLock()) {
	//die('Costa parser already running');
}

echo 'Syncing Costa', "\n";

try {
	$parser->sync();
} catch (Exception $e) {
	print_r($e); die;
}

$parser->syncUnlock();
$sssss = updateAvlTimeForItems();

CMSProcess::instance()->end_process();
echo "DONE\n";