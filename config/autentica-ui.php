<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Route → resource defaults for the screens this package ships
    |--------------------------------------------------------------------------
    |
    | Merged UNDER the host's own `config/autentica.php`, so a host that mounts
    | these screens on different route names keeps its entry and this one is
    | ignored.
    |
    | `EnsureResourcePermission` reads `autentica.routes`: it takes the route's
    | name, strips the `admin.` prefix and the action suffix, and looks the rest
    | up. A prefix that is not listed passes through UNGATED, by design — so a
    | package that ships a screen and not its mapping ships a hole.
    |
    | The route is `roles` and the resource is `groups`. The two names differ
    | entirely, which is why this is a map rather than a convention.
    |
    */

    'routes' => [
        'roles' => 'groups',
    ],

    /*
    |--------------------------------------------------------------------------
    | Action suffixes these screens use beyond the usual set
    |--------------------------------------------------------------------------
    |
    | An unlisted suffix falls to `read`, which is the safe default for a route
    | that only shows something and the WRONG one for a route that writes.
    | `rename` is the roles screen's own verb: without this line the middleware
    | would admit a read-only account to it.
    |
    */

    'actions' => [
        'rename' => 'update',
    ],

    /*
    |--------------------------------------------------------------------------
    | Middleware the screens run behind
    |--------------------------------------------------------------------------
    |
    | THE HOST MUST ADD ITS GATE HERE. The default is deliberately only `web` and
    | `auth`, because the gate is the host's middleware class and a package cannot
    | name one that may not exist. In an Apex host that line is
    | `EnsureResourcePermission`; publish this config and append it.
    |
    | It has to be middleware and not a check in a controller. These controllers
    | carry none — they moved into Autentica Core at P/004 — and a precognitive
    | request never runs a controller body in any case, so a gate anywhere else is
    | absent exactly when Precognition is asking.
    |
    | Leaving this at the default gives you screens that any signed-in account can
    | reach, including a patron created at checkout.
    |
    */

    'middleware' => ['web', 'auth'],

    /*
    |--------------------------------------------------------------------------
    | The host's user table
    |--------------------------------------------------------------------------
    |
    | The MODEL comes from `auth.providers.users.model` via Autentica; these are
    | the columns the members list reads. The defaults hold for a stock Laravel
    | `users` table. TBX adds `surname`.
    |
    | `search_columns` is filtered against the real schema before use, so naming
    | a column that has been dropped narrows the search rather than 500ing it.
    |
    */

    'user' => [
        'search_columns' => ['name', 'email'],
        'name_columns' => ['name'],
        'order_column' => 'name',
    ],

    /*
    |--------------------------------------------------------------------------
    | Where the host keeps the pages
    |--------------------------------------------------------------------------
    |
    | Inertia resolves a component name against the HOST's `Pages/` glob, so the
    | package cannot know the path — it can only be told. The host's page is a
    | thin wrapper that supplies the layout and renders the component this
    | package ships.
    |
    */

    'pages' => [
        'roles' => 'Admin/Roles/Index',
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-group notes
    |--------------------------------------------------------------------------
    |
    | A line the screen shows above a group's permission matrix, keyed by the
    | group's NAME. For the things only the host knows — that one group reaches
    | its own records by ownership rather than by permission, say. Empty by
    | default: the package has no groups of its own to explain.
    |
    */

    'group_notes' => [],

];
