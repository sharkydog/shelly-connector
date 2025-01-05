<?php

// include this file or add your autoloader


// and import your local config

// shelly id
if(!defined('CONFIG_SHELLY_ID')) {
  define('CONFIG_SHELLY_ID', 'shellyxxxx-xxxxxxxxxxxx');
}


use SharkyDog\Shelly;
use SharkyDog\HTTP;
use React\Promise;

function pn($d) {
  print "*** Ex.05: ".print_r($d,true)."\n";
}

//HTTP\Log::level(99);


// The proxy server will emulate websocket, http get and http post RPC channels
// of the shelly gen2 api.
// Commands and notifications will be forwarded between the proxy server
// and a given shelly device connected by a websocket client or server.
//
// This is somewhat more useful than the proxy client.
// A device behind NAT can connect to the server (outbound websocket),
// then local clients (like Home Assistant) can use it through the proxy server.
//
// Multiple proxies can be created for a device, essentially making copies of it.


// The server that will accept a connection from the device.
$httpd = new HTTP\Server;
$httpd->listen('0.0.0.0', 12345);
$shellyServer = new Shelly\Server('test-server');
$httpd->route('/ws/shelly', $shellyServer);

// The proxy server
$proxyServer = new Shelly\Proxy\Server;

// proxies are better used with known devices
$shelly = $shellyServer->registerDeviceByID(CONFIG_SHELLY_ID);

// register a proxy on port 12347, listening on all interfaces (0.0.0.0)
$proxyServer->registerDevice($shelly, 12347);
// or on a specific address if this host has multiple IP addresses
//$proxyServer->registerDevice($shelly, 12347, '192.168.0.123');

// change device id
// id should be in the form 'shellyxxxx-aabbccddeeff'
// the proxy device will extract the mac address part
// and set it in the Shelly.GetDeviceInfo response
$proxyServer->registerDevice(new Shelly\Proxy\Device($shelly,'shellyxxxx-aabbccddeeff'), 12348);

// a custom proxy device
// message formats
// https://shelly-api-docs.shelly.cloud/gen2/General/RPCProtocol
class MyProxyDevice extends Shelly\Proxy\Device {

  // resolves with \stdClass object which contains the result part of a response frame
  // rejects with \Throwable
  protected function _proxy_command(string $method, array $params=[]): Promise\PromiseInterface {
    // our device now has a new command
    if($method == 'Proxy.FindAJedi') {
      // resolved value must be a \stdClass object
      return Promise\resolve((object)['jedi1'=>'Hello there']);
    }

    // do your worst here with a command before being sent
    $promise = parent::_proxy_command($method, $params);

    // and/or after completed (resolved or rejected)
    $promise = $promise->then(function($result) {
      return $result;
    });
    $promise = $promise->catch(function($e) {
      throw $e;
    });

    return $promise;
  }

  // $msg is the full notification frame, including 'src' and 'dst' properties
  // and can be modified
  // if this function returns boolean false, the notification is discarded
  protected function _proxy_notify(\stdClass $msg) {
  }

  // always resolves with full response frame, constructed from
  // the result or error from _proxy_command()
  // commands can be mangled with here as well
  public function proxyCommand(string $method, array $params=[]): Promise\PromiseInterface {
    return parent::proxyCommand($method, $params);
  }
}

// decorate the device to a custom proxy device
//$proxyDevice = new MyProxyDevice($shelly);
// and change id
$proxyDevice = new MyProxyDevice($shelly, 'myshelly-010203040506');
// then register with the proxy server
$proxyServer->registerDevice($proxyDevice, 12349);

// Disable some commands that could brake connectivity
// these will apply for all devices registered with the proxy server
$proxyServer->disableCommand('Shelly.FactoryReset');
$proxyServer->disableCommand('Shelly.ResetWiFiConfig');
$proxyServer->disableCommand('Shelly.SetAuth');
$proxyServer->disableCommand('Wifi.SetConfig');
$proxyServer->disableCommand('Eth.SetConfig');
$proxyServer->disableCommand('Ws.SetConfig');

// this will apply only to the specific proxy device
// must be called after registerDevice() with the same port and address
//$proxyServer->disableCommand('Shelly.FactoryReset', true, 12349);
// equivalent to
//$proxyDevice->disableCommand('Shelly.FactoryReset');


// testing

// now, any client can connect to one of the registered ports above
// and use http and websocket api as described here
// https://shelly-api-docs.shelly.cloud/gen2/General/RPCChannels
//
// if a proxy device is to be added to Home Assistant and the real device
// is in the local network, be sure to change the device id with a unique mac
// as Home Assistant will change the ip address to whatever it sees in mDNS

// $shelly and $proxyDevice can be operated as usual
// with sendCommand() and event listeners
// but for the purposes of this example
// a websocket client will be used

$client = new Shelly\Client('ws://127.0.0.1:12349/rpc', 'test-client');
$client->connect();
$device = $client->getDevice();

$device->on('open', function($device) {
  pn('client: open');

  $device->sendCommand('Shelly.GetDeviceInfo')->then(function($res) {
    pn('client: Shelly.GetDeviceInfo: result');
    pn($res);
  })->catch(function($e) {
    pn('client: Shelly.GetDeviceInfo: error');
    pn(['code'=>$e->getCode(),'text'=>$e->getMessage()]);
  });

  $device->sendCommand('Proxy.FindAJedi')->then(function($res) {
    pn('client: Proxy.FindAJedi: result');
    pn($res);
  });
});

$device->on('close', function() {
  pn('client: close');
});

$device->on('notify-status', function($comp, $data, $time) {
  pn('client: notify-status: '.$comp);
});
