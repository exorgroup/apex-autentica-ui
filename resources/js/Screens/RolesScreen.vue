<script setup>
/**
 * Groups & Permissions — the screen, as shipped by `exorgroup/apex-autentica-ui` (P/019).
 *
 * Everything host-shaped stayed behind: the layout, the page heading, and the route that
 * renders it. What is here is the screen itself, so a host supplies its own chrome and
 * gets the same matrix. TBX's `Pages/Admin/Roles/Index.vue` is that wrapper and is a
 * handful of lines; it is also the worked example for anyone else.
 *
 * Phase P.
 *
 * Ran as `/admin/roles-new` beside the PrimeVue screen while the two were compared; it
 * replaced that screen at promotion (§6, P/017) and this is now the only roles page.
 * TBX's `Admin/Categories/Index.vue` was the reference implementation it was written
 * against; what differs from that pattern is recorded where it differs — and a good deal
 * differs, because this is a master–detail screen rather than a table with a dialog.
 *
 * Nothing imports a control. ApexUI registers the library under the `Apex` prefix at boot,
 * so the page names components and gets them.
 *
 * Every visible action is gated twice: hidden here by `can()`, refused by
 * EnsureResourcePermission on the admin route group. The hidden button is a courtesy; the
 * endpoint is the gate.
 *
 */
import { computed, ref, watch } from 'vue';
import { useCan } from '@apex/autentica';
import axios from 'axios';
import { router } from '@inertiajs/vue3';
/* Precognition's useForm, not Inertia's. It returns Inertia's form patched with
   `validate()`, and ApexForm picks the driver up by capability rather than by a prop. */
import { useForm } from 'laravel-precognition-vue-inertia';
import { useApexAlert } from '@exorgroup/apex-ui';
import { useRecordActions } from '../composables/useRecordActions';

const props = defineProps({
    groups: { type: Array, default: () => [] },
    tree: { type: Array, default: () => [] },
    matrix: { type: Object, default: () => ({}) },
    actions: { type: Array, default: () => [] },
    rules: { type: Object, default: () => ({}) },
});

const { can } = useCan();
const { saveRecord, deleteRecord, run, working, REPORT } = useRecordActions();
/* `notify` for a one-shot result. `run()` is confirm → progress → report around an
   action, and a refusal that has already come back is none of those. */
const { notify } = useApexAlert();

/* Spelled once — a typo denies for ever with no error, because a resource that does not
   exist has no permissions. The ROUTE is `roles`; the RESOURCE is `groups`. */
const RESOURCE = 'groups';

const selectedId = ref(props.groups[0]?.id ?? null);
const selected = computed(() => props.groups.find((g) => g.id === selectedId.value) ?? null);

/* A group can vanish under the selection — deleted here, or by somebody else between
   reloads. Fall back to the first rather than leaving the pane bound to a group that is
   no longer in the list, which renders as an empty detail with no explanation. */
watch(() => props.groups, (list) => {
    if (! list.some((g) => g.id === selectedId.value)) {
        selectedId.value = list[0]?.id ?? null;
    }
});

function select(group) {
    selectedId.value = group.id;
    draft.value = buildDraft(group.id);
}

/* ── the matrix ───────────────────────────────────────────────────────────
   A working copy as a Set of letters per resource, so ticking a box is a local edit and
   nothing reaches the server until Save. Straight from the PrimeVue screen — the storage
   is unchanged, only what draws it. */
function buildDraft(groupId) {
    const current = props.matrix[groupId] ?? {};
    const out = {};

    props.tree.forEach((module) => {
        module.children.forEach((resource) => {
            out[resource.identifier] = new Set((current[resource.identifier] ?? '').split(''));
        });
    });

    return out;
}

const draft = ref(buildDraft(selectedId.value));

/* A save, or somebody else's change, arrives as new props. Rebuild from them rather than
   keeping the local copy, or the screen would go on showing an edit the server rejected. */
watch(() => props.matrix, () => { draft.value = buildDraft(selectedId.value); });

/** Whether this screen may write the matrix at all — permission AND the group's own rule. */
const editable = computed(() => can(RESOURCE, 'update') && ! selected.value?.locked);

function has(identifier, letter) {
    return draft.value[identifier]?.has(letter) ?? false;
}

