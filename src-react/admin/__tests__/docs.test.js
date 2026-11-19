/**
 * docsUrl: docs-site link built from window.slotivaAdmin.docsBase; empty when the base is missing (no link is drawn).
 */
import { docsUrl } from '../shared/docs';

const BASE = 'https://maxhandmade.github.io/mhm-slotiva-docs/docs/';

afterEach( () => {
	delete window.slotivaAdmin;
} );

test( 'docs_url_joins_base_path_and_anchor', () => {
	window.slotivaAdmin = { docsBase: BASE };
	expect( docsUrl( 'getting-started/setup-wizard/' ) ).toBe(
		`${ BASE }getting-started/setup-wizard/`
	);
	expect( docsUrl( 'faq/faq/', 'no-payment-method' ) ).toBe(
		`${ BASE }faq/faq/#no-payment-method`
	);
} );

test( 'docs_url_is_empty_without_base', () => {
	window.slotivaAdmin = {};
	expect( docsUrl( 'faq/faq/', 'time-zone' ) ).toBe( '' );
	delete window.slotivaAdmin;
	expect( docsUrl( 'faq/faq/' ) ).toBe( '' );
} );
