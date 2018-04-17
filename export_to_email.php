<?php

//usage: php export_to_email.php <token_file> <date> <from_email> <to_email>

require_once('vendor/autoload.php');

list(, $tokenFile, $date, $fromEmail, $toEmail) = $_SERVER['argv'];

$exporter = new \Southern\SlackExport\SlackExporter(file_get_contents($tokenFile));
$htmls = $exporter->getHtmlsForDate($date);
$exporter->sendEmails($htmls, $fromEmail, $toEmail);


die;
