<?php
namespace SharkyDog\Shelly\Device;
use SharkyDog\Shelly\Device;
use SharkyDog\Shelly\Component;

class Shelly_PM_Mini extends Device {
  protected static $componentType = [
    'pm1' => Component\PM1::class
  ];
  protected static $componentLoad = [
    'pm1:0'
  ];

  public function PM(): Component\PM1 {
    return $this->getComponent('pm1:0');
  }
}
