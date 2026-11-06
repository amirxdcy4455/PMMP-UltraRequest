<?php

declare(strict_types=1);

namespace Amirxd\UltraRequest\Request;

use pocketmine\plugin\PluginBase;

final class RequestHandler {

    /** @var array<int, PluginBase> */
    private static array $plugins = [];

    /**
     * Registers a plugin as an AsyncPool provider.
     *
     * Multiple plugins may register safely when they share this virion.
     * Disabled plugin instances are ignored by getRegistrant().
     */
    public static function register(PluginBase $plugin): void {
        if (!$plugin->isEnabled()) {
            throw new \InvalidArgumentException("Only enabled plugins can be registered");
        }

        self::$plugins[spl_object_id($plugin)] = $plugin;
    }

    /**
     * Removes a plugin registration. Call this from onDisable() when possible.
     */
    public static function unregister(PluginBase $plugin): void {
        unset(self::$plugins[spl_object_id($plugin)]);
    }

    public static function isRegistered(): bool {
        return self::getRegistrant() !== null;
    }

    /**
     * Returns the first active plugin that can provide an AsyncPool.
     */
    public static function getRegistrant(): ?PluginBase {
        foreach (self::$plugins as $id => $plugin) {
            if (!$plugin->isEnabled()) {
                unset(self::$plugins[$id]);
                continue;
            }

            return $plugin;
        }

        return null;
    }
}
