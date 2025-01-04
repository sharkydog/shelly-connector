<?php
namespace SharkyDog\Shelly;
use SharkyDog\PrivateEmitter\PrivateEmitterTrait;
use SharkyDog\HTTP\Log;
use React\Promise;
use React\EventLoop\Loop;

class Device {
  use PrivateEmitterTrait {
    PrivateEmitterTrait::on as private _PrivateEmitter_on;
    PrivateEmitterTrait::once as private _PrivateEmitter_once;
    PrivateEmitterTrait::_emit as private _PrivateEmitter_emit;
  }

  private $_bound = false;
  private $_sender;
  private $_devId;
  private $_authPasswd;
  private $_auth = [];
  private $_silenceAll = false;
  private $_silenceNext;
  private $_rawResultNext = false;
  private $_cmdSent = [];
  private $_cmdCache = [];
  private $_cmdId = 0;

  public function __destruct() {
    Log::destruct(static::class);
  }

  final public function emitter(&$emitter) {
    if($this->_bound) return;

    $emitter = function(string $event, array $args=[]) {
      $this->_PrivateEmitter_emit($event, $args);
    };

    $this->_bound = true;
  }

  private function _nextSilent() {
    if($this->_silenceNext !== null) {
      $silent = $this->_silenceNext;
      $this->_silenceNext = null;
      return $silent;
    } else {
      return $this->_silenceAll;
    }
  }

  private function _nextRaw() {
    $raw = $this->_rawResultNext;
    $this->_rawResultNext = false;
    return $raw;
  }

  private function _silencedPromise($promise, $silent) {
    return $silent ? $promise->catch(fn()=>null) : $promise;
  }

  private function _cachedPromise($key, $raw) {
    $cache = $this->_cmdCache[$key];
    $pr = $cache->promise;

    if($raw) {
      $pr = $pr->then(function() use($cache) {
        return json_decode($cache->rawRes);
      });
    }

    return $pr;
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
      'def' => $deferred ?? new Promise\Deferred,
      'raw' => null
    ];

    ($this->_sender)($cmd);
    $this->_on_command($method, $params, $this->_cmdSent[$this->_cmdId]['def']->promise());

