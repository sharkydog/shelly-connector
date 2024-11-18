<?php

// include this file or add your autoloader


// and import your local config

// shelly id
if(!defined('CONFIG_SHELLY_ID')) {
  define('CONFIG_SHELLY_ID', 'shellyxxxx-xxxxxxxxxxxx');
}
// address
if(!defined('CONFIG_ADDR')) {
  define('CONFIG_ADDR', '192.168.1.123');
}


use SharkyDog\Shelly;
use SharkyDog\HTTP;

function pn($d) {
  print "*** Ex.02: ".print_r($d,true)."\n";
}

// Install sharkydog/logger package to see debug messages from the http server
//HTTP\Log::level(99);


// The websocket server accepts connections from
// shelly devices configured with outbound websocket
// The same SharkyDog\Shelly\Device objects are emitted
// as in the 01-client.php example, so they are used the same way


// The HTTP server
// see sharkydog/http package
$httpd = new HTTP\Server;
// listen on all interfaces, port 12345
$httpd->listen('0.0.0.0', 12345);

// The shelly websocket server
// 'test-server' is the source for the RPC calls
// https://shelly-api-docs.shelly.cloud/gen2/General/RPCProtocol
$shellyServer = new Shelly\Server('test-server');

// http route
// so the endpoint for the outbound websocket will be
// ws://your_server_ip:12345/ws/shelly
$httpd->route('/ws/shelly', $shellyServer);

// this event is emitted on new connections and gives
// the SharkyDog\Shelly\Device object of the connected shelly device
// unknown devices will always have new instances
// known (registered) devices will always emit the same Device object
$shellyServer->on('device', function(Shelly\Device $shelly, bool $registered) {
  // known devices are handled separately in this example
  if($registered) {
    return;
  }

  pn('Unknown device');

  // when connection to unknown device is closed
  // the object is not usable anymore
  // server will remove all listeners attached with on() and once()
  // after the 'close' event is emitted
  // so the object can be garbage collected

  $shelly->on('notify-status-full', function($data, $time) {
    pn('MAC: '.$data['sys']['mac']);
  });

  // send command
  $shelly->sendCommandSilent('Shelly.GetDeviceInfo')->then(function($result) {
    pn('Shelly.GetDeviceInfo [dev unknown]');
    pn($result ?? 'NULL');
  });
});


// register a known device by id
// the returned SharkyDog\Shelly\Device object
// is the same instance that will be emitted above when the shelly connects
$shelly = $shellyServer->registerDeviceByID(CONFIG_SHELLY_ID);
// or register by ip address
//$shelly = $shellyServer->registerDeviceByIP(CONFIG_ADDR);

// handle new connections in the 'device' event above
// or for registered devices with 'open' event bellow

// connected
$shelly->on('open', function($shelly) {
  pn('open');
  $shelly->sendCommandSilent('Shelly.GetDeviceInfo')->then(function($result) {
    pn('Shelly.GetDeviceInfo [dev known]');
    pn($result ?? 'NULL');
  });
});

// connection closed
$shelly->on('close', function() {
  pn('close');
});

// NotifyStatus
$shelly->on('notify-status', function($comp, $data, $time) {
  pn(['notify-status', $comp, $data, $time]);
});

// NotifyFullStatus
$shelly->on('notify-status-full', function($data, $time) {
  //pn(['notify-status-full', $data, $time]);
});

// NotifyEvent
$shelly->on('notify-event', function($event, $comp, $data, $time) {
  pn(['notify-event', $event, $comp, $data, $time]);
});
