#!/usr/bin/php
<?php
/**
 * Multi DNSRBL checker / monitor. Can send e-mails when your domain is blacklisted.
 *
 * License: MIT License
 * Author: Rob Vella <me@robvella.com>
 */

require 'vendor/autoload.php';
require_once __DIR__."/AnsiTheme.php";

use SensioLabs\AnsiConverter\AnsiToHtmlConverter as AnsiToHtml;

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Parse arguments list
$mArgs        = getopt("b::n::e::h::",['show-blacklists-only::','no-color::','email-if-bl::','host::']);

// SMTP host
$queryHost    = parseArgs($mArgs, 'h', 'host');

// Send an e-mail if the domain shows up on a blacklist
$email        = parseArgs($mArgs, 'e', 'email-if-bl');

// Disable terminal colors
$noColor      = parseArgs($mArgs, 'n', 'no-color');

// Only output blacklist information
$blOnly       = parseArgs($mArgs, 'b', 'show-blacklists-only');
// END

// Configure e-mail settings here

// Use PHP's mailer
$emailSettings = [
	'method' => 'builtin',
	'from' => 'Blacklist Checker <smtp@YOURDOMAIN.com>',
	'to' => 'YOUR@EMAIL.COM',
	'subject' => '[WARNING] Host ' . $queryHost . ' is on a blacklist!',
];

// Use Mailgun
$emailSettings = [
	'method' => 'mailgun',
	'from' => 'Blacklist Checker <smtp@YOURDOMAIN.mailgun.org>',
	'to' => 'YOUR@EMAIL.COM',
	'subject' => '[WARNING] Host ' . $queryHost . ' is on a blacklist!',
	'mailgun_apikey' => 'key-YOURAPIKEY',
	'mailgun_domain' => 'YOURDOMAIN.mailgun.org'
];
// END mail settings

if (strlen($queryHost) <= 0) {
	echo "You must specify a host to check!";
	printUsage();
}

echo "Checking host " . $queryHost . "\n";

$scrapeUrl = 'http://multirbl.valli.org/lookup/'. $queryHost.'.html';
$jsonUrl = 'http://multirbl.valli.org/json-lookup.php';

$blTypes = [
	'b' => colorizeString('Blacklist', 'red'),
	'c' => colorizeString('Combinedlist', 'cyan'),
	'w' => colorizeString('Whitelist', 'green'),
	'i' => colorizeString('Infolist', 'blue')
];

// Init vars
$l_ids = [];
$listedResults = [];
$lastType   = '';
$unlistedBl = 0;
$listedBl   = 0;
$emailBody     = '';
// END


$html = \SimpleHtmlDom\file_get_html($scrapeUrl);
echo "Scraping page...\r";
// Testing mode
//file_put_contents(__DIR__.'/tmp/scraped.html', $html);
//$html = \SimpleHtmlDom\str_get_html(file_get_contents(__DIR__.'/tmp/scraped.html'));

$asessionHash = '';

foreach ($html->find('script') as $el) {
	if (!empty($el->src)) {
		continue;
	}

	if (preg_match('/asessionHash\"\:\s?\"(.*?)\"/', $el, $matches)) {
		$asessionHash = $matches[1];
		break;
	}
}

if (empty($asessionHash)) {
	throw new Exception("Could not find session hash in HTML!");
}

// Find l_id of each blacklist
foreach ($html->find('#dnsbl_data tr td.l_id') as $el) {
	$l_ids[] = $el->text();
}

if (!count($l_ids)) {
	throw new Exception("Could not get l_ids of each RBL!");
}

// Get JSON of blacklist status
foreach ($l_ids as $k=>$id) {
	$postdata = http_build_query(
		[
			'lid' => $id,
			'ash' => $asessionHash,
			'rid' => 'DNSBLBlacklistTest_' . $k,
			'q'   => $queryHost
		]
	);

	$opts = [
		'http' => [
			'method' => 'POST',
			'header' => [
				'Content-Type: application/x-www-form-urlencoded',
				'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_11_3) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/48.0.2564.103 Safari/537.36',
				'X-Requested-With: XMLHttpRequest',
			],
			'content' => $postdata
		]
	];

	try {
		$progress = floor(($k / count($l_ids)) * 100);

		echo "Retrieving data from DNSBLs ({$progress}%)...\r";
		$context  = stream_context_create($opts);
		$result   = file_get_contents($jsonUrl, false, $context);

		// Testing mode
		//file_put_contents(__DIR__.'/tmp/result.'.$id.'.json', $result);
		//$result = file_get_contents(__DIR__.'/tmp/result.'.$id.'.json');

		$result = json_decode($result);

		if (empty($result) || empty($result->name) || empty($result->data)) {
			throw new \Exception('Result data was empty for lid ' . $id);
		}

		$listedResults[] = array_merge(
			[ 'blName' => $result->name, 'blUrl' => $result->url, 'blType' => $result->type ],
			(array) $result->data
		);
	} catch (\Exception $e) {
		echo 'ERROR: ' . $e->getMessage() . "\n";
	}
}

// Clear the progress bar
echo "                                           \r";
echo "RBL data retrieved!\n\n";