    return $this->_cmdId;
  }

  private function _cmdCacheKey($method, $params) {
    if(!empty($params)) {
      ksort($params);
      $key = json_encode($params);
      $key = '.'.(strlen($key)>32 ? md5($key) : $key);
    } else {
      $key = '';
    }
    return $method.$key;
  }

  private function _error($cmdId, $e) {
    $deferred = $this->_cmdSent[$cmdId]['def'] ?? null;
    unset($this->_cmdSent[$cmdId]);

    if($deferred) {
      $deferred->reject($e);
    }

    $this->_emit('error', [$e]);
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

  private function _event_open(callable $sender, ?string $devId=null) {
    $this->_sender = $sender;
    $this->_devId = $devId;
    $this->_cmdId = 0;
    $this->_on_open();
  }

  private function _event_close(bool $clean=false) {
    $this->_sender = null;
    $this->clearCommandCache(null);

    foreach($this->_cmdSent as $cmd) {
      $cmd['def']->reject(new Exception\ConnClosed);
    }
    $this->_cmdSent = [];

    $this->_on_close($clean);
  }

  private function _event_message(\stdClass $msg) {
    if(!$this->_devId) {
      $this->_devId = $msg->src ?? null;
    }

    if(($cmdId = $msg->id??null)) {
      if(!isset($this->_cmdSent[$cmdId])) {
        return;
      }
      if(($err = $msg->error??null)) {
        $err = json_decode(json_encode($err),true);
        $this->_on_error_msg($cmdId, $err);
        return;
      }
      if(!($msg->result??null)) {
        return;
      }

      $msg = json_encode($msg);
      $res = json_decode($msg,true)['result'];
      $this->_cmdSent[$cmdId]['raw'] = $msg;
      $this->_cmdSent[$cmdId]['def']->resolve($res);
      unset($this->_cmdSent[$cmdId]);

      return;
    }

    if(!($method = $msg->method??null) || !($params = $msg->params??null)) {
      return;
    } else {
      $params = json_decode(json_encode($params),true);
    }

    $this->_on_notify_raw($msg);

    if($method == 'NotifyStatus') {
      $ts = $params['ts'];
      unset($params['ts']);

      $comp = array_keys($params)[0];
      $this->_on_notify_status($comp, $params[$comp], $ts);

      return;
    }

    if($method == 'NotifyFullStatus') {
      $ts = $params['ts'];
      unset($params['ts']);

      $this->_on_notify_status_full($params, $ts);

      return;
    }

    if($method == 'NotifyEvent') {
      foreach($params['events'] as $data) {
        $ts = $data['ts'];
        $comp = $data['component'];
        $event = $data['event'];

        unset($data['ts'],$data['component'],$data['event']);
        $this->_on_notify_event($event, $comp, $data, $ts);
      }

      return;
    }
  }

  protected function _emit(string $event, array $args=[]) {
    $this->_EventEmitter_emit($event, $args);
  }

  protected function _on_open() {
    $this->_emit('open', [$this]);
  }

  protected function _on_close(bool $clean=false) {
    $this->_emit('close', [$clean]);
  }

  protected function _on_command(string $method, array $params, Promise\PromiseInterface $result) {
    $this->_emit('command', [$method, $params, $result]);
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

  protected function _on_notify_raw(\stdClass $msg) {
    $this->_emit('notify-raw', [json_encode($msg)]);
  }

  private function _on($event, $listener, $once) {
    if($event == 'open') {
      if($this->connected()) {
        $listener($this);
        if($once) return;
      }
    } else if($event == 'notify-raw') {
      $listener = function(string $msg) use($listener) {
        $listener(json_decode($msg));
      };
    }
    if($once) {
      $this->_PrivateEmitter_once($event, $listener);
    } else {
      $this->_PrivateEmitter_on($event, $listener);
    }
  }

  public function on($event, callable $listener) {
    $this->_on($event, $listener, false);
  }
  public function once($event, callable $listener) {
    $this->_on($event, $listener, true);
  }

  public function connected(): bool {
    return !!$this->_sender;
  }

  public function getDevId(): ?string {
    return $this->_devId;
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

  public function silenceAll(?bool $p=null): bool {
    if($p !== null) $this->_silenceAll = $p;
    return $this->_silenceAll;
  }
  public function silenceNext(?bool $p=null): ?bool {
    if($p !== null) $this->_silenceNext = $p;
    return $this->_silenceNext;
  }
  public function isNextSilent(bool $clearNext=false): bool {
    if($clearNext) {
      return $this->_nextSilent();
    } else {
      return $this->silenceNext() ?? $this->silenceAll();
    }
  }

  public function rawResultNext(?bool $p=null): bool {
    if($p !== null) $this->_rawResultNext = $p;
    return $this->_rawResultNext;
  }

  public function sendCommand(string $method, array $params=[]): Promise\PromiseInterface {
    $silent = $this->_nextSilent();
    $raw = $this->_nextRaw();

    if(!$method) {
      return $this->_silencedPromise(Promise\reject(new Exception\Error('Method is empty', 400)), $silent);
    }
    if(!$this->_sender) {
      return $this->_silencedPromise(Promise\reject(new Exception\ConnNotConnected), $silent);
    }

    try {
      $cmdId = $this->_cmd($method, $params);
      $pr = $this->_cmdSent[$cmdId]['def']->promise();
    } catch(\Exception $e) {
      return $this->_silencedPromise(Promise\reject($e), $silent);
    }

    if($raw) {
      $pr = $pr->then(function() use($cmdId) {
        return json_decode($this->_cmdSent[$cmdId]['raw']);
      });
    }

    return $this->_silencedPromise($pr, $silent);
  }

  public function sendCommandSilent(string $method, array $params=[]): Promise\PromiseInterface {
    $this->silenceNext(true);
    return $this->sendCommand($method, $params);
  }

  public function sendCommandCached(string $method, array $params=[], int $ttl=60): Promise\PromiseInterface {
    $silent = $this->_nextSilent();
    $raw = $this->_nextRaw();

    if(!$method) {
      return $this->_silencedPromise(Promise\reject(new Exception\Error('Method is empty', 400)), $silent);
    }
    if(!$this->_sender) {
      return $this->_silencedPromise(Promise\reject(new Exception\ConnNotConnected), $silent);
    }

    $key = $this->_cmdCacheKey($method, $params);
    $cache = $this->_cmdCache[$key] ?? null;

    if($cache && $ttl < (time() - $cache->time)) {
      Loop::cancelTimer($cache->timer);
      $cache->timer = null;
      $cache = null;
      unset($this->_cmdCache[$key]);
    }

    if($cache) {
      return $this->_silencedPromise($this->_cachedPromise($key,$raw), $silent);
    }

    $ttl = max(1,$ttl);
    $cache = (object)[
      'promise' => null,
      'timer' => null,
      'time' => null,
      'cmdId' => null,
      'rawRes' => null
    ];

    $this->silenceNext(false);
    $cache->promise = $this->sendCommand($method, $params);
    $cache->timer = Loop::addTimer($ttl, function() use($key,$cache) {
      $cache->timer = null;
      unset($this->_cmdCache[$key]);
    });
    $cache->time = time();
    $cache->cmdId = $this->_cmdId;

    $this->_silencedPromise($cache->promise->then(function() use($key,$cache) {
      if($cache !== ($this->_cmdCache[$key]??null)) return;
      $cache->rawRes = $this->_cmdSent[$cache->cmdId]['raw'];
    }),true);

    $this->_cmdCache[$key] = $cache;
    Log::destruct($cache, 'Shelly command cache: '.$key);

    return $this->_silencedPromise($this->_cachedPromise($key,$raw), $silent);
  }

  public function getCommandCache(string $method, array $params=[], &$result=false): ?Promise\PromiseInterface {
    $silent = $this->_nextSilent();
    $raw = $this->_nextRaw();
    $key = $this->_cmdCacheKey($method, $params);

    if(!isset($this->_cmdCache[$key])) {
      return null;
    }

    $pr = $this->_cachedPromise($key,$raw);

    if($result !== false) {
      $result = null;
      $extractor = function($res) use(&$result) {
        $result = $res;
      };
      $pr->then($extractor,$extractor);
    }

    return $this->_silencedPromise($pr, $silent);
  }

  public function clearCommandCache(?string $method='', ?array $params=[]) {
    if($method === '') {
      return;
    }
    if($method) {
      $skey = $this->_cmdCacheKey($method, $params);
    }

    if($method && $params !== null) {
      if(!isset($this->_cmdCache[$skey])) {
        return;
      }
      $caches = [$skey => $this->_cmdCache[$skey]];
    } else {
      $caches = &$this->_cmdCache;
    }

    foreach($caches as $ckey => $cache) {
      if($method && $params === null && strpos($ckey,$skey) !== 0) {
        continue;
      }
      Loop::cancelTimer($cache->timer);
      $cache->timer = null;
      unset($this->_cmdCache[$ckey]);
    }
  }
}
