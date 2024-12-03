<?php
namespace SharkyDog\Shelly;
use SharkyDog\Shelly\Device;
use SharkyDog\PrivateEmitter\PrivateEmitterTrait;
use React\Promise;

abstract class Component {
  use PrivateEmitterTrait {
    PrivateEmitterTrait::_emit as private _PrivateEmitter_emit;
  }

  protected static $namespaceUC;
  protected static $namespaceLC;

  protected $statusUpdates = false;
  protected $statusUpdatesFull = false;
  protected $configUpdates = false;

  protected $status = [];
  protected $config = [];

  private $_device;
  private $_id;
  private $_init = false;
  private $_ready = null;

  final public function bind(Device $device, &$emitter): bool {
    if($this->_device) {
      return false;
    }

    $this->_device = $device;
    $emitter = function(string $event, array $args=[]) {
      $this->_PrivateEmitter_emit($event, $args);
    };

    if($this->_ready instanceOf \Throwable) {
      $this->_ready = null;
    }

    return true;
  }

  private function _event_bind() {
    $this->_on_bind();
  }

  private function _event_open() {
    if($this->_ready instanceOf \Throwable) {
      $this->_ready = null;
    }
    $this->_on_open();
  }

  private function _event_init($status, $config) {
    if($this->statusUpdates) {
      $this->status = array_replace($this->status, $status);
    }
    if($this->configUpdates) {
      $this->config = array_replace($this->config, $config);
    }
    $this->_init = null;
    $this->_on_init();
  }

  private function _event_close() {
    $this->_init = null;
    $this->_on_close();
    $this->status = [];
    $this->config = [];
  }

  private function _event_remove($device, $id) {
    $this->_device = null;
    $this->_init = null;
    $this->_init(new Exception\Error('Component removed'));
    $this->_on_remove($device, $id);
    $this->status = [];
    $this->config = [];
  }

  private function _event_delete($device, $id) {
    $this->_on_delete($device, $id);
  }

  private function _event_error($e) {
    $this->_init = null;
    $this->_on_error($e);
    $this->_init($e);
  }

  private function _event_status($status, $time, $full) {
    $this->_on_status($status, $time, $full);

    if($this->statusUpdates) {
      $this->status = array_replace($this->status, $status);
    }

    if(!$full && $this->statusUpdatesFull) {
      $this->getStatus(true);
    }
  }

  private function _event_event($event, $data, $time) {
    $this->_on_event($event, $data, $time);

    if($this->configUpdates && $event == 'config_changed') {
      $this->getConfig()->then(function($config) {
        if(!$config) return;
        $this->_on_config($config);
        $this->config = array_replace($this->config, $config);
      })->catch(fn()=>null);
    }
  }

  protected function _emit(string $event, array $args=[]) {
    $this->_EventEmitter_emit($event, $args);
  }

  protected function _init(?\Throwable $msg=null) {
    if($this->_init !== null) {
      return;
    }

    if(!$msg) {
      if(!$this->_device) {
        $msg = new Exception\Error('Device invalid');
      } else if(!$this->_device->connected()) {
        $msg = new Exception\Error('Connection closed');
      }
    }

    if($this->_ready instanceOf Promise\Deferred) {
      if($msg) {
        $this->_ready->reject($msg);
        $this->_ready = $msg;
        $this->_init = false;
      } else {
        $this->_ready->resolve($this);
        $this->_ready = null;
        $this->_init = true;
      }
    } else if($msg) {
      $this->_ready = $msg;
      $this->_init = false;
    } else {
      $this->_ready = null;
      $this->_init = true;
    }

    if($this->_init) {
      $this->_emit('init');
    }
  }

  protected function _on_bind() {
    $this->_emit('bind');
  }

  protected function _on_open() {
    $this->_emit('open');
  }

  protected function _on_init() {
    $this->_init();
  }

  protected function _on_close() {
    $this->_emit('close');
  }

  protected function _on_remove(Device $device, ?int $id) {
    $this->_emit('remove', [$device, $id]);
  }

