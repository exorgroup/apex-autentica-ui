<?php

namespace Apex\AutenticaUi\Http\Controllers;

use Apex\Autentica\Core\Exceptions\GroupAdministrationException;
use Apex\Autentica\Core\Models\Group;
use Apex\Autentica\Core\Services\GroupAdministration;
use Apex\Autentica\Core\Support\Autentica;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Who belongs to a group.
 *
 * Membership is listed and edited a page at a time rather than as one big checkbox list of
 * every account. A host that creates an account per customer has a set that only grows — a
 * screen built on "all users" works today and times out later.
 *
 * Thin, like its sibling. The last-member refusal and the per-user cache rule are
 * {@see GroupAdministration}'s, because they must hold for any interface, not only this
 * one. Authorisation is the host's gate, configured in `autentica-ui.middleware`.
 *
 * ## Two host-shaped assumptions this had to lose on the way out of TBX
 *
 * The user MODEL now comes from `Autentica::userModel()` — the same seam Core's own
 * `Group::users()` uses — rather than a hard-coded `App\Models\User`.
 *
 * The user COLUMNS come from config. TBX stores a `surname`; nothing says another host
 * does, and a package that searches a column which is not there is a 500 on first use.
 * `autentica-ui.user.*` names the searchable columns and how to build a display name; the
 * defaults hold for a stock Laravel `users` table.
 */
class GroupMemberController extends Controller
{
    public function __construct(private GroupAdministration $groups)
    {
    }

    /** @return class-string<Model> */
    private function userModel(): string
    {
        return Autentica::userModel();
    }

    /**
     * Columns worth searching, filtered to the ones the table actually has.
     *
     * Filtered rather than trusted: a config naming a column that was dropped would turn
     * every search into a 500, and a search quietly missing one column is a much better
     * failure than a screen that will not load.
     *
     * @return array<int, string>
     */
    private function searchColumns(Model $prototype): array
    {
        $configured = (array) config('autentica-ui.user.search_columns', ['name', 'email']);
        $schema = $prototype->getConnection()->getSchemaBuilder();
        $table = $prototype->getTable();

        return array_values(array_filter(
            $configured,
            fn ($column) => $schema->hasColumn($table, $column)
        ));
    }

    /**
     * A page of users, each flagged with whether they are in this group.
     */
    public function index(Request $request, Group $group): JsonResponse
    {
        $model = $this->userModel();
        $prototype = new $model();
        $table = $prototype->getTable();
        $key = $prototype->getKeyName();

        $columns = $this->searchColumns($prototype);
        $query = $model::query();

        if ($request->filled('search') && $columns !== []) {
            $search = $request->input('search');

            $query->where(function ($q) use ($search, $columns) {
                foreach ($columns as $i => $column) {
                    $i === 0
                        ? $q->where($column, 'like', "%{$search}%")
                        : $q->orWhere($column, 'like', "%{$search}%");
                }
            });
        }

        // Members first, so ticking somebody does not make them vanish off the page you are
        // looking at, and so the group's current shape is visible without searching for it.
        $memberIds = $group->users()->pluck($table . '.' . $key);

        $users = $query
            ->orderByRaw("CASE WHEN {$table}.{$key} IN (" . ($memberIds->isEmpty() ? '0' : $memberIds->implode(',')) . ') THEN 0 ELSE 1 END')
            ->orderBy(config('autentica-ui.user.order_column', 'name'))
            ->paginate($request->integer('rows', 10))
            ->withQueryString();

        return response()->json([
            'users' => $users->through(fn (Model $user) => [
                'id' => $user->getKey(),
                'name' => $this->displayName($user),
                'email' => $user->getAttribute('email'),
                'is_member' => $memberIds->contains($user->getKey()),
            ]),
            'members_count' => $memberIds->count(),
        ]);
    }

    /**
     * The name to show, from whichever of the configured parts the record actually has.
     */
    private function displayName(Model $user): string
    {
        $parts = (array) config('autentica-ui.user.name_columns', ['name']);

        $name = trim(implode(' ', array_filter(array_map(
            fn ($column) => $user->getAttribute($column),
            $parts
        ))));

        return $name !== '' ? $name : (string) $user->getAttribute('email');
    }

    /**
     * Put a user in this group, or take them out.
     *
     * Stated as the state you want rather than as a toggle, so a double-click or a retried
     * request lands on the same answer instead of undoing itself.
     *
     * The user is resolved by hand rather than by route-model binding: binding works off
     * the type-hint, and the class is not known until config has been read.
     */
    public function update(Request $request, Group $group, int|string $user): JsonResponse
    {
        $validated = $request->validate([
            'member' => ['required', 'boolean'],
        ]);

        $model = $this->userModel();
        $subject = $model::query()->findOrFail($user);

        try {
            $this->groups->setMembership($group, $subject, $validated['member']);
        } catch (GroupAdministrationException $e) {
            // 422 rather than 403: the caller is allowed to change membership, and this
            // particular change is the one that would leave nobody able to grant it back.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'is_member' => $subject->fresh()->belongsToGroup($group),
            'members_count' => $group->users()->count(),
        ]);
    }
}
