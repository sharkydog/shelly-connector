<?php
namespace SharkyDog\Shelly;
use SharkyDog\PrivateEmitter\PrivateEmitterTrait;
use SharkyDog\HTTP\Log;
use React\Promise;

class Device {
  use PrivateEmitterTrait {
    PrivateEmitterTrait::on as private _PrivateEmitter_on;
    PrivateEmitterTrait::once as private _PrivateEmitter_once;
  }

  private $_bound = false;
  private $_sender;
  private $_authPasswd;
  private $_auth = [];
  private $_cmdSent = [];
  private $_cmdId = 0;

  public function __destruct() {
    Log::destruct(static::class);
  }

  final public function emitter(&$emitter) {
    if($this->_bound) return;
    $emitter = $this->_emitter();
    $this->_bound = true;
  }

  private function _cmd($method, $params=[], $deferred=null) {
    $noauth = empty($this->_auth) || ($method == 'Shelly.GetDeviceInfo');
    $noauth = $noauth || !isset($this->_auth['auth']['response']);

    if(!$noauth && $this->_auth['fail']) {
      $e = new Exception\AuthFailed;
      $this->_error(-1, $e);
      throw $e;
    }

    $cmd = [
      'id' => ++$this->_cmdId,
      'method' => $method
    ];

    if(!empty($params)) {
      $cmd['params'] = $params;
    }
    if(!$noauth) {
      $cmd['auth'] = $this->_auth['auth'];
    }

    $this->_cmdSent[$this->_cmdId] = [
      'cmd' => $cmd,
      'def' => $deferred ?? new Promise\Deferred
    ];
    ($this->_sender)($cmd);

    return $this->_cmdId;
  }

  private function _error($cmdId, $e) {
    $deferred = $this->_cmdSent[$cmdId]['def'] ?? null;
    unset($this->_cmdSent[$cmdId]);

    if($deferred) {
      $deferred->reject($e);
    }

    $this->_emit('error', [$e->getMessage(),$e->getCode()]);
  }

  private function _on_error_msg($cmdId, $errMsg) {
    $code = $errMsg['code'];

    if($code == 401) {
      if(!($authFailed = $this->_auth['fail'] ?? false)) {
        $authMsg = json_decode($errMsg['message'], true);
        $cmd = $this->_cmdSent[$cmdId]['cmd'];
        $authFailed = ($cmd['auth']['nonce'] ?? 0) == $authMsg['nonce'];
      }
      if($authFailed) {
        $this->_auth['fail'] = true;
        $this->_error($cmdId, new Exception\AuthFailed);
        return;
      }

      if(($this->_auth['auth']['nonce'] ?? 0) != $authMsg['nonce']) {
        $this->_auth['fail'] = false;
        $this->_auth['nc'] = $authMsg['nc'];

        $this->_auth['auth'] = [];
        $this->_auth['auth']['realm'] = $authMsg['realm'];
        $this->_auth['auth']['nonce'] = $authMsg['nonce'];

        if($this->_authPasswd) {
          $this->_authDigest();
        }
      }

      if(!$this->_authPasswd) {
        $this->_error($cmdId, new Exception\AuthRequired);
        return;
      }

      $deferred = $this->_cmdSent[$cmdId]['def'];
      unset($this->_cmdSent[$cmdId]);

      try {
        $this->_cmd($cmd['method'], $cmd['params']??[], $deferred);
      } catch(\Exception $e) {
        $this->_error(-1, $e);
        return;
      }

      return;
    }

    $this->_error($cmdId, new Exception\Error($errMsg['message'], $code));
  }

