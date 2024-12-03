<?php
namespace SharkyDog\Shelly\Component;
use SharkyDog\Shelly\Component;
use SharkyDog\Shelly\Device;
use React\Promise;

class PM1 extends Component {
  protected static $namespaceUC = 'PM1';
  protected static $namespaceLC = 'pm1';

  protected $statusUpdates = true;
  protected $statusUpdatesFull = true;

  public function resetCounters(string ...$types): Promise\PromiseInterface {
    return $this->_cmd('PM1.ResetCounters', ['id'=>$this->getId(),'type'=>$types]);
  }
}
