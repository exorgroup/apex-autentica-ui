import { router } from '@inertiajs/vue3';
import { useApexAlert } from '@exorgroup/apex-ui';

/**
 * Save and delete, reported through one Apex alert — AF2-316.
 *
 * The alert service itself is generic library code: `run()` carries a single
 * dialog through confirm → progress → result without closing in between.
 * What is NOT generic, and what this file exists to stop being copied onto
 * forty screens, is the three-line wrapper around it:
 *
 *   1. the look of the report (how long it stays, what the button says),
 *   2. the gears,
 *   3. turning an Inertia visit into a promise.
 *
 * (3) is the one that matters and the one that cannot live in apex-ui: the
 * library is deliberately transport-agnostic, which is the whole reason
 * `setAlertInterpreter` exists. So the Inertia knowledge stops here.
 *
 * Whether the request SUCCEEDED is settled in `onFinish`, never `onSuccess`.
 * The action's promise is what holds the spinner open, and `onSuccess` does
 * not fire on a 422 — so resolving there leaves the gears turning forever on
 * the one path that matters. `onFinish` fires whatever happened.
 *
 * Whether it was ACCEPTED is a different question, and app.js answers it: a
 * controller that refuses with `back()->with('error', …)` returns a 302, so
 * Inertia calls onSuccess and only the flash bag knows better. The registered
 * interpreter reads it and downgrades the outcome.
 */

/** The result stage. One definition, so every screen reports the same way. */
const REPORT = { autoClose: 3000, showTimer: true, confirmText: 'Dismiss' };

/** The working stage. Gears, because a record is being written. */
const working = (title) => ({ title, icon: 'settings' });

export function useRecordActions() {
    const { run } = useApexAlert();

    /**
     * Put an Inertia form to the server and report it.
     *
     * `onSuccess` fires the moment the server accepts, not when the alert is
     * dismissed — a host closing its dialog should not wait three seconds
     * with the saved record still on screen behind the report.
     */
    function saveRecord(form, url, {
        method = 'post',
        savingTitle,
        savedTitle = 'Record saved',
        failedTitle = 'Not saved',
        failedMessage = 'Check the highlighted fields.',
        /* Ask first, in ApexAlert's own option shape — the same shape
           `deleteRecord` takes. Omitted, nothing is asked, which is what
           every record dialog wants: the dialog IS the deliberate act.
           A settings page is different, and the screen it replaces asked
           (J/007). Pass `cancelText`, or the question renders with one
           button and no way to answer "no" except the scrim. */
        confirm,
        onSuccess,
        /**
         * The payload rewrite, applied to the form before it goes.
         *
         * Not a visit option — Inertia keeps it on the FORM — which is
         * why it needs naming here rather than travelling with the
         * rest. A screen that uploads a file has to send its update as
         * a POST carrying `_method: put`, because PHP does not populate
         * `$_FILES` on a real PUT; that rewrite is this.
         *
         * N/029: this parameter and `...visit` below did not exist, and
         * everything a caller passed beyond the names above was
         * silently dropped. The blog screen asked for exactly this
         * rewrite, did not get it, and every edit went out as a plain
         * POST to the update route — 405, Method Not Allowed, with the
         * screen's own code reading as though it had asked correctly.
         */
        transform,
        ...visit
    } = {}) {
        return run({
            confirm,
            progress: working(savingTitle ?? (method === 'post' ? 'Creating…' : 'Saving…')),
            action: () => new Promise((resolve) => {
                let accepted = false;

                /* Reset when none is given: Inertia's transform stays on
                   the form until it is replaced, so a rewrite meant for
                   one save would otherwise apply to every later one. */
                form.transform(transform ?? ((data) => data));

                form[method](url, {
                    /* Whatever else the caller asked for - forceFormData,
                       preserveState, only - spread FIRST, so the three
                       this helper owns cannot be overridden by accident. */
                    ...visit,
                    preserveScroll: true,
                    onSuccess: (page) => { accepted = true; onSuccess?.(page); },
                    onFinish: () => resolve(accepted
                        ? { ok: true, title: savedTitle }
                        : { ok: false, title: failedTitle, message: failedMessage }),
                });
            }),
            /* `title` here is the FALLBACK, used only when the outcome names
               none — which is what the interpreter leaves behind when it
               downgrades a 302-with-an-error-flash. */
            result: { ...REPORT, title: failedTitle },
        });
    }

    /**
     * Ask, delete, report — one alert across all three.
     *
     * `confirm` is the question, in ApexAlert's own option shape. Pass
     * `cancelText`: the service defaults it to null, and a yes/no question
     * with one button has no way to answer "no" except the scrim.
     */
    function deleteRecord(url, {
        confirm,
        deletingTitle = 'Deleting…',
        deletedTitle = 'Record deleted',
        refusedTitle = 'Not deleted',
        onSuccess,
    } = {}) {
        return run({
            confirm,
            progress: working(deletingTitle),
            action: () => new Promise((resolve) => {
                let accepted = false;
                router.delete(url, {
                    preserveScroll: true,
                    onSuccess: (page) => { accepted = true; onSuccess?.(page); },
                    /* No failure message here. A refusal arrives as a flash,
                       and the interpreter has the server's own words for it,
                       which are better than anything this file could guess. */
                    onFinish: () => resolve(accepted
                        ? { ok: true, title: deletedTitle }
                        : { ok: false, title: refusedTitle }),
                });
            }),
            result: { ...REPORT, title: refusedTitle },
        });
    }

    return { saveRecord, deleteRecord, run, REPORT, working };
}

/*
 * -- Why this file exists twice ---------------------------------------------
 *
 * TBX has the same file, and eleven of its screens import it from there. This is a
 * deliberate second copy, not an oversight, and the comment above says why it cannot
 * simply move: point (3). The Inertia knowledge must not go into apex-ui, because the
 * library is transport-agnostic on purpose -- `setAlertInterpreter` exists for exactly
 * that reason.
 *
 * So the choice was between a copy and a dependency pointing the wrong way. It is a
 * copy, at the layer that needs it: this package already depends on Inertia (its
 * controllers return Inertia responses) and on apex-ui, so the glue between the two is
 * at the right level HERE. It is not at the right level for TBX's eleven other screens
 * to reach through an auth package to find it.
 *
 * The proper home is a fourth thing that does not exist -- an `apex-ui-inertia` adapter
 * both copies would collapse into. Until it does: change one, change the other. The MFA
 * and session screens coming to this package will share this copy, so the count here
 * stays at one however many screens arrive.
 */