function toggle(identifier, letter) {
    if (! editable.value) return;

    const set = draft.value[identifier];
    set.has(letter) ? set.delete(letter) : set.add(letter);
    // Reassign so the computeds re-evaluate — a Set mutation is not reactive.
    draft.value = { ...draft.value };
}

/** Every action on one resource, on or off together. */
function toggleRow(identifier) {
    if (! editable.value) return;

    const set = draft.value[identifier];
    const full = props.actions.every((a) => set.has(a));

    props.actions.forEach((a) => (full ? set.delete(a) : set.add(a)));
    draft.value = { ...draft.value };
}

function rowIsFull(identifier) {
    return props.actions.every((a) => has(identifier, a));
}

const grantedCount = computed(
    () => Object.values(draft.value).filter((set) => set.size > 0).length);

/** Unsaved work — the reason to warn before the selection moves elsewhere. */
const dirty = computed(() => {
    const stored = props.matrix[selectedId.value] ?? {};

    return Object.entries(draft.value).some(([identifier, set]) => {
        const was = stored[identifier] ?? '';
        const now = props.actions.filter((a) => set.has(a)).join('');

        return was.split('').sort().join('') !== now.split('').sort().join('');
    });
});

function save() {
    if (! editable.value) return;

    // Letters in a stable order, so what is stored reads the same way every time.
    const permissions = {};
    Object.entries(draft.value).forEach(([identifier, set]) => {
        permissions[identifier] = props.actions.filter((a) => set.has(a)).join('');
    });

    return run({
        progress: working('Saving…'),
        action: () => new Promise((resolve) => {
            let accepted = false;
            router.put(route('admin.roles.update', selected.value.id), { permissions }, {
                preserveScroll: true,
                onSuccess: () => { accepted = true; },
                /* No message on failure: a refusal arrives as a flash and the server's own
                   words are better than anything this file could guess. */
                onFinish: () => resolve(accepted
                    ? { ok: true, title: 'Permissions saved' }
                    : { ok: false, title: 'Not saved' }),
            });
        }),
    });
}

/* ── the tree the table draws ─────────────────────────────────────────────
   Modules are rows too, carrying only their name: the expander lives on the resource
   column, and a module has nothing to tick. `key` is the resource identifier, which is
   also what the payload is keyed by, so a row and its permissions cannot drift apart. */
const nodes = computed(() => props.tree.map((module) => ({
    key: `module:${module.identifier}`,
    data: { name: module.name, identifier: module.identifier, isModule: true },
    children: (module.children ?? []).map((resource) => ({
        key: resource.identifier,
        data: { name: resource.name, identifier: resource.identifier, isModule: false },
    })),
})));

/* Everything open. The screen it replaces was one flat table with module headings, so
   collapsed modules would hide rows that used to be in front of you. */
const expandedKeys = ref({});
watch(nodes, (list) => {
    expandedKeys.value = Object.fromEntries(list.map((n) => [n.key, true]));
}, { immediate: true });

/** Full names for the column headers; the letters are what travels on the wire. */
const ACTION_LABELS = {
    c: 'Create', r: 'Read', u: 'Update', d: 'Delete', p: 'Print', h: 'History',
};

const columns = computed(() => [
    { field: 'name', header: 'Resource', expander: true },
    ...props.actions.map((a) => ({ field: a, header: ACTION_LABELS[a] ?? a, align: 'center' })),
    { field: '__all', header: '', align: 'center' },
]);

function remove(group) {
    deleteRecord(route('admin.roles.destroy', group.id), {
        confirm: {
            tone: 'danger',
            header: 'Delete group',
            /* The count is in the question because it is the consequence: the members are
               detached as part of the delete, not as a precondition for it, so this is the
               only place anybody is told how many people lose the group. */
            message: group.users_count > 0
                ? `Delete “${group.name}”? ${group.users_count} ${group.users_count === 1 ? 'user' : 'users'} will be removed from it.`
                : `Delete “${group.name}”?`,
            confirmText: 'Delete',
            /* `confirm()` defaults `cancelText` to null, so the question renders with one
               button and no way to answer "no" except the scrim. */
            cancelText: 'Cancel',
        },
    });
}

