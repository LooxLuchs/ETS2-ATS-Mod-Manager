<?php

declare(strict_types=1);

namespace Ets2AtsModManager;

use Ets2AtsModManager\Services\PackageUpload;
use Ets2AtsModManager\Services\PackageTransport;
use Ets2AtsModManager\Services\WingsPackageTransport;
use Pterodactyl\Extensions\ExtensionProvider;
use Pterodactyl\Services\Extensions\ExtensionSettingDefinition;
use Pterodactyl\Services\Extensions\ExtensionSettingsDefinition;

class Ets2AtsModManagerProvider extends ExtensionProvider
{
    public function boot(): void
    {
        $this->app->bind(PackageTransport::class, WingsPackageTransport::class);
        $this->registerApiRoutes();
        $this->loadExtensionMigrations();
        $this->registerSettings(new ExtensionSettingsDefinition($this->settings(), [
            ExtensionSettingDefinition::make('sii_mib', 'sii_mib', 4, ['required', 'integer', 'min:1', 'max:16'])
                ->label('SII limit (MiB)')->field('number'),
            ExtensionSettingDefinition::make('dat_mib', 'dat_mib', 64, ['required', 'integer', 'min:1', 'max:256'])
                ->label('DAT limit (MiB)')->field('number')
                ->help('Match PHP memory_limit, post_max_size and the web server upload limit to these values.'),
        ]));
        $this->app->scoped(PackageUpload::class, fn () => new PackageUpload(
            (int) $this->settings()->get('sii_mib', 4) * 1048576,
            (int) $this->settings()->get('dat_mib', 64) * 1048576,
        ));
    }
}
