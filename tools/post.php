<?php

function send2slack($text) {
  $data = json_encode(array(
    "username"      =>  SLACK_USERNAME,
    "channel"       =>  SLACK_CHANNEL,
    "text"          =>  $text,
    "icon_emoji"    =>  SLACK_ICON_EMOJI
  ));

  $ch = curl_init("SLACK_URL");
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
  curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

  $result = curl_exec($ch);
  curl_close($ch);
}
?>
