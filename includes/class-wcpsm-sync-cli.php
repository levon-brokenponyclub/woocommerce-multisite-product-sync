<?php
if ( ! class_exists( 'WP_CLI' ) ) {
    return;
}

WP_CLI::add_command( 'wcpsm-sync', function( $args, $assoc_args ) {
    if ( ! is_multisite() || ! is_main_site() ) {
        WP_CLI::error( 'Must be run on main site of multisite.' );
    }

    $manager = new WCPSM_Sync_Manager();

    if ( isset( $assoc_args['status'] ) ) {
        $status = $manager->get_sync_progress();
        WP_CLI::success( 'Sync Status: ' . json_encode( $status ) );
        return;
    }

    $limit = isset( $assoc_args['limit'] ) ? intval( $assoc_args['limit'] ) : null;

    if ( isset( $assoc_args['start'] ) ) {
        // Kick off using Action Scheduler if available
        if ( function_exists( 'as_enqueue_async_action' ) ) {
            as_enqueue_async_action( 'wcpsm_async_sync_products', [ 0, $limit ] );
            WP_CLI::success( 'Sync started via Action Scheduler' . ($limit ? " (limit {$limit})" : '') . '!' );
        } else {
            $manager->handle_manual_sync( $limit );
            WP_CLI::success( 'Sync started via manual process' . ($limit ? " (limit {$limit})" : '') . '.' );
        }
        return;
    }

    if ( isset( $assoc_args['cancel'] ) ) {
        $manager->update_sync_progress( [ 'status' => 'cancelled' ] );
        WP_CLI::success( 'Sync job status set to cancelled.' );
        return;
    }

    WP_CLI::log( "Usage: wp wcpsm-sync --start [--limit=10|20|30|40] | --status | --cancel" );
}, [
    'synopsis' => [
        [
            'type'        => 'flag',
            'name'        => 'start',
            'description' => 'Start a multisite product sync'
        ],
        [
            'type'        => 'flag',
            'name'        => 'status',
            'description' => 'View current sync progress'
        ],
        [
            'type'        => 'flag',
            'name'        => 'cancel',
            'description' => 'Cancel the running sync'
        ],
        [
            'type'        => 'assoc',
            'name'        => 'limit',
            'description' => 'The number of products to sync for testing (10, 20, 30, 40).',
            'optional'    => true,
        ],
    ],
    'shortdesc' => 'Manage WCPSM multisite product syncing from the command line, optionally only syncing N products for testing.'
] );
