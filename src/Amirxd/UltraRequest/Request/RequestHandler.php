<?php

declare(strict_types=1);

namespace Amirxd\UltraRequest\Request;

use pocketmine\plugin\Plugin;
use pocketmine\plugin\PluginBase;

class RequestHandler {

    private static ?PluginBase $plugin = null;

    public static function isRegistered(): bool {
        return self::$plugin !== null;
    }

    public static function register(PluginBase $plugin): void {
        if (self::isRegistered()) return;
        self::$plugin = $plugin;
    }

    public static function getRegistrant(): ?PluginBase {
        return self::$plugin;
    }
}