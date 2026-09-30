<?php
namespace CBD\API;
defined( 'ABSPATH' ) || exit;
class RestAPI {
    public function register_routes(): void {
        ( new BusinessEndpoints() )->register( CBD_REST_NS );
        ( new EventEndpoints() )->register( CBD_REST_NS );
        ( new SearchEndpoints() )->register( CBD_REST_NS );
    }
}
