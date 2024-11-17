<?php
namespace SharkyDog\Shelly;
use SharkyDog\HTTP\WebSocket as WS;
use SharkyDog\PrivateEmitter\PrivateEmitterTrait;

class Server extends WS\Handler {
  use PrivateEmitterTrait;
  protected static $protocols = ['json-rpc'];

  private $_src;
  private $_devices = [];

  public function __construct(string $src) {
    $this->_src = $src;
  }

  protected function wsOpen(WS\Connection $conn) {
    $conn->attr->dev = null;
  }

  protected function wsMsg(WS\Connection $conn, string $data) {
    if(!($msg = json_decode($data,true))) {
      return;
    }

    $dev = $conn->attr->dev;

    if(!$dev && ($msg['method']??'') == 'NotifyFullStatus') {
      $id = $msg['src'];
      $ip = $conn->remoteAddr;

      $conn->attr->dev = $this->_devices['ID:'.$id] ?? $this->_devices['IP:'.$ip] ?? null;
      $dev = $conn->attr->dev;

      $sender = function($msg) use($conn) {
        $msg['src'] = $this->_src;
        $conn->send(json_encode($msg));
      };
      $registered = !!$dev;

      if(!$registered) {
        $conn->attr->dev = (object)[];
        $dev = $conn->attr->dev;

        $dev->emitter = null;
        $dev->device = new Device();
        $dev->device->emitter($dev->emitter);
      }

      ($dev->emitter)('open', [$sender]);
      $this->_emit('device', [$dev->device, $registered]);
    }

    if(!$dev) {
      return;
    }

    ($dev->emitter)('message', [$msg]);
  }

  protected function wsClose(WS\Connection $conn) {
    if(!($dev = $conn->attr->dev)) {
      return;
    }

    $emitter = $dev->emitter;
    $conn->attr->dev = null;

    $emitter('close');

    if(!is_subclass_of($dev->device, Device::class)) {
      $dev->device->removeAllListeners();
    }
  }

  private function _registerDevice($key, $class, $args) {
    if($class && !is_subclass_of($class, Device::class)) {
      throw new \Exception('Shelly device class '.$class.' is not a subclass of '.Device::class);
    }

    $class = $class ?: Device::class;
    $dev = $this->_devices[$key] = (object)[];
    $dev->device = new $class(...$args);
    $dev->emitter = null;
    $dev->device->emitter($dev->emitter);

    return $dev->device;
  }

  public function registerDeviceByID(string $id, ?string $class=null, ...$args): Device {
    return $this->_registerDevice('ID:'.$id, $class, $args);
  }

  public function registerDeviceByIP(string $ip, ?string $class=null, ...$args): Device {
    return $this->_registerDevice('IP:'.$ip, $class, $args);
  }
}
