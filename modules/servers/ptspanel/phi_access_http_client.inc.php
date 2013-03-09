<?php

# Riap::HTTP client.
#
# Version: 20130308.1
#
# This software is copyright (c) 2013 by Steven Haryanto,
# <stevenharyanto@gmail.com>.
#
# This is free software; you can redistribute it and/or modify it under the
# Artistic License 2.0.
#
# Usage examples:
#
# $res = phi_http_request("call", "http://localhost:5000/Perinci/Examples/gen_array", array("args"=>array("len"=>3)));

# # http auth
# $res = phi_http_request("call", "http://localhost:5000/Perinci/Examples/gen_array", array(), array("user" => "admin", "password" => "blah"));
#
# # ssl, disable verify peer (otherwise curl network error 60 if server doesn't have valid cert)
# $res = phi_http_request("call", "https://localhost:5001/Perinci/Examples/gen_array", array(), array("ssl_verify_peer"=>0));
#
# other known copts:
# - retries (int, default 2)
# - retry_delay (int, default 3)

# todo:
# - support log viewing
# - support proxy

function phi_http_request($action, $url, $extra=array(), $copts=array()) {
  if (!extension_loaded("curl")) die("curl extension required");

  # copts
  $retries     = isset($copts['retries'])     ? $copts['retries']     : 2;
  $retry_delay = isset($copts['retry_delay']) ? $copts['retry_delay'] : 3;

  # form riap request
  $rreq = array('action' => $action, 'ua' => 'Phinci');
  foreach($extra as $k => $v) { $rreq[$k] = $v; }

  # put all riap request keys, except some like args, to http headers
  $headers = array();
  foreach ($rreq as $k => $v) {
    if (preg_match('/\A(args|fmt|loglevel|marklog|_.*)\z/', $k)) continue;
    $hk = "x-riap-$k";
    $hv = $rreq[$k];
    if (!isset($hv) || is_array($hv) || preg_match('/\n/', $hv)) {
      $hk = "$hk-j-";
      $hv = json_encode($hv);
    }
    $headers[] = "$hk: $hv";
  }
  #$http_req->header('x-riap-marklog'  => $ua->{__mark_log});
  #$http_req->header('x-riap-loglevel' => $self->{log_level});
  $headers[] = 'x-riap-fmt: json';

  $args = isset($rreq['args']) ? $rreq['args'] : array();

  # == currently doesn't work because Content-Type will either be
  # multipart/form-data (if array is passed to CURLOPT_POSTFIELDS) or
  # application/x-www-form-urlencoded (if string is passed to
  # CURLOPT_POSTFIELDS), so we put in post fields instead. this is
  # actually not guaranteed to be supported by all servers, per Riap::HTTP spec.

  ## put args in request body
  #$args_s = json_encode($args);
  #$headers['Content-Type'] = 'application/json';
  #$headers['Content-Length'] = strlen($args_s);

  # put args in form fields
  $postfields = array();
  foreach ($args as $k => $v) {
    if (!isset($v) || is_array($v)) {
      $postfields["$k:j"] = json_encode($v);
    } else {
      $postfields[$k] = $v;
    }
  }
  #print_r($postfields);
  $postfields_s = "";
  foreach ($postfields as $k => $v) {
    $postfields_s .= (strlen($postfields_s) ? "&" : "") .
      urlencode($k) . "=" . urlencode($v);
  }
  # ==

  $attempts = 0;
  $do_retry = true;
  while (true) {
    #echo "D1\n";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_VERBOSE, 0);
    curl_setopt($ch, CURLOPT_POST, 1);
    #curl_setopt($ch, CURLOPT_POSTFIELDS, $args_s);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postfields_s);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    if (isset($copts['user'])) {
      curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
      curl_setopt($ch, CURLOPT_USERPWD, "$copts[user]:$copts[password]");
    }
    if (preg_match('/^https/i', $url)) {
      if (isset($copts['ssl_verify_peer']) && !$copts['ssl_verify_peer'])
          curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
    }
    $cres = curl_exec($ch);
    $cinfo = curl_getinfo($ch);
    if (curl_errno($ch)) {
      $res = array(500, "Network failure: ".curl_errno($ch));
    } elseif ($cinfo['content_type'] != 'application/json') {
      #echo "D1b (content_type=$cinfo[content_type])\n";
      $res = array($cinfo['http_code'],
                   ($cinfo['http_code'] == 200 ? "OK" :
                    "Error $cinfo[http_code]"),
                   $cres);
      $do_retry = false;
    } else {
      #echo "D1c: cres=$cres\n";
      $res = json_decode($cres, true);
      $do_retry = false;
    }
    curl_close($ch);
    if (!$do_retry) break;
    $attempts++;
    if ($attempts > $retries) break;
    sleep($retry_delay);
  }

  #echo "D2\n";
  return $res;
}