/* ── members ──────────────────────────────────────────────────────────────
   These two endpoints stay JSON, and that is a deliberate exception to "RESTful
   controllers served through Inertia" — the same exception Phase O made for
   `SecurityLogController::forUser()`, for a sharper reason here.

   An Inertia visit replaces the whole props object, so `props.matrix` arrives as a new
   reference and the watcher above rebuilds the draft. Paging the members list would
   therefore throw away unsaved permission edits on the other tab, silently. Fetching the
   list on its own keeps the two halves of this screen independent, which is what they are.

   No SORTING is offered, exactly as before. The order is a rule rather than a preference:
   members first, then by name, so ticking somebody does not make them vanish off the page
   you are looking at. A sortable column would either break that or quietly ignore the
   click. The workflow's sort-whitelist rule bites when sorting is offered; it is not. */
const TAB_PERMISSIONS = 'permissions';
const TAB_MEMBERS = 'members';

const tab = ref(TAB_PERMISSIONS);

const members = ref({ data: [], total: 0, current_page: 1, per_page: 10 });
const membersLoading = ref(false);
const memberSearch = ref('');
const togglingId = ref(null);
let searchTimer = null;

/* Bumped on every load, and used in each tick's `:key`.

   Vue patches a DOM property only when the bound value CHANGES. A refused toggle leaves
   `is_member` exactly as it was, so re-fetching the list is not enough on its own: the
   vnode prop is identical, Vue skips the patch, and the box the browser unchecked on
   click stays unchecked beside a count that still says the person is a member. Changing
   the key remounts the control, which is the one thing that reliably puts the DOM back
   in step with the value it is supposed to be showing. */
const memberEpoch = ref(0);

const tabs = computed(() => [
    { value: TAB_PERMISSIONS, label: 'Permissions' },
    { value: TAB_MEMBERS, label: 'Members', badge: selected.value?.users_count ?? 0 },
]);

async function loadMembers(page = 1) {
    if (! selected.value) return;

    membersLoading.value = true;
    try {
        const url = route('admin.roles.members.index', selected.value.id)
            + `?page=${page}&rows=${members.value.per_page}`
            + (memberSearch.value ? `&search=${encodeURIComponent(memberSearch.value)}` : '');

        const { data } = await axios.get(url);
        members.value = data.users;
        memberEpoch.value++;
    } finally {
        membersLoading.value = false;
    }
}

/* Typing should not fire a request per keystroke, and the old page debounced the same
   way. 300ms is long enough to finish a word and short enough not to feel stuck. */
function onSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => loadMembers(1), 300);
}

async function toggleMember(user, shouldBeMember) {
    if (! can(RESOURCE, 'update')) return;

    togglingId.value = user.id;
    try {
        const { data } = await axios.put(
            route('admin.roles.members.update', [selected.value.id, user.id]),
            { member: shouldBeMember },
        );

        user.is_member = data.is_member;
        /* The count lives on the group in the rail, and the server is the one that knows
           it — a local increment would drift the moment two people edit at once. */
        if (selected.value) selected.value.users_count = data.members_count;
    } catch (e) {
        /* 422 is the last-administrator refusal, and the server's words are the right
           ones. Anything else is unexpected and says so. */
        notify({
            ...REPORT,
            tone: 'danger',
            title: e?.response?.data?.message ?? 'Could not change membership',
        });

        /* Then put the list back the way the SERVER has it. Without this the tick stays
           where the click left it: `row.is_member` never changed, so nothing re-renders,
           and the box sits unticked beside a rail still reading "1 user". A refusal that
           leaves the screen showing the thing it refused is worse than no message at all.

           A refetch rather than flipping the flag back, because the reason for a refusal
           may be something else having changed — the server's copy is the answer either
           way, and this list is one small page. */
        await loadMembers(members.value.current_page);
    } finally {
        togglingId.value = null;
    }
}

/* Switching group or tab reloads rather than showing the previous group's people. */
watch([selectedId, tab], ([, which]) => {
    if (which === TAB_MEMBERS) {
        memberSearch.value = '';
        loadMembers(1);
    }
});

/* ── the create / rename dialog ───────────────────────────────────────────
   Inertia's useForm holds the values; ApexForm reads and writes THROUGH it and keeps no
   copy. A 422's errors land on the fields with no work here. */
