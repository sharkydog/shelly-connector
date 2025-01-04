<?php

// include this file or add your autoloader


use SharkyDog\HTTP;
use SharkyDog\Shelly;
use SharkyDog\HTTP\Log;

function pn($d) {
  print "*** Ex.04: ".print_r($d,true)."\n";
}

//Log::level(99);


// The proxy client will connect to a websocket server
// as if a shelly device is connected to it with
// an outbound websocket.
// All notifications and commands will be forwarded
// and multiple proxies can be created for a device.
// Could be useful for devices on battery to use them
// with this library while still connected (proxied) to Home Assistant.

// The main server that will accept a connection from the device.
// A device can also be connected with the websocket client and then proxied.
$httpd1 = new HTTP\Server;
$httpd1->listen('0.0.0.0', 12345);
$shellyServer1 = new Shelly\Server('test-server');
$httpd1->route('/ws/shelly', $shellyServer1);

// Another server that will accept connections from the first server through a proxy.
// Only for this example, in reality connections would be proxied to other hosts.
$httpd2 = new HTTP\Server;
$httpd2->listen('0.0.0.0', 12346);
$shellyServer2 = new Shelly\Server('test-server');
$httpd2->route('/ws/shelly', $shellyServer2);


// Device connected
$shellyServer1->on('device', function($shelly) {
  // The proxy to server2
  $proxy = new Shelly\Proxy\Client($shelly, 'ws://127.0.0.1:12346/ws/shelly');

  $proxy->on('open', function() {
    pn('proxy client: open');
  });
  $proxy->on('close', function() {
    pn('proxy client: close');
  });

  // connect() must to be called to enable the proxy
  // and will not actually connect until the device is connected
  // must be after any websocket client options
  // and after all proxy event listeners were set up
  $proxy->connect();

  // Disable some commands that could brake connectivity
  $proxy->disableCommand('Shelly.FactoryReset');
  $proxy->disableCommand('Shelly.ResetWiFiConfig');
  $proxy->disableCommand('Shelly.SetAuth');
  $proxy->disableCommand('Wifi.SetConfig');
  $proxy->disableCommand('Eth.SetConfig');
  $proxy->disableCommand('Ws.SetConfig');


  pn('server1: new device');

  // When the device disconnects, the proxy will disconnect too.
  // And because in this setup, the device is not registered (by ip or id),
  // server will remove all event listeners.
  // If any are attached to the proxy, they have to be removed too.
  // When the device reconnects, the 'device' event (this callback) will be emitted again.
  $shelly->on('close', function() use($proxy) {
    pn('server1: device gone');
    $proxy->removeAllListeners();
  });

  $shelly->on('error', function($e) {
    pn('server1: dev error');
    pn([get_class($e),$e->getMessage(),$e->getCode(),$e->getFile(),$e->getLine()]);
  });

  // The rest is just to dump what is happening
  // Repeats for server2
  $shelly->sendCommandSilent('Shelly.GetDeviceInfo')->then(function($result) {
    pn('server1: Shelly.GetDeviceInfo');
    pn($result ?? 'NULL');
  });

  $shelly->on('notify-status', function($comp, $data, $time) {
    pn('server1: notify-status: '.$comp);
  });
  $shelly->on('notify-status-full', function($data, $time) {
    pn('server1: notify-status-full: mac: '.$data['sys']['mac']);
  });
  $shelly->on('notify-event', function($event, $comp, $data, $time) {
    pn('server1: notify-event: '.$comp.': '.$event);
  });
});


// Server1 proxy connected to server2
// as if the device connected directly to server2
$shellyServer2->on('device', function($shelly) {
  pn('server2: new device');

  $shelly->sendCommandSilent('WiFi.GetStatus')->then(function($result) {
    pn('server2: WiFi.GetStatus');
    pn($result ?? 'NULL');
  });

  $shelly->on('notify-status', function($comp, $data, $time) {
    pn('server2: notify-status: '.$comp);
  });
  $shelly->on('notify-status-full', function($data, $time) {
    pn('server2: notify-status-full: mac: '.$data['sys']['mac']);
  });
  $shelly->on('notify-event', function($event, $comp, $data, $time) {
    pn('server2: notify-event: '.$comp.': '.$event);
  });

  $shelly->on('close', function() {
    pn('server2: device gone');
  });
});
