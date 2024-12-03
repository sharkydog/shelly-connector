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

  protected static $componentType = [];
  protected static $componentLoad = [];
  protected static $componentArgs = [];
  protected static $componentLock = true;

  private $_bound = false;
  private $_sender;
  private $_authPasswd;
  private $_auth = [];
  private $_silenceAll = false;
  private $_silenceNext;
  private $_cmdSent = [];
  private $_cmdCache = [];
  private $_cmdId = 0;
  private $_comp = [];

  public function __construct() {
    foreach(static::$componentLoad as $key) {
      if(isset($this->_comp[$key])) continue;
      $this->_compLoad($key);
    }
  }

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

  private function _compLoad($key) {
    $class = static::$componentType[$key] ?? null;
    $re = preg_match('/^([^\:]+)(?:\:(\d+))?$/',$key,$m);

    if(!$class && $re) {
      $class = static::$componentType[$m[1]] ?? null;
    }

    if(!$class || !is_subclass_of($class, Component::class)) {
      return null;
    }

    $comp = new $class(...(static::$componentArgs[$key] ?? []));
    return $this->addComponent($comp, $m[2] ?? null) ? $comp : null;
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

  private function _silencedPromise($promise, $silent) {
    return $silent ? $promise->catch(fn()=>null) : $promise;
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

  private function _event_open(callable $sender, array $stat=[]) {
    $this->_sender = $sender;
    $this->_cmdId = 0;

    $this->_on_open();

    if(empty($this->_comp)) {
      return;
    }

    $loadStatus = false;
    $loadConfig = false;
    $promises = [];

    foreach($this->_comp as $key => $obj) {
      ($obj->emitter)('open');

      $status = $stat[$key] ?? null;
      $wantStatus = $obj->comp->statusUpdates();
      $wantConfig = $obj->comp->configUpdates();

      if((!$wantStatus || $status!==null) && !$wantConfig) {
        $obj->init = true;
        ($obj->emitter)('init', [$wantStatus?$status:[], []]);
      }

      $loadStatus = $loadStatus ?: ($wantStatus && $status===null);
      $loadConfig = $loadConfig ?: $wantConfig;
    }

    if($loadStatus) {
      $this->silenceNext(false);
      $promises['stat'] = $this->sendCommandCached('Shelly.GetStatus');
    }
    if($loadConfig) {
      $this->silenceNext(false);
      $promises['conf'] = $this->sendCommandCached('Shelly.GetConfig');
    }

    if(empty($promises)) {
      return;
    }

    Promise\all($promises)->then(function($res) use(&$stat) {
      foreach($this->_comp as $key => $obj) {
        if($obj->init) continue;
        $status = $obj->comp->statusUpdates() ? ($res['stat'][$key] ?? $stat[$key] ?? []) : [];
        $config = $obj->comp->configUpdates() ? ($res['conf'][$key] ?? []) : [];
        $obj->init = true;
        ($obj->emitter)('init', [$status, $config]);
      }
    })->catch(function($e) {
      $keys = [];
      foreach($this->_comp as $key => $obj) {
        if($obj->init) continue;
        $keys[] = $key;
        ($obj->emitter)('error', [new Exception\Error('Component init failed ('.$key.')')]);
      }
      $this->_error(-1, new Exception\Error('Components init failed ('.implode(',',$keys).')'));
    });
  }

  private function _event_close() {
    $this->_sender = null;
    $this->clearCommandCache(null);

    foreach($this->_cmdSent as $cmd) {
      $cmd['def']->reject(new Exception\ConnClosed);
    }
    $this->_cmdSent = [];

    foreach($this->_comp as $obj) {
      $obj->init = false;
      ($obj->emitter)('close');
    }

    $this->_on_close();
  }

  private function _event_message(array $msg, bool $sts=false) {
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

      $key = array_keys($msg['params'])[0];

      if(isset($this->_comp[$key]) && $msg['params'][$key] !== null) {
        ($this->_comp[$key]->emitter)('status', [$msg['params'][$key], $ts, false]);
      }

      $this->_on_notify_status($key, $msg['params'][$key], $ts);

      return;
    }

    if($msg['method'] == 'NotifyFullStatus') {
      $ts = $msg['params']['ts'];
      unset($msg['params']['ts']);

      if(!$sts) {
        foreach($this->_comp as $key => $obj) {
          if(!isset($msg['params'][$key])) continue;
          ($obj->emitter)('status', [$msg['params'][$key], $ts, true]);
        }
      }

      $this->_on_notify_status_full($msg['params'], $ts);

      return;
    }

    if($msg['method'] == 'NotifyEvent') {
      foreach($msg['params']['events'] as $data) {
        $ts = $data['ts'];
        $key = $data['component'];
        $event = $data['event'];
        unset($data['ts'],$data['component'],$data['event']);

        if(isset($this->_comp[$key])) {
          ($this->_comp[$key]->emitter)('event', [$event, $data, $ts]);
        }

        if($key == 'sys') {
          if($event == 'component_removed' && isset($this->_comp[$data['target']])) {
            $_comp = $this->_comp[$data['target']];
            $_comp_id = $_comp->comp->getId();
            $this->removeComponent($_comp->comp->getKey());
            ($_comp->emitter)('delete', [$this, $_comp_id]);
          }
        }

        $this->_on_notify_event($event, $key, $data, $ts);
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

  public function onceOpened(): Promise\PromiseInterface {
    if($this->connected()) {
      return Promise\resolve($this);
    }

    $deferred = new Promise\Deferred;
    $this->once('open', fn($dev)=>$deferred->resolve($dev));

    return $deferred->promise();
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

  public function addComponent(Component $comp, ?int $id=-1): ?Component {
    if($id != -1) {
      $comp->setId($id);
    }

    $key = $comp->getKey();

    if(isset($this->_comp[$key])) {
      return null;
    }
    if(!$comp->bind($this, $emitter)) {
      return null;
    }

    $this->_comp[$key] = (object)[
      'init' => false,
      'comp' => $comp,
      'emitter' => $emitter
    ];

    $emitter('bind');

    if(!$this->connected()) {
      return $comp;
    }

    $emitter('open');

    $wantStatus = $comp->statusUpdates();
    $wantConfig = $comp->configUpdates();

    if(!$wantStatus && !$wantConfig) {
      $this->_comp[$key]->init = true;
      ($this->_comp[$key]->emitter)('init', [[],[]]);
      return true;
    }

    $promises = [];
    $params = ($id=$comp->getId())===null ? [] : ['id'=>$id];

    $silenceAll = $this->silenceAll();
    $this->silenceAll(false);
    $this->silenceNext(false);

    if($wantStatus) {
      $pr = $this->getCommandCache('Shelly.GetStatus');
      $pr = $pr ?? $this->sendCommand($comp->getMethod('GetStatus'), $params);
      $promises['stat'] = $pr;
    }
    if($wantConfig) {
      $pr = $this->getCommandCache('Shelly.GetConfig');
      $pr = $pr ?? $this->sendCommand($comp->getMethod('GetConfig'), $params);
      $promises['conf'] = $pr;
    }

    $this->silenceAll($silenceAll);

    Promise\all($promises)->then(function($res) use($key,$comp) {
      $status = $comp->statusUpdates() ? ($res['stat'][$key] ?? []) : [];
      $config = $comp->configUpdates() ? ($res['conf'][$key] ?? []) : [];
      $this->_comp[$key]->init = true;
      ($this->_comp[$key]->emitter)('init', [$status, $config]);
    })->catch(function($e) use($key) {
      $e = new Exception\Error('Component init failed ('.$key.')');
      ($this->_comp[$key]->emitter)('error', [$e]);
      $this->_error(-1, $e);
    });

    return $comp;
  }

  public function getComponent(string $key): ?Component {
    if($comp = $this->_comp[$key]->comp ?? null) {
      return $comp;
    }
    if(!in_array($key,static::$componentLoad)) {
      return null;
    }
    if($comp = $this->_compLoad($key)) {
      return $comp;
    }
    else {
      return null;
    }
  }

  public function removeComponent(string $key) {
    if(static::$componentLock && in_array($key,static::$componentLoad)) {
      return;
    }
    if(!($obj = $this->_comp[$key])) {
      return;
    }
    unset($this->_comp[$key]);
    ($obj->emitter)('remove', [$this, $obj->comp->getId()]);
  }

  public function removeAllComponents() {
    foreach(array_keys($this->_comp) as $key) {
      $this->removeComponent($key);
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

  public function sendCommand(string $method, array $params=[]): Promise\PromiseInterface {
    $silent = $this->_nextSilent();

    if(!$method) {
      return $this->_silencedPromise(Promise\reject(new Exception\Error('Method is empty', 400)), $silent);
    }
    if(!$this->_sender) {
      return $this->_silencedPromise(Promise\reject(new Exception\ConnNotConnected), $silent);
    }

    try {
      $cmdId = $this->_cmd($method, $params);
    } catch(\Exception $e) {
      return $this->_silencedPromise(Promise\reject($e), $silent);
    }

    return $this->_silencedPromise($this->_cmdSent[$cmdId]['def']->promise(), $silent);
  }

  public function sendCommandSilent(string $method, array $params=[]): Promise\PromiseInterface {
    $this->silenceNext(true);
    return $this->sendCommand($method, $params);
  }

  public function sendCommandCached(string $method, array $params=[], int $ttl=60): Promise\PromiseInterface {
    $silent = $this->_nextSilent();

    if(!$method) {
      return $this->_silencedPromise(Promise\reject(new Exception\Error('Method is empty', 400)), $silent);
    }
    if(!$this->_sender) {
      return $this->_silencedPromise(Promise\reject(new Exception\ConnNotConnected), $silent);
    }

    $key = $this->_cmdCacheKey($method, $params);

    if(isset($this->_cmdCache[$key])) {
      return $this->_silencedPromise($this->_cmdCache[$key]->promise, $silent);
    }

    $ttl = max(1,$ttl);
    $cache = (object)[
      'promise' => null,
      'timer' => null
    ];

    $this->silenceNext(false);
    $cache->promise = $this->sendCommand($method, $params);
    $cache->timer = Loop::addTimer($ttl, function() use($key,$cache) {
      $cache->timer = null;
      unset($this->_cmdCache[$key]);
    });

    $this->_cmdCache[$key] = $cache;
    Log::destruct($cache, 'Shelly command cache: '.$key);

    return $this->_silencedPromise($cache->promise, $silent);
  }

  public function getCommandCache(string $method, array $params=[], &$result=false): ?Promise\PromiseInterface {
    $silent = $this->_nextSilent();
    $key = $this->_cmdCacheKey($method, $params);

    if(!($cache = $this->_cmdCache[$key] ?? null)) {
      return null;
    }

    if($result !== false) {
      $result = null;
      $extractor = function($res) use(&$result) {
        $result = $res;
      };
      $cache->promise->then($extractor,$extractor);
    }

    return $this->_silencedPromise($cache->promise, $silent);
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

  public function getStatus(bool $update=false): Promise\PromiseInterface {
    $promise = $this->sendCommand('Shelly.GetStatus');

    if($update) {
      $promise->then(function($res) {
        if(!$res) return;
        $ts = time();
        foreach($this->_comp as $key => $obj) {
          if(!isset($res[$key])) continue;
          ($obj->emitter)('status', [$res[$key], $ts, true]);
        }
      })->catch(fn()=>null);
    }

    return $promise;
  }

  public function getConfig(): Promise\PromiseInterface {
    return $this->sendCommand('Shelly.GetConfig');
  }
}