  private function _authDigest() {
    $this->_auth['auth']['username'] = $user = 'admin';
    $this->_auth['auth']['cnonce'] = $cnonce = unpack('L',random_bytes(4))[1];
    $this->_auth['auth']['algorithm'] = 'SHA-256';

    $realm = $this->_auth['auth']['realm'];
    $nonce = $this->_auth['auth']['nonce'];
    $nc = $this->_auth['nc'];

    $ha1 = hash('sha256', implode(':',[$user,$realm,$this->_authPasswd]));
    $ha2 = hash('sha256', 'dummy_method:dummy_uri');
    $res = hash('sha256', implode(':',[$ha1,$nonce,$nc,$cnonce,'auth',$ha2]));

    $this->_auth['auth']['response'] = $res;
  }

  private function _event_open(callable $sender) {
    $this->_sender = $sender;
    $this->_cmdId = 0;
    $this->_on_open();
  }

  private function _event_close() {
    $this->_sender = null;
    foreach($this->_cmdSent as $cmd) {
      $cmd['def']->reject(new Exception\ConnClosed);
    }
    $this->_cmdSent = [];
    $this->_on_close();
  }

  private function _event_message(array $msg) {
    if(isset($msg['id'])) {
      $cmdId = $msg['id'];

      if(!isset($this->_cmdSent[$cmdId])) {
        return;
      }
      if(isset($msg['error'])) {
        $this->_on_error_msg($cmdId, $msg['error']);
        return;
      }

      $this->_cmdSent[$cmdId]['def']->resolve($msg['result']);
      unset($this->_cmdSent[$cmdId]);

      return;
    }

    if(!isset($msg['method'])) {
      return;
    }

    if($msg['method'] == 'NotifyStatus') {
      $ts = $msg['params']['ts'];
      unset($msg['params']['ts']);

      $comp = array_keys($msg['params'])[0];
      $this->_on_notify_status($comp, $msg['params'][$comp], $ts);

      return;
    }

    if($msg['method'] == 'NotifyFullStatus') {
      $ts = $msg['params']['ts'];
      unset($msg['params']['ts']);

      $this->_on_notify_status_full($msg['params'], $ts);

      return;
    }

    if($msg['method'] == 'NotifyEvent') {
      foreach($msg['params']['events'] as $data) {
        $ts = $data['ts'];
        $comp = $data['component'];
        $event = $data['event'];

        unset($data['ts'],$data['component'],$data['event']);
        $this->_on_notify_event($event, $comp, $data, $ts);
      }
      return;
    }
  }

  protected function _on_open() {
    $this->_emit('open', [$this]);
  }

  protected function _on_close() {
    $this->_emit('close');
  }

  protected function _on_notify_status(string $comp, array $data, float $time) {
    $this->_emit('notify-status', [$comp, $data, $time]);
  }

  protected function _on_notify_status_full(array $data, float $time) {
    $this->_emit('notify-status-full', [$data, $time]);
  }

  protected function _on_notify_event(string $event, string $comp, array $data, float $time) {
    $this->_emit('notify-event', [$event, $comp, $data, $time]);
  }

  public function on($event, callable $listener) {
    if($event == 'open' && $this->connected()) $listener($this);
    $this->_PrivateEmitter_on($event, $listener);
  }
  public function once($event, callable $listener) {
    if($event == 'open' && $this->connected()) $listener($this);
    else $this->_PrivateEmitter_once($event, $listener);
  }

  public function connected(): bool {
    return !!$this->_sender;
  }

  public function setPassword(string $password) {
    $this->_authPasswd = $password;

    if($password) {
      if(!empty($this->_auth)) {
        $this->_auth['fail'] = false;
        $this->_authDigest();
      }
    } else {
      $this->_auth = [];
    }
  }

  public function sendCommand(string $method, array $params=[]): Promise\PromiseInterface {
    if(!$this->_sender) {
      return Promise\reject(new Exception\ConnNotConnected);
    }

    try {
      $cmdId = $this->_cmd($method, $params);
    } catch(\Exception $e) {
      return Promise\reject($e);
    }

    return $this->_cmdSent[$cmdId]['def']->promise();
  }

  public function sendCommandSilent(string $method, array $params=[]): Promise\PromiseInterface {
    return $this->sendCommand($method, $params)->catch(fn()=>null);
  }
}
