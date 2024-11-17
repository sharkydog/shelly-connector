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
  print "*** Ex.03: ".print_r($d,true)."\n";
}

//HTTP\Log::level(99);


// Customised device classes can be made
// to implemented specific device logic.
//
// For example, a ShellyPlus2PM could have methods
// like toggleSwitch0(), readSwitch0Voltage(), etc.
//
// Also, custom events could further parse status notifications.


// Since we all can not have the same devices
// the following class will just report
// uptime periodically (using Sys.GetStatus).
//
// A real world example would be to
// make Plus PM Mini report voltage on every NotifyStatus,
// which usually includes only energy.

// Need the event loop for the timer
use React\EventLoop\Loop;

// The custom device
class ShellyCustomDevice extends Shelly\Device {
  private $_interval;
  private $_timer;

  public function __construct(int $interval) {
    $this->_interval = $interval;
  }

  // connection opened, create a timer
  // call the parent to emit 'open' event
  protected function _on_open() {
    $this->_timer = Loop::addPeriodicTimer($this->_interval, function() {
      $this->_reportUptime();
    });
    parent::_on_open();
  }

  // connection close, stop the timer
  // call the parent to emit 'close' event
  protected function _on_close() {
    Loop::cancelTimer($this->_timer);
    $this->_timer = null;
    parent::_on_close();
  }

  // The following overrides are only shown here
  // to serve as documentation.
  // Not used for this example.
  // Call parent to emit the respective event
  // or remove the override.

  // on NotifyStatus, 'notify-status' event
  protected function _on_notify_status(string $comp, array $data, float $time) {
    parent::_on_notify_status($comp, $data, $time);
  }

  // NotifyFullStatus, 'notify-status-full' event
  // sent over an outbound websocket after connect
  protected function _on_notify_status_full(array $data, float $time) {
    // this notification can be used to emit the uptime
    // as the 'sys' component is included in the data
    $this->_emit('uptime-seconds', [ $data['sys']['uptime'] ]);
    parent::_on_notify_status_full($data, $time);
  }

  // NotifyEvent, 'notify-event' event
  protected function _on_notify_event(string $event, string $comp, array $data, float $time) {
    parent::_on_notify_event($event, $comp, $data, $time);
  }

  // The real work, triggered on a timer,
  // but may as well be triggered on event or command being sent.
  private function _reportUptime() {
    $this->sendCommandSilent('Sys.GetStatus')->then(function($result) {
      // custom event
      // _emit() comes from sharkydog/private-emitter package
      // same usage as EventEmitter::emit() (evenement/evenement)
      $this->_emit('uptime-seconds', [ $result['uptime'] ]);
    });
  }
}


// client
$client = new Shelly\Client('ws://'.CONFIG_ADDR.'/rpc', 'test-client');
$client->connect();

// set the custom device class
// all parameters after the class will be passed to the constructor
// only one in this case - uptime report interval
$client->setDeviceClass(ShellyCustomDevice::class, 10);

$shelly = $client->getDevice();
$shelly->setPassword(CONFIG_PASSWD);

$shelly->on('open', function($shelly) {
  pn('Connection opened (client)');
});
$shelly->on('close', function() {
  pn('Connection closed (client)');
});

// having fun
function secDiffToStr($s) {
  $t = [];
  if($d = floor($s / 24 / 3600)) {
    $t[] = $d.' day'.($d>1?'s':'');
    $s -= $d * 24 * 3600;
  }
  if($h = floor($s / 3600)) {
    $t[] = $h.' hour'.($h>1?'s':'');
    $s -= $h * 3600;
  }
  if($m = floor($s / 60)) {
    $t[] = $m.' minute'.($m>1?'s':'');
    $s -= $m * 60;
  }
  if($s) {
    $t[] = $s.' second'.($s>1?'s':'');
  }
  return implode(', ', $t);
}

// listen for the custom event
// first event will arrive after the interval
// set with setDeviceClass()
$shelly->on('uptime-seconds', function($uptime) use($client) {
  static $counter = 0;

  pn('Client');
  pn('Uptime: '.secDiffToStr($uptime));
  pn('Last boot: '.date('d.m.Y H:i:s', time()-$uptime));

  // close client after two events
  if(++$counter == 2) {
    $client->close();
  }
});


// server
$httpd = new HTTP\Server;
$httpd->listen('0.0.0.0', 12345);

$shellyServer = new Shelly\Server('test-server');
$httpd->route('/ws/shelly', $shellyServer);

// set the custom device class
// all parameters after the class will be passed to the constructor
// only one in this case - uptime report interval
// registerDeviceByIP() can be used the same way
$shelly = $shellyServer->registerDeviceByID(CONFIG_SHELLY_ID, ShellyCustomDevice::class, 10);

$shelly->on('open', function($shelly) {
  pn('Connection opened (server)');
});
$shelly->on('close', function() {
  pn('Connection closed (server)');
});

// listen for the custom event
// here first 'uptime-seconds' event will arrive immediately
// after server 'device' event is finished
// because of NotifyFullStatus
// next events will arrive after the interval
// set with registerDeviceByID()
$shelly->on('uptime-seconds', function($uptime) {
  pn('Server');
  pn('Uptime: '.secDiffToStr($uptime));
  pn('Last boot: '.date('d.m.Y H:i:s', time()-$uptime));
});

$shellyServer->on('device', function(Shelly\Device $shelly) {
  pn('Server spit out a Shelly device of class '.get_class($shelly));
});