const BLANK = { name: '', description: '', copy_from_group_id: null };

const dialogOpen = ref(false);
const renaming = ref(null);

/* ONE form, created once, with the method and url as FUNCTIONS. Both are resolved per
   request rather than at creation, so the same form validates against the right endpoint
   in either mode — and that matters for exactly one rule here:
   `unique:au10_groups,name,{id}` excludes the group being renamed, so validating a rename
   against the STORE url would report the group's own name as taken.

   Created here rather than per dialog: `useForm` ends with a `watchEffect`, which outside
   a component belongs to no scope and is never disposed. One per open would leak an
   effect each time. */
const form = useForm(
    () => (renaming.value ? 'patch' : 'post'),
    () => (renaming.value
        ? route('admin.roles.rename', renaming.value.id)
        : route('admin.roles.store')),
    { ...BLANK },
);

/** Every other group — what a new one may copy its permissions from. */
const copyOptions = computed(() => props.groups.map((g) => ({
    value: g.id,
    label: g.name,
})));

const dialogFields = computed(() => {
    const fields = [
        { key: 'name', label: 'Name', type: 'text', rules: props.rules.name, span: 2, placeholder: 'e.g. Box Office' },
        { key: 'description', label: 'Description', type: 'textarea', rules: props.rules.description, span: 2, rows: 2,
          help: 'What this group is for.' },
    ];

    /* Create only. The server agrees rather than merely tolerating it: GroupRequest makes
       the key `prohibited` once a group is bound, so a rename cannot smuggle a copy. */
    if (! renaming.value) {
        fields.push({
            key: 'copy_from_group_id',
            label: 'Copy permissions from',
            type: 'select',
            rules: props.rules.copy_from_group_id,
            options: copyOptions.value,
            clearable: true,
            span: 2,
            placeholder: 'Start with no permissions',
            help: 'A copy taken now, not a link — later changes to that group do not follow.',
        });
    }

    return fields;
});

/* `layout: 'sidebar'`, one section. The rail only draws with more than one section, so
   this renders as a plain panel — which is what the workflow says to write anyway: do not
   invent a second section to make the nav appear. */
const dialogSchema = computed(() => ({
    title: renaming.value ? 'Rename Group' : 'Add Group',
    layout: 'sidebar',
    shell: 'modal',
    sections: [{ id: 'details', title: 'Details', icon: 'group', columns: 2, fields: dialogFields.value }],
}));

function openCreate() {
    renaming.value = null;
    form.defaults({ ...BLANK });
    form.reset();
    form.clearErrors();
    dialogOpen.value = true;
}

function openRename(group) {
    renaming.value = group;
    /* `defaults()` then `reset()`, so Cancel and a failed save both return to the group as
       it was rather than to an empty form. */
    form.defaults({ ...BLANK, name: group.name, description: group.description ?? '' });
    form.reset();
    form.clearErrors();
    dialogOpen.value = true;
}

function saveGroup() {
    const group = renaming.value;

    saveRecord(
        form,
        group ? route('admin.roles.rename', group.id) : route('admin.roles.store'),
        {
            method: group ? 'patch' : 'post',
            /* Closes the moment the server accepts, not when the report is dismissed —
               leaving the form up behind a "Record saved" reads as though it had not. */
            onSuccess: () => { dialogOpen.value = false; },
        },
    );
}

const memberColumns = [
    { field: 'is_member', header: 'In group', align: 'center' },
    { field: 'name', header: 'Name' },
    { field: 'email', header: 'Email' },
];

/** Every resource the tree carries, flattened — the matrix's row count. */
const resourceCount = computed(() =>
    props.tree.reduce((n, module) => n + (module.children?.length ?? 0), 0));
</script>

