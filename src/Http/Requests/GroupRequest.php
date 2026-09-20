<?php

namespace Apex\AutenticaUi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One rule list for a group's identity — P/003.
 *
 * Covers the two writes that carry a name: `store` and `rename`. The matrix payload is a
 * different shape and has its own class, {@see GroupPermissionMatrixRequest}.
 *
 * The server decides. `clientRules()` is the same list rendered in the Laravel pipe DSL,
 * which is the notation ApexForm reads — two renderings of one array, never two arrays.
 * Before this class the rules were typed out separately in `store()` and `rename()`, which
 * is the drift the form design warns about.
 *
 * Authorisation is NOT here, and `authorize()` returning true is not an oversight. The gate
 * is `EnsureResourcePermission`, which P/002 finally put on these routes: read-then-action,
 * one place, and — unlike a check in the controller or in this class — it still runs for a
 * precognitive request, which resolves this FormRequest and then aborts before the action.
 *
 * Nothing is derived here, so there is no `prepareForValidation()`. If a value ever is
 * derived it belongs in one, not in the controller afterwards, where no rule would see it.
 */
class GroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // see EnsureResourcePermission on the admin route group
    }

    /**
     * The shared list. Every rule is a plain string so it can be rendered either way
     * without a special case — an object cannot be imploded.
     */
    private static function baseRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', static::uniqueName()],
            'description' => ['nullable', 'string', 'max:1000'],
            /* Live groups only: copying the permissions of a group nobody can see would
               produce a new group whose access came from nowhere visible. */
            'copy_from_group_id' => ['nullable', 'integer', 'exists:au10_groups,id,deleted_at,NULL'],
        ];
    }

    /**
     * Unique among LIVE groups.
     *
     * Read the tail: `…,{$except},id,deleted_at,NULL`. The last pair is an extra WHERE and
     * Laravel reads the literal `NULL` as `whereNull`, so a soft-deleted row is not
     * consulted — `destroy()` soft-deletes, and without this a group nobody can see would
     * reserve its name for ever.
     *
     * P/001 took the unique INDEX off `au10_groups.name`, because the rule cannot be
     * spelled as an index once NULLs are involved. So this is now the only thing standing
     * between two live groups with the same name.
     */
    private static function uniqueName(int|string $except = 'NULL'): string
    {
        return "unique:au10_groups,name,{$except},id,deleted_at,NULL";
    }

    /**
     * The authoritative rules, with uniqueness narrowed to exclude the group being renamed.
     */
    public function rules(): array
    {
        $rules = static::baseRules();

        $group = $this->route('group');

        if ($group) {
            $rules['name'] = ['required', 'string', 'max:255', static::uniqueName($group->id)];

            /* A rename cannot smuggle a permission copy. It never could — `rename()`
               simply did not validate the key — but one shared rule list has to say so
               out loud rather than rely on the controller ignoring it. */
            $rules['copy_from_group_id'] = ['prohibited'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'copy_from_group_id.prohibited' => 'Permissions can only be copied when a group is created.',
        ];
    }

    /**
     * The same list in the pipe DSL, for an ApexForm schema.
     *
     * Record-independent on purpose: the client cannot check uniqueness against a row it
     * cannot see, and the id would be meaningless in a schema shared by the add and rename
     * dialogs. `unique` travels as a rule ApexForm does not recognise — retained, never
     * evaluated locally, and settled by the server.
     */
    public static function clientRules(): array
    {
        return array_map(
            static fn (array $rules) => implode('|', $rules),
            static::baseRules()
        );
    }
}
