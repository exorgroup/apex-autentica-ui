<script setup>
/**
 * Groups & Permissions — the screen, as shipped by `exorgroup/apex-autentica-ui` (P/019),
 * redrawn against the Claude Design at TBX's Phase AS.
 *
 * Everything host-shaped stays behind: the layout, the page heading, the Add button's place
 * in that heading, and the route that renders it. What is here is the screen itself, so a
 * host supplies its own chrome and gets the same matrix. TBX's `Pages/Admin/Roles/Index.vue`
 * is that wrapper; it calls `openCreate()`, which this component exposes, from its header.
 *
 * ## What changed at AS, and why
 *
 * - **A figure band** — groups, full-access accounts, accounts in NO group (they can sign in
 *   and do nothing), resources × actions.
 * - **The rail** shows how much each group may do, as a bar of resources granted.
 * - **The matrix** gained per-module boxes that tick an action across the whole module, a
 *   tri-state All column, a resource search, collapse / expand all, a dot on each changed
 *   row, and a save bar that says how many people the change reaches.
 * - **The Read rule is now enforced**, not just described: ticking any action grants Read,
 *   and clearing Read clears the row. A permission to update what you cannot see is not one.
 * - **Members** gained an Everyone / In group switch, the other groups each person is in, a
 *   "you", and the last administrator's box disabled with its reason — the server has always
 *   refused that change; now the screen says so before the click.
 * - **Add group** offers "Start from" as cards with each group's size, a live duplicate-name
 *   check, and a warning when the copy is full access.
 *
 * Not built, by decision: an "unlock" for the protected group. The server refuses edits to
 * it, and a screen that offered them would only be offering a failure.
 *
 * Every visible action is gated twice: hidden here by `can()`, refused by the host's route
 * gate. The hidden button is a courtesy; the endpoint is the gate.
 *
 * Nothing imports a control. ApexUI registers the library under the `Apex` prefix at boot.
 */
import { computed, ref, watch } from 'vue';
import { useCan } from '@apex/autentica';
import axios from 'axios';
import { router, usePage } from '@inertiajs/vue3';
/* Precognition's useForm, not Inertia's. It returns Inertia's form patched with
   `validate()`, and ApexForm picks the driver up by capability rather than by a prop. */
import { useForm } from 'laravel-precognition-vue-inertia';
import { useApexAlert } from '@exorgroup/apex-ui';
import { useRecordActions } from '../composables/useRecordActions';

/* Several root nodes, and a host that forwards every page prop (TBX's wrapper does) —
   the ones this screen does not declare have nowhere to fall through to. */
defineOptions({ inheritAttrs: false });

const props = defineProps({
    groups: { type: Array, default: () => [] },
    tree: { type: Array, default: () => [] },
    matrix: { type: Object, default: () => ({}) },
    actions: { type: Array, default: () => [] },
    rules: { type: Object, default: () => ({}) },
    /* AS/003 — accounts in no group at all. */
    users_without_group: { type: Number, default: 0 },
    /* AS/006 — a line per group NAME, from the host's `autentica-ui.group_notes`. */
    group_notes: { type: Object, default: () => ({}) },
});

const { can } = useCan();
const { saveRecord, deleteRecord, run, working, REPORT } = useRecordActions();
const { notify } = useApexAlert();

/* Spelled once — a typo denies for ever with no error. The ROUTE is `roles`; the RESOURCE
   is `groups`. */
const RESOURCE = 'groups';

/* The signed-in user, for the "you" beside their own name — Breeze's `auth.user`, if the
   host shares it; nothing breaks if it does not. */
const page = usePage();
const meId = computed(() => page.props?.auth?.user?.id ?? null);

const selectedId = ref(props.groups[0]?.id ?? null);
const selected = computed(() => props.groups.find((g) => g.id === selectedId.value) ?? null);

watch(() => props.groups, (list) => {
    if (! list.some((g) => g.id === selectedId.value)) {
        selectedId.value = list[0]?.id ?? null;
    }
});

/* ── the matrix: a working copy ─────────────────────────────────────────── */
function buildDraft(groupId) {
    const current = props.matrix[groupId] ?? {};
    const out = {};

    props.tree.forEach((module) => {
        module.children.forEach((resource) => {
            out[resource.identifier] = new Set((current[resource.identifier] ?? '').split('').filter(Boolean));
        });
    });

    return out;
}

const draft = ref(buildDraft(selectedId.value));

watch(() => props.matrix, () => { draft.value = buildDraft(selectedId.value); });

/** Whether this screen may write the matrix at all — permission AND the group's own rule. */
const editable = computed(() => can(RESOURCE, 'update') && ! selected.value?.locked);