<template>
    <div class="perm-layout">
        <!-- ═══ The rail ═══ -->
        <aside class="admin-card group-list">
            <div class="group-list-head">
                <h2 class="panel-title">Groups</h2>

                <!-- `success` and "Add X", like the add button on every other migrated
                     screen — Add Post, Add Category, Add Entity. This said "New Group" in
                     `primary`, the only place in the admin that added a record with a blue
                     button and the only one that called it "new". -->
                <ApexButton
                    v-if="can(RESOURCE, 'create')"
                    v-apex-ripple
                    label="Add Group"
                    icon="add"
                    severity="success"
                    size="sm"
                    @click="openCreate"
                />
            </div>

            <!-- A div with the button role, not a <button>: the row carries Rename and
                 Delete inside it, and a button inside a button is invalid HTML — the
                 browser closes the outer one early and the actions fall out of the row.
                 The old page used a bare clickable div, which was legal and unreachable by
                 keyboard; role + tabindex + the two keys it implies is both. -->
            <div
                v-for="group in groups"
                :key="group.id"
                role="button"
                tabindex="0"
                class="group-item"
                :class="{ 'is-active': group.id === selectedId }"
                :aria-pressed="group.id === selectedId"
                @click="select(group)"
                @keydown.enter.prevent="select(group)"
                @keydown.space.prevent="select(group)"
            >
                <span class="group-name">
                    {{ group.name }}
                    <ApexIcon
                        v-if="group.locked"
                        name="lock"
                        :size="14"
                        class="group-lock"
                        label="Protected: this group cannot be renamed or deleted"
                    />
                </span>
                <span class="group-meta">
                    {{ group.users_count }} {{ group.users_count === 1 ? 'user' : 'users' }}
                </span>
                <span v-if="group.description" class="group-desc">{{ group.description }}</span>

                <!-- A protected group has no actions at all. The server refuses both anyway
                     (GroupAdministration::assertNotProtected), so this is the courtesy half
                     of the same rule: `isAdmin()` matches by NAME, so a rename turns every
                     administrator check false, and a delete leaves nobody able to get back in. -->
                <span v-if="!group.locked" class="group-actions">
                    <ApexButton
                        v-if="can(RESOURCE, 'update')"
                        v-apex-tooltip.top="'Rename'"
                        v-apex-ripple
                        icon="edit"
                        icon-only
                        label="Rename"
                        variant="text"
                        size="sm"
                        rounded
                        severity="success"
                        @click.stop="openRename(group)"
                    />
                    <ApexButton
                        v-if="can(RESOURCE, 'delete')"
                        v-apex-tooltip.top="'Delete'"
                        v-apex-ripple
                        icon="delete"
                        icon-only
                        label="Delete"
                        variant="text"
                        size="sm"
                        rounded
                        severity="danger"
                        @click.stop="remove(group)"
                    />
                </span>
            </div>
        </aside>

        <!-- ═══ The detail pane — P/007 onwards ═══ -->
        <section class="admin-card matrix">
            <template v-if="selected">
                <header class="matrix-head">
                    <div>
                        <h2 class="panel-title">{{ selected.name }}</h2>
                        <p class="panel-sub">
                            {{ grantedCount }} of {{ resourceCount }} resources granted
                        </p>
                    </div>

                    <!-- `success` as well: the three Settings screens all save in green,
                         and this is the same kind of action — a page-level write, not a
                         dialog's. Nothing in the admin saves in blue. -->
                    <ApexButton
                        v-if="editable && tab === TAB_PERMISSIONS"
                        v-apex-ripple
                        label="Save changes"
                        icon="check"
                        severity="success"
                        :disabled="!dirty"
                        @click="save"
                    />
                </header>

                <!-- Two questions about the same group: what it may do, and who is in it. -->
                <ApexTabs v-model="tab" :tabs="tabs" class="role-tabs" />

                <!-- ═══ Permissions ═══ -->
                <template v-if="tab === TAB_PERMISSIONS">
                <!-- The three notices the old screen earned, in the same order. -->
                <ApexMessage v-if="selected.locked" severity="info" :closable="false" class="notice">
                    Administrators always holds every permission, set by <code>autentica:sync</code>.
                    Editing it here could remove your own access to this screen, with no way back in.
                </ApexMessage>

                <ApexMessage v-else-if="!can(RESOURCE, 'update')" severity="info" :closable="false" class="notice">
                    You can view permissions but not change them.
                </ApexMessage>

                <ApexMessage v-else-if="grantedCount === 0" severity="info" :closable="false" class="notice">
                    This group holds no permissions. That is deliberate for Patrons — their
                    access to their own orders is by ownership, not by permission.
                </ApexMessage>

                <ApexTreeTable
                    :value="nodes"
                    :columns="columns"
                    v-model:expandedKeys="expandedKeys"
                    size="small"
                    grid-lines="horizontal"
                    class="matrix-table"
                >
                    <!-- A module row names itself and stops; there is nothing to tick on it.
                         The resource row adds its identifier, which is what the payload is
                         keyed by and the only way to tell two similarly named rows apart. -->
                    <template #cell:name="{ node }">
                        <span :class="node.data.isModule ? 'module-name' : 'resource-name'">
                            {{ node.data.name }}
                            <span v-if="!node.data.isModule" class="ident">{{ node.data.identifier }}</span>
                        </span>
                    </template>

                    <template v-for="a in actions" :key="a" #[`cell:${a}`]="{ node }">
                        <!-- No `binary` prop: a boolean modelValue IS the binary case here,
                             unlike the PrimeVue control this replaces.

                             The label is KEPT and hidden in CSS, not dropped. ApexCheckbox
                             draws `label` beside the box whatever `labelPlacement` says —
                             the placement governs the field wrapper, and the control's own
                             text is always rendered — so in a 6-column grid it printed
                             "Create on Events" in every cell. The label element wraps the
                             input, so hiding the TEXT keeps the accessible name where a
                             screen reader can still find it; `aria-label` on the component
                             would land on a <label>, which names nothing. -->
                        <ApexCheckbox
                            v-if="!node.data.isModule"
                            :model-value="has(node.data.identifier, a)"
                            :disabled="!editable"
                            :label="`${ACTION_LABELS[a] ?? a} on ${node.data.name}`"
                            @update:model-value="toggle(node.data.identifier, a)"
                        />
                    </template>

                    <template #cell:__all="{ node }">
                        <ApexButton
                            v-if="!node.data.isModule && editable"
                            v-apex-tooltip.top="'Every action on this resource'"
                            :label="rowIsFull(node.data.identifier) ? 'none' : 'all'"
                            variant="text"
                            size="sm"
                            @click="toggleRow(node.data.identifier)"
                        />
                    </template>
                </ApexTreeTable>
                </template>

                <!-- ═══ Members ═══ -->
                <template v-else>
                    <div class="member-search">
                        <ApexInput
                            v-model="memberSearch"
                            placeholder="Search by name or email…"
                            icon="search"
                            label="Search members"
                            label-placement="hidden"
                            @update:model-value="onSearch"
                        />
                    </div>

                    <ApexMessage v-if="!can(RESOURCE, 'update')" severity="info" :closable="false" class="notice">
                        You can see who is in this group but not change it.
                    </ApexMessage>

                    <!-- Lazy: the server pages and searches, and the order is its rule, not
                         this table's. No sortable columns — see the note in the script. -->
                    <ApexDataTable
                        :value="members.data"
                        :columns="memberColumns"
                        data-key="id"
                        lazy
                        paginator
                        :rows="members.per_page"
                        :first="(members.current_page - 1) * members.per_page"
                        :total-records="members.total"
                        :loading="membersLoading"
                        grid-lines="horizontal"
                        hoverable
                        empty-message="No users match that search."
                        class="member-table"
                        @page="(e) => loadMembers(e.page + 1)"
                    >
                        <template #cell:is_member="{ row }">
                            <ApexCheckbox
                                :key="`${row.id}-${memberEpoch}`"
                                :model-value="row.is_member"
                                :disabled="!can(RESOURCE, 'update') || togglingId === row.id"
                                :label="`${row.name} in ${selected.name}`"
                                @update:model-value="(v) => toggleMember(row, v)"
                            />
                        </template>
                    </ApexDataTable>
                </template>
            </template>

            <p v-else class="skeleton-note">No groups.</p>
        </section>
    </div>

    <!-- ApexForm never submits. It validates, emits, and leaves the verb to the host. -->
    <ApexForm
        v-model:open="dialogOpen"
        :schema="dialogSchema"
        :form="form"
        shell="modal"
        @submit="saveGroup"
        @cancel="dialogOpen = false"
    />
