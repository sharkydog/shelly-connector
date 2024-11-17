<?php

// include this file or add your autoloader


// and import your local config

// shelly id
// find yours at http://192.168.1.123/shelly
if(!defined('CONFIG_SHELLY_ID')) {
  define('CONFIG_SHELLY_ID', 'shellyxxxx-xxxxxxxxxxxx');
}
// ip address
if(!defined('CONFIG_ADDR')) {
  define('CONFIG_ADDR', '192.168.1.123');
}
// password
if(!defined('CONFIG_PASSWD')) {
  define('CONFIG_PASSWD', '');
}

// import the namespace
// keep in mind for the full name of the classes
use SharkyDog\Shelly;

function pn($d) {
  print "*** Ex.01: ".print_r($d,true)."\n";
}


// The websocket client is built on top of
// SharkyDog\HTTP\WebSocket\Client and
// SharkyDog\HTTP\Helpers\WsClientDecorator
// most of the methods and events come from these classes
// see sharkydog/http package examples if you want to know more
//
// The SharkyDog\Shelly\Client class defines only two public methods of importance
// setDeviceClass() and getDevice()
//
// Command responses are received through promises (react/promise package)
// Notifications through events (evenement/evenement)
//
// see https://shelly-api-docs.shelly.cloud/gen2/General/RPCProtocol
// for the structure of responses and notifications


// Find IP address using mDNS
// You need to install sharkydog/mdns package
// I do not consider mDNS (or DNS resolution) in LAN a requirement,
// so the mdns package is not set as required, only suggested.
$mdns = CONFIG_SHELLY_ID && class_exists(SharkyDog\mDNS\React\Resolver::class);

if($mdns) {
  // shellyxxxx-xxxxxxxxxxxx.local
  $url = 'ws://'.CONFIG_SHELLY_ID.'.local/rpc';
} else {
  $url = 'ws://'.CONFIG_ADDR.'/rpc';
}
pn('url: '.$url);

// 'test-client' is the string that will be used as source in the RPC calls
$client = new Shelly\Client($url, 'test-client');

// Set the mDNS resolver
if($mdns) {
  $client->resolver(new SharkyDog\mDNS\React\Resolver);
}

// Client failed to connect,
// by the default it will try to reconnect every 2 seconds until forever
$client->on('error-connect', function($e) {
  pn('Connection failed: '.$e->getMessage());
});
// or until stopped
$client->on('reconnect', function($interval) use($client) {
  static $attempts = 0;
  if($attempts == 2) {
    // seconds to wait before a reconnect attempt, 0 to disable reconnects
    $client->reconnect(0);
    pn('Stop reconnect after 2 failed attempts');
  } else {
    pn('Reconnect attempt '.(++$attempts).' after '.$interval.' seconds');
  }
});

// connection closed
$client->on('close', function() {
  pn('Connection closed (client)');
});
// connection established
// $shelly is the device object, more on that bellow
$client->on('open', function($shelly) {
  pn('Connection opened (client)');
});

// connect
// takes one parameter, timeout in seconds, default 5s
$client->connect();

// $shelly is a SharkyDog\Shelly\Device
// most operations will be performed on that object
// and will always be the same instance for the same client
$shelly = $client->getDevice();
// set password
$shelly->setPassword(CONFIG_PASSWD);

// Device object also emits connection close
$shelly->on('close', function() {
  pn('Connection closed (device)');
});

// and connection open
//
// $client is used here to close the connection
// after example is finished
$shelly->on('open', function($shelly) use($client) {
  pn('Connection opened (device)');

  // send command and ignore errors
  // If there was an error $result will be null
  // otherwise an array containing everything under "result" from the response frame
  $shelly->sendCommandSilent('Shelly.GetDeviceInfo')->then(function($result) {
    pn('Shelly.GetDeviceInfo');
    pn($result ?? 'NULL');
  });

  // commands bellow will use promise chaining
  // to execute them in sequence
  //
  // when sendCommand() and sendCommandSilent() are called
  // commands are sent immediately and return without waiting for response
  // using promise chain, the next command can be send
  // only after the previous is resolved or rejected

  // send command, but do not silence errors
  // an unhandled rejection (error) will be thrown as exception
  $pr = $shelly->sendCommand('WiFi.GetStatus');

  // catch only authentication required error
  // this can only happen if the password was not set above
  //
  // for failed authentication (wrong password)
  // the error will be SharkyDog\Shelly\Exception\AuthFailed
  //
  // any other error will propagate until next 'catch'
  // and 'Cloud.GetStatus' command bellow will not be sent
  $pr = $pr->catch(function(Shelly\Exception\AuthRequired $e) use($shelly) {
    pn($e->getMessage());
    // set password and retry
    $shelly->setPassword(CONFIG_PASSWD);
    // return new promise
    return $shelly->sendCommand('WiFi.GetStatus');
  });

  // result from first 'WiFi.GetStatus' or from the retried
  $pr = $pr->then(function($result) {
    pn('WiFi.GetStatus');
    pn($result);
  });

  // send another command
  $pr = $pr->then(function() use($shelly) {
    return $shelly->sendCommand('Cloud.GetStatus');
  });
  // and its result
  $pr = $pr->then(function($result) {
    pn('Cloud.GetStatus');
    pn($result);
  });

  // catch all authentication errors (AuthRequired and AuthFailed)
  $pr = $pr->catch(function(Shelly\Exception\Auth $e) {
    pn($e->getMessage());
  });
  // catch all errors
  $pr = $pr->catch(function($e) {
    pn('['.$e->getCode().'] '.$e->getMessage());
  });

  // cleanup, in this case just close the client
  // after all commands resolve or reject
  // so our script can exit by itself
  // but then we will see no events
  //$pr = $pr->finally(fn()=>$client->close());
});


// events (notifications)
// https://shelly-api-docs.shelly.cloud/gen2/General/Notifications


// NotifyStatus
$shelly->on('notify-status', function($comp, $data, $time) {
  pn(['notify-status', $comp, $data, $time]);
});
/**
 * $comp is the component, "switch:0"
 * $data is and array ["id"=>0,"output"=>true,"source"=>"button"]
{
   "src": "shellypro4pm-f008d1d8b8b8",
   "dst": "user_1",
   "method": "NotifyStatus",
   "params": {
      "ts": 1631186545.04,
      "switch:0": {
         "id": 0,
         "output": true,
         "source": "button"
      }
   }
}
*/


// NotifyEvent
// multiple events in one message will emit multiple 'notify-event'
$shelly->on('notify-event', function($event, $comp, $data, $time) {
  pn(['notify-event', $event, $comp, $data, $time]);
});
/**
 * $event - "single_push"
 * $comp - "input:0"
 * $data - event data without "component", "event" and "ts"
 *    here only "id" - array ["id"=>0]
{
   "src": "shellypro4pm-f008d1d8b8b8",
   "dst": "user_1",
   "method": "NotifyEvent",
   "params": {
      "ts": 1631266595.44,
      "events": [
         {
            "component": "input:0",
            "id": 0,
            "event": "single_push",
            "ts": 1631266595.44
         }
      ]
   }
}
*/