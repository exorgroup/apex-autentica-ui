<?php

namespace Apex\AutenticaUi;

use Illuminate\Support\ServiceProvider;

/**
 * The administration screens for Autentica — P/018.
 *
 * Autentica Core decides things; this package draws them. Every guard that matters lives
 * in `Apex\Autentica\Core\Services\GroupAdministration`, so a host that writes its own
 * interface calls the same service and inherits the lot. What is here is a skin: thin
 * controllers, their routes, and the Vue that renders them.
 *
 * ## Why this is a separate package
 *
 * Core's JavaScript is three dependency-free files. Screens need Apex UI, and a host that
 * installs Autentica purely for authentication should not acquire a component library with
 * it. Splitting keeps that choice with the host: require this package and get the screens,
 * or do not and build your own on the same service.
 *
 * ## Why one package and one licence
 *
 * Core and Pro are tiered inside one repository because their DATABASE tables are licensed
 * differently. Screens are not, so this does not mirror that split — the MFA and session
 * screens will ride here alongside the roles matrix when their turn comes.
 *
 * ## How the JavaScript reaches a host's build
 *
 * Inside the Composer package, as Core's does, reached by a Vite alias the host adds once.
 * The reason is Core's and it holds here too: the PHP that produces the permission maps and
 * the JS that reads them stay version-locked, because they ship in the same artefact.
 */
class AutenticaUiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/autentica-ui.php', 'autentica-ui');

        $this->contributeGateMap();
    }

    public function boot(): void
    {
        /*
         * Loaded, not published. The routes are the package's own and a host has no reason
         * to edit them — unlike Core's migrations, which become the host's tables and are
         * published for exactly that reason.
         */
        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
    }

    /**
     * Add this package's route → resource entries to the gate's map, per key.
     *
     * NOT `mergeConfigFrom('…', 'autentica')`, which is the obvious thing and is wrong
     * here: that helper merges one level deep, so a host with its own `autentica.routes`
     * array — which every host of any size has — would replace this package's entire
     * `routes` array rather than gaining its entries. The screens would then ship
     * UNGATED, silently, because `EnsureResourcePermission` passes through any prefix the
     * map does not list.
     *
     * Per key, and the HOST WINS on a collision: a host that has already mapped `roles`
     * to something of its own keeps it. That is the same precedence `mergeConfigFrom`
     * promises, applied at the depth that actually matters.
     */
    private function contributeGateMap(): void
    {
        $defaults = require __DIR__ . '/../config/autentica-ui.php';
        $config = $this->app['config'];

        foreach (['routes', 'actions'] as $section) {
            $config->set(
                "autentica.{$section}",
                array_merge($defaults[$section] ?? [], $config->get("autentica.{$section}", []))
            );
        }
    }
}