  protected function _on_delete(Device $device, ?int $id) {
    $this->_emit('delete', [$device, $id]);
  }

  protected function _on_error(\Throwable $e) {
    $this->_emit('error', [$e]);
  }

  protected function _on_status(array $status, float $time, bool $full) {
    $this->_emit('status', [$status, $time, $full]);
  }

  protected function _on_config(array $config) {
    $this->_emit('config', [$config]);
  }

  protected function _on_event(string $event, array $data, float $time) {
    $this->_emit('event', [$event, $data, $time]);
  }

  protected function _cmd(string $method, array $params=[], int $cacheTTL=0, ?bool $silent=null): Promise\PromiseInterface {
    if(!$this->_device) {
      if($silent) {
        return Promise\resolve(null);
      } else {
        return Promise\reject(new Exception\Error('Device invalid'));
      }
    }

    if($silent !== null) {
      $this->_device->silenceNext($silent);
    }

    if($cacheTTL) {
      return $this->_device->sendCommandCached($method, $params, $cacheTTL<0 ? 60 : $cacheTTL);
    } else {
      return $this->_device->sendCommand($method, $params);
    }
  }

  public function getNS(bool $uc): string {
    if($uc) {
      return static::$namespaceUC ?: (new \ReflectionClass($this))->getShortName();
    } else {
      return static::$namespaceLC ?: strtolower($this->getNS(true));
    }
  }

  public function getMethod(string $method): string {
    return $this->getNS(true).'.'.$method;
  }

  public function getDevice(): ?Device {
    return $this->_device;
  }

  public function setId(?int $id) {
    if($this->_device) return;
    $this->_id = $id ?? -1;
  }

  public function getId(): ?int {
    return $this->_id == -1 ? null : $this->_id;
  }

  public function getKey(): string {
    return $this->getNS(false).($this->_id!=-1 ? ':'.$this->_id : '');
  }

  public function statusUpdates(?bool $p=null): bool {
    if($p !== null) $this->statusUpdates = $p;
    if($p === false) $this->status = [];
    return !!$this->statusUpdates;
  }

  public function statusUpdatesFull(?bool $p=null): bool {
    if($p !== null) $this->statusUpdatesFull = $p;
    return !!$this->statusUpdatesFull;
  }

  public function configUpdates(?bool $p=null): bool {
    if($p !== null) $this->configUpdates = $p;
    if($p === false) $this->config = [];
    return !!$this->configUpdates;
  }

  public function getStatus(bool $update=false): Promise\PromiseInterface {
    $params = ($id=$this->getId())===null ? [] : ['id'=>$id];
    $promise = $this->_cmd($this->getMethod('GetStatus'), $params);

    if($update) {
      $promise->then(function($status) {
        if(!$status) return;
        $this->_event_status($status, time(), true);
      })->catch(fn()=>null);
    }

    $promise->catch(function($e) {
      $this->_on_error($e);
    });

    return $promise;
  }

  public function getConfig(): Promise\PromiseInterface {
    $params = ($id=$this->getId())===null ? [] : ['id'=>$id];
    $promise = $this->_cmd($this->getMethod('GetConfig'), $params);

    $promise->catch(function($e) {
      $this->_on_error($e);
    });

    return $promise;
  }

  public function connected(): bool {
    return $this->_device && $this->_device->connected();
  }

  public function ready(bool $silent=true): Promise\PromiseInterface {
    if($this->_init === true) {
      return Promise\resolve($this);
    }

    if(!$this->_device) {
      $promise = Promise\reject(new Exception\Error('Device invalid'));
    } else if($this->_ready instanceOf \Throwable) {
      $promise = Promise\reject($this->_ready);
    } else if(!$this->_ready) {
      $this->_ready = new Promise\Deferred;
      $promise = $this->_ready->promise();
    } else {
      $promise = $this->_ready->promise();
    }

    return $silent ? $promise->catch(fn()=>null) : $promise;
  }

  public function status(): array {
    return $this->status;
  }

  public function config(): array {
    return $this->config;
  }
}