const READ = 'r';

function has(identifier, letter) {
    return draft.value[identifier]?.has(letter) ?? false;
}

/* The Read rule — AS/006. Any action implies Read; clearing Read clears the row. Applied in
   ONE place so the box, the module column and the All column cannot disagree about it. */
function setAction(set, letter, on) {
    if (on) {
        set.add(letter);
        if (letter !== READ) set.add(READ);
    } else if (letter === READ) {
        set.clear();
    } else {
        set.delete(letter);
    }
}

function commit() {
    // Reassign so the computeds re-evaluate — a Set mutation is not reactive.
    draft.value = { ...draft.value };
}

function toggle(identifier, letter) {
    if (! editable.value) return;
    setAction(draft.value[identifier], letter, ! has(identifier, letter));
    commit();
}

function rowCount(identifier) {
    return props.actions.filter((a) => has(identifier, a)).length;
}

/** Every action on one resource, on or off together. */
function toggleRow(identifier) {
    if (! editable.value) return;
    const set = draft.value[identifier];
    const full = rowCount(identifier) === props.actions.length;
    set.clear();
    if (! full) props.actions.forEach((a) => set.add(a));
    commit();
}

/* A module's column: how many of its resources hold this action. */
function moduleCount(module, letter) {
    return (module.children ?? []).filter((r) => has(r.identifier, letter)).length;
}

function toggleModuleColumn(module, letter) {
    if (! editable.value) return;
    const all = moduleCount(module, letter) === module.children.length;
    module.children.forEach((r) => setAction(draft.value[r.identifier], letter, ! all));
    commit();
}

const grantedCount = computed(() => Object.values(draft.value).filter((set) => set.size > 0).length);
const permissionCount = computed(() => Object.values(draft.value).reduce((n, set) => n + set.size, 0));

/** What the server holds for one resource, for the changed-row dot and the count. */
function stored(identifier) {
    return [...((props.matrix[selectedId.value] ?? {})[identifier] ?? '')].sort().join('');
}

function isChanged(identifier) {
    return stored(identifier) !== [...(draft.value[identifier] ?? [])].sort().join('');
}

/** Individual boxes that differ from what is stored — the save bar's count. */
const changeCount = computed(() => Object.keys(draft.value).reduce((n, identifier) => {
    const was = new Set(stored(identifier).split('').filter(Boolean));
    const now = draft.value[identifier];

    return n + props.actions.filter((a) => was.has(a) !== now.has(a)).length;
}, 0));

const dirty = computed(() => changeCount.value > 0);

function discard() {
    draft.value = buildDraft(selectedId.value);
}

function select(group) {
    if (group.id === selectedId.value) return;
    selectedId.value = group.id;
    draft.value = buildDraft(group.id);
}

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
                onFinish: () => resolve(accepted
                    ? { ok: true, title: 'Permissions saved' }
                    : { ok: false, title: 'Not saved' }),
            });
        }),
    });
}

/* ── the tree the table draws ───────────────────────────────────────────── */
const resourceQuery = ref('');

const nodes = computed(() => {
    const q = resourceQuery.value.trim().toLowerCase();

    return props.tree.map((module) => {
        const children = (module.children ?? []).filter((r) => ! q
            || `${r.name} ${r.identifier}`.toLowerCase().includes(q));

        return {
            key: `module:${module.identifier}`,
            data: { name: module.name, identifier: module.identifier, isModule: true, module },
            children: children.map((resource) => ({
                key: resource.identifier,
                data: { name: resource.name, identifier: resource.identifier, isModule: false },
            })),
        };
    }).filter((n) => n.children.length);
});

const expandedKeys = ref({});
const openAll = () => { expandedKeys.value = Object.fromEntries(nodes.value.map((n) => [n.key, true])); };
watch(() => props.tree, openAll, { immediate: true });
/* A search opens everything it finds, or a match inside a closed module is a match nobody sees. */
watch(resourceQuery, (q) => { if (q) openAll(); });

const allOpen = computed(() => nodes.value.every((n) => expandedKeys.value[n.key]));

function toggleAllOpen() {
    if (allOpen.value) expandedKeys.value = {};
    else openAll();
}

/** Full names for the column headers; the letters are what travels on the wire. */
const ACTION_LABELS = {
    c: 'Create', r: 'Read', u: 'Update', d: 'Delete', p: 'Print', h: 'History',
};

/* The two actions worth a second thought, said on their headers. */
const ACTION_RISK = {
    d: 'Deletes records',
    h: 'Sees who changed what',
};