</template>

<style scoped>
/* Min-width rather than the max-width the old page used: same two shapes, written so the
   narrow one is the base case. This screen is admin, so the patron mobile contract does
   not govern it — but one column below 1024 and two above is the behaviour being kept. */
.perm-layout {
    display: grid;
    grid-template-columns: 1fr;
    gap: 1.5rem;
    align-items: start;
}

@media (min-width: 1024px) {
    .perm-layout {
        grid-template-columns: 260px 1fr;
    }
}

.panel-title { font-size: 1rem; font-weight: 700; margin: 0; }
.panel-sub { font-size: 0.8125rem; color: var(--admin-text-muted); margin: 0.25rem 0 0; }

.group-list { padding: 1rem; }

.group-item {
    display: block;
    width: 100%;
    text-align: left;
    cursor: pointer;
    padding: 0.75rem;
    margin-top: 0.5rem;
    border-radius: 0.5rem;
    border: 1px solid var(--admin-border);
    background: var(--admin-surface);
}

/* Both states were fixed indigo — `#c7d2fe` and `#eef2ff`. A light fill under a card whose
   title is `--admin-text` means white-on-white the moment the host is in dark mode, which
   is how the user found it. The accent is read from the host so a tenant can set its own,
   and the tint is `--admin-hover`, which is translucent and therefore correct in both
   themes without this package knowing which one is active. */
