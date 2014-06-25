#!/usr/bin/php
<?php

/*
 * W3C Multipage Unicorn Result Parser
 *
 * Copyright (c) 2013 Jonathan Brace
 */

define('NOTICE', 0);
define('WARNING', '1;33');
define('ERROR', '0;31');

error_reporting(0);

libxml_use_internal_errors(true);
$totalErrors = 0;

array_shift($argv);

$options = array();
$ignore = array();

foreach ($argv as $arg) {
  preg_match('/^--([A-Za-z_]+)=(.*)/', $arg, $matches);
  $options[$matches[1]] = $matches[2];
}

$results = array();

if (isset($options['ignore'])) {
  $ignore_list = yaml_parse_file($options['ignore']);

  if ( !$ignore_list ) {
    writeLogConsole("Cannot parse YAML file: " . $options['ignore'], ERROR);
    exit(1);
  }
}

$duplicates = array();

foreach (explode(',', $options['pages']) as $page) {

  writeLogConsole("Checking:   " . $page . "\n");

  $file_parts = pathinfo($page);
  $filename = $file_parts['filename'] . '.' . $file_parts['extension'];

  $results[$page] = array(
    'results' => array()
  );

  if (isset($options['base_url'])) {
  
    $html = validateHtmlUrl($page, $options);
    
  } else {

    $html = validateHtmlFile($page, $options);

    if (false === $html) {
      writeLogConsole("...no changes - ignoring");
      continue;
    }
  }

  $doc = new DOMDocument();
  $doc->strictErrorChecking = FALSE;
  $doc->loadHTML($html);
  $xml = simplexml_import_dom($doc);

  if (!$xml) {
    writeLogConsole("Failed loading XML", ERROR);
    foreach (libxml_get_errors() as $error) {
      writeLogConsole("\t", $error->message, ERROR);
    }
  }

  $severityXpath = array(
    'markup-validator_warning' => array(
      'type' => 'html',
      'severity' => 'warning'
    ),
    'markup-validator_error' => array(
      'type' => 'html',
      'severity' => 'error'
    ),
    'css21-validator_error' => array(
      'type' => 'css',
      'severity' => 'error'
    ),
    'css21-validator_warning' => array(
      'type' => 'css',
      'severity' => 'error'
    ),
    'css3-validator_warning' => array(
      'type' => 'css',
      'severity' => 'error'
    ),
    'css3-validator_error' => array(
      'type' => 'css',
      'severity' => 'error'
    )
  );

  $results[$page]['results']['css'] = array();
  $results[$page]['results']['html'] = array();

  foreach ($severityXpath as $xpath => $data) {

    $xpath = sprintf('//div[@id="%s"]//table/tbody/tr', $xpath);

    $nodes = $xml->xpath($xpath);

    if (!is_array($nodes)) {
      continue;
    }

    array_shift($nodes);

    foreach ($nodes as $node) {

      $linenumber = $node->xpath('./*[@class="linenumber"]');
      $colnumber = $node->xpath('./*[@class="colnumber"]');
      $message = $node->xpath('./*[@class="info level0 message"]/span');

      $ignore = false;

      $result = array(
        'line' => (string)$linenumber[0],
        'col' => (string)$colnumber[0],
        'message' => (string)$message[0],
        'severity' => $data['severity']
      );

      if (@in_array($result['message'], $duplicates[$result['line']][$result['col']])) {
        continue;
      }

      if (isset($options['ignore'])) {

        $rules = $ignore_list['global'];
        if ( array_key_exists($filename, $ignore_list) ) {
          $rules = array_merge($rules, $ignore_list[$filename]);
        }

        foreach ($rules as $rule ) {
          if (1 === preg_match($rule, $result['message'], $matches)) {
            $ignore = true;
            continue;
          }
        }
      }

      if (!$ignore) {
        $duplicates[$result['line']][$result['col']][] = $result['message'];

        $results[$page]['results'][$data['type']][] = $result;
        $totalErrors++;

        $type = $result['severity'] == 'error' ? ERROR : WARNING;

        writeLogConsole(sprintf("   [%s] %s: %d,%d\t: %s", $result['severity'], $page, $result['line'], $result['col'], $result['message']), $type);
      }
    }

    writeLogFile($page, $results[$page]['results'][$data['type']], $options['logdir'], $data['type']);
  }
}

function writeLogConsole($msg, $type = NOTICE) {

  if ( NOTICE ) {
    echo $msg . "\n";
  }
  else {
    echo "\033[" . $type . "m" . $msg . "\033[0m" . "\n";
  }

}

function writeLogFile($filename, $results, $logdir, $type) {

  if (empty($results)) {
    return;
  }

  $file = $logdir . '/build-' . $type . 'lint--' . sha1($filename) . '.log';

  $output = null;

  foreach ($results as $error) {
    $output .= sprintf('[%s] %s:%d: %s', $error['severity'], $filename, $error['line'], str_replace(PHP_EOL, '', $error['message'])) . "\n";
  }

  file_put_contents($file, $output);
}

function validateHtmlUrl($page, $options) {
  $url = sprintf('http://%s/unicorn/check?ucn_uri=%s/%s&doctype=HTML5&ucn_task=conformance', $options['unicorn'], $options['base_url'], $page);
  return file_get_contents($url);
}

function validateHtmlFile($page, $options) {

  $pagePath = $options['base_path'] . $page;

  $html = file_get_contents($pagePath);

  /*
   * Hashing the contents so that we only lint changes
   */
  if (!file_exists($options['logdir'] . '/fingerprints/')) {
    mkdir($options['logdir'] . '/fingerprints/');
  }

  $hash = sha1($page) . '.sha';
  $path = $options['logdir'] . '/fingerprints/' . $hash;

  if (file_exists($path)) {
    $contentsHash = file_get_contents($path);

    if ($contentsHash == hash_file('sha256', $pagePath)) {
      return false;
    }
  }

  file_put_contents($path, hash_file('sha256', $pagePath));

  $url = sprintf('http://%s/unicorn/check', $options['unicorn']);

  $fields = array(
    'ucn_text' => rawurlencode($html),
    'ucn_task' => urlencode('conformance'),
    'ucn_text_mime' => urlencode('text/html'),
    'charset' => urlencode('UTF-8'),
    'doctype' => 'HTML5'
  );

  $fields_string = null;
  foreach ($fields as $key => $value) {
    $fields_string .= $key . '=' . $value . '&';
  }

  rtrim($fields_string, '&');

  //open connection
  $ch = curl_init();

  //set the url, number of POST vars, POST data
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_POST, count($fields));
  curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_string);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 60);
  curl_setopt($ch, CURLOPT_TIMEOUT, 120);

  //execute post
  $result = curl_exec($ch);

  //close connection
  curl_close($ch);

  return $result;
}