// Sort array by type
usort($listedResults, function($a, $b) {
	return $a['blType'] == $b['blType'] ? 0 : ($a['blType'] === 'b' ? -1 : 1);
});

foreach ($listedResults as $k=>$res) {
	$resultColor = '';
	if ($res['listed'] != true) {
		// Number of blacklists we're on
		if ($res['blType'] == "b") {
			$unlistedBl++;
		}

		continue;
	} else if ($res['blType'] != "b" && $blOnly == true) {
		// Skip this list if blacklist only option is set
		continue;
	}

	if ($res['blType'] == "b") {
		$resultColor = 'red';

		if ($res['listed'] == true) {
			$listedBl++;
		}
	}

	ob_start();

	$res['listed'] = $res['listed'] == true ? colorizeString('YES', $resultColor) : 'NO';
	$blType = $blTypes[$res['blType']];

	if ($res['blType'] !== $lastType) {
		echo "----------------------------\n";
		echo "{$blType}\n";
		echo "----------------------------\n";
	}

	echo "{$res['blName']}\n";
	echo "{$res['blUrl']}\n";
	echo "\tType: {$blType}\n";
	echo "\tListed: {$res['listed']}\n";

	if ($res['blType'] == "c") {
		if (!empty($res['a'])) {
			$db_rc = $res['a'][0]->db_rc[0];
			echo "\tStatus: " . $db_rc->type . "\n";
			echo "\tDescription: " . $db_rc->description . "\n";
		}
	}
	echo "\n";

	$tempBuffer = ob_get_clean();

	if ($email) {
		$emailBody .= $tempBuffer;
	}

	echo $tempBuffer;

	$lastType = $res['blType'];
}

echo colorizeString("Total Blacklisted: ".$listedBl, 'red')."\n";
echo "Total lists: ".count($listedResults)."\n";
echo "Number of blacklists domain is not listed on: {$unlistedBl}\n\n";

if ($listedBl && $email) {
	echo "Sending e-mail to " . $emailSettings['to'] . "...\n";
	sendMail($emailBody);
}

/**
 * @param $string
 * @param $color
 * @return string
 */
function colorizeString($string, $color) {
	global $noColor;

	$colors['black'] = '0;30';
	$colors['dark_gray'] = '1;30';
	$colors['blue'] = '0;34';
	$colors['light_blue'] = '1;34';
	$colors['green'] = '0;32';
	$colors['light_green'] = '1;32';
	$colors['cyan'] = '0;36';
	$colors['light_cyan'] = '1;36';
	$colors['red'] = '0;31';
	$colors['light_red'] = '1;31';
	$colors['purple'] = '0;35';
	$colors['light_purple'] = '1;35';
	$colors['brown'] = '0;33';
	$colors['yellow'] = '1;33';
	$colors['light_gray'] = '0;37';
	$colors['white'] = '1;37';

	if (isset($colors[$color]) && PHP_SAPI == "cli" && $noColor == false) {
		$string = "\033[" . $colors[$color] . "m" . $string . "\033[0m";
	}

	return $string;
}

/**
 * @param $mArgs
 * @param $short
 * @param $long
 * @return string
 */
function parseArgs($mArgs, $short, $long) {
	return isset($mArgs[$short]) ? ($mArgs[$short] == false ? true : $mArgs[$short]) : (isset($mArgs[$long]) ? $mArgs[$long] : false);
}

/**
 * @param $email
 * @param $subject
 * @param $emailBody
 */
function sendMail($emailBody) {
	global $emailSettings;

	// Convert ANSI colors to HTML
	$ansi = new AnsiToHtml(new \AnsiTheme());

	$emailBody = '
	<style type="text/css">
		pre { font-size: 16px; };
	</style>
	<pre>' . nl2br($ansi->convert($emailBody)) . "</pre>";

	if ($emailSettings['method'] == "builtin") {
		mail($emailSettings['to'], $emailSettings['subject'], $emailBody, 'From: ' . $emailSettings['from'] . "\r\nContent-Type: text/html\r\n");
	} else if ($emailSettings['method'] == "mailgun") {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
		curl_setopt($ch, CURLOPT_USERPWD, 'api:'.$emailSettings['mailgun_apikey']);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
		curl_setopt($ch, CURLOPT_URL, 'https://api.mailgun.net/v3/'.$emailSettings['mailgun_domain'].'/messages');
		curl_setopt($ch, CURLOPT_POSTFIELDS, [
				'from' => $emailSettings['from'],
				'to' => $emailSettings['to'],
				'subject' => $emailSettings['subject'],
				'html' => $emailBody
			]
		);

		$j = json_decode(curl_exec($ch));
		$rInfo = curl_getinfo($ch);

		if ($rInfo['http_code'] != 200) {
			throw new Exception("Error with sending e-mail through MailGun! Check your settings and try again.\n\n" . $j->message);
		}
	}

	return;
}

function printUsage() {
	echo "\nusage: MultiRbl.php [-b -n -e -h=smtp.yourdomain.com] [--show-blacklists-only=y --no-color=y --email-if-bl=y --host=smtp.yourdomain.com]";
	echo "\n";
	exit;
}