const columns = computed(() => [
    { field: 'name', header: 'Resource', expander: true },
    ...props.actions.map((a) => ({ field: a, header: ACTION_LABELS[a] ?? a, align: 'center', width: '4.25rem' })),
    { field: '__all', header: 'All', align: 'center', width: '3.5rem' },
]);

/** Every resource the tree carries, flattened — the matrix's row count. */
const resourceCount = computed(() => props.tree.reduce((n, module) => n + (module.children?.length ?? 0), 0));

/* ── the rail and the band ──────────────────────────────────────────────── */
/** Resources a group holds anything on, from the STORED matrix — counted against the tree,
    because the matrix can still carry rows for resources the tree no longer lists. */
const identifiers = computed(() => props.tree.flatMap((module) => (module.children ?? []).map((r) => r.identifier)));
const grantedFor = (group) => {
    const held = props.matrix[group.id] ?? {};
    return identifiers.value.filter((id) => (held[id] ?? '').length).length;
};

const protectedGroup = computed(() => props.groups.find((g) => g.locked) ?? null);

const kpis = computed(() => {
    const empty = props.groups.filter((g) => ! g.users_count).length;

    return [
        { label: 'Groups', value: props.groups.length, sub: `${empty} with no members` },
        /* No colour, whatever the count — the user's call at AS. */
        { label: protectedGroup.value?.name ?? 'Administrators', value: protectedGroup.value?.users_count ?? 0, sub: 'Full-access accounts' },
        {
            label: 'Users without a group',
            value: props.users_without_group,
            sub: props.users_without_group ? "Can sign in but can't do anything" : 'Everyone is assigned',
            warn: props.users_without_group > 0,
        },
        { label: 'Resources', value: resourceCount.value, sub: `× ${props.actions.length} actions each` },
    ];
});

function remove(group) {
    deleteRecord(route('admin.roles.destroy', group.id), {
        confirm: {
            tone: 'danger',
            header: 'Delete group',
            message: group.users_count > 0
                ? `Delete “${group.name}”? ${group.users_count} ${group.users_count === 1 ? 'user' : 'users'} will be removed from it.`
                : `Delete “${group.name}”?`,
            confirmText: 'Delete',
            cancelText: 'Cancel',
        },
    });
}

/* ── members ────────────────────────────────────────────────────────────────
   JSON, deliberately: an Inertia visit replaces every prop, so paging the members list
   through Inertia would rebuild the permission draft on the other tab and throw unsaved
   edits away. The order is the server's rule — members first, then by name. */
const TAB_PERMISSIONS = 'permissions';
const TAB_MEMBERS = 'members';

const tab = ref(TAB_PERMISSIONS);

const tabOptions = computed(() => [
    { value: TAB_PERMISSIONS, label: 'Permissions' },
    { value: TAB_MEMBERS, label: `Members · ${selected.value?.users_count ?? 0}` },
]);

const members = ref({ data: [], total: 0, current_page: 1, per_page: 10 });
const membersLoading = ref(false);
const memberSearch = ref('');
const onlyMembers = ref(false);
const togglingId = ref(null);
let searchTimer = null;

/* Bumped on every load and used in each tick's `:key`: a refused toggle leaves `is_member`
   unchanged, Vue skips the patch, and the box the browser flipped stays flipped. A new key
   remounts it from the value. */
const memberEpoch = ref(0);

async function loadMembers(page = 1) {
    if (! selected.value) return;

    membersLoading.value = true;
    try {
        const url = route('admin.roles.members.index', selected.value.id)
            + `?page=${page}&rows=${members.value.per_page}`
            + (memberSearch.value ? `&search=${encodeURIComponent(memberSearch.value)}` : '')
            + (onlyMembers.value ? '&only_members=1' : '');

        const { data } = await axios.get(url);
        members.value = data.users;
        memberEpoch.value++;
    } finally {
        membersLoading.value = false;
    }
}

function onSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => loadMembers(1), 300);
}

watch(onlyMembers, () => loadMembers(1));

async function toggleMember(user, shouldBeMember) {
    if (! can(RESOURCE, 'update') || user.last_protected) return;

    togglingId.value = user.id;
    try {
        const { data } = await axios.put(
            route('admin.roles.members.update', [selected.value.id, user.id]),
            { member: shouldBeMember },
        );

        user.is_member = data.is_member;
        if (selected.value) selected.value.users_count = data.members_count;
        /* Membership changed who is "last" — re-read the page so the disabled box moves. */
        await loadMembers(members.value.current_page);
    } catch (e) {
        notify({
            ...REPORT,
            tone: 'danger',
            title: e?.response?.data?.message ?? 'Could not change membership',
        });
        await loadMembers(members.value.current_page);
    } finally {
        togglingId.value = null;
    }
}

