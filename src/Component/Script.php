<?php
namespace SharkyDog\Shelly\Component;
use SharkyDog\Shelly\Component;
use SharkyDog\Shelly\Device;
use SharkyDog\Shelly\Exception;
use React\Promise;

class Script extends Component {
  protected static $namespaceUC = 'Script';
  protected static $namespaceLC = 'script';

  protected $statusUpdates = true;
  protected $configUpdates = true;

  public static function findByName(Device $device, string $name, bool $add=true): Promise\PromiseInterface {
    $silent = $device->isNextSilent(true);

    $device->silenceNext(false);
    $pr = $device->sendCommandCached('Script.List');

    $pr = $pr->then(function($res) use($name) {
      foreach($res['scripts'] as $script) {
        if($script['name'] != $name) continue;
        return $script;
      }
      throw new Exception\Error('Script "'.$name.'" not found');
    });

    $pr = $pr->then(function($script) use($device,$add) {
      $that = $device->getComponent('script:'.$script['id']);

      if($that && !($that instanceOf self)) {
        throw new Exception\Error('Component "script:'.$script['id'].'" is not instance of '.self::class);
      }
      if($that) {
        return $that;
      }

      $that = new static;
      $that->statusUpdates = false;
      $that->configUpdates = false;

      $that->setId($script['id']);
      $that->status = ['running'=>$script['running']];
      $that->config = ['name'=>$script['name'],'enable'=>$script['enable']];

      if($add) {
        $device->addComponent($that);
      }

      return $that;
    });

    if($silent) {
      $pr = $pr->catch(fn()=>null);
    }

    return $pr;
  }

  protected function _on_init() {
    $this->statusUpdates = true;
    $this->configUpdates = true;
    $this->_init();
  }

  protected function _on_status(array $status, float $time, bool $full) {
    parent::_on_status($status, $time, $full);

    if(isset($status['running']) && $status['running'] != ($this->status['running']??false)) {
      $this->_emit($status['running'] ? 'start' : 'stop');
    }
  }

  protected function _on_config(array $config) {
    parent::_on_config($config);

    if($config['name'] != ($this->config['name']??'')) {
      $this->_emit('rename', [$config['name']]);
    }

    if($config['enable'] != ($this->config['enable']??false)) {
      $this->_emit($config['enable'] ? 'enable' : 'disable');
    }
  }

  public function getName(): string {
    return $this->config['name'] ?? '';
  }

  public function isEnabled(): bool {
    return $this->config['enable'] ?? false;
  }

  public function isRunning(): bool {
    return $this->status['running'] ?? false;
  }

  public function start(): Promise\PromiseInterface {
    return $this->_cmd('Script.Start', ['id'=>$this->getId()])->then(function($res) {
      return $res['was_running'] ?? null;
    });
  }

  public function stop(): Promise\PromiseInterface {
    return $this->_cmd('Script.Stop', ['id'=>$this->getId()])->then(function($res) {
      return $res['was_running'] ?? null;
    });
  }

  public function enable(): Promise\PromiseInterface {
    return $this->_cmd('Script.SetConfig', [
      'id'=>$this->getId(),
      'config' => ['enable'=>true]
    ]);
  }

  public function disable(): Promise\PromiseInterface {
    return $this->_cmd('Script.SetConfig', [
      'id'=>$this->getId(),
      'config' => ['enable'=>false]
    ]);
  }

  public function rename(string $name): Promise\PromiseInterface {
    return $this->_cmd('Script.SetConfig', [
      'id'=>$this->getId(),
      'config' => ['name'=>$name]
    ]);
  }
}
