/**
 * Admin API adapter (Task 14, Codex plan ek): ui-core's createApiClient has no PUT, so `put()` is local and must use
 * the same transport and namespace.
 */
import apiFetch from '@wordpress/api-fetch';

import {
	put,
	api,
	createAppointment,
	reschedule,
	exportUrl,
	availability,
	endTime,
} from '../shared/api';

jest.mock( '@wordpress/api-fetch', () =>
	jest.fn( () => Promise.resolve( {} ) )
);

beforeEach( () => apiFetch.mockClear() );

test( 'put_sends_put_method_and_json_body_on_admin_namespace', async () => {
	const days = [ [ { from: '10:00', to: '12:00' } ], [], [], [], [], [], [] ];
	await put( '/schedule', { days } );
	expect( apiFetch ).toHaveBeenCalledTimes( 1 );
	expect( apiFetch ).toHaveBeenCalledWith( {
		path: '/slotiva/v1/admin/schedule',
		method: 'PUT',
		data: { days },
	} );

	apiFetch.mockClear();
	await api.put( 'rules', { leadHours: 2 } );
	expect( apiFetch ).toHaveBeenCalledWith( {
		path: '/slotiva/v1/admin/rules',
		method: 'PUT',
		data: { leadHours: 2 },
	} );
} );

test( 'get_and_post_go_through_ui_core_client', async () => {
	await api.get( '/appointments', { tab: 'past', page: '2' } );
	expect( apiFetch ).toHaveBeenLastCalledWith( {
		path: '/slotiva/v1/admin/appointments?tab=past&page=2',
	} );
	await api.post( '/appointments/7/status', { status: 'cancelled' } );
	expect( apiFetch ).toHaveBeenLastCalledWith( {
		path: '/slotiva/v1/admin/appointments/7/status',
		method: 'POST',
		data: { status: 'cancelled' },
	} );
} );

test( 'create_omits_blank_email_and_phone', async () => {
	await createAppointment( {
		productId: '11',
		slot: '2026-11-17T07:00:00Z',
		name: '  Ada  ',
		phone: ' ',
		email: '',
		notify: false,
	} );
	const body = apiFetch.mock.calls[ 0 ][ 0 ].data;
	expect( body ).toEqual( {
		product_id: 11,
		slot: '2026-11-17T07:00:00Z',
		customer: { name: 'Ada' },
		notify: false,
	} );
	expect( 'email' in body.customer ).toBe( false );
} );

test( 'reschedule_always_sends_expect_sequence', async () => {
	await reschedule( 5, {
		start: '2026-11-18T08:00:00Z',
		countChange: false,
		notify: true,
		sequence: 3,
	} );
	expect( apiFetch.mock.calls[ 0 ][ 0 ] ).toEqual( {
		path: '/slotiva/v1/admin/appointments/5/reschedule',
		method: 'POST',
		data: {
			start: '2026-11-18T08:00:00Z',
			count_change: false,
			notify: true,
			expect_sequence: 3,
		},
	} );
} );

test( 'availability_with_exclude_may_leave_out_product', async () => {
	await availability( { day: '2026-11-18', exclude: 9 } );
	expect( apiFetch.mock.calls[ 0 ][ 0 ].path ).toBe(
		'/slotiva/v1/admin/availability?day=2026-11-18&exclude_reservation=9'
	);
} );

test( 'export_url_carries_wpnonce_in_query', () => {
	expect( exportUrl( 'http://x.test/wp-json/', 'abc' ) ).toBe(
		'http://x.test/wp-json/slotiva/v1/admin/export.csv?_wpnonce=abc'
	);
	expect( exportUrl( 'http://x.test/?rest_route=/', 'abc' ) ).toBe(
		'http://x.test/?rest_route=/slotiva/v1/admin/export.csv&_wpnonce=abc'
	);
} );

test( 'end_time_is_wall_clock_start_plus_length', () => {
	expect(
		endTime( {
			local_time: '10:00',
			starts_at: '2026-11-17T07:00:00Z',
			ends_at: '2026-11-17T07:30:00Z',
		} )
	).toBe( '10:30' );
} );