.group-item:hover { border-color: var(--admin-text-muted); }

.group-item.is-active {
    border-color: var(--admin-accent, #6366f1);
    /* A neutral `--admin-hover` tint measured 4.43:1 for the description in light mode —
       just under AA. Mixing the accent itself gives a lighter wash (4.66:1) and makes the
       fill agree with the border instead of merely sitting behind it. */
    background: color-mix(in srgb, var(--admin-accent, #6366f1) 8%, transparent);
}

.group-name {
    display: flex;
    align-items: center;
    gap: 0.375rem;
    font-weight: 600;
    font-size: 0.875rem;
    color: var(--admin-text);
}

.group-lock { color: var(--admin-text-muted); }
.group-meta { display: block; font-size: 0.75rem; color: var(--admin-text-muted); margin-top: 0.125rem; }
.group-desc { display: block; font-size: 0.75rem; color: var(--admin-text-muted); margin-top: 0.25rem; line-height: 1.3; }

.group-list-head { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; }

/* Row actions appear on hover so the list stays quiet when you are only reading it.
   `:focus-within` as well as hover — keyboard users never trigger hover, and without it
   the two buttons are reachable by tab and invisible while focused. */
.group-actions {
    display: flex;
    gap: 0.25rem;
    margin-top: 0.5rem;
    opacity: 0;
    transition: opacity 0.15s;
}

.group-item:hover .group-actions,
.group-item:focus-within .group-actions,
.group-item.is-active .group-actions { opacity: 1; }

.matrix-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; }

.notice { margin-top: 1rem; }

.role-tabs { margin-top: 1rem; }

.matrix-table { margin-top: 1rem; }

.member-search { margin-top: 1rem; max-width: 22rem; }
.member-table { margin-top: 1rem; }

/* Same treatment as the matrix: the name stays for assistive technology and comes off
   the screen, because ApexCheckbox always draws its label. */
.member-table :deep(.apex-cb__txt) {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip-path: inset(50%);
    white-space: nowrap;
    border: 0;
}

.member-table :deep(.apex-cb) { justify-content: center; }

/* See the checkbox comment in the template: the name stays in the DOM for assistive
   technology and comes off the screen, which is the only way to get a bare box in a
   grid cell out of a control that always draws its label. */
.matrix-table :deep(.apex-cb__txt) {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip-path: inset(50%);
    white-space: nowrap;
    border: 0;
}

/* With the text gone the box is all there is, so centre it in the cell. */
.matrix-table :deep(.apex-cb) { justify-content: center; }

.module-name { font-weight: 700; color: var(--admin-text); }

.resource-name { display: inline-flex; align-items: baseline; gap: 0.5rem; }

/* The identifier is the key the payload travels under, so it earns a place beside the
   label — two resources can read alike and never be the same row. */
.ident {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 0.6875rem;
    color: var(--admin-text-muted);
}

.skeleton-note {
    margin: 1rem 0 0;
    font-size: 0.875rem;
    color: var(--admin-text-muted);
}
</style>