watch([selectedId, tab], ([, which]) => {
    if (which === TAB_MEMBERS) {
        memberSearch.value = '';
        loadMembers(1);
    }
});

const initials = (name) => String(name ?? '').split(/\s+/).filter(Boolean).map((w) => w[0]).join('').slice(0, 2).toUpperCase() || '?';

const memberColumns = [
    { field: 'is_member', header: 'In group', align: 'center', width: '6rem' },
    { field: 'name', header: 'Name' },
    { field: 'email', header: 'Email' },
    { field: 'other_groups', header: 'Other groups' },
];

const memberRowClass = (row) => (row.is_member ? 'is-member' : '');

/* ── the create / rename dialog ─────────────────────────────────────────── */
/* `copy_from_group_id` is 0 for "No permissions" on the cards — a radio needs a real value —
   and goes to the server as null. */
const BLANK = { name: '', description: '', copy_from_group_id: 0 };

const dialogOpen = ref(false);
const renaming = ref(null);

const form = useForm(
    () => (renaming.value ? 'patch' : 'post'),
    () => (renaming.value
        ? route('admin.roles.rename', renaming.value.id)
        : route('admin.roles.store')),
    { ...BLANK },
);

/* AS/008 — the cards: "No permissions", then every group with how much it holds. */
const startFromOptions = computed(() => [
    { value: 0, label: 'No permissions', help: 'Blank' },
    ...props.groups.map((g) => ({ value: g.id, label: g.name, help: `${grantedFor(g)} resources` })),
]);

/* The same test the server's `unique` makes, run against the groups already on screen so
   the clash is said while typing. The server still decides. */
const nameClash = computed(() => {
    const name = String(form.name ?? '').trim().toLowerCase();

    return !! name && props.groups.some((g) => g.name.toLowerCase() === name && g.id !== renaming.value?.id);
});

const copyingProtected = computed(() => !! props.groups.find((g) => g.id === form.copy_from_group_id)?.locked);

const dialogFields = computed(() => {
    const fields = [
        { key: 'name', label: 'Name', type: 'text', rules: props.rules.name, span: 2, placeholder: 'e.g. Box Office' },
        { key: 'description', label: 'Description', type: 'textarea', rules: props.rules.description, span: 2, rows: 3,
          placeholder: 'e.g. Sells and reprints tickets at the venue', help: 'What this group is for.' },
    ];

    /* Create only. GroupRequest makes the key `prohibited` once a group is bound. */
    if (! renaming.value) {
        fields.push({
            key: 'copy_from_group_id',
            label: 'Start from',
            type: 'cards',
            options: startFromOptions.value,
            span: 2,
            props: { columns: 3 },
            help: 'A copy taken now, not a link — later changes to that group do not follow.',
        });
    }

    return fields;
});

