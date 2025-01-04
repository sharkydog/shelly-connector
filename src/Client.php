<?php
namespace SharkyDog\Shelly;
use SharkyDog\HTTP\Helpers\WsClientDecorator;
use React\Promise;

class Client extends WsClientDecorator {
  private $_src;
  private $_device;
  private $_emitter;

  public function __construct(string $url, string $src, array $headers=[]) {
    parent::__construct($url, $headers);
    $this->_src = $src;
    $this->ws->reconnect(2);
  }

  protected function _event_open() {
    $this->getDevice();

    $sender = function($msg) {
      $msg['src'] = $this->_src;
      $this->ws->send(json_encode($msg));
    };

    ($this->_emitter)('open', [$sender]);
    $this->_emit('open', [$this->_device]);
  }

  protected function _event_close($reconnect) {
    if($this->_emitter) {
      ($this->_emitter)('close');
    }
    $this->_emit('close', [$reconnect]);
  }

  protected function _event_message($json) {
    if(!($msg = json_decode($json))) return;
    ($this->_emitter)('message', [$msg]);
  }

  private function _getDevice($class, $args) {
    $class = $class ?: Device::class;
    $this->_device = new $class(...$args);
    $this->_device->emitter($this->_emitter);
    return $this->_device;
  }

  public function send() {
  }

  public function on($event, callable $listener) {
    if($event == 'open' && $this->ws->connected()) $listener($this->_device);
    parent::on($event, $listener);
  }
  public function once($event, callable $listener) {
    if($event == 'open' && $this->ws->connected()) $listener($this->_device);
    else parent::once($event, $listener);
  }

  public function setDeviceClass(string $class, ...$args) {
    if($this->_device) {
      return;
    }
    if(!is_subclass_of($class, Device::class)) {
      throw new \Exception('Shelly device class '.$class.' is not a subclass of '.Device::class);
    }
    $this->_getDevice($class, $args);
  }

  public function getDevice(): Device {
    return $this->_device ?: $this->_getDevice(Device::class, []);
  }

  public function onceOpened(): Promise\PromiseInterface {
    if($this->ws->connected()) {
      return Promise\resolve($this->_device);
    }

    $deferred = new Promise\Deferred;
    $this->once('open', fn($dev)=>$deferred->resolve($dev));
    $this->once('error-connect', fn($e)=>$deferred->reject($e));

    return $deferred->promise();
  }
}
