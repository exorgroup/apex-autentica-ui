# Apex Autentica UI

The administration screens for [Apex Autentica](https://github.com/exorgroup/apex-autentica),
built on [Apex UI](https://github.com/exorgroup/apex-ui).

Autentica Core decides things; this package draws them. Every guard that matters —
the protected-group rules, the permission force-deletes, the cache rules — lives in
`Apex\Autentica\Core\Services\GroupAdministration`. A host that would rather write its own
screens calls that service and inherits all of it; this package is a skin over the same
thing.

## Why it is separate

Core's JavaScript is three dependency-free files. Screens need a component library, and a
host installing Autentica purely for authentication should not acquire one with it.
Requiring this package is how you say you want the screens.

## What it ships

| | |
| --- | --- |
| Groups & Permissions | the group rail, the resource × action matrix, and group membership |

More will follow — MFA enrolment, active sessions, the security log — in this same package.
It does **not** mirror Core's Core/Pro split: that exists because those database tables are
licensed differently, and screens are not.

## Installing

```
composer require exorgroup/apex-autentica-ui
```

**Apex UI is not a composer requirement, and that is deliberate.** Nothing in this
package's PHP touches it; the dependency is that the host's Vue app has the Apex UI
components registered, which is a build-time concern composer cannot express. It is
normally installed through npm as `@exorgroup/apex-ui`. Requiring it here would make this
package uninstallable in a host that takes Apex UI that way — which is how it is actually
taken. It is listed under `suggest` instead.

Then three things the package cannot do for itself.

**1. Add your gate to the middleware stack.** The default is `['web', 'auth']` and that is
not enough: it means any signed-in account can reach the screens, including a patron
created at checkout. Publish the config and append your resource gate —
`EnsureResourcePermission` in an Apex host.

**2. Have Apex UI registered in your Vue app.** The screens name its components and get
them from the global registration `ApexUI` performs at boot. Without it every control on
the page resolves to nothing.

**3. Point Vite at the package's JavaScript.** It ships inside the Composer package, as
Core's does, so the PHP that builds the permission maps and the JS that reads them stay
version-locked:

```js
resolve: {
    preserveSymlinks: true,
    alias: {
        '@apex/autentica-ui': path.resolve(__dirname, 'vendor/exorgroup/apex-autentica-ui/resources/js'),
    },
},
```

## The route map

The package contributes its own `route → resource` entries to `autentica.routes`, merged
**per key** with the host winning on a collision. This is not `mergeConfigFrom`, which
merges one level deep and would have a host's own `routes` array replace the package's
wholesale — leaving the screens ungated, silently, because the gate passes through any
prefix its map does not list.

The route is `roles`; the resource is `groups`. The two differ, which is why it is a map
and not a convention.

## Licence

Proprietary. One package, one licence.
