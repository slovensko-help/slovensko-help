<?php

function post(string $url, array $json, string $login = null, string $password = null): void
{
  if ($login === null || $password === null) {
    $authHeader = '';
  }
  else {
    $authHeader = "Authorization: Basic " . base64_encode("$login:$password") . "\r\n";
  }

  $options = array(
      'http' => array(
          'header' => "Content-type: application/json\r\n" . $authHeader,
          'method' => 'POST',
          'content' => json_encode($json)
      )
  );

  file_get_contents($url, false, stream_context_create($options));
}
