<?php

namespace Apex\AutenticaUi\Http\Requests;

use Apex\Autentica\Core\Models\SystemResource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The matrix payload — P/003.
 *
 * A map of resource identifier to a string of action letters:
 * `['events' => 'cru', 'venues' => '']`. Separate from {@see GroupRequest} because it is a
 * different shape with nothing in common: no name, no `clientRules()`, and no ApexForm
 * schema to feed — the matrix is a grid of checkboxes, not a field list.
 *
 * Two things are checked that a regex cannot say on its own:
 *
 *   - every key names a resource that exists, so a stale tab or a hand-made payload cannot
 *     create a permission pointing at nothing;
 *   - the letters are drawn from the six the system knows.
 *
 * The identifier check was a `foreach` in the controller returning `back()->with('error')`,
 * which reported the first unknown key as a flash message rather than a validation error.
 * Here it is a validation failure like any other, on the field that caused it.
 */
class GroupPermissionMatrixRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // see EnsureResourcePermission on the admin route group
    }

    public function rules(): array
    {
        return [
            'permissions' => ['present', 'array'],
            'permissions.*' => ['nullable', 'string', 'regex:/^[crudph]*$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'permissions.*.regex' => 'Permissions may only contain the letters c, r, u, d, p and h.',
        ];
    }

    /**
     * Every key must name a real resource.
     *
     * `after` rather than a rule, because the check is on the KEYS of the array and there
     * is no per-key rule slot for that. One query, not one per key.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $submitted = array_keys((array) $this->input('permissions', []));

                if ($submitted === []) {
                    return;
                }

                $known = SystemResource::whereIn('identifier', $submitted)->pluck('identifier')->all();

                foreach (array_diff($submitted, $known) as $unknown) {
                    $validator->errors()->add(
                        "permissions.{$unknown}",
                        "Unknown resource '{$unknown}'."
                    );
                }
            },
        ];
    }
}
