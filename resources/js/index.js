/**
 * What this package offers a host's build — P/019.
 *
 * Reached through a Vite alias rather than npm, for the reason Autentica Core's JS is:
 * the PHP that produces the data and the JS that renders it ship in one Composer
 * artefact, so they cannot drift across a version.
 *
 *     '@apex/autentica-ui': path.resolve(
 *         __dirname,
 *         'vendor/exorgroup/apex-autentica-ui/resources/js',
 *     ),
 *
 * A screen is a component, not a page: it carries no layout and no heading, because both
 * are the host's. Give it the props the controller sent and put your own chrome round it.
 * TBX's `Pages/Admin/Roles/Index.vue` is the worked example.
 */
export { default as RolesScreen } from './Screens/RolesScreen.vue';
