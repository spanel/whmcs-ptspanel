<?php

# WHMCS provisioning module for PT Spanel's products (mainly Spanel licenses)
#
# Version: 0.01
#
# This software is copyright (c) 2013 by PT Spanel (contact: <info@spanel.info>)
#
# This is free software; you can redistribute it and/or modify it under the
# Artistic License 2.0.
#
# Installation instruction:
#
# 1. Obtain a reseller account from <licensing@spanel.info>.
#
# 2. Upload the ptspanel/ directory into the modules/servers/ folder of your
#    WHMCS installation.
#
# 3. Activate the provisioning module from your WHMCS. Enter username and
#    password given in step 1, which will be used to access the API at
#    our license server at http://spanel.info .

# 4. Create two products, e.g. called "Spanel physical server license" and
#    "Spanel VPS license". Set the module for these products to "ptspanel". Fill
#    out the reseller username and password given in step #1. Also fill the
#    product name, which must be exactly "Spanel physical server license" for
#    the first one, and "Spanel VPS license" for the second one.
#
# 5. That's it. Now clients just need to order from your WHMCS installation.
#    When the order is processed and the product is created, license ID will
#    appear in the Subscription ID field in the product page. This license ID
#    can be used to install Spanel (see http://spanel.info/ on Spanel
#    installation instruction).
#
# 6. To reset the license IP (e.g. the server changes IP or you want to reuse
#    the license to another machine), you can press the [Create] button again.
#    To prevent abuse, there is a limit on the number of IP resets you can do.
#
# For questions/support, please contact <support@spanel.info>.

require_once "phi_access_http_client.inc.php";

function ptspanel_ConfigOptions() {
  $configarray = array(
                       "user"    => array( "Type" => "text", "Size" => "32", "Description" => "spanel.info reseller account username"), # configoption1
                       "pass"    => array( "Type" => "text", "Size" => "32", "Description" => "spanel.info reseller account password"), # configoption2
                       "product" => array( "Type" => "text", "Size" => "64", "Description" => "product name"), # configoption3
                       );
  return $configarray;
}

function ptspanel_ALL($params) {
  $action  = $params['action'];
  $user    = $params['configoption1'];
  $pass    = $params['configoption2'];
  $hid     = $params['serviceid'];

  // $urlp = "https://spanel.info/api/Spanel/License/Server/create_license;debug".json_encode(array($params)),
  $urlp = "https://spanel.info/api/Spanel/License/Server";
  $np = "Performed by WHMCS module ptspanel, admin=(id=$_SESSION[adminid]), ip=$_SERVER[REMOTE_ADDR], service ID=$hid"; // noteprefix

  # phi client options
  $phiopts = array(
                 "user"=>$user,
                 "password"=>$pass,
                 "ssl_verify_peer"=>false,
                 );

  $result = "";
  do {

    $res = mysql_query("SELECT *, UNIX_TIMESTAMP(nextduedate) AS nextduedate_u FROM tblhosting WHERE id=$hid");
    $row = mysql_fetch_assoc($res);
    $lid = $row['subscriptionid'];

    if ($action == 'create' && !$lid) {
      $product = $params['configoption3'];
      $cli     = $params['clientsdetails'];

      // get license's billing cycle
      $res = mysql_query("SELECT * FROM tblhosting WHERE id=$hid");
      $row = mysql_fetch_assoc($res);
      $bcycle = strtolower($row['billingcycle']);

      $res = phi_http_request("call", "$urlp/create_license",
                              array("args" => array(
                                                    "reseller"      => $user,
                                                    "product"       => $product,
                                                    "billing_cycle" => $bcycle,
                                                    // "client"        => strtolower($params['clientsdetails']['email']), # XXX API will automatically create client with that email
                                                    "note"          => "$np, client=(name=$cli[firstname] $cli[lastname], email=$cli[email], ID=$cli[id])",
                                                    )),
                              $phiopts);
      if ($res[0] != 200) { $result = "ERROR: $res[0] - $res[1]"; break; }

      // put license ID in subscriptionid field
      mysql_query("UPDATE tblhosting SET subscriptionid='".$res[2]['id']."' WHERE id=$hid");

      $result = "success"; break;
    }

    // hack: since there's no button to do IP reset, we do IP reset as follow:
    // press create when subscription ID is already set. this will do an IP
    // reset instead.
    if ($action == 'create' && $lid) {
      $res = phi_http_request("call", "$urlp/reset_license_ip",
                              array("args" => array(
                                                    "id"       => $lid,
                                                    "reason"   => "$np",
                                                    )),
                              $phiopts);
      if ($res[0] != 200) { $result = "ERROR: $res[0] - $res[1]"; break; }
      $result = "success"; break;
    }

    if ($action == 'suspend' || $action == 'unsuspend') {
      $res = phi_http_request("call", "$urlp/{$action}_license",
                              array("args" => array(
                                                    "id"     => $lid,
                                                    "reason" => "$np",
                                                    )),
                              $phiopts);
      if ($res[0] != 200) { $result = "ERROR: $res[0] - $res[1]"; break; }
      $result = "success"; break;
    }

    if ($action == 'terminate') {
      $res = phi_http_request("call", "$urlp/delete_license",
                              array("args" => array(
                                                    "id"     => $lid,
                                                    "reason" => "$np",
                                                    )),
                              $phiopts);
      if ($res[0] != 200) { $result = "ERROR: $res[0] - $res[1]"; break; }

      mysql_query("UPDATE tblhosting SET subscriptionid='' WHERE id=$hid");

      $result = "success"; break;
    }

    if ($action == 'renew') {
      $res = phi_http_request("call", "$urlp/renew_license",
                              array("args" => array(
                                                    "id"            => $lid,
                                                    "billing_cycle" => strtolower($row['billingcycle']),
                                                    "expire_time"   => $row['nextduedate_u'],
                                                    )),
                              $phiopts);
      if ($res[0] != 200) { $result = "ERROR: $res[0] - $res[1]"; break; }
      $result = "success"; break;
    }

    $result = "ERROR: unknown action";
  } while (0);

  return $result;
}

function ptspanel_CreateAccount($params)    { ptspanel_ALL($params); }
function ptspanel_SuspendAccount($params)   { ptspanel_ALL($params); }
function ptspanel_UnsuspendAccount($params) { ptspanel_ALL($params); }
function ptspanel_TerminateAccount($params) { ptspanel_ALL($params); }
function ptspanel_Renew($params)            { ptspanel_ALL($params); }

# ChangePackage

# ClientArea

# AdminArea

# LoginLink

# ClientAreaCustomButtonArray

# AdminCustomButtonArray

# UsageUpdate

# AdminServicesTabFields

# AdminServicesTabFieldsSave