const dialogSchema = computed(() => ({
    /* No "NEW GROUP" label above the title — the host's rule. */
    title: renaming.value ? 'Rename group' : 'Add group',
    /* `long`, not `sidebar`: one section has no side nav to show, and a sidebar modal is a
       fixed 660px — Rename drew two fields over a screen of empty panel. */
    layout: 'long',
    shell: 'modal',
    submitLabel: renaming.value ? 'Save' : 'Create group',
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
    form.defaults({ ...BLANK, name: group.name, description: group.description ?? '' });
    form.reset();
    form.clearErrors();
    dialogOpen.value = true;
}

function saveGroup() {
    if (nameClash.value) return;
    const group = renaming.value;

    saveRecord(
        form,
        group ? route('admin.roles.rename', group.id) : route('admin.roles.store'),
        {
            method: group ? 'patch' : 'post',
            transform: (data) => (group
                ? { name: data.name, description: data.description }
                : { ...data, copy_from_group_id: data.copy_from_group_id || null }),
            onSuccess: () => { dialogOpen.value = false; },
        },
    );
}

/* The host's header carries Add group; it calls this. */
defineExpose({ openCreate });
</script>

<template>
    <!-- ═══ The figures — AS/003 ═══ -->
    <div class="ar-kpis">
        <div v-for="k in kpis" :key="k.label" class="admin-card ar-kpi">
            <span class="ar-kpi__label">{{ k.label }}</span>
            <span class="ar-kpi__value" :class="{ 'ar-kpi__value--warn': k.warn }">{{ k.value }}</span>
            <span class="ar-kpi__sub">{{ k.sub }}</span>
        </div>
    </div>

    <div class="perm-layout">
        <!-- ═══ The rail — AS/004 ═══ -->
        <aside class="group-list">
            <!-- A div with the button role, not a <button>: the card carries Rename and
                 Delete inside it, and a button inside a button is invalid HTML. -->
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
                <span class="group-top">
                    <span class="group-name">
                        {{ group.name }}
                        <ApexIcon
                            v-if="group.locked"
                            name="lock"
                            :size="13"
                            class="group-lock"
                            label="Protected: this group cannot be renamed, deleted or edited"
                        />
                    </span>
                    <span class="group-meta">{{ group.users_count }} {{ group.users_count === 1 ? 'user' : 'users' }}</span>
                </span>
                <span v-if="group.description" class="group-desc">{{ group.description }}</span>
                <span class="group-bar" :aria-label="`${grantedFor(group)} of ${resourceCount} resources`">
                    <span class="group-bar__track">
                        <span class="group-bar__fill" :style="{ width: `${resourceCount ? (grantedFor(group) / resourceCount) * 100 : 0}%` }" />
                    </span>
                    <span class="group-bar__n">{{ grantedFor(group) }}/{{ resourceCount }}</span>
                </span>

                <!-- A protected group has no actions at all — the server refuses both. -->
                <span v-if="!group.locked" class="group-actions">
                    <ApexButton
                        v-if="can(RESOURCE, 'update')"
                        v-apex-tooltip.top="'Rename'"
                        icon="edit"
                        icon-only
                        label="Rename"
                        variant="text"
                        size="sm"
                        rounded
                        @click.stop="openRename(group)"
                    />
                    <ApexButton
                        v-if="can(RESOURCE, 'delete')"
                        v-apex-tooltip.top="'Delete'"
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

        <!-- ═══ The detail pane ═══ -->
        <section class="admin-card matrix">
            <template v-if="selected">
                <header class="matrix-head">
                    <div>
                        <h2 class="panel-title">
                            {{ selected.name }}
                            <ApexBadge v-if="selected.locked" value="System" severity="secondary" />
                        </h2>
                        <p class="panel-sub">
                            {{ grantedCount }} of {{ resourceCount }} resources ·
                            {{ permissionCount }} of {{ resourceCount * actions.length }} permissions
                        </p>
                    </div>
                    <ApexSegmented v-model="tab" :options="tabOptions" />
                </header>

                <!-- ═══ Permissions — AS/006 ═══ -->
                <template v-if="tab === TAB_PERMISSIONS">
                    <ApexMessage v-if="selected.locked" severity="warn" :closable="false" class="notice">
                        {{ selected.name }} always holds every permission, set by <code>autentica:sync</code>.
                        Editing it here could remove your own access to this screen, with no way back in.
                    </ApexMessage>

                    <ApexMessage v-else-if="!can(RESOURCE, 'update')" severity="info" :closable="false" class="notice">
                        You can view permissions but not change them.
                    </ApexMessage>

                    <p v-if="group_notes[selected.name]" class="group-note">{{ group_notes[selected.name] }}</p>

                    <div class="matrix-tools">
                        <ApexInput
                            v-model="resourceQuery"
                            label="Find a resource"
                            label-placement="hidden"
                            placeholder="Find a resource"
                            leading-icon="search"
                            clearable
                            class="matrix-find"
                        />
                        <ApexButton
                            :label="allOpen ? 'Collapse all' : 'Expand all'"
                            variant="text"
                            severity="secondary"
                            size="sm"
                            @click="toggleAllOpen"
                        />
                        <span class="matrix-hint">Ticking any action also grants Read · clearing Read clears the row</span>
                    </div>

                    <ApexTreeTable
                        :value="nodes"
                        :columns="columns"
                        v-model:expandedKeys="expandedKeys"
                        size="small"
                        grid-lines="horizontal"
                        class="matrix-table"
                        :class="{ 'is-locked': !editable }"
                    >
                        <!-- Delete and History say what they are on their headers. -->
                        <template v-for="a in Object.keys(ACTION_RISK)" :key="`h-${a}`" #[`header:${a}`]="{ column }">
                            <span v-apex-tooltip.top="ACTION_RISK[a]" class="risky">{{ column.header }}</span>
                        </template>

                        <template #cell:name="{ node }">
                            <span v-if="node.data.isModule" class="module-name">
                                {{ node.data.name }}
                                <span class="ident">
                                    {{ node.data.module.children.filter((r) => rowCount(r.identifier) > 0).length }}/{{ node.data.module.children.length }}
                                </span>
                            </span>
                            <span v-else class="resource-name">
                                <span v-if="isChanged(node.data.identifier)" class="changed-dot" title="Changed" />
                                <span class="resource-name__text">
                                    {{ node.data.name }}
                                    <span class="ident">{{ node.data.identifier }}</span>
                                </span>
                            </span>
                        </template>

                        <template v-for="a in actions" :key="a" #[`cell:${a}`]="{ node }">
                            <!-- A module's box ticks this action on every resource in it. -->
                            <ApexCheckbox
                                v-if="node.data.isModule"
                                :model-value="moduleCount(node.data.module, a) === node.data.module.children.length"
                                :indeterminate="moduleCount(node.data.module, a) > 0 && moduleCount(node.data.module, a) < node.data.module.children.length"
                                :disabled="!editable"
                                :label="`${ACTION_LABELS[a] ?? a} for all of ${node.data.name}`"
                                class="box--module"
                                @update:model-value="toggleModuleColumn(node.data.module, a)"
                            />
                            <!-- The label is kept for assistive technology and hidden in CSS:
                                 ApexCheckbox always draws its text. -->
                            <ApexCheckbox
                                v-else
                                :model-value="has(node.data.identifier, a)"
                                :disabled="!editable"
                                :label="`${ACTION_LABELS[a] ?? a} on ${node.data.name}`"
                                @update:model-value="toggle(node.data.identifier, a)"
                            />
                        </template>

                        <template #cell:__all="{ node }">
                            <ApexCheckbox
                                v-if="!node.data.isModule"
                                :model-value="rowCount(node.data.identifier) === actions.length"
                                :indeterminate="rowCount(node.data.identifier) > 0 && rowCount(node.data.identifier) < actions.length"
                                :disabled="!editable"
                                :label="`Every action on ${node.data.name}`"
                                class="box--module"
                                @update:model-value="toggleRow(node.data.identifier)"
                            />
                        </template>
                    </ApexTreeTable>

                    <!-- The design's save bar: only while there is something to save. -->
                    <div v-if="editable && dirty" class="save-bar" role="status" aria-live="polite">
                        <span class="save-bar__dot" />
                        <span class="save-bar__text">
                            {{ changeCount }} unsaved {{ changeCount === 1 ? 'change' : 'changes' }} ·
                            affects {{ selected.users_count }} {{ selected.users_count === 1 ? 'user' : 'users' }} on their next page load
                        </span>
                        <span class="save-bar__actions">
                            <ApexButton label="Discard" variant="text" size="sm" class="save-bar__discard" @click="discard" />
                            <ApexButton label="Save permissions" icon="check" size="sm" @click="save" />
                        </span>
                    </div>
                </template>

                <!-- ═══ Members — AS/007 ═══ -->
                <template v-else>
                    <div class="matrix-tools">
                        <ApexInput
                            v-model="memberSearch"
                            placeholder="Search by name or email"
                            leading-icon="search"
                            label="Search members"
                            label-placement="hidden"
                            class="member-search"
                            @update:model-value="onSearch"
                        />
                        <ApexSegmented v-model="onlyMembers" :options="[{ value: false, label: 'Everyone' }, { value: true, label: 'In group' }]" />
                        <span class="matrix-hint">Changes save straight away</span>
                    </div>

                    <ApexMessage v-if="!can(RESOURCE, 'update')" severity="info" :closable="false" class="notice">
                        You can see who is in this group but not change it.
                    </ApexMessage>

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
                        :row-class="memberRowClass"
                        grid-lines="horizontal"
                        hoverable
                        :empty-message="onlyMembers ? 'Nobody is in this group.' : 'No users match that search.'"
                        class="member-table"
                        @page="(e) => loadMembers(e.page + 1)"
                    >
                        <template #cell:is_member="{ row }">
                            <span v-apex-tooltip.top="row.last_protected ? `The last member of ${selected.name} cannot be removed` : null">
                                <ApexCheckbox
                                    :key="`${row.id}-${memberEpoch}`"
                                    :model-value="row.is_member"
                                    :disabled="!can(RESOURCE, 'update') || togglingId === row.id || row.last_protected"
                                    :label="`${row.name} in ${selected.name}`"
                                    @update:model-value="(v) => toggleMember(row, v)"
                                />
                            </span>
                        </template>
                        <template #cell:name="{ row }">
                            <span class="member-name">
                                <span class="member-avatar">{{ initials(row.name) }}</span>
                                <span :class="{ 'member-name__in': row.is_member }">{{ row.name }}</span>
                                <span v-if="row.id === meId" class="member-you">you</span>
                            </span>
                        </template>
                        <template #cell:other_groups="{ row }">
                            <span v-if="row.other_groups?.length" class="member-groups">
                                <ApexBadge v-for="g in row.other_groups" :key="g" :value="g" severity="secondary" />
                            </span>
                            <span v-else class="muted">—</span>
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
    >
        <template #field-name="{ field, value, update, error, blur }">
            <ApexInput
                :model-value="value"
                :label="field.label"
                :placeholder="field.placeholder"
                :error="error || (nameClash ? 'A group with this name already exists.' : undefined)"
                required
                @update:model-value="update"
                @blur="blur"
            />
        </template>
        <template #actions="{ cancel, submit, processing }">
            <span class="dialog-note">
                <template v-if="!renaming && copyingProtected">Copies full access. Trim it on the next screen.</template>
                <template v-else-if="!renaming">You can add members after creating it.</template>
            </span>
            <ApexButton label="Cancel" variant="text" severity="secondary" :disabled="processing" @click="cancel" />
            <ApexButton
                :label="renaming ? 'Save' : 'Create group'"
                icon="check"
                :loading="processing"
                :disabled="nameClash"
                @click="submit"
            />
        </template>
    </ApexForm>
</template>

<style scoped>
/* Colours are the HOST's tokens (`--admin-*`, the kit's `--accent-*`), each with a neutral
   fallback, so another host's theme — and its dark mode — reaches this screen untouched. */

/* ── the figures ───────────────────────────────────────────────────────── */
.ar-kpis {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 200px), 1fr));
    gap: var(--admin-gap, 20px);
    margin-bottom: var(--admin-gap, 20px);
}

