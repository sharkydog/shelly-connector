<?php
namespace SharkyDog\Shelly;
use SharkyDog\HTTP\WebSocket as WS;
use SharkyDog\PrivateEmitter\PrivateEmitterTrait;
use React\EventLoop\Loop;

class Server extends WS\Handler {
  use PrivateEmitterTrait;
  protected static $protocols = ['json-rpc'];

  private $_requireStat = true;
  private $_dropNoStat = false;
  private $_dropNoSrc = false;
  private $_onlyKnown = false;
  private $_deviceTmo = 0;

  private $_src;
  private $_devices = [];

  public function __construct(string $src) {
    $this->_src = $src;
  }

  protected function wsOpen(WS\Connection $conn) {
    $conn->attr->dev = null;
    $conn->attr->timer = null;

    if($this->_deviceTmo) {
      $conn->attr->timer = Loop::addTimer($this->_deviceTmo, function() use($conn) {
        $conn->attr->timer = null;
        $conn->close();
      });
    }
  }

  protected function wsMsg(WS\Connection $conn, string $json) {
    if(!($msg = json_decode($json))) return;
    $src = $msg->src ?? null;
    $dev = $conn->attr->dev;

    if(!$src) {
      if(!$dev && $this->_dropNoSrc) $conn->close();
      return;
    }

    if(!$dev) {
      $stat = ($msg->method??'') == 'NotifyFullStatus' ? ($msg->params??null) : null;

      if($this->_requireStat && empty($stat)) {
        if($this->_dropNoStat) $conn->close();
        return;
      }

      $ip = $conn->remoteAddr;
      $conn->attr->dev = $this->_devices['ID:'.$src] ?? $this->_devices['IP:'.$ip] ?? null;
      $dev = $conn->attr->dev;
      $known = !!$dev;

      if(!$known) {
        if($this->_onlyKnown) {
          $conn->close();
          return;
        }

        $conn->attr->dev = (object)[];
        $dev = $conn->attr->dev;

        $dev->known = false;
        $dev->device = new Device();
        $dev->emitter = null;
        $dev->device->emitter($dev->emitter);
      }

      if($conn->attr->timer) {
        Loop::cancelTimer($conn->attr->timer);
        $conn->attr->timer = null;
      }

      $sender = function($msg) use($conn) {
        $msg['src'] = $this->_src;
        $conn->send(json_encode($msg));
      };

      $stat = json_decode(json_encode($stat),true);
      ($dev->emitter)('open', [$sender,$src]);
      $this->_emit('device', [$dev->device, $known, $conn, $stat]);
    }

    try {
      ($dev->emitter)('message', [$msg]);
    } catch(\Throwable $e) {
      ($dev->emitter)('error', [$e]);
      $conn->close();
    }
  }

  protected function wsClose(WS\Connection $conn) {
    if($conn->attr->timer) {
      Loop::cancelTimer($conn->attr->timer);
      $conn->attr->timer = null;
    }
    if(!($dev = $conn->attr->dev)) {
      return;
    }

    $conn->attr->dev = null;
    ($dev->emitter)('close', [!$dev->known]);

    if(!$dev->known) {
      $dev->device->removeAllListeners();
    }
  }

  private function _registerDevice($key, $class, $args) {
    if($class && !is_subclass_of($class, Device::class)) {
      throw new \Exception('Shelly device class '.$class.' is not a subclass of '.Device::class);
    }

    $class = $class ?: Device::class;
    $dev = $this->_devices[$key] = (object)[];
    $dev->known = true;
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

  public function requireStatus(bool $p) {
    $this->_requireStat = $p;
  }
  public function dropNoStatus(bool $p) {
    $this->_dropNoStat = $p;
  }
  public function dropNoSource(bool $p) {
    $this->_dropNoSrc = $p;
  }
  public function onlyRegistered(bool $p) {
    $this->_onlyKnown = $p;
  }
  public function noDeviceTimeout(int $p) {
    $this->_deviceTmo = max(0,$p);
  }
}
