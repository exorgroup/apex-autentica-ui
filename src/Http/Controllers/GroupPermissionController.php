<?php

namespace Apex\AutenticaUi\Http\Controllers;

use Illuminate\Routing\Controller;
use Apex\AutenticaUi\Http\Requests\GroupPermissionMatrixRequest;
use Apex\AutenticaUi\Http\Requests\GroupRequest;
use Apex\Autentica\Core\Exceptions\GroupAdministrationException;
use Apex\Autentica\Core\Models\Group;
use Apex\Autentica\Core\Models\SystemResource;
use Apex\Autentica\Core\Services\GroupAdministration;
use Apex\Autentica\Core\Support\PermissionMap;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The group × resource permission matrix.
 *
 * Thin by design — P/004. Everything that decides anything lives in
 * {@see GroupAdministration} in Autentica Core: the force-deletes, the two cache rules and
 * the three lockout guards. A host building its own interface calls the same service and
 * inherits them, rather than rewriting three subtleties it has no way of knowing about.
 *
 * What is left here is what a controller is for: take the request, call the service, say
 * what happened.
 *
 * Authorisation is NOT in these methods any more. The gate is `EnsureResourcePermission`
 * on the admin route group, which P/002 finally put on these seven routes — read-then-
 * action, in one place, and still running for a precognitive request, which resolves the
 * FormRequest and aborts before the action ever runs.
 */
class GroupPermissionController extends Controller
{
    public function __construct(private GroupAdministration $groups)
    {
    }

    /**
     * One index action again — P/017.
     *
     * This was `indexNew()` while the Apex page ran beside the PrimeVue one; that screen
     * was deleted at promotion and this is the only roles page now. The three `can*` props
     * went with it: the page asks `can()` against the shared permission map, as every
     * other migrated screen does.
     */
    public function index(Request $request)
    {
        /* The page path is the host's, not the package's: Inertia resolves it against the
           host's own `Pages/` glob, so a hard-coded path would be a guess about somebody
           else's directory layout. */
        return Inertia::render(config('autentica-ui.pages.roles', 'Admin/Roles/Index'), $this->payload($request) + [
            'rules' => GroupRequest::clientRules(),
        ]);
    }

    /**
     * The groups, the resource tree, and the matrix.
     */
    private function payload(Request $request): array
    {
        $resources = SystemResource::orderBy('menu_order')->get();
        $groups = Group::withCount('users')->orderBy('id')->get();

        // Modules first, each with its children, so the UI does not have to rebuild the tree.
        $tree = $resources->whereNull('parent_id')->map(fn ($module) => [
            'id' => $module->id,
            'identifier' => $module->identifier,
            'name' => $module->name,
            'children' => $resources->where('parent_id', $module->id)->values()->map(fn ($child) => [
                'id' => $child->id,
                'identifier' => $child->identifier,
                'name' => $child->name,
                'type' => $child->type,
            ]),
        ])->values();

        return [
            'groups' => $groups->map(fn ($g) => [
                'id' => $g->id,
                'name' => $g->name,
                'description' => $g->description,
                'users_count' => $g->users_count,
                // Defined by autentica:sync as '*' => crudph. Editing it here could strip
                // your own access to this very screen, with no way back in.
                'locked' => $this->groups->isProtected($g),
            ]),
            'tree' => $tree,
            'matrix' => $this->groups->matrixFor($groups),
            'actions' => array_values(PermissionMap::ACTIONS),
        ];
    }

    /**
     * Create a group, optionally starting from another group's permissions.
     */
    public function store(GroupRequest $request)
    {
        $group = $this->groups->create(
            $request->validated('name'),
            $request->validated('description'),
            $request->validated('copy_from_group_id'),
        );

        return back()->with('success', "Group '{$group->name}' created.");
    }

    /**
     * Rename a group, or change its description.
     */
    public function rename(GroupRequest $request, Group $group)
    {
        try {
            $this->groups->rename(
                $group,
                $request->validated('name'),
                $request->validated('description'),
            );
        } catch (GroupAdministrationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Group renamed to '{$group->name}'.");
    }

    /**
     * Delete a group.
     */
    public function destroy(Group $group)
    {
        $name = $group->name;

        try {
            $members = $this->groups->delete($group);
        } catch (GroupAdministrationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $members > 0
            ? sprintf(
                "Group '%s' deleted. %d %s removed from it.",
                $name,
                $members,
                $members === 1 ? 'user was' : 'users were'
            )
            : "Group '{$name}' deleted.");
    }

    /**
     * Replace one group's permissions with what was submitted.
     */
    public function update(GroupPermissionMatrixRequest $request, Group $group)
    {
        try {
            $this->groups->setMatrix($group, $request->validated('permissions'));
        } catch (GroupAdministrationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Permissions updated for {$group->name}.");
    }
}