.ar-kpi {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    min-width: 0;
    padding: var(--admin-pad-card, 24px);
    margin-bottom: 0;
}

.ar-kpi__label {
    font-size: 0.6875rem;
    font-weight: 600;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--admin-text-muted);
}

.ar-kpi__value {
    font-size: clamp(24px, calc(var(--admin-kpi, 56px) * 0.7), 40px);
    font-weight: 700;
    letter-spacing: -0.025em;
    line-height: 1;
    color: var(--admin-text);
    font-variant-numeric: tabular-nums;
}

.ar-kpi__value--warn { color: var(--accent-warning, #d97706); }

.ar-kpi__sub { font-size: 0.75rem; color: var(--admin-text-muted); }

/* ── layout ────────────────────────────────────────────────────────────── */
.perm-layout {
    display: grid;
    grid-template-columns: 1fr;
    gap: var(--admin-gap, 20px);
    align-items: start;
}

/* Side by side from 1280. Below it the matrix — seven columns of boxes beside the resource
   names — does not fit beside a 300px rail, and History and All were cut off at 1024; the
   rail goes above it as a grid of cards instead. */
@media (min-width: 768px) and (max-width: 1279.98px) {
    /* Doubled up to outrank the base `.group-list` rule, which comes later in the file. */
    .perm-layout .group-list {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    }
}

@media (min-width: 1280px) {
    .perm-layout { grid-template-columns: 300px minmax(0, 1fr); }

    /* The rail stays in view while the matrix scrolls past it. */
    .group-list {
        position: sticky;
        top: calc(var(--topbar-height, 64px) + 16px);
    }
}

.panel-title {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 1rem;
    font-weight: 700;
    margin: 0;
    color: var(--admin-text);
}

.panel-sub { font-size: 0.8125rem; color: var(--admin-text-muted); margin: 0.25rem 0 0; }

/* ── the rail ──────────────────────────────────────────────────────────── */
.group-list { display: flex; flex-direction: column; gap: 0.5rem; }

.group-item {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    width: 100%;
    text-align: left;
    cursor: pointer;
    padding: 0.75rem 0.875rem;
    border-radius: 10px;
    border: 1px solid var(--admin-border);
    background: var(--admin-surface);
}

.group-item:hover { border-color: var(--admin-text-muted); }

/* The selected group keeps the accent border and its 8% wash (6f8f90a), with an inset line
   so it reads as chosen, not merely hovered. */
.group-item.is-active {
    border-color: var(--admin-accent, #6366f1);
    box-shadow: inset 0 0 0 1px var(--admin-accent, #6366f1);
    background: color-mix(in srgb, var(--admin-accent, #6366f1) 8%, transparent);
}

.group-top { display: flex; align-items: center; gap: 0.5rem; }

.group-name {
    display: flex;
    align-items: center;
    gap: 0.375rem;
    font-weight: 700;
    font-size: 0.9375rem;
    color: var(--admin-text);
    min-width: 0;
}

.group-lock { color: var(--admin-text-muted); }
.group-meta { margin-left: auto; font-size: 0.75rem; color: var(--admin-text-muted); white-space: nowrap; }
.group-desc { font-size: 0.75rem; color: var(--admin-text-muted); line-height: 1.45; }

.group-bar { display: flex; align-items: center; gap: 0.5rem; margin-top: 0.25rem; }
.group-bar__track { flex: 1; height: 4px; border-radius: 2px; background: var(--admin-border); overflow: hidden; }
.group-bar__fill { display: block; height: 100%; background: var(--admin-text); }
.group-bar__n { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.6875rem; color: var(--admin-text-muted); }

.group-actions { display: none; gap: 0.25rem; margin-top: 0.25rem; }
.group-item.is-active .group-actions,
.group-item:focus-within .group-actions { display: flex; }

/* ── the detail ────────────────────────────────────────────────────────── */
.matrix { padding: var(--admin-pad-card, 24px); min-width: 0; }

.matrix-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }

.notice { margin-top: 1rem; }

.group-note { margin: 1rem 0 0; font-size: 0.8125rem; color: var(--admin-text-muted); }

.matrix-tools {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-wrap: wrap;
    margin-top: 1rem;
}

.matrix-find { width: 14rem; }
.member-search { width: 17rem; }

.matrix-hint { margin-left: auto; font-size: 0.75rem; color: var(--admin-text-muted); }

.matrix-table { margin-top: 0.75rem; }

/* Locked or read-only: the boxes are shown, dimmed, and cannot be pressed. */
.matrix-table.is-locked { opacity: 0.7; }

.matrix-table :deep(tr[data-depth='0']) { background: var(--bg-subtle, var(--admin-bg)); }

.module-name { display: inline-flex; align-items: baseline; gap: 0.5rem; font-weight: 700; color: var(--admin-text); }
.resource-name { display: inline-flex; align-items: center; gap: 0.5rem; }

/* Name over identifier: side by side, a long name wrapped into three lines at 1440. */
.resource-name__text { display: flex; flex-direction: column; line-height: 1.3; }

.ident {
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 0.6875rem;
    font-weight: 400;
    color: var(--admin-text-muted);
}

.changed-dot {
    flex: none;
    width: 6px;
    height: 6px;
    border-radius: 3px;
    background: var(--admin-accent, #6366f1);
}

.risky { border-bottom: 1px dotted currentColor; cursor: help; }

/* A module's and a row's boxes are summaries, drawn lighter than the boxes they summarise. */
.matrix-table :deep(.box--module .apex-cb__box) { opacity: 0.75; }

/* ApexCheckbox always draws its label; the name stays for assistive technology and comes
   off the screen, which is the only way to get a bare box in a grid cell. */
.matrix-table :deep(.apex-cb__txt),
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

.matrix-table :deep(.apex-cb),
.member-table :deep(.apex-cb) { justify-content: center; }

/* ── the save bar ──────────────────────────────────────────────────────── */
.save-bar {
    position: sticky;
    bottom: 0;
    z-index: 5;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-top: 1rem;
    padding: 0.75rem 1rem;
    border-radius: 12px;
    background: var(--admin-sidebar-bg, #1a1a1a);
    color: #fff;
    border: 1px solid rgba(255, 255, 255, 0.1);
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.18);
}

.save-bar__dot { width: 8px; height: 8px; border-radius: 4px; background: var(--admin-accent, #6366f1); flex: none; }
.save-bar__text { font-size: 0.8125rem; }
.save-bar__actions { display: flex; gap: 0.5rem; margin-left: auto; }
.save-bar__discard { --apex-btn-label: #fff; }

/* ── members ───────────────────────────────────────────────────────────── */
.member-table { margin-top: 0.75rem; }

.member-table :deep(tr.is-member td) {
    background: color-mix(in srgb, var(--admin-accent, #6366f1) 7%, var(--admin-surface, #fff));
}

.member-name { display: inline-flex; align-items: center; gap: 0.5rem; }

.member-avatar {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    border: 1px solid var(--admin-border);
    background: var(--bg-subtle, var(--admin-bg));
    font-size: 0.6875rem;
    font-weight: 700;
    color: var(--admin-text);
    flex: none;
}

.member-name__in { font-weight: 600; }
.member-you { font-size: 0.6875rem; color: var(--admin-text-muted); }
.member-groups { display: inline-flex; gap: 0.25rem; flex-wrap: wrap; }
.muted { color: var(--admin-text-muted); }

.dialog-note { margin-right: auto; font-size: 0.8125rem; color: var(--admin-text-muted); }

.skeleton-note { margin: 1rem 0 0; font-size: 0.875rem; color: var(--admin-text-muted); }
</style>
